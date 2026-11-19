<?php

namespace SocialPoster\Transformers;

use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Contracts\MediaTransformer;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\Media;

/**
 * The default transformer: media is uploaded exactly as given. Formats that
 * need converting (ImageRules::$convert) then fail validation with a hint to
 * bind ImageConvertingTransformer.
 */
class NullMediaTransformer implements MediaTransformer
{
    public function transform(Media $media, Platform $platform, Capabilities $caps): Media
    {
        return $media;
    }
}
