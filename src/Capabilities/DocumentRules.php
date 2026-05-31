<?php

namespace SocialPoster\Capabilities;

final class DocumentRules
{
    /**
     * @param string[]|null $extensions Accepted file extensions (lowercase, no dot).
     */
    public function __construct(
        public readonly ?array $extensions = null,
        public readonly ?int $maxSizeBytes = null,
    ) {}
}
