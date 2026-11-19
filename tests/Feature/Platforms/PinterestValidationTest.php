<?php

namespace SocialPoster\Tests\Feature\Platforms\PinterestValidationTest;

use Illuminate\Support\Facades\Http;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\MediaType;
use SocialPoster\Enums\Platform;
use SocialPoster\Platforms\Instagram\InstagramOptions;
use SocialPoster\Platforms\Pinterest\PinterestDriver;
use SocialPoster\Platforms\Pinterest\PinterestImageTransfer;
use SocialPoster\Platforms\Pinterest\PinterestOptions;
use SocialPoster\SocialManager;
use SocialPoster\Tests\PinterestFixtures\PinterestKit as K;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\PreparedPost;

require_once __DIR__.'/../../Fixtures/pinterest/PinterestKit.php';

afterEach(function () {
    K::cleanupFiles();
});

/** @return array<string, list<string>> */
function errorsFor(PreparedPost $post, array $metadata = [], mixed $gateway = null): array
{
    Http::fake();

    $errors = K::driver($metadata, $gateway)->validate($post)->toArray();

    Http::assertNothingSent();

    return $errors;
}

// --- Capabilities -------------------------------------------------------------------------

it('declares what a Pin can carry', function () {
    $caps = K::driver()->capabilities();

    expect($caps->caption->max)->toBe(800)
        ->and($caps->caption->min)->toBe(0)
        ->and($caps->media->minCount)->toBe(1)
        ->and($caps->media->maxCount)->toBe(5)
        ->and($caps->media->image->extensions)->toBe(['jpg', 'jpeg', 'png', 'webp'])
        ->and($caps->media->image->maxSizeBytes)->toBe(20971520)
        ->and($caps->media->video->extensions)->toBe(['mp4', 'mov', 'm4v'])
        ->and($caps->media->video->maxSizeBytes)->toBe(2147483648)
        ->and($caps->media->video->minDuration)->toBe(4.0)
        ->and($caps->media->video->maxDuration)->toBe(900.0)
        ->and($caps->media->document)->toBeNull()
        ->and($caps->supportsTitle)->toBeTrue()
        ->and($caps->supportsCarousel)->toBeTrue()
        ->and(K::driver()->platform())->toBe(Platform::Pinterest);
});

it('is resolved by the manager with the bound inspector and gateway', function () {
    expect(app(SocialManager::class)->driver('pinterest'))->toBeInstanceOf(PinterestDriver::class);
});

it('reads every media limit from config', function () {
    config([
        'social.api.pinterest.image_max_bytes' => 1000,
        'social.api.pinterest.video_max_bytes' => 2000,
        'social.api.pinterest.video_min_duration' => 1,
        'social.api.pinterest.video_max_duration' => 300,
    ]);

    $caps = K::driver()->capabilities();

    expect($caps->media->image->maxSizeBytes)->toBe(1000)
        ->and($caps->media->video->maxSizeBytes)->toBe(2000)
        ->and($caps->media->video->minDuration)->toBe(1.0)
        ->and($caps->media->video->maxDuration)->toBe(300.0);
});

it('accepts the three Pin shapes', function (array $media) {
    expect(errorsFor(K::post($media)))->toBe([]);
})->with([
    'one image' => [[Media::image(K::IMG_A)]],
    'one remote webp' => [[Media::image('https://cdn.example.com/a.webp')]],
    'a carousel of five' => [[Media::image(K::IMG_A), Media::image(K::IMG_B), Media::image(K::IMG_C), Media::image(K::IMG_A), Media::image(K::IMG_B)]],
    'one video' => [[Media::video('https://cdn.example.com/clip.mp4', 'https://cdn.example.com/cover.jpg')]],
    'one mov' => [[Media::video('https://cdn.example.com/clip.mov')]],
]);

it('needs media, and refuses too many, mixed or unsupported items', function (array $media, string $key, string $message) {
    $errors = errorsFor(K::post($media));

    expect($errors)->toHaveKey($key)
        ->and(implode(' ', $errors[$key]))->toContain($message);
})->with([
    'nothing' => [[], 'media', 'Not enough media items: minimum is 1'],
    'six images' => [array_fill(0, 6, Media::image(K::IMG_A)), 'media', 'maximum is 5'],
    'video with images' => [[Media::image(K::IMG_A), Media::video('https://cdn.example.com/a.mp4')], 'media', 'never both'],
    'video in a carousel is not an image' => [[Media::image(K::IMG_A), Media::video('https://cdn.example.com/a.mp4')], 'media.1', 'video media is not supported'],
    'a document' => [[Media::document('https://cdn.example.com/deck.pdf')], 'media.0', 'document media is not supported'],
    'a gif' => [[Media::image('https://cdn.example.com/a.gif')], 'media.0', 'Unsupported file type .gif'],
    'an avi' => [[Media::video('https://cdn.example.com/a.avi')], 'media.0', 'Unsupported file type .avi'],
]);

it('limits the description to 800 characters of trimmed text, and does not require one', function () {
    expect(errorsFor(K::post([Media::image(K::IMG_A)], caption: '  '.str_repeat('é', 800).'  ')))->toBe([])
        ->and(errorsFor(K::post([Media::image(K::IMG_A)], caption: null, title: null)))->toBe([])
        ->and(errorsFor(K::post([Media::image(K::IMG_A)], caption: str_repeat('a', 801)))['caption'][0])->toContain('800 character limit');
});

it('limits the title to 100 characters', function () {
    expect(errorsFor(K::post([Media::image(K::IMG_A)], title: ' '.str_repeat('t', 100).' ')))->toBe([])
        ->and(errorsFor(K::post([Media::image(K::IMG_A)], title: str_repeat('t', 101)))['title'][0])->toContain('100 characters');
});

it('refuses a caption link too long to be the Pin link, unless the link is set or not derived', function () {
    $caption = 'See https://example.com/'.str_repeat('a', 2100);

    expect(errorsFor(K::post([Media::image(K::IMG_A)], caption: $caption)))->toHaveKey('link')
        ->and(errorsFor(K::post([Media::image(K::IMG_A)], caption: $caption, options: new PinterestOptions(link: 'https://example.com'))))->not->toHaveKey('link')
        ->and(errorsFor(K::post([Media::image(K::IMG_A)], caption: $caption, options: new PinterestOptions(linkFromCaption: false))))->not->toHaveKey('link');
});

it('limits the media alt text to 500 characters unless the options set one', function () {
    $media = [Media::image(K::IMG_A, str_repeat('a', 501))];

    expect(errorsFor(K::post($media))['media.0'][0])->toContain('500 characters')
        ->and(errorsFor(K::post($media, options: new PinterestOptions(altText: 'Short'))))->toBe([]);
});

it('checks that the images can reach Pinterest', function (array $media, ?PinterestOptions $options, ?string $message) {
    $errors = errorsFor(K::post($media, options: $options));

    if ($message === null) {
        expect($errors)->toBe([]);
    } else {
        expect(implode(' ', $errors['media.0'] ?? []))->toContain($message);
    }
})->with(function () {
    return [
        'local webp needs base64' => [[Media::image(K::file('webp'))], null, 'JPG or PNG bytes'],
        'local png goes as base64' => [[Media::image(K::file('png'))], null, null],
        'a local image makes the carousel base64' => [[Media::image('https://cdn.example.com/a.webp'), Media::image(K::file('jpg'))], null, 'host this WEBP publicly'],
        'remote webp in base64 mode' => [[Media::image('https://cdn.example.com/a.webp')], new PinterestOptions(imageTransfer: PinterestImageTransfer::Base64), 'JPG or PNG bytes'],
        'local jpg in url mode' => [[Media::image(K::file('jpg'))], new PinterestOptions(imageTransfer: PinterestImageTransfer::Url), 'fetch images by URL'],
        'remote jpg in url mode' => [[Media::image(K::IMG_A)], new PinterestOptions(imageTransfer: PinterestImageTransfer::Url), null],
    ];
});

it('accepts a local WebP that the gateway re-hosts', function () {
    $webp = K::file('webp');

    expect(errorsFor(K::post([Media::image($webp)]), gateway: K::rehostingGateway([$webp => 'https://media.example.com/a.webp'])))->toBe([]);
});

it('refuses video-only options on an image Pin and carousel overrides outside a carousel', function (array $media, PinterestOptions $options, string $key) {
    expect(errorsFor(K::post($media, options: $options)))->toHaveKey($key);
})->with([
    'cover image' => [[Media::image(K::IMG_A)], new PinterestOptions(thumbnail: Media::image('https://cdn.example.com/cover.jpg')), 'thumbnail'],
    'key frame' => [[Media::image(K::IMG_A)], new PinterestOptions(coverKeyFrameTime: 2), 'cover_key_frame_time'],
    'carousel item on one image' => [[Media::image(K::IMG_A)], new PinterestOptions(carouselItems: [0 => ['title' => 'x']]), 'carousel_items'],
    'carousel item past the end' => [[Media::image(K::IMG_A), Media::image(K::IMG_B)], new PinterestOptions(carouselItems: [3 => ['title' => 'x']]), 'carousel_items'],
]);

it('accepts carousel overrides on a carousel and a cover on a video', function () {
    expect(errorsFor(K::post([Media::image(K::IMG_A), Media::image(K::IMG_B)], options: new PinterestOptions(carouselItems: [1 => ['title' => 'Two', 'link' => 'https://example.com/2']]))))->toBe([])
        ->and(errorsFor(K::post([Media::video('https://cdn.example.com/a.mp4')], options: new PinterestOptions(thumbnail: Media::image('https://cdn.example.com/cover.png')))))->toBe([]);
});

it('checks the board ids it is given, and never requires credentials', function () {
    $media = [Media::image(K::IMG_A)];

    expect(errorsFor(K::post($media, credentials: [])))->toBe([])
        ->and(errorsFor(K::post($media, credentials: ['board_id' => 'acme/spring'])))->toHaveKey('credentials.board_id')
        ->and(errorsFor(K::post($media, credentials: ['account_id' => 'acme'])))->toHaveKey('credentials.board_id')
        ->and(errorsFor(K::post($media, credentials: ['board_id' => K::BOARD, 'board_section_id' => 'x'])))->toHaveKey('credentials.board_section_id');
});

it('runs the deep media checks on inspected metadata', function (Media $media, MediaMetadata $meta, string $message) {
    $errors = errorsFor(K::post([$media]), [$media->source => $meta]);

    expect(implode(' ', $errors['media.0'] ?? []))->toContain($message);
})->with([
    'video too short' => [Media::video('https://cdn.example.com/short.mp4'), new MediaMetadata(durationSeconds: 3.0), 'too short'],
    'video too long' => [Media::video('https://cdn.example.com/long.mp4'), new MediaMetadata(durationSeconds: 901.0), 'too long'],
    'video too big' => [Media::video('https://cdn.example.com/big.mp4'), new MediaMetadata(sizeBytes: 2147483649), 'maximum file size'],
    'video not mp4' => [Media::video('https://cdn.example.com/fake.mp4'), new MediaMetadata(mimeType: 'video/webm'), 'Unsupported video format'],
    'image too big' => [Media::image(K::IMG_A), new MediaMetadata(sizeBytes: 20971521), 'maximum file size'],
]);

it('lets config lower the video duration limit', function () {
    config(['social.api.pinterest.video_max_duration' => 300]);
    $video = Media::video('https://cdn.example.com/clip.mp4');

    expect(errorsFor(K::post([$video]), [$video->source => new MediaMetadata(durationSeconds: 301.0)])['media.0'][0])->toContain('maximum is 300');
});

it('reports missing scopes when the credentials list them', function () {
    $errors = errorsFor(K::post([Media::video('https://cdn.example.com/clip.mp4')], credentials: K::credentials(['scopes' => 'boards:read,pins:read'])));

    expect(implode(' ', $errors['credentials.scopes']))->toContain('boards:write')
        ->and(implode(' ', $errors['credentials.scopes']))->toContain('pins:write');

    expect(errorsFor(K::post([Media::image(K::IMG_A)], credentials: K::credentials(['scopes' => 'boards:read boards:write pins:read pins:write boards:read_secret']))))->toBe([]);
});

it('declares its scopes per feature', function () {
    $driver = K::driver();

    expect($driver->scopesFor(Feature::Publish))->toBe([['boards:read'], ['boards:write'], ['pins:read'], ['pins:write']])
        ->and($driver->scopesFor(Feature::Video))->toBe([['pins:read'], ['pins:write']])
        ->and($driver->scopesFor(Feature::Comment))->toBe([])
        ->and($driver->scopesFor(Feature::Document))->toBe([]);
});

it('identifies the account by board and section, never by the token alone when a board is known', function (array $values, string $expected) {
    expect(K::driver()->accountKey(new Credentials($values)))->toBe($expected);
})->with([
    'board' => [['access_token' => 'a', 'board_id' => K::BOARD], K::BOARD],
    'board and section' => [['access_token' => 'a', 'board_id' => K::BOARD, 'board_section_id' => K::SECTION], K::BOARD.':'.K::SECTION],
    'account_id alias' => [['access_token' => 'a', 'account_id' => ' '.K::BOARD.' '], K::BOARD],
    'alias with section' => [['access_token' => 'a', 'account_id' => K::BOARD, 'board_section_id' => K::SECTION], K::BOARD.':'.K::SECTION],
    'board wins over account_id' => [['access_token' => 'a', 'board_id' => K::BOARD, 'account_id' => '1'], K::BOARD],
    'token only' => [['access_token' => 'a'], ''],
    'non-numeric account_id is not a board' => [['access_token' => 'a', 'account_id' => 'acme'], ''],
]);

it('never uses the token as the key when two boards share it', function () {
    $driver = K::driver();

    expect($driver->accountKey(new Credentials(['access_token' => 't', 'board_id' => '1'])))
        ->not->toBe($driver->accountKey(new Credentials(['access_token' => 't', 'board_id' => '2'])));
});

it('ignores options meant for another platform', function () {
    $options = new InstagramOptions(thumbnail: Media::image('https://cdn.example.com/a.gif'));

    expect(errorsFor(K::post([Media::image(K::IMG_A)], options: $options)))->toBe([]);
});

// --- PinterestOptions --------------------------------------------------------------------

it('validates its options', function (PinterestOptions $options, string $key, string $message) {
    $errors = $options->validate()->toArray();

    expect($errors)->toHaveKey($key)
        ->and(implode(' ', $errors[$key]))->toContain($message);
})->with([
    'link scheme' => [new PinterestOptions(link: 'ftp://example.com'), 'link', 'http:// or https://'],
    'link without host' => [new PinterestOptions(link: 'https://'), 'link', 'http:// or https://'],
    'link too long' => [new PinterestOptions(link: 'https://example.com/'.str_repeat('a', 2048)), 'link', '2048'],
    'alt text' => [new PinterestOptions(altText: str_repeat('a', 501)), 'alt_text', '500'],
    'section' => [new PinterestOptions(boardSectionId: 'spring'), 'board_section_id', 'numeric'],
    'ad account' => [new PinterestOptions(adAccountId: 'act_1'), 'ad_account_id', 'numeric'],
    'negative key frame' => [new PinterestOptions(coverKeyFrameTime: -1), 'cover_key_frame_time', 'negative'],
    'cover and key frame' => [new PinterestOptions(thumbnail: Media::image('https://cdn.example.com/c.jpg'), coverKeyFrameTime: 1), 'cover_key_frame_time', 'not both'],
    'video cover' => [new PinterestOptions(thumbnail: Media::video('https://cdn.example.com/c.mp4')), 'thumbnail', 'must be an image'],
    'gif cover' => [new PinterestOptions(thumbnail: Media::image('https://cdn.example.com/c.gif')), 'thumbnail', 'JPEG or PNG'],
    'item index' => [new PinterestOptions(carouselItems: [5 => ['title' => 'x']]), 'carousel_items', '0 to 4'],
    'item string key' => [new PinterestOptions(carouselItems: ['first' => ['title' => 'x']]), 'carousel_items', '0 to 4'],
    'item not an array' => [new PinterestOptions(carouselItems: [0 => 'x']), 'carousel_items.0', 'must be an array'],
    'item unknown key' => [new PinterestOptions(carouselItems: [0 => ['url' => 'x']]), 'carousel_items.0', 'Unknown Pinterest carousel item keys: url'],
    'item title' => [new PinterestOptions(carouselItems: [0 => ['title' => str_repeat('t', 101)]]), 'carousel_items.0.title', 'at most 100'],
    'item description' => [new PinterestOptions(carouselItems: [1 => ['description' => ['x']]]), 'carousel_items.1.description', 'at most 800'],
    'item link' => [new PinterestOptions(carouselItems: [0 => ['link' => 'example.com']]), 'carousel_items.0.link', 'http:// or https://'],
    'item link type' => [new PinterestOptions(carouselItems: [0 => ['link' => 42]]), 'carousel_items.0.link', 'must be text'],
]);

it('accepts sound options and exposes its platform and extra', function () {
    $options = new PinterestOptions(
        link: 'https://example.com/p',
        altText: 'Alt',
        boardSectionId: K::SECTION,
        thumbnail: Media::image('https://cdn.example.com/c.png'),
        imageTransfer: PinterestImageTransfer::Url,
        carouselItems: [0 => ['title' => 'One', 'description' => 'First', 'link' => 'https://example.com/1']],
        adAccountId: '549755885175',
        extra: ['dominant_color' => '#FFFFFF'],
    );

    expect($options->validate()->toArray())->toBe([])
        ->and($options->platform())->toBe(Platform::Pinterest)
        ->and($options->extra())->toBe(['dominant_color' => '#FFFFFF'])
        ->and((new PinterestOptions)->validate()->isEmpty())->toBeTrue()
        ->and(array_map(fn ($case) => $case->value, PinterestImageTransfer::cases()))->toBe(['auto', 'url', 'base64']);
});

it('merges option errors into the post validation', function () {
    expect(errorsFor(K::post([Media::image(K::IMG_A)], options: new PinterestOptions(link: 'nope'))))->toHaveKey('link');
});

it('treats a thumbnail without extension as acceptable', function () {
    expect((new PinterestOptions(thumbnail: new Media('https://cdn.example.com/cover', MediaType::Image)))->validate()->isEmpty())->toBeTrue();
});
