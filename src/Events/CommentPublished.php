<?php

namespace SocialPoster\Events;

use SocialPoster\Enums\Platform;

class CommentPublished
{
    /** @param array<string, mixed> $metadata Caller correlation data from ->withMetadata(). */
    public function __construct(
        public readonly Platform $platform,
        public readonly string $postId,
        public readonly ?string $commentId,
        public readonly array $metadata = [],
    ) {}
}
