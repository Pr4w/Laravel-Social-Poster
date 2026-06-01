<?php

namespace SocialPoster\Events;

use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\SocialPosterException;

class PostFailed
{
    /** @param array<string, mixed> $metadata Caller correlation data from ->withMetadata(). */
    public function __construct(
        public readonly Platform $platform,
        public readonly SocialPosterException $exception,
        public readonly array $metadata = [],
    ) {}
}
