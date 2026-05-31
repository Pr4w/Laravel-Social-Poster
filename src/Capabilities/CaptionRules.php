<?php

namespace SocialPoster\Capabilities;

final class CaptionRules
{
    public function __construct(
        public readonly int $min = 0,
        public readonly int $max = 2200,
    ) {}
}
