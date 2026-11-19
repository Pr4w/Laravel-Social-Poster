<?php

use GuzzleHttp\Psr7\Utils as GuzzleUtils;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SocialPoster\Inspectors\FFProbeInspector;
use SocialPoster\Support\PublicUrl;
use SocialPoster\Tests\Unit\Media\Support\SpyStreamWrapper;
use SocialPoster\ValueObjects\Media;

/** FFProbeInspector with ffprobe replaced by canned output (no binary needed). */
function spProbeInspector(?string $json = null, ?Throwable $throw = null): FFProbeInspector
{
    return new class($json, $throw) extends FFProbeInspector
    {
        /** @var array<int, string[]> */
        public array $commands = [];

        public function __construct(private ?string $json, private ?Throwable $throw)
        {
            parent::__construct();
        }

        protected function runProbe(array $command): ?string
        {
            $this->commands[] = $command;

            if ($this->throw !== null) {
                throw $this->throw;
            }

            return $this->json;
        }

        protected function ffprobeAvailable(): bool
        {
            return true;
        }

        public function command(Media $media): array
        {
            return $this->probeCommand($media);
        }
    };
}

/** @param  array<string, mixed>  $stream */
function spProbeJson(array $stream, array $format = []): string
{
    return (string) json_encode([
        'streams' => [['codec_type' => 'audio', 'codec_name' => 'aac'], ['codec_type' => 'video'] + $stream],
        'format' => $format + ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2', 'duration' => '12.480000', 'size' => '2048', 'bit_rate' => '1312', 'tags' => ['major_brand' => 'isom']],
    ]);
}

function spProbeTempFile(string $extension, string $contents = 'video-bytes'): string
{
    $path = sys_get_temp_dir().'/sp-mt-probe-'.bin2hex(random_bytes(4)).'.'.$extension;
    file_put_contents($path, $contents);

    return $path;
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/sp-mt-inspector-'.bin2hex(random_bytes(4));
    config(['social.temp_dir' => $this->tempDir]);
});

afterEach(function () {
    PublicUrl::resolveUsing(null);
    array_map('unlink', glob($this->tempDir.'/*') ?: []);
    @rmdir($this->tempDir);
});

it('whitelists protocols: file for local paths, https/tls/tcp plus safe demuxers for URLs', function () {
    $inspector = spProbeInspector();
    $local = $inspector->command(Media::video('/tmp/clip.mp4'));
    $remote = $inspector->command(Media::video('https://cdn.example.com/clip.mp4'));
    $http = $inspector->command(Media::video('http://cdn.example.com/clip.mp4'));

    expect($local)->toContain('-protocol_whitelist')
        ->and($local[array_search('-protocol_whitelist', $local, true) + 1])->toBe('file')
        ->and(end($local))->toBe('/tmp/clip.mp4')
        ->and($remote[array_search('-protocol_whitelist', $remote, true) + 1])->toBe('https,tls,tcp')
        ->and($remote[array_search('-format_whitelist', $remote, true) + 1])->not->toContain('hls')
        ->and($remote[array_search('-format_whitelist', $remote, true) + 1])->not->toContain('concat')
        ->and($remote)->toContain('-rw_timeout')
        ->and($http[array_search('-protocol_whitelist', $http, true) + 1])->toBe('http,https,tls,tcp');
});

it('reads display dimensions from the rotation side data and the rotate tag', function (array $stream, int $width, int $height, ?int $rotation) {
    $path = spProbeTempFile('mp4');
    $meta = spProbeInspector(spProbeJson(['width' => 1920, 'height' => 1080] + $stream))->inspect(Media::video($path));
    unlink($path);

    expect($meta->width)->toBe($width)
        ->and($meta->height)->toBe($height)
        ->and($meta->rotation)->toBe($rotation)
        ->and($meta->aspectRatio())->toBe($width / $height);
})->with([
    'display matrix -90 (portrait phone video)' => [['side_data_list' => [['side_data_type' => 'Display Matrix', 'rotation' => -90]]], 1080, 1920, 90],
    'display matrix 90' => [['side_data_list' => [['rotation' => 90]]], 1080, 1920, 270],
    'legacy rotate tag' => [['tags' => ['rotate' => '90']], 1080, 1920, 90],
    'upside down keeps orientation' => [['tags' => ['rotate' => '180']], 1920, 1080, 180],
    'no rotation' => [[], 1920, 1080, null],
]);

it('parses frame rate, duration, codec, bitrate, size and MIME', function () {
    $path = spProbeTempFile('mp4');
    $meta = spProbeInspector(spProbeJson(['width' => 1080, 'height' => 1920, 'codec_name' => 'h264', 'avg_frame_rate' => '30000/1001']))
        ->inspect(Media::video($path));
    unlink($path);

    expect($meta->frameRate)->toBe(29.97)
        ->and($meta->durationSeconds)->toBe(12.48)
        ->and($meta->codec)->toBe('h264')
        ->and($meta->bitrate)->toBe(1312)
        ->and($meta->sizeBytes)->toBe(2048)
        ->and($meta->mimeType)->toBe('video/mp4');
});

it('falls back to r_frame_rate and recognises containers', function (array $stream, array $format, ?float $fps, ?string $mime) {
    $path = spProbeTempFile('mp4');
    $meta = spProbeInspector(spProbeJson(['width' => 10, 'height' => 10] + $stream, $format))->inspect(Media::video($path));
    unlink($path);

    expect($meta->frameRate)->toBe($fps)
        ->and($meta->mimeType)->toBe($mime);
})->with([
    'avg 0/0 falls back to r_frame_rate' => [['avg_frame_rate' => '0/0', 'r_frame_rate' => '25/1'], [], 25.0, 'video/mp4'],
    'QuickTime brand' => [[], ['tags' => ['major_brand' => 'qt  ']], null, 'video/quicktime'],
    'webm' => [['codec_name' => 'vp9'], ['format_name' => 'matroska,webm'], null, 'video/webm'],
    'avi' => [[], ['format_name' => 'avi'], null, 'video/x-msvideo'],
]);

it('never throws: a failing or timed-out ffprobe yields size-only metadata', function () {
    $path = spProbeTempFile('mp4', str_repeat('x', 321));

    $failed = spProbeInspector(null)->inspect(Media::video($path));
    $thrown = spProbeInspector(null, new RuntimeException('boom'))->inspect(Media::video($path));
    unlink($path);

    expect($failed->sizeBytes)->toBe(321)
        ->and($failed->width)->toBeNull()
        ->and($thrown->sizeBytes)->toBe(321)
        ->and($thrown->width)->toBeNull();
});

it('applies the inspector timeout to ffprobe', function () {
    $script = spProbeTempFile('sh', "#!/bin/sh\nsleep 5\n");
    chmod($script, 0755);
    $video = spProbeTempFile('mp4', 'abc');
    config(['social.ffprobe_path' => $script, 'social.inspector_timeout' => 1]);

    $started = microtime(true);
    $meta = (new class extends FFProbeInspector
    {
        protected function ffprobeAvailable(): bool
        {
            return true;
        }
    })->inspect(Media::video($video));
    $elapsed = microtime(true) - $started;

    unlink($script);
    unlink($video);

    expect($elapsed)->toBeLessThan(4.0)
        ->and($meta->sizeBytes)->toBe(3)
        ->and($meta->durationSeconds)->toBeNull();
})->skip(PHP_OS_FAMILY === 'Windows', 'needs a POSIX shell');

it('takes the binary from social.ffprobe_path and checks availability once per process', function () {
    $counter = sys_get_temp_dir().'/sp-mt-probe-count-'.bin2hex(random_bytes(4));
    $script = spProbeTempFile('sh', "#!/bin/sh\necho x >> '{$counter}'\nexit 0\n");
    chmod($script, 0755);
    $video = spProbeTempFile('mp4');
    config(['social.ffprobe_path' => $script]);

    $inspector = new FFProbeInspector;

    expect($inspector->supports(Media::video($video)))->toBeTrue()
        ->and($inspector->supports(Media::video($video)))->toBeTrue()
        ->and(count(file($counter) ?: []))->toBe(1);

    config(['social.ffprobe_path' => '/nonexistent/ffprobe']);
    expect((new FFProbeInspector)->supports(Media::video($video)))->toBeFalse();

    // An explicit constructor argument still wins over the config.
    expect((new FFProbeInspector($script))->supports(Media::video($video)))->toBeTrue();

    @unlink($counter);
    unlink($script);
    unlink($video);
})->skip(PHP_OS_FAMILY === 'Windows', 'needs a POSIX shell');

it('measures remote images with a capped, pinned Range request instead of getimagesize()', function () {
    config(['social.media.block_private_networks' => true]);
    PublicUrl::resolveUsing(fn () => ['93.184.216.34']);

    $image = imagecreatetruecolor(3, 2);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    $captured = [];

    Http::fake(function (Request $request, array $options) use ($png, &$captured) {
        $captured = $options;

        return Http::response($png, 206, ['Content-Range' => 'bytes 0-'.(strlen($png) - 1).'/5000']);
    });

    $meta = (new FFProbeInspector)->inspect(Media::image('https://cdn.example.com/pic.png?sig=1'));

    expect($meta->width)->toBe(3)
        ->and($meta->height)->toBe(2)
        ->and($meta->mimeType)->toBe('image/png')
        ->and($meta->sizeBytes)->toBe(5000)
        ->and($captured['curl'][CURLOPT_RESOLVE])->toContain('cdn.example.com:443:93.184.216.34')
        ->and($captured['allow_redirects']['max'])->toBe(3)
        ->and(glob($this->tempDir.'/sp_*') ?: [])->toBe([]);

    Http::assertSent(fn (Request $request) => $request->header('Range') === ['bytes=0-2097151']);
})->skip(! function_exists('imagepng'), 'ext-gd is required');

it('never fetches a private remote URL during inspection', function () {
    config(['social.media.block_private_networks' => true]);
    Http::fake();

    $meta = (new FFProbeInspector)->inspect(Media::image('https://169.254.169.254/latest/meta-data/x.png'));

    expect($meta->width)->toBeNull()
        ->and($meta->sizeBytes)->toBeNull();

    Http::assertNothingSent();
});

it('can skip remote inspection entirely', function () {
    config(['social.inspect_remote' => false]);

    expect((new FFProbeInspector)->supports(Media::image('https://cdn.example.com/a.png')))->toBeFalse();
});

it('reports display dimensions for EXIF-rotated JPEGs', function () {
    $image = imagecreatetruecolor(4, 2);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();

    // APP1 Exif segment, big-endian TIFF, one IFD entry: Orientation (0x0112) = 6.
    $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00\x00\x00\x00\x00";
    $app1 = "Exif\x00\x00".$tiff;
    $jpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    $path = spProbeTempFile('jpg', $jpeg);

    $meta = (new FFProbeInspector)->inspect(Media::image($path));
    unlink($path);

    expect($meta->width)->toBe(2)
        ->and($meta->height)->toBe(4)
        ->and($meta->rotation)->toBe(90)
        ->and($meta->sizeBytes)->toBe(strlen($jpeg));
})->skip(! function_exists('imagejpeg') || ! function_exists('exif_read_data'), 'ext-gd and ext-exif are required');

it('never hands a remote video URL to ffprobe while private networks are blocked (ffprobe follows redirects itself)', function () {
    config(['social.media.block_private_networks' => true]);
    PublicUrl::resolveUsing(fn () => ['93.184.216.34']);
    Http::fake(fn (Request $request) => Http::response('VIDEO-HEAD', 206, ['Content-Range' => 'bytes 0-9/5000000']));

    $inspector = spProbeInspector(spProbeJson(['width' => 1920, 'height' => 1080, 'codec_name' => 'h264', 'avg_frame_rate' => '30/1', 'tags' => ['rotate' => '90']], ['size' => '10', 'bit_rate' => '8']));
    $meta = $inspector->inspect(Media::video('https://cdn.example.com/v.mp4?sig=1'));
    $arguments = array_merge(...$inspector->commands);
    $command = $inspector->commands[0] ?? [];

    expect($inspector->commands)->toHaveCount(1)
        ->and(array_filter($arguments, fn (string $argument) => preg_match('~^[a-z][a-z0-9+.-]*:~i', $argument) === 1))->toBe([])
        ->and($command[array_search('-protocol_whitelist', $command, true) + 1])->toBe('file')
        ->and($command)->toContain('-format_whitelist')
        ->and($meta->width)->toBe(1080)
        ->and($meta->height)->toBe(1920)
        ->and($meta->durationSeconds)->toBe(12.48)
        ->and($meta->codec)->toBe('h264')
        ->and($meta->sizeBytes)->toBe(5000000)
        ->and($meta->bitrate)->toBe((int) round(5000000 * 8 / 12.48))
        ->and(glob($this->tempDir.'/sp_*') ?: [])->toBe([]);

    Http::assertSent(fn (Request $request) => str_starts_with($request->header('Range')[0] ?? '', 'bytes=0-'));
});

it('adds the end of a remote video when its index (moov) is stored there', function () {
    config(['social.media.block_private_networks' => true, 'social.inspector_video_range_bytes' => 65536]);
    PublicUrl::resolveUsing(fn () => ['93.184.216.34']);
    Http::fakeSequence()
        ->push('HEAD', 206, ['Content-Range' => 'bytes 0-3/500000'])
        ->push('TAIL', 206, ['Content-Range' => 'bytes 434464-499999/500000']);

    $inspector = new class(spProbeJson(['width' => 1080, 'height' => 1920])) extends FFProbeInspector
    {
        /** @var list<array{size: int, head: string, tail: string}> */
        public array $seen = [];

        public function __construct(private string $json)
        {
            parent::__construct();
        }

        protected function runProbe(array $command): ?string
        {
            $path = (string) end($command);
            clearstatcache(true, $path);
            $handle = fopen($path, 'rb');
            $head = (string) fread($handle, 4);
            fseek($handle, 434464);
            $tail = (string) fread($handle, 4);
            fclose($handle);
            $this->seen[] = ['size' => (int) filesize($path), 'head' => $head, 'tail' => $tail];

            return count($this->seen) === 1 ? null : $this->json; // "moov atom not found" on the head alone
        }

        protected function ffprobeAvailable(): bool
        {
            return true;
        }
    };

    $meta = $inspector->inspect(Media::video('https://cdn.example.com/phone.mov'));

    expect($inspector->seen)->toBe([
        ['size' => 4, 'head' => 'HEAD', 'tail' => ''],
        ['size' => 500000, 'head' => 'HEAD', 'tail' => 'TAIL'],
    ])
        ->and($meta->width)->toBe(1080)
        ->and($meta->sizeBytes)->toBe(500000)
        ->and(glob($this->tempDir.'/sp_*') ?: [])->toBe([]);
});

it('caps the inspection download at the sink, where cURL honours it, and measures what it got', function () {
    config(['social.media.block_private_networks' => true, 'social.inspector_range_bytes' => 65536]);
    PublicUrl::resolveUsing(fn () => ['93.184.216.34']);

    $image = imagecreatetruecolor(3, 2);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    $writes = [];
    $streams = [];

    // A server that ignores Range and sends the whole (large) file with 200.
    Http::fake(function (Request $request, array $options) use ($png, &$writes, &$streams) {
        $streams[] = $sink = GuzzleUtils::streamFor($options['sink']);
        $writes[] = $sink->write($png.str_repeat("\0", 65536 - strlen($png)));
        $writes[] = $sink->write('more');

        return Http::response('', 200);
    });

    $meta = (new FFProbeInspector)->inspect(Media::image('https://cdn.example.com/pic.png'));

    expect($writes)->toBe([65536, 0])
        ->and($meta->width)->toBe(3)
        ->and($meta->height)->toBe(2)
        ->and(glob($this->tempDir.'/sp_*') ?: [])->toBe([]);
})->skip(! function_exists('imagepng'), 'ext-gd is required');

it('refuses stream-wrapper sources without touching them', function () {
    SpyStreamWrapper::register();

    try {
        $inspector = spProbeInspector(spProbeJson(['width' => 1, 'height' => 1]));
        $image = Media::image('sp-spy://10.0.0.5/internal/a.jpg');
        $video = Media::video('sp-spy://10.0.0.5/internal/a.mp4');

        expect($inspector->supports($image))->toBeFalse()
            ->and($inspector->supports($video))->toBeFalse()
            ->and($inspector->inspect($image)->isEmpty())->toBeTrue()
            ->and($inspector->inspect($video)->isEmpty())->toBeTrue()
            ->and($inspector->commands)->toBe([])
            ->and(SpyStreamWrapper::$calls)->toBe([]);
    } finally {
        SpyStreamWrapper::unregister();
    }
});
