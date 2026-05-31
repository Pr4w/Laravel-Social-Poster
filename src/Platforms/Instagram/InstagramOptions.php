<?php

namespace SocialPoster\Platforms\Instagram;

use Illuminate\Support\MessageBag;
use SocialPoster\Concerns\CarriesExtraPayload;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\Media;

final class InstagramOptions implements PlatformOptions
{
    use CarriesExtraPayload;

    /**
     * @param array<string, mixed> $extra Raw params merged into the /media container
     *                                     create (e.g. ['trial_params' => [...]]).
     */
    public function __construct(
        public readonly bool $isStory = false,
        public readonly ?Media $thumbnail = null, // reel cover image
        public readonly array $extra = [],
    ) {}

    public function platform(): Platform
    {
        return Platform::Instagram;
    }

    public function validate(): MessageBag
    {
        return new MessageBag();
    }
}
