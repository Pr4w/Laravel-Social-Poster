<?php

namespace SocialPoster\Tests\Feature\Platforms;

use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Contracts\MediaGateway;
use SocialPoster\Contracts\MediaTransformer;
use SocialPoster\Enums\Platform;
use SocialPoster\Platforms\Instagram\InstagramOptions;
use SocialPoster\Tests\Fixtures\Instagram\PublishingGateway;
use SocialPoster\Transformers\ImageConvertingTransformer;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\SocialPost;

use function SocialPoster\Tests\Fixtures\Instagram\igCreds;
use function SocialPoster\Tests\Fixtures\Instagram\igDriver;
use function SocialPoster\Tests\Fixtures\Instagram\igPost;

require_once __DIR__.'/../../Fixtures/instagram/helpers.php';

beforeEach(function () {
    config(['social.api.instagram.graph_version' => 'v25.0']);
});

function igImage(int $width = 1080, int $height = 1080, string $ext = 'jpg', ?string $alt = null, int $size = 500000): Media
{
    return Media::image("https://cdn.example.com/photo-{$width}x{$height}.{$ext}", $alt, ['width' => $width, 'height' => $height, 'size' => $size]);
}

function igVideo(int $width = 1080, int $height = 1920, float $duration = 30, int $size = 50000000, ?string $thumbnail = null): Media
{
    return Media::video("https://cdn.example.com/video-{$width}x{$height}.mp4", $thumbnail, meta: ['width' => $width, 'height' => $height, 'duration' => $duration, 'size' => $size]);
}

/** @return array<string, list<string>> */
function igErrors(array $media, ?string $caption = 'Caption', ?InstagramOptions $options = null, ?MediaGateway $gateway = null, array $credentials = []): array
{
    return igDriver(gateway: $gateway)->validate(igPost($media, $caption, $options, igCreds($credentials)))->toArray();
}

// --- Rules -------------------------------------------------------------------------------

it('declares the documented Instagram limits', function () {
    $caps = igDriver()->capabilities();

    expect($caps->caption->max)->toBe(2200)
        ->and($caps->supportsCarousel)->toBeTrue()
        ->and($caps->media->maxCount)->toBe(10)
        ->and($caps->media->image->extensions)->toBe(['jpg', 'jpeg', 'png'])
        ->and($caps->media->image->maxSizeBytes)->toBe(8388608)
        ->and($caps->media->image->aspectRatioRange)->toBe([0.8, 1.91])
        ->and($caps->media->image->conversionTarget('webp'))->toBe('jpg')
        ->and($caps->media->video->maxWidth)->toBe(1920)
        ->and($caps->media->video->maxHeight)->toBeNull()
        ->and($caps->media->video->maxSizeBytes)->toBe(314572800)
        ->and($caps->media->video->aspectRatioRange)->toBe([0.01, 10.0])
        ->and($caps->media->video->minDuration)->toEqual(3)
        ->and($caps->media->video->maxDuration)->toEqual(900);

    $story = igDriver()->call('rulesFor', igPost([igVideo()], options: new InstagramOptions(isStory: true)));

    expect($story->media->maxCount)->toBe(1)
        ->and($story->media->image)->toBeNull()
        ->and($story->media->video->maxWidth)->toBe(1920)
        ->and($story->media->video->maxSizeBytes)->toBe(104857600)
        ->and($story->media->video->maxDuration)->toEqual(60)
        ->and($story->media->video->aspectRatioRange)->toBe([0.1, 10.0]);

    $storyPhoto = igDriver()->call('rulesFor', igPost([igImage()], options: new InstagramOptions(isStory: true)));

    expect($storyPhoto->media->image->aspectRatioRange)->toBeNull()
        ->and($storyPhoto->media->video)->toBeNull();
});

it('reads every limit override from config', function () {
    config([
        'social.api.instagram.carousel_max' => 20,
        'social.api.instagram.reel_max_bytes' => 1073741824,
        'social.api.instagram.story_video_max_bytes' => 52428800,
        'social.api.instagram.image_max_bytes' => 4194304,
        'social.api.instagram.jpeg_only' => true,
    ]);

    $carousel = igDriver()->call('rulesFor', igPost([igImage(), igImage()]));
    $story = igDriver()->call('rulesFor', igPost([igVideo()], options: new InstagramOptions(isStory: true)));

    expect($carousel->media->maxCount)->toBe(20)
        ->and($carousel->media->minCount)->toBe(2)
        ->and($carousel->media->video->maxSizeBytes)->toBe(1073741824)
        ->and($carousel->media->image->maxSizeBytes)->toBe(4194304)
        ->and($carousel->media->image->extensions)->toBe(['jpg', 'jpeg'])
        ->and($carousel->media->image->conversionTarget('png'))->toBe('jpg')
        ->and($story->media->video->maxSizeBytes)->toBe(52428800);
});

// --- Media validation --------------------------------------------------------------------

it('accepts well-formed posts of every variant', function (array $media, ?InstagramOptions $options) {
    expect(igErrors($media, 'A caption #one @two', $options))->toBe([]);
})->with([
    'square photo' => [[igImage()], null],
    'png photo' => [[igImage(ext: 'png')], null],
    'portrait 4:5 photo' => [[igImage(1080, 1350)], null],
    'landscape 1.91 photo' => [[igImage(1910, 1000)], null],
    'vertical 1440x2560 reel' => [[igVideo(1440, 2560)], null],
    'reel with a cover' => [[igVideo()], new InstagramOptions(thumbnail: Media::image('https://cdn.example.com/cover.jpg', meta: ['size' => 100000]), shareToFeed: true, collaborators: ['a', 'b', 'c'])],
    'reel with a thumb offset' => [[igVideo()], new InstagramOptions(thumbOffsetMs: 2000)],
    'tall story photo' => [[igImage(1080, 2400)], new InstagramOptions(isStory: true)],
    'story video' => [[igVideo(1080, 1920, 15)], new InstagramOptions(isStory: true)],
    'mixed carousel' => [[igImage(), igVideo(1080, 1350), igImage(1080, 1350)], new InstagramOptions(collaborators: ['a'])],
]);

it('rejects media outside the Instagram limits', function (array $media, ?InstagramOptions $options, string $key) {
    expect(igErrors($media, 'Caption', $options))->toHaveKey($key);
})->with([
    'tall feed photo (1:2)' => [[igImage(500, 1000)], null, 'media.0'],
    'wide feed photo (2:1)' => [[igImage(2000, 1000)], null, 'media.0'],
    'photo over 8 MB' => [[igImage(size: 9000000)], null, 'media.0'],
    'webp photo without a converter' => [[igImage(ext: 'webp')], null, 'media.0'],
    'reel wider than 1920' => [[igVideo(2160, 3840)], null, 'media.0'],
    'reel over 300 MB' => [[igVideo(size: 400000000)], null, 'media.0'],
    'reel shorter than 3s' => [[igVideo(duration: 2)], null, 'media.0'],
    'reel longer than 15 min' => [[igVideo(duration: 901)], null, 'media.0'],
    'story video over 100 MB' => [[igVideo(size: 150000000)], new InstagramOptions(isStory: true), 'media.0'],
    'story video over 60s' => [[igVideo(duration: 90)], new InstagramOptions(isStory: true), 'media.0'],
    'two story items' => [[igImage(), igImage()], new InstagramOptions(isStory: true), 'media'],
    'eleven carousel items' => [array_fill(0, 11, igImage()), null, 'media'],
    'carousel photo out of range' => [[igImage(), igImage(500, 1000)], null, 'media.1'],
]);

it('limits reel and story videos to 23-60 fps', function (float $fps, ?InstagramOptions $options, bool $valid) {
    $video = Media::video('https://cdn.example.com/clip.mp4', meta: ['width' => 1080, 'height' => 1920, 'duration' => 30, 'size' => 50000000, 'frame_rate' => $fps]);

    expect(isset(igErrors([$video], 'Caption', $options)['media.0']))->toBe(! $valid);
})->with([
    'reel at 30 fps' => [30.0, null, true],
    'reel at 23.976 fps' => [23.976, null, true],
    'reel at 59.94 fps' => [59.94, null, true],
    'reel at 120 fps' => [120.0, null, false],
    'reel at 15 fps' => [15.0, null, false],
    'story video at 120 fps' => [120.0, new InstagramOptions(isStory: true), false],
    'story video at 15 fps' => [15.0, new InstagramOptions(isStory: true), false],
]);

it('limits carousel videos to 23-60 fps and reads the frame-rate limits from config', function () {
    $fast = Media::video('https://cdn.example.com/fast.mp4', meta: ['width' => 1080, 'height' => 1350, 'duration' => 30, 'size' => 5000000, 'fps' => 120]);

    expect(igErrors([igImage(), $fast]))->toHaveKey('media.1');

    config(['social.api.instagram.max_video_frame_rate' => 120, 'social.api.instagram.min_video_frame_rate' => 10]);

    expect(igErrors([igImage(), $fast]))->toBe([])
        ->and(igDriver()->capabilities()->media->video->minFrameRate)->toEqual(10);
});

it('allows a bigger carousel when configured', function () {
    config(['social.api.instagram.carousel_max' => 20]);

    expect(igErrors(array_fill(0, 15, igImage())))->toBe([]);
});

it('refuses PNG when jpeg_only is set and nothing can convert it', function () {
    config(['social.api.instagram.jpeg_only' => true]);

    expect(igErrors([igImage(ext: 'png')])['media.0'][0])->toContain('Convert it to .jpg first');
});

it('accepts convertible images when a MediaTransformer is bound', function () {
    app()->instance(MediaTransformer::class, new class implements MediaTransformer
    {
        public function transform(Media $media, Platform $platform, Capabilities $caps): Media
        {
            return $media;
        }
    });

    expect(igErrors([igImage(ext: 'webp')]))->toBe([]);
});

it('refuses an image only conversion makes acceptable when the gateway cannot hand the converted file over', function (string $ext, bool $jpegOnly) {
    if (! (new ImageConvertingTransformer)->canConvert($ext, 'jpg')) {
        $this->markTestSkipped("This runtime cannot convert .{$ext} images.");
    }

    config(['social.api.instagram.jpeg_only' => $jpegOnly]);
    app()->instance(MediaTransformer::class, new ImageConvertingTransformer);

    // The converted JPEG is a local file Instagram cannot pull: with the
    // default gateway the original would be sent and refused (2207005).
    $refused = igErrors([igImage(ext: $ext)]);

    expect($refused)->toHaveKey('media.0')
        ->and($refused['media.0'][0])->toContain('FilesystemMediaGateway')
        ->and(igErrors([igImage(ext: $ext)], gateway: new PublishingGateway))->toBe([])
        ->and(igErrors([igImage(), igImage(ext: $ext)]))->toHaveKey('media.1')
        ->and(igErrors([igImage(ext: $ext)], options: new InstagramOptions(isStory: true)))->toHaveKey('media.0');
})->with([
    'webp' => ['webp', false],
    'png under jpeg_only' => ['png', true],
]);

it('refuses local media the gateway cannot hand over by URL', function () {
    expect(igErrors([Media::image('/var/www/storage/photo.jpg')]))->toHaveKey('media.0');
});

// --- Caption, alt text, typed options ----------------------------------------------------

it('limits a caption to 30 hashtags and 20 mentions', function (string $caption, bool $valid) {
    $errors = igErrors([igImage()], $caption);

    expect(isset($errors['caption']))->toBe(! $valid);
})->with([
    '30 hashtags' => [implode(' ', array_map(fn ($i) => "#tag{$i}", range(1, 30))), true],
    '31 hashtags' => [implode(' ', array_map(fn ($i) => "#tag{$i}", range(1, 31))), false],
    'unicode hashtags' => [implode(' ', array_map(fn ($i) => "#été{$i}", range(1, 31))), false],
    'html entities are not hashtags' => [str_repeat('&#39; ', 40), true],
    '20 mentions' => [implode(' ', array_map(fn ($i) => "@user.{$i}", range(1, 20))), true],
    '21 mentions' => [implode(' ', array_map(fn ($i) => "@user_{$i}", range(1, 21))), false],
    'emails are not mentions' => [implode(' ', array_map(fn ($i) => "me{$i}@example.com", range(1, 25))), true],
]);

it('reads the tag limits from config and skips them on stories', function () {
    config(['social.api.instagram.max_hashtags' => 2]);

    expect(igErrors([igImage()], '#a #b #c'))->toHaveKey('caption')
        ->and(igErrors([igImage(1080, 1920)], '#a #b #c', new InstagramOptions(isStory: true)))->toBe([]);
});

it('limits alt text to 1000 characters on feed images', function () {
    expect(igErrors([igImage(alt: str_repeat('a', 1001))]))->toHaveKey('media.0')
        ->and(igErrors([igImage(alt: str_repeat('é', 1000))]))->toBe([])
        ->and(igErrors([igImage(1080, 1920, alt: str_repeat('a', 1001))], options: new InstagramOptions(isStory: true)))->toBe([]);
});

it('rejects reel-only options on other variants', function (array $media, InstagramOptions $options, string $key) {
    expect(igErrors($media, 'Caption', $options))->toHaveKey($key);
})->with([
    'cover on a photo' => [[igImage()], new InstagramOptions(thumbnail: Media::image('https://cdn.example.com/c.jpg')), 'thumbnail'],
    'cover on a carousel' => [[igImage(), igVideo(1080, 1350)], new InstagramOptions(thumbnail: Media::image('https://cdn.example.com/c.jpg')), 'thumbnail'],
    'thumb offset on a photo' => [[igImage()], new InstagramOptions(thumbOffsetMs: 100), 'thumb_offset'],
    'share to feed on a carousel' => [[igImage(), igImage()], new InstagramOptions(shareToFeed: true), 'share_to_feed'],
]);

it('checks the reel cover can be fetched and fits 8 MB', function () {
    $local = new InstagramOptions(thumbnail: Media::image('/var/www/storage/cover.jpg'));
    $large = new InstagramOptions(thumbnail: Media::image('https://cdn.example.com/cover.jpg', meta: ['size' => 9000000]));

    expect(igErrors([igVideo()], options: $local))->toHaveKey('thumbnail')
        ->and(igErrors([igVideo()], options: $local, gateway: new PublishingGateway))->toBe([])
        ->and(igErrors([igVideo()], options: $large)['thumbnail'][0])->toContain('8 MB');
});

it('never refuses a story over the caption, which a story does not send', function () {
    expect(igErrors([igImage()], str_repeat('a', 2500), new InstagramOptions(isStory: true)))->toBe([])
        ->and(igErrors([igImage()], str_repeat('a', 2500)))->toHaveKey('caption');
});

it('validates scopes when the credentials list them', function () {
    $errors = igErrors([igImage()], credentials: ['scopes' => ['instagram_basic']]);

    expect($errors['credentials.scopes'][0])->toContain('instagram_content_publish');
});

// --- InstagramOptions --------------------------------------------------------------------

it('validates its own fields', function (InstagramOptions $options, string $key) {
    expect($options->validate()->toArray())->toHaveKey($key);
})->with([
    'four collaborators' => [new InstagramOptions(collaborators: ['a', 'b', 'c', 'd']), 'collaborators'],
    'collaborators on a story' => [new InstagramOptions(isStory: true, collaborators: ['a']), 'collaborators'],
    'blank collaborator' => [new InstagramOptions(collaborators: ['@']), 'collaborators'],
    'cover on a story' => [new InstagramOptions(isStory: true, thumbnail: Media::image('https://cdn.example.com/c.jpg')), 'thumbnail'],
    'png cover' => [new InstagramOptions(thumbnail: Media::image('https://cdn.example.com/c.png')), 'thumbnail'],
    'video cover' => [new InstagramOptions(thumbnail: Media::video('https://cdn.example.com/c.mp4')), 'thumbnail'],
    'negative thumb offset' => [new InstagramOptions(thumbOffsetMs: -1), 'thumb_offset'],
    'cover and thumb offset' => [new InstagramOptions(thumbnail: Media::image('https://cdn.example.com/c.jpg'), thumbOffsetMs: 10), 'thumb_offset'],
    'thumb offset on a story' => [new InstagramOptions(isStory: true, thumbOffsetMs: 10), 'thumb_offset'],
    'share to feed on a story' => [new InstagramOptions(isStory: true, shareToFeed: false), 'share_to_feed'],
]);

it('accepts valid options, including a cover URL without an extension', function () {
    $options = new InstagramOptions(
        thumbnail: Media::image('https://cdn.example.com/cover?id=1'),
        extra: ['trial_params' => ['graduation_strategy' => 'MANUAL']],
        shareToFeed: true,
        collaborators: ['@alice', 'bob'],
    );

    expect($options->validate()->toArray())->toBe([])
        ->and($options->extra())->toBe(['trial_params' => ['graduation_strategy' => 'MANUAL']])
        ->and($options->platform())->toBe(Platform::Instagram);
});

it('keeps the 1.0 constructor positions', function () {
    $options = new InstagramOptions(true, Media::image('https://cdn.example.com/c.jpg'), ['a' => 1]);

    expect($options->isStory)->toBeTrue()
        ->and($options->thumbnail->source)->toBe('https://cdn.example.com/c.jpg')
        ->and($options->extra())->toBe(['a' => 1])
        ->and($options->shareToFeed)->toBeNull()
        ->and($options->thumbOffsetMs)->toBeNull()
        ->and($options->collaborators)->toBe([]);
});

it('unserializes options queued by 1.0, with defaults for the new fields', function () {
    $class = InstagramOptions::class;
    $legacy = 'O:'.strlen($class).':"'.$class.'":3:{s:7:"isStory";b:1;s:9:"thumbnail";N;s:5:"extra";a:1:{s:8:"location";s:2:"42";}}';

    $options = unserialize($legacy);

    expect($options)->toBeInstanceOf(InstagramOptions::class)
        ->and($options->isStory)->toBeTrue()
        ->and($options->thumbnail)->toBeNull()
        ->and($options->extra())->toBe(['location' => '42'])
        ->and($options->shareToFeed)->toBeNull()
        ->and($options->thumbOffsetMs)->toBeNull()
        ->and($options->collaborators)->toBe([])
        ->and($options->validate()->toArray())->toBe([]);

    $post = unserialize(serialize(new SocialPost(caption: 'x', options: $options)));

    expect($post->options)->toEqual($options);
});

it('round-trips every field through serialization', function () {
    $options = new InstagramOptions(false, Media::image('https://cdn.example.com/c.jpg'), ['k' => 'v'], true, 1500, ['alice']);

    expect(unserialize(serialize($options)))->toEqual($options);
});
