<?php

namespace SocialPoster\Inspectors;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Enums\MediaType;
use SocialPoster\Gateways\CappedSink;
use SocialPoster\Gateways\LocalMediaGateway;
use SocialPoster\Support\PublicUrl;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Default inspector. Videos use ffprobe, images use getimagesize() (plus EXIF
 * orientation), documents report their size only.
 *
 * Hardened because validate() runs it on user-supplied input, often inside a
 * web request:
 * - inspect() never throws; on any failure it returns what it has (size only,
 *   or nothing) and validation falls back to the structural checks.
 * - Every remote URL passes PublicUrl::check() first, and every fetch is
 *   pinned to the checked address with a short timeout. Sources on other
 *   schemes (ftp://, phar://...) are never opened (Media::hasForeignScheme()).
 * - Remote media is never handed to getimagesize() or, while private networks
 *   are blocked, to ffprobe: ffprobe follows HTTP redirects by itself, to any
 *   host, and no protocol whitelist stops that. The first bytes (and for a
 *   video whose index sits at the end, the last ones) are fetched with capped
 *   Range requests into a temp file, then measured or probed locally. The cap
 *   is enforced by the sink (CappedSink), so a server that ignores Range
 *   cannot make validation read the whole file.
 * - ffprobe runs with a timeout (social.inspector_timeout, 20s) and a protocol
 *   whitelist: "file" for local paths and copies, plus a demuxer whitelist for
 *   remote bytes so an HLS or concat playlist cannot pivot to other files or
 *   hosts. With block_private_networks off (local development), remote videos
 *   are probed by URL with https/tls/tcp only.
 *
 * The binary comes from social.ffprobe_path (Horizon/supervisor often run
 * without /opt/homebrew/bin or /usr/local/bin on PATH); its availability is
 * checked once per process.
 */
class FFProbeInspector implements MediaInspector
{
    /** Demuxers allowed for remote probing (no hls, dash, concat or image2 sequences). */
    protected const REMOTE_FORMATS = 'mov,mp4,m4a,3gp,3g2,mj2,matroska,webm,avi,flv,asf,mpeg,mpegts,mpegvideo,gif,h264,hevc';

    /** @var array<string, bool> availability per binary, memoized for the process */
    private array $available = [];

    public function __construct(
        protected string $ffprobe = 'ffprobe',
    ) {}

    public function supports(Media $media): bool
    {
        if ($media->hasForeignScheme()) {
            return false;
        }

        try {
            if ($media->isRemote()) {
                if (! filter_var(config('social.inspect_remote', true), FILTER_VALIDATE_BOOLEAN)) {
                    return false;
                }
            } elseif (! is_file($media->source)) {
                return false;
            }

            return match ($media->type) {
                MediaType::Image => function_exists('getimagesize'),
                MediaType::Document => true,
                MediaType::Video => $this->ffprobeAvailable(),
            };
        } catch (Throwable) {
            return false;
        }
    }

    public function inspect(Media $media): MediaMetadata
    {
        if ($media->hasForeignScheme()) {
            return new MediaMetadata;
        }

        try {
            if ($media->isRemote()) {
                PublicUrl::check($media->source);
            }

            return match ($media->type) {
                MediaType::Document => new MediaMetadata(sizeBytes: $this->sizeOf($media)),
                MediaType::Image => $this->inspectImage($media),
                MediaType::Video => $this->probeVideo($media),
            };
        } catch (Throwable $e) {
            $this->logFailure($media, $e);

            return new MediaMetadata(sizeBytes: $media->isRemote() ? null : $this->safeLocalSize($media));
        }
    }

    // --- Images ------------------------------------------------------------------

    protected function inspectImage(Media $media): MediaMetadata
    {
        if (! $media->isRemote()) {
            return $this->measureImage($media->source, $this->sizeOf($media));
        }

        [$tmp, $total] = $this->fetchHead($media->source);

        try {
            return $this->measureImage($tmp, $total);
        } finally {
            @unlink($tmp);
        }
    }

    /** getimagesize() plus EXIF orientation, with display (rotated) dimensions. */
    protected function measureImage(string $path, ?int $size): MediaMetadata
    {
        $info = @getimagesize($path) ?: [];
        $width = isset($info[0]) ? (int) $info[0] : null;
        $height = isset($info[1]) ? (int) $info[1] : null;
        $mime = isset($info['mime']) ? (string) $info['mime'] : null;
        $rotation = null;

        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
            $rotation = match ($orientation) {
                3, 4 => 180,
                5, 6 => 90,
                7, 8 => 270,
                default => null,
            };

            // Orientations 5-8 are transposed: the stored width is the displayed height.
            if ($orientation >= 5 && $orientation <= 8) {
                [$width, $height] = [$height, $width];
            }
        }

        return new MediaMetadata(
            sizeBytes: $size,
            width: $width ?: null,
            height: $height ?: null,
            mimeType: $mime,
            rotation: $rotation,
        );
    }

    /**
     * Download the first bytes of a remote file into a temp file (never the
     * whole file: the cap is $cap, else social.inspector_range_bytes, 2 MiB).
     * Returns the temp path and the full size when the server reported it.
     *
     * @return array{0: string, 1: int|null}
     */
    protected function fetchHead(string $url, ?int $cap = null): array
    {
        $cap ??= max(65536, (int) config('social.inspector_range_bytes', 2097152));
        $range = $this->fetchRange($url, 0, $cap);

        return [$range['path'], $range['total']];
    }

    /**
     * GET at most $length bytes of $url from offset $start into a new temp
     * file. The sink refuses bytes past $length, which stops the transfer even
     * when the server ignores Range and sends the whole file.
     *
     * @return array{path: string, total: int|null, start: int|null} start: the offset the
     *                                                               bytes begin at (0 for a 200), null when unknown
     */
    protected function fetchRange(string $url, int $start, int $length): array
    {
        $tmp = $this->tempFile();
        $sink = CappedSink::open($tmp, $length);
        $total = null;

        try {
            $response = $this->remoteRequest($url)
                ->withHeaders(['Range' => 'bytes='.$start.'-'.($start + $length - 1)])
                ->withOptions([
                    'sink' => $sink->resource(),
                    'on_headers' => static function (ResponseInterface $response) use (&$total): void {
                        $length = $response->getHeaderLine('Content-Length');

                        if ($response->getStatusCode() === 200 && ctype_digit($length)) {
                            $total = (int) $length;
                        }
                    },
                ])
                ->get($url);
        } catch (Throwable $e) {
            $sink->close();

            if (! $sink->exceeded) {
                @unlink($tmp);

                throw $e;
            }

            // The server ignored Range: the transfer was stopped after the first $length bytes.
            return ['path' => $tmp, 'total' => $total, 'start' => 0];
        }

        $sink->close();

        if (! $response->successful()) {
            @unlink($tmp);

            throw new RuntimeException("Media URL returned HTTP {$response->status()}.");
        }

        if (preg_match('~^\s*bytes\s+(\d+)-\d+/(\d+|\*)\s*$~i', (string) $response->header('Content-Range'), $m)) {
            return ['path' => $tmp, 'total' => is_numeric($m[2]) ? (int) $m[2] : null, 'start' => (int) $m[1]];
        }

        if ($response->status() === 200 && is_numeric($response->header('Content-Length'))) {
            $total = (int) $response->header('Content-Length');
        }

        return ['path' => $tmp, 'total' => $response->status() === 200 ? $total : null, 'start' => $response->status() === 200 ? 0 : null];
    }

    // --- Videos ------------------------------------------------------------------

    protected function probeVideo(Media $media): MediaMetadata
    {
        if ($media->isRemote() && PublicUrl::blocksPrivateNetworks()) {
            return $this->probeRemoteCopy($media->source);
        }

        $output = $this->runProbe($this->probeCommand($media));
        $data = $output !== null ? json_decode($output, true) : null;

        if (! is_array($data)) {
            return new MediaMetadata(sizeBytes: $this->sizeOf($media));
        }

        $metadata = $this->parseProbe($data);

        return $metadata->sizeBytes !== null
            ? $metadata
            : $metadata->withFallback(new MediaMetadata(sizeBytes: $this->sizeOf($media)));
    }

    /**
     * Probe a remote video from a local copy of its first bytes
     * (social.inspector_video_range_bytes, 4 MiB), plus its last bytes when the
     * head alone cannot be probed: phone videos store the MP4/MOV index (moov)
     * at the end. The tail is written at its real offset in a sparse file, so
     * ffprobe follows the box sizes to it. Size and bitrate come from the
     * remote file, not the partial copy.
     */
    protected function probeRemoteCopy(string $url): MediaMetadata
    {
        $cap = max(65536, (int) config('social.inspector_video_range_bytes', 4194304));
        $output = null;
        [$tmp, $total] = $this->fetchHead($url, $cap);

        try {
            $output = $this->runProbe($this->localProbeCommand($tmp));

            if ($output === null && $total !== null && $this->addTail($url, $tmp, $total, $cap)) {
                $output = $this->runProbe($this->localProbeCommand($tmp));
            }
        } finally {
            @unlink($tmp);
        }

        $data = $output !== null ? json_decode($output, true) : null;

        if (! is_array($data)) {
            return new MediaMetadata(sizeBytes: $total);
        }

        $probed = $this->parseProbe($data);

        return new MediaMetadata(
            sizeBytes: $total,
            width: $probed->width,
            height: $probed->height,
            durationSeconds: $probed->durationSeconds,
            mimeType: $probed->mimeType,
            codec: $probed->codec,
            bitrate: $total !== null && $probed->durationSeconds ? (int) round($total * 8 / $probed->durationSeconds) : null,
            frameRate: $probed->frameRate,
            rotation: $probed->rotation,
        );
    }

    /**
     * Fetch the last bytes of the $total-byte file at $url and write them at
     * their offset in $path, leaving a hole (a sparse file) between the head
     * and the tail. False when the tail could not be placed.
     */
    protected function addTail(string $url, string $path, int $total, int $cap): bool
    {
        clearstatcache(true, $path);
        $have = (int) @filesize($path);
        $free = @disk_free_space(dirname($path));

        // Filesystems without sparse files allocate the hole: never risk filling the disk.
        if ($total <= $have || ! is_float($free) || $free < $total + 67108864) {
            return false;
        }

        $start = max($have, $total - $cap);
        $tail = $this->fetchRange($url, $start, $total - $start);

        try {
            if ($tail['start'] !== $start) {
                return false; // the server ignored Range: these are not the last bytes
            }

            $in = @fopen($tail['path'], 'rb');
            $out = @fopen($path, 'r+b');

            if ($in === false || $out === false) {
                return false;
            }

            try {
                // The copy keeps the remote file's size, as the container's box sizes expect.
                return ftruncate($out, $total) && fseek($out, $start) === 0 && stream_copy_to_stream($in, $out) !== false;
            } finally {
                fclose($in);
                fclose($out);
            }
        } finally {
            @unlink($tail['path']);
        }
    }

    /**
     * ffprobe on a local copy of remote bytes: only the "file" protocol, and
     * the remote demuxer whitelist because the bytes are untrusted.
     *
     * @return string[]
     */
    protected function localProbeCommand(string $path): array
    {
        return [
            $this->binary(), '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams',
            '-protocol_whitelist', 'file', '-format_whitelist', static::REMOTE_FORMATS, $path,
        ];
    }

    /**
     * The ffprobe command line. Remote sources get a protocol and demuxer
     * whitelist; local files may only be read through the "file" protocol.
     * Remote URLs reach it only while private networks are not blocked (see
     * probeRemoteCopy()).
     *
     * @return string[]
     */
    protected function probeCommand(Media $media): array
    {
        $command = [$this->binary(), '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams'];

        if ($media->isRemote()) {
            $protocols = str_starts_with($media->source, 'http://') ? 'http,https,tls,tcp' : 'https,tls,tcp';
            $timeoutMicros = (string) ((int) $this->timeout() * 1000000);

            array_push($command, '-protocol_whitelist', $protocols, '-format_whitelist', static::REMOTE_FORMATS, '-rw_timeout', $timeoutMicros);
        } else {
            array_push($command, '-protocol_whitelist', 'file');
        }

        $command[] = $media->source;

        return $command;
    }

    /** Run ffprobe; the JSON output, or null when it failed or timed out. */
    protected function runProbe(array $command): ?string
    {
        $process = new Process($command);
        $process->setTimeout($this->timeout());
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    /**
     * Turn ffprobe JSON into metadata: display dimensions (rotation applied),
     * average frame rate, duration, codec, bitrate and a MIME type when the
     * container identifies one.
     *
     * @param  array<string, mixed>  $data
     */
    protected function parseProbe(array $data): MediaMetadata
    {
        $format = is_array($data['format'] ?? null) ? $data['format'] : [];
        $video = [];

        foreach ((array) ($data['streams'] ?? []) as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? null) === 'video'
                && (int) ($stream['disposition']['attached_pic'] ?? 0) !== 1) {
                $video = $stream;
                break;
            }
        }

        $width = isset($video['width']) ? (int) $video['width'] : null;
        $height = isset($video['height']) ? (int) $video['height'] : null;
        $rotation = $this->rotationOf($video);

        if ($rotation !== null && abs($rotation) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }

        $duration = $format['duration'] ?? $video['duration'] ?? null;

        return new MediaMetadata(
            sizeBytes: isset($format['size']) && is_numeric($format['size']) ? (int) $format['size'] : null,
            width: $width ?: null,
            height: $height ?: null,
            durationSeconds: is_numeric($duration) ? (float) $duration : null,
            mimeType: $this->mimeOf($format, $video),
            codec: isset($video['codec_name']) ? (string) $video['codec_name'] : null,
            bitrate: isset($format['bit_rate']) && is_numeric($format['bit_rate']) ? (int) $format['bit_rate'] : null,
            frameRate: $this->rate($video['avg_frame_rate'] ?? null) ?? $this->rate($video['r_frame_rate'] ?? null),
            rotation: $rotation,
        );
    }

    /**
     * Clockwise display rotation in degrees: the display-matrix side data
     * (ffprobe >= 5 reports it counter-clockwise, hence the sign flip), else
     * the legacy "rotate" tag.
     *
     * @param  array<string, mixed>  $video
     */
    protected function rotationOf(array $video): ?int
    {
        foreach ((array) ($video['side_data_list'] ?? []) as $side) {
            if (is_array($side) && isset($side['rotation']) && is_numeric($side['rotation'])) {
                return $this->normalizeRotation(-(int) round((float) $side['rotation']));
            }
        }

        $tag = $video['tags']['rotate'] ?? null;

        return is_numeric($tag) ? $this->normalizeRotation((int) $tag) : null;
    }

    protected function normalizeRotation(int $degrees): int
    {
        return (($degrees % 360) + 360) % 360;
    }

    /** "30000/1001" → 29.97; "0/0" or junk → null. */
    protected function rate(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value > 0 ? (float) $value : null;
        }

        if (! is_string($value) || ! preg_match('~^(\d+(?:\.\d+)?)/(\d+(?:\.\d+)?)$~', trim($value), $m) || (float) $m[2] == 0.0) {
            return null;
        }

        $rate = (float) $m[1] / (float) $m[2];

        return $rate > 0 ? round($rate, 3) : null;
    }

    /**
     * @param  array<string, mixed>  $format
     * @param  array<string, mixed>  $video
     */
    protected function mimeOf(array $format, array $video): ?string
    {
        $name = (string) ($format['format_name'] ?? '');

        if (str_contains($name, 'mp4') || str_contains($name, 'mov')) {
            $brand = strtolower(trim((string) ($format['tags']['major_brand'] ?? '')));

            return match (true) {
                $brand === 'qt' => 'video/quicktime',
                str_starts_with($brand, '3g') => 'video/3gpp',
                default => 'video/mp4',
            };
        }

        return match (true) {
            str_contains($name, 'webm') && in_array($video['codec_name'] ?? null, ['vp8', 'vp9', 'av1'], true) => 'video/webm',
            str_contains($name, 'matroska') => 'video/x-matroska',
            $name === 'avi' => 'video/x-msvideo',
            $name === 'flv' => 'video/x-flv',
            $name === 'asf' => 'video/x-ms-wmv',
            $name === 'mpegts' => 'video/mp2t',
            $name === 'mpeg' => 'video/mpeg',
            $name === 'gif' => 'image/gif',
            default => null,
        };
    }

    // --- Shared ------------------------------------------------------------------

    protected function sizeOf(Media $media): ?int
    {
        if (! $media->isRemote()) {
            return $this->safeLocalSize($media);
        }

        try {
            $response = $this->remoteRequest($media->source)->head($media->source);
            $length = $response->successful() ? $response->header('Content-Length') : null;

            return is_numeric($length) ? (int) $length : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function ffprobeAvailable(): bool
    {
        $binary = $this->binary();

        if (isset($this->available[$binary])) {
            return $this->available[$binary];
        }

        try {
            $process = new Process([$binary, '-version']);
            $process->setTimeout(5);
            $process->run();

            return $this->available[$binary] = $process->isSuccessful();
        } catch (Throwable) {
            return $this->available[$binary] = false;
        }
    }

    /** The ffprobe binary: an explicit constructor argument wins, else social.ffprobe_path. */
    protected function binary(): string
    {
        if ($this->ffprobe !== 'ffprobe' && $this->ffprobe !== '') {
            return $this->ffprobe;
        }

        $configured = config('social.ffprobe_path');

        return is_string($configured) && $configured !== '' ? $configured : 'ffprobe';
    }

    protected function timeout(): float
    {
        return max(1.0, (float) config('social.inspector_timeout', 20));
    }

    /** A short-timeout HTTP request pinned to the checked address, re-checking redirects. */
    protected function remoteRequest(string $url): PendingRequest
    {
        $options = ['allow_redirects' => PublicUrl::redirectOptions(3)];

        if (PublicUrl::blocksPrivateNetworks()) {
            $options += PublicUrl::curlOptions(PublicUrl::resolvePinned($url));
        }

        return Http::connectTimeout(5)
            ->timeout((int) min(10, $this->timeout()))
            ->withOptions($options);
    }

    protected function tempFile(): string
    {
        return LocalMediaGateway::temporaryDirectory().'/sp_'.bin2hex(random_bytes(8)).'.part';
    }

    private function safeLocalSize(Media $media): ?int
    {
        if ($media->hasForeignScheme()) {
            return null;
        }

        $size = @filesize($media->source);

        return is_int($size) && $size > 0 ? $size : null;
    }

    private function logFailure(Media $media, Throwable $e): void
    {
        try {
            Log::info('[SocialPoster] Media inspection skipped: '.$e->getMessage(), [
                'type' => $media->type->value,
                'remote' => $media->isRemote(),
                'exception' => $e::class,
            ]);
        } catch (Throwable) {
            // Logging must never break validation.
        }
    }
}
