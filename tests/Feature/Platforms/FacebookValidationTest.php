<?php

namespace SocialPoster\Tests\Feature\Platforms;

use const SocialPoster\Tests\Fixtures\Facebook\FB_PAGE;
use const SocialPoster\Tests\Fixtures\Facebook\FB_TOKEN;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SocialPoster\Contracts\SupportsComments;
use SocialPoster\Contracts\SupportsFirstComment;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Gateways\LocalMediaGateway;
use SocialPoster\Platforms\Facebook\FacebookDriver;
use SocialPoster\Platforms\Facebook\FacebookOptions;
use SocialPoster\SocialManager;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\SocialPost;

use function SocialPoster\Tests\Fixtures\Facebook\fbDriver;
use function SocialPoster\Tests\Fixtures\Facebook\fbFake;
use function SocialPoster\Tests\Fixtures\Facebook\fbPost;
use function SocialPoster\Tests\Fixtures\Facebook\fbTempFile;

require_once __DIR__.'/../../Fixtures/facebook/helpers.php';

beforeEach(fn () => config(['social.api.facebook.graph_version' => 'v25.0']));

/** The validation errors for a post, flattened to "key: message". */
function fbErrors(FacebookDriver $driver, PreparedPost $post): array
{
    $errors = [];

    foreach ($driver->validate($post)->toArray() as $key => $messages) {
        foreach ($messages as $message) {
            $errors[] = "{$key}: {$message}";
        }
    }

    return $errors;
}

it('needs a caption on a text post and media on a story', function () {
    $driver = fbDriver();

    expect(fbErrors($driver, fbPost(caption: '   ')))->toContain("caption: Facebook posts can't have an empty caption.")
        ->and(fbErrors($driver, fbPost(options: new FacebookOptions(isStory: true))))->toContain("media: You don't have any media attached for this Facebook story.")
        ->and(fbErrors($driver, fbPost()))->toBe([]);
});

it('caps multi-photo posts at max_attached_media and refuses video in them', function () {
    $driver = fbDriver();
    $photos = array_map(fn ($i) => Media::image("https://cdn.example.com/{$i}.jpg"), range(1, 11));

    expect(fbErrors($driver, fbPost(media: $photos)))->toContain('media: Too many media items: maximum is 10, got 11.');

    config(['social.api.facebook.max_attached_media' => 20]);
    expect(fbErrors($driver, fbPost(media: $photos)))->toBe([])
        ->and($driver->capabilities()->media->maxCount)->toBe(20)
        ->and($driver->capabilities()->supportsTitle)->toBeTrue();

    expect(fbErrors($driver, fbPost(media: [Media::image('https://cdn.example.com/a.jpg'), Media::video('https://cdn.example.com/b.mp4')])))
        ->toContain('media.1: video media is not supported here on facebook.');
});

it('accepts bmp photos and applies the feed and story photo size limits', function () {
    $driver = fbDriver([
        'https://cdn.example.com/a.bmp' => new MediaMetadata(sizeBytes: 1000),
        'https://cdn.example.com/big.jpg' => new MediaMetadata(sizeBytes: 9 * 1048576),
        'https://cdn.example.com/huge.jpg' => new MediaMetadata(sizeBytes: 11 * 1048576),
    ]);

    expect(fbErrors($driver, fbPost(media: [Media::image('https://cdn.example.com/a.bmp')])))->toBe([])
        ->and(fbErrors($driver, fbPost(media: [Media::image('https://cdn.example.com/a.webp')])))->toHaveCount(1)
        ->and(fbErrors($driver, fbPost(media: [Media::image('https://cdn.example.com/big.jpg')])))->toContain('media.0: Image exceeds the maximum file size.')
        ->and(fbErrors($driver, fbPost(media: [Media::image('https://cdn.example.com/big.jpg')], options: new FacebookOptions(isStory: true))))->toBe([])
        ->and(fbErrors($driver, fbPost(media: [Media::image('https://cdn.example.com/huge.jpg')], options: new FacebookOptions(isStory: true))))->toContain('media.0: Image exceeds the maximum file size.');
});

it('requires 9:16 story videos at 24-60 fps, without a maximum resolution', function (array $meta, ?string $error) {
    $driver = fbDriver(['https://cdn.example.com/s.mp4' => new MediaMetadata(...$meta)]);
    $errors = fbErrors($driver, fbPost(media: [Media::video('https://cdn.example.com/s.mp4')], options: new FacebookOptions(isStory: true)));

    $error === null ? expect($errors)->toBe([]) : expect(implode("\n", $errors))->toContain($error);
})->with([
    'vertical 1080p' => [['width' => 1080, 'height' => 1920, 'durationSeconds' => 20, 'frameRate' => 30], null],
    'encoder rounding' => [['width' => 1080, 'height' => 1918, 'durationSeconds' => 20, 'frameRate' => 29.97], null],
    '4K vertical' => [['width' => 2160, 'height' => 3840, 'durationSeconds' => 20, 'frameRate' => 60], null],
    'square' => [['width' => 1080, 'height' => 1080, 'durationSeconds' => 20, 'frameRate' => 30], 'aspect ratio'],
    'slow' => [['width' => 1080, 'height' => 1920, 'durationSeconds' => 20, 'frameRate' => 15], 'below the 24 fps minimum'],
    'too long' => [['width' => 1080, 'height' => 1920, 'durationSeconds' => 75, 'frameRate' => 30], 'maximum is 60'],
]);

it('applies Reel rules to vertical videos and regular rules to the rest', function () {
    $driver = fbDriver([
        'https://cdn.example.com/reel.mp4' => new MediaMetadata(width: 2160, height: 3840, durationSeconds: 100, frameRate: 30),
        'https://cdn.example.com/wide.mp4' => new MediaMetadata(width: 1920, height: 1080, durationSeconds: 100, frameRate: 30),
        'https://cdn.example.com/fast.mp4' => new MediaMetadata(width: 1080, height: 1920, durationSeconds: 30, frameRate: 120),
    ]);

    expect(fbErrors($driver, fbPost(media: [Media::video('https://cdn.example.com/reel.mp4')])))->toContain('media.0: Video is too long: maximum is 90s.')
        ->and(fbErrors($driver, fbPost(media: [Media::video('https://cdn.example.com/reel.mp4')], options: new FacebookOptions(asReel: false))))->toBe([])
        ->and(fbErrors($driver, fbPost(media: [Media::video('https://cdn.example.com/wide.mp4')])))->toBe([])
        ->and(fbErrors($driver, fbPost(media: [Media::video('https://cdn.example.com/wide.mp4')], options: new FacebookOptions(asReel: true))))->toContain('media.0: Video is too long: maximum is 90s.')
        ->and(implode("\n", fbErrors($driver, fbPost(media: [Media::video('https://cdn.example.com/fast.mp4')]))))->toContain('above the 60 fps maximum');

    config(['social.api.facebook.reel_max_duration' => 120]);
    expect(fbErrors($driver, fbPost(media: [Media::video('https://cdn.example.com/reel.mp4')])))->toBe([]);
});

it('validates the custom thumbnail of a regular video', function () {
    $driver = fbDriver([
        'https://cdn.example.com/wide.mp4' => new MediaMetadata(width: 1920, height: 1080),
        'https://cdn.example.com/reel.mp4' => new MediaMetadata(width: 1080, height: 1920),
        'https://cdn.example.com/signed-thumb' => new MediaMetadata(mimeType: 'image/png'),
        'https://cdn.example.com/huge.png' => new MediaMetadata(sizeBytes: 11 * 1048576, mimeType: 'image/png'),
    ]);
    $wide = [Media::video('https://cdn.example.com/wide.mp4')];
    $thumb = fn (string $url) => new FacebookOptions(thumbnail: Media::image($url));

    expect(fbErrors($driver, fbPost(media: $wide, options: $thumb('https://cdn.example.com/t.jpg'))))->toBe([])
        ->and(fbErrors($driver, fbPost(media: $wide, options: $thumb('https://cdn.example.com/signed-thumb'))))->toBe([])
        ->and(fbErrors($driver, fbPost(media: $wide, options: $thumb('https://cdn.example.com/t.webp'))))->toBe(['thumbnail: Facebook video thumbnails must be one of: bmp, gif, jpeg, jpg, png, tiff.'])
        ->and(implode("\n", fbErrors($driver, fbPost(media: $wide, options: $thumb('https://cdn.example.com/unknown')))))->toContain('no file extension')
        ->and(fbErrors($driver, fbPost(media: $wide, options: $thumb('https://cdn.example.com/huge.png'))))->toBe(['thumbnail: Facebook video thumbnails must be at most 10MB.']);
});

it('never fails a post over the cross-platform Media::video() thumbnail (it is best-effort on Facebook)', function () {
    $driver = fbDriver(['https://cdn.example.com/wide.mp4' => new MediaMetadata(width: 1920, height: 1080)]);

    // v1.0 never read the Media thumbnail on Facebook: a webp or unconfirmable
    // cover meant for another platform in a fan-out must not block this one.
    expect(fbErrors($driver, fbPost(media: [Media::video('https://cdn.example.com/wide.mp4', thumbnail: 'https://cdn.example.com/t.webp')])))->toBe([])
        ->and(fbErrors($driver, fbPost(media: [Media::video('https://cdn.example.com/wide.mp4', thumbnail: 'https://cdn.example.com/signed-cover')])))->toBe([]);
});

it('ignores a FacebookOptions thumbnail on a Reel, story, photo or text post, as v1.0 did', function () {
    $driver = fbDriver(['https://cdn.example.com/reel.mp4' => new MediaMetadata(width: 1080, height: 1920, durationSeconds: 30, frameRate: 30)]);
    $cover = new FacebookOptions(false, Media::image('https://cdn.example.com/cover.jpg'));

    // The v1.0 README calls this the Reel cover and reuses one options object
    // across posts; v1.0 published them and simply did not send the thumbnail.
    expect(fbErrors($driver, fbPost(media: [Media::video('https://cdn.example.com/reel.mp4')], options: $cover)))->toBe([])
        ->and(fbErrors($driver, fbPost(options: $cover)))->toBe([])
        ->and(fbErrors($driver, fbPost(media: [Media::image('https://cdn.example.com/p.jpg')], options: $cover)))->toBe([])
        ->and(fbErrors($driver, fbPost(media: [Media::image('https://cdn.example.com/s.jpg')], options: new FacebookOptions(true, Media::image('https://cdn.example.com/cover.webp')))))->toBe([]);
});

it('asks for a scheduled Reel to be far enough ahead to cover the upload and the finish delay', function () {
    $driver = fbDriver([
        'https://cdn.example.com/reel.mp4' => new MediaMetadata(width: 1080, height: 1920, durationSeconds: 30, frameRate: 30),
        'https://cdn.example.com/wide.mp4' => new MediaMetadata(width: 1920, height: 1080, durationSeconds: 30, frameRate: 30),
    ]);
    $at = fn (int $minutes) => new FacebookOptions(scheduledAt: CarbonImmutable::now()->addMinutes($minutes));

    // The finish (which carries scheduled_publish_time) is sent finish_delay
    // and the upload checks later, and Facebook wants more than 10 minutes then.
    expect(fbErrors($driver, fbPost(media: [Media::video('https://cdn.example.com/reel.mp4')], options: $at(11))))
        ->toBe(['scheduledAt: Facebook needs a scheduled Reel to be at least 15 minutes in the future, to leave time for the upload.'])
        ->and(fbErrors($driver, fbPost(media: [Media::video('https://cdn.example.com/reel.mp4')], options: $at(16))))->toBe([])
        ->and(fbErrors($driver, fbPost(media: [Media::video('https://cdn.example.com/wide.mp4')], options: $at(11))))->toBe([])
        ->and(fbErrors($driver, fbPost(options: $at(11))))->toBe([]);

    config(['social.api.facebook.finish_delay' => 600]);
    expect(implode("\n", fbErrors($driver, fbPost(media: [Media::video('https://cdn.example.com/reel.mp4')], options: $at(16)))))->toContain('at least 24 minutes');
});

it('reads the token from an overridden v1.0 $tokenKey first', function () {
    fbFake([
        'POST *v25.0/'.FB_PAGE.'/feed' => Http::response(['id' => FB_PAGE.'_1']),
        'GET *' => Http::response(['permalink_url' => 'https://www.facebook.com/x']),
    ]);
    $driver = new class extends FacebookDriver
    {
        protected string $tokenKey = 'fb_page_token';

        public function key(Credentials $credentials): string
        {
            return $this->token(new PreparedPost(Platform::Facebook, new SocialPost, $credentials));
        }
    };

    $post = fbPost(credentials: ['account_id' => FB_PAGE, 'fb_page_token' => 'EAAOverridden']);
    $driver->resume($post, $driver->publish($post)->state);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer EAAOverridden'));
    expect($driver->key(new Credentials(['fb_page_token' => 'A', 'page_access_token' => 'B'])))->toBe('A')
        ->and($driver->key(new Credentials(['page_access_token' => 'B'])))->toBe('B')
        ->and(fn () => $driver->publish(fbPost(credentials: ['account_id' => FB_PAGE])))
        ->toThrow(fn (PermanentException $e) => expect($e->context['missing_credentials'])->toBe(['fb_page_token|page_access_token|access_token']));
});

it('lets a local video through only as a Reel or a story', function () {
    $file = fbTempFile('mp4');
    $photo = fbTempFile('jpg');
    $driver = fbDriver([$file => new MediaMetadata(width: 1080, height: 1920)], new LocalMediaGateway);

    expect(fbErrors($driver, fbPost(media: [Media::video($file)])))->toBe([])
        ->and(fbErrors($driver, fbPost(media: [Media::video($file)], options: new FacebookOptions(isStory: true))))->toBe([])
        ->and(fbErrors($driver, fbPost(media: [Media::video($file)], options: new FacebookOptions(asReel: false))))->toHaveCount(1)
        ->and(fbErrors($driver, fbPost(media: [Media::image($photo)])))->toHaveCount(1);

    config(['social.api.facebook.local_video_upload' => false]);
    expect(fbErrors($driver, fbPost(media: [Media::video($file)])))->toHaveCount(1);
});

it('checks the publish scope when the credentials list their scopes', function () {
    $driver = fbDriver();

    expect(fbErrors($driver, fbPost(credentials: ['account_id' => FB_PAGE, 'page_access_token' => FB_TOKEN, 'scopes' => 'pages_show_list,pages_read_engagement'])))
        ->toBe(['credentials.scopes: The Facebook token is missing a permission needed to publish: grant pages_manage_posts.'])
        ->and(fbErrors($driver, fbPost(credentials: ['account_id' => FB_PAGE, 'page_access_token' => FB_TOKEN, 'scopes' => ['pages_manage_posts']])))->toBe([])
        ->and($driver->scopesFor(Feature::Publish))->toBe([['pages_manage_posts']])
        ->and($driver->scopesFor(Feature::Comment))->toBe([['pages_manage_engagement'], ['pages_read_user_content']])
        ->and($driver->scopesFor(Feature::Video))->toBe([]);
});

it('validates the options on their own', function (FacebookOptions $options, ?string $field) {
    $errors = $options->validate();

    $field === null ? expect($errors->isEmpty())->toBeTrue() : expect($errors->has($field))->toBeTrue();
})->with([
    'tomorrow' => [new FacebookOptions(scheduledAt: CarbonImmutable::now()->addDay()), null],
    'too soon' => [new FacebookOptions(scheduledAt: CarbonImmutable::now()->addMinutes(5)), 'scheduledAt'],
    'too far' => [new FacebookOptions(scheduledAt: CarbonImmutable::now()->addDays(30)), 'scheduledAt'],
    'story' => [new FacebookOptions(isStory: true, scheduledAt: CarbonImmutable::now()->addDay()), 'scheduledAt'],
    'blank link' => [new FacebookOptions(link: ' '), 'link'],
    'not a url' => [new FacebookOptions(link: 'http://'), 'link'],
    'bare domain link' => [new FacebookOptions(link: 'example.com/page'), null],
    'no link card' => [new FacebookOptions(link: false), null],
]);

it('reports option errors through the driver validation', function () {
    expect(fbErrors(fbDriver(), fbPost(options: new FacebookOptions(link: ''))))->toHaveCount(1);
});

it('restores v1.0 serialized options with defaults for the new fields', function () {
    $legacy = 'O:'.strlen(FacebookOptions::class).':"'.FacebookOptions::class.'":3:{s:7:"isStory";b:1;s:9:"thumbnail";N;s:5:"extra";a:1:{s:5:"place";s:2:"P1";}}';

    $options = unserialize($legacy);

    expect($options)->toBeInstanceOf(FacebookOptions::class)
        ->and($options->isStory)->toBeTrue()
        ->and($options->extra())->toBe(['place' => 'P1'])
        ->and($options->asReel)->toBeNull()
        ->and($options->link)->toBeNull()
        ->and($options->scheduledAt)->toBeNull();

    $current = new FacebookOptions(false, Media::image('https://cdn.example.com/t.jpg'), [], true, false, CarbonImmutable::now()->addDay());
    $copy = unserialize(serialize($current));

    expect($copy->asReel)->toBeTrue()
        ->and($copy->link)->toBeFalse()
        ->and($copy->thumbnail?->source)->toBe('https://cdn.example.com/t.jpg')
        ->and($copy->scheduledAt?->getTimestamp())->toBe($current->scheduledAt?->getTimestamp());
});

it('fails fast without the Page id or a token, before any request', function (array $credentials, string $missing) {
    fbFake([]);
    $driver = fbDriver();

    expect(fn () => $driver->publish(fbPost(credentials: $credentials)))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::Misconfigured)
            ->and($e->context['missing_credentials'])->toBe([$missing]))
        ->and(fn () => $driver->resume(fbPost(credentials: $credentials), ['phase' => 'feed_create']))
        ->toThrow(PermanentException::class);

    Http::assertNothingSent();
})->with([
    'no page id' => [['page_access_token' => FB_TOKEN], 'account_id'],
    'no token' => [['account_id' => FB_PAGE, 'page_access_token' => ' '], 'page_access_token|access_token'],
]);

it('accepts the token under access_token and prefers page_access_token', function () {
    fbFake(['POST *v25.0/'.FB_PAGE.'/feed' => Http::response(['id' => FB_PAGE.'_1']), 'GET *' => Http::response(['permalink_url' => 'https://www.facebook.com/x'])]);
    $driver = fbDriver();

    $post = fbPost(credentials: ['account_id' => FB_PAGE, 'access_token' => 'EAAUserFallback']);
    $driver->resume($post, $driver->publish($post)->state);
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer EAAUserFallback'));

    $both = fbPost(credentials: ['account_id' => FB_PAGE, 'access_token' => 'EAAUser', 'page_access_token' => 'EAAPage']);
    expect($driver->call('token', $both))->toBe('EAAPage');
});

it('identifies the account by its Page id, never by the token', function () {
    $driver = fbDriver();

    expect($driver->accountKey(new Credentials(['account_id' => FB_PAGE, 'page_access_token' => 'a'])))->toBe(FB_PAGE)
        ->and($driver->accountKey(new Credentials(['account_id' => FB_PAGE, 'page_access_token' => 'b'])))->toBe(FB_PAGE);
});

it('defaults to Graph v25.0 and follows the configured version', function () {
    config(['social.api.facebook' => []]);
    expect(fbDriver()->call('graphBase'))->toBe('https://graph.facebook.com/v25.0');

    config(['social.api.facebook.graph_version' => 'v26.0']);
    expect(fbDriver()->call('graphBase'))->toBe('https://graph.facebook.com/v26.0');
});

it('is registered as a first-comment driver', function () {
    $driver = app(SocialManager::class)->platform('facebook');

    expect($driver)->toBeInstanceOf(FacebookDriver::class)
        ->and($driver)->toBeInstanceOf(SupportsFirstComment::class)
        ->and($driver)->toBeInstanceOf(SupportsComments::class);
});
