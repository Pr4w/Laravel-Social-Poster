<?php

namespace SocialPoster\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use LogicException;
use SocialPoster\Contracts\AtomicIdempotencyStore;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Contracts\IdempotencyStore;
use SocialPoster\Contracts\MediaGateway;
use SocialPoster\Contracts\MediaTransformer;
use SocialPoster\Contracts\SocialPlatform;
use SocialPoster\Contracts\SupportsComments;
use SocialPoster\Contracts\SupportsFirstComment;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\CommentFailed;
use SocialPoster\Events\CommentSkipped;
use SocialPoster\Events\CredentialsRejected;
use SocialPoster\Events\PostDeduplicated;
use SocialPoster\Events\PostFailed;
use SocialPoster\Events\PostProcessing;
use SocialPoster\Events\PostPublished;
use SocialPoster\Events\PostRetrying;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Exceptions\ValidationException;
use SocialPoster\Idempotency\KeyCollisionException;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\Resolvers\IdentityCredentialsResolver;
use SocialPoster\SocialManager;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\Published;
use SocialPoster\ValueObjects\SocialPost;
use Throwable;

/**
 * Drives one platform's publish to completion. The first run validates (unless
 * skipped) and calls publish(). A Pending outcome dispatches a delayed
 * continuation carrying the state, which calls resume(); this repeats until
 * Published, a failure, or the deadline, so a worker never blocks waiting on
 * the platform.
 *
 * Two zones:
 *
 * - Zone A (retryable) only wraps credential resolution, validation and the
 *   driver call. Failures there are classified and retried, failed or
 *   restarted.
 * - Zone B runs once the platform accepted the post: markPublished, the
 *   PostPublished event and the comment dispatch are each guarded, so a
 *   throwing listener, store or queue can never re-publish a live post or turn
 *   it into a PostFailed.
 *
 * Duplicate protection, in layers:
 *
 * - the idempotency key (computed once at dispatch, carried by every
 *   continuation) replays a published result instead of posting again;
 * - with an AtomicIdempotencyStore (every first-party store), the key is bound
 *   to the account, claimed by this chain's owner token for the run, and
 *   marked 'committing' before a commit phase, so a crash, a redelivery or a
 *   second chain never re-runs a create blind;
 * - a commit phase (Pending::$commits) that runs again for any reason gets
 *   `_recovering = true` in its state, so the driver verifies on the platform
 *   before creating: after a redelivery (attempts() > 1, whatever the store),
 *   a 'committing' store row, or an ambiguous earlier failure. Commit phases
 *   are never release()d: a retry is a fresh continuation whose
 *   `_recovering` says exactly whether the previous try may have created;
 * - a per-step lock holds back a redelivered copy of a step while another
 *   run holds it (still running, or dead with the lock not yet expired); a
 *   run that finishes a step marks it handed over, and a later copy of a
 *   handed-over step is dropped (PostDeduplicated) instead of forking the
 *   chain. A step whose continuation could not be queued after it recorded
 *   it re-queues the recorded step rather than running its create again.
 *
 * Failures are reported once, from failed(): PostFailed (with
 * possiblyPublished when the post may be live) and, for a rejected token,
 * CredentialsRejected. A failure that may have published keeps the store row
 * ('failed') for reconcile instead of forgetting it.
 *
 * Retries: social.tries per step (attempts + continuation retries), waiting a
 * platform's Retry-After or social.backoff. The 'sync' queue cannot delay or
 * release, so there a temporary failure is final and processing waits at most
 * social.sync.max_wait.
 *
 * Credentials are re-resolved through the CredentialsResolver at the start of
 * every run; continuations carry the dispatched credentials (or reference),
 * never the resolved ones. Payloads are encrypted when social.encrypt_jobs is
 * on and the app has a key.
 *
 * Every property added after v1.0 is non-readonly with a default, so payloads
 * queued by v1.0 still unserialize and run (they keep the v1.0 store key).
 *
 * Continuations are built with `new static(...)` using the constructor below
 * (as v1.0's static::dispatch() did), so a subclass keeps its signature.
 *
 * @phpstan-consistent-constructor
 */
class PublishToConnectionJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels {
        __serialize as protected serializeProperties;
    }

    /** Hard ceiling for any wall-clock deadline, whatever a driver declares. */
    public const MAX_DEADLINE_SECONDS = 604800;

    /** Ceiling for a single retry delay taken from a platform's Retry-After. */
    public const MAX_RETRY_DELAY = 3600;

    public int $tries = 5;

    /** Floor of the poll cap; the effective cap is derived from the deadline. */
    public int $maxPolls = 60;

    public int $uniqueFor = 3600;

    // --- 1.1 state. Non-readonly with defaults: v1.0 payloads lack them. ------

    /** The store key, computed once at dispatch (see idempotencyKeyFor()). */
    public ?string $idempotencyKey = null;

    /** This run executes a commit phase: the next resume() performs a non-idempotent create. */
    public bool $commits = false;

    /** The post (or the platform's publish job) already exists. */
    public bool $committed = false;

    /** Driver-declared deadline (unix time), carried across continuations. */
    public ?int $deadline = null;

    /** Unix time of the first publish() call of this chain. */
    public ?int $startedAt = null;

    /** Chain owner token for the idempotency lease; continuations keep it. */
    public ?string $owner = null;

    /** Fresh restarts so far (TemporaryException with restart = true). */
    public int $restarts = 0;

    /** Earlier failed tries of this step that were re-dispatched (commit phases). */
    public int $retries = 0;

    /** An earlier try of this commit phase may have created the post. */
    public bool $recovering = false;

    /** Unix time this commit phase was first entered. */
    public ?int $committingSince = null;

    /**
     * The step before this one handed over to this verify step after a create
     * whose answer was lost (Pending::$possiblySent: Pinterest verify_pin,
     * Facebook feed_verify, LinkedIn verify...): the earlier create may have
     * gone out. The driver verifies on its own (no `_recovering`); the job
     * uses this flag so its own exits (deadline, publish window, exhausted
     * tries, an unflagged failure, a restart) report the post as possibly
     * published instead of forgetting it.
     */
    public bool $commitSent = false;

    /** Do not run the driver before this unix time (waits longer than one queue delay). */
    public ?int $notBefore = null;

    /** Refuse to create the post after this unix time (FailureReason::Expired). */
    public ?int $notAfter = null;

    /** Seconds one run may take (social.job_timeout, or derived per platform). */
    public ?int $timeout = null;

    /**
     * A run killed for timeout is failed (possiblyPublished in a commit phase),
     * not retried blind. Steps the driver can safely run again
     * (AbstractPlatform::retriesKilledRun(): the YouTube upload) are queued
     * with false, so the queue runs them again after retry_after.
     */
    public bool $failOnTimeout = true;

    public int $maxExceptions = 3;

    /** Encrypt the payload (it carries credentials); see social.encrypt_jobs. */
    public bool $shouldBeEncrypted = false;

    /** 1 = queued by v1.0 (unserialized without this property); 2 = 1.1. */
    protected int $payloadVersion = 1;

    /**
     * Collaborators of the current run. Never serialized.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $run = null;

    /**
     * @param  array<string, mixed>|null  $state
     * @param  ?string  $idempotencyKey  Pass the key computed once at dispatch (idempotencyKeyFor()); null derives it.
     * @param  DateTimeInterface|int|null  $notAfter  Never create the post after this moment.
     */
    public function __construct(
        public readonly Platform $platform,
        public readonly SocialPost $post,
        public readonly Credentials $credentials,
        public readonly bool $skipValidation = false,
        public readonly ?array $state = null,
        public readonly int $pollCount = 0,
        public readonly ?string $correlationId = null,
        ?string $idempotencyKey = null,
        DateTimeInterface|int|null $notAfter = null,
    ) {
        $this->idempotencyKey = $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : null;
        $this->notAfter = $notAfter instanceof DateTimeInterface ? $notAfter->getTimestamp() : $notAfter;
        $this->owner = (string) Str::uuid();
        $this->payloadVersion = 2;

        $this->applyConfig();
    }

    // --- Keys -------------------------------------------------------------------

    /**
     * The idempotency key for one platform and account: a hash of the caller's
     * correlation id (or the content), the platform and the driver's stable
     * accountKey(). Compute it once, at dispatch, and pass it to every job of
     * the publish: re-computing it after a token refresh could change it.
     */
    public static function idempotencyKeyFor(
        Platform $platform,
        SocialPost $post,
        Credentials $credentials,
        ?string $correlationId = null,
        ?SocialPlatform $driver = null,
    ): string {
        $account = '';

        try {
            $driver ??= Container::getInstance()->make(SocialManager::class)->driver($platform->value);
            $account = $driver instanceof AbstractPlatform ? $driver->accountKey($credentials) : '';
        } catch (Throwable) {
            // No usable driver: the job fails on it anyway; the key just has no account.
        }

        return static::makeIdempotencyKey($platform, $correlationId ?? static::contentKeyFor($platform, $post), $account);
    }

    /** sha256 over the base (correlation id or content key), the platform and the account identity. */
    public static function makeIdempotencyKey(Platform $platform, string $base, string $accountKey): string
    {
        return 'sp:'.hash('sha256', (string) json_encode([$platform->value, $base, $accountKey]));
    }

    /** The v1.0 content key: the platform plus a hash of caption, title and media sources. */
    public static function contentKeyFor(Platform $platform, SocialPost $post): string
    {
        $sources = array_map(static fn ($media) => $media->source, $post->media);

        return $platform->value.':'.md5((string) json_encode([
            $post->caption,
            $post->title,
            $sources,
        ]));
    }

    /**
     * The store key of this publish. The key given at dispatch wins; a payload
     * queued by v1.0 keeps the key v1.0 recorded under; otherwise it is derived
     * from the dispatched credentials (stable across runs) and remembered.
     */
    public function idempotencyKey(): string
    {
        if ($this->idempotencyKey !== null && $this->idempotencyKey !== '') {
            return $this->idempotencyKey;
        }

        if ($this->payloadVersion < 2) {
            return $this->legacyKey();
        }

        $driver = is_array($this->run) && ($this->run['driver'] ?? null) instanceof SocialPlatform ? $this->run['driver'] : null;

        return $this->idempotencyKey = static::idempotencyKeyFor(
            $this->platform,
            $this->post,
            $this->credentials,
            $this->correlationId,
            $driver,
        );
    }

    /**
     * The unique-dispatch lock id. Separate from the store key, and per step
     * for continuations, so a continuation never meets a stale lock left by
     * the dispatch that started the chain.
     */
    public function uniqueId(): string
    {
        $base = $this->idempotencyKey !== null && $this->idempotencyKey !== '' ? $this->idempotencyKey : $this->legacyKey();

        return $this->isContinuation() ? $base.':step:'.$this->stepId() : $base;
    }

    public function uniqueVia(): CacheRepository
    {
        return Cache::store(static::lockStoreName());
    }

    /** @return list<string> */
    public function tags(): array
    {
        $tags = ['social-poster', 'social-poster:'.$this->platform->value];

        if ($this->correlationId !== null) {
            $tags[] = 'social-poster:'.$this->correlationId;
        }

        return $tags;
    }

    protected function contentKey(): string
    {
        return static::contentKeyFor($this->platform, $this->post);
    }

    /** The key (and unique id) v1.0 used. */
    protected function legacyKey(): string
    {
        return $this->correlationId !== null
            ? $this->correlationId.':'.$this->platform->value
            : $this->contentKey();
    }

    protected function isContinuation(): bool
    {
        return $this->state !== null || $this->pollCount > 0 || $this->restarts > 0 || $this->retries > 0;
    }

    protected function stepId(): string
    {
        return $this->pollCount.'.'.$this->restarts.'.'.$this->retries;
    }

    /** The cache lock a run of this exact step holds while it runs. */
    public function overlapLockKey(): string
    {
        return 'social-poster:publish-run:'.hash('sha256', $this->idempotencyKey().'|'.$this->ownerToken().'|'.$this->stepId());
    }

    protected function ownerToken(): string
    {
        return $this->owner ?? 'legacy:'.hash('sha256', $this->idempotencyKey());
    }

    // --- Config -----------------------------------------------------------------

    /**
     * The run timeout for a platform: social.job_timeout when set, else the
     * longest upload slice (api.<platform>.upload_run_seconds, 240) plus the
     * longest single call (upload, publish or HTTP timeout) plus 120.
     */
    public static function configuredTimeout(Platform|string|null $platform = null): int
    {
        $config = static::config();
        $explicit = $config('social.job_timeout');

        if (is_numeric($explicit) && (int) $explicit > 0) {
            return (int) $explicit;
        }

        $name = $platform instanceof Platform ? $platform->value : $platform;
        $api = $name !== null ? (array) $config("social.api.{$name}", []) : [];
        $seconds = static fn ($value, int $default): int => is_numeric($value) && (int) $value > 0 ? (int) $value : $default;

        $http = $seconds($api['timeout'] ?? null, $seconds($config('social.http_timeout'), 60));
        $publish = $seconds($api['publish_timeout'] ?? null, $seconds($config('social.publish_timeout'), 120));
        $upload = $seconds($api['upload_timeout'] ?? null, $seconds($config('social.upload_timeout'), 300));
        $slice = $seconds($api['upload_run_seconds'] ?? null, 240);

        return max($upload, $slice + max($http, $publish, $upload)) + 120;
    }

    /** @return int[] */
    public function backoff(): array
    {
        $configured = static::config()('social.backoff');
        $schedule = is_array($configured) ? array_values(array_filter($configured, 'is_numeric')) : [];

        return $schedule === [] ? [30, 120, 300, 900] : array_map('intval', $schedule);
    }

    protected function backoffFor(int $attempt): int
    {
        $schedule = $this->backoff();
        $index = max(0, min($attempt, count($schedule)) - 1);

        return $schedule[$index] ?? (int) end($schedule);
    }

    protected function applyConfig(): void
    {
        $config = static::config();

        $tries = $config('social.tries');
        $this->tries = is_numeric($tries) && (int) $tries > 0 ? (int) $tries : 5;
        $this->timeout = static::configuredTimeout($this->platform);
        $this->uniqueFor = max($this->uniqueFor, $this->timeout + 60);
        $this->shouldBeEncrypted = (bool) $config('social.encrypt_jobs', true) && filled($config('app.key'));

        $connection = $config('social.connection');
        $queue = $config('social.queue');
        $afterCommit = $config('social.after_commit');

        if (is_string($connection) && $connection !== '') {
            $this->connection = $connection;
        }

        if (is_string($queue) && $queue !== '') {
            $this->queue = $queue;
        }

        if ($afterCommit !== null && $afterCommit !== '') {
            $this->afterCommit = (bool) $afterCommit;
        }
    }

    /** @return callable(string, mixed=): mixed */
    protected static function config(): callable
    {
        return static function (string $key, mixed $default = null): mixed {
            $container = Container::getInstance();

            return $container->bound('config') ? $container->make('config')->get($key, $default) : $default;
        };
    }

    protected static function lockStoreName(): ?string
    {
        $store = static::config()('social.lock_store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    protected function timeoutSeconds(): int
    {
        return $this->timeout !== null && $this->timeout > 0 ? $this->timeout : static::configuredTimeout($this->platform);
    }

    // --- Run --------------------------------------------------------------------

    public function handle(SocialManager $platforms, Dispatcher $events, IdempotencyStore $idempotency): void
    {
        $this->run = ['events' => $events, 'store' => $idempotency, 'driver' => null, 'state' => null, 'commit' => false, 'sent' => false, 'credentials' => null];

        try {
            $key = $this->idempotencyKey();
            $this->run['key'] = $key;

            if ($this->notBefore !== null && $this->notBefore > $this->now()) {
                // A wait longer than one queue delay allows: chain the remainder.
                $this->renewLease($this->notBefore - $this->now());
                $this->dispatchNext($this->replicate(), $this->notBefore - $this->now());

                return;
            }

            try {
                $driver = $platforms->driver($this->platform->value);
            } catch (Throwable $e) {
                $this->finish($e instanceof SocialPosterException ? $e : new PermanentException(
                    "No usable {$this->platform->value} driver: ".$this->redact($e->getMessage()),
                    $this->platform,
                    FailureReason::Misconfigured,
                    ['exception' => $e::class],
                    $e,
                ), false, null, false);

                return;
            }

            $this->run['driver'] = $driver;
            $guard = $this->acquireOverlapGuard();

            if ($guard === false) {
                $this->overlapping($key);

                return;
            }

            $this->run['guard'] = $guard;

            try {
                if ($this->stepHandedOver()) {
                    // A copy of a step that a run already finished (a redelivery
                    // or a double push): that run moved the chain on.
                    $this->safeDispatch(new PostDeduplicated($this->platform, $key, $this->post->metadata, $this->correlationId));

                    return;
                }

                $this->runStep($driver, $idempotency, $key);

                // Released (a retry of this step follows) or failed (an
                // operator may queue:retry it): not handed over.
                if (! ($this->run['released'] ?? false) && ! ($this->run['failed'] ?? false)) {
                    $this->markStepHandedOver();
                }
            } finally {
                $this->releaseRunGuard();
            }
        } finally {
            $this->run = null;
        }
    }

    /**
     * Another run holds this step's lock: either it is still going (the queue
     * redelivered a slow run) or its worker died and the lock waits to expire.
     * Come back once the lock is free: a step that run finished is then
     * dropped as handed over, one it never finished runs (a commit phase as
     * recovering). Deleting the copy instead would silently lose the publish
     * whenever the holder was dead. The 'sync' queue cannot release, and there
     * the holder is a live process that reports the outcome.
     */
    protected function overlapping(string $key): void
    {
        if (! $this->cannotRelease()) {
            $this->release($this->timeoutSeconds() + 5);

            return;
        }

        $this->safeDispatch(new PostDeduplicated($this->platform, $key, $this->post->metadata, $this->correlationId));
    }

    protected function runStep(SocialPlatform $driver, IdempotencyStore $store, string $key): void
    {
        $atomic = $store instanceof AtomicIdempotencyStore ? $store : null;

        try {
            if ($atomic !== null) {
                $account = $this->accountKeyOf($driver, $this->credentials);

                if ($account !== '') {
                    try {
                        $atomic->bindAccount($key, $this->platform, $account);
                    } catch (KeyCollisionException $e) {
                        if (! $this->replaysRecord($store, $key, $driver)) {
                            throw $e;
                        }

                        return;
                    }
                }
            }

            $prior = $store->completed($key) ?? $this->legacyRecord($store, $key);

            if ($prior !== null) {
                $this->complete($driver, $prior, true);

                return;
            }

            if ($atomic !== null && ! $atomic->claim($key, $this->ownerToken(), $this->timeoutSeconds() + 60)) {
                $this->claimRefused($driver, $atomic, $key);

                return;
            }

            if ($this->requeueRecordedStep($store, $atomic, $key)) {
                return;
            }

            $state = $this->resumeState($store, $atomic, $key);
            $this->run['state'] = $state;
            $this->run['commit'] = $state !== null && $this->commits;

            $this->assertNotExpired();

            $credentials = $this->resolveCredentials();
            $this->run['credentials'] = $credentials;
            $prepared = new PreparedPost($this->platform, $this->post, $credentials);

            if ($state === null && ! $this->skipValidation) {
                $errors = $driver->validate($prepared);

                if ($errors->isNotEmpty()) {
                    throw ValidationException::fromMessageBag($this->platform, $errors);
                }
            }

            if ($this->run['commit']) {
                $this->committingSince ??= $this->now();
                $atomic?->markCommitting($key, $this->storedState($state, true, $this->committed, $this->deadline));
            }

            $this->startedAt ??= $this->now();
            $this->run['sent'] = true;
            $outcome = $this->callDriver($driver, $prepared, $state);
        } catch (KeyCollisionException $e) {
            // The record belongs to the account that bound the key first.
            $this->finish($e, false, null, false);

            return;
        } catch (ConnectionException $e) {
            // cURL-level failure (timeout, DNS, refused, TLS): no response ever
            // came back, so the driver could not classify it.
            $this->logTransport($e);
            $this->releaseOrFail(TemporaryException::connection($this->platform, $e), $this->events(), $store, $key);

            return;
        } catch (TemporaryException $e) {
            $this->releaseOrFail($e, $this->events(), $store, $key);

            return;
        } catch (SocialPosterException $e) {
            $this->onPermanent($e);

            return;
        } catch (Throwable $e) {
            $this->onUnexpected($e);

            return;
        }

        // Zone B: the driver returned normally; nothing below may re-run it.
        if ($outcome instanceof Published) {
            $this->complete($driver, $outcome->result, false);

            return;
        }

        if ($outcome instanceof Pending) {
            $this->scheduleContinuation($outcome, $this->events());

            return;
        }

        $this->onPermanent(new PermanentException(
            'The '.$this->platform->value.' driver returned an unknown outcome ('.get_debug_type($outcome).').',
            $this->platform,
            FailureReason::Unknown,
            ['outcome' => get_debug_type($outcome)] + ($this->run['commit'] || $this->committed ? ['possibly_published' => true] : []),
            new LogicException('Unknown PublishOutcome '.get_debug_type($outcome)),
        ));
    }

    /**
     * Poster::reconcile() replays a published record with whatever credentials
     * the caller had (a Credentials::ref(), the app's default account), which
     * need not be the account the key is bound to. The record is the truth: a
     * live post must never be reported as PostFailed(KeyCollision). The first
     * comment is skipped, since those credentials may belong to another
     * account. A normal dispatch that collides still fails (another account
     * must not be handed this account's post).
     */
    protected function replaysRecord(IdempotencyStore $store, string $key, SocialPlatform $driver): bool
    {
        if (! $this->isRecoveryDispatch()) {
            return false;
        }

        $prior = $store->completed($key);

        if ($prior === null || $prior->platform !== $this->platform) {
            return false;
        }

        $this->run['skip_comment'] = CommentSkipped::REASON_ACCOUNT_MISMATCH;
        $this->complete($driver, $prior, true);

        return true;
    }

    /** A first run flagged recovering by its dispatcher (Poster::reconcile()), not by an earlier run. */
    protected function isRecoveryDispatch(): bool
    {
        return $this->recovering && $this->state === null && $this->pollCount === 0 && $this->restarts === 0;
    }

    /**
     * A v1.0 record of this publish ('{correlationId}:{platform}'), for a 1.1
     * job dispatched directly with a correlation id (the builder copies such
     * records itself). Copied under the 1.1 key so the replay finds it next
     * time; the post is never created again.
     */
    protected function legacyRecord(IdempotencyStore $store, string $key): ?PostResult
    {
        if ($this->payloadVersion < 2 || $this->correlationId === null || $this->correlationId === '' || $this->isContinuation()) {
            return null;
        }

        $legacyKey = $this->correlationId.':'.$this->platform->value;

        if ($legacyKey === $key) {
            return null;
        }

        try {
            $legacy = $store->completed($legacyKey);
        } catch (Throwable $e) {
            $this->reportQuietly($e);

            return null;
        }

        if ($legacy === null || $legacy->platform !== $this->platform) {
            return null;
        }

        try {
            $store->markPublished($key, $legacy);
        } catch (Throwable $e) {
            $this->reportQuietly($e);
        }

        return $legacy;
    }

    /**
     * Whether a job-level exit of this run (not a failure the driver flagged
     * itself) may leave a live post behind: the post exists, or this run is a
     * commit phase that repeats a create that may have gone out (recovering),
     * or follows one (commitSent).
     */
    protected function mayHaveCreated(): bool
    {
        $commit = (bool) ($this->run['commit'] ?? false);

        return $this->committed || ($commit && ($this->recovering || $this->commitSent));
    }

    /**
     * Call publish()/resume(). A token rejection flagged reresolve (a custom
     * resolver is bound) is retried once, immediately, with re-resolved
     * credentials; a second rejection is final (PermanentException InvalidToken).
     *
     * @param  array<string, mixed>|null  $state
     */
    protected function callDriver(SocialPlatform $driver, PreparedPost $prepared, ?array $state): mixed
    {
        try {
            return $this->invokeDriver($driver, $prepared, $state);
        } catch (TemporaryException $e) {
            if (($e->context['reresolve'] ?? false) !== true) {
                throw $e;
            }
        }

        $credentials = $this->resolveCredentials();
        $this->run['credentials'] = $credentials;

        try {
            return $this->invokeDriver($driver, new PreparedPost($this->platform, $this->post, $credentials), $state);
        } catch (TemporaryException $again) {
            if (($again->context['reresolve'] ?? false) === true) {
                throw $this->rejected($again);
            }

            throw $again;
        }
    }

    /** @param  array<string, mixed>|null  $state */
    protected function invokeDriver(SocialPlatform $driver, PreparedPost $prepared, ?array $state): mixed
    {
        return $state === null
            ? $driver->publish($prepared)
            : $driver->resume($prepared, $this->withReservedKeys($state));
    }

    /**
     * The state to run with: the continuation's own, else a 'committing' store
     * row (a commit phase that never finished: verify first), else the stored
     * pending state of a crashed chain. Flags the run as recovering whenever a
     * commit phase may be repeating.
     *
     * @return array<string, mixed>|null
     */
    protected function resumeState(IdempotencyStore $store, ?AtomicIdempotencyStore $atomic, string $key): ?array
    {
        $state = $this->state !== null ? Pending::withoutReservedKeys($this->state) : null;

        if ($state === null || $this->commits) {
            $row = $atomic?->committing($key);

            if ($row !== null) {
                $state ??= $this->restoreStoredState($row['state']);
                $this->commits = true;
                $this->recovering = true;
                $this->committingSince = (int) $row['since'];
            } elseif ($state === null) {
                $pending = $store->pendingState($key);
                $state = $pending !== null ? $this->restoreStoredState($pending) : null;
            }
        }

        // Redelivered after a worker died or timed out mid-run: whatever the
        // store, the create may have gone out.
        if ($state !== null && $this->commits && $this->attempts() > 1) {
            $this->recovering = true;
        }

        return $state;
    }

    /**
     * A commit step that already ran: its run recorded the step after it
     * (scheduleContinuation's markPending) but queuing that step failed, so
     * the queue re-ran this payload. Creating again would duplicate the post
     * (or, for a driver that cannot verify, fail a post that is going live).
     * Queue the recorded step instead, under its own step id: if the first
     * push went through after all, the two copies share one step lock and
     * handed-over marker, so only one runs.
     */
    protected function requeueRecordedStep(IdempotencyStore $store, ?AtomicIdempotencyStore $atomic, string $key): bool
    {
        if ($this->state === null || ! $this->commits || $atomic?->committing($key) !== null) {
            return false;
        }

        $stored = $store->pendingState($key);

        if ($stored === null || ! $this->isLaterStep($stored)) {
            return false;
        }

        // From here a failure must not forget a record whose post exists.
        $this->committed = $this->committed || (bool) ($stored['_committed'] ?? false);
        $int = static fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null;

        $this->dispatchNext($this->replicate([
            'state' => Pending::withoutReservedKeys($stored),
            'pollCount' => (int) $stored['_step'],
            'commits' => (bool) ($stored['_commits'] ?? false),
            'committed' => $this->committed,
            'deadline' => $int($stored['_deadline'] ?? null) ?? $this->deadline,
            'startedAt' => $this->startedAt ?? $int($stored[Pending::STARTED_AT] ?? null),
            'retries' => 0,
            'recovering' => false,
            'committingSince' => null,
        ]), 0);

        return true;
    }

    /**
     * The driver state plus the job's reserved keys (see Pending).
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function withReservedKeys(array $state): array
    {
        $reserved = [
            Pending::RECOVERING => $this->run['commit'] && $this->recovering,
            Pending::STARTED_AT => $this->startedAt ?? $this->now(),
            Pending::ATTEMPT => $this->attemptNumber(),
            Pending::RESTARTS => $this->restarts,
        ];

        if ($this->run['commit'] && $this->committingSince !== null) {
            $reserved[Pending::COMMITTING_SINCE] = $this->committingSince;
        }

        return Pending::withoutReservedKeys($state) + $reserved;
    }

    /**
     * What the store keeps for a step: the driver's state plus the job's own
     * markers, so a chain resumed from the store (crash, re-dispatch) knows
     * whether the step commits, whether the post exists, its clock, and which
     * step of which chain wrote it.
     *
     * @param  array<string, mixed>  $state
     * @param  ?int  $step  The poll number of the step the state is for (default: this run's).
     * @return array<string, mixed>
     */
    protected function storedState(array $state, bool $commits, bool $committed, ?int $deadline, ?int $step = null): array
    {
        return Pending::withoutReservedKeys($state) + array_filter([
            '_commits' => $commits ?: null,
            '_committed' => $committed ?: null,
            '_deadline' => $deadline,
            Pending::STARTED_AT => $this->startedAt,
            Pending::RESTARTS => $this->restarts ?: null,
            '_step' => $step ?? $this->pollCount,
            '_chain' => $this->ownerToken(),
        ], static fn ($value) => $value !== null);
    }

    /**
     * Whether a stored state is for a step this chain reached after this
     * one. States written before these markers existed never are.
     *
     * @param  array<string, mixed>  $stored
     */
    protected function isLaterStep(array $stored): bool
    {
        return ($stored['_chain'] ?? null) === $this->ownerToken()
            && is_numeric($stored['_step'] ?? null)
            && (int) ($stored[Pending::RESTARTS] ?? 0) === $this->restarts
            && (int) $stored['_step'] > $this->pollCount;
    }

    /**
     * Apply the markers of a stored state to this run and return the driver's part.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    protected function restoreStoredState(array $stored): array
    {
        $this->commits = $this->commits || (bool) ($stored['_commits'] ?? false);
        $this->committed = $this->committed || (bool) ($stored['_committed'] ?? false);
        $this->deadline ??= is_numeric($stored['_deadline'] ?? null) ? (int) $stored['_deadline'] : null;
        $this->startedAt ??= is_numeric($stored[Pending::STARTED_AT] ?? null) ? (int) $stored[Pending::STARTED_AT] : null;
        $this->restarts = max($this->restarts, (int) ($stored[Pending::RESTARTS] ?? 0));

        return Pending::withoutReservedKeys($stored);
    }

    protected function assertNotExpired(): void
    {
        // Past the window: never create. A post that exists (committed) or may
        // exist (recovering) still gets finished or verified.
        if ($this->notAfter === null || $this->mayHaveCreated() || $this->now() <= $this->notAfter) {
            return;
        }

        throw new PermanentException(
            'The publish window closed at '.gmdate(DATE_ATOM, $this->notAfter).' before the post could go out; nothing was published.',
            $this->platform,
            FailureReason::Expired,
            ['not_after' => gmdate(DATE_ATOM, $this->notAfter)],
        );
    }

    protected function resolveCredentials(): Credentials
    {
        $container = Container::getInstance();
        $resolver = $container->bound(CredentialsResolver::class)
            ? $container->make(CredentialsResolver::class)
            : new IdentityCredentialsResolver;

        return $resolver->resolve($this->platform, $this->credentials, $this->post->metadata);
    }

    // --- Outcomes ---------------------------------------------------------------

    /**
     * Zone B. The post exists: record it, announce it, queue the comment. Each
     * step is guarded; none of them may fail the job or re-run the publish.
     */
    protected function complete(SocialPlatform $driver, PostResult $result, bool $replayed): void
    {
        $store = $this->store();
        $key = $this->key();

        if (! $replayed) {
            try {
                $store->markPublished($key, $result);
            } catch (Throwable $e) {
                $this->reportQuietly($e);
            }
        }

        $this->releaseLease();
        $this->cleanupMedia();

        $result = $result->withMetadata($this->post->metadata);

        $this->safeDispatch(new PostPublished($result, $this->correlationId, $key, $this->attemptNumber(), $replayed));

        try {
            $this->dispatchComment($driver, $result);
        } catch (Throwable $e) {
            $this->reportQuietly($e);
            $this->commentNotQueued($result, $e);
        }
    }

    /**
     * On the 'sync' queue: sleep the platform's minimum comment delay, within
     * social.sync.max_wait. A longer minimum cannot be met synchronously, so
     * the comment is reported failed (Misconfigured) instead of being sent
     * early to fail.
     */
    protected function waitForSyncComment(int $minimum, string $postId): bool
    {
        $configured = static::config()('social.sync.max_wait', 120);
        $max = is_numeric($configured) ? max(0, (int) $configured) : 120;

        if ($minimum > $max) {
            $this->safeDispatch(new CommentFailed($this->platform, $postId, new PermanentException(
                "The 'sync' queue connection cannot delay the {$this->platform->value} first comment by the {$minimum}s the platform requires "
                ."(social.sync.max_wait is {$max}s). Use an asynchronous queue connection, or raise social.sync.max_wait.",
                $this->platform,
                FailureReason::Misconfigured,
                ['min_delay_seconds' => $minimum, 'sync_max_wait' => $max],
            ), $this->post->metadata));

            return false;
        }

        Sleep::for($minimum)->seconds();

        return true;
    }

    /**
     * The comment could not be queued (the queue push, the comment's unique
     * lock or the driver's commentRules() threw). The post stays a success;
     * the app still hears that its comment will not come.
     */
    protected function commentNotQueued(PostResult $result, Throwable $e): void
    {
        if ($this->post->comment === null || trim($this->post->comment) === '') {
            return;
        }

        $this->safeDispatch(new CommentFailed(
            $this->platform,
            (string) $result->platformPostId,
            new PermanentException(
                'The first comment could not be queued: '.$this->redact($e->getMessage()),
                $this->platform,
                FailureReason::Unknown,
                ['unexpected' => true, 'exception' => $e::class],
                $e,
            ),
            $this->post->metadata,
        ));
    }

    /**
     * Another owner holds the key, or the record is final. Published: replay.
     * Failed (a possibly published outcome was already reported): refuse to
     * re-post blind. Otherwise a live chain owns it: step aside quietly.
     */
    protected function claimRefused(SocialPlatform $driver, AtomicIdempotencyStore $store, string $key): void
    {
        $status = $store->status($key);

        if ($status === AtomicIdempotencyStore::STATUS_PUBLISHED) {
            $prior = $store->completed($key);

            if ($prior !== null) {
                $this->complete($driver, $prior, true);
            }

            return;
        }

        if ($status === AtomicIdempotencyStore::STATUS_FAILED) {
            $this->failJob(new PermanentException(
                'An earlier attempt with this idempotency key ended with an unknown outcome, so the post may already be live. '
                .'Check the platform before re-posting, or use a new idempotency key.',
                $this->platform,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'previously_failed' => true],
            ));

            return;
        }

        $this->safeDispatch(new PostDeduplicated($this->platform, $key, $this->post->metadata, $this->correlationId));
    }

    /**
     * The platform is still processing, or the publish has more steps. Record
     * the step, then dispatch the continuation, unless the deadline passed.
     */
    protected function scheduleContinuation(Pending $pending, Dispatcher $events): void
    {
        $now = $this->now();
        $startedAt = $this->startedAt ?? $now;
        $committed = $this->committed || $pending->committed;
        $declared = $pending->deadline ?? $this->deadline;
        $commitRun = (bool) ($this->run['commit'] ?? false);
        // The driver hands over to a verify step after a create whose answer
        // was lost: the post may exist.
        $sentCommit = $pending->commits && $pending->possiblySent;
        $deadline = $this->effectiveDeadline($declared, $startedAt, $pending->recheckAfter <= 0);
        $state = Pending::withoutReservedKeys($pending->state);
        $poll = $this->pollCount + 1;
        $late = $now >= $deadline || $poll > $this->pollCap($startedAt, $deadline);

        // The platform finished processing on the last look (the run at the
        // deadline): the create is ready now, so it runs once instead of the
        // processed media being thrown away as a timeout. Only from a step
        // that is not itself a create, so this cannot repeat.
        $grace = $late && $pending->commits && $pending->recheckAfter <= 0 && ! $commitRun && ! $this->commitSent;

        if ($late && ! $grace) {
            $context = ['deadline' => gmdate(DATE_ATOM, $deadline), 'elapsed_seconds' => $now - $startedAt, 'polls' => $this->pollCount];

            $error = match (true) {
                $committed => new PermanentException(
                    'The platform was still processing the post when the wait limit passed; it may still go live. Check the platform before re-posting.',
                    $this->platform,
                    FailureReason::ProcessingTimeout,
                    $context + ['possibly_published' => true],
                ),
                $sentCommit => new PermanentException(
                    'The wait limit passed while checking whether an earlier create went through; the post may be live. Check the platform before re-posting.',
                    $this->platform,
                    FailureReason::OutcomeUnknown,
                    $context + ['possibly_published' => true],
                ),
                default => new TemporaryException(
                    $pending->recheckAfter <= 0
                        ? 'Publishing timed out while the media was still being uploaded or prepared.'
                        : 'Publishing timed out while the platform was still processing the media.',
                    $this->platform,
                    FailureReason::Timeout,
                    null,
                    $context,
                ),
            };

            $this->committed = $committed;
            $this->finish($error, $error->possiblyPublished(), $state, true);

            return;
        }

        try {
            $this->store()->markPending($this->key(), $this->storedState($state, $pending->commits, $committed, $declared, $poll));
        } catch (Throwable $e) {
            // The continuation carries the state itself; the store copy only
            // helps a re-dispatch after a crash.
            $this->reportQuietly($e);
        }

        $next = $this->replicate([
            'state' => $state,
            'pollCount' => $poll,
            'commits' => $pending->commits,
            'committed' => $committed,
            'deadline' => $declared,
            'startedAt' => $startedAt,
            'retries' => 0,
            'recovering' => false,
            // A verify step searches from the original create time.
            'committingSince' => $sentCommit ? $this->committingSince : null,
            'commitSent' => $sentCommit,
        ]);

        // Every wait, a create step's too (media not ready, an earlier attempt
        // being verified, a temporary refusal); not the immediate hop into a create.
        if (! $pending->commits || $pending->recheckAfter > 0) {
            $this->safeDispatch(new PostProcessing(
                $this->platform,
                $poll,
                $now - $startedAt,
                $this->post->metadata,
                $this->correlationId,
                $pending->reason ?? match (true) {
                    $sentCommit => PostProcessing::REASON_VERIFYING,
                    ! $pending->commits => PostProcessing::REASON_PROCESSING,
                    default => null,
                },
                $this->identifiersOf($state),
            ));
        }

        $this->renewLease($this->delayFor($pending->recheckAfter));

        // Never sleep past the deadline: the run at the deadline gets one last
        // look before the timeout is reported.
        $this->dispatchNext($next, min($this->delayFor($pending->recheckAfter), max(0, $deadline - $now)));
    }

    /**
     * Keep this chain's claim on the key over the wait before its next step
     * (up to social.max_recheck_after, longer than the run lease), so a
     * re-dispatch or postNow() with the same key steps aside meanwhile
     * instead of running the same publish in parallel. Best effort.
     */
    protected function renewLease(int $delay): void
    {
        $store = $this->store();

        if (! $store instanceof AtomicIdempotencyStore || $delay <= 0) {
            return;
        }

        try {
            $store->claim($this->key(), $this->ownerToken(), min($delay, $this->maxRecheck()) + $this->timeoutSeconds() + 60);
        } catch (Throwable $e) {
            $this->reportQuietly($e);
        }
    }

    /**
     * Retry, restart or fail on a TemporaryException. Commit phases are never
     * release()d: the retry is a fresh continuation whose `_recovering` says
     * exactly whether this failure may have created the post.
     */
    protected function releaseOrFail(TemporaryException $e, Dispatcher $events, IdempotencyStore $idempotency, string $key): void
    {
        if (($e->context['restart'] ?? false) === true) {
            if ($this->restartMayDuplicate($e)) {
                // Starting over would create the post a second time.
                $this->finish(new PermanentException(
                    $e->getMessage().' Starting over is not safe: an earlier create of this post may have gone out. Check the platform before re-posting.',
                    $e->platform ?? $this->platform,
                    FailureReason::OutcomeUnknown,
                    ['possibly_published' => true, 'restart_refused' => true] + array_diff_key($e->context, ['restart' => true]),
                    $e,
                ), true, $this->run['state'] ?? null, true);

                return;
            }

            $this->restart($e);

            return;
        }

        if (($e->context['reresolve'] ?? false) === true) {
            // Raised outside the driver call (the resolver itself) or already retried.
            $this->onPermanent($this->rejected($e));

            return;
        }

        $commit = (bool) ($this->run['commit'] ?? false);
        $attempt = $this->attemptNumber();
        $ambiguous = ($this->run['sent'] ?? false) && $this->isAmbiguous($e);
        $recovering = $this->recovering || ($commit && $ambiguous);

        if ($attempt >= $this->tries || $this->cannotRelease()) {
            $explicit = array_key_exists('possibly_published', $e->context);
            $possibly = $explicit ? $e->possiblyPublished() : ($this->committed || ($commit && ($recovering || $this->commitSent)));

            $this->finish($e, $possibly, $this->run['state'] ?? null, true);

            return;
        }

        $delay = $e->retryAfter !== null
            ? min(max(0, $e->retryAfter), static::MAX_RETRY_DELAY)
            : $this->backoffFor($attempt);

        $this->safeDispatch(new PostRetrying(
            $this->platform,
            $e->errorSummary(),
            $attempt,
            now()->addSeconds($delay),
            $this->post->metadata,
            $this->correlationId,
        ));

        $state = $this->run['state'] ?? null;

        if ($commit && $state !== null && ! $recovering && $idempotency instanceof AtomicIdempotencyStore) {
            // The create provably did not happen: reopen the record as pending,
            // so the retry is not mistaken for a repeat of a sent create.
            try {
                $idempotency->markPending($key, $this->storedState($state, true, $this->committed, $this->deadline));
            } catch (Throwable $inner) {
                $this->reportQuietly($inner);
            }
        }

        if ($commit && $state !== null) {
            $this->dispatchNext($this->replicate([
                'state' => $state,
                'retries' => $attempt,
                'recovering' => $recovering,
                'commits' => true,
                'committingSince' => $this->committingSince,
            ]), $delay);

            return;
        }

        // The released copy is this same step (same lock): free the lock
        // first, or a worker that picks up a zero-delay retry before this run
        // returns would find it held. The step is not handed over.
        $this->releaseRunGuard();
        $this->run['released'] = true;
        $this->release($delay);
    }

    /**
     * Whether a restart (fresh publish) asked for by a commit phase could
     * duplicate the post: the driver did not say the post cannot exist, and
     * this run repeats or follows a create that may have gone out, or its own
     * create may have been sent.
     */
    protected function restartMayDuplicate(TemporaryException $e): bool
    {
        if (array_key_exists('possibly_published', $e->context)) {
            return $e->possiblyPublished();
        }

        if (! ($this->run['commit'] ?? false)) {
            // Uploads and polls: the driver knows what its restart discards.
            return false;
        }

        return $this->recovering || $this->commitSent
            || (($this->run['sent'] ?? false) && $this->isAmbiguous($e));
    }

    /** Drop the state and publish again from scratch, within social.max_restarts. */
    protected function restart(TemporaryException $e): void
    {
        $restarts = $this->restarts + 1;
        $max = max(0, (int) static::config()('social.max_restarts', 3));

        if ($restarts > $max) {
            $this->onPermanent(new PermanentException(
                $e->getMessage()." (gave up after {$this->restarts} restart(s)).",
                $this->platform,
                $e->reason,
                ['restarts' => $this->restarts] + array_diff_key($e->context, ['restart' => true]),
                $e,
            ));

            return;
        }

        try {
            $this->store()->forget($this->key());
        } catch (Throwable $inner) {
            $this->reportQuietly($inner);
        }

        $this->safeDispatch(new PostRetrying(
            $this->platform,
            $e->errorSummary(),
            $this->attemptNumber(),
            now()->addSeconds(max(0, $e->retryAfter ?? 0)),
            $this->post->metadata,
            $this->correlationId,
        ));

        $this->dispatchNext($this->replicate([
            'state' => null,
            'pollCount' => 0,
            'restarts' => $restarts,
            'retries' => 0,
            'commits' => false,
            'committed' => false,
            'recovering' => false,
            'committingSince' => null,
            'commitSent' => false,
            'deadline' => null,
            'startedAt' => null,
            'skipValidation' => true,
        ]), max(0, $e->retryAfter ?? 0));
    }

    protected function onPermanent(SocialPosterException $e): void
    {
        $possibly = array_key_exists('possibly_published', $e->context)
            ? $e->possiblyPublished()
            : $this->mayHaveCreated();

        $this->finish($e, $possibly, $this->run['state'] ?? null, ! $e instanceof KeyCollisionException);
    }

    /**
     * A throwable from outside the package's taxonomy. Programming errors
     * (\Error, LogicException) are permanent; anything else (store, cache,
     * process, I/O) is treated as temporary. Either way, when it hit a commit
     * phase after the driver was called, the post may exist.
     */
    protected function onUnexpected(Throwable $e): void
    {
        $sent = (bool) ($this->run['sent'] ?? false);
        $commit = (bool) ($this->run['commit'] ?? false);
        $message = $this->redact($e->getMessage());
        $context = ['unexpected' => true, 'exception' => $e::class];

        if ($e instanceof \Error || $e instanceof LogicException) {
            $possibly = $this->committed || ($sent && $commit);

            $this->finish(new PermanentException(
                $possibly
                    ? "The {$this->platform->value} publish stopped with an unexpected error during a create step; the post may be live. {$message}"
                    : "The {$this->platform->value} publish failed with an unexpected error: {$message}",
                $this->platform,
                $possibly ? FailureReason::OutcomeUnknown : FailureReason::Unknown,
                $context + ($possibly ? ['possibly_published' => true] : []),
                $e,
            ), $possibly, $this->run['state'] ?? null, true);

            return;
        }

        $this->releaseOrFail(new TemporaryException(
            "The {$this->platform->value} publish hit an unexpected error: {$message}",
            $this->platform,
            FailureReason::Unknown,
            null,
            $context,
            $e,
        ), $this->events(), $this->store(), $this->key());
    }

    /**
     * Final failure: keep a possibly published record for reconcile (status
     * 'failed'), otherwise forget the key so a fresh post can proceed; then
     * fail the job, which reports PostFailed from failed().
     *
     * @param  array<string, mixed>|null  $state
     */
    protected function finish(SocialPosterException $e, bool $possiblyPublished, ?array $state, bool $forget): void
    {
        $store = $this->store();
        $key = $this->run['key'] ?? $this->idempotencyKey();
        $this->addIdentifiers($e, $state ?? $this->state);

        if ($possiblyPublished) {
            $e->withFlags(['possibly_published' => true]);

            if ($store instanceof AtomicIdempotencyStore) {
                try {
                    $store->markFailed($key, $state !== null ? $this->storedState($state, $this->commits, $this->committed, $this->deadline) : null);
                } catch (Throwable $inner) {
                    $this->reportQuietly($inner);
                }
            }
        } elseif ($forget) {
            try {
                $store->forget($key);
            } catch (Throwable $inner) {
                $this->reportQuietly($inner);
            }
        }

        if ($forget || $possiblyPublished) {
            $this->releaseLease();
        }

        $this->cleanupMedia();
        $this->failJob($e);
    }

    /**
     * Name the ids the platform already gave (context['identifiers']), from the
     * step's state, else the failure's own context (a failure in the very run
     * that got them). An existing 'identifiers' key is kept.
     *
     * @param  array<string, mixed>|null  $state
     */
    protected function addIdentifiers(SocialPosterException $e, ?array $state): void
    {
        $identifiers = $this->identifiersOf($state) + $this->identifiersOf($e->context);

        if ($identifiers !== []) {
            $e->addContext(['identifiers' => $identifiers]);
        }
    }

    /**
     * The chain is over: delete the temporary files of this process's gateway
     * (downloads) and transformer (conversions). Not between steps, since a
     * later run on this worker may still read them; drivers also clean up
     * after themselves, and social-poster:prune-temp is the backstop.
     */
    protected function cleanupMedia(): void
    {
        $container = Container::getInstance();

        foreach ([MediaGateway::class, MediaTransformer::class] as $abstract) {
            try {
                if (! $container->bound($abstract)) {
                    continue;
                }

                $service = $container->make($abstract);

                if (is_object($service) && method_exists($service, 'cleanup')) {
                    $service->cleanup();
                }
            } catch (Throwable) {
                // Best effort.
            }
        }
    }

    /**
     * Mark the job failed so failed() reports it. Without a real queue job
     * (handle() called directly, a FakeJob), failed() is called here instead.
     */
    protected function failJob(Throwable $e): void
    {
        if (is_array($this->run)) {
            $this->run['failed'] = true;
        }

        if ($this->job !== null && ! $this->job instanceof FakeJob) {
            $this->fail($e);

            return;
        }

        if ($this->job instanceof FakeJob) {
            $this->fail($e);
        }

        $this->failed($e);
    }

    /**
     * The single place PostFailed (and CredentialsRejected) is dispatched:
     * after handle() failed the job, or when the worker gave up on it (timeout,
     * too many attempts). For the latter, a run that was in a commit phase may
     * have published: PostFailed(OutcomeUnknown, possiblyPublished) and the
     * store row is kept as 'failed' for reconcile. A key already published
     * reports nothing (the post is live and PostPublished was sent), nor does
     * a redelivered copy of a step another run already handed over. A store
     * that cannot be read does not silence the report.
     */
    public function failed(?Throwable $e = null): void
    {
        try {
            $key = $this->idempotencyKey();
            $store = $this->resolveStore();

            if ($e instanceof SocialPosterException) {
                $exception = $e;
            } else {
                if ($this->publishedInStore($store, $key) || $this->stepHandedOver()) {
                    return;
                }

                $exception = $this->wrapUnexpectedFailure($e, $store, $key);
            }

            $events = $this->events();

            $this->safeDispatch(new PostFailed(
                $this->platform,
                $exception,
                $this->post->metadata,
                $this->correlationId,
                $key,
                $this->attemptNumber(),
                false,
                $exception->possiblyPublished(),
            ), $events);

            if ($exception instanceof PermanentException && $exception->needsReconnect()) {
                $this->safeDispatch(new CredentialsRejected(
                    $this->platform,
                    $this->accountKeyOf(null, $this->credentials),
                    $exception->reason,
                    $exception->isTerminal(),
                    $this->post->metadata,
                    $exception->errorSummary(),
                ), $events);
            }
        } catch (Throwable $inner) {
            $this->reportQuietly($inner);
        }
    }

    /** Whether the store records the key as published; false when it cannot tell. */
    protected function publishedInStore(?IdempotencyStore $store, string $key): bool
    {
        try {
            return $store !== null && $store->completed($key) !== null;
        } catch (Throwable $inner) {
            $this->reportQuietly($inner);

            return false;
        }
    }

    protected function wrapUnexpectedFailure(?Throwable $e, ?IdempotencyStore $store, string $key): PermanentException
    {
        $status = null;

        if ($store instanceof AtomicIdempotencyStore) {
            try {
                $status = $store->status($key);
            } catch (Throwable) {
                $status = null;
            }
        }

        $commit = $this->commits || $this->committed || $status === AtomicIdempotencyStore::STATUS_COMMITTING;
        $timeout = $e !== null && is_a($e, 'Illuminate\Queue\TimeoutExceededException');
        $what = $timeout ? 'timed out' : ($e !== null ? 'stopped ('.class_basename($e).')' : 'stopped');
        // The queue gave up on a step it re-runs after a kill (the YouTube
        // upload): the last kill may have hit its final request.
        $killedRerun = ! $commit && ! $this->failOnTimeout && $e !== null && is_a($e, 'Illuminate\Queue\MaxAttemptsExceededException');
        $possibly = $commit || $killedRerun;

        $exception = new PermanentException(
            match (true) {
                $commit => "The {$this->platform->value} publish {$what} during a create step; the post may be live. Check the platform before re-posting.",
                $killedRerun => "The {$this->platform->value} publish {$what} during an upload step that may have sent its last part; the post may be live. Check the platform before re-posting.",
                default => "The {$this->platform->value} publish {$what}".($e !== null ? ': '.$this->redact($e->getMessage()) : '.'),
            },
            $this->platform,
            $possibly ? FailureReason::OutcomeUnknown : ($timeout ? FailureReason::Timeout : FailureReason::Unknown),
            ['unexpected' => true, 'exception' => $e !== null ? $e::class : null, 'timeout' => $timeout]
                + ($possibly ? ['possibly_published' => true] : []),
            $e,
        );

        $this->addIdentifiers($exception, $this->state);

        if ($store !== null) {
            try {
                if ($possibly && $store instanceof AtomicIdempotencyStore) {
                    $store->markFailed($key, $this->state !== null
                        ? $this->storedState($this->state, $this->commits, $this->committed, $this->deadline)
                        : null);
                } elseif (! $possibly) {
                    $store->forget($key);
                }

                if ($store instanceof AtomicIdempotencyStore) {
                    $store->release($key, $this->ownerToken());
                }
            } catch (Throwable $inner) {
                $this->reportQuietly($inner);
            }
        }

        return $exception;
    }

    /** The permanent form of a token rejection that survived one re-resolve. */
    protected function rejected(TemporaryException $e): PermanentException
    {
        $previous = $e->getPrevious();

        if ($previous instanceof PermanentException) {
            return $previous->withFlags(['reresolved' => true] + (array_key_exists('possibly_published', $e->context)
                ? ['possibly_published' => $e->possiblyPublished()]
                : []));
        }

        return new PermanentException(
            $e->getMessage(),
            $e->platform ?? $this->platform,
            FailureReason::InvalidToken,
            ['reresolved' => true] + array_diff_key($e->context, ['reresolve' => true]),
            $e,
        );
    }

    /**
     * Whether a failed try of a commit phase may still have created the post.
     * Provably unsent requests and explicit refusals (4xx, rate limits, token
     * rejections) cannot have; a connection lost after sending, a 5xx, or
     * anything unclassified may have.
     */
    protected function isAmbiguous(TemporaryException $e): bool
    {
        if ($e->possiblyPublished()) {
            return true;
        }

        $context = $e->context;

        if (($context['request_sent'] ?? null) === false || ($context['reresolve'] ?? false) === true) {
            return false;
        }

        if ($e->reason === FailureReason::RateLimited || $e->reason === FailureReason::InvalidToken) {
            return false;
        }

        $status = $context['http_status'] ?? $context['status'] ?? null;

        return ! (is_numeric($status) && (int) $status >= 400 && (int) $status < 500);
    }

    // --- Comments ---------------------------------------------------------------

    /**
     * Queue the first comment for a live post, or say why not (CommentSkipped):
     * blank text, a post that is scheduled or a draft, no post id, no comment
     * support, a variant that takes none, or a comment an earlier run already
     * added. A post still processing when the wait ran out (it usually goes
     * live) gets its comment after social.comment_processing_delay (300 s).
     */
    protected function dispatchComment(SocialPlatform $driver, PostResult $result): void
    {
        $comment = $this->post->comment;

        if ($comment === null) {
            return;
        }

        $postId = $result->platformPostId !== null && trim($result->platformPostId) !== '' ? $result->platformPostId : null;
        $skip = fn (string $reason, ?string $id = null) => $this->safeDispatch(new CommentSkipped($this->platform, $id, $reason, $this->post->metadata));

        if (trim($comment) === '') {
            $skip(CommentSkipped::REASON_EMPTY, $postId);

            return;
        }

        $skipped = $this->run['skip_comment'] ?? null;

        if (is_string($skipped)) {
            $skip($skipped, $postId);

            return;
        }

        if (! $result->isPublished() && ! $result->isProcessing()) {
            $skip(CommentSkipped::REASON_SCHEDULED, $postId);

            return;
        }

        if ($postId === null) {
            $skip(CommentSkipped::REASON_NO_POST_ID);

            return;
        }

        if (! $driver instanceof SupportsFirstComment && ! $driver instanceof SupportsComments) {
            $skip(CommentSkipped::REASON_UNSUPPORTED, $postId);

            return;
        }

        $key = $this->key();

        try {
            $done = $this->store()->completed(AddFirstCommentJob::commentKeyFor($key)) !== null;
        } catch (Throwable $e) {
            // Unreadable is not done: the comment job checks again and is unique.
            $this->reportQuietly($e);
            $done = false;
        }

        if ($done) {
            $skip(CommentSkipped::REASON_ALREADY_DONE, $postId);

            return;
        }

        $config = static::config();
        $delay = max(0, (int) $config('social.comment_delay', 60));
        $minimum = 0;

        if ($driver instanceof SupportsFirstComment) {
            $credentials = $this->run['credentials'] ?? null;
            $context = new PreparedPost($this->platform, $this->post, $credentials instanceof Credentials ? $credentials : $this->credentials);
            $rules = $driver->commentRules($context, $result);

            if ($rules === null) {
                $skip(CommentSkipped::REASON_STORY, $postId);

                return;
            }

            $minimum = $rules->minDelaySeconds;
            $delay = $rules->delay($delay);
        }

        if ($result->isProcessing()) {
            $delay = max($delay, (int) $config('social.comment_processing_delay', 300));
        }

        $job = new AddFirstCommentJob(
            $this->platform,
            $postId,
            $comment,
            $this->credentials,
            $this->post->metadata,
            $this->post,
            $result->withMetadata([]),
            $key,
            $this->correlationId,
        );

        $this->propagateQueue($job);
        $job->uniqueFor = max($job->uniqueFor, $delay + 3600);

        if ($this->connectionIsSync($job->connection)) {
            // The sync queue runs the comment right away and ignores delays.
            // Wait out the platform's minimum here (LinkedIn answers 404 to a
            // comment on a post a few seconds old), as postNow() does; the
            // configured comment_delay is a preference and is not waited.
            if ($minimum > 0 && ! $this->waitForSyncComment($minimum, $postId)) {
                return;
            }
        } elseif ($delay > 0) {
            $job->delay($delay);
        }

        // Through PendingDispatch, so the comment's unique lock drops a
        // duplicate queued by a replay of this publish.
        dispatch($job);
    }

    // --- Queue plumbing ---------------------------------------------------------

    /**
     * A copy of this job for the next step, keeping the chain (key, owner,
     * clock, flags, connection, queue) unless $overrides says otherwise.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function replicate(array $overrides = []): static
    {
        $o = $overrides + [
            'skipValidation' => true,
            'state' => $this->run['state'] ?? $this->state,
            'pollCount' => $this->pollCount,
            'commits' => $this->commits,
            'committed' => $this->committed,
            'deadline' => $this->deadline,
            'startedAt' => $this->startedAt,
            'restarts' => $this->restarts,
            'retries' => $this->retries,
            'recovering' => $this->recovering,
            'committingSince' => $this->committingSince,
            'commitSent' => $this->commitSent,
        ];

        $int = static fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null;

        $next = new static(
            $this->platform,
            $this->post,
            $this->credentials,
            (bool) $o['skipValidation'],
            is_array($o['state']) ? $o['state'] : null,
            (int) $o['pollCount'],
            $this->correlationId,
            $this->idempotencyKey(),
            $this->notAfter,
        );

        $next->owner = $this->ownerToken();
        $next->commits = (bool) $o['commits'];
        $next->committed = (bool) $o['committed'];
        $next->deadline = $int($o['deadline']);
        $next->startedAt = $int($o['startedAt']);
        $next->restarts = (int) $o['restarts'];
        $next->retries = (int) $o['retries'];
        $next->recovering = (bool) $o['recovering'];
        $next->committingSince = $int($o['committingSince']);
        $next->commitSent = (bool) $o['commitSent'];
        $next->notBefore = null;
        $next->maxPolls = $this->maxPolls;
        $next->failOnTimeout = ! $this->retriesKilledRun($next->state);

        $this->propagateQueue($next);

        return $next;
    }

    /**
     * Whether the driver runs a step with $state again safely after the queue
     * killed it (AbstractPlatform::retriesKilledRun()). False when it cannot
     * tell: a killed run then fails, as before.
     *
     * @param  array<string, mixed>|null  $state
     */
    protected function retriesKilledRun(?array $state): bool
    {
        if ($state === null) {
            return false;
        }

        try {
            return $this->platformDriver()?->retriesKilledRun(Pending::withoutReservedKeys($state)) ?? false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The ids the platform already gave for this publish
     * (AbstractPlatform::identifiersOf()), from a driver state or a failure's
     * context: non-empty strings only, and empty when the driver cannot tell.
     *
     * @param  array<string, mixed>|null  $state
     * @return array<string, string>
     */
    protected function identifiersOf(?array $state): array
    {
        if ($state === null || $state === []) {
            return [];
        }

        try {
            $identifiers = $this->platformDriver()?->identifiersOf(Pending::withoutReservedKeys($state)) ?? [];
        } catch (Throwable) {
            return [];
        }

        return array_filter(
            $identifiers,
            static fn ($value, $key) => is_string($key) && is_string($value) && $value !== '',
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** This run's driver, else the platform's, when it is a first-party style AbstractPlatform. */
    protected function platformDriver(): ?AbstractPlatform
    {
        try {
            $driver = is_array($this->run) ? ($this->run['driver'] ?? null) : null;
            $driver ??= Container::getInstance()->make(SocialManager::class)->driver($this->platform->value);
        } catch (Throwable) {
            return null;
        }

        return $driver instanceof AbstractPlatform ? $driver : null;
    }

    protected function propagateQueue(object $job): void
    {
        if (! empty($this->connection) && property_exists($job, 'connection')) {
            $job->connection = $this->connection;
        }

        if (! empty($this->queue) && property_exists($job, 'queue')) {
            $job->queue = $this->queue;
        }
    }

    /**
     * Push a continuation straight to the queue: no unique-lock check (a stale
     * lock must never drop a chain). Waits longer than social.max_recheck_after
     * are chained through notBefore. On the 'sync' connection the wait happens
     * here, since the sync queue ignores delays.
     */
    protected function dispatchNext(self $next, int $delay): void
    {
        $max = $this->maxRecheck();

        if ($delay > $max) {
            $next->notBefore = $this->now() + $delay;
            $delay = $max;
        }

        if ($this->isSyncConnection($next->connection)) {
            if ($delay > 0) {
                Sleep::for($delay)->seconds();
            }

            $delay = 0;
            $next->notBefore = null;
        }

        if ($delay > 0) {
            $next->delay($delay);
        }

        $bus = Container::getInstance()->make(BusDispatcher::class);

        if ($bus instanceof QueueingDispatcher) {
            $bus->dispatchToQueue($next);

            return;
        }

        $bus->dispatch($next);
    }

    /** The 'sync' queue (and handle() called without a queue) cannot release or delay. */
    protected function cannotRelease(): bool
    {
        return $this->job === null || $this->job instanceof SyncJob;
    }

    protected function isSyncConnection(?string $connection): bool
    {
        return $this->job instanceof SyncJob || $this->connectionIsSync($connection);
    }

    /** Whether a queue connection (null = the default) uses the 'sync' driver. */
    protected function connectionIsSync(?string $connection): bool
    {
        $config = static::config();
        $name = $connection ?: $config('queue.default');

        return is_string($name) && $config("queue.connections.{$name}.driver") === 'sync';
    }

    /** Recheck delay: 0 means now; otherwise at least 5 seconds. */
    protected function delayFor(int $recheckAfter): int
    {
        return $recheckAfter <= 0 ? 0 : max(5, $recheckAfter);
    }

    protected function maxRecheck(): int
    {
        $configured = static::config()('social.max_recheck_after', 900);

        return max(5, is_numeric($configured) ? (int) $configured : 900);
    }

    /**
     * The wall-clock limit for this chain: the driver's declared deadline, else
     * social.async.max_wait_per_platform.<platform>, else social.async.max_wait;
     * never more than a week, and at most social.sync.max_wait on 'sync'.
     * $progress (a step that runs again at once: the next upload slice, a
     * create that is ready) is not a wait on the platform, so on 'sync' it is
     * bounded by the deadline alone, as v1.0's inline uploads were.
     */
    protected function effectiveDeadline(?int $declared, int $startedAt, bool $progress = false): int
    {
        $deadline = min($declared ?? ($startedAt + $this->maxWait()), $startedAt + static::MAX_DEADLINE_SECONDS);

        if (! $progress && $this->isSyncConnection($this->connection)) {
            $sync = static::config()('social.sync.max_wait', 120);
            $deadline = min($deadline, $startedAt + (is_numeric($sync) ? max(0, (int) $sync) : 120));
        }

        return $deadline;
    }

    protected function maxWait(): int
    {
        $config = static::config();
        $platform = $config('social.async.max_wait_per_platform.'.$this->platform->value);
        $global = $config('social.async.max_wait', 3600);

        if (is_numeric($platform) && (int) $platform > 0) {
            return (int) $platform;
        }

        return is_numeric($global) && (int) $global > 0 ? (int) $global : 3600;
    }

    /** A safety net derived from the deadline (one poll per 5 s at most), never below $maxPolls. */
    protected function pollCap(int $startedAt, int $deadline): int
    {
        return max($this->maxPolls, (int) ceil(max(0, $deadline - $startedAt) / 5) + 10);
    }

    /** The attempt number of this step: queue attempts plus re-dispatched tries. */
    protected function attemptNumber(): int
    {
        return $this->retries + max(1, (int) $this->attempts());
    }

    /** @return Lock|false|null A held lock, false when another run holds it, null when locks are unavailable. */
    protected function acquireOverlapGuard(): Lock|false|null
    {
        try {
            $locks = Cache::store(static::lockStoreName())->getStore();

            if (! $locks instanceof LockProvider) {
                return null;
            }

            $lock = $locks->lock($this->overlapLockKey(), $this->timeoutSeconds());

            return $lock->get() ? $lock : false;
        } catch (Throwable) {
            return null;
        }
    }

    protected function releaseGuard(Lock|false|null $guard): void
    {
        if ($guard instanceof Lock) {
            try {
                $guard->release();
            } catch (Throwable) {
                // Expires on its own.
            }
        }
    }

    /** Free this run's step lock (once). */
    protected function releaseRunGuard(): void
    {
        if (! is_array($this->run)) {
            return;
        }

        $guard = $this->run['guard'] ?? null;
        $this->run['guard'] = null;

        $this->releaseGuard($guard instanceof Lock ? $guard : null);
    }

    /** The marker a run leaves once this step handed the chain on (or published). */
    protected function stepDoneKey(): string
    {
        return 'social-poster:publish-step-done:'.hash('sha256', $this->idempotencyKey().'|'.$this->ownerToken().'|'.$this->stepId());
    }

    /**
     * Whether a run already finished this exact step: it queued the next
     * step or published. Released retries and failures do not count. v1.0
     * payloads share one owner token per key, so they are never marked.
     */
    protected function stepHandedOver(): bool
    {
        if ($this->owner === null) {
            return false;
        }

        try {
            return Cache::store(static::lockStoreName())->has($this->stepDoneKey());
        } catch (Throwable) {
            return false;
        }
    }

    protected function markStepHandedOver(): void
    {
        if ($this->owner === null) {
            return;
        }

        try {
            Cache::store(static::lockStoreName())->put($this->stepDoneKey(), 1, max(3600, 3 * $this->timeoutSeconds()));
        } catch (Throwable) {
            // Without the marker a late copy runs the step again; a commit
            // phase still verifies first (attempts > 1).
        }
    }

    protected function releaseLease(): void
    {
        $store = $this->store();

        if ($store instanceof AtomicIdempotencyStore) {
            try {
                $store->release($this->key(), $this->ownerToken());
            } catch (Throwable) {
                // The lease expires on its own.
            }
        }
    }

    protected function accountKeyOf(?SocialPlatform $driver, Credentials $credentials): string
    {
        try {
            $driver ??= Container::getInstance()->make(SocialManager::class)->driver($this->platform->value);

            return $driver instanceof AbstractPlatform ? $driver->accountKey($credentials) : '';
        } catch (Throwable) {
            return '';
        }
    }

    protected function events(): Dispatcher
    {
        $events = $this->run['events'] ?? null;

        return $events instanceof Dispatcher ? $events : Container::getInstance()->make(Dispatcher::class);
    }

    protected function store(): IdempotencyStore
    {
        $store = $this->run['store'] ?? null;

        return $store instanceof IdempotencyStore ? $store : Container::getInstance()->make(IdempotencyStore::class);
    }

    protected function resolveStore(): ?IdempotencyStore
    {
        try {
            return $this->store();
        } catch (Throwable) {
            return null;
        }
    }

    protected function key(): string
    {
        $key = $this->run['key'] ?? null;

        return is_string($key) ? $key : $this->idempotencyKey();
    }

    protected function safeDispatch(object $event, ?Dispatcher $events = null): void
    {
        try {
            ($events ?? $this->events())->dispatch($event);
        } catch (Throwable $e) {
            // A listener must never fail or re-run the publish.
            $this->reportQuietly($e);
        }
    }

    protected function reportQuietly(Throwable $e): void
    {
        try {
            report($e);
        } catch (Throwable) {
            // Reporting is best effort.
        }
    }

    protected function now(): int
    {
        return now()->getTimestamp();
    }

    protected function redact(string $message): string
    {
        return (string) preg_replace('/((?:access_token|token|key|signature|password)=)[^&\s"]+/i', '$1[redacted]', $message);
    }

    protected function logTransport(Throwable $e): void
    {
        if (! (bool) static::config()('social.error_logging', true)) {
            return;
        }

        $context = [
            'platform' => $this->platform->value,
            'transport' => true,
            'exception' => $e::class,
            'message' => $this->redact($e->getMessage()),
            'attempt' => $this->attemptNumber(),
            'tries' => $this->tries,
            'will_retry' => $this->attemptNumber() < $this->tries && ! $this->cannotRelease(),
        ];

        $message = '[SocialPoster] '.$this->platform->value.' transport error: '.$this->redact($e->getMessage());

        try {
            if ($channel = static::config()('social.error_log_channel')) {
                Log::channel($channel)->error($message, $context);
            } else {
                Log::error($message, $context);
            }
        } catch (Throwable) {
            // never let logging interfere with retry handling
        }
    }

    /**
     * The run collaborators are never part of the payload.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $values = $this->serializeProperties();
        unset($values["\0*\0run"]);

        return $values;
    }
}
