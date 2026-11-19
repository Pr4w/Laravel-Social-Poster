<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Contracts\IdempotencyStore;
use SocialPoster\Contracts\SupportsFirstComment;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Idempotency\NullIdempotencyStore;
use SocialPoster\Platforms\X\XDriver;
use SocialPoster\Platforms\X\XOptions;
use SocialPoster\Platforms\X\XPoll;
use SocialPoster\Platforms\X\XReplySettings;
use SocialPoster\SocialManager;
use SocialPoster\Tests\Fixtures\x\XFixtures;
use SocialPoster\Tests\Fixtures\x\XTestDriver;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\Published;
use SocialPoster\ValueObjects\RawOptions;

afterEach(fn () => XFixtures::cleanup());

// --- Text posts and the commit phase ----------------------------------------------------

it('publishes a text post through a commit phase, then creates it', function () {
    Http::fake(XFixtures::routes());
    $driver = new XTestDriver;
    $post = XFixtures::post();

    $outcome = $driver->publish($post);

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->commits)->toBeTrue()
        ->and($outcome->recheckAfter)->toBe(0)
        ->and($outcome->state)->toBe(['phase' => 'tweet', 'media_ids' => []]);
    Http::assertNothingSent();

    $published = $driver->resume($post, $outcome->state);

    expect($published)->toBeInstanceOf(Published::class)
        ->and($published->result->platformPostId)->toBe('1880028106020515840')
        ->and($published->result->url)->toBe('https://x.com/i/status/1880028106020515840')
        ->and($published->result->state)->toBe('published')
        ->and($published->result->payload['data']['text'])->toBe('Hello from the package');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.x.com/2/tweets'
        && $request->method() === 'POST'
        && $request->data() === ['text' => 'Hello from the package']
        && $request->hasHeader('Authorization', 'Bearer x-token'));
});

it('is registered as the x driver and implements the first-comment contract', function () {
    $driver = app(SocialManager::class)->driver('x');

    expect($driver)->toBeInstanceOf(XDriver::class)
        ->and($driver)->toBeInstanceOf(SupportsFirstComment::class)
        ->and($driver->platform())->toBe(Platform::X);
});

it('merges the extra passthrough and the typed options into the post', function () {
    Http::fake(XFixtures::routes());
    $driver = new XTestDriver;
    $options = new XOptions(
        replySettings: XReplySettings::MentionedUsers,
        quoteTweetId: '1880000000000000001',
        replyToTweetId: '1880000000000000002',
        extra: [
            'reply' => ['exclude_reply_user_ids' => ['42'], 'in_reply_to_tweet_id' => 'ignored'],
            'reply_settings' => 'following',
            'nullcast' => false,
        ],
    );
    $post = XFixtures::post(options: $options);

    $driver->resume($post, ['phase' => 'tweet', 'media_ids' => []]);

    Http::assertSent(fn (Request $request) => $request->data() === [
        'reply' => ['exclude_reply_user_ids' => ['42'], 'in_reply_to_tweet_id' => '1880000000000000002'],
        'reply_settings' => 'mentionedUsers',
        'nullcast' => false,
        'text' => 'Hello from the package',
        'quote_tweet_id' => '1880000000000000001',
    ]);
});

it('sends a poll and a string reply setting', function () {
    Http::fake(XFixtures::routes());
    $options = new XOptions(replySettings: 'following', poll: new XPoll([' Yes ', 'No'], 60), extra: ['poll' => ['reply_settings' => 'following']]);

    (new XTestDriver)->resume(XFixtures::post(options: $options), ['phase' => 'tweet']);

    Http::assertSent(fn (Request $request) => $request->data()['poll'] === ['reply_settings' => 'following', 'options' => ['Yes', 'No'], 'duration_minutes' => 60]
        && $request->data()['reply_settings'] === 'following');
});

it('ignores options meant for another platform', function () {
    Http::fake(XFixtures::routes());

    (new XTestDriver)->resume(
        XFixtures::post(options: new RawOptions(Platform::Threads, ['reply_control' => 'everyone'])),
        ['phase' => 'tweet', 'media_ids' => []],
    );

    Http::assertSent(fn (Request $request) => $request->data() === ['text' => 'Hello from the package']);
});

it('takes RawOptions for X as the extra passthrough', function () {
    Http::fake(XFixtures::routes());

    (new XTestDriver)->resume(
        XFixtures::post(options: new RawOptions(Platform::X, ['reply_settings' => 'verified', 'text' => 'never wins'])),
        ['phase' => 'tweet', 'media_ids' => []],
    );

    Http::assertSent(fn (Request $request) => $request->data() === ['reply_settings' => 'verified', 'text' => 'Hello from the package']);
});

it('reads the API host from api.x.base_url', function () {
    config(['social.api.x.base_url' => 'https://api.twitter.com/2/']);
    Http::fake(['api.twitter.com/2/tweets' => Http::response(XFixtures::json('tweet_created'), 201)]);

    (new XTestDriver)->resume(XFixtures::post(), ['phase' => 'tweet', 'media_ids' => []]);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.twitter.com/2/tweets');
});

it('uses a 120s timeout for API calls and the upload timeout for bytes', function () {
    $driver = new XTestDriver;
    $post = XFixtures::post();

    expect($driver->call('client', $post)->getOptions()['timeout'])->toBe(120)
        ->and($driver->call('client', $post)->getOptions()['connect_timeout'])->toBe(10)
        ->and($driver->call('uploadClient', $post)->getOptions()['timeout'])->toBe(300);

    config(['social.api.x.timeout' => 45, 'social.api.x.upload_timeout' => 600]);

    expect($driver->call('client', $post)->getOptions()['timeout'])->toBe(45)
        ->and($driver->call('uploadClient', $post)->getOptions()['timeout'])->toBe(600);
});

// --- Images ----------------------------------------------------------------------------

it('uploads images in one shot, sets alt text, then commits the post with their ids', function () {
    Http::fake(XFixtures::routes([
        'api.x.com/2/media/upload' => Http::sequence()
            ->push(XFixtures::json('image_uploaded'), 200)
            ->push(XFixtures::json('image_uploaded_second'), 200),
    ]));
    $driver = new XTestDriver;
    $post = XFixtures::post(
        media: [Media::image(XFixtures::png(), 'A tiny pixel'), Media::image(XFixtures::file('jpg', 'jpeg-bytes'))],
        options: new XOptions(extra: ['media' => ['tagged_user_ids' => ['2244994945'], 'media_ids' => ['nope']]]),
    );

    $outcome = $driver->publish($post);

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->commits)->toBeTrue()
        ->and($outcome->state)->toBe(['phase' => 'tweet', 'media_ids' => ['1880028106020515841', '1880028106020515843']]);

    $uploads = XFixtures::sent('/2/media/upload');
    expect($uploads)->toHaveCount(2)
        ->and(XFixtures::field($uploads[0], 'media_category'))->toBe('tweet_image')
        ->and(XFixtures::field($uploads[0], 'media_type'))->toBe('image/png')
        ->and(XFixtures::field($uploads[1], 'media_type'))->toBe('image/jpeg')
        ->and(XFixtures::field($uploads[1], 'media'))->toBe('jpeg-bytes')
        ->and($uploads[0]->hasFile('media', null, 'image.png'))->toBeTrue();

    $metadata = XFixtures::sent('/2/media/metadata');
    expect($metadata)->toHaveCount(1)
        ->and($metadata[0]->data())->toBe(['id' => '1880028106020515841', 'metadata' => ['alt_text' => ['text' => 'A tiny pixel']]]);

    $driver->resume($post, $outcome->state);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/2/tweets')
        && $request->data()['media'] === ['tagged_user_ids' => ['2244994945'], 'media_ids' => ['1880028106020515841', '1880028106020515843']]);
});

it('omits empty text on a media post when text is optional', function () {
    config(['social.api.x.require_text' => false]);
    Http::fake(XFixtures::routes());

    (new XTestDriver)->resume(XFixtures::post(caption: '  '), ['phase' => 'tweet', 'media_ids' => ['1880028106020515841']]);

    Http::assertSent(fn (Request $request) => $request->data() === ['media' => ['media_ids' => ['1880028106020515841']]]);
});

it('keeps the text on a media post while text is required (the default)', function () {
    Http::fake(XFixtures::routes());

    (new XTestDriver)->resume(XFixtures::post(caption: 'Look'), ['phase' => 'tweet', 'media_ids' => ['1880028106020515841']]);

    Http::assertSent(fn (Request $request) => $request->data() === ['text' => 'Look', 'media' => ['media_ids' => ['1880028106020515841']]]);
});

it('cuts alt text to the X limit when validation was skipped', function () {
    Http::fake(XFixtures::routes());

    (new XTestDriver)->publish(XFixtures::post(media: [Media::image(XFixtures::png(), str_repeat('é', 1005))]));

    expect(mb_strlen(XFixtures::sent('/media/metadata')[0]->data()['metadata']['alt_text']['text']))->toBe(1000);
});

it('never fails a post because alt text was refused', function () {
    config(['social.error_logging' => true]);
    Log::spy();
    Http::fake(XFixtures::routes([
        'api.x.com/2/media/metadata' => Http::response(XFixtures::json('error_forbidden'), 403),
    ]));

    $outcome = (new XTestDriver)->publish(XFixtures::post(media: [Media::image(XFixtures::png(), 'Alt')]));

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->state['media_ids'])->toBe(['1880028106020515841']);
    Log::shouldHaveReceived('error')->once();
});

it('never fails a post because the alt text request lost its connection', function () {
    config(['social.error_logging' => true]);
    Log::spy();
    Http::fake(XFixtures::routes([
        'api.x.com/2/media/metadata' => XFixtures::connectionFailure('cURL error 28: Operation timed out'),
    ]));

    $outcome = (new XTestDriver)->publish(XFixtures::post(media: [Media::image(XFixtures::png(), 'Alt')]));

    expect($outcome->state['media_ids'])->toBe(['1880028106020515841']);
    Log::shouldHaveReceived('warning')->once();
});

it('treats a blank image id as a temporary server error', function () {
    Http::fake(XFixtures::routes(['api.x.com/2/media/upload' => Http::response(['data' => ['id' => '']], 200)]));

    expect(fn () => (new XTestDriver)->publish(XFixtures::post(media: [Media::image(XFixtures::png())])))
        ->toThrow(fn (TemporaryException $e) => expect($e->reason)->toBe(FailureReason::ServerError)
            ->and($e->context['missing_field'])->toBe('data.id'));
});

it('maps an image upload refusal', function () {
    Http::fake(XFixtures::routes(['api.x.com/2/media/upload' => Http::response(XFixtures::json('error_unauthorized'), 401)]));

    expect(fn () => (new XTestDriver)->publish(XFixtures::post(media: [Media::image(XFixtures::png())])))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::InvalidToken));
});

it('refuses a remote URL that no gateway downloaded', function () {
    Http::fake(XFixtures::routes());

    expect(fn () => (new XTestDriver)->publish(XFixtures::post(media: [Media::image('https://cdn.example.com/a.jpg')])))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::Misconfigured));
    Http::assertNothingSent();
});

it('reports an unreadable image as rejected media', function () {
    Http::fake(XFixtures::routes());

    expect(fn () => (new XTestDriver)->publish(XFixtures::post(media: [Media::image('/definitely/missing/file.png')])))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::MediaRejected));
});

it('sniffs the MIME type of an extension-less file from its content', function () {
    $driver = new XTestDriver;
    $path = XFixtures::file('', (string) base64_decode(XFixtures::PNG_1X1));

    expect($driver->call('mimeFor', Media::image($path), $path))->toBe('image/png')
        ->and($driver->call('mimeFor', Media::image('/x/a.webp'), '/x/a.webp'))->toBe('image/webp')
        ->and($driver->call('mime', Media::video('/x/a.mov')))->toBe('video/quicktime')
        ->and($driver->call('mimeFor', Media::image('/missing/blob'), '/missing/blob'))->toBe('application/octet-stream');
});

// --- Video and GIF (chunked) ------------------------------------------------------------

it('uploads a video in full segments, waits for processing, then commits the post', function () {
    config(['social.api.x.chunk_size' => 4]);
    Http::fake(XFixtures::routes([
        'api.x.com/2/media/upload/*/finalize' => Http::response(XFixtures::json('finalize_pending'), 200),
        'api.x.com/2/media/upload?*' => Http::sequence()
            ->push(XFixtures::json('status_in_progress'), 200)
            ->push(XFixtures::json('status_succeeded'), 200),
    ]));
    $driver = new XTestDriver;
    $post = XFixtures::post(media: [Media::video(XFixtures::file('mp4', 'abcdefghij'))]);

    $outcome = $driver->publish($post);

    $init = XFixtures::sent('/media/upload/initialize')[0];
    expect($init->data())->toBe(['media_category' => 'tweet_video', 'media_type' => 'video/mp4', 'total_bytes' => 10]);

    $appends = XFixtures::sent('/append');
    expect($appends)->toHaveCount(3)
        ->and(array_map(fn (Request $r) => (int) XFixtures::field($r, 'segment_index'), $appends))->toBe([0, 1, 2])
        ->and(array_map(fn (Request $r) => XFixtures::field($r, 'media'), $appends))->toBe(['abcd', 'efgh', 'ij'])
        ->and($appends[0]->url())->toBe('https://api.x.com/2/media/upload/1880028106020515842/append');

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->commits)->toBeFalse()
        ->and($outcome->recheckAfter)->toBe(1)
        ->and($outcome->state)->toBe(['phase' => 'await_media', 'media_id' => '1880028106020515842']);

    $outcome = $driver->resume($post, $outcome->state);

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->recheckAfter)->toBe(30) // check_after_secs 100, clamped
        ->and($outcome->state['phase'])->toBe('await_media');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/2/media/upload?')
        && $request['command'] === 'STATUS'
        && $request['media_id'] === '1880028106020515842');

    $outcome = $driver->resume($post, $outcome->state);

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->commits)->toBeTrue()
        ->and($outcome->state)->toBe(['phase' => 'tweet', 'media_ids' => ['1880028106020515842']]);

    $published = $driver->resume($post, $outcome->state);

    expect($published->result->platformPostId)->toBe('1880028106020515840');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/2/tweets')
        && $request->data() === ['text' => 'Hello from the package', 'media' => ['media_ids' => ['1880028106020515842']]]);
    expect(XFixtures::sent('/media/metadata'))->toBe([]); // no alt text on video
});

it('stages a long upload across runs with the offset in state', function () {
    config(['social.api.x.chunk_size' => 4]);
    Http::fake(XFixtures::routes());
    $driver = new XTestDriver;
    $driver->budgetChecks = 0; // one segment per run
    $post = XFixtures::post(media: [Media::video(XFixtures::file('mp4', 'abcdefghij'))]);

    $first = $driver->publish($post);

    expect($first)->toBeInstanceOf(Pending::class)
        ->and($first->commits)->toBeFalse()
        ->and($first->recheckAfter)->toBe(0)
        ->and($first->state)->toBe([
            'phase' => 'append',
            'media_id' => '1880028106020515842',
            'size' => 10,
            'chunk_size' => 4,
            'segment_index' => 1,
            'offset' => 4,
        ]);

    // The job injects its reserved keys; the driver must ignore them.
    $second = $driver->resume($post, $first->state + [Pending::ATTEMPT => 1, Pending::STARTED_AT => time()]);

    expect($second->state)->toMatchArray(['phase' => 'append', 'segment_index' => 2, 'offset' => 8])
        ->and($second->state)->not->toHaveKey(Pending::ATTEMPT);

    $third = $driver->resume($post, $second->state);

    expect($third)->toBeInstanceOf(Pending::class)
        ->and($third->commits)->toBeTrue()
        ->and($third->state)->toBe(['phase' => 'tweet', 'media_ids' => ['1880028106020515842']]);

    expect(array_map(fn (Request $r) => XFixtures::field($r, 'media'), XFixtures::sent('/append')))->toBe(['abcd', 'efgh', 'ij'])
        ->and(XFixtures::sent('/media/upload/initialize'))->toHaveCount(1)
        ->and(XFixtures::sent('/finalize'))->toHaveCount(1);
});

it('sends more segments per run while the budget lasts', function () {
    config(['social.api.x.chunk_size' => 2]);
    Http::fake(XFixtures::routes());
    $driver = new XTestDriver;
    $driver->budgetChecks = 2; // three segments in this run
    $post = XFixtures::post(media: [Media::video(XFixtures::file('mp4', 'abcdefghij'))]);

    $outcome = $driver->publish($post);

    expect($outcome->state)->toMatchArray(['segment_index' => 3, 'offset' => 6]);

    $driver->budgetChecks = 5;
    $outcome = $driver->resume($post, $outcome->state);

    expect($outcome->state['phase'])->toBe('tweet')
        ->and(XFixtures::sent('/append'))->toHaveCount(5);
});

it('recomputes a missing segment size when continuing', function () {
    config(['social.api.x.chunk_size' => 6]);
    Http::fake(XFixtures::routes());
    $post = XFixtures::post(media: [Media::video(XFixtures::file('mp4', 'abcdefghij'))]);

    $outcome = (new XTestDriver)->resume($post, ['phase' => 'append', 'media_id' => '5', 'size' => 10, 'segment_index' => 0, 'offset' => 0]);

    expect($outcome->state)->toBe(['phase' => 'tweet', 'media_ids' => ['5']])
        ->and(array_map(fn (Request $r) => XFixtures::field($r, 'media'), XFixtures::sent('/append')))->toBe(['abcdef', 'ghij']);
});

it('keeps the segments within X limits for the largest documented videos (segment_index up to 9999)', function () {
    $driver = new XTestDriver;

    // 8 GB (default accounts) and 16 GB (Premium) fit in 4 MB segments, far
    // below X's 8 MB segment maximum.
    expect($driver->call('segmentSize', 1000))->toBe(4194304)
        ->and($driver->call('segmentSize', 8 * 1024 ** 3))->toBe(4194304)
        ->and($driver->call('segmentSize', 16 * 1024 ** 3))->toBe(4194304);

    // A small configured segment grows only as far as 10,000 segments need.
    config(['social.api.x.chunk_size' => 1000]);

    expect($driver->call('segmentSize', 100_000_000))->toBe(10_000);

    // A configured segment above the 5 MiB cap is capped (X refuses > 8 MB).
    config(['social.api.x.chunk_size' => 16 * 1024 * 1024]);

    expect($driver->call('segmentSize', 1000))->toBe(5242880);
});

it('refuses a file that needs segments above the maximum before INITIALIZE', function () {
    Http::fake(XFixtures::routes());
    config(['social.api.x.chunk_size' => 1]);
    $driver = new class extends XTestDriver
    {
        protected const MAX_SEGMENTS = 2;

        protected const MAX_SEGMENT_BYTES = 4;
    };

    expect(fn () => $driver->publish(XFixtures::post(media: [Media::video(XFixtures::file('mp4', 'abcdefghij'))])))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::MediaRejected)
            ->and($e->context)->toMatchArray(['size' => 10]));
    Http::assertNothingSent();
});

it('starts the upload again when the file changed between runs, then gives up', function () {
    Http::fake(XFixtures::routes());
    $driver = new XTestDriver;
    $post = XFixtures::post(media: [Media::video(XFixtures::file('mp4', 'abcdefghijkl'))]);
    $state = ['phase' => 'append', 'media_id' => '1880028106020515842', 'size' => 10, 'chunk_size' => 4, 'segment_index' => 1, 'offset' => 4];

    expect(fn () => $driver->resume($post, $state))
        ->toThrow(fn (TemporaryException $e) => expect($e->context['restart'])->toBeTrue()
            ->and($e->retryAfter)->toBe(0)
            ->and($e->context['size'])->toBe(12));

    expect(fn () => $driver->resume($post, $state + [Pending::RESTARTS => 2]))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::MediaRejected));

    Http::assertNothingSent();
});

it('refuses an empty video file before any request', function () {
    Http::fake(XFixtures::routes());

    expect(fn () => (new XTestDriver)->publish(XFixtures::post(media: [Media::video(XFixtures::file('mp4', ''))])))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::MediaRejected));
    Http::assertNothingSent();
});

it('treats a blank media id from INITIALIZE as a temporary error', function () {
    Http::fake(XFixtures::routes(['api.x.com/2/media/upload/initialize' => Http::response(XFixtures::json('media_initialized_without_id'), 200)]));

    expect(fn () => (new XTestDriver)->publish(XFixtures::post(media: [Media::video(XFixtures::file('mp4'))])))
        ->toThrow(fn (TemporaryException $e) => expect($e->reason)->toBe(FailureReason::ServerError));
    expect(XFixtures::sent('/append'))->toBe([]);
});

it('maps INITIALIZE, APPEND and FINALIZE refusals', function (string $route, string $fixture, int $status, FailureReason $reason) {
    Http::fake(XFixtures::routes([$route => Http::response(XFixtures::json($fixture), $status)]));

    expect(fn () => (new XTestDriver)->publish(XFixtures::post(media: [Media::video(XFixtures::file('mp4'))])))
        ->toThrow(fn (SocialPosterException $e) => expect($e->reason)->toBe($reason));
})->with([
    'initialize 400' => ['api.x.com/2/media/upload/initialize', 'error_invalid_media_ids', 400, FailureReason::MediaRejected],
    'append 503' => ['api.x.com/2/media/upload/*/append', 'error_service_unavailable', 503, FailureReason::ServerError],
    'finalize 401' => ['api.x.com/2/media/upload/*/finalize', 'error_unauthorized', 401, FailureReason::InvalidToken],
]);

it('rejects media that X failed to process, with its reason', function () {
    Http::fake(XFixtures::routes(['api.x.com/2/media/upload/*/finalize' => Http::response(XFixtures::json('finalize_failed'), 200)]));

    expect(fn () => (new XTestDriver)->publish(XFixtures::post(media: [Media::video(XFixtures::file('mp4'))])))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::MediaRejected)
            ->and($e->getMessage())->toContain('Unsupported video format')
            ->and($e->context['processing_info']['state'])->toBe('failed'));
});

it('rejects media whose processing fails during the STATUS polls', function () {
    Http::fake(XFixtures::routes(['api.x.com/2/media/upload?*' => Http::response(XFixtures::json('status_failed'), 200)]));

    expect(fn () => (new XTestDriver)->resume(XFixtures::post(), ['phase' => 'await_media', 'media_id' => '1880028106020515842']))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::MediaRejected)
            ->and($e->getMessage())->toContain('Video duration exceeds the maximum allowed'));
});

it('treats a STATUS without processing_info as ready', function () {
    Http::fake(XFixtures::routes(['api.x.com/2/media/upload?*' => Http::response(XFixtures::json('status_without_processing_info'), 200)]));

    $outcome = (new XTestDriver)->resume(XFixtures::post(), ['phase' => 'await_media', 'media_id' => '1880028106020515842']);

    expect($outcome->commits)->toBeTrue()
        ->and($outcome->state)->toBe(['phase' => 'tweet', 'media_ids' => ['1880028106020515842']]);
});

it('logs an unknown processing state and keeps polling, at least 1s apart', function () {
    config(['social.error_logging' => true]);
    Log::spy();
    Http::fake(XFixtures::routes(['api.x.com/2/media/upload?*' => Http::response(XFixtures::json('status_unknown_state'), 200)]));

    $outcome = (new XTestDriver)->resume(XFixtures::post(), ['phase' => 'await_media', 'media_id' => '1880028106020515842']);

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->commits)->toBeFalse()
        ->and($outcome->recheckAfter)->toBe(1)
        ->and($outcome->state['phase'])->toBe('await_media');
    Log::shouldHaveReceived('warning')->once();
});

it('maps a failed STATUS call instead of treating it as done', function () {
    Http::fake(XFixtures::routes(['api.x.com/2/media/upload?*' => Http::response(XFixtures::json('error_not_found'), 404)]));

    expect(fn () => (new XTestDriver)->resume(XFixtures::post(), ['phase' => 'await_media', 'media_id' => '1880028106020515842']))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::NotFound));
});

it('honours api.x.max_check_after', function () {
    config(['social.api.x.max_check_after' => 10]);
    Http::fake(XFixtures::routes(['api.x.com/2/media/upload?*' => Http::response(XFixtures::json('status_in_progress'), 200)]));

    $outcome = (new XTestDriver)->resume(XFixtures::post(), ['phase' => 'await_media', 'media_id' => '1880028106020515842']);

    expect($outcome->recheckAfter)->toBe(10);
});

it('keeps the deprecated mediaStatus() shape for subclasses', function () {
    Http::fake(XFixtures::routes(['api.x.com/2/media/upload?*' => Http::sequence()
        ->push(XFixtures::json('status_in_progress'), 200)
        ->push(XFixtures::json('status_without_processing_info'), 200)]));
    $driver = new XTestDriver;

    expect($driver->call('mediaStatus', XFixtures::post(), '1'))->toBe(['in_progress', 30])
        ->and($driver->call('mediaStatus', XFixtures::post(), '1'))->toBe(['succeeded', 5]);
});

it('uploads the whole file through the deprecated appendChunks()', function () {
    config(['social.api.x.chunk_size' => 3]);
    Http::fake(XFixtures::routes());

    (new XTestDriver)->call('appendChunks', XFixtures::post(), '99', XFixtures::file('mp4', 'abcdefg'));

    expect(array_map(fn (Request $r) => XFixtures::field($r, 'media'), XFixtures::sent('/append')))->toBe(['abc', 'def', 'g']);
});

it('uploads a GIF as tweet_gif and sets its alt text once processed', function () {
    Http::fake(XFixtures::routes(['api.x.com/2/media/upload/*/finalize' => Http::response(XFixtures::json('finalize_succeeded'), 200)]));
    $post = XFixtures::post(media: [Media::image(XFixtures::file('gif', 'GIF89a....'), 'A dancing cat')]);

    $outcome = (new XTestDriver)->publish($post);

    expect(XFixtures::sent('/media/upload/initialize')[0]->data())->toMatchArray(['media_category' => 'tweet_gif', 'media_type' => 'image/gif'])
        ->and(XFixtures::sent('/media/metadata')[0]->data())->toBe(['id' => '1880028106020515842', 'metadata' => ['alt_text' => ['text' => 'A dancing cat']]])
        ->and($outcome->commits)->toBeTrue()
        ->and($outcome->state)->toBe(['phase' => 'tweet', 'media_ids' => ['1880028106020515842']]);
});

it('carries the GIF alt text in state and sets it when processing ends', function () {
    Http::fake(XFixtures::routes([
        'api.x.com/2/media/upload/*/finalize' => Http::response(XFixtures::json('finalize_pending'), 200),
        'api.x.com/2/media/upload?*' => Http::response(XFixtures::json('status_succeeded'), 200),
    ]));
    $driver = new XTestDriver;
    $post = XFixtures::post(media: [Media::image(XFixtures::file('gif', 'GIF89a....'), 'A dancing cat')]);

    $pending = $driver->publish($post);

    expect($pending->state)->toBe(['phase' => 'await_media', 'media_id' => '1880028106020515842', 'alt_text' => 'A dancing cat'])
        ->and(XFixtures::sent('/media/metadata'))->toBe([]);

    // The post's own alt text changed: the state wins.
    $edited = XFixtures::post(media: [Media::image(XFixtures::file('gif'), 'Edited')]);
    $commit = $driver->resume($edited, $pending->state);

    expect($commit->commits)->toBeTrue()
        ->and(XFixtures::sent('/media/metadata')[0]->data()['metadata']['alt_text']['text'])->toBe('A dancing cat');
});

it('carries the alt text through a staged GIF upload', function () {
    config(['social.api.x.chunk_size' => 5]);
    Http::fake(XFixtures::routes());
    $driver = new XTestDriver;
    $driver->budgetChecks = 0;
    $post = XFixtures::post(media: [Media::image(XFixtures::file('gif', 'GIF89a....'), 'Loop')]);

    $pending = $driver->publish($post);

    expect($pending->state['alt_text'])->toBe('Loop');

    $driver->resume($post, $pending->state);

    expect(XFixtures::sent('/media/metadata')[0]->data()['metadata']['alt_text']['text'])->toBe('Loop');
});

// --- Legacy v1.0 continuations ----------------------------------------------------------

it('resumes a v1.0 await_media continuation', function () {
    Http::fake(XFixtures::routes());
    $driver = new XTestDriver;
    $post = XFixtures::post(media: [Media::video(XFixtures::file('mp4'))]);

    $commit = $driver->resume($post, ['phase' => 'await_media', 'media_id' => '123']);

    expect($commit->commits)->toBeTrue()
        ->and($commit->state)->toBe(['phase' => 'tweet', 'media_ids' => ['123']]);

    $driver->resume($post, $commit->state);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/2/tweets')
        && $request->data()['media'] === ['media_ids' => ['123']]);
});

it('takes the alt text of a v1.0 GIF continuation from the post', function () {
    Http::fake(XFixtures::routes());

    (new XTestDriver)->resume(
        XFixtures::post(media: [Media::image(XFixtures::file('gif'), 'Legacy alt')]),
        ['phase' => 'await_media', 'media_id' => '123'],
    );

    expect(XFixtures::sent('/media/metadata')[0]->data())->toBe(['id' => '123', 'metadata' => ['alt_text' => ['text' => 'Legacy alt']]]);
});

it('accepts a single media_id in the tweet state', function () {
    Http::fake(XFixtures::routes());

    (new XTestDriver)->resume(XFixtures::post(), ['phase' => 'tweet', 'media_id' => '77']);

    Http::assertSent(fn (Request $request) => ($request->data()['media'] ?? null) === ['media_ids' => ['77']]);
});

it('refuses an unknown phase and a state without its media id', function () {
    Http::fake(XFixtures::routes());
    $driver = new XTestDriver;

    expect(fn () => $driver->resume(XFixtures::post(), ['phase' => 'bogus']))
        ->toThrow(PermanentException::class, 'Unknown X publish phase.');
    expect(fn () => $driver->resume(XFixtures::post(), []))
        ->toThrow(PermanentException::class, 'Unknown X publish phase.');
    expect(fn () => $driver->resume(XFixtures::post(), ['phase' => 'await_media']))
        ->toThrow(fn (PermanentException $e) => expect($e->context['missing_field'])->toBe('media_id'));
    expect(fn () => $driver->resume(XFixtures::post(), ['phase' => 'append', 'media_id' => '1', 'size' => 4]))
        ->toThrow(PermanentException::class, 'no media to continue with');

    Http::assertNothingSent();
});

// --- The commit phase: ids, recovery, verification ---------------------------------------

it('never retries a created post that came back without an id', function () {
    Http::fake(XFixtures::routes(['api.x.com/2/tweets' => Http::response(XFixtures::json('tweet_created_without_id'), 201)]));

    expect(fn () => (new XTestDriver)->resume(XFixtures::post(), ['phase' => 'tweet', 'media_ids' => []]))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::OutcomeUnknown)
            ->and($e->possiblyPublished())->toBeTrue());
});

it('lets a connection failure on the create bubble up to the job', function () {
    Http::fake(XFixtures::routes(['api.x.com/2/tweets' => XFixtures::connectionFailure()]));

    expect(fn () => (new XTestDriver)->resume(XFixtures::post(), ['phase' => 'tweet', 'media_ids' => []]))
        ->toThrow(ConnectionException::class);
});

it('reports a duplicate as DuplicateContent on a first attempt', function () {
    Http::fake(XFixtures::routes(['api.x.com/2/tweets' => Http::response(XFixtures::json('error_duplicate'), 403)]));

    expect(fn () => (new XTestDriver)->resume(XFixtures::post(), ['phase' => 'tweet', 'media_ids' => []]))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::DuplicateContent));
});

it('re-sends a recovering create and treats a duplicate as the earlier attempt landing', function () {
    config(['social.error_logging' => true]);
    Log::spy();
    Http::fake(XFixtures::routes(['api.x.com/2/tweets' => Http::response(XFixtures::json('error_duplicate'), 403)]));

    $published = (new XTestDriver)->resume(XFixtures::post(), [
        'phase' => 'tweet',
        'media_ids' => ['1880028106020515841'],
        Pending::RECOVERING => true,
        Pending::COMMITTING_SINCE => time() - 60,
    ]);

    expect($published)->toBeInstanceOf(Published::class)
        ->and($published->result->success)->toBeTrue()
        ->and($published->result->platformPostId)->toBeNull()
        ->and($published->result->url)->toBeNull()
        ->and($published->result->payload)->toMatchArray([
            'recovered' => true,
            'possibly_duplicate' => true,
            'possibly_published' => true,
            'verified' => false,
            'media_ids' => ['1880028106020515841'],
        ]);

    // No billed read by default: only the create was sent.
    Http::assertSentCount(1);
    Log::shouldHaveReceived('warning')->once();
    Log::shouldNotHaveReceived('error');
});

it('never re-sends a recovering post without text, which X cannot flag as a duplicate', function () {
    config(['social.api.x.require_text' => false]);
    Http::fake(XFixtures::routes());

    expect(fn () => (new XTestDriver)->resume(XFixtures::post(caption: ''), ['phase' => 'tweet', 'media_ids' => ['5'], Pending::RECOVERING => true]))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::OutcomeUnknown)
            ->and($e->possiblyPublished())->toBeTrue()
            ->and($e->context['media_ids'])->toBe(['5']));
    Http::assertNothingSent();
});

it('finds a recovering post without text through verify_reads', function () {
    config(['social.api.x.require_text' => false, 'social.api.x.verify_reads' => true]);
    Http::fake(XFixtures::routes([
        'api.x.com/2/users/1/tweets*' => Http::response(XFixtures::json('user_tweets_match_media'), 200),
    ]));

    $published = (new XTestDriver)->resume(
        XFixtures::post(caption: null, credentials: ['user_id' => '1']),
        ['phase' => 'tweet', 'media_ids' => ['1880028106020515842'], Pending::RECOVERING => true],
    );

    expect($published->result->platformPostId)->toBe('1880028106020515850');
});

it('publishes normally when a recovering re-send is accepted', function () {
    Http::fake(XFixtures::routes());

    $published = (new XTestDriver)->resume(XFixtures::post(), ['phase' => 'tweet', 'media_ids' => [], Pending::RECOVERING => true]);

    expect($published->result->platformPostId)->toBe('1880028106020515840');
    Http::assertSentCount(1);
});

it('still throws other refusals while recovering', function () {
    Http::fake(XFixtures::routes(['api.x.com/2/tweets' => Http::response(XFixtures::json('error_forbidden'), 403)]));

    expect(fn () => (new XTestDriver)->resume(XFixtures::post(), ['phase' => 'tweet', 'media_ids' => [], Pending::RECOVERING => true]))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::InsufficientPermissions));
});

it('looks the post up before re-sending when verify_reads is on (text match)', function () {
    config(['social.api.x.verify_reads' => true]);
    Http::fake(XFixtures::routes([
        'api.x.com/2/users/2244994945/tweets*' => Http::response(XFixtures::json('user_tweets_match_text'), 200),
    ]));
    $since = time() - 300;

    $published = (new XTestDriver)->resume(
        XFixtures::post(caption: 'Fish & chips at https://example.com/menu?day=friday  tonight', credentials: ['user_id' => '2244994945']),
        ['phase' => 'tweet', 'media_ids' => [], Pending::RECOVERING => true, Pending::COMMITTING_SINCE => $since],
    );

    expect($published->result->platformPostId)->toBe('1880028106020515840')
        ->and($published->result->url)->toBe('https://x.com/i/status/1880028106020515840')
        ->and($published->result->payload)->toMatchArray(['recovered' => true, 'verified' => true]);

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/2/tweets'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/2/users/2244994945/tweets')
        && $request['start_time'] === gmdate('Y-m-d\TH:i:s\Z', $since - 120)
        && (string) $request['max_results'] === '20'
        && $request['exclude'] === 'retweets'
        && str_contains($request['tweet.fields'], 'attachments'));
});

it('matches a recovered media post by its media key, finding the user through /2/users/me', function () {
    config(['social.api.x.verify_reads' => true]);
    Http::fake(XFixtures::routes([
        'api.x.com/2/users/me' => Http::response(XFixtures::json('users_me'), 200),
        'api.x.com/2/users/2244994945/tweets*' => Http::response(XFixtures::json('user_tweets_match_media'), 200),
    ]));

    $published = (new XTestDriver)->resume(
        XFixtures::post(caption: 'A caption X rewrote'),
        ['phase' => 'tweet', 'media_ids' => ['1880028106020515842'], Pending::RECOVERING => true],
    );

    expect($published->result->platformPostId)->toBe('1880028106020515850');
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/2/tweets'));
});

/**
 * Lookup failures and incomplete answers, for the recovery datasets: the
 * route, the response body (a fixture name or an inline body), its status and
 * the credentials of the post.
 *
 * @return array<string, array{0: string, 1: string|array<string, mixed>, 2: int, 3: array<string, string>}>
 */
function xLookupNotConfirmed(): array
{
    return [
        'read refused (403)' => ['api.x.com/2/users/1/tweets*', 'error_forbidden', 403, ['user_id' => '1']],
        'read credits depleted (402)' => ['api.x.com/2/users/1/tweets*', 'error_credits_depleted', 402, ['user_id' => '1']],
        'read rate limited (429)' => ['api.x.com/2/users/1/tweets*', 'error_too_many_requests', 429, ['user_id' => '1']],
        'read server error (503)' => ['api.x.com/2/users/1/tweets*', 'error_service_unavailable', 503, ['user_id' => '1']],
        'me refused' => ['api.x.com/2/users/me', 'error_unauthorized', 401, []],
        'me without id' => ['api.x.com/2/users/me', ['data' => []], 200, []],
        'more posts than one page' => ['api.x.com/2/users/1/tweets*', array_merge(XFixtures::json('user_tweets_no_match'), ['meta' => ['result_count' => 20, 'next_token' => '7140dibdnow9c7btw4b3znrlb6qvj7cpkdjn0fe2ffyrd']]), 200, ['user_id' => '1']],
    ];
}

it('re-sends a post with text when the lookup finds nothing, or cannot run', function (string $route, string|array $body, int $status, array $credentials) {
    config(['social.api.x.verify_reads' => true]);
    Http::fake(XFixtures::routes([$route => Http::response(is_array($body) ? $body : XFixtures::json($body), $status)]));

    $published = (new XTestDriver)->resume(
        XFixtures::post(credentials: $credentials),
        ['phase' => 'tweet', 'media_ids' => [], Pending::RECOVERING => true],
    );

    // X's 403 duplicate check covers a re-send of a post with text.
    expect($published->result->platformPostId)->toBe('1880028106020515840');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/2/tweets')
        && $request->data()['text'] === 'Hello from the package');
})->with([
    'no match' => ['api.x.com/2/users/1/tweets*', 'user_tweets_no_match', 200, ['user_id' => '1']],
    'empty timeline' => ['api.x.com/2/users/1/tweets*', 'user_tweets_empty', 200, ['user_id' => '1']],
    'match without an id' => ['api.x.com/2/users/1/tweets*', 'user_tweets_match_without_id', 200, ['user_id' => '1']],
    ...xLookupNotConfirmed(),
]);

it('never re-sends a recovering post without text unless the lookup ran and did not find it', function (string $route, string|array $body, int $status, array $credentials) {
    config(['social.api.x.require_text' => false, 'social.api.x.verify_reads' => true]);
    Http::fake(XFixtures::routes([$route => Http::response(is_array($body) ? $body : XFixtures::json($body), $status)]));

    expect(fn () => (new XTestDriver)->resume(
        XFixtures::post(caption: null, credentials: $credentials),
        ['phase' => 'tweet', 'media_ids' => ['1880028106020515842'], Pending::RECOVERING => true],
    ))->toThrow(function (SocialPosterException $e) {
        expect($e->possiblyPublished())->toBeTrue()
            ->and($e->context['media_ids'])->toBe(['1880028106020515842']);

        // A transient lookup failure is retried (the job keeps the run recovering); anything else is final.
        $e instanceof TemporaryException
            ? expect($e->reason)->toBeIn([FailureReason::RateLimited, FailureReason::ServerError])
            : expect($e->reason)->toBe(FailureReason::OutcomeUnknown);
    });

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/2/tweets'));
})->with(xLookupNotConfirmed());

it('re-sends a recovering post without text once the lookup confirmed it is not there', function () {
    config(['social.api.x.require_text' => false, 'social.api.x.verify_reads' => true]);
    Http::fake(XFixtures::routes([
        'api.x.com/2/users/1/tweets*' => Http::response(XFixtures::json('user_tweets_no_match'), 200),
    ]));

    $published = (new XTestDriver)->resume(
        XFixtures::post(caption: null, credentials: ['user_id' => '1']),
        ['phase' => 'tweet', 'media_ids' => ['1880028106020515899'], Pending::RECOVERING => true],
    );

    expect($published->result->platformPostId)->toBe('1880028106020515840');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/2/tweets')
        && $request->data() === ['media' => ['media_ids' => ['1880028106020515899']]]);
});

it('does not read anything on a normal (not recovering) create even with verify_reads', function () {
    config(['social.api.x.verify_reads' => true]);
    Http::fake(XFixtures::routes());

    (new XTestDriver)->resume(XFixtures::post(credentials: ['user_id' => '1']), ['phase' => 'tweet', 'media_ids' => []]);

    Http::assertSentCount(1);
});

it('requires every uploaded media id to match', function () {
    $driver = new XTestDriver;
    $tweet = ['id' => '9', 'text' => 'x', 'attachments' => ['media_keys' => ['3_1', '3_2']]];

    expect($driver->call('matchesTweet', $tweet, 'x', ['1', '2']))->toBeTrue()
        ->and($driver->call('matchesTweet', $tweet, 'x', ['1', '3']))->toBeFalse()
        ->and($driver->call('matchesTweet', ['id' => '9', 'text' => ''], '', []))->toBeFalse()
        ->and($driver->call('matchesTweet', ['id' => '9', 'text' => 'short', 'note_tweet' => ['text' => 'A long post']], 'A long post', []))->toBeTrue();
});

// --- Credentials and tokens ---------------------------------------------------------------

it('fails fast without an access token', function (string $method, array $args) {
    Http::fake();
    $post = XFixtures::post(using: new Credentials(['user_id' => '1']));

    expect(fn () => (new XTestDriver)->{$method}($post, ...$args))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::Misconfigured)
            ->and($e->context['missing_credentials'])->toBe(['access_token']));
    Http::assertNothingSent();
})->with([
    'publish' => ['publish', []],
    'resume' => ['resume', [['phase' => 'tweet']]],
]);

it('refuses an expired token before uploading or creating', function () {
    Http::fake();
    $post = XFixtures::post(credentials: ['expires_at' => time() - 10]);

    expect(fn () => (new XTestDriver)->publish($post))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::InvalidToken)
            ->and($e->context['token_expired'])->toBeTrue());
    expect(fn () => (new XTestDriver)->resume($post, ['phase' => 'tweet']))
        ->toThrow(PermanentException::class);
    Http::assertNothingSent();
});

it('asks for fresh credentials instead when a resolver is bound', function () {
    app()->instance(CredentialsResolver::class, new class implements CredentialsResolver
    {
        public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
        {
            return $dispatched;
        }
    });
    Http::fake();

    expect(fn () => (new XTestDriver)->resume(XFixtures::post(credentials: ['expires_at' => time() + 30]), ['phase' => 'tweet']))
        ->toThrow(fn (TemporaryException $e) => expect($e->reason)->toBe(FailureReason::InvalidToken)
            ->and($e->retryAfter)->toBe(0)
            ->and($e->context['reresolve'])->toBeTrue());
});

it('honours api.x.token_margin', function () {
    config(['social.api.x.token_margin' => 600]);
    Http::fake();

    expect(fn () => (new XTestDriver)->publish(XFixtures::post(credentials: ['expires_at' => time() + 300])))
        ->toThrow(PermanentException::class);
});

it('identifies the account by user_id, never by the token (it rotates every 2 hours)', function () {
    $driver = new XTestDriver;

    expect($driver->accountKey(new Credentials(['access_token' => 't', 'user_id' => '2244994945'])))->toBe('2244994945')
        ->and($driver->accountKey(new Credentials(['access_token' => 't'])))->toBe('');
});

it('says once that an account without user_id is not kept apart from other X accounts', function () {
    config(['social.error_logging' => true]);
    Log::spy();
    Http::fake(XFixtures::routes());
    $driver = new XTestDriver;

    $driver->publish(XFixtures::post());
    $driver->publish(XFixtures::post(credentials: ['access_token' => 'refreshed-token']));

    Log::shouldHaveReceived('notice')
        ->withArgs(fn (string $message) => str_contains($message, 'user_id') && ! str_contains($message, 'x-token'))
        ->once();
});

it('stays quiet when the account has a stable identity or nothing persists keys', function (array $credentials, ?string $store) {
    config(['social.error_logging' => true]);

    if ($store !== null) {
        app()->instance(IdempotencyStore::class, new $store);
    }

    Log::spy();
    Http::fake(XFixtures::routes());

    (new XTestDriver)->publish(XFixtures::post(credentials: $credentials));

    Log::shouldNotHaveReceived('notice');
})->with([
    'user_id' => [['user_id' => '2244994945'], null],
    'account' => [['account' => 'acme-x'], null],
    'null store' => [[], NullIdempotencyStore::class],
]);
