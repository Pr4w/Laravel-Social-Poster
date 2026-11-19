<?php

namespace SocialPoster\ValueObjects;

/**
 * What an inspector (or the caller, through Media::$meta) knows about a file.
 * Every field is optional: null means "unknown", and validation skips the
 * checks it cannot make.
 *
 * $width and $height are DISPLAY dimensions: already corrected for a video's
 * rotation (side data / rotate tag) and an image's EXIF orientation, so a
 * phone video stored as 1920x1080 with rotation 90 reports 1080x1920.
 * $rotation keeps the raw clockwise rotation in degrees for drivers that need it.
 */
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
        public readonly ?float $frameRate = null,
        public readonly ?int $rotation = null,
    ) {}

    public function aspectRatio(): ?float
    {
        if (! $this->width || ! $this->height) {
            return null;
        }

        return $this->width / $this->height;
    }

    /** width x height, or null when either is unknown. */
    public function pixels(): ?int
    {
        return $this->width && $this->height ? $this->width * $this->height : null;
    }

    /** True when nothing at all is known. */
    public function isEmpty(): bool
    {
        return $this->sizeBytes === null && $this->width === null && $this->height === null
            && $this->durationSeconds === null && $this->mimeType === null && $this->codec === null
            && $this->bitrate === null && $this->frameRate === null && $this->rotation === null;
    }

    /**
     * Fill the fields this object does not know from $fallback (measured values
     * win over caller-supplied ones).
     */
    public function withFallback(?self $fallback): self
    {
        if ($fallback === null) {
            return $this;
        }

        return new self(
            sizeBytes: $this->sizeBytes ?? $fallback->sizeBytes,
            width: $this->width ?? $fallback->width,
            height: $this->height ?? $fallback->height,
            durationSeconds: $this->durationSeconds ?? $fallback->durationSeconds,
            mimeType: $this->mimeType ?? $fallback->mimeType,
            codec: $this->codec ?? $fallback->codec,
            bitrate: $this->bitrate ?? $fallback->bitrate,
            frameRate: $this->frameRate ?? $fallback->frameRate,
            rotation: $this->rotation ?? $fallback->rotation,
        );
    }

    /**
     * Build from a caller-supplied array (Media::$meta). Accepts camelCase and
     * snake_case keys plus short aliases: width, height, duration
     * (durationSeconds), size (sizeBytes), mime (mimeType), codec, bitrate,
     * frameRate (fps), rotation. Unknown keys and non-numeric values are ignored.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function fromArray(array $meta): self
    {
        $pick = static function (array $keys) use ($meta): mixed {
            foreach ($keys as $key) {
                if (array_key_exists($key, $meta) && $meta[$key] !== null && $meta[$key] !== '') {
                    return $meta[$key];
                }
            }

            return null;
        };

        $int = static fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null;
        $float = static function (mixed $value): ?float {
            if (is_string($value) && preg_match('~^\s*(\d+(?:\.\d+)?)\s*/\s*(\d+(?:\.\d+)?)\s*$~', $value, $m)) {
                return (float) $m[2] > 0 ? (float) $m[1] / (float) $m[2] : null;
            }

            return is_numeric($value) ? (float) $value : null;
        };
        $string = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;

        return new self(
            sizeBytes: $int($pick(['sizeBytes', 'size_bytes', 'size'])),
            width: $int($pick(['width'])),
            height: $int($pick(['height'])),
            durationSeconds: $float($pick(['durationSeconds', 'duration_seconds', 'duration'])),
            mimeType: $string($pick(['mimeType', 'mime_type', 'mime'])),
            codec: $string($pick(['codec'])),
            bitrate: $int($pick(['bitrate', 'bit_rate'])),
            frameRate: $float($pick(['frameRate', 'frame_rate', 'fps'])),
            rotation: $int($pick(['rotation'])),
        );
    }
}
