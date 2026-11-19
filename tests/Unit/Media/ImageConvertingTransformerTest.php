<?php

use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\ImageRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\MediaType;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Tests\Unit\Media\Support\SpyStreamWrapper;
use SocialPoster\Transformers\ImageConvertingTransformer;
use SocialPoster\Transformers\NullMediaTransformer;
use SocialPoster\ValueObjects\Media;

function spTikTokPhotoCaps(): Capabilities
{
    return new Capabilities(
        new CaptionRules,
        new MediaRules(image: new ImageRules(extensions: ['jpg', 'jpeg', 'webp'], convert: ['png' => 'jpg'])),
    );
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/sp-mt-transform-'.bin2hex(random_bytes(4));
    config(['social.temp_dir' => $this->tempDir]);
});

afterEach(function () {
    array_map('unlink', glob($this->tempDir.'/*') ?: []);
    @rmdir($this->tempDir);
});

it('leaves media untouched by default', function () {
    $media = Media::image('/tmp/a.png');

    expect((new NullMediaTransformer)->transform($media, Platform::TikTok, spTikTokPhotoCaps()))->toBe($media);
});

it('converts a transparent PNG to a JPEG flattened onto white', function () {
    $image = imagecreatetruecolor(4, 3);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagesetpixel($image, 3, 2, (int) imagecolorallocate($image, 255, 0, 0));
    $png = sys_get_temp_dir().'/sp-mt-'.bin2hex(random_bytes(4)).'.png';
    imagepng($image, $png);

    $source = Media::image($png, 'Alt', ['width' => 4, 'height' => 3, 'size' => 999, 'tiktok' => ['x' => 1]]);
    $transformer = new ImageConvertingTransformer;
    $converted = $transformer->transform($source, Platform::TikTok, spTikTokPhotoCaps());

    expect($converted)->not->toBe($source)
        ->and($converted->type)->toBe(MediaType::Image)
        ->and($converted->extension())->toBe('jpg')
        ->and(basename($converted->source))->toStartWith('sp_')
        ->and(dirname($converted->source))->toBe($this->tempDir)
        ->and(getimagesize($converted->source)['mime'] ?? null)->toBe('image/jpeg')
        ->and($converted->altText)->toBe('Alt')
        ->and($converted->meta)->toMatchArray(['width' => 4, 'height' => 3, 'tiktok' => ['x' => 1], '_converted_from' => $png])
        ->and($converted->meta)->not->toHaveKey('size');

    $jpeg = imagecreatefromjpeg($converted->source);
    $corner = imagecolorsforindex($jpeg, imagecolorat($jpeg, 0, 0));

    expect($corner['red'])->toBeGreaterThan(240)
        ->and($corner['green'])->toBeGreaterThan(240)
        ->and($corner['blue'])->toBeGreaterThan(240);

    $transformer->cleanup();
    unlink($png);

    expect(is_file($converted->source))->toBeFalse();
})->skip(! function_exists('imagecreatefromjpeg'), 'ext-gd is required');

it('only converts what the rules ask for', function () {
    $transformer = new ImageConvertingTransformer;
    $caps = spTikTokPhotoCaps();

    $jpeg = Media::image('/tmp/a.jpg');
    $gif = Media::image('/tmp/a.gif');
    $video = Media::video('/tmp/a.png');

    expect($transformer->transform($jpeg, Platform::TikTok, $caps))->toBe($jpeg)
        ->and($transformer->transform($gif, Platform::TikTok, $caps))->toBe($gif)
        ->and($transformer->transform($video, Platform::TikTok, $caps))->toBe($video)
        ->and($transformer->canConvert('png', 'jpg'))->toBe(function_exists('imagejpeg') || class_exists(Imagick::class))
        ->and($transformer->canConvert('png', 'heic'))->toBeFalse()
        ->and($transformer->canConvert('docx', 'jpg'))->toBeFalse();
});

it('reports an unreadable image as rejected media', function () {
    $broken = sys_get_temp_dir().'/sp-mt-'.bin2hex(random_bytes(4)).'.png';
    file_put_contents($broken, 'not an image');

    try {
        expect(fn () => (new ImageConvertingTransformer)->transform(Media::image($broken), Platform::TikTok, spTikTokPhotoCaps()))
            ->toThrow(PermanentException::class);
    } finally {
        unlink($broken);
    }

    expect(glob($this->tempDir.'/sp_*') ?: [])->toBe([]);
})->skip(! function_exists('imagecreatefromstring'), 'ext-gd is required');

/** A PNG of random pixels: it compresses badly, so its JPEG is large. */
function spNoisePng(int $width, int $height): string
{
    mt_srand(42);
    $image = imagecreatetruecolor($width, $height);

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
        }
    }

    $path = sys_get_temp_dir().'/sp-mt-noise-'.bin2hex(random_bytes(4)).'.png';
    imagepng($image, $path, 1);

    return $path;
}

/** A tiny 1-bit PNG that declares $width x $height pixels (a "decompression bomb" when large). */
function spDeclaredPng(int $width, int $height): string
{
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $deflate = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
    $row = "\0".str_repeat("\0", intdiv($width + 7, 8));
    $idat = '';

    for ($y = 0; $y < $height; $y++) {
        $idat .= deflate_add($deflate, $row, ZLIB_NO_FLUSH);
    }

    $idat .= deflate_add($deflate, '', ZLIB_FINISH);
    $path = sys_get_temp_dir().'/sp-mt-bomb-'.bin2hex(random_bytes(4)).'.png';
    file_put_contents($path, "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', $width, $height, 1, 0, 0, 0, 0))
        .$chunk('IDAT', $idat)
        .$chunk('IEND', ''));

    return $path;
}

function spPngToJpegCaps(?int $maxSizeBytes = null, ?int $maxWidth = null, ?int $maxPixels = null): Capabilities
{
    return new Capabilities(
        new CaptionRules,
        new MediaRules(image: new ImageRules(
            extensions: ['jpg', 'jpeg'],
            maxSizeBytes: $maxSizeBytes,
            maxPixels: $maxPixels,
            maxWidth: $maxWidth,
            convert: ['png' => 'jpg'],
        )),
    );
}

it('fits the converted image within the platform maximum file size (validation skips that check for converted images)', function () {
    $png = spNoisePng(1200, 900);
    $transformer = new ImageConvertingTransformer;

    try {
        $full = (new ImageConvertingTransformer)->convert(Media::image($png), 'jpg');
        $converted = $transformer->transform(Media::image($png), Platform::Bluesky, spPngToJpegCaps(maxSizeBytes: 300000));
    } finally {
        unlink($png);
    }

    [$width, $height] = getimagesize($converted->source);

    expect(filesize($full->source))->toBeGreaterThan(900000)
        ->and(filesize($converted->source))->toBeLessThanOrEqual(300000)
        ->and(getimagesize($converted->source)['mime'])->toBe('image/jpeg')
        ->and(round($width / $height, 1))->toBe(1.3)
        ->and($converted->meta['width'] ?? null)->toBe($width)
        ->and($converted->meta['height'] ?? null)->toBe($height);

    $transformer->cleanup();
})->skip(! function_exists('imagecreatefromjpeg'), 'ext-gd is required');

it('scales the converted image down to the platform pixel limits', function () {
    $png = spNoisePng(600, 300);

    try {
        $narrow = (new ImageConvertingTransformer)->transform(Media::image($png), Platform::Bluesky, spPngToJpegCaps(maxWidth: 300));
        $small = (new ImageConvertingTransformer)->transform(Media::image($png), Platform::Bluesky, spPngToJpegCaps(maxPixels: 20000));
    } finally {
        unlink($png);
    }

    expect(getimagesize($narrow->source)[0])->toBe(300)
        ->and(getimagesize($narrow->source)[1])->toBe(150)
        ->and(getimagesize($small->source)[0] * getimagesize($small->source)[1])->toBeLessThanOrEqual(20000);
})->skip(! function_exists('imagecreatefromjpeg'), 'ext-gd is required');

it('rejects an image that cannot be made to fit, leaving no temp file', function () {
    $png = spNoisePng(300, 200);

    try {
        $e = null;

        try {
            (new ImageConvertingTransformer)->transform(Media::image($png), Platform::Bluesky, spPngToJpegCaps(maxSizeBytes: 100));
        } catch (Throwable $e) {
        }
    } finally {
        unlink($png);
    }

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->context['max_size_bytes'] ?? null)->toBe(100)
        ->and(glob($this->tempDir.'/sp_*') ?: [])->toBe([]);
})->skip(! function_exists('imagecreatefromjpeg'), 'ext-gd is required');

it('refuses to decode an image above social.media.max_convert_pixels instead of exhausting memory', function () {
    // 8000 x 8000 declared (64 MP, about 512 MB once decoded twice by GD) in a few kilobytes.
    $bomb = spDeclaredPng(8000, 8000);

    try {
        $e = null;

        try {
            (new ImageConvertingTransformer)->transform(Media::image($bomb), Platform::TikTok, spTikTokPhotoCaps());
        } catch (Throwable $e) {
        }
    } finally {
        $bytes = filesize($bomb);
        unlink($bomb);
    }

    expect($bytes)->toBeLessThan(65536)
        ->and($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain('max_convert_pixels')
        ->and(glob($this->tempDir.'/sp_*') ?: [])->toBe([]);
})->skip(! function_exists('imagecreatefromstring'), 'ext-gd is required');

it('takes the pixel cap from the constructor or social.media.max_convert_pixels', function () {
    $png = spNoisePng(200, 100);

    try {
        config(['social.media.max_convert_pixels' => 10000]);
        $fromConfig = null;

        try {
            (new ImageConvertingTransformer)->transform(Media::image($png), Platform::TikTok, spTikTokPhotoCaps());
        } catch (PermanentException $fromConfig) {
        }

        $allowed = (new ImageConvertingTransformer(maxSourcePixels: 20000))->transform(Media::image($png), Platform::TikTok, spTikTokPhotoCaps());
    } finally {
        unlink($png);
    }

    expect($fromConfig?->reason)->toBe(FailureReason::MediaRejected)
        ->and($allowed->extension())->toBe('jpg');
})->skip(! function_exists('imagecreatefromjpeg'), 'ext-gd is required');

it('refuses stream-wrapper sources without touching them', function () {
    SpyStreamWrapper::register();

    try {
        $e = null;

        try {
            (new ImageConvertingTransformer)->transform(Media::image('sp-spy://10.0.0.5/internal/a.png'), Platform::TikTok, spTikTokPhotoCaps());
        } catch (Throwable $e) {
        }

        expect($e)->toBeInstanceOf(PermanentException::class)
            ->and($e->reason)->toBe(FailureReason::MediaRejected)
            ->and(SpyStreamWrapper::$calls)->toBe([]);
    } finally {
        SpyStreamWrapper::unregister();
    }
});
