<?php

namespace SocialPoster\Idempotency;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use SocialPoster\Contracts\AtomicIdempotencyStore;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\PostResult;
use Throwable;

/**
 * Zero-migration idempotency store on Laravel's cache, and the 1.1 default.
 * The package already needs a lock-capable cache for its unique jobs, so this
 * adds duplicate protection without a table:
 *
 * - claim() is an atomic cache lock (Cache::store(...)->lock()) whose owner is
 *   the chain token and whose TTL is the lease, so a dead worker's lease
 *   expires on its own;
 * - each record (status, state, result, account) is one cache entry, kept for
 *   social.idempotency_ttl seconds (7 days) after its last write;
 * - read-modify-write updates run under a short per-key mutex lock, so a
 *   'published' record is never overwritten and two accounts cannot both bind
 *   one key.
 *
 * Stores: social.idempotency_cache_store for records (null = default store) and
 * social.lock_store for the leases (null = the record store). Works on array
 * (tests, sync queue), file (single server), database, redis and memcached.
 *
 * Durability is the cache's: a flush or an LRU eviction forgets records, and an
 * evicted 'published' record lets a later redelivery publish again. Use a
 * non-evicting store, or DatabaseIdempotencyStore, when that matters.
 */
final class CacheIdempotencyStore implements AtomicIdempotencyStore
{
    private const PREFIX = 'social-poster:idempotency:';

    private const DEFAULT_TTL = 604800;

    /**
     * @param  ?string  $store  Cache store for the records; null reads social.idempotency_cache_store (null = default store).
     * @param  ?int  $ttl  Seconds a record lives after its last write; null reads social.idempotency_ttl (604800).
     * @param  ?string  $lockStore  Cache store for leases; null reads social.lock_store, then falls back to the record store.
     */
    public function __construct(
        private readonly ?string $store = null,
        private readonly ?int $ttl = null,
        private readonly ?string $lockStore = null,
    ) {}

    public function completed(string $key): ?PostResult
    {
        $record = $this->read($key);

        if (($record['status'] ?? null) !== self::STATUS_PUBLISHED || ! is_array($record['result'] ?? null)) {
            return null;
        }

        try {
            return PostResult::fromArray($record['result']);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function markPublished(string $key, PostResult $result): void
    {
        $stored = $result->toArray();
        unset($stored['metadata'], $stored['error']);

        // A JSON round trip keeps the entry plain arrays and scalars, so every
        // cache serializer (and a later package version) can read it back.
        $stored = json_decode((string) json_encode($stored, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), true);

        $this->mutate($key, fn (?array $record): array => [
            'status' => self::STATUS_PUBLISHED,
            'platform' => $result->platform->value,
            'state' => null,
            'since' => null,
            'result' => is_array($stored) ? $stored : ['platform' => $result->platform->value],
        ] + ($record ?? []));
    }

    public function pendingState(string $key): ?array
    {
        $record = $this->read($key);

        if (($record['status'] ?? null) !== self::STATUS_PENDING || ! is_array($record['state'] ?? null)) {
            return null;
        }

        return $record['state'];
    }

    public function markPending(string $key, array $state): void
    {
        $this->mutate($key, function (?array $record) use ($state): ?array {
            if (($record['status'] ?? null) === self::STATUS_PUBLISHED) {
                return null;
            }

            return ['status' => self::STATUS_PENDING, 'state' => $state, 'since' => null] + ($record ?? []);
        });
    }

    public function forget(string $key): void
    {
        $after = $this->mutate($key, fn (?array $record) => ($record['status'] ?? null) === self::STATUS_PUBLISHED ? null : false);

        if (($after['status'] ?? null) !== self::STATUS_PUBLISHED) {
            $this->releaseLeaseOf($key);
        }
    }

    public function claim(string $key, string $owner, int $leaseSeconds): bool
    {
        $lease = $this->lease();
        $name = $this->leaseName($key);

        if (! $lease->acquire($name, $owner, $leaseSeconds)) {
            return false;
        }

        $blocked = false;

        try {
            $this->mutate($key, function (?array $record) use (&$blocked): ?array {
                $status = $record['status'] ?? null;

                if ($status === self::STATUS_PUBLISHED || $status === self::STATUS_FAILED) {
                    $blocked = true;

                    return null;
                }

                return ['attempts' => (int) ($record['attempts'] ?? 0) + 1] + ($record ?? ['status' => self::STATUS_PENDING]);
            });
        } catch (Throwable $e) {
            $lease->release($name, $owner);

            throw $e;
        }

        if ($blocked) {
            $lease->release($name, $owner);

            return false;
        }

        return true;
    }

    public function release(string $key, string $owner): void
    {
        $this->lease()->release($this->leaseName($key), $owner);
    }

    public function markCommitting(string $key, array $state): void
    {
        $this->mutate($key, function (?array $record) use ($state): ?array {
            $status = $record['status'] ?? null;

            if ($status === self::STATUS_PUBLISHED) {
                return null;
            }

            // Keep the first 'since' on a repeat, and when a failed record is
            // reopened (reconcile): markFailed() keeps the time the failed
            // commit phase began, and that is where a driver must start looking
            // for a post the earlier create may have made.
            $keep = $status === self::STATUS_COMMITTING || $status === self::STATUS_FAILED;
            $since = $keep && is_int($record['since'] ?? null)
                ? $record['since']
                : now()->getTimestamp();

            return ['status' => self::STATUS_COMMITTING, 'state' => $state, 'since' => $since] + ($record ?? []);
        });
    }

    public function committing(string $key): ?array
    {
        $record = $this->read($key);

        if (($record['status'] ?? null) !== self::STATUS_COMMITTING) {
            return null;
        }

        $since = $record['since'] ?? $record['updated_at'] ?? null;

        return [
            'state' => is_array($record['state'] ?? null) ? $record['state'] : [],
            'since' => is_int($since) ? $since : now()->getTimestamp(),
        ];
    }

    public function bindAccount(string $key, Platform|string $platform, string $account): void
    {
        $platform = $platform instanceof Platform ? $platform->value : $platform;

        $record = $this->mutate($key, function (?array $record) use ($platform, $account): ?array {
            if ($record === null) {
                return ['status' => self::STATUS_PENDING, 'platform' => $platform, 'account' => $account, 'attempts' => 0];
            }

            $changed = false;

            if (($record['account'] ?? null) === null) {
                $record['account'] = $account;
                $changed = true;
            }

            if (($record['platform'] ?? null) === null) {
                $record['platform'] = $platform;
                $changed = true;
            }

            return $changed ? $record : null;
        });

        $boundPlatform = isset($record['platform']) ? (string) $record['platform'] : null;

        if ($record !== null && (($record['account'] ?? null) !== $account || ($boundPlatform !== null && $boundPlatform !== $platform))) {
            throw KeyCollisionException::make($key, $platform, $boundPlatform);
        }
    }

    public function markFailed(string $key, ?array $state = null): void
    {
        $after = $this->mutate($key, function (?array $record) use ($state): ?array {
            if (($record['status'] ?? null) === self::STATUS_PUBLISHED) {
                return null;
            }

            $record = ['status' => self::STATUS_FAILED] + ($record ?? []);

            if ($state !== null) {
                $record['state'] = $state;
            }

            return $record;
        });

        if (($after['status'] ?? null) === self::STATUS_FAILED) {
            $this->releaseLeaseOf($key);
        }
    }

    public function status(string $key): ?string
    {
        $status = $this->read($key)['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    /**
     * The whole record of a key, for Poster::reconcile() and diagnostics: a
     * 'failed' record keeps the state markFailed() was given, and nothing else
     * reads it back. Null when nothing is recorded.
     *
     * @return array{status: string, state: ?array<string, mixed>, platform: ?string, account: ?string, since: ?int, updated_at: ?int}|null
     */
    public function record(string $key): ?array
    {
        $record = $this->read($key);

        if ($record === null || ! is_string($record['status'] ?? null)) {
            return null;
        }

        return [
            'status' => $record['status'],
            'state' => is_array($record['state'] ?? null) ? $record['state'] : null,
            'platform' => is_string($record['platform'] ?? null) ? $record['platform'] : null,
            'account' => is_string($record['account'] ?? null) ? $record['account'] : null,
            'since' => is_int($record['since'] ?? null) ? $record['since'] : null,
            'updated_at' => is_int($record['updated_at'] ?? null) ? $record['updated_at'] : null,
        ];
    }

    /**
     * Apply $change to the record under the key's mutex. $change returns the
     * new record, null to leave it as is, or false to delete it. Returns the
     * record as it stands afterwards.
     *
     * The mutex only narrows races the lease already prevents (writers hold
     * the claim), so when it cannot be had within a few seconds the change is
     * applied anyway rather than lose a write such as markPublished.
     *
     * @param  callable(?array<string, mixed>): (array<string, mixed>|false|null)  $change
     * @return array<string, mixed>|null
     */
    private function mutate(string $key, callable $change): ?array
    {
        $apply = function () use ($key, $change): ?array {
            $current = $this->read($key);
            $next = $change($current);

            if ($next === null) {
                return $current;
            }

            if ($next === false) {
                $this->cache()->forget($this->recordKey($key));

                return null;
            }

            $now = now()->getTimestamp();
            $next['key'] = $key;
            $next['created_at'] = is_int($current['created_at'] ?? null) ? $current['created_at'] : $now;
            $next['updated_at'] = $now;

            $this->cache()->put($this->recordKey($key), $next, $this->ttl());

            return $next;
        };

        try {
            return $this->lease()->mutex('social-poster:idempotency-mutex:'.hash('sha256', $key), $apply);
        } catch (LockTimeoutException) {
            return $apply();
        }
    }

    /** @return array<string, mixed>|null */
    private function read(string $key): ?array
    {
        $record = $this->cache()->get($this->recordKey($key));

        return is_array($record) ? $record : null;
    }

    private function releaseLeaseOf(string $key): void
    {
        try {
            $this->lease()->forceRelease($this->leaseName($key));
        } catch (Throwable) {
            // Best effort: an unreleased lease only delays the next claim until it expires.
        }
    }

    private function cache(): Repository
    {
        return Cache::store($this->storeName());
    }

    private function lease(): CacheLease
    {
        $store = $this->lockStore ?? config('social.lock_store');

        return new CacheLease(is_string($store) && $store !== '' ? $store : $this->storeName());
    }

    private function storeName(): ?string
    {
        $store = $this->store ?? config('social.idempotency_cache_store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    private function ttl(): int
    {
        $ttl = $this->ttl ?? config('social.idempotency_ttl', self::DEFAULT_TTL);

        return max(60, is_numeric($ttl) ? (int) $ttl : self::DEFAULT_TTL);
    }

    private function recordKey(string $key): string
    {
        return self::PREFIX.hash('sha256', $key);
    }

    private function leaseName(string $key): string
    {
        return 'social-poster:idempotency-lease:'.hash('sha256', $key);
    }
}
