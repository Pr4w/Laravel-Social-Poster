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

    /**
     * This platform's options: its own entry from ->withOptions(), else the
     * post's single v1.0 options object (which, in a v1.0 fan-out, may belong
     * to another platform, so drivers keep checking the type or platform()).
     */
    public function options(): ?PlatformOptions
    {
        return $this->post->optionsFor($this->platform);
    }

    /** @return array<string, mixed> Opaque caller correlation data; never sent to the platform. */
    public function metadata(): array
    {
        return $this->post->metadata;
    }

    public function comment(): ?string
    {
        return $this->post->comment;
    }

    /** @return array{media: Media[], caption: ?string, title: ?string, metadata: array<string, mixed>} */
    public function toArray(): array
    {
        return $this->post->toArray();
    }
}
