<?php

namespace SocialPoster\Idempotency;

use SocialPoster\Contracts\IdempotencyStore;
use SocialPoster\ValueObjects\PostResult;

/**
 * Default store: remembers nothing. The package keeps its prior behaviour and
 * needs no migration. Bind DatabaseIdempotencyStore in config to turn on the
 * at-least-once duplicate protection.
 */
final class NullIdempotencyStore implements IdempotencyStore
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
}
