<?php

namespace SocialPoster\Events;

use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\SocialPosterException;

class CommentFailed
{
    /** @param array<string, mixed> $metadata Caller correlation data from ->withMetadata(). */
    public function __construct(
        public readonly Platform $platform,
        public readonly string $postId,
        public readonly SocialPosterException $exception,
        public readonly array $metadata = [],
    ) {}
}
