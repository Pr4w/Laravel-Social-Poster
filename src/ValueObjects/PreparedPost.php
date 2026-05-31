<?php

namespace SocialPoster\ValueObjects;

use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;

/**
 * A SocialPost bound to one platform and its credentials. This is the unit a
 * driver validates and publishes.
 */
final class PreparedPost
{
    public function __construct(
        public readonly Platform $platform,
        public readonly SocialPost $post,
        public readonly Credentials $credentials,
    ) {}

    /** @return Media[] */
    public function media(): array
    {
        return $this->post->media;
    }

    public function caption(): ?string
    {
        return $this->post->caption;
    }

    public function title(): ?string
    {
        return $this->post->title;
    }

    public function options(): ?PlatformOptions
    {
        return $this->post->options;
    }

    public function toArray(): array
    {
        return $this->post->toArray();
    }
}
