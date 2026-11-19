<?php

namespace SocialPoster\Idempotency;

use SocialPoster\Contracts\AtomicIdempotencyStore;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\PostResult;

/**
 * Remembers nothing and never refuses a claim: no duplicate protection at all.
 * It was the v1.0 default; 1.1 defaults to CacheIdempotencyStore. Bind it only
 * where a store is not wanted (some tests, a strictly synchronous setup), and
 * know that a retried publish then posts again.
 *
 * The job still flags redelivered commit phases as recovering with this store,
 * so drivers verify before re-creating; what is lost is cross-dispatch memory.
 */
final class NullIdempotencyStore implements AtomicIdempotencyStore
{
    public function completed(string $key): ?PostResult
    {
        return null;
    }

    public function markPublished(string $key, PostResult $result): void {}

    public function pendingState(string $key): ?array
    {
        return null;
    }

    public function markPending(string $key, array $state): void {}

    public function forget(string $key): void {}

    public function claim(string $key, string $owner, int $leaseSeconds): bool
    {
        return true;
    }

    public function release(string $key, string $owner): void {}

    public function markCommitting(string $key, array $state): void {}

    public function committing(string $key): ?array
    {
        return null;
    }

    public function bindAccount(string $key, Platform|string $platform, string $account): void {}

    public function markFailed(string $key, ?array $state = null): void {}

    public function status(string $key): ?string
    {
        return null;
    }

    /** Nothing is ever recorded (see CacheIdempotencyStore::record() for the shape). */
    public function record(string $key): null
    {
        return null;
    }
}
