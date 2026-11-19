<?php

namespace SocialPoster\Gateways;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\WhitespacePathNormalizer;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SocialPoster\Contracts\MediaGateway;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Support\PublicUrl;
use SocialPoster\ValueObjects\Media;
use Throwable;

/**
 * Default gateway. Remote media is passed through as a URL; bytes are produced
 * for anything (downloading remote sources to a temp file). It cannot invent a
 * public URL for a local file, so url() on local media fails loudly. Bind
 * FilesystemMediaGateway (re-hosts local files on a public disk) or your own
 * MediaGateway to support local files on pull-only platforms.
 *
 * Downloads are hardened because the URL usually comes from a user:
 * - PublicUrl policy on every hop (https unless allowed, no userinfo, optional
 *   host list, no private networks when block_private_networks is on), with
 *   the connection pinned to the checked address (no DNS rebinding);
 * - at most 3 redirects, each re-checked and re-pinned;
 * - a timeout (social.download_timeout, 120s) and a size cap
 *   (social.media.max_download_bytes, plus the free space of the temp disk)
 *   that stops the transfer itself: a Content-Length above it aborts at the
 *   headers, and the sink refuses bytes past it (see CappedSink);
 * - 4xx → PermanentException(MediaRejected), 5xx or a transport error →
 *   TemporaryException(Network); the partial file is always removed.
 *
 * Temp files are named sp_* under social.temp_dir (storage/app/social-poster-tmp)
 * and removed by cleanup(); `php artisan social-poster:prune-temp` is the
 * backstop for workers killed mid-run.
 *
 * Local paths: when social.media.local_roots is a list, only files under one of
 * those directories (or the temp dir) are accepted, so request input can never
 * publish arbitrary server files. null (the 1.x default) accepts any readable
 * path and logs a deprecation notice once per process. Sources on any other
 * scheme than http(s):// and file:// (ftp://, phar://, php://, a registered
 * s3:// wrapper...) are refused before PHP touches them (Media::hasForeignScheme()),
 * and disk paths may not climb out of the disk root ("../").
 */
class LocalMediaGateway implements MediaGateway
{
    protected const MAX_REDIRECTS = 3;

    /** Bytes always left free on the temp disk. */
    protected const FREE_SPACE_RESERVE = 67108864;

    private const TOO_LARGE = 'social-poster:download-exceeds-max-bytes';

    private const DISK_FULL = 'social-poster:download-exceeds-free-space';

    /** @var string[] */
    private array $tempFiles = [];

    private static bool $warnedAboutRoots = false;

    public function __construct(
        protected ?string $tempDir = null,
    ) {}

    public function canProvideUrl(Media $media): bool
    {
        return $media->isRemote();
    }

    public function url(Media $media): string
    {
        if (! $media->isRemote()) {
            throw new PermanentException(
                "No public URL is available for the local media '{$media->source}'. Host it publicly, pass a URL, or bind a MediaGateway that can publish local files (e.g. FilesystemMediaGateway).",
                reason: FailureReason::MediaRejected,
            );
        }

        return $media->source;
    }

    public function path(Media $media): string
    {
        if ($media->isOnDisk()) {
            return $this->pathFromDisk($media);
        }

        if ($media->hasForeignScheme()) {
            throw static::foreignScheme($media);
        }

        if (! $media->isRemote()) {
            if (! is_file($media->source)) {
                throw new PermanentException("Local media not found: {$media->source}", reason: FailureReason::MediaRejected);
            }

            $this->assertLocalPathAllowed($media->source);

            return $media->source;
        }

        return $this->download($media->source, $media->extension());
    }

    public function cleanup(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        $this->tempFiles = [];
    }

    /**
     * The directory for sp_* temp files: the constructor argument, else
     * social.temp_dir, else storage/app/social-poster-tmp. Created on demand.
     */
    public static function temporaryDirectory(?string $preferred = null): string
    {
        $dir = $preferred ?? config('social.temp_dir');

        if (! is_string($dir) || $dir === '') {
            $dir = storage_path('app/social-poster-tmp');
        }

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new PermanentException(
                "The social-poster temp directory '{$dir}' does not exist and cannot be created. Set social.temp_dir to a writable directory.",
                reason: FailureReason::Misconfigured,
            );
        }

        return rtrim($dir, '/\\');
    }

    // --- Remote downloads ----------------------------------------------------------

    /**
     * Download $url to a fresh sp_* temp file and return its path.
     *
     * @throws PermanentException|TemporaryException
     */
    protected function download(string $url, string $extension = ''): string
    {
        $tmp = $this->newTempFile($extension);

        try {
            $this->fetchTo($url, $tmp);
        } catch (Throwable $e) {
            $this->forget($tmp);

            throw $e instanceof SocialPosterException ? $e : TemporaryException::connection(null, $e);
        }

        return $tmp;
    }

    /** Follow up to MAX_REDIRECTS hops by hand so every hop is checked AND pinned. */
    protected function fetchTo(string $url, string $tmp): void
    {
        $limits = $this->byteLimits(dirname($tmp));
        $response = null;

        for ($hop = 0; $hop <= static::MAX_REDIRECTS; $hop++) {
            $response = $this->send($url, $tmp, $limits);

            if (! $response->redirect()) {
                break;
            }

            $location = trim((string) $response->header('Location'));

            if ($location === '' || $hop === static::MAX_REDIRECTS) {
                throw new PermanentException(
                    $location === ''
                        ? 'The media URL '.$this->display($url).' redirected without a Location header.'
                        : 'The media URL '.$this->display($url).' redirected more than '.static::MAX_REDIRECTS.' times.',
                    reason: FailureReason::MediaRejected,
                    context: ['http_status' => $response->status()],
                );
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
        }

        $this->assertDownloaded($response, $url, $tmp, $limits);
    }

    /**
     * One GET into $tmp, stopped as soon as the size limits are known to be
     * exceeded: at the headers when Content-Length says so, else when the sink
     * refuses the first byte past the cap (cURL then aborts the transfer).
     *
     * @param  array{max: int|null, free: int|null}  $limits
     */
    protected function send(string $url, string $tmp, array $limits): Response
    {
        $aborted = null;
        $options = [
            'allow_redirects' => false,
            'on_headers' => static function (ResponseInterface $response) use ($limits, &$aborted): void {
                $length = $response->getHeaderLine('Content-Length');
                $status = $response->getStatusCode();

                if ($status >= 200 && $status < 300 && ctype_digit($length)
                    && ($aborted = self::limitExceeded((int) $length, $limits)) !== null) {
                    throw new RuntimeException($aborted);
                }
            },
        ];

        if (PublicUrl::blocksPrivateNetworks()) {
            $options += PublicUrl::curlOptions(PublicUrl::resolvePinned($url, true));
        } else {
            PublicUrl::check($url, false);
        }

        try {
            $sink = CappedSink::open($tmp, self::capOf($limits));
        } catch (RuntimeException $e) {
            throw new PermanentException("Cannot write the temp file {$tmp}.", reason: FailureReason::Misconfigured, previous: $e);
        }

        $options['sink'] = $sink->resource();

        try {
            $response = Http::connectTimeout(10)
                ->timeout((int) config('social.download_timeout', 120))
                ->withOptions($options)
                ->get($url);
        } catch (Throwable $e) {
            $abort = $aborted
                ?? ($sink->exceeded ? self::limitExceeded($sink->written + 1, $limits) : null)
                ?? $this->abortReason($e);

            if ($abort !== null) {
                throw $this->tooLarge($url, $abort, $limits);
            }

            if ($e instanceof ConnectionException) {
                throw TemporaryException::connection(null, $e)->addContext(['media_download' => true]);
            }

            throw $e;
        } finally {
            $sink->close();
        }

        if ($sink->exceeded) {
            throw $this->tooLarge($url, self::limitExceeded($sink->written + 1, $limits) ?? self::TOO_LARGE, $limits);
        }

        return $response;
    }

    /** @param  array{max: int|null, free: int|null}  $limits */
    protected function assertDownloaded(?Response $response, string $url, string $tmp, array $limits): void
    {
        if ($response === null) {
            throw new TemporaryException('No response downloading '.$this->display($url).'.', reason: FailureReason::Network);
        }

        $status = $response->status();
        $context = ['http_status' => $status, 'url_host' => parse_url($url, PHP_URL_HOST)];

        if ($status >= 500) {
            throw new TemporaryException("The media URL {$this->display($url)} returned HTTP {$status}.", reason: FailureReason::Network, retryAfter: $this->retryAfter($response), context: $context);
        }

        if (in_array($status, [408, 425, 429], true)) {
            throw new TemporaryException(
                "The media URL {$this->display($url)} returned HTTP {$status}.",
                reason: $status === 429 ? FailureReason::RateLimited : FailureReason::Timeout,
                retryAfter: $this->retryAfter($response),
                context: $context,
            );
        }

        if (! $response->successful()) {
            throw new PermanentException("The media URL {$this->display($url)} returned HTTP {$status}.", reason: FailureReason::MediaRejected, context: $context);
        }

        clearstatcache(true, $tmp);
        $size = (int) @filesize($tmp);

        if ($limits['max'] !== null && $size > $limits['max']) {
            throw $this->tooLarge($url, self::TOO_LARGE, $limits);
        }

        if ($size === 0) {
            throw new PermanentException("The media URL {$this->display($url)} returned an empty file.", reason: FailureReason::MediaRejected, context: $context);
        }
    }

    // --- Disk-backed media (Media::fromDisk) -------------------------------------------

    /**
     * A local path for media on a filesystem disk: the file itself on a local
     * disk, else a temp copy streamed from the disk (no public URL needed, so
     * private buckets work). The path is normalised first and may not climb
     * out of the disk root: on Laravel 12 FilesystemAdapter::path()
     * does not normalise "../" itself.
     */
    protected function pathFromDisk(Media $media): string
    {
        $disk = (string) $media->disk();
        $path = static::normalizeDiskPath($disk, (string) $media->diskPath());
        $filesystem = Storage::disk($disk);

        try {
            if ($filesystem instanceof FilesystemAdapter && $filesystem->getAdapter() instanceof LocalFilesystemAdapter) {
                $local = $filesystem->path($path);

                if (! is_file($local)) {
                    throw new PermanentException("Media not found on disk '{$disk}': {$path}", reason: FailureReason::MediaRejected);
                }

                return $local;
            }

            $stream = $filesystem->readStream($path);
        } catch (SocialPosterException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PermanentException("Media cannot be read from disk '{$disk}': {$path} ({$e->getMessage()})", reason: FailureReason::MediaRejected, previous: $e);
        }

        if (! is_resource($stream)) {
            throw new PermanentException("Media not found on disk '{$disk}': {$path}", reason: FailureReason::MediaRejected);
        }

        $tmp = $this->newTempFile($media->extension());

        try {
            $this->copyStream($stream, $tmp, $this->byteLimits(dirname($tmp)));
        } catch (Throwable $e) {
            $this->forget($tmp);

            throw $e instanceof SocialPosterException
                ? $e
                : new TemporaryException("Could not copy media from disk '{$disk}': {$e->getMessage()}", reason: FailureReason::Network, previous: $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $tmp;
    }

    /**
     * Copy $stream into $tmp within the same limits as a download: the
     * max_download_bytes cap and the free space of the temp disk. A short
     * write (disk full, quota) fails the copy instead of leaving a truncated
     * file that would be uploaded as if it were complete.
     *
     * @param  resource  $stream
     * @param  array{max: int|null, free: int|null}  $limits
     */
    protected function copyStream($stream, string $tmp, array $limits): void
    {
        $out = @fopen($tmp, 'wb');

        if ($out === false) {
            throw new PermanentException("Cannot write the temp file {$tmp}.", reason: FailureReason::Misconfigured);
        }

        $written = 0;

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1048576);

                if ($chunk === false) {
                    throw new RuntimeException('read failed');
                }

                $written += strlen($chunk);

                if (($exceeded = self::limitExceeded($written, $limits)) !== null) {
                    throw $this->tooLarge('disk media', $exceeded, $limits);
                }

                if ($chunk !== '' && @fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new PermanentException(
                        "Could not write the temp file {$tmp} (disk full or quota exceeded). Free space in social.temp_dir or point it to a larger disk.",
                        reason: FailureReason::Misconfigured,
                        context: ['free_bytes' => $limits['free']],
                    );
                }
            }
        } finally {
            fclose($out);
        }
    }

    /**
     * $path on $disk, normalised ("a/./b/../c" → "a/c"); a path that climbs out
     * of the disk root is refused.
     *
     * @throws PermanentException MediaRejected for "../" past the root or control characters
     */
    public static function normalizeDiskPath(string $disk, string $path): string
    {
        try {
            return (new WhitespacePathNormalizer)->normalizePath($path);
        } catch (Throwable $e) {
            throw new PermanentException(
                "Refusing the media path '{$path}' on disk '{$disk}': it leaves the disk root or contains control characters.",
                reason: FailureReason::MediaRejected,
                context: ['path_traversal' => true],
                previous: $e,
            );
        }
    }

    /** The PermanentException for a source on a scheme no gateway may open (ftp://, phar://...). */
    public static function foreignScheme(Media $media): PermanentException
    {
        $scheme = strtolower((string) strstr($media->source, '://', true));

        return new PermanentException(
            "Media sources must be http(s) URLs or local files; the '{$scheme}://' scheme is not allowed.",
            reason: FailureReason::MediaRejected,
            context: ['scheme' => $scheme],
        );
    }

    // --- Local paths ---------------------------------------------------------------------

    /** Enforce social.media.local_roots (null = any path, with a one-time deprecation notice). */
    protected function assertLocalPathAllowed(string $path): void
    {
        $roots = config('social.media.local_roots');

        if ($roots === null) {
            $this->warnAboutOpenRoots();

            return;
        }

        $real = realpath($path);
        $allowed = array_merge((array) $roots, [static::temporaryDirectory($this->tempDir)]);

        foreach ($allowed as $root) {
            $root = is_string($root) ? realpath($root) : false;

            if ($real !== false && $root !== false && ($real === $root || str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))) {
                return;
            }
        }

        throw new PermanentException(
            "Local media '{$path}' is outside the allowed directories (social.media.local_roots).",
            reason: FailureReason::MediaRejected,
            context: ['local_roots' => true],
        );
    }

    private function warnAboutOpenRoots(): void
    {
        if (self::$warnedAboutRoots) {
            return;
        }

        self::$warnedAboutRoots = true;

        try {
            $channel = config('logging.deprecations.channel');

            Log::channel(is_string($channel) && $channel !== '' ? $channel : 'null')->warning(
                '[SocialPoster] social.media.local_roots is not set, so any readable local path can be published. '
                .'Set it to the directories your media lives in; 2.0 will require it.'
            );
        } catch (Throwable) {
            // A missing log channel must never break publishing.
        }
    }

    // --- Helpers ---------------------------------------------------------------------------

    protected function newTempFile(string $extension = ''): string
    {
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?? '';
        $tmp = static::temporaryDirectory($this->tempDir).'/sp_'.bin2hex(random_bytes(8)).($extension !== '' ? '.'.$extension : '');

        // Registered before writing so cleanup() also removes partial files.
        $this->tempFiles[] = $tmp;

        return $tmp;
    }

    protected function forget(string $tmp): void
    {
        @unlink($tmp);
        $this->tempFiles = array_values(array_diff($this->tempFiles, [$tmp]));
    }

    /** @return array{max: int|null, free: int|null} */
    protected function byteLimits(string $dir): array
    {
        $max = config('social.media.max_download_bytes');
        $free = @disk_free_space($dir);

        return [
            'max' => is_numeric($max) && (int) $max > 0 ? (int) $max : null,
            'free' => is_float($free) ? max(0, (int) $free - static::FREE_SPACE_RESERVE) : null,
        ];
    }

    /**
     * The limit $bytes breaks (TOO_LARGE before DISK_FULL), or null.
     *
     * @param  array{max: int|null, free: int|null}  $limits
     */
    private static function limitExceeded(int $bytes, array $limits): ?string
    {
        if ($limits['max'] !== null && $bytes > $limits['max']) {
            return self::TOO_LARGE;
        }

        if ($limits['free'] !== null && $bytes > $limits['free']) {
            return self::DISK_FULL;
        }

        return null;
    }

    /**
     * The smallest limit, or null when there is none.
     *
     * @param  array{max: int|null, free: int|null}  $limits
     */
    private static function capOf(array $limits): ?int
    {
        $caps = array_filter([$limits['max'], $limits['free']], static fn ($limit) => $limit !== null);

        return $caps === [] ? null : min($caps);
    }

    /** @param  array{max: int|null, free: int|null}  $limits */
    private function tooLarge(string $url, string $reason, array $limits): PermanentException
    {
        if ($reason === self::DISK_FULL) {
            return new PermanentException(
                'Not enough free space in the social-poster temp directory to download '.$this->display($url).'.',
                reason: FailureReason::Misconfigured,
                context: ['free_bytes' => $limits['free']],
            );
        }

        return new PermanentException(
            'The media at '.$this->display($url).' is larger than social.media.max_download_bytes ('.$limits['max'].' bytes).',
            reason: FailureReason::MediaRejected,
            context: ['max_download_bytes' => $limits['max']],
        );
    }

    private function abortReason(Throwable $e): ?string
    {
        for ($cursor = $e; $cursor !== null; $cursor = $cursor->getPrevious()) {
            foreach ([self::TOO_LARGE, self::DISK_FULL] as $marker) {
                if (str_contains($cursor->getMessage(), $marker)) {
                    return $marker;
                }
            }
        }

        return null;
    }

    private function retryAfter(Response $response): ?int
    {
        $header = trim((string) $response->header('Retry-After'));

        return ctype_digit($header) ? min((int) $header, 900) : null;
    }

    /** scheme://host/path, never the query string (signed URLs carry credentials there). */
    protected function display(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return "'".$url."'";
        }

        return "'".($parts['scheme'] ?? 'https').'://'.$parts['host'].($parts['path'] ?? '')."'";
    }
}
