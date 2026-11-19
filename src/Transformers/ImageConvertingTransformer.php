<?php

namespace SocialPoster\Transformers;

use GdImage;
use Imagick;
use ImagickPixel;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\ImageRules;
use SocialPoster\Contracts\MediaGateway;
use SocialPoster\Contracts\MediaTransformer;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\MediaType;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Gateways\LocalMediaGateway;
use SocialPoster\ValueObjects\Media;
use Throwable;

/**
 * Converts images whose extension the platform refuses but ImageRules::$convert
 * maps to an accepted one (TikTok: png → jpg, Bluesky: heic → jpg). Uses GD
 * when it can read and write both formats within memory_limit, else Imagick.
 *
 * - JPEG output is written at quality 90 with transparency flattened onto
 *   white (a transparent PNG would otherwise turn black).
 * - EXIF orientation is applied, so the pixels are upright once the EXIF
 *   block is gone.
 * - The output fits the target ImageRules: scaled down to maxWidth, maxHeight
 *   and maxPixels, then, while it is above maxSizeBytes, re-encoded at quality
 *   80 and 70 and at smaller sizes (longest side 2048, 1600, 1200, 1024, 800,
 *   never below minWidth/minHeight). Validation cannot check the size of a
 *   file that does not exist yet, so this is what keeps a 12 MP phone HEIC
 *   under Bluesky's 1 MB; when nothing fits, MediaRejected says so.
 * - Images above social.media.max_convert_pixels (50 MP; the constructor's
 *   $maxSourcePixels wins) are refused before they are decoded: a few-kilobyte
 *   PNG can declare 30000x30000 pixels, and decoding it kills the worker with
 *   an uncatchable out-of-memory error.
 * - Sources on other schemes than http(s):// and file:// are refused untouched.
 * - The output is a new sp_* file in the temp dir whose extension matches the
 *   new bytes: platforms that sniff the format from the URL (TikTok) see .jpg.
 *
 * The returned Media is a local file: on pull-only platforms bind a gateway
 * that re-hosts local files (FilesystemMediaGateway). Converted files are
 * removed by cleanup() or, as a backstop, by social-poster:prune-temp.
 */
class ImageConvertingTransformer implements MediaTransformer
{
    /** Extension aliases → canonical format names. */
    private const FORMATS = [
        'jpg' => 'jpeg', 'jpeg' => 'jpeg', 'png' => 'png', 'webp' => 'webp', 'gif' => 'gif',
        'bmp' => 'bmp', 'avif' => 'avif', 'heic' => 'heic', 'heif' => 'heic', 'tif' => 'tiff', 'tiff' => 'tiff',
    ];

    /** Longest sides tried, in order, when the output is above maxSizeBytes. */
    protected const FIT_SIDES = [2048, 1600, 1200, 1024, 800];

    /** Qualities tried at each size when the output is above maxSizeBytes (capped by $quality). */
    protected const FIT_QUALITIES = [80, 70];

    /** social.media.max_convert_pixels default: about 50 MP (48 MP phone photos still convert). */
    protected const MAX_SOURCE_PIXELS = 50000000;

    /** @var string[] */
    private array $files = [];

    public function __construct(
        protected ?MediaGateway $gateway = null,
        protected int $quality = 90,
        protected ?int $maxSourcePixels = null,
    ) {}

    public function transform(Media $media, Platform $platform, Capabilities $caps): Media
    {
        $rules = $caps->media->image;

        if ($media->type !== MediaType::Image || $rules === null) {
            return $media;
        }

        $target = $rules->conversionTarget($media->extension());

        return $target === null ? $media : $this->convert($media, $target, $rules);
    }

    /** Whether this runtime (GD or Imagick) can convert $from images to $to. */
    public function canConvert(string $from, string $to): bool
    {
        $from = self::FORMATS[strtolower($from)] ?? null;
        $to = self::FORMATS[strtolower($to)] ?? null;

        if ($from === null || ! in_array($to, ['jpeg', 'png', 'webp'], true)) {
            return false;
        }

        return $this->gdCan($from, $to) || $this->imagickCan($from, $to);
    }

    /**
     * Convert $media to the $to format and return the converted Media. With
     * $rules, the output also fits their pixel and file-size limits.
     *
     * @throws PermanentException MediaRejected when the image cannot be read, is too
     *                            large to decode safely, or cannot be made to fit;
     *                            Misconfigured when neither GD nor Imagick can convert it
     */
    public function convert(Media $media, string $to, ?ImageRules $rules = null): Media
    {
        if ($media->hasForeignScheme()) {
            throw LocalMediaGateway::foreignScheme($media);
        }

        $from = self::FORMATS[$media->extension()] ?? $media->extension();
        $format = self::FORMATS[strtolower($to)] ?? strtolower($to);
        $extension = $format === 'jpeg' ? 'jpg' : $format;

        if (! $this->canConvert($media->extension() ?: $from, $format)) {
            throw new PermanentException(
                "Converting .{$media->extension()} images to .{$extension} needs ext-gd or ext-imagick with support for both formats.",
                reason: FailureReason::Misconfigured,
            );
        }

        $source = $this->localPath($media);
        [$width, $height] = $this->sourceDimensions($source);
        $this->assertDecodable($width, $height);
        $out = LocalMediaGateway::temporaryDirectory().'/sp_'.bin2hex(random_bytes(8)).'.'.$extension;

        try {
            if ($this->gdCan($from, $format) && $this->gdFits($width, $height, (int) @filesize($source))) {
                $this->convertWithGd($source, $out, $format, $rules);
            } elseif ($this->imagickCan($from, $format)) {
                $this->convertWithImagick($source, $out, $format, $rules);
            } else {
                throw new PermanentException(
                    "The {$width}x{$height} image is too large to convert with GD within memory_limit. Raise memory_limit or install ext-imagick.",
                    reason: FailureReason::MediaRejected,
                    context: ['width' => $width, 'height' => $height],
                );
            }
        } catch (Throwable $e) {
            @unlink($out);

            throw $e instanceof PermanentException ? $e : new PermanentException(
                "The image could not be converted to .{$extension}: {$e->getMessage()}",
                reason: FailureReason::MediaRejected,
                previous: $e,
            );
        }

        $this->files[] = $out;
        $size = @getimagesize($out);
        $dimensions = is_array($size) && $size[0] > 0 ? ['width' => (int) $size[0], 'height' => (int) $size[1]] : [];

        return new Media($out, MediaType::Image, $media->thumbnail, $media->altText, $dimensions + $this->metaFor($media));
    }

    /** Delete the converted files created by this instance. */
    public function cleanup(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->files = [];
    }

    protected function localPath(Media $media): string
    {
        if (! $media->isRemote() && ! $media->isOnDisk() && is_file($media->source)) {
            return $media->source;
        }

        $gateway = $this->gateway;

        if ($gateway === null) {
            $gateway = app()->bound(MediaGateway::class) ? app(MediaGateway::class) : new LocalMediaGateway;
        }

        return $gateway->path($media);
    }

    /**
     * The original metadata minus what conversion invalidates (size, MIME,
     * dimensions, disk location); platform extras carry over. convert() adds
     * the output's real width and height.
     *
     * @return array<string, mixed>
     */
    protected function metaFor(Media $media): array
    {
        $meta = $media->meta;

        foreach (['size', 'sizeBytes', 'size_bytes', 'mime', 'mimeType', 'mime_type', 'width', 'height', Media::META_DISK, Media::META_DISK_PATH] as $key) {
            unset($meta[$key]);
        }

        return ['_converted_from' => $media->source] + $meta;
    }

    /**
     * Width and height from the file header, without decoding the pixels
     * (getimagesize(), else Imagick::pingImage() for HEIC/AVIF); nulls when
     * neither can read it.
     *
     * @return array{0: int|null, 1: int|null}
     */
    protected function sourceDimensions(string $source): array
    {
        $info = @getimagesize($source);

        if (is_array($info) && (int) $info[0] > 0 && (int) $info[1] > 0) {
            return [(int) $info[0], (int) $info[1]];
        }

        if (class_exists(Imagick::class)) {
            try {
                $probe = new Imagick;
                $probe->pingImage($source);
                $dimensions = [(int) $probe->getImageWidth(), (int) $probe->getImageHeight()];
                $probe->clear();

                return $dimensions[0] > 0 && $dimensions[1] > 0 ? $dimensions : [null, null];
            } catch (Throwable) {
                // Unreadable header: the decoder reports it.
            }
        }

        return [null, null];
    }

    /** @throws PermanentException MediaRejected above the source pixel cap */
    protected function assertDecodable(?int $width, ?int $height): void
    {
        $cap = $this->maxSourcePixels ?? config('social.media.max_convert_pixels', static::MAX_SOURCE_PIXELS);
        $cap = is_numeric($cap) ? (int) $cap : static::MAX_SOURCE_PIXELS;

        if ($cap <= 0 || $width === null || $height === null || $width * $height <= $cap) {
            return;
        }

        throw new PermanentException(
            "The image is {$width}x{$height} (".($width * $height)." pixels); converting images above {$cap} pixels is refused (social.media.max_convert_pixels).",
            reason: FailureReason::MediaRejected,
            context: ['width' => $width, 'height' => $height, 'max_convert_pixels' => $cap],
        );
    }

    /** Whether GD can hold the decoded image, its canvas and a rotated copy within memory_limit. */
    protected function gdFits(?int $width, ?int $height, int $fileSize): bool
    {
        $limit = $this->memoryLimit();

        if ($limit === null || $width === null || $height === null) {
            return true;
        }

        return memory_get_usage() + $width * $height * 4 * 3 + $fileSize < $limit;
    }

    protected function convertWithGd(string $source, string $out, string $format, ?ImageRules $rules = null): void
    {
        $bytes = @file_get_contents($source);
        $image = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
        unset($bytes);

        if (! $image instanceof GdImage) {
            throw new PermanentException('The image cannot be decoded.', reason: FailureReason::MediaRejected);
        }

        $image = $this->orientGd($image, $source);
        $canvas = null;
        $canvasSize = null;

        $this->encodeFitting(imagesx($image), imagesy($image), $format, $rules, $out, function (int $width, int $height, int $quality) use ($image, $format, $out, &$canvas, &$canvasSize): void {
            if ($canvasSize !== [$width, $height]) {
                $canvas = null; // release the previous size before allocating the next
                $canvas = $this->gdCanvas($image, $width, $height, $format);
                $canvasSize = [$width, $height];
            }

            $written = match ($format) {
                'jpeg' => imagejpeg($canvas, $out, $quality),
                'png' => imagepng($canvas, $out),
                'webp' => imagewebp($canvas, $out, $quality),
                default => false,
            };

            if (! $written) {
                throw new PermanentException("GD could not write the {$format} image.", reason: FailureReason::MediaRejected);
            }
        });
    }

    /** $image drawn at $width x $height, on white for JPEG, keeping transparency otherwise. */
    protected function gdCanvas(GdImage $image, int $width, int $height, string $format): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);

        if ($format === 'jpeg') {
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
            imagealphablending($canvas, true);
        } else {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        }

        if ($width === imagesx($image) && $height === imagesy($image)) {
            imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        } else {
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
        }

        return $canvas;
    }

    protected function orientGd(GdImage $image, string $source): GdImage
    {
        if (! function_exists('exif_read_data') || @exif_imagetype($source) !== IMAGETYPE_JPEG) {
            return $image;
        }

        $exif = @exif_read_data($source);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    protected function convertWithImagick(string $source, string $out, string $format, ?ImageRules $rules = null): void
    {
        $image = new Imagick($source);
        $image->setIteratorIndex(0);

        try {
            $image->autoOrient();
        } catch (Throwable) {
            // imagick < 3.6 has no autoOrient(); the image keeps its stored orientation.
        }

        if ($format === 'jpeg') {
            $image->setImageBackgroundColor(new ImagickPixel('white'));
            $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        }

        $this->encodeFitting((int) $image->getImageWidth(), (int) $image->getImageHeight(), $format, $rules, $out, function (int $width, int $height, int $quality) use ($image, $format, $out): void {
            $copy = clone $image;

            if ($width !== (int) $copy->getImageWidth() || $height !== (int) $copy->getImageHeight()) {
                $copy->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
            }

            $copy->setImageFormat($format);
            $copy->setImageCompressionQuality($quality);
            $copy->stripImage();
            $written = $copy->writeImage($out);
            $copy->clear();

            if (! $written) {
                throw new PermanentException("Imagick could not write the {$format} image.", reason: FailureReason::MediaRejected);
            }
        });

        $image->clear();
    }

    /**
     * Encode with $encode(width, height, quality) until the file at $out fits
     * $rules->maxSizeBytes (see attempts()).
     *
     * @param  callable(int, int, int): void  $encode
     *
     * @throws PermanentException MediaRejected when no attempt fits
     */
    protected function encodeFitting(int $width, int $height, string $format, ?ImageRules $rules, string $out, callable $encode): void
    {
        $max = $rules?->maxSizeBytes;
        $size = 0;
        $last = [$width, $height];

        foreach ($this->attempts($width, $height, $format, $rules) as [$w, $h, $quality]) {
            $encode($w, $h, $quality);
            clearstatcache(true, $out);
            $size = (int) @filesize($out);
            $last = [$w, $h];

            if ($max === null || $max <= 0 || $size <= $max) {
                return;
            }
        }

        throw new PermanentException(
            "The converted image is still {$size} bytes at {$last[0]}x{$last[1]}; the platform accepts at most {$max} bytes.",
            reason: FailureReason::MediaRejected,
            context: ['size_bytes' => $size, 'max_size_bytes' => $max],
        );
    }

    /**
     * [width, height, quality] encodings to try, best first: the image scaled
     * into maxWidth/maxHeight/maxPixels at $quality; then, only when a
     * maxSizeBytes applies, lower qualities and smaller sizes.
     *
     * @return list<array{0: int, 1: int, 2: int}>
     */
    protected function attempts(int $width, int $height, string $format, ?ImageRules $rules): array
    {
        [$width, $height] = $this->scaledInto($width, $height, $rules);

        if ($rules?->maxSizeBytes === null) {
            return [[$width, $height, $this->quality]];
        }

        $qualities = $format === 'png'
            ? [$this->quality]
            : array_values(array_unique(array_merge([$this->quality], array_map(fn (int $q) => min($q, $this->quality), static::FIT_QUALITIES))));
        $sizes = [[$width, $height]];

        foreach (static::FIT_SIDES as $side) {
            $longest = max($width, $height);

            if ($side >= $longest) {
                continue;
            }

            $w = max(1, (int) round($width * $side / $longest));
            $h = max(1, (int) round($height * $side / $longest));

            if (($rules->minWidth && $w < $rules->minWidth) || ($rules->minHeight && $h < $rules->minHeight)) {
                break;
            }

            $sizes[] = [$w, $h];
        }

        $attempts = [];

        foreach ($sizes as [$w, $h]) {
            foreach ($qualities as $quality) {
                $attempts[] = [$w, $h, $quality];
            }
        }

        return $attempts;
    }

    /**
     * $width x $height scaled down (aspect ratio kept) into the rules'
     * maxWidth, maxHeight and maxPixels.
     *
     * @return array{0: int, 1: int}
     */
    protected function scaledInto(int $width, int $height, ?ImageRules $rules): array
    {
        $scale = 1.0;

        if ($rules?->maxWidth && $width > $rules->maxWidth) {
            $scale = min($scale, $rules->maxWidth / $width);
        }

        if ($rules?->maxHeight && $height > $rules->maxHeight) {
            $scale = min($scale, $rules->maxHeight / $height);
        }

        if ($rules?->maxPixels && $width * $height > $rules->maxPixels) {
            $scale = min($scale, sqrt($rules->maxPixels / ($width * $height)));
        }

        if ($scale >= 1.0) {
            return [$width, $height];
        }

        return [max(1, (int) floor($width * $scale)), max(1, (int) floor($height * $scale))];
    }

    /** memory_limit in bytes, or null when unlimited or unreadable. */
    private function memoryLimit(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1' || preg_match('/^(\d+)\s*([kmg]?)$/i', $value, $m) !== 1) {
            return null;
        }

        $bytes = (int) $m[1];

        return match (strtolower($m[2])) {
            'g' => $bytes * 1073741824,
            'm' => $bytes * 1048576,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }

    private function gdCan(string $from, string $to): bool
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagetypes')) {
            return false;
        }

        $read = match ($from) {
            'jpeg' => IMG_JPG,
            'png' => IMG_PNG,
            'gif' => IMG_GIF,
            'webp' => IMG_WEBP,
            'bmp' => IMG_BMP,
            'avif' => defined('IMG_AVIF') ? (int) constant('IMG_AVIF') : 0,
            default => 0,
        };

        $write = match ($to) {
            'jpeg' => function_exists('imagejpeg'),
            'png' => function_exists('imagepng'),
            'webp' => function_exists('imagewebp'),
            default => false,
        };

        return $read !== 0 && (imagetypes() & $read) !== 0 && $write;
    }

    private function imagickCan(string $from, string $to): bool
    {
        if (! class_exists(Imagick::class)) {
            return false;
        }

        try {
            $formats = array_map('strtolower', Imagick::queryFormats());
        } catch (Throwable) {
            return false;
        }

        return in_array($from, $formats, true) && in_array($to, $formats, true);
    }
}
