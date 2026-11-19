<?php

namespace SocialPoster\Tests\Feature\Platforms;

use const SocialPoster\Tests\Fixtures\Facebook\FB_PAGE;
use const SocialPoster\Tests\Fixtures\Facebook\FB_RUPLOAD;
use const SocialPoster\Tests\Fixtures\Facebook\FB_TOKEN;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Events\PostProcessing;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Gateways\LocalMediaGateway;
use SocialPoster\Platforms\Facebook\FacebookOptions;
use SocialPoster\Tests\Fixtures\Facebook\FbFailingGateway;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\Published;
use Throwable;

use function SocialPoster\Tests\Fixtures\Facebook\fbConnectionFailure;
use function SocialPoster\Tests\Fixtures\Facebook\fbDriver;
use function SocialPoster\Tests\Fixtures\Facebook\fbFake;
use function SocialPoster\Tests\Fixtures\Facebook\fbFixture;
use function SocialPoster\Tests\Fixtures\Facebook\fbPost;
use function SocialPoster\Tests\Fixtures\Facebook\fbQuery;
use function SocialPoster\Tests\Fixtures\Facebook\fbResume;
use function SocialPoster\Tests\Fixtures\Facebook\fbStatus;
use function SocialPoster\Tests\Fixtures\Facebook\fbTempFile;

require_once __DIR__.'/../../Fixtures/facebook/helpers.php';

const FB_VERTICAL = 'https://cdn.example.com/vertical.mp4';
const FB_WIDE = 'https://cdn.example.com/wide.mp4';

beforeEach(fn () => config(['social.api.facebook.graph_version' => 'v25.0']));

/** @return array<string, MediaMetadata> */
function fbVideoMeta(): array
{
    return [
        FB_VERTICAL => new MediaMetadata(width: 1080, height: 1920, durationSeconds: 30, frameRate: 30),
        FB_WIDE => new MediaMetadata(width: 1920, height: 1080, durationSeconds: 30, frameRate: 30),
    ];
}

/** Routes for a Reel (or story) upload session start plus the hosted-file upload. */
function fbStartRoutes(string $endpoint = 'video_reels', array $upload = ['success' => true]): array
{
    return [
        'POST *v25.0/'.FB_PAGE.'/'.$endpoint => Http::response(['video_id' => '7788', 'upload_url' => FB_RUPLOAD]),
        'POST '.FB_RUPLOAD => Http::response($upload),
    ];
}

// --- Regular videos ---------------------------------------------------------------

it('commits a regular video and uploads it with description, title and extra', function () {
    fbFake([
        'POST *v25.0/'.FB_PAGE.'/videos' => Http::response(['id' => '4455']),
        'GET *v25.0/4455?fields=permalink_url*' => Http::response(['permalink_url' => '/'.FB_PAGE.'/videos/4455/']),
    ]);
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_WIDE)], caption: ' A landscape video ', options: new FacebookOptions(extra: ['content_category' => 'OTHER']), title: 'My title');

    $outcome = $driver->publish($post);

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->commits)->toBeTrue()
        ->and($outcome->state['phase'])->toBe('video_create')
        ->and($outcome->state['params'])->toBe(['file_url' => FB_WIDE, 'description' => 'A landscape video', 'title' => 'My title']);

    $result = fbResume($driver, $post, $outcome);

    expect($result->result->platformPostId)->toBe('4455')
        ->and($result->result->idKind)->toBe('video_id')
        ->and($result->result->url)->toBe('https://www.facebook.com/'.FB_PAGE.'/videos/4455/');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/videos')
        && $request->isForm()
        && $request['file_url'] === FB_WIDE
        && $request['title'] === 'My title'
        && $request['content_category'] === 'OTHER');
});

it('sends the thumbnail as uploaded bytes, from the options or the Media::video thumbnail', function (bool $fromOptions) {
    $thumb = fbTempFile('png', 'png-bytes');
    fbFake([
        'POST *v25.0/'.FB_PAGE.'/videos' => Http::response(['id' => '4455']),
        'GET *fields=permalink_url*' => Http::response([], 500),
    ]);
    $driver = fbDriver(fbVideoMeta());
    $post = $fromOptions
        ? fbPost(media: [Media::video(FB_WIDE)], options: new FacebookOptions(thumbnail: Media::image($thumb)))
        : fbPost(media: [Media::video(FB_WIDE, thumbnail: $thumb)], caption: '');

    $result = fbResume($driver, $post, $driver->publish($post));

    expect($result->result->url)->toBe('https://www.facebook.com/4455');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/videos')
        && $request->isMultipart()
        && collect($request->data())->contains(fn ($part) => $part['name'] === 'thumb' && $part['contents'] === 'png-bytes' && $part['filename'] === 'thumbnail.png')
        && collect($request->data())->contains(fn ($part) => $part['name'] === 'file_url' && $part['contents'] === FB_WIDE));
})->with(['options thumbnail' => true, 'media thumbnail' => false]);

it('posts the video without a Media::video() cover Facebook cannot take or read, and says so', function (string $cover) {
    fbFake([
        'POST *v25.0/'.FB_PAGE.'/videos' => Http::response(['id' => '4455']),
        'GET *fields=permalink_url*' => Http::response(['permalink_url' => 'https://www.facebook.com/4455']),
    ]);
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_WIDE, thumbnail: $cover === 'webp' ? fbTempFile('webp', 'webp-bytes') : '/no/such/cover.jpg')]);

    $result = fbResume($driver, $post, $driver->publish($post));

    expect($result->result->platformPostId)->toBe('4455')
        ->and($result->result->payload['thumbnail_warning'])->toBeString();
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/videos') && $request->isForm() && $request['file_url'] === FB_WIDE);
})->with(['format Facebook refuses' => 'webp', 'unreadable' => 'missing']);

it('keeps an explicit FacebookOptions thumbnail strict, but says nothing was sent when it cannot be read', function (Throwable $error, string $class) {
    fbFake([]);
    $cover = 'https://cdn.example.com/thumb.jpg';
    $driver = fbDriver(fbVideoMeta(), new FbFailingGateway($cover, $error));
    $post = fbPost(media: [Media::video(FB_WIDE)], options: new FacebookOptions(thumbnail: Media::image($cover)));

    expect(fn () => fbResume($driver, $post, $driver->publish($post)))
        ->toThrow(fn (SocialPosterException $e) => expect($e)->toBeInstanceOf($class)
            ->and($e->context['request_sent'])->toBeFalse()
            ->and($e->possiblyPublished())->toBeFalse());

    Http::assertNothingSent();
})->with([
    'cover host 503' => [new TemporaryException('The media URL returned HTTP 503.', null, FailureReason::Network, null, ['http_status' => 503]), TemporaryException::class],
    'cover gone' => [new PermanentException('The media URL returned HTTP 404.', null, FailureReason::MediaRejected, ['http_status' => 404]), PermanentException::class],
]);

it('skips a Media::video() cover whose download fails, instead of failing the video', function () {
    fbFake([
        'POST *v25.0/'.FB_PAGE.'/videos' => Http::response(['id' => '4455']),
        'GET *fields=permalink_url*' => Http::response(['permalink_url' => 'https://www.facebook.com/4455']),
    ]);
    $cover = 'https://cdn.example.com/thumb.jpg';
    $driver = fbDriver(fbVideoMeta(), new FbFailingGateway($cover, new TemporaryException('The media URL returned HTTP 503.', null, FailureReason::Network, null, ['http_status' => 503])));
    $post = fbPost(media: [Media::video(FB_WIDE, thumbnail: $cover)], caption: '');

    $result = fbResume($driver, $post, $driver->publish($post));

    expect($result->result->platformPostId)->toBe('4455')
        ->and($result->result->payload['thumbnail_warning'])->toContain('without it');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/videos') && $request->isForm());
});

it('refuses a scheduled create whose time is now less than 10 minutes away, before sending it', function () {
    fbFake([]);
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_WIDE)], options: new FacebookOptions(scheduledAt: CarbonImmutable::now()->addMinutes(9)));

    expect(fn () => $driver->resume($post, ['phase' => 'video_create', 'params' => ['file_url' => FB_WIDE, 'published' => false, 'scheduled_publish_time' => time() + 540], 'since' => time()]))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::Validation)
            ->and($e->getMessage())->toContain('less than 10 minutes away'));

    Http::assertNothingSent();
});

it('schedules a regular video and sends booleans as words', function () {
    $at = CarbonImmutable::now()->addDays(3);
    fbFake(['POST *v25.0/'.FB_PAGE.'/videos' => Http::response(['id' => '4455'])]);
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_WIDE)], options: new FacebookOptions(scheduledAt: $at));

    $result = fbResume($driver, $post, $driver->publish($post));

    expect($result->result->state)->toBe(PostResult::STATE_SCHEDULED)->and($result->result->url)->toBeNull();
    Http::assertSent(fn (Request $request) => $request['published'] === 'false' && (int) $request['scheduled_publish_time'] === $at->getTimestamp());
});

it('looks a regular video up by description when recovering', function () {
    fbFake([
        'GET *v25.0/'.FB_PAGE.'/videos*' => Http::response(['data' => [
            ['id' => '1', 'description' => 'Other', 'created_time' => gmdate('Y-m-d\TH:i:sO')],
            ['id' => '4455', 'description' => 'Hello Facebook', 'title' => 'T', 'created_time' => gmdate('Y-m-d\TH:i:sO')],
        ]]),
        'GET *v25.0/4455?fields=permalink_url*' => Http::response(['permalink_url' => 'https://www.facebook.com/watch/?v=4455']),
    ]);
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_WIDE)], title: 'T');

    $result = fbResume($driver, $post, $driver->publish($post), [Pending::RECOVERING => true]);

    expect($result->result->platformPostId)->toBe('4455')->and($result->result->payload['recovered'])->toBeTrue();
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/videos?') && fbQuery($request)['fields'] === 'id,title,description,created_time');
});

it('uploads a regular video when the lookup finds no match', function () {
    fbFake([
        'GET *v25.0/'.FB_PAGE.'/videos*' => Http::response(['data' => [['id' => '1', 'description' => 'Hello Facebook', 'title' => 'Other title']]]),
        'POST *v25.0/'.FB_PAGE.'/videos' => Http::response(['id' => '4455']),
        'GET *fields=permalink_url*' => Http::response(['permalink_url' => 'https://www.facebook.com/4455']),
    ]);
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_WIDE)], title: 'T');

    expect(fbResume($driver, $post, $driver->publish($post), [Pending::RECOVERING => true])->result->platformPostId)->toBe('4455');
});

it('cannot tell a video without description or title apart when others appeared', function () {
    fbFake(['GET *v25.0/'.FB_PAGE.'/videos*' => Http::sequence()
        ->push(['data' => [['id' => '1', 'created_time' => gmdate('Y-m-d\TH:i:sO')]]])
        ->push(['data' => []]),
    ]);
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_WIDE)], caption: '');
    $state = $driver->publish($post)->state + [Pending::RECOVERING => true];

    expect(fn () => $driver->resume($post, $state))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::OutcomeUnknown)->and($e->possiblyPublished())->toBeTrue());

    fbFake([
        'GET *v25.0/'.FB_PAGE.'/videos*' => Http::response(['data' => []]),
        'POST *v25.0/'.FB_PAGE.'/videos' => Http::response(['id' => '4455']),
        'GET *fields=permalink_url*' => Http::response(['permalink_url' => 'https://www.facebook.com/4455']),
    ]);

    expect($driver->resume($post, $state)->result->platformPostId)->toBe('4455');
});

it('goes to video_verify when the upload times out, and maps refusals and missing ids', function () {
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_WIDE)]);
    $state = $driver->publish($post)->state;

    fbFake(['POST *v25.0/'.FB_PAGE.'/videos' => fbConnectionFailure()]);
    $retry = $driver->resume($post, $state);
    expect($retry->state['phase'])->toBe('video_verify')->and($retry->recheckAfter)->toBe(60)->and($retry->commits)->toBeTrue();

    fbFake(['POST *v25.0/'.FB_PAGE.'/videos' => Http::response(fbFixture('download_timeout'), 400)]);
    expect(fn () => $driver->resume($post, $state))
        ->toThrow(fn (TemporaryException $e) => expect($e->reason)->toBe(FailureReason::Timeout)->and($e->retryAfter)->toBe(30));

    fbFake(['POST *v25.0/'.FB_PAGE.'/videos' => Http::response(['success' => true])]);
    expect(fn () => $driver->resume($post, $state))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::OutcomeUnknown));
});

it('logs a warning when a video goes out as a regular video only because its shape is unknown', function () {
    config(['social.error_logging' => true]);
    Log::spy();
    $driver = fbDriver();

    $outcome = $driver->publish(fbPost(media: [Media::video('https://cdn.example.com/unknown.mp4')]));

    expect($outcome->state['phase'])->toBe('video_create');
    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'dimensions are unknown'));
});

// --- Reels -------------------------------------------------------------------------

it('uploads a vertical video as a Reel and commits the finish a minute later', function () {
    fbFake(fbStartRoutes());
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_VERTICAL)], caption: ' Reel caption ');

    $outcome = $driver->publish($post);

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->commits)->toBeTrue()
        ->and($outcome->recheckAfter)->toBe(60)
        ->and($outcome->reason)->toBe(PostProcessing::REASON_PROCESSING)
        ->and($outcome->state)->toBe(['phase' => 'reel_finish', 'video_id' => '7788', 'caption' => 'Reel caption']);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/video_reels') && $request['upload_phase'] === 'start');
    Http::assertSent(fn (Request $request) => $request->url() === FB_RUPLOAD
        && $request->hasHeader('Authorization', 'OAuth '.FB_TOKEN)
        && $request->hasHeader('file_url', FB_VERTICAL));
});

it('routes by asReel over the shape, and by Media::$meta when there is no inspector data', function (?bool $asReel, string $source, array $meta, string $phase) {
    fbFake(fbStartRoutes());
    $driver = fbDriver(fbVideoMeta());

    $outcome = $driver->publish(fbPost(media: [Media::video($source, meta: $meta)], options: new FacebookOptions(asReel: $asReel)));

    expect($outcome->state['phase'])->toBe($phase);
})->with([
    'forced reel' => [true, FB_WIDE, [], 'reel_finish'],
    'forced regular' => [false, FB_VERTICAL, [], 'video_create'],
    'meta vertical' => [null, 'https://cdn.example.com/meta.mp4', ['width' => 1080, 'height' => 1918], 'reel_finish'],
    'meta square' => [null, 'https://cdn.example.com/meta.mp4', ['width' => 1080, 'height' => 1080], 'video_create'],
]);

it('rejects a hosted-file upload that is not confirmed or fails', function (array|string $body, int $status, string $class, FailureReason $reason) {
    fbFake([
        'POST *v25.0/'.FB_PAGE.'/video_reels' => Http::response(['video_id' => '7788', 'upload_url' => FB_RUPLOAD]),
        'POST '.FB_RUPLOAD => Http::response(is_string($body) ? fbFixture($body) : $body, $status),
    ]);
    $driver = fbDriver(fbVideoMeta());

    expect(fn () => $driver->publish(fbPost(media: [Media::video(FB_VERTICAL)])))
        ->toThrow(fn (SocialPosterException $e) => expect($e)->toBeInstanceOf($class)->and($e->reason)->toBe($reason));
})->with([
    'no success' => [['h' => 'abc'], 200, TemporaryException::class, FailureReason::ServerError],
    'processing failed' => ['rupload_processing_failed', 400, TemporaryException::class, FailureReason::ServerError],
    'fetch failed' => ['rupload_fetch_failed', 400, PermanentException::class, FailureReason::MediaRejected],
    'oauth' => ['rupload_oauth', 400, PermanentException::class, FailureReason::InvalidToken],
    'graph error' => ['token_expired', 400, PermanentException::class, FailureReason::InvalidToken],
]);

it('fails the start call on an error or a missing upload url', function () {
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_VERTICAL)]);

    fbFake(['POST *v25.0/'.FB_PAGE.'/video_reels' => Http::response(fbFixture('permission_200'), 403)]);
    expect(fn () => $driver->publish($post))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::InsufficientPermissions));

    fbFake(['POST *v25.0/'.FB_PAGE.'/video_reels' => Http::response(['video_id' => '7788'])]);
    expect(fn () => $driver->publish($post))
        ->toThrow(fn (TemporaryException $e) => expect($e->context['missing_field'])->toBe('upload_url'));
});

it('uploads a local Reel file directly with offset and file_size headers', function () {
    $file = fbTempFile('mp4', str_repeat('v', 2048));
    fbFake(fbStartRoutes());
    $driver = fbDriver([$file => new MediaMetadata(width: 1080, height: 1920)], new LocalMediaGateway);
    $post = fbPost(media: [Media::video($file)]);

    expect($driver->validate($post)->has('media.0'))->toBeFalse();

    $outcome = $driver->publish($post);

    expect($outcome->state['phase'])->toBe('reel_finish');
    Http::assertSent(fn (Request $request) => $request->url() === FB_RUPLOAD
        && $request->hasHeader('offset', '0')
        && $request->hasHeader('file_size', '2048')
        && $request->body() === str_repeat('v', 2048)
        && ! $request->hasHeader('file_url'));
});

it('fails a local upload whose file cannot be read', function () {
    fbFake(fbStartRoutes());
    $driver = fbDriver(['/no/such/file.mp4' => new MediaMetadata(width: 1080, height: 1920)]);

    expect(fn () => $driver->publish(fbPost(media: [Media::video('/no/such/file.mp4')])))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::MediaRejected));
});

it('finishes a Reel after checking its status, then polls until it is published', function () {
    fbFake([
        'GET *v25.0/7788?fields=status*' => Http::sequence()
            ->push(fbStatus('upload_complete', 'complete', 'not_started'))
            ->push(fbStatus('processing', 'complete', 'in_progress'))
            ->push(fbStatus('ready', 'complete', 'complete', 'complete', 'published')),
        'POST *v25.0/'.FB_PAGE.'/video_reels' => Http::response(['success' => true, 'post_id' => '9911']),
        'GET *v25.0/'.FB_PAGE.'_9911?fields=permalink_url*' => Http::response(['permalink_url' => 'https://www.facebook.com/reel/9911']),
    ]);
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_VERTICAL)], options: new FacebookOptions(extra: ['is_ai_generated' => false, 'video_state' => 'DRAFT']), title: 'Reel title');

    $poll = $driver->resume($post, ['phase' => 'reel_finish', 'video_id' => '7788', 'caption' => 'Reel caption']);

    expect($poll)->toBeInstanceOf(Pending::class)
        ->and($poll->commits)->toBeFalse()
        ->and($poll->committed)->toBeTrue()
        ->and($poll->recheckAfter)->toBe(20)
        ->and($poll->deadline)->toBeGreaterThan(time())
        ->and($poll->state['phase'])->toBe('reel_status')
        ->and($poll->state['post_id'])->toBe(FB_PAGE.'_9911')
        ->and($poll->state['deadline'])->toBe($poll->deadline);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/video_reels')
        && $request['upload_phase'] === 'finish'
        && $request['video_id'] === '7788'
        && $request['video_state'] === 'PUBLISHED'
        && $request['description'] === 'Reel caption'
        && $request['title'] === 'Reel title'
        && $request['is_ai_generated'] === false);

    $again = fbResume($driver, $post, $poll);

    expect($again)->toBeInstanceOf(Pending::class)
        ->and($again->state['polls'])->toBe(1)
        ->and($again->committed)->toBeTrue()
        ->and($again->deadline)->toBe($poll->deadline);

    $done = fbResume($driver, $post, $again);

    expect($done)->toBeInstanceOf(Published::class)
        ->and($done->result->platformPostId)->toBe(FB_PAGE.'_9911')
        ->and($done->result->idKind)->toBe('post_id')
        ->and($done->result->state)->toBe(PostResult::STATE_PUBLISHED)
        ->and($done->result->url)->toBe('https://www.facebook.com/reel/9911')
        ->and($done->result->payload)->toMatchArray(['video_id' => '7788', 'post_id' => FB_PAGE.'_9911']);
});

it('normalises the finish post id', function (array $finish, ?string $postId, string $id, string $kind) {
    fbFake([
        'GET *v25.0/7788?fields=status*' => Http::sequence()
            ->push(fbStatus('upload_complete', 'complete', 'not_started'))
            ->push(fbStatus('ready', 'complete', 'complete', 'complete', 'published')),
        'POST *v25.0/'.FB_PAGE.'/video_reels' => Http::response($finish),
        'GET *fields=permalink_url*' => Http::response([], 400),
    ]);
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_VERTICAL)]);

    $poll = $driver->resume($post, ['phase' => 'reel_finish', 'video_id' => '7788']);
    $done = fbResume($driver, $post, $poll);

    expect($poll->state['post_id'])->toBe($postId)
        ->and($done->result->platformPostId)->toBe($id)
        ->and($done->result->idKind)->toBe($kind)
        ->and($done->result->url)->toBe('https://www.facebook.com/'.$id);
})->with([
    'bare id' => [['success' => true, 'post_id' => '9911'], FB_PAGE.'_9911', FB_PAGE.'_9911', 'post_id'],
    'numeric id' => [['success' => true, 'post_id' => 9911], FB_PAGE.'_9911', FB_PAGE.'_9911', 'post_id'],
    'already prefixed' => [['success' => true, 'post_id' => '42_9911'], '42_9911', '42_9911', 'post_id'],
    'missing: the video id, never prefixed' => [['success' => true], null, '7788', 'video_id'],
]);

it('waits for an upload Facebook is still fetching, then finishes anyway after the checks', function () {
    config(['social.api.facebook.upload_checks' => 1]);
    fbFake([
        'GET *v25.0/7788?fields=status*' => Http::response(fbStatus('uploading', 'in_progress', 'not_started')),
        'POST *v25.0/'.FB_PAGE.'/video_reels' => Http::response(['success' => true, 'post_id' => '9911']),
    ]);
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_VERTICAL)]);

    $wait = $driver->resume($post, ['phase' => 'reel_finish', 'video_id' => '7788', 'caption' => 'c', Pending::ATTEMPT => 1]);

    expect($wait)->toBeInstanceOf(Pending::class)
        ->and($wait->commits)->toBeTrue()
        ->and($wait->recheckAfter)->toBe(30)
        ->and($wait->reason)->toBe(PostProcessing::REASON_PROCESSING)
        ->and($wait->state)->toBe(['upload_checks' => 1, 'phase' => 'reel_finish', 'video_id' => '7788', 'caption' => 'c']);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');

    $poll = fbResume($driver, $post, $wait);

    expect($poll->state['phase'])->toBe('reel_status');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['upload_phase'] === 'finish');
});

it('never finishes twice: a recovering finish whose publishing started goes straight to the poll', function () {
    fbFake(['GET *v25.0/7788?fields=status*' => Http::response(fbStatus('ready', 'complete', 'complete', 'in_progress'))]);
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_VERTICAL)]);

    $poll = $driver->resume($post, ['phase' => 'reel_finish', 'video_id' => '7788', Pending::RECOVERING => true]);

    expect($poll->state)->toMatchArray(['phase' => 'reel_status', 'post_id' => null, 'recovered' => true])
        ->and($poll->committed)->toBeTrue();
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('rejects a Reel whose upload or processing failed, with a readable reason', function (array $status, string $expected) {
    fbFake(['GET *v25.0/7788?fields=status*' => Http::response($status)]);
    $driver = fbDriver(fbVideoMeta());

    expect(fn () => $driver->resume(fbPost(media: [Media::video(FB_VERTICAL)]), ['phase' => 'reel_finish', 'video_id' => '7788']))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::MediaRejected)
            ->and($e->getMessage())->toContain($expected));
})->with([
    'aspect ratio' => [fbStatus('error', 'complete', 'error', overrides: ['processing_phase' => ['errors' => [['code' => 1363040, 'message' => 'Unsupported aspect ratio']]]]), 'aspect ratio'],
    'frame rate' => [fbStatus('error', 'complete', 'error', overrides: ['processing_phase' => ['error' => ['code' => 1363129, 'message' => 'Bad fps']]]), 'frame rate'],
    'upload failed' => [fbStatus('upload_failed', 'error', 'not_started', overrides: ['uploading_phase' => ['errors' => [['code' => 1363001, 'message' => 'Could not download']]]]), 'Could not download'],
    'expired' => [fbStatus('expired', 'complete', 'not_started'), 'upload session expired'],
]);

it('rejects a failed status during the poll too', function () {
    fbFake(['GET *v25.0/7788?fields=status*' => Http::response(fbStatus('error', 'complete', 'complete', 'error', overrides: ['publishing_phase' => ['errors' => [['code' => 1363128, 'message' => 'Too long']]]]))]);
    $driver = fbDriver(fbVideoMeta());

    expect(fn () => $driver->resume(fbPost(), ['phase' => 'reel_status', 'video_id' => '7788', 'polls' => 0]))
        ->toThrow(fn (PermanentException $e) => expect($e->getMessage())->toContain('length')->and($e->context['code'])->toBe(1363128));
});

it('reports a scheduled Reel as scheduled', function () {
    $at = CarbonImmutable::now()->addDays(5);
    fbFake([
        'GET *v25.0/7788?fields=status*' => Http::sequence()
            ->push(fbStatus('upload_complete', 'complete', 'not_started'))
            ->push(fbStatus('ready', 'complete', 'complete', 'complete', 'scheduled')),
        'POST *v25.0/'.FB_PAGE.'/video_reels' => Http::response(['success' => true, 'post_id' => '9911']),
    ]);
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_VERTICAL)], options: new FacebookOptions(scheduledAt: $at, extra: ['published' => false]));

    $done = fbResume($driver, $post, $driver->resume($post, ['phase' => 'reel_finish', 'video_id' => '7788']));

    expect($done->result->state)->toBe(PostResult::STATE_SCHEDULED)->and($done->result->url)->toBeNull();
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['video_state'] === 'SCHEDULED'
        && $request['scheduled_publish_time'] === $at->getTimestamp()
        && ! isset($request['published']));
    Http::assertSentCount(3);
});

it('reports a Reel still processing at the end of the poll budget as processing, not failed', function () {
    config(['social.api.facebook.status_polls' => 2]);
    fbFake([
        'GET *v25.0/7788?fields=status*' => Http::response(fbStatus('processing', 'complete', 'in_progress')),
        'GET *fields=permalink_url*' => Http::response(['permalink_url' => 'https://www.facebook.com/reel/9911']),
    ]);
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_VERTICAL)]);

    $done = $driver->resume($post, ['phase' => 'reel_status', 'kind' => 'reel', 'video_id' => '7788', 'post_id' => FB_PAGE.'_9911', 'polls' => 1]);

    expect($done)->toBeInstanceOf(Published::class)
        ->and($done->result->state)->toBe(PostResult::STATE_PROCESSING)
        ->and($done->result->payload['processing'])->toBeTrue();
});

it('ends the poll as processing before its own deadline, whatever the poll count', function () {
    fbFake([
        'GET *v25.0/7788?fields=status*' => Http::response(fbStatus('processing', 'complete', 'in_progress')),
        'GET *fields=permalink_url*' => Http::response(['permalink_url' => 'https://www.facebook.com/reel/9911']),
    ]);
    $driver = fbDriver(fbVideoMeta());

    // Queue lag ate the budget: the next poll would land after the deadline,
    // where the job would report a Reel Facebook accepted as failed.
    $done = $driver->resume(fbPost(), ['phase' => 'reel_status', 'kind' => 'reel', 'video_id' => '7788', 'post_id' => FB_PAGE.'_9911', 'polls' => 1, 'deadline' => time() + 10]);

    expect($done)->toBeInstanceOf(Published::class)
        ->and($done->result->state)->toBe(PostResult::STATE_PROCESSING)
        ->and($done->result->payload['processing'])->toBeTrue();
});

it('waits out a throttled status read inside the deadline, and reports processing when it cannot', function () {
    $usage = (string) json_encode([FB_PAGE => [['type' => 'pages', 'call_count' => 100, 'estimated_time_to_regain_access' => 15]]]);
    fbFake([
        'GET *v25.0/7788?fields=status*' => Http::response(fbFixture('throttle_page'), 400, ['X-Business-Use-Case-Usage' => $usage]),
        'GET *fields=permalink_url*' => Http::response(['permalink_url' => 'https://www.facebook.com/reel/9911']),
    ]);
    $driver = fbDriver(fbVideoMeta());
    $state = ['phase' => 'reel_status', 'kind' => 'reel', 'video_id' => '7788', 'post_id' => FB_PAGE.'_9911', 'polls' => 0];

    $deadline = time() + 3600;
    $wait = $driver->resume(fbPost(), $state + ['deadline' => $deadline]);

    expect($wait)->toBeInstanceOf(Pending::class)
        ->and($wait->recheckAfter)->toBe(900)
        ->and($wait->committed)->toBeTrue()
        ->and($wait->state['polls'])->toBe(1)
        ->and($wait->deadline)->toBe($deadline);

    $done = $driver->resume(fbPost(), $state + ['deadline' => time() + 300]);

    expect($done)->toBeInstanceOf(Published::class)
        ->and($done->result->state)->toBe(PostResult::STATE_PROCESSING)
        ->and($done->result->payload['status_unavailable'])->toBeTrue();
});

it('refuses a scheduled Reel finish when the time is now less than 10 minutes away', function () {
    fbFake(['GET *v25.0/7788?fields=status*' => Http::response(fbStatus('upload_complete', 'complete', 'not_started'))]);
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_VERTICAL)], options: new FacebookOptions(scheduledAt: CarbonImmutable::now()->addMinutes(9)));

    expect(fn () => $driver->resume($post, ['phase' => 'reel_finish', 'video_id' => '7788', 'caption' => 'c']))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::Validation)
            ->and($e->possiblyPublished())->toBeFalse()
            ->and($e->getMessage())->toContain('less than 10 minutes away'));

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('does not send a FacebookOptions thumbnail with a Reel, and logs that it was ignored', function () {
    config(['social.error_logging' => true]);
    Log::spy();
    fbFake(fbStartRoutes());
    $driver = fbDriver(fbVideoMeta());

    $outcome = $driver->publish(fbPost(media: [Media::video(FB_VERTICAL)], options: new FacebookOptions(thumbnail: Media::image('https://cdn.example.com/cover.jpg'))));

    expect($outcome->state['phase'])->toBe('reel_finish');
    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'thumbnail'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'cover.jpg'));
});

it('does not trust a finish without success: the poll decides', function () {
    config(['social.api.facebook.status_polls' => 1]);
    fbFake([
        'GET *v25.0/7788?fields=status*' => Http::sequence()
            ->push(fbStatus('upload_complete', 'complete', 'not_started'))
            ->push(fbStatus('processing', 'complete', 'complete', 'not_started')),
        'POST *v25.0/'.FB_PAGE.'/video_reels' => Http::response(['post_id' => '9911']),
    ]);
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_VERTICAL)]);

    $poll = $driver->resume($post, ['phase' => 'reel_finish', 'video_id' => '7788']);

    expect($poll->state['unconfirmed'])->toBeTrue();

    expect(fn () => fbResume($driver, $post, $poll))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::OutcomeUnknown)->and($e->possiblyPublished())->toBeTrue());
});

it('fails a finish that says success: false, or that Facebook refuses', function () {
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_VERTICAL)]);

    fbFake([
        'GET *v25.0/7788?fields=status*' => Http::response(fbStatus('upload_complete', 'complete', 'not_started')),
        'POST *v25.0/'.FB_PAGE.'/video_reels' => Http::response(['success' => false]),
    ]);
    expect(fn () => $driver->resume($post, ['phase' => 'reel_finish', 'video_id' => '7788']))
        ->toThrow(fn (PermanentException $e) => expect($e->getMessage())->toContain('did not confirm the Reel')
            ->and($e->context['body'])->toBe(['success' => false]));

    fbFake([
        'GET *v25.0/7788?fields=status*' => Http::response(fbStatus('upload_complete', 'complete', 'not_started')),
        'POST *v25.0/'.FB_PAGE.'/video_reels' => Http::response(fbFixture('reel_aspect_ratio'), 400),
    ]);
    expect(fn () => $driver->resume($post, ['phase' => 'reel_finish', 'video_id' => '7788']))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::MediaRejected)->and($e->getMessage())->toContain('aspect ratio'));
});

it('reports a live Reel as processing when its status cannot be read, and polls again after transient reads', function () {
    $driver = fbDriver(fbVideoMeta());
    $state = ['phase' => 'reel_status', 'kind' => 'reel', 'video_id' => '7788', 'post_id' => FB_PAGE.'_9911', 'polls' => 0];

    fbFake([
        'GET *v25.0/7788?fields=status*' => Http::response(fbFixture('permission_10'), 403),
        'GET *fields=permalink_url*' => Http::response(['permalink_url' => 'https://www.facebook.com/reel/9911']),
    ]);
    $done = $driver->resume(fbPost(), $state);
    expect($done->result->state)->toBe(PostResult::STATE_PROCESSING)
        ->and($done->result->payload['status_unavailable'])->toBeTrue();

    // A transient read stays in the poll (the driver owns the wait, so a
    // throttle cannot push the job past the poll's deadline).
    fbFake(['GET *v25.0/7788?fields=status*' => Http::response(fbFixture('throttle_page'), 400)]);
    $throttled = $driver->resume(fbPost(), $state);
    expect($throttled)->toBeInstanceOf(Pending::class)
        ->and($throttled->recheckAfter)->toBe(900)
        ->and($throttled->state['polls'])->toBe(1);

    fbFake(['GET *v25.0/7788?fields=status*' => fbConnectionFailure()]);
    $lost = $driver->resume(fbPost(), $state);
    expect($lost)->toBeInstanceOf(Pending::class)
        ->and($lost->recheckAfter)->toBe(20)
        ->and($lost->committed)->toBeTrue();
});

it('still finishes v1.0 continuations (reel_finish with caption, story_finish)', function () {
    fbFake([
        'GET *v25.0/555?fields=status*' => Http::response(fbStatus('upload_complete', 'complete', 'not_started')),
        'POST *v25.0/'.FB_PAGE.'/video_reels' => Http::response(['success' => true, 'post_id' => '9911']),
        'POST *v25.0/'.FB_PAGE.'/video_stories' => Http::response(['success' => true, 'post_id' => '8822']),
    ]);
    $driver = fbDriver(fbVideoMeta());

    $reel = $driver->resume(fbPost(media: [Media::video(FB_VERTICAL)], caption: 'new caption'), ['phase' => 'reel_finish', 'video_id' => '555', 'caption' => 'v1 caption']);
    $story = $driver->resume(fbPost(media: [Media::video(FB_VERTICAL)], options: new FacebookOptions(isStory: true)), ['phase' => 'story_finish', 'video_id' => '555']);

    expect($reel->state['phase'])->toBe('reel_status')
        ->and($story->state)->toMatchArray(['phase' => 'reel_status', 'kind' => 'story', 'post_id' => FB_PAGE.'_8822']);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && ($request->data()['description'] ?? null) === 'v1 caption');
});

// --- Stories ---------------------------------------------------------------------

it('publishes a video story: upload, finish with extra but no scheduling, then poll until ready', function () {
    fbFake(fbStartRoutes('video_stories') + [
        'GET *v25.0/7788?fields=status*' => Http::sequence()
            ->push(fbStatus('upload_complete', 'complete', 'not_started'))
            ->push(fbStatus('ready', 'complete', 'complete')),
    ]);
    $driver = fbDriver(fbVideoMeta());
    $post = fbPost(media: [Media::video(FB_VERTICAL)], options: new FacebookOptions(isStory: true, extra: ['published' => false, 'scheduled_publish_time' => 1893456000, 'is_ai_generated' => true]));

    $finish = $driver->publish($post);

    expect($finish->state)->toBe(['phase' => 'story_finish', 'video_id' => '7788'])
        ->and($finish->commits)->toBeTrue();

    fbFake([
        'GET *v25.0/7788?fields=status*' => Http::sequence()
            ->push(fbStatus('upload_complete', 'complete', 'not_started'))
            ->push(fbStatus('ready', 'complete', 'complete')),
        'POST *v25.0/'.FB_PAGE.'/video_stories' => Http::response(['success' => true, 'post_id' => '8822']),
    ]);

    $poll = fbResume($driver, $post, $finish);
    $done = fbResume($driver, $post, $poll);

    expect($done)->toBeInstanceOf(Published::class)
        ->and($done->result->platformPostId)->toBe(FB_PAGE.'_8822')
        ->and($done->result->url)->toBeNull();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['upload_phase'] === 'finish'
        && $request['is_ai_generated'] === true
        && ! isset($request['published'])
        && ! isset($request['scheduled_publish_time'])
        && ! isset($request['video_state']));
});

it('publishes a photo story in a commit phase and normalises its id', function () {
    fbFake([
        'POST *v25.0/'.FB_PAGE.'/photos' => Http::response(['id' => '3344']),
        'POST *v25.0/'.FB_PAGE.'/photo_stories' => Http::response(['success' => true, 'post_id' => '6677']),
    ]);
    $driver = fbDriver();
    $post = fbPost(media: [Media::image('https://cdn.example.com/story.jpg', 'Alt')], options: new FacebookOptions(isStory: true, extra: ['photo' => ['no_story' => true], 'source_tag' => 'x']));

    $outcome = $driver->publish($post);

    expect($outcome->state)->toHaveKeys(['phase', 'photo_id', 'since'])
        ->and($outcome->state)->toMatchArray(['phase' => 'photo_story', 'photo_id' => '3344'])
        ->and($outcome->state['since'])->toBeLessThanOrEqual(time() - 300)
        ->and($outcome->commits)->toBeTrue();
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/photos') && $request['alt_text_custom'] === 'Alt' && $request['no_story'] === true);

    $done = fbResume($driver, $post, $outcome);

    expect($done->result->platformPostId)->toBe(FB_PAGE.'_6677')
        ->and($done->result->url)->toBeNull()
        ->and($done->result->payload['photo_id'])->toBe('3344');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/photo_stories') && $request['photo_id'] === '3344' && $request['source_tag'] === 'x' && ! isset($request['photo']));
});

it('never posts a photo story twice: a recovering run is OutcomeUnknown', function () {
    fbFake([]);
    $driver = fbDriver();

    expect(fn () => $driver->resume(fbPost(options: new FacebookOptions(isStory: true)), ['phase' => 'photo_story', 'photo_id' => '3344', Pending::RECOVERING => true]))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::OutcomeUnknown)->and($e->possiblyPublished())->toBeTrue());

    Http::assertNothingSent();
});

it('looks a recovering photo story up on the Page stories by its photo id', function () {
    fbFake([
        'GET *v25.0/'.FB_PAGE.'/stories*' => Http::response(['data' => [
            ['post_id' => '1111', 'status' => 'PUBLISHED', 'creation_time' => (string) time(), 'media_type' => 'photo', 'media_id' => '9999'],
            ['post_id' => '6677', 'status' => 'PUBLISHED', 'creation_time' => (string) time(), 'media_type' => 'photo', 'media_id' => '3344'],
        ]]),
    ]);
    $driver = fbDriver();
    $since = time() - 300;

    $done = $driver->resume(fbPost(options: new FacebookOptions(isStory: true)), ['phase' => 'photo_story', 'photo_id' => '3344', 'since' => $since, Pending::RECOVERING => true]);

    expect($done)->toBeInstanceOf(Published::class)
        ->and($done->result->platformPostId)->toBe(FB_PAGE.'_6677')
        ->and($done->result->payload['recovered'])->toBeTrue()
        ->and($done->result->payload['photo_id'])->toBe('3344');
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/stories') && (int) fbQuery($request)['since'] === $since);
});

it('publishes a recovering photo story when no story appeared since the commit', function () {
    fbFake([
        'GET *v25.0/'.FB_PAGE.'/stories*' => Http::response(['data' => [
            ['post_id' => '1111', 'status' => 'PUBLISHED', 'creation_time' => (string) (time() - 7200), 'media_type' => 'photo', 'media_id' => '9999'],
        ]]),
        'POST *v25.0/'.FB_PAGE.'/photo_stories' => Http::response(['success' => true, 'post_id' => '6677']),
    ]);
    $driver = fbDriver();

    $done = $driver->resume(fbPost(options: new FacebookOptions(isStory: true)), ['phase' => 'photo_story', 'photo_id' => '3344', 'since' => time() - 300, Pending::RECOVERING => true]);

    expect($done->result->platformPostId)->toBe(FB_PAGE.'_6677');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/photo_stories') && $request['photo_id'] === '3344');
});

it('never posts a recovering photo story it cannot rule out', function (mixed $listing, FailureReason $reason) {
    fbFake(['GET *v25.0/'.FB_PAGE.'/stories*' => $listing]);
    $driver = fbDriver();

    // Another story in the window may be this one (media_id is not confirmed
    // live to equal the uploaded photo id); a refused listing proves nothing.
    expect(fn () => $driver->resume(fbPost(options: new FacebookOptions(isStory: true)), ['phase' => 'photo_story', 'photo_id' => '3344', 'since' => time() - 300, Pending::RECOVERING => true]))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe($reason)->and($e->possiblyPublished())->toBeTrue());

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
})->with([
    'another story since' => [fn () => Http::response(['data' => [['post_id' => '1111', 'status' => 'PUBLISHED', 'creation_time' => (string) time(), 'media_type' => 'photo', 'media_id' => '9999']]]), FailureReason::OutcomeUnknown],
    'refused' => [fn () => Http::response(fbFixture('permission_10'), 403), FailureReason::InsufficientPermissions],
]);

it('reads the stories again later when Facebook is down, then gives up as unknown', function () {
    config(['social.api.facebook.verify_retries' => 1]);
    fbFake(['GET *v25.0/'.FB_PAGE.'/stories*' => Http::response(fbFixture('service_unavailable'), 500)]);
    $driver = fbDriver();
    $post = fbPost(options: new FacebookOptions(isStory: true));

    $retry = $driver->resume($post, ['phase' => 'photo_story', 'photo_id' => '3344', 'since' => time() - 300, Pending::RECOVERING => true]);

    expect($retry)->toBeInstanceOf(Pending::class)
        ->and($retry->commits)->toBeTrue()
        ->and($retry->state['phase'])->toBe('photo_story_verify')
        ->and($retry->state['verify_failures'])->toBe(1);

    expect(fn () => $driver->resume($post, $retry->state))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::OutcomeUnknown)->and($e->possiblyPublished())->toBeTrue());
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('checks the photo story confirmation', function (array $body, FailureReason $reason, bool $possibly) {
    fbFake(['POST *v25.0/'.FB_PAGE.'/photo_stories' => Http::response($body)]);
    $driver = fbDriver();

    expect(fn () => $driver->resume(fbPost(options: new FacebookOptions(isStory: true)), ['phase' => 'photo_story', 'photo_id' => '3344']))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe($reason)->and($e->possiblyPublished())->toBe($possibly));
})->with([
    'success false' => [['success' => false], FailureReason::Unknown, false],
    'no success' => [['post_id' => '6677'], FailureReason::OutcomeUnknown, true],
    'no post id' => [['success' => true], FailureReason::OutcomeUnknown, true],
]);

it('accepts "true" as a success flag and maps a refused photo story', function () {
    $driver = fbDriver();
    $post = fbPost(options: new FacebookOptions(isStory: true));

    fbFake(['POST *v25.0/'.FB_PAGE.'/photo_stories' => Http::response(['success' => 'true', 'post_id' => '42_6677'])]);
    expect($driver->resume($post, ['phase' => 'photo_story', 'photo_id' => '1'])->result->platformPostId)->toBe('42_6677');

    fbFake(['POST *v25.0/'.FB_PAGE.'/photo_stories' => Http::response(fbFixture('policy_block'), 400)]);
    expect(fn () => $driver->resume($post, ['phase' => 'photo_story', 'photo_id' => '1']))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::AccountRestricted)->and($e->context['retry_later'])->toBeTrue());
});

it('refuses to schedule a story at publish time', function () {
    expect(fn () => fbDriver()->publish(fbPost(media: [Media::image('https://cdn.example.com/s.jpg')], options: new FacebookOptions(isStory: true, scheduledAt: CarbonImmutable::now()->addDay()))))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::Validation));
});

it('fails clearly on an unknown phase or a state without its id', function () {
    $driver = fbDriver();

    expect(fn () => $driver->resume(fbPost(), ['phase' => 'mystery']))
        ->toThrow(fn (PermanentException $e) => expect($e->getMessage())->toBe('Unknown Facebook publish phase.')->and($e->context['phase'])->toBe('mystery'))
        ->and(fn () => $driver->resume(fbPost(), ['phase' => 'reel_finish']))
        ->toThrow(fn (PermanentException $e) => expect($e->context['missing_field'])->toBe('video_id'));
});
