<?php

namespace SocialPoster\ValueObjects;

use SocialPoster\Enums\MediaType;

final class Media
{
    /**
     * @param array<string, mixed> $meta Caller-supplied known metadata (e.g. width/height
     *                                    for sources the inspector cannot reach).
     */
    public function __construct(
        public readonly string $source,
        public readonly MediaType $type,
        public readonly ?string $thumbnail = null,
        public readonly ?string $altText = null,
        public readonly array $meta = [],
    ) {}

    public static function image(string $source, ?string $altText = null): self
    {
        return new self($source, MediaType::Image, altText: $altText);
    }

    public static function video(string $source, ?string $thumbnail = null): self
    {
        return new self($source, MediaType::Video, thumbnail: $thumbnail);
    }

    public static function document(string $source): self
    {
        return new self($source, MediaType::Document);
    }

    public static function guess(string $source): self
    {
        $ext = self::extensionOf($source);

        if (in_array($ext, ['mp4', 'mov', 'm4v', 'webm', 'avi', 'mkv'], true)) {
            return self::video($source);
        }

        if (in_array($ext, ['pdf', 'doc', 'docx', 'ppt', 'pptx'], true)) {
            return self::document($source);
        }

        return self::image($source);
    }

    public function extension(): string
    {
        return self::extensionOf($this->source);
    }

    public function isRemote(): bool
    {
        return str_starts_with($this->source, 'http://')
            || str_starts_with($this->source, 'https://');
    }

    private static function extensionOf(string $source): string
    {
        $path = parse_url($source, PHP_URL_PATH) ?: $source;

        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }
}
