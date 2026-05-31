<?php

namespace SocialPoster\Inspectors;

use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Enums\MediaType;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Default inspector. Images use getimagesize, videos use ffprobe, documents
 * report size only. ffprobe and getimagesize both read remote URLs, so remote
 * media is inspected too. Degrades only when tooling cannot run.
 */
class FFProbeInspector implements MediaInspector
{
    public function __construct(
        protected string $ffprobe = 'ffprobe',
    ) {}

    public function supports(Media $media): bool
    {
        if (! $media->isRemote() && ! is_file($media->source)) {
            return false;
        }

        return match ($media->type) {
            MediaType::Image => function_exists('getimagesize'),
            MediaType::Document => true,
            MediaType::Video => $this->ffprobeAvailable(),
        };
    }

    public function inspect(Media $media): MediaMetadata
    {
        if ($media->type === MediaType::Document) {
            return new MediaMetadata(sizeBytes: $this->sizeOf($media));
        }

        if ($media->type === MediaType::Image) {
            $info = @getimagesize($media->source) ?: [];

            return new MediaMetadata(
                sizeBytes: $this->sizeOf($media),
                width: $info[0] ?? null,
                height: $info[1] ?? null,
                mimeType: $info['mime'] ?? null,
            );
        }

        return $this->probeVideo($media);
    }

    protected function sizeOf(Media $media): ?int
    {
        if (! $media->isRemote()) {
            return @filesize($media->source) ?: null;
        }

        $headers = @get_headers($media->source, true) ?: [];
        $length = $headers['Content-Length'] ?? $headers['content-length'] ?? null;

        if (is_array($length)) {
            $length = end($length);
        }

        return $length !== null ? (int) $length : null;
    }

    protected function ffprobeAvailable(): bool
    {
        try {
            $process = new Process([$this->ffprobe, '-version']);
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    protected function probeVideo(Media $media): MediaMetadata
    {
        $process = new Process([
            $this->ffprobe, '-v', 'quiet', '-print_format', 'json',
            '-show_format', '-show_streams', $media->source,
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            return new MediaMetadata(sizeBytes: $this->sizeOf($media));
        }

        $data = json_decode($process->getOutput(), true) ?: [];
        $format = $data['format'] ?? [];
        $video = [];

        foreach ($data['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? null) === 'video') {
                $video = $stream;
                break;
            }
        }

        return new MediaMetadata(
            sizeBytes: isset($format['size']) ? (int) $format['size'] : $this->sizeOf($media),
            width: $video['width'] ?? null,
            height: $video['height'] ?? null,
            durationSeconds: isset($format['duration']) ? (float) $format['duration'] : null,
            codec: $video['codec_name'] ?? null,
            bitrate: isset($format['bit_rate']) ? (int) $format['bit_rate'] : null,
        );
    }
}
