<?php

namespace SocialPoster\Events;

use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\SocialPost;

class PostQueued
{
    public function __construct(
        public readonly Platform $platform,
        public readonly SocialPost $post,
    ) {}
}
