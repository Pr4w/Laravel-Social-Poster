<?php

namespace SocialPoster\Contracts;

use Illuminate\Support\MessageBag;
use SocialPoster\Enums\Platform;

/**
 * Typed, per-platform options (TikTok privacy, Instagram share-to-feed, etc.).
 * Implementations own their own self-consistency validation and serialisation.
 */
interface PlatformOptions
{
    public function platform(): Platform;

    public function toPayload(): array;

    public function validate(): MessageBag;
}
