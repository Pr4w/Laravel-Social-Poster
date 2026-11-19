<?php

namespace SocialPoster\Idempotency;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Exceptions\PermanentException;

/**
 * Owner-aware leases on top of Laravel's atomic cache locks
 * (Cache::store(...)->lock()), shared by CacheIdempotencyStore and by
 * DatabaseIdempotencyStore when its table predates the 1.1 claim columns.
 *
 * A lock's TTL is the lease: it expires on its own when a worker dies. The
 * owner token is the lock owner, so a continuation of the same chain can
 * recognise and renew its own lease, and release() never frees someone else's.
 * Works with every lock-capable store: array, file, database, redis, memcached,
 * dynamodb.
 *
 * @internal
 */
final class CacheLease
{
    /** @param  ?string  $store  Cache store name; null uses the default store. */
    public function __construct(private readonly ?string $store = null) {}

    /**
     * Acquire the lease for $owner, or renew it when $owner already holds it.
     * Renewing re-creates the lock with the new TTL; if another owner slips in
     * between, the renewal fails safe (false).
     */
    public function acquire(string $name, string $owner, int $seconds): bool
    {
        $locks = $this->locks();
        $seconds = max(1, $seconds);

        if ($locks->lock($name, $seconds, $owner)->get()) {
            return true;
        }

        // Held. Every driver's release() only succeeds for the owner that holds
        // the lock, so a successful release proves the lease is ours to renew.
        if (! $locks->restoreLock($name, $owner)->release()) {
            return false;
        }

        return (bool) $locks->lock($name, $seconds, $owner)->get();
    }

    /** Release $owner's lease. A no-op when another owner holds it. */
    public function release(string $name, string $owner): void
    {
        $this->locks()->restoreLock($name, $owner)->release();
    }

    /** Drop the lease whoever holds it (the record it guards is gone). */
    public function forceRelease(string $name): void
    {
        $this->locks()->lock($name)->forceRelease();
    }

    /**
     * Run $callback under a short mutex, waiting up to $wait seconds for it.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws LockTimeoutException
     */
    public function mutex(string $name, callable $callback, int $wait = 5): mixed
    {
        return $this->locks()->lock($name, 10)->block($wait, $callback);
    }

    private function locks(): LockProvider
    {
        $store = Cache::store($this->store)->getStore();

        if (! $store instanceof LockProvider) {
            throw new PermanentException(
                'The "'.($this->store ?? 'default').'" cache store does not support atomic locks, which the idempotency '
                .'claim needs. Point social.lock_store at a store that does (redis, database, memcached, dynamodb, file, array).',
                null,
                FailureReason::Misconfigured,
            );
        }

        return $store;
    }
}
