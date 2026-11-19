<?php

namespace SocialPoster\Contracts;

use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;

/**
 * Extracts real file metadata for the deep validation checks. Implementations
 * report supports() = false when they cannot reach the media (remote URL,
 * missing binary), so validation degrades to the cheap structural checks.
 *
 * Both methods run during validate(), often inside a web request, on input a
 * user controls. They should be bounded in time, never fetch a remote URL
 * without the PublicUrl guard, and never throw: return what is known (fields
 * left null) instead. The validator also catches exceptions and completes the
 * result with the caller's Media::$meta.
 *
 * Width and height are display dimensions (rotation / EXIF orientation applied).
 */
interface MediaInspector
{
    public function supports(Media $media): bool;

    public function inspect(Media $media): MediaMetadata;
}
