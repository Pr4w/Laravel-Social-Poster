<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\DecoratedAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SocialPoster\Enums\MediaType;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;

it('guesses every video extension the drivers accept', function (string $source, MediaType $type) {
    expect(Media::guess($source)->type)->toBe($type);
})->with([
    ['clip.mp4', MediaType::Video],
    ['clip.MOV', MediaType::Video],
    ['clip.wmv', MediaType::Video],
    ['clip.flv', MediaType::Video],
    ['clip.mpg', MediaType::Video],
    ['clip.mpeg', MediaType::Video],
    ['clip.3gp', MediaType::Video],
    ['https://cdn.example.com/v/clip.webm?sig=abc', MediaType::Video],
    ['deck.pdf', MediaType::Document],
    ['slides.pptx', MediaType::Document],
    ['photo.jpg', MediaType::Image],
    ['https://cdn.example.com/no-extension', MediaType::Image],
]);

it('keeps the v1.0 factory positions and adds meta and video alt text at the end', function () {
    $image = Media::image('a.jpg', 'A cat', ['width' => 10]);
    $video = Media::video('a.mp4', 'cover.jpg', 'A dog', ['duration' => 3.5]);
    $document = Media::document('a.pdf', ['size' => 100]);
    $guessed = Media::guess('a.mov', ['fps' => 30]);

    expect($image->altText)->toBe('A cat')
        ->and($image->meta)->toBe(['width' => 10])
        ->and($video->thumbnail)->toBe('cover.jpg')
        ->and($video->altText)->toBe('A dog')
        ->and($video->meta)->toBe(['duration' => 3.5])
        ->and($document->meta)->toBe(['size' => 100])
        ->and($guessed->type)->toBe(MediaType::Video)
        ->and($guessed->meta)->toBe(['fps' => 30])
        ->and(Media::video('b.mp4', 'thumb.png')->thumbnail)->toBe('thumb.png');
});

it('exposes caller-supplied metadata', function () {
    $media = Media::video('https://example.com/v.mp4', meta: [
        'width' => '1080', 'height' => 1920, 'duration' => '12.5', 'size_bytes' => 2048,
        'fps' => '30000/1001', 'mime' => 'video/mp4', 'rotation' => 90, 'instagram' => ['user_tags' => []],
    ]);

    $meta = $media->knownMetadata();

    expect($meta->width)->toBe(1080)
        ->and($meta->height)->toBe(1920)
        ->and($meta->durationSeconds)->toBe(12.5)
        ->and($meta->sizeBytes)->toBe(2048)
        ->and(round((float) $meta->frameRate, 2))->toBe(29.97)
        ->and($meta->mimeType)->toBe('video/mp4')
        ->and($meta->rotation)->toBe(90);
});

it('builds media from a local disk with the absolute path as source', function () {
    config(['filesystems.disks.sp-mt-media' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/sp-mt-media']]);
    Storage::fake('sp-mt-media');
    Storage::disk('sp-mt-media')->put('videos/clip.mp4', 'bytes');

    $media = Media::fromDisk('sp-mt-media', '/videos/clip.mp4');

    expect($media->type)->toBe(MediaType::Video)
        ->and($media->source)->toBe(Storage::disk('sp-mt-media')->path('videos/clip.mp4'))
        ->and(is_file($media->source))->toBeTrue()
        ->and($media->disk())->toBe('sp-mt-media')
        ->and($media->diskPath())->toBe('videos/clip.mp4')
        ->and($media->isOnDisk())->toBeTrue()
        ->and($media->isRemote())->toBeFalse()
        ->and($media->extension())->toBe('mp4');

    expect(Media::fromDisk('sp-mt-media', 'docs/file.bin', MediaType::Document, ['size' => 5], 'Alt')->type)->toBe(MediaType::Document)
        ->and(Media::image('x.jpg')->isOnDisk())->toBeFalse();
});

it('builds media from a cloud disk with the disk URL as source', function () {
    // A non-local adapter (decorated, so it is not a LocalFilesystemAdapter) that builds bucket URLs.
    $adapter = new class(new LocalFilesystemAdapter(sys_get_temp_dir())) extends DecoratedAdapter {};

    Storage::set('sp-mt-cloud', new class(new Filesystem($adapter), $adapter) extends FilesystemAdapter
    {
        public function url($path)
        {
            return 'https://bucket.example.com/'.$path;
        }
    });

    $media = Media::fromDisk('sp-mt-cloud', 'img/a.png');

    expect($media->source)->toBe('https://bucket.example.com/img/a.png')
        ->and($media->isRemote())->toBeTrue()
        ->and($media->type)->toBe(MediaType::Image)
        ->and($media->disk())->toBe('sp-mt-cloud');
});

it('merges metadata with measured values first', function () {
    $measured = new MediaMetadata(width: 1080, height: null, durationSeconds: 10.0);
    $known = new MediaMetadata(width: 999, height: 1920, durationSeconds: 99.0, frameRate: 30.0);

    $merged = $measured->withFallback($known);

    expect($merged->width)->toBe(1080)
        ->and($merged->height)->toBe(1920)
        ->and($merged->durationSeconds)->toBe(10.0)
        ->and($merged->frameRate)->toBe(30.0)
        ->and($merged->pixels())->toBe(1080 * 1920)
        ->and((new MediaMetadata)->isEmpty())->toBeTrue()
        ->and($merged->isEmpty())->toBeFalse()
        ->and(MediaMetadata::fromArray(['width' => 'abc', 'fps' => '0/0'])->isEmpty())->toBeTrue();
});
