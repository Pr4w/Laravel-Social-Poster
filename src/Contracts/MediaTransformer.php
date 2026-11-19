<?php

namespace SocialPoster\Contracts;

use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\Media;

/**
 * Pre-processes media for one platform before it is uploaded or handed over as
 * a URL, e.g. PNG → JPEG for TikTok photos or JPEG-only Instagram feeds.
 *
 * Drivers call app(MediaTransformer::class)->transform() on each media item,
 * passing the Capabilities that apply to the post, and then resolve the
 * returned Media through the MediaGateway. Return $media itself when nothing
 * needs to change; never mutate it (Media is shared across a fan-out).
 * A returned local file usually needs a gateway that can re-host it
 * (FilesystemMediaGateway) on pull-only platforms.
 *
 * Bound through config('social.transformer'); the default
 * NullMediaTransformer changes nothing, ImageConvertingTransformer applies
 * ImageRules::$convert with GD or Imagick.
 */
interface MediaTransformer
{
    public function transform(Media $media, Platform $platform, Capabilities $caps): Media;
}
