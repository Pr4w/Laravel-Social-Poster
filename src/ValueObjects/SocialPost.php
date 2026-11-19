<?php

namespace SocialPoster\ValueObjects;

use InvalidArgumentException;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;

/**
 * The immutable, platform-agnostic intent produced by the builder. This is
 * what gets serialised into jobs and handed to drivers. The user's original
 * content is never mutated; platform-specific transforms happen in the driver.
 *
 * Options: since 1.1 a post carries one options object per platform
 * (platformOptions(), optionsFor()), so a fan-out can give Instagram a story
 * flag and LinkedIn its mentions in the same call. $options is the v1.0 single
 * slot: it is still read when a platform has no entry of its own (payloads
 * queued by v1.0, posts built by hand), and the builder fills it when exactly
 * one options object was given.
 *
 * The per-platform map is a private, non-promoted property with an
 * __unserialize() default, so SocialPost objects serialised by v1.0 (inside
 * queued jobs) still unserialize: they simply have no map.
 */
final class SocialPost
{
    /** @var array<string, PlatformOptions> Keyed by platform value. */
    private array $platformOptions = [];

    /**
     * @param  Media[]  $media
     * @param  array<string, mixed>  $metadata
     * @param  array<array-key, PlatformOptions>  $platformOptions  One options object per platform; keyed by each object's platform().
     *
     * @throws InvalidArgumentException When $platformOptions holds a non-options value or two objects for one platform.
     */
    public function __construct(
        public readonly array $media = [],
        public readonly ?string $caption = null,
        public readonly ?string $title = null,
        public readonly ?PlatformOptions $options = null,
        public readonly array $metadata = [],
        public readonly ?string $comment = null,
        array $platformOptions = [],
    ) {
        foreach ($platformOptions as $option) {
            if (! $option instanceof PlatformOptions) {
                throw new InvalidArgumentException('SocialPost platform options must implement '.PlatformOptions::class.', got '.get_debug_type($option).'.');
            }

            $value = $option->platform()->value;

            if (isset($this->platformOptions[$value])) {
                throw new InvalidArgumentException("SocialPost received two options objects for [{$value}]; pass one per platform.");
            }

            $this->platformOptions[$value] = $option;
        }
    }

    /**
     * The options for one platform: its own entry, else the v1.0 single
     * $options (which may belong to another platform, as in v1.0; drivers
     * check the type or platform before using it).
     */
    public function optionsFor(Platform $platform): ?PlatformOptions
    {
        return $this->platformOptions[$platform->value] ?? $this->options;
    }

    /**
     * Every per-platform options object, keyed by platform value.
     *
     * @return array<string, PlatformOptions>
     */
    public function platformOptions(): array
    {
        return $this->platformOptions;
    }

    /** @return array{media: Media[], caption: ?string, title: ?string, metadata: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'media' => $this->media,
            'caption' => $this->caption,
            'title' => $this->title,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Plain property names (the private map included), so the payload format
     * does not depend on PHP's name mangling.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'media' => $this->media,
            'caption' => $this->caption,
            'title' => $this->title,
            'options' => $this->options,
            'metadata' => $this->metadata,
            'comment' => $this->comment,
            'platformOptions' => $this->platformOptions,
        ];
    }

    /**
     * Restores both 1.1 payloads and v1.0 ones (serialised without
     * __serialize(): the same public property names and no platformOptions),
     * which would otherwise leave the map uninitialised.
     *
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        $options = $data['options'] ?? null;
        $map = is_array($data['platformOptions'] ?? null) ? $data['platformOptions'] : [];

        $this->media = is_array($data['media'] ?? null) ? $data['media'] : [];
        $this->caption = is_string($data['caption'] ?? null) ? $data['caption'] : null;
        $this->title = is_string($data['title'] ?? null) ? $data['title'] : null;
        $this->options = $options instanceof PlatformOptions ? $options : null;
        $this->metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $this->comment = is_string($data['comment'] ?? null) ? $data['comment'] : null;
        $this->platformOptions = [];

        foreach ($map as $option) {
            if ($option instanceof PlatformOptions) {
                $this->platformOptions[$option->platform()->value] = $option;
            }
        }
    }
}
