<?php

namespace SocialPoster\Gateways;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\ValueObjects\Media;
use Throwable;
use WeakMap;

/**
 * Gives pull-only platforms (Instagram, Facebook, Threads, Pinterest URLs,
 * TikTok PULL_FROM_URL) a public URL for media they cannot otherwise fetch, by
 * copying it to a Laravel filesystem disk you control.
 *
 * Rehost policy (social.media.rehost):
 * - local_only (default): local files and files on local disks are copied to
 *   the rehost disk; remote URLs and cloud-disk media are passed through.
 * - always: remote URLs are downloaded (with LocalMediaGateway's SSRF/size
 *   guards) and copied too. Use it when your sources are signed or
 *   short-lived URLs, or when TikTok requires a verified domain.
 * - never: behaves like LocalMediaGateway, except that media on a disk
 *   (Media::fromDisk) gets that disk's URL.
 *
 * Copies go to social.media.rehost_disk ('public') under
 * social.media.rehost_prefix ('social-poster'), named by a hash of the source
 * so a retry reuses the same object instead of uploading a duplicate. They must
 * outlive the publish (the platform fetches them asynchronously), so cleanup()
 * leaves them; `php artisan social-poster:prune-temp` removes copies older
 * than 24 hours. URLs come from the disk's url(), or temporaryUrl() when
 * social.media.temporary_urls is on and the disk supports it (TTL
 * social.media.url_ttl). Note that some platforms (Pinterest, Instagram)
 * reject signed or expiring URLs, so a public disk is the safer default.
 *
 * Copies keep their extension only when it is a media format (MEDIA_EXTENSIONS);
 * anything else is stored as .bin. The rehost disk is usually served from the
 * application's own origin, so a ".html", ".svg" or ".php" copy of a
 * user-supplied URL at a predictable path would be stored XSS, or code
 * execution on servers that run any *.php under public/.
 */
class FilesystemMediaGateway extends LocalMediaGateway
{
    public const REHOST_ALWAYS = 'always';

    public const REHOST_LOCAL_ONLY = 'local_only';

    public const REHOST_NEVER = 'never';

    /** Re-upload a cached copy older than this, so the prune never races a retry. */
    protected const REFRESH_AFTER = 3600;

    /** Extensions a copy may keep: images, videos and documents the drivers publish. */
    protected const MEDIA_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif', 'avif', 'bmp', 'tif', 'tiff',
        'mp4', 'mov', 'm4v', 'webm', 'avi', 'mkv', 'wmv', 'flv', 'mpg', 'mpeg', '3gp',
        'pdf', 'doc', 'docx', 'ppt', 'pptx',
    ];

    /** @var WeakMap<Media, string>|null */
    private ?WeakMap $urls = null;

    public function __construct(
        ?string $tempDir = null,
        protected ?string $disk = null,
        protected ?string $prefix = null,
        protected ?string $policy = null,
        protected ?bool $temporaryUrls = null,
        protected ?int $urlTtl = null,
        protected ?string $visibility = null,
    ) {
        parent::__construct($tempDir);
    }

    public function canProvideUrl(Media $media): bool
    {
        if ($media->isRemote()) {
            return true;
        }

        if ($media->hasForeignScheme()) {
            return false;
        }

        try {
            $never = $this->policy() === self::REHOST_NEVER;
        } catch (SocialPosterException) {
            $never = false; // validate() never throws; url() reports the bad policy at publish time
        }

        return $never ? $media->isOnDisk() : ($media->isOnDisk() || is_file($media->source));
    }

    public function url(Media $media): string
    {
        $this->urls ??= new WeakMap;

        return $this->urls[$media] ??= $this->resolveUrl($media);
    }

    /** The disk copies are written to. */
    public function rehostDisk(): string
    {
        $disk = $this->disk ?? config('social.media.rehost_disk', 'public');

        return is_string($disk) && $disk !== '' ? $disk : 'public';
    }

    /** The directory on the rehost disk that holds the copies (no slashes around it). */
    public function rehostPrefix(): string
    {
        $prefix = $this->prefix ?? config('social.media.rehost_prefix', 'social-poster');

        return trim(is_string($prefix) ? $prefix : 'social-poster', '/');
    }

    public function policy(): string
    {
        $policy = $this->policy ?? config('social.media.rehost', self::REHOST_LOCAL_ONLY);

        if (! in_array($policy, [self::REHOST_ALWAYS, self::REHOST_LOCAL_ONLY, self::REHOST_NEVER], true)) {
            throw new PermanentException(
                "Unknown social.media.rehost policy '".(is_scalar($policy) ? $policy : get_debug_type($policy))."'. Use always, local_only or never.",
                reason: FailureReason::Misconfigured,
            );
        }

        return $policy;
    }

    protected function resolveUrl(Media $media): string
    {
        $policy = $this->policy();

        if ($media->hasForeignScheme() && ! $media->isOnDisk()) {
            throw static::foreignScheme($media);
        }

        if ($media->isOnDisk()) {
            return $this->urlForDiskMedia($media, $policy);
        }

        if ($media->isRemote()) {
            if ($policy !== self::REHOST_ALWAYS) {
                return $media->source;
            }

            $tmp = $this->download($media->source, $media->extension());

            return $this->rehost($tmp, 'url|'.$media->source, $media->extension());
        }

        if ($policy === self::REHOST_NEVER) {
            return parent::url($media);
        }

        $path = $this->path($media); // existence + social.media.local_roots
        clearstatcache(true, $path);

        return $this->rehost($path, 'file|'.realpath($path).'|'.@filesize($path).'|'.@filemtime($path), $media->extension());
    }

    protected function urlForDiskMedia(Media $media, string $policy): string
    {
        $disk = (string) $media->disk();
        $path = static::normalizeDiskPath($disk, (string) $media->diskPath());

        // Already on the public disk, or a cloud disk that serves its own URLs.
        if ($disk === $this->rehostDisk() || $policy === self::REHOST_NEVER
            || ($policy === self::REHOST_LOCAL_ONLY && ! $this->isLocalDisk($disk))) {
            return $this->publicUrl($disk, $path);
        }

        $source = Storage::disk($disk);

        try {
            $size = $source->size($path);
        } catch (Throwable) {
            $size = null;
        }

        $key = 'disk|'.$disk.'|'.$path.'|'.$size;
        $target = $this->targetPath($key, $media->extension());

        if (! $this->isFresh($target)) {
            $stream = $source->readStream($path);

            if (! is_resource($stream)) {
                throw new PermanentException("Media not found on disk '{$disk}': {$path}", reason: FailureReason::MediaRejected);
            }

            try {
                $this->write($target, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        return $this->publicUrl($this->rehostDisk(), $target);
    }

    /** Copy a local file to the rehost disk (unless a fresh copy exists) and return its URL. */
    protected function rehost(string $localPath, string $identity, string $extension): string
    {
        $target = $this->targetPath($identity, $extension);

        if (! $this->isFresh($target)) {
            $stream = @fopen($localPath, 'rb');

            if ($stream === false) {
                throw new PermanentException("Cannot read the media file '{$localPath}'.", reason: FailureReason::MediaRejected);
            }

            try {
                $this->write($target, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        return $this->publicUrl($this->rehostDisk(), $target);
    }

    /** @param  resource  $stream */
    protected function write(string $target, $stream): void
    {
        $options = $this->visibility ?? config('social.media.rehost_visibility');
        $options = is_string($options) && $options !== '' ? ['visibility' => $options] : [];

        try {
            $written = $this->filesystem()->writeStream($target, $stream, $options);
        } catch (Throwable $e) {
            throw new TemporaryException(
                "Could not copy media to the '{$this->rehostDisk()}' disk: {$e->getMessage()}",
                reason: FailureReason::Network,
                previous: $e,
            );
        }

        if ($written === false) {
            throw new TemporaryException("Could not copy media to the '{$this->rehostDisk()}' disk.", reason: FailureReason::Network);
        }
    }

    /** A public (or temporary) absolute URL for $path on $disk. */
    protected function publicUrl(string $disk, string $path): string
    {
        $filesystem = Storage::disk($disk);
        $ttl = (int) ($this->urlTtl ?? config('social.media.url_ttl', 86400));
        $temporary = $this->temporaryUrls ?? (bool) config('social.media.temporary_urls', false);

        try {
            $url = $temporary && $filesystem instanceof FilesystemAdapter && $filesystem->providesTemporaryUrls()
                ? $filesystem->temporaryUrl($path, now()->addSeconds(max(60, $ttl)))
                : $filesystem->url($path);
        } catch (SocialPosterException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PermanentException(
                "The '{$disk}' disk cannot produce a URL for {$path}: {$e->getMessage()}",
                reason: FailureReason::Misconfigured,
                previous: $e,
            );
        }

        if (preg_match('~^https?://~i', (string) $url) !== 1) {
            throw new PermanentException(
                "The '{$disk}' disk returned a relative URL ({$url}). Set the disk's 'url' option (or APP_URL) so platforms can fetch the media.",
                reason: FailureReason::Misconfigured,
            );
        }

        return (string) $url;
    }

    protected function targetPath(string $identity, string $extension): string
    {
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?? '';

        if ($extension !== '' && ! in_array($extension, static::MEDIA_EXTENSIONS, true)) {
            $extension = 'bin';
        }

        $prefix = $this->rehostPrefix();

        return ($prefix !== '' ? $prefix.'/' : '').sha1($identity).($extension !== '' ? '.'.$extension : '');
    }

    protected function isFresh(string $target): bool
    {
        try {
            $filesystem = $this->filesystem();

            return $filesystem->exists($target) && $filesystem->lastModified($target) >= time() - static::REFRESH_AFTER;
        } catch (Throwable) {
            return false;
        }
    }

    protected function isLocalDisk(string $disk): bool
    {
        $filesystem = Storage::disk($disk);

        return $filesystem instanceof FilesystemAdapter && $filesystem->getAdapter() instanceof LocalFilesystemAdapter;
    }

    protected function filesystem(): Filesystem
    {
        return Storage::disk($this->rehostDisk());
    }
}
