<?php

namespace SocialPoster\Capabilities;

/**
 * The declarative posting rules for a platform. Drives the generic validation
 * layer and is introspectable by callers ("can this platform do X?").
 * Conditional or cross-field rules do not live here; they belong in the
 * driver's validatePlatformRules() hook.
 */
final class Capabilities
{
    public function __construct(
        public readonly CaptionRules $caption,
        public readonly MediaRules $media,
        public readonly bool $supportsTitle = false,
        public readonly bool $supportsCarousel = false,
    ) {}
}
