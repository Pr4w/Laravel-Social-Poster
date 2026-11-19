<?php

namespace SocialPoster\Events;

use SocialPoster\ValueObjects\PostResult;

/**
 * The post exists on the platform. Check $result->state before acting on it: a
 * scheduled, draft (TikTok inbox), private or still-processing post also lands
 * here (PostResult::isScheduled(), isDraft(), isProcessing()).
 *
 * Listeners may run more than once for the same post (a replay after a worker
 * crash, or a reconcile), so make them idempotent, ideally keyed on
 * $idempotencyKey, and queue them.
 */
class PostPublished
{
    use SerializesForQueue;

    /**
     * @param  ?string  $correlationId  The id returned by post() / passed to ->correlationId(), shared by a fan-out.
     * @param  ?string  $idempotencyKey  The per-platform, per-account key the store records this publish under.
     * @param  int  $attempt  Queue attempt (1-based) of the run that produced this event.
     * @param  bool  $replayed  True when the result came from the idempotency store, not from a new publish.
     */
    public function __construct(
        public readonly PostResult $result,
        public readonly ?string $correlationId = null,
        public readonly ?string $idempotencyKey = null,
        public readonly int $attempt = 1,
        public readonly bool $replayed = false,
    ) {}
}
