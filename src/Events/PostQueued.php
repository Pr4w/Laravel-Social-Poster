<?php

namespace SocialPoster\Events;

use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\SocialPost;

/**
 * A publish job for one platform was accepted by the queue. A refused duplicate
 * fires PostDeduplicated instead.
 */
class PostQueued
{
    use SerializesForQueue;

    /**
     * @param  array<string, mixed>  $metadata  Caller correlation data from ->withMetadata().
     * @param  ?string  $correlationId  The id post() returns, shared by every platform of the fan-out.
     * @param  ?string  $idempotencyKey  The per-platform, per-account key the job will record the publish under.
     */
    public function __construct(
        public readonly Platform $platform,
        public readonly SocialPost $post,
        public readonly array $metadata = [],
        public readonly ?string $correlationId = null,
        public readonly ?string $idempotencyKey = null,
    ) {}
}
