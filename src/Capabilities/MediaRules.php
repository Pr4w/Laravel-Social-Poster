<?php

namespace SocialPoster\Capabilities;

final class MediaRules
{
    public function __construct(
        public readonly int $minCount = 0,
        public readonly int $maxCount = 10,
        public readonly ?ImageRules $image = null,
        public readonly ?VideoRules $video = null,
        public readonly ?DocumentRules $document = null,
    ) {}
}
