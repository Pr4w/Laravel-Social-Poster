<?php

use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\Platform;
use SocialPoster\Platforms\X\XOptions;
use SocialPoster\Platforms\X\XPoll;
use SocialPoster\Platforms\X\XReplySettings;
use SocialPoster\Poster;
use SocialPoster\SocialManager;
use SocialPoster\Support\Text\XWeightedCounter;
use SocialPoster\Tests\Fixtures\x\XFixtures;
use SocialPoster\Tests\Fixtures\x\XTestDriver;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\RawOptions;

afterEach(fn () => XFixtures::cleanup());

/** @param  array<string, mixed>  $meta */
function xVideo(array $meta): Media
{
    return Media::video(XFixtures::file('mp4'), meta: $meta + ['size' => 1000]);
}

/**
 * Drop the video limits from config('social.api.x'), so the driver's own
 * defaults apply (the published config file may pin other values).
 */
function xDriverVideoDefaults(): void
{
    $x = (array) config('social.api.x', []);
    unset($x['max_video_bytes'], $x['max_video_duration'], $x['min_video_duration']);
    config(['social.api.x' => $x]);
}

/** Validation messages for a post, keyed by field. */
function xErrors(array $media = [], ?string $caption = 'Hello', ...$named): array
{
    return (new XTestDriver)->validate(XFixtures::post(...['media' => $media, 'caption' => $caption, ...$named]))->toArray();
}

// --- Capabilities ---------------------------------------------------------------------------

it('advertises X capabilities: weighted 280, photos and GIFs, realistic video', function () {
    xDriverVideoDefaults();
    $caps = (new XTestDriver)->capabilities();

    expect($caps->caption->max)->toBe(280)
        ->and($caps->caption->counter)->toBeInstanceOf(XWeightedCounter::class)
        ->and($caps->media->maxCount)->toBe(4)
        ->and($caps->media->image->extensions)->toBe(['jpg', 'jpeg', 'png', 'webp', 'gif'])
        ->and($caps->media->image->maxSizeBytes)->toBe(15728640)
        ->and($caps->media->video->extensions)->toBe(['mp4'])
        ->and($caps->media->video->maxWidth)->toBeNull()
        ->and($caps->media->video->maxHeight)->toBeNull()
        ->and($caps->media->video->minWidth)->toBe(32)
        ->and($caps->media->video->minHeight)->toBe(32)
        ->and($caps->media->video->aspectRatioRange)->toBe([1 / 3, 3.0])
        ->and($caps->media->video->minDuration)->toBe(0.5)
        // X's documented post video limit for accounts without Premium: 20 min, 8 GB
        // (140 s / 512 MB are its DM video limits).
        ->and($caps->media->video->maxDuration)->toBe(1200.0)
        ->and($caps->media->video->maxSizeBytes)->toBe(8589934592);
});

it('accepts post videos above the DM limits (140s, 512MB), up to X default post limits', function () {
    xDriverVideoDefaults();

    expect(xErrors([xVideo(['width' => 1280, 'height' => 720, 'duration' => 180])]))->toBe([])
        ->and(xErrors([xVideo(['width' => 1280, 'height' => 720, 'duration' => 1200])]))->toBe([])
        ->and(xErrors([xVideo(['width' => 1280, 'height' => 720, 'duration' => 600, 'size' => 2 * 1024 ** 3])]))->toBe([]);
});

it('reads every limit from api.x config', function () {
    config(['social.api.x' => [
        'max_characters' => 25000,
        'max_video_duration' => 600,
        'min_video_duration' => 1,
        'max_video_bytes' => 1073741824,
        'max_image_bytes' => 1000,
        'max_gif_bytes' => 2000,
        'min_video_dimension' => 64,
    ]]);

    $caps = (new XTestDriver)->capabilities();

    expect($caps->caption->max)->toBe(25000)
        ->and($caps->media->video->maxDuration)->toBe(600.0)
        ->and($caps->media->video->minDuration)->toBe(1.0)
        ->and($caps->media->video->maxSizeBytes)->toBe(1073741824)
        ->and($caps->media->video->minWidth)->toBe(64)
        ->and($caps->media->image->maxSizeBytes)->toBe(2000);
});

it('declares its scopes per feature', function () {
    $driver = new XTestDriver;

    // Publish covers a post with any media, so a connect flow built from it
    // asks for media.write (every image, GIF and video upload needs it).
    expect($driver->scopesFor(Feature::Publish))->toBe([['tweet.read'], ['tweet.write'], ['users.read'], ['media.write']])
        ->and($driver->scopesFor(Feature::Comment))->toBe([['tweet.read'], ['tweet.write'], ['users.read']])
        ->and($driver->scopesFor(Feature::Video))->toBe([['media.write']])
        ->and($driver->scopesFor(Feature::Document))->toBe([]);
});

it('reports media.write through the public scope API used to build connect URLs', function () {
    expect(app(Poster::class)->requiredScopes(Platform::X, Feature::Publish))->toContain(['media.write']);
});

// --- Text -----------------------------------------------------------------------------------

it('requires text by default', function () {
    expect(xErrors(caption: '  '))->toHaveKey('caption')
        ->and(xErrors([Media::image(XFixtures::png())], caption: null))->toHaveKey('caption');
});

it('allows media-only posts when api.x.require_text is false, but never an empty text post', function () {
    config(['social.api.x.require_text' => false]);

    expect(xErrors([Media::image(XFixtures::png())], caption: ''))->toBe([])
        ->and(xErrors(caption: ''))->toHaveKey('caption');
});

it('counts the caption the way X does', function () {
    expect(xErrors(caption: str_repeat('a', 280)))->toBe([])
        ->and(xErrors(caption: str_repeat('😀', 140)))->toBe([])
        // A long link counts 23, so this is 274 for X (and 371 code points).
        ->and(xErrors(caption: str_repeat('a', 250).' https://example.com/'.str_repeat('x', 100)))->toBe([]);

    $errors = xErrors(caption: str_repeat('😀', 141));

    expect($errors['caption'][0])->toContain('282')
        ->and($errors['caption'][0])->toContain('each link as 23');
    expect(xErrors(caption: str_repeat('a', 281)))->toHaveKey('caption');
});

// --- Media ----------------------------------------------------------------------------------

it('accepts 1080p, vertical and 4K video', function (int $width, int $height) {
    expect(xErrors([xVideo(['width' => $width, 'height' => $height, 'duration' => 30])]))->toBe([]);
})->with([
    'landscape 1080p' => [1920, 1080],
    'vertical 1080p' => [1080, 1920],
    '4K' => [3840, 2160],
    'square' => [720, 720],
    'exactly 1:3' => [360, 1080],
]);

it('rejects video outside X limits', function (array $meta, string $message) {
    xDriverVideoDefaults();
    $errors = xErrors([xVideo($meta)]);

    expect(implode(' ', $errors['media.0'] ?? []))->toContain($message);
})->with([
    'too wide' => [['width' => 4000, 'height' => 1000, 'duration' => 30], 'aspect ratio'],
    'too small' => [['width' => 20, 'height' => 20, 'duration' => 30], 'below the 32px minimum'],
    'too long' => [['width' => 1280, 'height' => 720, 'duration' => 1201], 'maximum is 1200s'],
    'too short' => [['width' => 1280, 'height' => 720, 'duration' => 0.2], 'minimum is 0.5s'],
    'too big' => [['width' => 1280, 'height' => 720, 'duration' => 30, 'size' => 9 * 1024 ** 3], 'maximum file size'],
]);

it('lets Premium limits through when configured', function () {
    config(['social.api.x.max_video_duration' => 7500, 'social.api.x.max_video_bytes' => 16 * 1024 ** 3]);

    expect(xErrors([xVideo(['width' => 1280, 'height' => 720, 'duration' => 3600, 'size' => 12 * 1024 ** 3])]))->toBe([]);
});

it('keeps a product limit stricter than X when configured', function () {
    config(['social.api.x.max_video_duration' => 140]);

    expect(implode(' ', xErrors([xVideo(['width' => 1280, 'height' => 720, 'duration' => 200])])['media.0'] ?? []))->toContain('maximum is 140s');
});

it('validates against the bound inspector', function () {
    xDriverVideoDefaults();
    app()->instance(MediaInspector::class, new class implements MediaInspector
    {
        public function supports(Media $media): bool
        {
            return true;
        }

        public function inspect(Media $media): MediaMetadata
        {
            return new MediaMetadata(sizeBytes: 5_000_000, width: 3840, height: 2160, durationSeconds: 1500.0);
        }
    });

    $driver = app(SocialManager::class)->driver('x');
    $errors = $driver->validate(XFixtures::post(media: [Media::video(XFixtures::file('mp4'))]))->toArray();

    expect($errors['media.0'])->toBe(['Video is too long: maximum is 1200s.']);
});

it('explains that a GIF or a video cannot share a post', function () {
    $errors = xErrors([Media::image(XFixtures::png()), Media::image(XFixtures::file('gif'))]);

    expect($errors)->toBe(['media.1' => ["X takes one animated GIF on its own; it can't be combined with other images."]]);

    $errors = xErrors([Media::image(XFixtures::png()), Media::video(XFixtures::file('mp4'))]);

    expect($errors)->toBe(['media.1' => ["X takes one video on its own; it can't be combined with other media."]]);
});

it('takes up to four photos', function () {
    $photos = fn (int $count) => array_map(fn () => Media::image(XFixtures::file('webp')), range(1, $count));

    expect(xErrors($photos(4)))->toBe([])
        ->and(xErrors($photos(5))['media'][0])->toContain('maximum is 4');
});

it('applies the GIF and photo size limits', function () {
    expect(xErrors([Media::image(XFixtures::file('gif'), meta: ['size' => 10 * 1024 * 1024])]))->toBe([])
        ->and(xErrors([Media::image(XFixtures::file('gif'), meta: ['size' => 16 * 1024 * 1024])]))->toHaveKey('media.0')
        ->and(xErrors([Media::image(XFixtures::file('jpg'), meta: ['size' => 6 * 1024 * 1024])]))->toHaveKey('media.0')
        ->and(xErrors([Media::image(XFixtures::file('bmp'))]))->toHaveKey('media.0');
});

it('limits alt text to 1000 characters', function () {
    expect(xErrors([Media::image(XFixtures::png(), str_repeat('a', 1000))]))->toBe([]);

    $errors = xErrors([Media::image(XFixtures::png(), str_repeat('a', 1001))]);

    expect($errors['media.0'][0])->toContain('1000 character limit');
});

// --- Options ----------------------------------------------------------------------------

it('validates XOptions', function () {
    $options = new XOptions(
        replySettings: 'everyone',
        quoteTweetId: 'abc',
        replyToTweetId: '12',
        poll: new XPoll(['Only one option that is far too long'], 2),
    );

    $errors = xErrors(options: $options);

    expect($errors['options.reply_settings'][0])->toContain("Unknown X reply setting 'everyone'")
        ->and($errors['options.quote_tweet_id'][0])->toContain('numeric post id')
        ->and($errors)->not->toHaveKey('options.reply_to_tweet_id')
        ->and($errors['options.poll'])->toHaveCount(4)
        ->and(implode(' ', $errors['options.poll']))->toContain('2 to 4 options')
        ->and(implode(' ', $errors['options.poll']))->toContain('X allows 25')
        ->and(implode(' ', $errors['options.poll']))->toContain('between 5 and 10080 minutes')
        ->and(implode(' ', $errors['options.poll']))->toContain('quote post');
});

it('accepts valid XOptions', function () {
    $options = new XOptions(XReplySettings::Following, null, '1880000000000000002', XPoll::make(['A', 'B', 'C'], 10080));

    expect(xErrors(options: $options))->toBe([])
        ->and($options->platform())->toBe(Platform::X)
        ->and($options->toPayload())->toBe([
            'reply_settings' => 'following',
            'reply' => ['in_reply_to_tweet_id' => '1880000000000000002'],
            'poll' => ['options' => ['A', 'B', 'C'], 'duration_minutes' => 10080],
        ]);
});

it('leaves blank option fields out of the payload', function () {
    $options = new XOptions(replySettings: ' ', quoteTweetId: '', replyToTweetId: '  ');

    expect($options->toPayload())->toBe([])
        ->and($options->replySettingsValue())->toBeNull()
        ->and($options->validate()->isEmpty())->toBeTrue()
        ->and((new XOptions(extra: ['geo' => ['place_id' => 'abc']]))->extra())->toBe(['geo' => ['place_id' => 'abc']]);
});

it('refuses quote posts up front when api.x.allow_quote_posts is false (Enterprise-only on X)', function () {
    expect(xErrors(options: new XOptions(quoteTweetId: '1880000000000000001')))->toBe([]);

    config(['social.api.x.allow_quote_posts' => false]);

    $typed = xErrors(options: new XOptions(quoteTweetId: '1880000000000000001'));
    $raw = xErrors(options: new RawOptions(Platform::X, ['quote_tweet_id' => '1880000000000000001']));
    $extra = xErrors(options: new XOptions(quoteTweetId: ' ', extra: ['quote_tweet_id' => '1880000000000000001']));

    expect($typed['options.quote_tweet_id'][0])->toContain('Enterprise')
        ->and($raw['options.quote_tweet_id'][0])->toContain('Enterprise')
        ->and($extra['options.quote_tweet_id'][0])->toContain('Enterprise')
        ->and(xErrors(options: new XOptions(quoteTweetId: ' ')))->toBe([])
        ->and(xErrors(options: new XOptions(replyToTweetId: '1880000000000000002')))->toBe([]);
});

it('flags an empty poll option and a poll with media', function () {
    $errors = xErrors([Media::image(XFixtures::png())], options: new XOptions(poll: new XPoll(['Yes', '  '])));

    expect($errors['options.poll'])->toContain('Poll option #2 is empty.')
        ->and($errors['options.poll'])->toContain("X polls can't be combined with media.");
});

// --- First comment and scopes -----------------------------------------------------------------

it('validates the first comment with the weighted count', function () {
    expect(xErrors(comment: str_repeat('a', 280)))->toBe([])
        ->and(xErrors(comment: '   '))->toBe([]);

    $errors = xErrors(comment: str_repeat('😀', 141));

    expect($errors['comment'][0])->toContain('282');
});

it('checks the granted scopes when the credentials list them', function () {
    $partial = ['scopes' => 'tweet.read users.read'];
    $full = ['scopes' => ['tweet.read', 'tweet.write', 'users.read', 'offline.access']];
    $withMedia = ['scopes' => 'tweet.read tweet.write users.read media.write'];

    expect(xErrors(credentials: $partial)['credentials.scopes'][0])->toContain('tweet.write')
        ->and(xErrors(credentials: $full))->toBe([])
        ->and(xErrors([Media::image(XFixtures::png())], credentials: $full)['credentials.scopes'][0])->toContain('media.write')
        ->and(xErrors([xVideo(['width' => 1280, 'height' => 720, 'duration' => 10])], credentials: $full)['credentials.scopes'])->toHaveCount(1)
        ->and(xErrors([Media::image(XFixtures::png())], credentials: $withMedia))->toBe([])
        // Unknown scopes are never an error.
        ->and(xErrors([Media::image(XFixtures::png())]))->toBe([]);

    // Each missing permission is reported once, with what it is needed for.
    expect(xErrors([Media::image(XFixtures::file('gif'))], credentials: $partial)['credentials.scopes'])->toBe([
        'The X token is missing a permission needed to publish: grant tweet.write.',
        'The X token is missing a permission needed to upload media: grant media.write.',
    ])->and(xErrors([xVideo(['width' => 1280, 'height' => 720, 'duration' => 10])], credentials: $full)['credentials.scopes'])->toBe([
        'The X token is missing a permission needed to upload media: grant media.write.',
    ]);
});
