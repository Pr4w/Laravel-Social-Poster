<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use SocialPoster\Console\PruneTempMediaCommand;
use SocialPoster\Gateways\FilesystemMediaGateway;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/sp-mt-prune-'.bin2hex(random_bytes(4));
    mkdir($this->tempDir);

    config([
        'social.temp_dir' => $this->tempDir,
        'social.media.rehost_disk' => 'sp-mt-prune-disk',
        'filesystems.disks.sp-mt-prune-disk' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/sp-mt-prune-disk', 'url' => 'https://media.example.com'],
    ]);

    Storage::fake('sp-mt-prune-disk', ['url' => 'https://media.example.com']);

    // Registered here until the service provider registers it.
    app(Kernel::class)->registerCommand(app(PruneTempMediaCommand::class));

    $this->makeFile = function (string $name, int $age): string {
        $path = $this->tempDir.'/'.$name;
        file_put_contents($path, 'x');
        touch($path, time() - $age);

        return $path;
    };
});

afterEach(function () {
    array_map('unlink', glob($this->tempDir.'/*') ?: []);
    @rmdir($this->tempDir);
});

it('deletes stale sp_ temp files and keeps fresh or foreign ones', function () {
    $stale = ($this->makeFile)('sp_0123456789abcdef.mp4', 90000);
    $staleProbe = ($this->makeFile)('sp_fedcba9876543210.part', 90000);
    $fresh = ($this->makeFile)('sp_aaaaaaaaaaaaaaaa.jpg', 60);
    $foreign = ($this->makeFile)('notes.txt', 90000);

    $this->artisan('social-poster:prune-temp')->assertSuccessful();

    expect(is_file($stale))->toBeFalse()
        ->and(is_file($staleProbe))->toBeFalse()
        ->and(is_file($fresh))->toBeTrue()
        ->and(is_file($foreign))->toBeTrue();
});

it('deletes the stale upload copies TikTok keeps between runs', function () {
    // TikTokDriver renames a download to sp_tiktok_<16 hex> so a retry can reuse it.
    $stale = ($this->makeFile)('sp_tiktok_0123456789abcdef.mp4', 90000);
    $fresh = ($this->makeFile)('sp_tiktok_fedcba9876543210.mov', 60);
    $foreign = ($this->makeFile)('sp_tiktok_notes.mp4', 90000);

    $this->artisan('social-poster:prune-temp')->assertSuccessful();

    expect(is_file($stale))->toBeFalse()
        ->and(is_file($fresh))->toBeTrue()
        ->and(is_file($foreign))->toBeTrue();
});

it('deletes the stale upload copies YouTube keeps between runs', function () {
    // YouTubeDriver keeps its staged source as sp_youtube_<16 hex> for the whole upload;
    // a chain the job ended (deadline, retries, a killed worker) leaves it behind.
    $stale = ($this->makeFile)('sp_youtube_0123456789abcdef.mp4', 90000);
    $fresh = ($this->makeFile)('sp_youtube_fedcba9876543210.mp4', 60);
    $foreign = ($this->makeFile)('sp_youtube_notes.mp4', 90000);

    $this->artisan('social-poster:prune-temp')->assertSuccessful();

    expect(is_file($stale))->toBeFalse()
        ->and(is_file($fresh))->toBeTrue()
        ->and(is_file($foreign))->toBeTrue();
});

it('honours --hours and --dry-run', function () {
    $file = ($this->makeFile)('sp_0123456789abcdef.mp4', 7200);

    $this->artisan('social-poster:prune-temp', ['--hours' => 1, '--dry-run' => true])->assertSuccessful();
    expect(is_file($file))->toBeTrue();

    $this->artisan('social-poster:prune-temp', ['--hours' => 1])->assertSuccessful();
    expect(is_file($file))->toBeFalse();
});

it('prunes expired re-hosted copies when FilesystemMediaGateway is the gateway', function () {
    config(['social.gateway' => FilesystemMediaGateway::class]);
    $disk = Storage::disk('sp-mt-prune-disk');
    $old = 'social-poster/'.sha1('old').'.jpg';
    $new = 'social-poster/'.sha1('new').'.jpg';
    $mine = 'social-poster/keep-me.jpg';

    foreach ([$old, $new, $mine] as $file) {
        $disk->put($file, 'x');
    }

    touch($disk->path($old), time() - 90000);
    touch($disk->path($mine), time() - 90000);

    $this->artisan('social-poster:prune-temp')->assertSuccessful();

    expect($disk->exists($old))->toBeFalse()
        ->and($disk->exists($new))->toBeTrue()
        ->and($disk->exists($mine))->toBeTrue();
});

it('leaves re-hosted copies alone unless that gateway is used or --rehosted is passed', function () {
    $disk = Storage::disk('sp-mt-prune-disk');
    $old = 'social-poster/'.sha1('old').'.jpg';
    $disk->put($old, 'x');
    touch($disk->path($old), time() - 90000);

    $this->artisan('social-poster:prune-temp')->assertSuccessful();
    expect($disk->exists($old))->toBeTrue();

    $this->artisan('social-poster:prune-temp', ['--rehosted' => true])->assertSuccessful();
    expect($disk->exists($old))->toBeFalse();
});
