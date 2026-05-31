<?php

namespace SocialPoster\Capabilities;

final class ImageRules
{
    /**
     * @param string[]|null $extensions Accepted file extensions (lowercase, no dot).
     * @param string[] $types Accepted MIME types. Opt-in: empty means the extension
     *                         list is the only format gate. Set this only to additionally
     *                         enforce MIME when the inspector reports one.
     * @param array{0: float, 1: float}|null $aspectRatioRange [min, max] width/height.
     */
    public function __construct(
        public readonly ?array $extensions = null,
        public readonly array $types = [],
        public readonly ?int $maxSizeBytes = null,
        public readonly ?array $aspectRatioRange = null,
    ) {}
}
