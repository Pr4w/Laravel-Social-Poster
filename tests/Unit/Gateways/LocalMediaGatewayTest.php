<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils as GuzzleUtils;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\DecoratedAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\MediaType;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Gateways\LocalMediaGateway;
use SocialPoster\Support\PublicUrl;
use SocialPoster\Tests\Unit\Media\Support\SpyStreamWrapper;
use SocialPoster\ValueObjects\Media;

/** Run $fn and return the exception it throws (fails the test when it does not throw). */
function spGatewayCatch(callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('Expected an exception.');
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/sp-mt-gw-'.bin2hex(random_bytes(4));
    config([
        'social.temp_dir' => $this->tempDir,
        'social.media.block_private_networks' => true,
    ]);

    PublicUrl::resolveUsing(fn (string $host) => match ($host) {
        'cdn.example.com' => ['93.184.216.34'],
        'cdn2.example.com' => ['151.101.1.69'],
        'rebind.example.com' => ['93.184.216.34', '10.0.0.1'],
        default => [],
    });

    $this->leftovers = fn (): array => glob($this->tempDir.'/sp_*') ?: [];
});

afterEach(function () {
    PublicUrl::resolveUsing(null);
    array_map('unlink', glob($this->tempDir.'/*') ?: []);
    @rmdir($this->tempDir);
});

it('downloads remote media into an sp_ temp file with a timeout, pinned and without auto-redirects', function () {
    config(['social.download_timeout' => 33]);
    $captured = [];

    Http::fake(function (Request $request, array $options) use (&$captured) {
        $captured = $options;

        return Http::response('VIDEO-BYTES', 200);
    });

    $gateway = new LocalMediaGateway;
    $path = $gateway->path(Media::video('https://cdn.example.com/v/clip.mp4?sig=abc'));

    expect(dirname($path))->toBe($this->tempDir)
        ->and(basename($path))->toMatch('/^sp_[0-9a-f]{16}\.mp4$/')
        ->and(file_get_contents($path))->toBe('VIDEO-BYTES')
        ->and($captured['timeout'])->toBe(33)
        ->and($captured['connect_timeout'])->toBe(10)
        ->and($captured['allow_redirects'])->toBeFalse()
        ->and($captured['sink'])->toBeResource()
        ->and($captured['curl'][CURLOPT_RESOLVE])->toContain('cdn.example.com:443:93.184.216.34')
        ->and($captured['on_headers'])->toBeCallable()
        ->and($captured)->not->toHaveKey('progress');

    $gateway->cleanup();

    expect(is_file($path))->toBeFalse();
});

it('rejects a download above social.media.max_download_bytes and removes the partial file', function () {
    config(['social.media.max_download_bytes' => 10]);
    Http::fake(['*' => Http::response(str_repeat('x', 11), 200)]);

    $e = spGatewayCatch(fn () => (new LocalMediaGateway)->path(Media::video('https://cdn.example.com/big.mp4')));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain('max_download_bytes')
        ->and(($this->leftovers)())->toBe([]);
});

it('stops an oversized transfer where cURL honours it: a short write at the sink, or the headers', function () {
    config(['social.media.max_download_bytes' => 10]);
    $writes = [];
    $headerAbort = null;
    $smallHeaders = null;
    $streams = [];

    Http::fake(function (Request $request, array $options) use (&$writes, &$headerAbort, &$smallHeaders, &$streams) {
        // Guzzle wraps the sink like this and returns write()'s result to cURL,
        // which aborts the transfer (CURLE_WRITE_ERROR) on any short count.
        // Exceptions thrown from cURL callbacks are swallowed by PHP and stop nothing.
        $streams[] = $sink = GuzzleUtils::streamFor($options['sink']);
        $writes[] = $sink->write('12345');
        $writes[] = $sink->write('678901');
        $writes[] = $sink->write('2');

        // A Content-Length above the cap aborts before the body (Guzzle returns -1 from the header callback).
        try {
            $options['on_headers'](new PsrResponse(200, ['Content-Length' => '5000']));
        } catch (Throwable $e) {
            $headerAbort = $e;
        }

        $options['on_headers'](new PsrResponse(200, ['Content-Length' => '10']));
        $options['on_headers'](new PsrResponse(404, ['Content-Length' => '5000']));
        $smallHeaders = true;

        return Http::response('', 200);
    });

    $e = spGatewayCatch(fn () => (new LocalMediaGateway)->path(Media::video('https://cdn.example.com/a.mp4')));

    expect($writes)->toBe([5, 5, 0])
        ->and($headerAbort)->toBeInstanceOf(RuntimeException::class)
        ->and($smallHeaders)->toBeTrue()
        ->and($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain('max_download_bytes')
        ->and(($this->leftovers)())->toBe([]);
});

it('maps an aborted transfer to the limit that tripped', function () {
    config(['social.media.max_download_bytes' => 10]);

    // What the real cURL handler raises after the sink refused the bytes.
    Http::fake(function (Request $request, array $options) {
        GuzzleUtils::streamFor($options['sink'])->write(str_repeat('x', 11));

        throw new RequestException('cURL error 23: Failure writing output to destination', new PsrRequest('GET', $request->url()));
    });

    $e = spGatewayCatch(fn () => (new LocalMediaGateway)->path(Media::video('https://cdn.example.com/a.mp4')));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->context['max_download_bytes'] ?? null)->toBe(10)
        ->and(($this->leftovers)())->toBe([]);
});

it('pins every spelling of the host cURL may look up (trailing dot)', function () {
    $captured = [];

    Http::fake(function (Request $request, array $options) use (&$captured) {
        $captured = $options;

        return Http::response('BYTES', 200);
    });

    (new LocalMediaGateway)->path(Media::image('https://cdn.example.com./a.jpg'));

    expect($captured['curl'][CURLOPT_RESOLVE])
        ->toContain('cdn.example.com.:443:93.184.216.34')
        ->toContain('cdn.example.com:443:93.184.216.34');
});

it('refuses stream-wrapper sources (ftp://, phar://, ...) without touching them', function () {
    SpyStreamWrapper::register();

    try {
        $gateway = new LocalMediaGateway;
        $e = spGatewayCatch(fn () => $gateway->path(Media::image('sp-spy://10.0.0.5/backups/db.png')));

        expect($e)->toBeInstanceOf(PermanentException::class)
            ->and($e->reason)->toBe(FailureReason::MediaRejected)
            ->and($gateway->canProvideUrl(Media::image('sp-spy://10.0.0.5/backups/db.png')))->toBeFalse()
            ->and(SpyStreamWrapper::$calls)->toBe([]);
    } finally {
        SpyStreamWrapper::unregister();
    }
});

it('still accepts file:// sources like plain local paths', function () {
    $file = sys_get_temp_dir().'/sp-mt-local-'.bin2hex(random_bytes(4)).'.jpg';
    file_put_contents($file, 'img');

    expect((new LocalMediaGateway)->path(Media::image('file://'.$file)))->toBe('file://'.$file);

    unlink($file);
});

it('maps HTTP statuses: 4xx is permanent, 5xx and 429 are temporary', function (int $status, string $class, FailureReason $reason) {
    Http::fake(['*' => Http::response('nope', $status, ['Retry-After' => '30'])]);

    $e = spGatewayCatch(fn () => (new LocalMediaGateway)->path(Media::video('https://cdn.example.com/v.mp4?signature=SECRET')));

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason)
        ->and($e->getMessage())->toContain("returned HTTP {$status}")
        ->and($e->getMessage())->not->toContain('SECRET')
        ->and($e->context['http_status'])->toBe($status)
        ->and(($this->leftovers)())->toBe([]);

    if ($e instanceof TemporaryException) {
        expect($e->retryAfter)->toBe(30);
    }
})->with([
    '404' => [404, PermanentException::class, FailureReason::MediaRejected],
    '403' => [403, PermanentException::class, FailureReason::MediaRejected],
    '410' => [410, PermanentException::class, FailureReason::MediaRejected],
    '429' => [429, TemporaryException::class, FailureReason::RateLimited],
    '500' => [500, TemporaryException::class, FailureReason::Network],
    '503' => [503, TemporaryException::class, FailureReason::Network],
]);

it('turns a connection failure into a temporary network error and cleans up', function () {
    Http::fake(fn (Request $request) => throw new ConnectException('cURL error 28: Operation timed out after 120000 milliseconds with 5 bytes received', new PsrRequest('GET', $request->url())));

    $e = spGatewayCatch(fn () => (new LocalMediaGateway)->path(Media::video('https://cdn.example.com/v.mp4')));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::Network)
        ->and($e->context['connection'] ?? null)->toBeTrue()
        ->and(($this->leftovers)())->toBe([]);
});

it('follows redirects itself, re-checking and re-pinning every hop', function () {
    $pins = [];

    Http::fake(function (Request $request, array $options) use (&$pins) {
        $pins[] = $options['curl'][CURLOPT_RESOLVE][0] ?? null;

        return str_contains($request->url(), 'cdn2')
            ? Http::response('FINAL', 200)
            : Http::response('', 302, ['Location' => 'https://cdn2.example.com/final.mp4']);
    });

    $path = (new LocalMediaGateway)->path(Media::video('https://cdn.example.com/start.mp4'));

    expect(file_get_contents($path))->toBe('FINAL')
        ->and($pins)->toBe(['cdn.example.com:443:93.184.216.34', 'cdn2.example.com:443:151.101.1.69']);
});

it('refuses a redirect to the cloud metadata endpoint', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);

    $e = spGatewayCatch(fn () => (new LocalMediaGateway)->path(Media::image('https://cdn.example.com/a.jpg')));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->context['ssrf_guard'] ?? null)->toBeTrue()
        ->and(($this->leftovers)())->toBe([]);

    Http::assertSentCount(1);
});

it('gives up after three redirects', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => '/again'])]);

    $e = spGatewayCatch(fn () => (new LocalMediaGateway)->path(Media::image('https://cdn.example.com/a.jpg')));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->getMessage())->toContain('more than 3 times');

    Http::assertSentCount(4);
});

it('sends nothing to private, rebinding or credential-bearing URLs', function (string $url) {
    Http::fake();

    expect(spGatewayCatch(fn () => (new LocalMediaGateway)->path(Media::image($url))))->toBeInstanceOf(PermanentException::class)
        ->and(($this->leftovers)())->toBe([]);

    Http::assertNothingSent();
})->with([
    ['https://127.0.0.1/a.jpg'],
    ['https://[::ffff:169.254.169.254]/a.jpg'],
    ['https://rebind.example.com/a.jpg'],
    ['https://user:pass@cdn.example.com/a.jpg'],
]);

it('honours allow_http and lets private hosts through when blocking is off (local development)', function () {
    config(['social.media.allow_http' => false]);

    // No fake yet: any request would be a stray-request failure.
    expect(spGatewayCatch(fn () => (new LocalMediaGateway)->path(Media::image('http://cdn.example.com/a.jpg'))))
        ->toBeInstanceOf(PermanentException::class);

    config(['social.media.allow_http' => true, 'social.media.block_private_networks' => false]);
    $captured = [];
    Http::fake(function (Request $request, array $options) use (&$captured) {
        $captured = $options;

        return Http::response('LOCAL', 200);
    });

    $path = (new LocalMediaGateway)->path(Media::image('http://app.example.test/storage/a.jpg'));

    expect(file_get_contents($path))->toBe('LOCAL')
        ->and($captured)->not->toHaveKey('curl');
});

it('rejects an empty download', function () {
    Http::fake(['*' => Http::response('', 200)]);

    expect(spGatewayCatch(fn () => (new LocalMediaGateway)->path(Media::image('https://cdn.example.com/a.jpg')))->getMessage())
        ->toContain('empty file');
});

it('accepts any local path when local_roots is null (1.x default)', function () {
    $file = sys_get_temp_dir().'/sp-mt-local-'.bin2hex(random_bytes(4)).'.jpg';
    file_put_contents($file, 'img');

    expect((new LocalMediaGateway)->path(Media::image($file)))->toBe($file);

    unlink($file);
});

it('restricts local paths to social.media.local_roots and the temp dir', function () {
    $root = sys_get_temp_dir().'/sp-mt-root-'.bin2hex(random_bytes(4));
    mkdir($root);
    file_put_contents($inside = $root.'/a.jpg', 'img');
    file_put_contents($outside = sys_get_temp_dir().'/sp-mt-outside-'.bin2hex(random_bytes(4)).'.jpg', 'img');
    config(['social.media.local_roots' => [$root]]);

    $gateway = new LocalMediaGateway;

    expect($gateway->path(Media::image($inside)))->toBe($inside)
        ->and(spGatewayCatch(fn () => $gateway->path(Media::image($outside))))->toBeInstanceOf(PermanentException::class)
        ->and(spGatewayCatch(fn () => $gateway->path(Media::image($root.'/../'.basename($outside)))))->toBeInstanceOf(PermanentException::class);

    $converted = LocalMediaGateway::temporaryDirectory().'/sp_0123456789abcdef.jpg';
    file_put_contents($converted, 'img');
    expect($gateway->path(Media::image($converted)))->toBe($converted);

    unlink($inside);
    unlink($outside);
    rmdir($root);
});

it('keeps the v1.0 local behaviour for missing files and url()', function () {
    $gateway = new LocalMediaGateway;

    expect(spGatewayCatch(fn () => $gateway->path(Media::image('/nope/missing.jpg')))->getMessage())->toContain('Local media not found')
        ->and(spGatewayCatch(fn () => $gateway->url(Media::image('/nope/missing.jpg'))))->toBeInstanceOf(PermanentException::class)
        ->and($gateway->url(Media::image('https://cdn.example.com/a.jpg')))->toBe('https://cdn.example.com/a.jpg')
        ->and($gateway->canProvideUrl(Media::image('https://cdn.example.com/a.jpg')))->toBeTrue()
        ->and($gateway->canProvideUrl(Media::image('/tmp/a.jpg')))->toBeFalse();
});

it('reads disk media from the disk: the file itself on a local disk, a streamed copy otherwise', function () {
    config(['filesystems.disks.sp-mt-gw-disk' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/sp-mt-gw-disk']]);
    Storage::fake('sp-mt-gw-disk');
    Storage::disk('sp-mt-gw-disk')->put('a/clip.mp4', 'DISK-BYTES');

    $gateway = new LocalMediaGateway;

    expect($gateway->path(Media::fromDisk('sp-mt-gw-disk', 'a/clip.mp4')))->toBe(Storage::disk('sp-mt-gw-disk')->path('a/clip.mp4'));

    // A cloud-like (non-local) adapter over the same files: streamed into an sp_ temp file, no HTTP.
    $adapter = new class(new LocalFilesystemAdapter(Storage::disk('sp-mt-gw-disk')->path(''))) extends DecoratedAdapter {};
    Storage::set('sp-mt-gw-cloud', new class(new Filesystem($adapter), $adapter) extends FilesystemAdapter
    {
        public function url($path)
        {
            return 'https://bucket.example.com/'.$path;
        }
    });
    Http::fake();

    $media = Media::fromDisk('sp-mt-gw-cloud', 'a/clip.mp4');
    $copy = $gateway->path($media);

    expect($media->isRemote())->toBeTrue()
        ->and(dirname($copy))->toBe($this->tempDir)
        ->and(file_get_contents($copy))->toBe('DISK-BYTES')
        ->and(spGatewayCatch(fn () => $gateway->path(Media::fromDisk('sp-mt-gw-cloud', 'a/missing.mp4'))))->toBeInstanceOf(PermanentException::class);

    Http::assertNothingSent();
    $gateway->cleanup();

    expect(is_file($copy))->toBeFalse();
});

/** A local disk whose path() does not normalise "../", as on Laravel 12. */
function spLegacyLocalDisk(string $name, string $root): void
{
    $adapter = new LocalFilesystemAdapter($root);

    Storage::set($name, new class(new Filesystem($adapter), $adapter, ['root' => $root]) extends FilesystemAdapter
    {
        public function path($path)
        {
            return $this->prefixer->prefixPath($path);
        }
    });
}

it('refuses disk paths that climb out of the disk root, even where path() does not normalise them', function () {
    $base = sys_get_temp_dir().'/sp-mt-trav-'.bin2hex(random_bytes(4));
    mkdir($base.'/root/a', 0777, true);
    file_put_contents($base.'/secret.jpg', 'SECRET');
    file_put_contents($base.'/root/a/b.jpg', 'OK');
    spLegacyLocalDisk('sp-mt-legacy', $base.'/root');

    $gateway = new LocalMediaGateway;
    $escaping = new Media($base.'/root/../secret.jpg', MediaType::Image, meta: [Media::META_DISK => 'sp-mt-legacy', Media::META_DISK_PATH => '../secret.jpg']);

    try {
        $fromDisk = spGatewayCatch(fn () => Media::fromDisk('sp-mt-legacy', '../secret.jpg'));
        $viaGateway = spGatewayCatch(fn () => $gateway->path($escaping));

        expect($fromDisk)->toBeInstanceOf(PermanentException::class)
            ->and($fromDisk->reason)->toBe(FailureReason::MediaRejected)
            ->and($viaGateway)->toBeInstanceOf(PermanentException::class)
            ->and($viaGateway->reason)->toBe(FailureReason::MediaRejected)
            ->and($gateway->path(Media::fromDisk('sp-mt-legacy', 'a/./x/../b.jpg')))->toBe($base.'/root/a/b.jpg')
            ->and(Media::fromDisk('sp-mt-legacy', 'a/./x/../b.jpg')->diskPath())->toBe('a/b.jpg');
    } finally {
        @unlink($base.'/secret.jpg');
        @unlink($base.'/root/a/b.jpg');
        @rmdir($base.'/root/a');
        @rmdir($base.'/root');
        @rmdir($base);
    }
});

it('keeps the free-space reserve when copying disk media, leaving no partial file', function () {
    $root = sys_get_temp_dir().'/sp-mt-gw-big-'.bin2hex(random_bytes(4));
    mkdir($root.'/a', 0777, true);
    file_put_contents($root.'/a/big.mp4', str_repeat('x', 100));

    $adapter = new class(new LocalFilesystemAdapter($root)) extends DecoratedAdapter {};
    Storage::set('sp-mt-gw-cloud-big', new FilesystemAdapter(new Filesystem($adapter), $adapter));

    $gateway = new class extends LocalMediaGateway
    {
        protected function byteLimits(string $dir): array
        {
            return ['max' => null, 'free' => 10];
        }
    };

    try {
        $e = spGatewayCatch(fn () => $gateway->path(Media::fromDisk('sp-mt-gw-cloud-big', 'a/big.mp4')));

        expect($e)->toBeInstanceOf(PermanentException::class)
            ->and($e->reason)->toBe(FailureReason::Misconfigured)
            ->and(($this->leftovers)())->toBe([]);
    } finally {
        @unlink($root.'/a/big.mp4');
        @rmdir($root.'/a');
        @rmdir($root);
    }
});
