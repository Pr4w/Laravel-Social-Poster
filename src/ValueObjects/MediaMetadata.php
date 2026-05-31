<?php

namespace SocialPoster\ValueObjects;

final class MediaMetadata
{
    public function __construct(
        public readonly ?int $sizeBytes = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?float $durationSeconds = null,
        public readonly ?string $mimeType = null,
        public readonly ?string $codec = null,
        public readonly ?int $bitrate = null,
    ) {}

    public function aspectRatio(): ?float
    {
        if (! $this->width || ! $this->height) {
            return null;
        }

        return $this->width / $this->height;
    }
}
