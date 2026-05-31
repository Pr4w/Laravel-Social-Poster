<?php

namespace SocialPoster\Events;

use SocialPoster\ValueObjects\PostResult;

class PostPublished
{
    public function __construct(
        public readonly PostResult $result,
    ) {}
}
