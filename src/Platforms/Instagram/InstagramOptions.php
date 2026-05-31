<?php

namespace SocialPoster\Platforms\Instagram;

use Illuminate\Support\MessageBag;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\Media;

final class InstagramOptions implements PlatformOptions
{
    public function __construct(
        public readonly bool $isStory = false,
        public readonly ?Media $thumbnail = null, // reel cover image
    ) {}

    public function platform(): Platform
    {
        return Platform::Instagram;
    }

    public function toPayload(): array
    {
        return ['is_story' => $this->isStory];
    }

    public function validate(): MessageBag
    {
        return new MessageBag();
    }
}
