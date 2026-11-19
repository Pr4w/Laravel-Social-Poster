<?php

namespace SocialPoster\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Contracts\IdempotencyStore;
use SocialPoster\Contracts\SocialPlatform;
use SocialPoster\Contracts\SupportsComments;
use SocialPoster\Contracts\SupportsFirstComment;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\CommentFailed;
use SocialPoster\Events\CommentPublished;
use SocialPoster\Events\CommentSkipped;
use SocialPoster\Events\CredentialsRejected;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\Resolvers\IdentityCredentialsResolver;
use SocialPoster\SocialManager;
use SocialPoster\ValueObjects\CommentResult;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\SocialPost;
use Throwable;

/**
 * Adds a first comment to an already-published post. Dispatched by the publish
 * job once a post is confirmed live. Kept separate on purpose: the post has
 * already succeeded, so a comment failure here never retries or undoes it.
 *
 * Since 1.1 the job carries the full SocialPost (options included), the parent
 * PostResult (payload included, e.g. the Bluesky cid) and the publish's
 * idempotency key. The v1.0 shape (platform, postId, comment, credentials,
 * metadata) still works, for payloads already queued and for direct dispatch.
 *
 * Duplicate protection: ShouldBeUnique on the comment key while the job is
 * queued; a run lock on the comment key while it runs (a copy the queue
 * redelivers before the first run finished, or whose worker died, waits for
 * the lock and comes back); before calling the platform the job skips a
 * comment the store already records ('{key}:comment'); after success it
 * records it.
 *
 * Retries: social.comment_tries attempts, waiting the platform's Retry-After
 * (at most 600 s) or social.comment_backoff. Network errors and unexpected
 * throwables are retried as temporary. CommentFailed is dispatched once: for a
 * permanent error or the last temporary attempt (and from failed() when the
 * worker gives up), with CredentialsRejected as well when the refusal needs a
 * reconnect (rejected token, missing comment permission). The job is then
 * deleted, or failed in the queue when social.comment_fail_jobs is on.
 *
 * A parent that was still processing when the publish returned (a Facebook
 * Reel past the status-poll budget) is commented like a live one; the publish
 * job queues it after social.comment_processing_delay.
 *
 * Credentials are re-resolved at the start of every run; a rejected token gets
 * one immediate retry with re-resolved credentials when a custom resolver is
 * bound.
 *
 * An attempt whose answer was lost after the request went out (a connection
 * dropped after sending, a 5xx, a worker killed mid-call) may have added the
 * comment. The job records that a call is in flight (a "sent" marker in the
 * lock store) before it calls the platform, and forgets it after a success, a
 * clean refusal, or a request that provably never left. A run that finds the
 * marker is a recovery:
 *
 * - the driver gets the parent with payload `_recovering` = true
 *   (Pending::RECOVERING), as a publish commit phase gets it in its state. A
 *   driver that can look the comment up MUST do so before adding it again; one
 *   that cannot MUST throw PermanentException(OutcomeUnknown) with
 *   possibly_published = true instead of commenting twice. (Legacy
 *   SupportsComments drivers take no parent and cannot be told.)
 * - a duplicate refusal (FailureReason::DuplicateContent, e.g. X's 403
 *   "duplicate content") is read as the earlier attempt's comment:
 *   CommentPublished with a null id and payload recovered = true, never
 *   CommentFailed for a comment that is live;
 * - a failure reported after it carries possibly_published = true, as does a
 *   last attempt whose own answer was lost, and failed() reports a worker that
 *   died or timed out during the call as OutcomeUnknown (possibly published).
 *
 * The run timeout is derived per platform (configuredTimeout()) so a slow but
 * successful call (threads_publish can take well over a minute) is not
 * killed. A killed run is not failed at once (failOnTimeout stays false): its
 * marker survives, so the redelivered copy runs as a recovery and lets the
 * driver look the comment up.
 */
class AddFirstCommentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels {
        __serialize as protected serializeProperties;
    }

    /** Ceiling for a wait taken from the platform's Retry-After. */
    public const MAX_RETRY_DELAY = 600;

    public int $tries = 5;

    // --- 1.1 state. Non-readonly with defaults: v1.0 payloads lack them. ------

    /** The full post (options included); null for a v1.0-shaped job. */
    public ?SocialPost $post = null;

    /** The published parent (payload included); null for a v1.0-shaped job. */
    public ?PostResult $parent = null;

    /** The publish's idempotency key; the comment is recorded under '{key}:comment'. */
    public ?string $idempotencyKey = null;

    public ?string $correlationId = null;

    public int $uniqueFor = 7200;

    /** Seconds one run may take: social.comment_timeout, or derived per platform (configuredTimeout()). */
    public ?int $timeout = 120;

    /**
     * A run killed for timeout is redelivered after the queue's retry_after,
     * not failed at once: the sent marker it leaves makes that copy a
     * recovery, in which the driver looks for the comment before adding it.
     */
    public bool $failOnTimeout = false;

    public int $maxExceptions = 3;

    public bool $shouldBeEncrypted = false;

    /** The run lock held while handle() runs. Never serialized. */
    protected ?Lock $runLock = null;

    /** This run found the sent marker: an earlier attempt may have added the comment. Never serialized. */
    protected bool $recovering = false;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly Platform $platform,
        public readonly string $postId,
        public readonly string $comment,
        public readonly Credentials $credentials,
        public readonly array $metadata = [],
        ?SocialPost $post = null,
        ?PostResult $parent = null,
        ?string $idempotencyKey = null,
        ?string $correlationId = null,
    ) {
        $this->post = $post;
        $this->parent = $parent;
        $this->idempotencyKey = $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : null;
        $this->correlationId = $correlationId;

        $config = static::config();
        $tries = $config('social.comment_tries');
        $this->tries = is_numeric($tries) && (int) $tries > 0 ? (int) $tries : 5;
        $this->timeout = static::configuredTimeout($this->platform);
        $this->uniqueFor = max($this->uniqueFor, $this->timeout + 60);
        $this->shouldBeEncrypted = (bool) $config('social.encrypt_jobs', true) && filled($config('app.key'));

        $connection = $config('social.connection');
        $queue = $config('social.queue');

        if (is_string($connection) && $connection !== '') {
            $this->connection = $connection;
        }

        if (is_string($queue) && $queue !== '') {
            $this->queue = $queue;
        }
    }

    /** The store key a publish's first comment is recorded under. */
    public static function commentKeyFor(string $idempotencyKey): string
    {
        return $idempotencyKey.':comment';
    }

    /** This comment's store key: the publish key's, else one per platform post. */
    public function commentKey(): string
    {
        return $this->idempotencyKey !== null
            ? static::commentKeyFor($this->idempotencyKey)
            : 'social-poster:comment:'.$this->platform->value.':'.$this->postId;
    }

    public function uniqueId(): string
    {
        return $this->commentKey();
    }

    public function uniqueVia(): CacheRepository
    {
        $store = static::config()('social.lock_store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }

    /** The cache lock a run of this comment holds while it runs. */
    public function runLockKey(): string
    {
        return 'social-poster:comment-run:'.hash('sha256', $this->commentKey());
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['social-poster', 'social-poster:comment', 'social-poster:'.$this->platform->value];
    }

    /**
     * The run timeout of a platform's comment: social.comment_timeout when
     * set, else the longest single call (api.<platform>.publish_timeout or
     * social.publish_timeout, e.g. threads_publish; or the HTTP timeout when
     * longer) plus three more calls with their connect timeouts (a lookup, the
     * create, a status or permalink read), the reply-status waits
     * (api.<platform>.reply_status_polls x reply_status_interval) and 60 s of
     * margin. Never below v1.0's 120 s. The run lock lasts as long.
     */
    public static function configuredTimeout(Platform|string|null $platform = null): int
    {
        $config = static::config();
        $explicit = $config('social.comment_timeout');

        if (is_numeric($explicit) && (int) $explicit > 0) {
            return (int) $explicit;
        }

        $name = $platform instanceof Platform ? $platform->value : $platform;
        $api = $name !== null && $name !== '' ? (array) $config("social.api.{$name}", []) : [];
        $seconds = static fn ($value, int $default): int => is_numeric($value) && (int) $value > 0 ? (int) $value : $default;

        $http = $seconds($api['timeout'] ?? null, $seconds($config('social.http_timeout'), 60));
        $connect = $seconds($api['connect_timeout'] ?? null, 10);
        $publish = $seconds($api['publish_timeout'] ?? null, $seconds($config('social.publish_timeout'), 120));
        $waits = $seconds($api['reply_status_polls'] ?? null, 0) * $seconds($api['reply_status_interval'] ?? null, 0);

        return max(120, max($publish, $http) + 3 * ($http + $connect) + $waits + 60);
    }

    /** @return int[] */
    public function backoff(): array
    {
        $configured = static::config()('social.comment_backoff');
        $schedule = is_array($configured) ? array_values(array_filter($configured, 'is_numeric')) : [];

        return $schedule === [] ? [60, 120, 300, 600] : array_map('intval', $schedule);
    }

    public function handle(SocialManager $platforms, Dispatcher $events): void
    {
        $lock = $this->acquireRunLock();

        if ($lock === false) {
            // Another run of this comment holds the lock: still going (the
            // queue redelivered a slow run) or dead with the lock not yet
            // expired. Come back after it: the store then says whether the
            // comment was added. On 'sync' the holder is a live process.
            if ($this->job !== null && ! $this->job instanceof SyncJob) {
                $this->release($this->runLockSeconds() + 5);
            }

            return;
        }

        $this->runLock = $lock;

        try {
            $this->addComment($platforms, $events);
        } finally {
            $this->releaseRunLock();
        }
    }

    /** Check, call the platform, record: the body of handle() under the run lock. */
    protected function addComment(SocialManager $platforms, Dispatcher $events): void
    {
        $store = $this->store();
        $metadata = $this->metadata;

        try {
            if ($store?->completed($this->commentKey()) !== null) {
                $this->skip($events, CommentSkipped::REASON_ALREADY_DONE);

                return;
            }
        } catch (Throwable $e) {
            report($e);
        }

        $text = trim($this->comment);

        if ($text === '') {
            $this->skip($events, CommentSkipped::REASON_EMPTY);

            return;
        }

        if (trim($this->postId) === '') {
            $this->skip($events, CommentSkipped::REASON_NO_POST_ID, null);

            return;
        }

        $parent = $this->parent ?? PostResult::success($this->platform, $this->postId);

        if (! $parent->isPublished() && ! $parent->isProcessing()) {
            $this->skip($events, CommentSkipped::REASON_SCHEDULED);

            return;
        }

        try {
            $driver = $platforms->driver($this->platform->value);
        } catch (Throwable) {
            $this->skip($events, CommentSkipped::REASON_UNSUPPORTED);

            return;
        }

        if (! $driver instanceof SupportsFirstComment && ! $driver instanceof SupportsComments) {
            $this->skip($events, CommentSkipped::REASON_UNSUPPORTED);

            return;
        }

        // Read before this run writes its own marker (attempt()).
        $this->recovering = $this->sentMarked();

        try {
            $result = $this->attempt($driver, $parent, $text, $events);

            if ($result === null) {
                return;
            }
        } catch (ConnectionException $e) {
            $this->retryOrFail(TemporaryException::connection($this->platform, $e), $events);

            return;
        } catch (TemporaryException $e) {
            $this->retryOrFail($e, $events);

            return;
        } catch (SocialPosterException $e) {
            if (! $this->isEarlierAttempt($e)) {
                $this->settleSentMarker($e->possiblyPublished());
                $this->reportFailure($this->flagPossiblyPublished($e, $e->possiblyPublished()), $events);

                return;
            }

            $result = new CommentResult(null, null, ['recovered' => true, 'duplicate_refused' => true]);
        } catch (Throwable $e) {
            $this->retryOrFail(new TemporaryException(
                'Adding the first comment hit an unexpected error: '.$e->getMessage(),
                $this->platform,
                FailureReason::Unknown,
                null,
                ['unexpected' => true, 'exception' => $e::class],
                $e,
            ), $events);

            return;
        }

        // The comment exists: record it and announce it. Neither may retry it.
        try {
            $store?->markPublished($this->commentKey(), PostResult::success(
                $this->platform,
                $result->id,
                $result->url,
                $result->payload,
                PostResult::STATE_PUBLISHED,
                'comment',
            ));
        } catch (Throwable $e) {
            report($e);
        }

        $this->forgetSent();

        try {
            $events->dispatch(new CommentPublished($this->platform, $this->postId, $result->id, $metadata, $result->url, $result->payload));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Run the comment through the v2 contract when the driver has it, else
     * the legacy comment(). Null when the comment was skipped or refused
     * (already reported).
     */
    protected function attempt(SocialPlatform $driver, PostResult $parent, string $text, Dispatcher $events): ?CommentResult
    {
        $credentials = $this->resolveCredentials();
        $context = $this->context($credentials);

        if ($driver instanceof SupportsFirstComment) {
            $rules = $driver->commentRules($context, $parent);

            if ($rules === null) {
                $this->skip($events, CommentSkipped::REASON_STORY);

                return null;
            }

            $reason = $rules->skipReason($credentials);

            if ($reason !== null) {
                $this->skip($events, $reason);

                return null;
            }

            $errors = $rules->errors($text);

            if ($errors !== []) {
                $this->reportFailure(new PermanentException(
                    'The first comment was not posted: '.implode(' ', $errors),
                    $this->platform,
                    FailureReason::InvalidContent,
                    ['comment_errors' => $errors],
                ), $events);

                return null;
            }

            $target = $this->recovering ? $this->recoveringParent($parent) : $parent;
            $this->markSent();

            return $this->withReresolve(fn (PreparedPost $context) => $driver->addFirstComment($context, $target, $text), $context);
        }

        if ($driver instanceof SupportsComments) {
            $this->markSent();

            return $this->withReresolve(
                fn (PreparedPost $context) => new CommentResult($driver->comment($context, $this->postId, $text)),
                $context,
            );
        }

        $this->skip($events, CommentSkipped::REASON_UNSUPPORTED);

        return null;
    }

    /**
     * The parent as the driver sees it in a recovery: its payload carries the
     * reserved `_recovering` = true (Pending::RECOVERING), telling the driver
     * to look for an earlier attempt's comment before adding it again, or to
     * refuse (OutcomeUnknown, possibly_published) when it cannot look.
     */
    protected function recoveringParent(PostResult $parent): PostResult
    {
        $payload = $parent->payload;
        $payload[Pending::RECOVERING] = true;

        return new PostResult(
            $parent->platform,
            $parent->success,
            $parent->platformPostId,
            $parent->url,
            $payload,
            $parent->error,
            $parent->metadata,
            $parent->state,
            $parent->idKind,
        );
    }

    /**
     * Run $call once; a token rejection flagged reresolve gets one immediate
     * retry with re-resolved credentials, and a second one is final.
     *
     * @param  callable(PreparedPost): CommentResult  $call
     */
    protected function withReresolve(callable $call, PreparedPost $context): CommentResult
    {
        try {
            return $call($context);
        } catch (TemporaryException $e) {
            if (($e->context['reresolve'] ?? false) !== true) {
                throw $e;
            }
        }

        try {
            return $call($this->context($this->resolveCredentials()));
        } catch (TemporaryException $again) {
            if (($again->context['reresolve'] ?? false) !== true) {
                throw $again;
            }

            $previous = $again->getPrevious();

            throw $previous instanceof PermanentException
                ? $previous->withFlags(['reresolved' => true])
                : new PermanentException($again->getMessage(), $this->platform, FailureReason::InvalidToken, ['reresolved' => true], $again);
        }
    }

    protected function retryOrFail(TemporaryException $e, Dispatcher $events): void
    {
        $sent = $this->mayHaveBeenSent($e);
        $this->settleSentMarker($sent);

        if (($e->context['reresolve'] ?? false) === true) {
            $previous = $e->getPrevious();
            $this->reportFailure($this->flagPossiblyPublished($previous instanceof SocialPosterException ? $previous : new PermanentException(
                $e->getMessage(),
                $this->platform,
                FailureReason::InvalidToken,
                [],
                $e,
            ), false), $events);

            return;
        }

        if ($this->attempts() >= $this->tries || $this->job === null || $this->job instanceof SyncJob) {
            $this->reportFailure($this->flagPossiblyPublished($e, $sent), $events);

            return;
        }

        // The released copy needs the run lock: free it first, or a worker
        // that picks up a zero-delay retry before this run returns finds it held.
        $this->releaseRunLock();
        $this->release($e->retryAfter !== null
            ? min(max(0, $e->retryAfter), static::MAX_RETRY_DELAY)
            : $this->backoffFor($this->attempts()));
    }

    /**
     * Whether a failed attempt may still have added the comment: the request
     * went out and no clear refusal came back (a connection lost after
     * sending, a 5xx, anything unclassified). A driver that checked and found
     * nothing posted says so with possibly_published = false.
     */
    protected function mayHaveBeenSent(TemporaryException $e): bool
    {
        if ($e->possiblyPublished()) {
            return true;
        }

        if (array_key_exists('possibly_published', $e->context)) {
            return false;
        }

        if (($e->context['request_sent'] ?? null) === false || ($e->context['reresolve'] ?? false) === true) {
            return false;
        }

        if ($e->reason === FailureReason::RateLimited || $e->reason === FailureReason::InvalidToken) {
            return false;
        }

        $status = $e->context['http_status'] ?? $e->context['status'] ?? null;

        return ! (is_numeric($status) && (int) $status >= 400 && (int) $status < 500);
    }

    /**
     * A duplicate refusal in a recovery: the earlier attempt's comment. Read
     * from the marker found at the start of the run, never the one this run
     * wrote, so a first attempt's duplicate refusal stays a failure.
     */
    protected function isEarlierAttempt(SocialPosterException $e): bool
    {
        return $e->reason === FailureReason::DuplicateContent && $this->recovering;
    }

    /**
     * Mark a failure that may have left a live comment: this attempt's answer
     * was lost ($sent), or an earlier one's was (a recovery). A driver's own
     * possibly_published value is kept.
     *
     * @template T of SocialPosterException
     *
     * @param  T  $e
     * @return T
     */
    protected function flagPossiblyPublished(SocialPosterException $e, bool $sent): SocialPosterException
    {
        return $sent || $this->recovering ? $e->addContext(['possibly_published' => true]) : $e;
    }

    /**
     * After a failed call: keep the marker when this attempt may have added
     * the comment, or an earlier one may have (a recovery); forget it after a
     * clean refusal or a request that provably never left.
     */
    protected function settleSentMarker(bool $sent): void
    {
        if (! $sent && ! $this->recovering) {
            $this->forgetSent();
        }
    }

    /**
     * Record, before the platform call, that a call is in flight. A run killed
     * during it never clears the marker, so its redelivery is a recovery; it
     * is cleared after a success or a clean refusal. Lives for a day, longer
     * than any retry schedule of this job.
     */
    protected function markSent(): void
    {
        try {
            $this->uniqueVia()->put($this->sentMarkerKey(), 1, max(86400, $this->uniqueFor));
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function forgetSent(): void
    {
        try {
            $this->uniqueVia()->forget($this->sentMarkerKey());
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Whether an earlier attempt may have added the comment; false when the store cannot tell. */
    protected function sentMarked(): bool
    {
        try {
            return $this->uniqueVia()->has($this->sentMarkerKey());
        } catch (Throwable) {
            return false;
        }
    }

    /** The lock-store key of the marker set while a call to the platform may have added the comment. */
    public function sentMarkerKey(): string
    {
        return 'social-poster:comment-sent:'.hash('sha256', $this->commentKey());
    }

    /**
     * The single in-handle place CommentFailed is reported. With
     * social.comment_fail_jobs the job is failed in the queue and failed()
     * reports it instead, so it is never sent twice.
     */
    protected function reportFailure(SocialPosterException $e, Dispatcher $events): void
    {
        if ((bool) static::config()('social.comment_fail_jobs', false) && $this->job !== null && ! $this->job instanceof FakeJob) {
            $this->fail($e);

            return;
        }

        try {
            $events->dispatch(new CommentFailed($this->platform, $this->postId, $e, $this->metadata, max(1, (int) $this->attempts())));
        } catch (Throwable $inner) {
            report($inner);
        }

        $this->reportRejectedCredentials($e, $events);
        $this->delete();
    }

    /**
     * Reports a failure the worker decided on (timeout, too many attempts),
     * or the one handle() handed over under social.comment_fail_jobs. A
     * comment the store already records reports nothing; a store that cannot
     * be read does not silence the report. A worker that gave up while a call
     * to the platform may have added the comment (the sent marker is still
     * set) reports OutcomeUnknown with possibly_published = true.
     */
    public function failed(?Throwable $e = null): void
    {
        try {
            if (! $e instanceof SocialPosterException && $this->commentRecorded()) {
                return;
            }

            $exception = $e instanceof SocialPosterException ? $e : $this->workerFailure($e);

            $events = Container::getInstance()->make(Dispatcher::class);
            $events->dispatch(
                new CommentFailed($this->platform, $this->postId, $exception, $this->metadata, max(1, (int) $this->attempts())),
            );

            $this->reportRejectedCredentials($exception, $events);
        } catch (Throwable $inner) {
            report($inner);
        }
    }

    /** The failure to report for a worker that gave up (timeout, too many attempts, an escaped error). */
    protected function workerFailure(?Throwable $e): PermanentException
    {
        $timeout = $e !== null && is_a($e, 'Illuminate\Queue\TimeoutExceededException');
        $sent = $this->sentMarked();
        $context = ['unexpected' => true, 'exception' => $e !== null ? $e::class : null, 'timeout' => $timeout];

        if ($sent) {
            return new PermanentException(
                'Adding the first comment '.($timeout ? 'timed out' : 'stopped').' while a call to '.$this->platform->value
                .' may have posted it, so it was not tried again. Check the post before adding the comment by hand.',
                $this->platform,
                FailureReason::OutcomeUnknown,
                $context + ['possibly_published' => true],
                $e,
            );
        }

        return new PermanentException(
            'Adding the first comment '.($timeout ? 'timed out' : 'stopped').($e !== null ? ': '.$e->getMessage() : '.'),
            $this->platform,
            $timeout ? FailureReason::Timeout : FailureReason::Unknown,
            $context,
            $e,
        );
    }

    /**
     * A refusal that only a reconnect fixes (rejected token, missing comment
     * permission) is also reported as CredentialsRejected, as the publish job
     * does, so an app that drives its reconnect prompt from that event hears
     * about a connection that can publish but not comment.
     */
    protected function reportRejectedCredentials(SocialPosterException $e, Dispatcher $events): void
    {
        if (! $e instanceof PermanentException || ! $e->needsReconnect()) {
            return;
        }

        try {
            $events->dispatch(new CredentialsRejected(
                $this->platform,
                $this->accountKey(),
                $e->reason,
                $e->isTerminal(),
                $this->metadata,
                $e->errorSummary(),
            ));
        } catch (Throwable $inner) {
            report($inner);
        }
    }

    /** The driver's stable identity of the dispatched credentials ('' when unknown); never a token. */
    protected function accountKey(): string
    {
        try {
            $driver = Container::getInstance()->make(SocialManager::class)->driver($this->platform->value);

            return $driver instanceof AbstractPlatform ? $driver->accountKey($this->credentials) : '';
        } catch (Throwable) {
            return '';
        }
    }

    /** Whether the store records this comment; false when it cannot tell. */
    protected function commentRecorded(): bool
    {
        try {
            return $this->store()?->completed($this->commentKey()) !== null;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** @return Lock|false|null A held lock, false when another run holds it, null when locks are unavailable. */
    protected function acquireRunLock(): Lock|false|null
    {
        try {
            $locks = $this->uniqueVia()->getStore();

            if (! $locks instanceof LockProvider) {
                return null;
            }

            $lock = $locks->lock($this->runLockKey(), $this->runLockSeconds());

            return $lock->get() ? $lock : false;
        } catch (Throwable) {
            return null;
        }
    }

    protected function releaseRunLock(): void
    {
        $lock = $this->runLock;
        $this->runLock = null;

        try {
            $lock?->release();
        } catch (Throwable) {
            // Expires on its own.
        }
    }

    /** One run's limit: the job timeout (configuredTimeout(); 120 s for a job queued by v1.0). */
    protected function runLockSeconds(): int
    {
        return $this->timeout !== null && $this->timeout > 0 ? $this->timeout : 120;
    }

    protected function backoffFor(int $attempt): int
    {
        $schedule = $this->backoff();
        $index = max(0, min($attempt, count($schedule)) - 1);

        return $schedule[$index] ?? (int) end($schedule);
    }

    protected function skip(Dispatcher $events, string $reason, ?string $postId = ''): void
    {
        try {
            $events->dispatch(new CommentSkipped($this->platform, $postId === '' ? $this->postId : $postId, $reason, $this->metadata));
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function context(Credentials $credentials): PreparedPost
    {
        return new PreparedPost($this->platform, $this->post ?? new SocialPost(metadata: $this->metadata), $credentials);
    }

    protected function resolveCredentials(): Credentials
    {
        $container = Container::getInstance();
        $resolver = $container->bound(CredentialsResolver::class)
            ? $container->make(CredentialsResolver::class)
            : new IdentityCredentialsResolver;

        return $resolver->resolve($this->platform, $this->credentials, $this->metadata);
    }

    protected function store(): ?IdempotencyStore
    {
        try {
            return Container::getInstance()->make(IdempotencyStore::class);
        } catch (Throwable) {
            return null;
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

    /**
     * The run lock and the per-run recovery flag are never part of the payload.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $values = $this->serializeProperties();
        unset($values["\0*\0runLock"], $values["\0*\0recovering"]);

        return $values;
    }
}
