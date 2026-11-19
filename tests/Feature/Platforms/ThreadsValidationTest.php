<?php

namespace SocialPoster\Tests\Feature\Platforms\ThreadsValidationTest;

use Illuminate\Support\Facades\Http;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\Platform;
use SocialPoster\Platforms\Threads\ThreadsOptions;
use SocialPoster\Platforms\Threads\ThreadsPoll;
use SocialPoster\Platforms\Threads\ThreadsReplyControl;
use SocialPoster\Support\Text\ThreadsCounter;
use SocialPoster\Tests\ThreadsFixtures\ThreadsKit as K;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\RawOptions;

require_once __DIR__.'/../../Fixtures/threads/ThreadsKit.php';

const PIC = 'https://cdn.example.com/p.jpg';
const MOV = 'https://cdn.example.com/m.mov';

/** @return array<string, list<string>> */
function errorsFor(PreparedPost $post, array $metadata = []): array
{
    return K::driver($metadata)->validate($post)->toArray();
}

it('accepts plain posts without any request', function () {
    Http::fake();

    expect(errorsFor(K::post()))->toBe([])
        ->and(errorsFor(K::post([Media::image(PIC)], null)))->toBe([])
        ->and(errorsFor(K::post([Media::image(PIC), Media::video(MOV)], 'Two')))->toBe([]);

    Http::assertNothingSent();
});

it('needs text or media', function () {
    expect(errorsFor(K::post(caption: '   ')))->toHaveKey('caption');
});

it('counts emoji as their UTF-8 bytes against the 500 limit', function () {
    $plain = str_repeat('a', 500);
    $emoji = str_repeat('👍', 126); // 126 x 4 bytes = 504
    $flags = str_repeat('🇫🇷', 63); // 63 x 8 bytes = 504

    expect(errorsFor(K::post(caption: $plain)))->toBe([])
        ->and(errorsFor(K::post(caption: $plain.'b'))['caption'][0])->toContain('500 character limit')
        ->and(errorsFor(K::post(caption: $emoji))['caption'][0])->toContain('UTF-8 byte')
        ->and(errorsFor(K::post(caption: $flags)))->toHaveKey('caption')
        ->and(errorsFor(K::post(caption: str_repeat('👍', 125))))->toBe([]);
});

it('counts unique links, plus a different link attachment, up to 5', function () {
    $five = 'https://a.com https://b.com https://c.com https://d.com https://e.com';

    expect(errorsFor(K::post(caption: $five)))->toBe([])
        ->and(errorsFor(K::post(caption: $five.' https://f.com'))['caption'][0])->toContain('at most 5 links')
        // Duplicates and trailing punctuation count once.
        ->and(errorsFor(K::post(caption: $five.' https://a.com/ and https://B.com.')))->toBe([])
        // A link attachment already in the text is not counted again...
        ->and(errorsFor(K::post(caption: $five, options: new ThreadsOptions(linkAttachment: 'https://c.com'))))->toBe([])
        // ...a different one is.
        ->and(errorsFor(K::post(caption: $five, options: new ThreadsOptions(linkAttachment: 'https://z.com'))))->toHaveKey('caption')
        ->and(errorsFor(K::post(caption: $five, options: new RawOptions(Platform::Threads, ['link_attachment' => 'https://z.com']))))->toHaveKey('caption');
});

it('honours a configured link limit', function () {
    config(['social.api.threads.max_links' => 1]);

    expect(errorsFor(K::post(caption: 'https://a.com https://b.com')))->toHaveKey('caption');
});

it('limits the links in the first comment too', function () {
    $six = 'https://a.com https://b.com https://c.com https://d.com https://e.com https://f.com';

    expect(errorsFor(K::post(comment: $six))['comment'][0])->toContain('reply')
        ->and(errorsFor(K::post(comment: 'see https://a.com')))->toBe([]);
});

it('keeps link and poll attachments on text posts only', function () {
    $errors = errorsFor(K::post([Media::image(PIC)], 'x', new ThreadsOptions(
        linkAttachment: 'https://example.com',
        pollAttachment: new ThreadsPoll('A', 'B'),
    )));

    expect($errors)->toHaveKeys(['options.link_attachment', 'options.poll_attachment'])
        ->and(errorsFor(K::post([Media::image(PIC)], 'x', new RawOptions(Platform::Threads, ['poll_attachment' => ['option_a' => 'A']]))))
        ->toHaveKey('options.poll_attachment')
        ->and(errorsFor(K::post(options: new ThreadsOptions(linkAttachment: 'https://example.com', pollAttachment: new ThreadsPoll('A', 'B')))))
        ->toBe([]);
});

it('flags a spoiler on a post without media', function () {
    expect(errorsFor(K::post(options: new ThreadsOptions(spoilerMedia: true))))->toHaveKey('options.spoiler_media')
        ->and(errorsFor(K::post([Media::image(PIC)], options: new ThreadsOptions(spoilerMedia: true))))->toBe([]);
});

it('caps alt text at 1000 characters', function () {
    expect(errorsFor(K::post([Media::image(PIC, str_repeat('a', 1000))])))->toBe([])
        ->and(errorsFor(K::post([Media::image(PIC, str_repeat('a', 1001))])))->toHaveKey('media.0');
});

it('takes 2 to 20 carousel items', function () {
    $items = fn (int $n) => array_map(fn ($i) => Media::image("https://cdn.example.com/{$i}.jpg"), range(1, $n));

    expect(errorsFor(K::post($items(20))))->toBe([])
        ->and(errorsFor(K::post($items(21)))['media'][0])->toContain('maximum is 20');
});

it('rejects documents and unsupported formats', function () {
    expect(errorsFor(K::post([Media::document('https://cdn.example.com/deck.pdf')])))->toHaveKey('media.0')
        ->and(errorsFor(K::post([Media::image('https://cdn.example.com/a.gif')])))->toHaveKey('media.0')
        ->and(errorsFor(K::post([Media::image('https://cdn.example.com/a.webp')])))->toHaveKey('media.0')
        ->and(errorsFor(K::post([Media::video('https://cdn.example.com/a.webm')])))->toHaveKey('media.0');
});

it('applies the documented image limits', function (array $meta, bool $valid) {
    $errors = errorsFor(K::post([Media::image(PIC)]), [PIC => new MediaMetadata(...$meta)]);

    expect($errors === [])->toBe($valid);
})->with([
    'fine' => [['sizeBytes' => 2_000_000, 'width' => 1080, 'height' => 1350], true],
    '8 MB' => [['sizeBytes' => 8_388_608], true],
    'over 8 MB' => [['sizeBytes' => 8_388_609], false],
    'panorama 10:1' => [['width' => 10000, 'height' => 1000], true],
    'wider than 10:1' => [['width' => 11000, 'height' => 1000], false],
    'taller than 1:10' => [['width' => 100, 'height' => 1100], false],
]);

it('applies the documented video limits', function (array $meta, bool $valid) {
    $errors = errorsFor(K::post([Media::video(MOV)]), [MOV => new MediaMetadata(...$meta)]);

    expect($errors === [])->toBe($valid, json_encode($errors) ?: '');
})->with([
    'portrait 1440x2560 (height is not capped)' => [['width' => 1440, 'height' => 2560, 'durationSeconds' => 30.0, 'codec' => 'hevc', 'frameRate' => 30.0], true],
    'landscape 2560x1440 (wider than 1920)' => [['width' => 2560, 'height' => 1440], false],
    '1920 wide' => [['width' => 1920, 'height' => 1080, 'codec' => 'h264'], true],
    '300s' => [['durationSeconds' => 300.0], true],
    'over 300s' => [['durationSeconds' => 301.0], false],
    'under 1s' => [['durationSeconds' => 0.5], false],
    '1 GB' => [['sizeBytes' => 1_073_741_824], true],
    'over 1 GB' => [['sizeBytes' => 1_073_741_825], false],
    'vp9' => [['codec' => 'vp9'], false],
    '23.976 fps' => [['frameRate' => 23.976], true],
    '20 fps' => [['frameRate' => 20.0], false],
    '59.94 fps' => [['frameRate' => 59.94], true],
    '120 fps' => [['frameRate' => 120.0], false],
    'aspect over 10:1' => [['width' => 1900, 'height' => 100], false],
]);

it('lets every limit be overridden through config', function () {
    config([
        'social.api.threads.max_characters' => 10,
        'social.api.threads.max_video_width' => 3840,
        'social.api.threads.max_video_duration' => 600,
        'social.api.threads.max_image_bytes' => 1000,
        'social.api.threads.max_carousel_items' => 3,
        'social.api.threads.max_alt_text' => 5,
    ]);

    $driver = K::driver([MOV => new MediaMetadata(width: 2560, height: 1440, durationSeconds: 500.0), PIC => new MediaMetadata(sizeBytes: 2000)]);

    expect($driver->validate(K::post(caption: str_repeat('a', 11)))->toArray())->toHaveKey('caption')
        ->and($driver->validate(K::post([Media::video(MOV)], 'ok'))->toArray())->toBe([])
        ->and($driver->validate(K::post([Media::image(PIC)], 'ok'))->toArray())->toHaveKey('media.0')
        ->and(K::driver()->validate(K::post(array_fill(0, 4, Media::image('https://x.example/1.jpg')), 'ok'))->toArray())->toHaveKey('media')
        ->and(K::driver()->validate(K::post([Media::image('https://x.example/1.jpg', 'longer')], 'ok'))->toArray())->toHaveKey('media.0');
});

it('describes its capabilities', function () {
    $caps = K::driver()->capabilities();

    expect($caps->caption->max)->toBe(500)
        ->and($caps->caption->counter)->toBeInstanceOf(ThreadsCounter::class)
        ->and($caps->media->maxCount)->toBe(20)
        ->and($caps->supportsCarousel)->toBeTrue()
        ->and($caps->media->video->maxWidth)->toBe(1920)
        ->and($caps->media->video->maxHeight)->toBeNull()
        ->and($caps->media->video->codecs)->toBe(['h264', 'hevc'])
        ->and($caps->media->image->extensions)->toBe(['jpg', 'jpeg', 'png'])
        ->and($caps->media->document)->toBeNull();
});

it('checks the publish scope only when the credentials list their scopes', function () {
    $missing = K::post(credentials: ['account_id' => '1', 'access_token' => 't', 'scopes' => ['threads_basic']]);
    $granted = K::post(credentials: ['account_id' => '1', 'access_token' => 't', 'scopes' => 'threads_basic,threads_content_publish']);

    expect(errorsFor($missing)['credentials.scopes'][0])->toContain('threads_content_publish')
        ->and(errorsFor($granted))->toBe([])
        ->and(errorsFor(K::post(credentials: [])))->toBe([]);
});

it('needs threads_location_tagging for a location tag when the credentials list their scopes', function (ThreadsOptions|RawOptions $options) {
    $publish = ['account_id' => '1', 'access_token' => 't', 'scopes' => ['threads_basic', 'threads_content_publish']];
    $tagging = ['account_id' => '1', 'access_token' => 't', 'scopes' => 'threads_basic,threads_content_publish,threads_location_tagging'];

    $errors = errorsFor(K::post(options: $options, credentials: $publish));

    expect($errors)->toHaveKey('credentials.scopes')
        ->and($errors['credentials.scopes'][0])->toContain('threads_location_tagging')
        ->and(errorsFor(K::post(options: $options, credentials: $tagging)))->toBe([])
        // Unknown scopes are never an error.
        ->and(errorsFor(K::post(options: $options, credentials: [])))->toBe([])
        // No location tag, no extra scope.
        ->and(errorsFor(K::post(credentials: $publish)))->toBe([]);
})->with([
    'typed' => [new ThreadsOptions(locationId: '123')],
    'raw extra' => [new RawOptions(Platform::Threads, ['location_id' => '123'])],
]);

it('declares its identity and scopes', function () {
    $driver = K::driver();

    expect($driver->accountKey(new Credentials(['account_id' => '1784', 'access_token' => 'x', 'expires_at' => 1])))->toBe('1784')
        ->and($driver->scopesFor(Feature::Publish))->toBe([['threads_content_publish']])
        ->and($driver->scopesFor(Feature::Comment))->toBe([['threads_manage_replies']])
        ->and($driver->scopesFor(Feature::Video))->toBe([]);
});

// --- ThreadsOptions -------------------------------------------------------------------------

it('validates its options', function (ThreadsOptions $options, ?string $key) {
    $errors = $options->validate()->toArray();

    if ($key === null) {
        expect($errors)->toBe([]);
    } else {
        expect($errors)->toHaveKey($key);
    }

    // The driver merges the options' own errors.
    expect(K::driver()->validate(K::post(options: $options))->has($key ?? 'none'))->toBe($key !== null);
})->with([
    'everything fine' => [new ThreadsOptions(
        replyControl: ThreadsReplyControl::FollowersOnly,
        topicTag: '#Book Club',
        linkAttachment: 'https://example.com/a?b=c',
        allowlistedCountryCodes: ['US', 'FR'],
        pollAttachment: new ThreadsPoll('Yes', 'No', 'Maybe'),
    ), null],
    'empty topic tag' => [new ThreadsOptions(topicTag: '#'), 'options.topic_tag'],
    'topic tag too long' => [new ThreadsOptions(topicTag: str_repeat('a', 51)), 'options.topic_tag'],
    'topic tag with a dot' => [new ThreadsOptions(topicTag: 'web3.0'), 'options.topic_tag'],
    'topic tag with an ampersand' => [new ThreadsOptions(topicTag: 'R&D'), 'options.topic_tag'],
    'link attachment not a URL' => [new ThreadsOptions(linkAttachment: 'example.com'), 'options.link_attachment'],
    'link attachment ftp' => [new ThreadsOptions(linkAttachment: 'ftp://example.com/file'), 'options.link_attachment'],
    'lowercase country' => [new ThreadsOptions(allowlistedCountryCodes: ['us']), 'options.allowlisted_country_codes'],
    'three-letter country' => [new ThreadsOptions(allowlistedCountryCodes: ['USA']), 'options.allowlisted_country_codes'],
    'blank poll option' => [new ThreadsOptions(pollAttachment: new ThreadsPoll('Yes', ' ')), 'options.poll_attachment'],
    'poll option D without C' => [new ThreadsOptions(pollAttachment: new ThreadsPoll('A', 'B', null, 'D')), 'options.poll_attachment'],
    'duplicate poll options' => [new ThreadsOptions(pollAttachment: new ThreadsPoll('Yes', 'yes')), 'options.poll_attachment'],
    'poll option over 25 characters' => [new ThreadsOptions(pollAttachment: new ThreadsPoll(str_repeat('y', 26), 'No')), 'options.poll_attachment'],
]);

it('caps each poll option at 25 characters, as Threads documents', function () {
    $ok = new ThreadsPoll(str_repeat('a', 25), str_repeat('é', 25), ' '.str_repeat('c', 25).' ');
    $long = new ThreadsPoll('Absolutely yes, without any doubt', 'No', null, null);
    $longD = new ThreadsPoll('A', 'B', 'C', str_repeat('d', 26));

    expect($ok->validate()->toArray())->toBe([])
        ->and($long->validate()->get('options.poll_attachment'))->toHaveCount(1)
        ->and($long->validate()->first('options.poll_attachment'))->toContain('option A')
        ->and($long->validate()->first('options.poll_attachment'))->toContain('25 characters')
        ->and($longD->validate()->first('options.poll_attachment'))->toContain('option D')
        // The driver reports it before dispatch.
        ->and(errorsFor(K::post(options: new ThreadsOptions(pollAttachment: $long))))->toHaveKey('options.poll_attachment');
});

it('builds the poll attachment object', function () {
    $poll = new ThreadsPoll(' Red ', 'Blue', '', 'Green');

    expect($poll->toArray())->toBe(['option_a' => 'Red', 'option_b' => 'Blue', 'option_d' => 'Green'])
        ->and($poll->options())->toBe(['Red', 'Blue', 'Green']);
});

it('normalises the topic tag and binds to Threads', function () {
    $options = new ThreadsOptions(topicTag: '  #Travel ', extra: ['a' => 1]);

    expect($options->normalizedTopicTag())->toBe('Travel')
        ->and((new ThreadsOptions(topicTag: '  '))->normalizedTopicTag())->toBeNull()
        ->and($options->platform())->toBe(Platform::Threads)
        ->and($options->extra())->toBe(['a' => 1]);
});
