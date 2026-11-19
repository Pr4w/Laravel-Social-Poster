<?php

namespace SocialPoster;

use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use SocialPoster\Contracts\AtomicIdempotencyStore;
use SocialPoster\Contracts\IdempotencyStore;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\UnsupportedPlatformException;
use SocialPoster\Jobs\PublishToConnectionJob;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\SocialPost;
use Throwable;

/**
 * Entry point behind the SocialPoster facade. Each call starts a fresh,
 * immutable-by-convention builder.
 *
 * The provider builds it with $live = true: every new builder then reads the
 * current config('social') and event dispatcher instead of a snapshot taken
 * when the singleton was created, so runtime config changes, Event::fake() and
 * long-lived Octane workers see current values. A Poster constructed by hand
 * with an explicit $config keeps using that array.
 *
 * Besides starting builders it answers questions about earlier publishes,
 * by idempotency key: outcome() (the recorded result), status() (what the
 * store holds) and reconcile() (finish a publish whose outcome is unknown).
 * In tests, SocialPoster::fake() swaps it for a recording fake.
 */
class Poster
{
    /** reconcile(): a published result was found; PostPublished is dispatched again ($replayed = true). */
    public const RECONCILE_REPLAYED = 'replayed';

    /** reconcile(): a recovery job was queued; it verifies on the platform before creating anything. */
    public const RECONCILE_RESUMED = 'resumed';

    /** reconcile(): nothing is recorded under the key. */
    public const RECONCILE_NOTHING = 'nothing';

    /** reconcile(): the record can be resumed, but only with the original post (content and options). */
    public const RECONCILE_NEEDS_CONTENT = 'needs_content';

    /** reconcile(): the publish failed in a way no recorded state can verify; check the platform by hand. */
    public const RECONCILE_UNRECOVERABLE = 'unrecoverable';

    /**
     * @param  array<string, mixed>  $config  A fixed config('social') array; empty means read it live.
     * @param  bool  $live  Resolve config and the event dispatcher from the container per builder.
     */
    public function __construct(
        protected SocialManager $manager,
        protected Dispatcher $events,
        protected array $config = [],
        protected bool $live = false,
    ) {}

    public function on(string|Platform ...$platforms): PostBuilder
    {
        return $this->newBuilder()->on(...$platforms);
    }

    /**
     * @param  Credentials|array<string, mixed>  $credentials  One bag, or a map keyed by platform.
     * @param  string|Platform|null  $for  The platform these credentials are for (null: every platform).
     */
    public function using(Credentials|array $credentials, string|Platform|null $for = null): PostBuilder
    {
        return $this->newBuilder()->using($credentials, $for);
    }

    /**
     * The OAuth scopes a platform needs for a feature, as CNF groups (every
     * group required, any one member of a group suffices), e.g. to build a
     * connect URL. Some drivers depend on the credentials (login type, person
     * or organisation). Empty when the driver does not declare any.
     *
     * @param  Credentials|array<string, mixed>|null  $credentials
     * @return list<list<string>>
     */
    public function requiredScopes(Platform|string $platform, Feature $feature, Credentials|array|null $credentials = null): array
    {
        $driver = $this->manager->driver($platform instanceof Platform ? $platform->value : $platform);

        if (is_array($credentials)) {
            $credentials = Credentials::make($credentials);
        }

        return $driver instanceof AbstractPlatform ? $driver->scopesFor($feature, $credentials) : [];
    }

    /**
     * The recorded result of an earlier publish, or null when nothing
     * published is recorded (yet) under the key. $key is either the store key
     * the events and QueuedPost report (PostFailed::$idempotencyKey,
     * QueuedPost::key()) or your own ->idempotencyKey(); for the latter pass
     * the platform and, for an account-bound key, the credentials it was
     * published with.
     *
     * @param  Credentials|array<string, mixed>|null  $credentials
     */
    public function outcome(string $key, Platform|string|null $platform = null, Credentials|array|null $credentials = null): ?PostResult
    {
        $platform = $this->platformOrNull($platform);
        $store = $this->idempotencyStore();

        if ($store === null) {
            return null;
        }

        foreach ($this->lookupKeys($key, $platform, $credentials) as $candidate) {
            try {
                $result = $store->completed($candidate);
            } catch (Throwable $e) {
                $this->reportQuietly($e);

                continue;
            }

            if ($result !== null && ($platform === null || $result->platform === $platform)) {
                return $result;
            }
        }

        return null;
    }

    /**
     * What the idempotency store holds for the key: 'pending', 'committing',
     * 'published' or 'failed' (see AtomicIdempotencyStore), or null when
     * nothing is recorded. Keys as for outcome().
     *
     * @param  Credentials|array<string, mixed>|null  $credentials
     */
    public function status(string $key, Platform|string|null $platform = null, Credentials|array|null $credentials = null): ?string
    {
        $platform = $this->platformOrNull($platform);
        $store = $this->idempotencyStore();

        if ($store === null) {
            return null;
        }

        foreach ($this->lookupKeys($key, $platform, $credentials) as $candidate) {
            if ($store->completed($candidate) !== null) {
                return AtomicIdempotencyStore::STATUS_PUBLISHED;
            }

            $status = $store instanceof AtomicIdempotencyStore ? $store->status($candidate) : null;

            if ($status !== null) {
                return $status;
            }
        }

        return null;
    }

    /**
     * Finish a publish whose outcome is unknown: a PostFailed with
     * possiblyPublished (OutcomeUnknown, ProcessingTimeout), a worker that died
     * mid-publish, or a PostPublished listener that failed. Keys as for
     * outcome(). Returns one of the RECONCILE_* constants:
     *
     * - published record: a job replays it, dispatching PostPublished again
     *   with $replayed = true (and the first comment, when $post has one that
     *   was not added yet). Listeners must be idempotent;
     * - pending, committing or failed record: a job resumes the recorded state
     *   with `_recovering` set, so the driver checks the platform before it
     *   creates anything, and the outcome arrives as PostPublished / PostFailed.
     *   This needs the original $post (a later step may need its content);
     *   without it nothing is dispatched (RECONCILE_NEEDS_CONTENT). A failed
     *   record whose state was not kept (the create was the first call) cannot
     *   be verified automatically (RECONCILE_UNRECOVERABLE).
     *
     * Credentials default to config('social.platforms.<platform>.credentials');
     * a replay uses those only when they are the account the key is bound to
     * (it needs no token, and another account's would fail as a key
     * collision). A Credentials::ref() works with a CredentialsResolver. The
     * recovery job is pushed even inside a transaction that later rolls back.
     * The builder form,
     * SocialPoster::on(...)->using(...)->caption(...)->idempotencyKey(...)->reconcile(),
     * supplies all of it from your own records.
     *
     * @param  Credentials|array<string, mixed>|null  $credentials
     *
     * @throws InvalidArgumentException When the record needs a platform and none is given or recorded.
     */
    public function reconcile(string $key, Platform|string|null $platform = null, Credentials|array|null $credentials = null, ?SocialPost $post = null): string
    {
        $platform = $this->platformOrNull($platform);
        $store = $this->idempotencyStore();

        if ($store === null) {
            return self::RECONCILE_NOTHING;
        }

        foreach ($this->lookupKeys($key, $platform, $credentials) as $candidate) {
            $prior = $store->completed($candidate);

            if ($prior !== null) {
                if ($platform !== null && $prior->platform !== $platform) {
                    continue;
                }

                $this->dispatchRecovery($prior->platform, $candidate, $credentials ?? $this->replayCredentials($prior->platform, $store, $candidate), $post);

                return self::RECONCILE_REPLAYED;
            }

            if (! $store instanceof AtomicIdempotencyStore) {
                continue;
            }

            $status = $store->status($candidate);

            if ($status === null || $status === AtomicIdempotencyStore::STATUS_PUBLISHED) {
                continue;
            }

            $record = $this->storeRecord($store, $candidate);
            $recorded = is_string($record['platform'] ?? null) ? Platform::tryFrom($record['platform']) : null;
            $target = $platform ?? $recorded;

            if ($target === null) {
                throw new InvalidArgumentException("The publish recorded under this key is '{$status}'; pass its platform to reconcile it.");
            }

            if ($post === null) {
                return self::RECONCILE_NEEDS_CONTENT;
            }

            if ($status === AtomicIdempotencyStore::STATUS_FAILED) {
                $state = is_array($record['state'] ?? null) ? $record['state'] : null;

                if ($state === null || Pending::withoutReservedKeys($state) === []) {
                    return self::RECONCILE_UNRECOVERABLE;
                }

                // Reopen it with a fresh wait window (the old clock already ran
                // out): a commit phase as 'committing', so the job verifies first.
                unset($state['_deadline'], $state[Pending::STARTED_AT]);

                if ((bool) ($state['_commits'] ?? false)) {
                    $store->markCommitting($candidate, $state);
                } else {
                    $store->markPending($candidate, $state);
                }
            }

            $this->dispatchRecovery($target, $candidate, $credentials, $post);

            return self::RECONCILE_RESUMED;
        }

        return self::RECONCILE_NOTHING;
    }

    public function newBuilder(): PostBuilder
    {
        return new PostBuilder($this->manager, $this->events(), $this->config());
    }

    /**
     * The config the next builder uses: the fixed array given to the
     * constructor, else the live config('social').
     *
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        if ($this->config !== [] || ! $this->live) {
            return $this->config;
        }

        $container = Container::getInstance();

        return $container->bound('config') ? (array) $container->make('config')->get('social', []) : [];
    }

    protected function events(): Dispatcher
    {
        if (! $this->live) {
            return $this->events;
        }

        $container = Container::getInstance();
        $events = $container->bound('events') ? $container->make('events') : null;

        return $events instanceof Dispatcher ? $events : $this->events;
    }

    /**
     * The store keys a lookup tries, in order: the key as given (a store key
     * from an event or QueuedPost); for a caller key with a platform, the key
     * a 1.1 publish derived from it (platform + account), then v1.0's
     * "{key}:{platform}".
     *
     * @param  Credentials|array<string, mixed>|null  $credentials
     * @return list<string>
     */
    protected function lookupKeys(string $key, ?Platform $platform, Credentials|array|null $credentials): array
    {
        $keys = [$key];

        if ($platform !== null && ! str_starts_with($key, 'sp:')) {
            $keys[] = PublishToConnectionJob::idempotencyKeyFor($platform, new SocialPost, $this->credentialsFor($platform, $credentials), $key);
            $keys[] = $key.':'.$platform->value;
        }

        return array_values(array_unique($keys));
    }

    /**
     * Queue the job that replays or resumes a recorded publish. It goes
     * straight to the queue (no unique-lock check) and is flagged recovering,
     * so a commit phase verifies on the platform before creating.
     *
     * @param  Credentials|array<string, mixed>|null  $credentials
     */
    protected function dispatchRecovery(Platform $platform, string $key, Credentials|array|null $credentials, ?SocialPost $post): void
    {
        $job = new PublishToConnectionJob(
            $platform,
            $post ?? new SocialPost,
            $this->credentialsFor($platform, $credentials),
            true,
            null,
            0,
            null,
            $key,
        );

        $job->recovering = true;

        // The record exists whatever the caller's transaction does: a rollback
        // must not drop the job that replays or finishes it (after_commit would).
        $job->beforeCommit();

        $bus = Container::getInstance()->make(BusDispatcher::class);

        if ($bus instanceof QueueingDispatcher) {
            $bus->dispatchToQueue($job);

            return;
        }

        $bus->dispatch($job);
    }

    /**
     * The credentials given, else the platform's configured ones, else none.
     *
     * @param  Credentials|array<string, mixed>|null  $credentials
     */
    protected function credentialsFor(Platform $platform, Credentials|array|null $credentials): Credentials
    {
        if ($credentials instanceof Credentials) {
            return $credentials;
        }

        if (is_array($credentials) && $credentials !== []) {
            return Credentials::make($credentials);
        }

        $configured = $this->config()['platforms'][$platform->value]['credentials'] ?? [];

        return Credentials::make(is_array($configured) ? $configured : []);
    }

    /**
     * Credentials for replaying a published record when the caller gave none.
     * A replay needs no token (only a pending first comment does), and the
     * configured credentials may belong to another account than the one the
     * key is bound to: the replay job would then fail on a key collision and
     * report PostFailed for a live post. So the configured credentials are
     * used only when they are provably the recorded account's; otherwise none.
     */
    protected function replayCredentials(Platform $platform, IdempotencyStore $store, string $key): Credentials
    {
        $recorded = $this->storeRecord($store, $key)['account'] ?? null;

        if (! is_string($recorded) || $recorded === '') {
            return new Credentials;
        }

        $configured = $this->credentialsFor($platform, null);

        try {
            $driver = $this->manager->driver($platform->value);
            $account = $driver instanceof AbstractPlatform ? $driver->accountKey($configured) : '';
        } catch (Throwable $e) {
            $this->reportQuietly($e);

            return new Credentials;
        }

        return $account === $recorded ? $configured : new Credentials;
    }

    /**
     * The whole record of a key (status, state, platform...) when the store
     * exposes one through a public record() method; first-party stores may,
     * custom ones need not.
     *
     * @return array<string, mixed>|null
     */
    protected function storeRecord(IdempotencyStore $store, string $key): ?array
    {
        if (! method_exists($store, 'record')) {
            return null;
        }

        try {
            $record = $store->record($key);
        } catch (Throwable $e) {
            $this->reportQuietly($e);

            return null;
        }

        return is_array($record) ? $record : null;
    }

    protected function idempotencyStore(): ?IdempotencyStore
    {
        try {
            return Container::getInstance()->make(IdempotencyStore::class);
        } catch (Throwable $e) {
            $this->reportQuietly($e);

            return null;
        }
    }

    /** @throws UnsupportedPlatformException */
    protected function platformOrNull(Platform|string|null $platform): ?Platform
    {
        if ($platform === null || $platform instanceof Platform) {
            return $platform;
        }

        return Platform::tryFrom(strtolower(trim($platform)))
            ?? throw $this->manager->unsupportedPlatform($platform);
    }

    protected function reportQuietly(Throwable $e): void
    {
        try {
            report($e);
        } catch (Throwable) {
            // Reporting is best effort.
        }
    }
}
