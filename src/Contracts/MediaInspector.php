<?php

namespace SocialPoster\Contracts;

use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;

/**
 * Extracts real file metadata for the deep validation checks. Implementations
 * report supports() = false when they cannot reach the media (remote URL,
 * missing binary), so validation degrades to the cheap structural checks.
 */
interface MediaInspector
{
    public function supports(Media $media): bool;

    public function inspect(Media $media): MediaMetadata;
}
