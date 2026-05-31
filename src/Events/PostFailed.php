<?php

namespace SocialPoster\Events;

use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\SocialPosterException;

class PostFailed
{
    public function __construct(
        public readonly Platform $platform,
        public readonly SocialPosterException $exception,
    ) {}
}
