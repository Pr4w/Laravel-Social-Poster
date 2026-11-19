<?php

namespace SocialPoster\Capabilities;

final class VideoRules
{
    /**
     * @param  string[]|null  $extensions  Accepted file extensions (lowercase, no dot).
     * @param  string[]  $types  Accepted MIME types, checked only when the inspector reports one.
     *                           A MIME that matches one of $extensions is accepted too, so the
     *                           default ['video/mp4'] does not reject a .mov where 'mov' is allowed.
     * @param  array{0: float, 1: float}|null  $aspectRatioRange  [min, max] width/height.
     * @param  string[]  $codecs  Accepted codec names (empty = unrestricted).
     * @param  float|null  $minFrameRate  Lowest accepted average frame rate (fps).
     * @param  float|null  $maxFrameRate  Highest accepted average frame rate (fps).
     * @param  int|null  $minSizeBytes  Smallest accepted file (catches empty or truncated files).
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
        public readonly ?float $minFrameRate = null,
        public readonly ?float $maxFrameRate = null,
        public readonly ?int $minSizeBytes = null,
    ) {}
}
