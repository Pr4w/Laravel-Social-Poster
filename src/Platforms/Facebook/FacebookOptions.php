<?php

namespace SocialPoster\Platforms\Facebook;

use Illuminate\Support\MessageBag;
use SocialPoster\Concerns\CarriesExtraPayload;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\Media;

final class FacebookOptions implements PlatformOptions
{
    use CarriesExtraPayload;

    /** @param array<string, mixed> $extra Raw params merged into the post-level create. */
    public function __construct(
        public readonly bool $isStory = false,
        public readonly ?Media $thumbnail = null,
        public readonly array $extra = [],
    ) {}

    public function platform(): Platform
    {
        return Platform::Facebook;
    }

    public function validate(): MessageBag
    {
        // Cross-field rules that need the post live in the driver's
        // validatePlatformRules(); options self-validation is a no-op here.
        return new MessageBag();
    }
}
