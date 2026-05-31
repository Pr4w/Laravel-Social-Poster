<?php

namespace SocialPoster\Capabilities;

final class VideoRules
{
    /**
     * @param string[]|null $extensions Accepted file extensions (lowercase, no dot).
     * @param string[] $types Accepted MIME types (checked only when the inspector reports one).
     * @param array{0: float, 1: float}|null $aspectRatioRange [min, max] width/height.
     * @param string[] $codecs Accepted codec names (empty = unrestricted).
     */
    public function __construct(
        public readonly ?array $extensions = null,
        public readonly array $types = ['video/mp4'],
        public readonly ?int $maxSizeBytes = null,
        public readonly ?float $minDuration = null,
        public readonly ?float $maxDuration = null,
        public readonly ?int $minWidth = null,
        public readonly ?int $minHeight = null,
        public readonly ?int $maxWidth = null,
        public readonly ?int $maxHeight = null,
        public readonly ?array $aspectRatioRange = null,
        public readonly array $codecs = [],
    ) {}
}
