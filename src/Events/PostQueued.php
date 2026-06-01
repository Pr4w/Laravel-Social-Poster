<?php

namespace SocialPoster\Events;

use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\SocialPost;

class PostQueued
{
    /** @param array<string, mixed> $metadata Caller correlation data from ->withMetadata(). */
    public function __construct(
        public readonly Platform $platform,
        public readonly SocialPost $post,
        public readonly array $metadata = [],
    ) {}
}
