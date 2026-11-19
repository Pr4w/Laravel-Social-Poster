<?php

namespace SocialPoster\ValueObjects;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SocialPoster\Enums\MediaType;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Gateways\LocalMediaGateway;
use Throwable;

final class Media
{
    /** Video extensions recognised by guess() (the union of every driver's VideoRules). */
    private const VIDEO_EXTENSIONS = ['mp4', 'mov', 'm4v', 'webm', 'avi', 'mkv', 'wmv', 'flv', 'mpg', 'mpeg', '3gp'];

    private const DOCUMENT_EXTENSIONS = ['pdf', 'doc', 'docx', 'ppt', 'pptx'];

    /** Reserved $meta keys written by fromDisk(); gateways read them through disk()/diskPath(). */
    public const META_DISK = '_disk';

    public const META_DISK_PATH = '_disk_path';

    /**
     * @param  array<string, mixed>  $meta  Caller-supplied known metadata, used when the inspector
     *                                      cannot reach the file: width, height, duration, size,
     *                                      frameRate, mime, codec, bitrate, rotation (see
     *                                      MediaMetadata::fromArray). Drivers may also read
     *                                      platform-keyed extras (e.g. meta['instagram']).
     *                                      Keys starting with "_" are reserved.
     */
    public function __construct(
        public readonly string $source,
        public readonly MediaType $type,
        public readonly ?string $thumbnail = null,
        public readonly ?string $altText = null,
        public readonly array $meta = [],
    ) {}

    /** @param  array<string, mixed>  $meta */
    public static function image(string $source, ?string $altText = null, array $meta = []): self
    {
        return new self($source, MediaType::Image, altText: $altText, meta: $meta);
    }

    /** @param  array<string, mixed>  $meta */
    public static function video(string $source, ?string $thumbnail = null, ?string $altText = null, array $meta = []): self
    {
        return new self($source, MediaType::Video, thumbnail: $thumbnail, altText: $altText, meta: $meta);
    }

    /** @param  array<string, mixed>  $meta */
    public static function document(string $source, array $meta = []): self
    {
        return new self($source, MediaType::Document, meta: $meta);
    }

    /** @param  array<string, mixed>  $meta */
    public static function guess(string $source, array $meta = []): self
    {
        return match (self::typeFor($source)) {
            MediaType::Video => self::video($source, meta: $meta),
            MediaType::Document => self::document($source, $meta),
            MediaType::Image => self::image($source, meta: $meta),
        };
    }

    /**
     * Media stored on a Laravel filesystem disk.
     *
     * On a local disk the source is the absolute file path, so every gateway,
     * inspector and driver handles it like any local file. On a cloud disk (S3,
     * R2, ...) the source is the disk's url() for the file; gateways that know
     * about disks (LocalMediaGateway, FilesystemMediaGateway) stream the bytes
     * from the disk and can mint a temporary URL instead of relying on it.
     * The disk and path are kept in $meta under the reserved "_disk" keys.
     * The path is normalised ("a/./b/../c" → "a/c"), and one that climbs out
     * of the disk root ("../") is refused: on Laravel 11 and 12 a local disk's
     * path() does not normalise it, so it would name a file outside the disk.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws PermanentException MediaRejected when $path leaves the disk root
     */
    public static function fromDisk(string $disk, string $path, ?MediaType $type = null, array $meta = [], ?string $altText = null): self
    {
        $path = LocalMediaGateway::normalizeDiskPath($disk, $path);
        $filesystem = Storage::disk($disk);
        $type ??= self::typeFor($path);

        if ($filesystem instanceof FilesystemAdapter && $filesystem->getAdapter() instanceof LocalFilesystemAdapter) {
            $source = $filesystem->path($path);
        } else {
            try {
                $source = (string) $filesystem->url($path);
            } catch (Throwable) {
                $source = $path; // the disk cannot build URLs; gateways stream it from the disk
            }
        }

        return new self(
            $source,
            $type,
            altText: $altText,
            meta: [self::META_DISK => $disk, self::META_DISK_PATH => $path] + $meta,
        );
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

    /**
     * True for a source on a URL scheme other than http(s):// and file://
     * (ftp://, phar://, php://, a registered s3:// stream wrapper...). Such
     * sources are neither remote URLs (the SSRF guard only sees http(s)) nor
     * local files: PHP's stream wrappers would open network connections or
     * archives on the server's behalf. Gateways, the inspector and the
     * transformer refuse them before touching them.
     */
    public function hasForeignScheme(): bool
    {
        return preg_match('~^([a-z][a-z0-9+.-]*)://~i', $this->source, $match) === 1
            && ! in_array(strtolower($match[1]), ['http', 'https', 'file'], true);
    }

    /** The filesystem disk this media was created from (Media::fromDisk), or null. */
    public function disk(): ?string
    {
        $disk = $this->meta[self::META_DISK] ?? null;

        return is_string($disk) && $disk !== '' ? $disk : null;
    }

    /** The path on disk() (Media::fromDisk), or null. */
    public function diskPath(): ?string
    {
        $path = $this->meta[self::META_DISK_PATH] ?? null;

        return $this->disk() !== null && is_string($path) && $path !== '' ? $path : null;
    }

    public function isOnDisk(): bool
    {
        return $this->diskPath() !== null;
    }

    /** The metadata the caller supplied in $meta (fields left null when not given). */
    public function knownMetadata(): MediaMetadata
    {
        return MediaMetadata::fromArray($this->meta);
    }

    private static function typeFor(string $source): MediaType
    {
        $ext = self::extensionOf($source);

        if (in_array($ext, self::VIDEO_EXTENSIONS, true)) {
            return MediaType::Video;
        }

        if (in_array($ext, self::DOCUMENT_EXTENSIONS, true)) {
            return MediaType::Document;
        }

        return MediaType::Image;
    }

    private static function extensionOf(string $source): string
    {
        $path = parse_url($source, PHP_URL_PATH) ?: $source;

        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }
}
