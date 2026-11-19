<?php

namespace SocialPoster\Events;

use SocialPoster\Enums\Platform;

/**
 * A publish was not queued because an identical one (same key) is already queued
 * or running. Nothing is lost: the earlier job reports PostPublished or
 * PostFailed. Before 1.1 this case was silent.
 *
 * Also dispatched when a redelivered copy of a publish step is dropped because
 * another run already handed that step over (queued the next step or
 * published), and, on the sync queue, when a copy finds the step still running.
 */
class PostDeduplicated
{
    use SerializesForQueue;

    /**
     * @param  string  $key  The unique/idempotency key that collided.
     * @param  array<string, mixed>  $metadata  Caller correlation data from ->withMetadata().
     * @param  ?string  $correlationId  The id returned by post() for the refused call.
     */
    public function __construct(
        public readonly Platform $platform,
        public readonly string $key,
        public readonly array $metadata = [],
        public readonly ?string $correlationId = null,
    ) {}
}
