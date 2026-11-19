<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\MediaType;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Gateways\FilesystemMediaGateway;
use SocialPoster\Support\PublicUrl;
use SocialPoster\Tests\Unit\Media\Support\SpyStreamWrapper;
use SocialPoster\ValueObjects\Media;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/sp-mt-fs-'.bin2hex(random_bytes(4));

    // Unique disk names so concurrent test runs never share a fake disk.
    config([
        'social.temp_dir' => $this->tempDir,
        'social.media.block_private_networks' => true,
        'social.media.rehost_disk' => 'sp-mt-rehost',
        'filesystems.disks.sp-mt-rehost' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/sp-mt-rehost'],
        'filesystems.disks.sp-mt-uploads' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/sp-mt-uploads'],
        'filesystems.disks.sp-mt-relative' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/sp-mt-relative'],
    ]);

    Storage::fake('sp-mt-rehost', ['url' => 'https://media.example.com/storage']);
    Storage::fake('sp-mt-uploads');
    Storage::fake('sp-mt-relative');

    PublicUrl::resolveUsing(fn () => ['93.184.216.34']);

    $this->localFile = sys_get_temp_dir().'/sp-mt-local-'.bin2hex(random_bytes(4)).'.jpg';
    file_put_contents($this->localFile, 'JPEG-BYTES');
});

afterEach(function () {
    PublicUrl::resolveUsing(null);
    @unlink($this->localFile);
    array_map('unlink', glob($this->tempDir.'/*') ?: []);
    @rmdir($this->tempDir);
});

it('re-hosts a local file on the public disk and returns its absolute URL', function () {
    $gateway = new FilesystemMediaGateway;
    $media = Media::image($this->localFile);

    $url = $gateway->url($media);
    $files = Storage::disk('sp-mt-rehost')->files('social-poster');

    expect($gateway->canProvideUrl($media))->toBeTrue()
        ->and($files)->toHaveCount(1)
        ->and($files[0])->toMatch('~^social-poster/[0-9a-f]{40}\.jpg$~')
        ->and($url)->toBe('https://media.example.com/storage/'.$files[0])
        ->and(Storage::disk('sp-mt-rehost')->get($files[0]))->toBe('JPEG-BYTES');
});

it('reuses the same copy for retries instead of uploading duplicates', function () {
    $first = (new FilesystemMediaGateway)->url(Media::image($this->localFile));
    $second = (new FilesystemMediaGateway)->url(Media::image($this->localFile));

    expect($second)->toBe($first)
        ->and(Storage::disk('sp-mt-rehost')->files('social-poster'))->toHaveCount(1);
});

it('passes remote URLs through under the local_only policy', function () {
    Http::fake();

    $gateway = new FilesystemMediaGateway;

    expect($gateway->url(Media::image('https://cdn.example.com/a.jpg?sig=1')))->toBe('https://cdn.example.com/a.jpg?sig=1');
    Http::assertNothingSent();
});

it('downloads and re-hosts remote media under the always policy', function () {
    config(['social.media.rehost' => 'always']);
    Http::fake(['cdn.example.com/*' => Http::response('REMOTE-BYTES', 200)]);

    $gateway = new FilesystemMediaGateway;
    $url = $gateway->url(Media::video('https://cdn.example.com/signed/v.mp4?X-Amz-Signature=abc'));
    $files = Storage::disk('sp-mt-rehost')->files('social-poster');

    expect($url)->toStartWith('https://media.example.com/storage/social-poster/')
        ->and($url)->toEndWith('.mp4')
        ->and(Storage::disk('sp-mt-rehost')->get($files[0]))->toBe('REMOTE-BYTES');

    $gateway->cleanup();
    expect(glob($this->tempDir.'/sp_*') ?: [])->toBe([]);
});

it('refuses local files under the never policy', function () {
    $gateway = new FilesystemMediaGateway(policy: 'never');
    $media = Media::image($this->localFile);

    expect($gateway->canProvideUrl($media))->toBeFalse()
        ->and(fn () => $gateway->url($media))->toThrow(PermanentException::class);
});

it('serves disk media from its disk, copying only local disks', function () {
    Storage::disk('sp-mt-rehost')->put('library/a.png', 'ON-PUBLIC');
    Storage::disk('sp-mt-uploads')->put('u/b.png', 'ON-PRIVATE');

    $gateway = new FilesystemMediaGateway;

    expect($gateway->url(Media::fromDisk('sp-mt-rehost', 'library/a.png')))->toBe('https://media.example.com/storage/library/a.png')
        ->and(Storage::disk('sp-mt-rehost')->files('social-poster'))->toBe([]);

    $copied = $gateway->url(Media::fromDisk('sp-mt-uploads', 'u/b.png'));
    $files = Storage::disk('sp-mt-rehost')->files('social-poster');

    expect($files)->toHaveCount(1)
        ->and($copied)->toBe('https://media.example.com/storage/'.$files[0])
        ->and(Storage::disk('sp-mt-rehost')->get($files[0]))->toBe('ON-PRIVATE');
});

it('can hand out temporary URLs', function () {
    config(['social.media.temporary_urls' => true]);

    $url = (new FilesystemMediaGateway)->url(Media::image($this->localFile));

    expect($url)->toContain('?expiration=');
});

it('fails clearly on a disk that only builds relative URLs', function () {
    $e = null;

    try {
        (new FilesystemMediaGateway(disk: 'sp-mt-relative'))->url(Media::image($this->localFile));
    } catch (PermanentException $e) {
    }

    expect($e)->not->toBeNull()
        ->and($e->reason)->toBe(FailureReason::Misconfigured)
        ->and($e->getMessage())->toContain('relative URL');
});

it('rejects an unknown rehost policy', function () {
    config(['social.media.rehost' => 'sometimes']);

    expect(fn () => (new FilesystemMediaGateway)->url(Media::image($this->localFile)))
        ->toThrow(PermanentException::class, 'Unknown social.media.rehost policy');
});

it('still enforces local_roots before re-hosting', function () {
    config(['social.media.local_roots' => [sys_get_temp_dir().'/sp-mt-nowhere']]);

    expect(fn () => (new FilesystemMediaGateway)->url(Media::image($this->localFile)))
        ->toThrow(PermanentException::class, 'local_roots');
});

it('never re-hosts media under an extension a web server could execute or render', function (string $source, string $suffix) {
    config(['social.media.rehost' => 'always']);
    Http::fake(['cdn.example.com/*' => Http::response('<script>alert(document.cookie)</script>', 200)]);

    $url = (new FilesystemMediaGateway)->url(Media::image($source));
    $files = Storage::disk('sp-mt-rehost')->files('social-poster');
    $pattern = '~/[0-9a-f]{40}'.preg_quote($suffix, '~').'$~';

    expect($files)->toHaveCount(1)
        ->and($files[0])->toMatch($pattern)
        ->and($url)->toMatch($pattern);
})->with([
    'html' => ['https://cdn.example.com/cover.html', '.bin'],
    'svg' => ['https://cdn.example.com/cover.svg', '.bin'],
    'php' => ['https://cdn.example.com/cover.php', '.bin'],
    'phtml' => ['https://cdn.example.com/cover.phtml', '.bin'],
    'no extension' => ['https://cdn.example.com/cover', ''],
    'jpg kept (lowercased)' => ['https://cdn.example.com/cover.JPG', '.jpg'],
    'mp4 kept' => ['https://cdn.example.com/clip.mp4', '.mp4'],
]);

it('re-hosts a local file with an unsafe extension as .bin', function () {
    $html = sys_get_temp_dir().'/sp-mt-local-'.bin2hex(random_bytes(4)).'.html';
    file_put_contents($html, '<script>alert(1)</script>');

    try {
        $url = (new FilesystemMediaGateway)->url(Media::image($html));
    } finally {
        unlink($html);
    }

    expect($url)->toEndWith('.bin')
        ->and(Storage::disk('sp-mt-rehost')->files('social-poster')[0])->toEndWith('.bin');
});

it('refuses stream-wrapper sources without touching them', function () {
    SpyStreamWrapper::register();

    try {
        $gateway = new FilesystemMediaGateway;
        $media = Media::image('sp-spy://10.0.0.5/internal/secret.jpg');

        expect($gateway->canProvideUrl($media))->toBeFalse()
            ->and(fn () => $gateway->url($media))->toThrow(PermanentException::class)
            ->and(SpyStreamWrapper::$calls)->toBe([])
            ->and(Storage::disk('sp-mt-rehost')->files('social-poster'))->toBe([]);
    } finally {
        SpyStreamWrapper::unregister();
    }
});

it('refuses disk paths that climb out of the disk root', function () {
    $media = new Media('/x', MediaType::Image, meta: [Media::META_DISK => 'sp-mt-uploads', Media::META_DISK_PATH => '../../etc/passwd.jpg']);
    $e = null;

    try {
        (new FilesystemMediaGateway)->url($media);
    } catch (Throwable $e) {
    }

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected);
});
