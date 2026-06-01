<?php

namespace SocialPoster\ValueObjects;

use SocialPoster\Contracts\PlatformOptions;

/**
 * The immutable, platform-agnostic intent produced by the builder. This is
 * what gets serialised into jobs and handed to drivers. The user's original
 * content is never mutated; platform-specific transforms happen in the driver.
 */
final class SocialPost
{
    /** @param Media[] $media */
    public function __construct(
        public readonly array $media = [],
        public readonly ?string $caption = null,
        public readonly ?string $title = null,
        public readonly ?PlatformOptions $options = null,
        public readonly array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'media' => $this->media,
            'caption' => $this->caption,
            'title' => $this->title,
            'metadata' => $this->metadata,
        ];
    }
}
