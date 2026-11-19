<?php

namespace SocialPoster\Events;

use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\SocialPosterException;

/**
 * The first comment could not be added after its retries. The post itself is
 * live; only the comment is missing.
 */
class CommentFailed
{
    use SerializesForQueue;

    /**
     * @param  array<string, mixed>  $metadata  Caller correlation data from ->withMetadata().
     * @param  int  $attempt  Attempt (1-based) of the comment job that gave up.
     */
    public function __construct(
        public readonly Platform $platform,
        public readonly string $postId,
        public readonly SocialPosterException $exception,
        public readonly array $metadata = [],
        public readonly int $attempt = 1,
    ) {}

    /**
     * A serialisable, JSON-safe summary of the failure (platform, reason,
     * category, message, class, context reduced to plain values).
     *
     * @return array{platform: ?string, reason: string, category: string, message: string, class: string, context: array<string, mixed>}
     */
    public function errorSummary(): array
    {
        return QueueSafe::summary($this->exception, $this->platform);
    }
}
