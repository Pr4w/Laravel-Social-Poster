<?php

namespace SocialPoster\Tests\Feature\Platforms;

use const SocialPoster\Tests\Fixtures\Facebook\FB_PAGE;
use const SocialPoster\Tests\Fixtures\Facebook\FB_TOKEN;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\Facebook\FacebookOptions;
use SocialPoster\Tests\Fixtures\Facebook\FbTestAuthorizer;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\Published;

use function SocialPoster\Tests\Fixtures\Facebook\fbConnectionFailure;
use function SocialPoster\Tests\Fixtures\Facebook\fbDriver;
use function SocialPoster\Tests\Fixtures\Facebook\fbFake;
use function SocialPoster\Tests\Fixtures\Facebook\fbFixture;
use function SocialPoster\Tests\Fixtures\Facebook\fbPost;
use function SocialPoster\Tests\Fixtures\Facebook\fbQuery;
use function SocialPoster\Tests\Fixtures\Facebook\fbResume;

require_once __DIR__.'/../../Fixtures/facebook/helpers.php';

const FB_FEED_POST_ID = '1029384756_555';

beforeEach(fn () => config(['social.api.facebook.graph_version' => 'v25.0']));

/** Routes for a /feed create that succeeds, plus its permalink. */
function fbFeedRoutes(array $extra = []): array
{
    return $extra + [
        'POST *v25.0/'.FB_PAGE.'/feed' => Http::response(['id' => FB_FEED_POST_ID]),
        'GET *v25.0/'.FB_FEED_POST_ID.'?fields=permalink_url*' => Http::response(['permalink_url' => 'https://www.facebook.com/'.FB_PAGE.'/posts/555', 'id' => FB_FEED_POST_ID]),
    ];
}

it('commits a text post and creates it on resume with the link card, permalink and extra', function () {
    fbFake(fbFeedRoutes());
    $driver = fbDriver();
    $post = fbPost(caption: '  Read this: https://example.com/article). ', options: new FacebookOptions(extra: ['message' => 'ignored', 'place' => '110843418940484']));

    $outcome = $driver->publish($post);

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->commits)->toBeTrue()
        ->and($outcome->recheckAfter)->toBe(0)
        ->and($outcome->state['phase'])->toBe('feed_create')
        ->and($outcome->state['params'])->toBe(['message' => 'Read this: https://example.com/article).', 'link' => 'https://example.com/article'])
        ->and($outcome->state['since'])->toBeLessThanOrEqual(time() - 300);

    Http::assertNothingSent();

    $result = fbResume($driver, $post, $outcome);

    expect($result)->toBeInstanceOf(Published::class)
        ->and($result->result->platformPostId)->toBe(FB_FEED_POST_ID)
        ->and($result->result->url)->toBe('https://www.facebook.com/'.FB_PAGE.'/posts/555')
        ->and($result->result->state)->toBe(PostResult::STATE_PUBLISHED)
        ->and($result->result->idKind)->toBe('post_id');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), FB_PAGE.'/feed')
        && $request->hasHeader('Authorization', 'Bearer '.FB_TOKEN)
        && $request['message'] === 'Read this: https://example.com/article).'
        && $request['link'] === 'https://example.com/article'
        && $request['place'] === '110843418940484');
});

it('picks the link card from the options, extra or a bare domain', function (?FacebookOptions $options, string $caption, ?string $expected) {
    $driver = fbDriver();
    $outcome = $driver->publish(fbPost(caption: $caption, options: $options));

    expect($outcome->state['params']['link'] ?? null)->toBe($expected);
})->with([
    'explicit link' => [new FacebookOptions(link: 'example.org/landing'), 'See https://example.com', 'https://example.org/landing'],
    'link card off' => [new FacebookOptions(link: false), 'See https://example.com', null],
    'extra link wins over detection' => [new FacebookOptions(extra: ['link' => 'https://chosen.example']), 'See https://example.com', null],
    'bare domain' => [null, 'Visit example.com today', 'https://example.com'],
    'no link' => [null, 'Just words', null],
]);

it('keeps the caller extra link and drops it when the link card is off', function () {
    fbFake(fbFeedRoutes());
    $driver = fbDriver();

    $post = fbPost(caption: 'See https://example.com', options: new FacebookOptions(extra: ['link' => 'https://chosen.example']));
    fbResume($driver, $post, $driver->publish($post));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/feed') && $request['link'] === 'https://chosen.example');

    $off = fbPost(caption: 'See https://example.com', options: new FacebookOptions(link: false, extra: ['link' => 'https://chosen.example']));
    fbResume($driver, $off, $driver->publish($off));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/feed') && ! isset($request['link']) && $request['message'] === 'See https://example.com');
});

it('retries the post once without the link when Facebook cannot fetch it (1609005)', function () {
    fbFake(fbFeedRoutes([
        'POST *v25.0/'.FB_PAGE.'/feed' => Http::sequence()
            ->push(fbFixture('link_unavailable'), 400)
            ->push(['id' => FB_FEED_POST_ID]),
    ]));
    $driver = fbDriver();
    $post = fbPost(caption: 'Read https://broken.example/page');

    $result = fbResume($driver, $post, $driver->publish($post));

    expect($result->result->platformPostId)->toBe(FB_FEED_POST_ID)
        ->and($result->result->payload['link_dropped'])->toBeTrue();

    $feeds = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/feed'));
    expect($feeds)->toHaveCount(2)
        ->and($feeds[0][0]['link'])->toBe('https://broken.example/page')
        ->and(isset($feeds[1][0]['link']))->toBeFalse();
});

it('fails as MediaRejected when the post without the link still fails with 1609005', function () {
    fbFake(['POST *v25.0/'.FB_PAGE.'/feed' => Http::response(fbFixture('link_unavailable'), 400)]);
    $driver = fbDriver();
    $post = fbPost(caption: 'Read https://broken.example/page');

    expect(fn () => fbResume($driver, $post, $driver->publish($post)))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::MediaRejected));

    Http::assertSentCount(2);
});

it('treats a /feed answer without an id as a possibly published post', function () {
    fbFake(['POST *v25.0/'.FB_PAGE.'/feed' => Http::response(['success' => true])]);
    $driver = fbDriver();
    $post = fbPost();

    expect(fn () => fbResume($driver, $post, $driver->publish($post)))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::OutcomeUnknown)
            ->and($e->possiblyPublished())->toBeTrue());
});

it('maps a refused /feed create through the classifier', function () {
    fbFake(['POST *v25.0/'.FB_PAGE.'/feed' => Http::response(fbFixture('duplicate_post'), 400)]);
    $driver = fbDriver();
    $post = fbPost();

    expect(fn () => fbResume($driver, $post, $driver->publish($post)))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::DuplicateContent));
});

it('looks the post up instead of posting again when recovering', function () {
    fbFake([
        'GET *v25.0/'.FB_PAGE.'/published_posts*' => Http::response(['data' => [
            ['id' => FB_PAGE.'_111', 'message' => 'Another post', 'created_time' => gmdate('Y-m-d\TH:i:sO')],
            ['id' => FB_PAGE.'_222', 'message' => "Hello Facebook\r\n", 'created_time' => gmdate('Y-m-d\TH:i:sO')],
        ]]),
        'GET *v25.0/'.FB_PAGE.'_222?fields=permalink_url*' => Http::response(['permalink_url' => '/'.FB_PAGE.'/posts/222']),
    ]);
    $driver = fbDriver();
    $post = fbPost();

    $result = fbResume($driver, $post, $driver->publish($post), [Pending::RECOVERING => true]);

    expect($result)->toBeInstanceOf(Published::class)
        ->and($result->result->platformPostId)->toBe(FB_PAGE.'_222')
        ->and($result->result->url)->toBe('https://www.facebook.com/'.FB_PAGE.'/posts/222')
        ->and($result->result->payload['recovered'])->toBeTrue();

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/published_posts')
        && fbQuery($request)['limit'] === '25'
        && (int) fbQuery($request)['since'] <= time() - 300
        && str_contains((string) fbQuery($request)['fields'], 'attachments{target{id},subattachments{target{id}}}'));
});

it('posts when the lookup finds nothing, ignoring posts older than the commit', function () {
    fbFake(fbFeedRoutes([
        'GET *v25.0/'.FB_PAGE.'/published_posts*' => Http::response(['data' => [
            ['id' => FB_PAGE.'_old', 'message' => 'Hello Facebook', 'created_time' => gmdate('Y-m-d\TH:i:sO', time() - 7200)],
        ]]),
    ]));
    $driver = fbDriver();
    $post = fbPost();

    $result = fbResume($driver, $post, $driver->publish($post), [Pending::RECOVERING => true]);

    expect($result->result->platformPostId)->toBe(FB_FEED_POST_ID);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/feed'));
});

it('goes to a verify phase a minute later when the create times out after sending', function () {
    fbFake(['POST *v25.0/'.FB_PAGE.'/feed' => fbConnectionFailure()]);
    $driver = fbDriver();
    $post = fbPost();

    $outcome = fbResume($driver, $post, $driver->publish($post), [Pending::ATTEMPT => 1]);

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->commits)->toBeTrue()
        ->and($outcome->recheckAfter)->toBe(60)
        ->and($outcome->state['phase'])->toBe('feed_verify')
        ->and($outcome->state['ambiguous_rounds'])->toBe(1)
        ->and($outcome->state['params']['message'])->toBe('Hello Facebook')
        ->and(array_key_exists(Pending::ATTEMPT, $outcome->state))->toBeFalse();
});

it('verifies first in the feed_verify phase, then posts with the stored params', function () {
    fbFake(fbFeedRoutes([
        'GET *v25.0/'.FB_PAGE.'/published_posts*' => Http::response(['data' => []]),
    ]));
    $driver = fbDriver();
    $post = fbPost();

    $result = $driver->resume($post, [
        'phase' => 'feed_verify',
        'params' => ['message' => 'Hello Facebook', 'link' => 'https://example.com'],
        'since' => time() - 400,
        'ambiguous_rounds' => 1,
    ]);

    expect($result->result->platformPostId)->toBe(FB_FEED_POST_ID);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/feed') && $request['link'] === 'https://example.com');
});

it('rethrows a connection failure that never reached Facebook', function () {
    fbFake(['POST *v25.0/'.FB_PAGE.'/feed' => fbConnectionFailure('cURL error 6: Could not resolve host: graph.facebook.com')]);
    $driver = fbDriver();
    $post = fbPost();

    expect(fn () => fbResume($driver, $post, $driver->publish($post)))->toThrow(ConnectionException::class);
});

it('gives up with OutcomeUnknown after too many unanswered creates', function () {
    config(['social.api.facebook.verify_retries' => 2]);
    fbFake([
        'GET *v25.0/'.FB_PAGE.'/published_posts*' => Http::response(['data' => []]),
        'POST *v25.0/'.FB_PAGE.'/feed' => fbConnectionFailure(),
    ]);
    $driver = fbDriver();
    $post = fbPost();

    expect(fn () => $driver->resume($post, ['phase' => 'feed_verify', 'params' => ['message' => 'Hi'], 'since' => time(), 'ambiguous_rounds' => 2]))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::OutcomeUnknown)
            ->and($e->possiblyPublished())->toBeTrue()
            ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class));
});

it('retries the lookup later when Facebook cannot be read, then gives up as OutcomeUnknown', function () {
    config(['social.api.facebook.verify_retries' => 1]);
    fbFake(['GET *v25.0/'.FB_PAGE.'/published_posts*' => Http::response(fbFixture('service_unavailable'), 500)]);
    $driver = fbDriver();
    $post = fbPost();
    $state = ['phase' => 'feed_create', 'params' => ['message' => 'Hi'], 'since' => time() - 300, Pending::RECOVERING => true];

    $retry = $driver->resume($post, $state);

    expect($retry)->toBeInstanceOf(Pending::class)
        ->and($retry->commits)->toBeTrue()
        ->and($retry->state['phase'])->toBe('feed_verify')
        ->and($retry->state['verify_failures'])->toBe(1)
        ->and(array_key_exists(Pending::RECOVERING, $retry->state))->toBeFalse();

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');

    expect(fn () => $driver->resume($post, $retry->state))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::OutcomeUnknown)
            ->and($e->getPrevious())->toBeInstanceOf(TemporaryException::class));
});

it('retries the lookup later when reading the Page times out', function () {
    fbFake(['GET *v25.0/'.FB_PAGE.'/published_posts*' => fbConnectionFailure()]);
    $driver = fbDriver();
    $post = fbPost();

    $retry = $driver->resume($post, ['phase' => 'feed_verify', 'params' => ['message' => 'Hi'], 'since' => time()]);

    expect($retry)->toBeInstanceOf(Pending::class)->and($retry->state['verify_failures'])->toBe(1);
});

it('flags a refused lookup as possibly published', function () {
    fbFake(['GET *v25.0/'.FB_PAGE.'/published_posts*' => Http::response(fbFixture('permission_10'), 403)]);
    $driver = fbDriver();
    $post = fbPost();

    expect(fn () => $driver->resume($post, ['phase' => 'feed_verify', 'params' => ['message' => 'Hi'], 'since' => time()]))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::InsufficientPermissions)
            ->and($e->possiblyPublished())->toBeTrue());
});

it('waits out a throttled lookup instead of counting it as a failed read', function () {
    $usage = (string) json_encode([FB_PAGE => [['type' => 'pages', 'call_count' => 100, 'estimated_time_to_regain_access' => 30]]]);
    fbFake(['GET *v25.0/'.FB_PAGE.'/published_posts*' => Http::response(fbFixture('throttle_page'), 400, ['X-Business-Use-Case-Usage' => $usage])]);
    $driver = fbDriver();
    $post = fbPost(caption: 'Hi');
    $state = ['phase' => 'feed_verify', 'params' => ['message' => 'Hi'], 'since' => time() - 300];

    $retry = $driver->resume($post, $state);

    expect($retry)->toBeInstanceOf(Pending::class)
        ->and($retry->commits)->toBeTrue()
        ->and($retry->recheckAfter)->toBe(1800)
        ->and($retry->state['phase'])->toBe('feed_verify')
        ->and($retry->state['verify_failures'] ?? 0)->toBe(0)
        ->and($retry->deadline)->toBeGreaterThan(time() + 1800);

    // Far more throttled reads than verify_retries: still waiting, not unknown.
    for ($i = 0; $i < 7; $i++) {
        $retry = $driver->resume($post, $retry->state);
    }

    expect($retry)->toBeInstanceOf(Pending::class);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('gives up a throttled lookup as OutcomeUnknown once verify_max_wait has passed', function () {
    fbFake(['GET *v25.0/'.FB_PAGE.'/published_posts*' => Http::response(fbFixture('throttle_page'), 400)]);
    $driver = fbDriver();

    expect(fn () => $driver->resume(fbPost(caption: 'Hi'), ['phase' => 'feed_verify', 'params' => ['message' => 'Hi'], 'since' => time() - 4000, 'verify_started' => time() - 3500]))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::OutcomeUnknown)->and($e->possiblyPublished())->toBeTrue());
});

it('takes a duplicate refusal of the re-create as proof the first attempt went through', function () {
    fbFake(fbFeedRoutes([
        'GET *v25.0/'.FB_PAGE.'/published_posts*' => Http::sequence()
            ->push(['data' => []])
            ->push(['data' => [['id' => FB_FEED_POST_ID, 'message' => 'Hi', 'created_time' => gmdate('Y-m-d\TH:i:sO')]]]),
        'POST *v25.0/'.FB_PAGE.'/feed' => Http::response(fbFixture('duplicate_post'), 400),
    ]));
    $driver = fbDriver();

    $result = $driver->resume(fbPost(caption: 'Hi'), ['phase' => 'feed_verify', 'params' => ['message' => 'Hi'], 'since' => time() - 300, 'ambiguous_rounds' => 1]);

    expect($result)->toBeInstanceOf(Published::class)
        ->and($result->result->platformPostId)->toBe(FB_FEED_POST_ID)
        ->and($result->result->payload['recovered'])->toBeTrue();
});

it('reports a duplicate refusal of the re-create as possibly published when the post still cannot be found', function (array $state) {
    fbFake([
        'GET *v25.0/'.FB_PAGE.'/published_posts*' => Http::response(['data' => []]),
        'POST *v25.0/'.FB_PAGE.'/feed' => Http::response(fbFixture('duplicate_post'), 400),
    ]);

    expect(fn () => fbDriver()->resume(fbPost(caption: 'Hi'), $state))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::DuplicateContent)->and($e->possiblyPublished())->toBeTrue());
})->with([
    'feed_verify' => [['phase' => 'feed_verify', 'params' => ['message' => 'Hi'], 'since' => time() - 300]],
    'recovering' => [['phase' => 'feed_create', 'params' => ['message' => 'Hi'], 'since' => time() - 300, Pending::RECOVERING => true]],
]);

it('refuses a scheduled /feed create whose time is now less than 10 minutes away, before sending it', function () {
    fbFake([]);

    expect(fn () => fbDriver()->resume(fbPost(), ['phase' => 'feed_create', 'params' => ['message' => 'Hi', 'published' => false, 'scheduled_publish_time' => time() + 300], 'since' => time()]))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::Validation)->and($e->possiblyPublished())->toBeFalse());

    Http::assertNothingSent();

    // In feed_verify the earlier, unanswered create may still exist.
    fbFake(['GET *v25.0/'.FB_PAGE.'/scheduled_posts*' => Http::response(['data' => []])]);

    expect(fn () => fbDriver()->resume(fbPost(), ['phase' => 'feed_verify', 'params' => ['message' => 'Hi', 'published' => false, 'scheduled_publish_time' => time() + 300], 'since' => time() - 300]))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::Validation)->and($e->possiblyPublished())->toBeTrue());

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('falls back to a facebook.com link when the permalink cannot be read', function () {
    fbFake(fbFeedRoutes([
        'GET *v25.0/'.FB_FEED_POST_ID.'?fields=permalink_url*' => Http::response(fbFixture('permission_10'), 403),
    ]));
    $driver = fbDriver();
    $post = fbPost();

    $result = fbResume($driver, $post, $driver->publish($post));

    expect($result->result->url)->toBe('https://www.facebook.com/'.FB_FEED_POST_ID);
});

it('uploads photos unpublished with alt text and per-photo extra, then attaches them in order', function () {
    fbFake(fbFeedRoutes([
        'POST *v25.0/'.FB_PAGE.'/photos' => fn (Request $request) => Http::response(['id' => 'photo-'.basename((string) $request['url'], '.jpg')]),
    ]));
    $driver = fbDriver();
    $post = fbPost(
        media: [
            Media::image('https://cdn.example.com/a.jpg', 'A red bike'),
            Media::image('https://cdn.example.com/b.jpg'),
            Media::image('https://cdn.example.com/c.jpg'),
        ],
        caption: 'Three photos https://example.com',
        options: new FacebookOptions(extra: ['photo' => ['no_story' => true, 'published' => true], 'place' => 'P1']),
    );

    $outcome = $driver->publish($post);

    expect($outcome->state['media_ids'])->toBe(['photo-a', 'photo-b', 'photo-c'])
        ->and($outcome->state['params'])->toBe([
            'message' => 'Three photos https://example.com',
            'attached_media' => [['media_fbid' => 'photo-a'], ['media_fbid' => 'photo-b'], ['media_fbid' => 'photo-c']],
        ]);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/photos')
        && $request['url'] === 'https://cdn.example.com/a.jpg'
        && $request['published'] === false
        && $request['alt_text_custom'] === 'A red bike'
        && $request['no_story'] === true
        && ! isset($request['temporary'])
        && ! isset($request['place'])
        && $request->hasHeader('Authorization', 'Bearer '.FB_TOKEN));

    $result = fbResume($driver, $post, $outcome);

    expect($result->result->platformPostId)->toBe(FB_FEED_POST_ID)
        ->and($result->result->payload['media_ids'])->toBe(['photo-a', 'photo-b', 'photo-c']);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/feed')
        && $request['attached_media'] === [['media_fbid' => 'photo-a'], ['media_fbid' => 'photo-b'], ['media_fbid' => 'photo-c']]
        && $request['place'] === 'P1'
        && ! isset($request['photo'])
        && ! isset($request['link']));
});

it('posts a single photo through attached_media', function () {
    fbFake(fbFeedRoutes(['POST *v25.0/'.FB_PAGE.'/photos' => Http::response(['id' => '9001', 'post_id' => FB_PAGE.'_9001'])]));
    $driver = fbDriver();
    $post = fbPost(media: [Media::image('https://cdn.example.com/one.png')], caption: '');

    $outcome = $driver->publish($post);

    expect($outcome->state['params'])->toBe(['message' => '', 'attached_media' => [['media_fbid' => '9001']]]);

    expect(fbResume($driver, $post, $outcome)->result->platformPostId)->toBe(FB_FEED_POST_ID);
});

it('fails the photo upload on a Graph error, a missing id or a dropped connection', function () {
    $driver = fbDriver();
    $two = fbPost(media: [Media::image('https://cdn.example.com/a.jpg'), Media::image('https://cdn.example.com/b.jpg')]);

    fbFake(['POST *v25.0/'.FB_PAGE.'/photos' => fn (Request $request) => str_contains((string) $request['url'], 'b.jpg')
        ? Http::response(fbFixture('invalid_image'), 400)
        : Http::response(['id' => '1'])]);
    expect(fn () => $driver->publish($two))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::MediaRejected));

    fbFake(['POST *v25.0/'.FB_PAGE.'/photos' => Http::response(['success' => true])]);
    expect(fn () => $driver->publish($two))
        ->toThrow(fn (TemporaryException $e) => expect($e->reason)->toBe(FailureReason::ServerError));

    fbFake(['POST *v25.0/'.FB_PAGE.'/photos' => fbConnectionFailure()]);
    expect(fn () => $driver->publish($two))
        ->toThrow(fn (TemporaryException $e) => expect($e->reason)->toBe(FailureReason::Network));

    fbFake(['POST *v25.0/'.FB_PAGE.'/photos' => Http::response(fbFixture('throttle_page'), 400)]);
    expect(fn () => $driver->publish(fbPost(media: [Media::image('https://cdn.example.com/a.jpg')])))
        ->toThrow(fn (TemporaryException $e) => expect($e->reason)->toBe(FailureReason::RateLimited));
});

it('uploads photos one by one when a request authorizer is configured', function () {
    config(['social.authorizers.facebook' => FbTestAuthorizer::class]);
    $calls = 0;
    fbFake(['POST *v25.0/'.FB_PAGE.'/photos' => function () use (&$calls) {
        $calls++;

        return Http::response(['id' => 'p'.$calls]);
    }]);
    $driver = fbDriver();

    $outcome = $driver->publish(fbPost(media: [Media::image('https://cdn.example.com/a.jpg'), Media::image('https://cdn.example.com/b.jpg')]));

    expect($outcome->state['media_ids'])->toBe(['p1', 'p2']);
    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Test-Authorizer', 'yes'));
});

it('schedules a text post and reports it as scheduled without a permalink', function () {
    $at = CarbonImmutable::now()->addDay();
    fbFake(['POST *v25.0/'.FB_PAGE.'/feed' => Http::response(['id' => FB_FEED_POST_ID])]);
    $driver = fbDriver();
    $post = fbPost(options: new FacebookOptions(scheduledAt: $at));

    $outcome = $driver->publish($post);

    expect($outcome->state['params'])->toBe(['message' => 'Hello Facebook', 'published' => false, 'scheduled_publish_time' => $at->getTimestamp()]);

    $result = fbResume($driver, $post, $outcome);

    expect($result->result->state)->toBe(PostResult::STATE_SCHEDULED)
        ->and($result->result->isScheduled())->toBeTrue()
        ->and($result->result->url)->toBeNull();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request['published'] === false && $request['scheduled_publish_time'] === $at->getTimestamp());
});

it('schedules a multi-photo post with temporary photos and unpublished_content_type', function () {
    $at = CarbonImmutable::now()->addDays(2);
    fbFake(['POST *v25.0/'.FB_PAGE.'/photos' => fn (Request $request) => Http::response(['id' => md5((string) $request['url'])])]);
    $driver = fbDriver();
    $post = fbPost(media: [Media::image('https://cdn.example.com/a.jpg'), Media::image('https://cdn.example.com/b.jpg')], options: new FacebookOptions(scheduledAt: $at));

    $outcome = $driver->publish($post);

    expect($outcome->state['params']['unpublished_content_type'])->toBe('SCHEDULED')
        ->and($outcome->state['params']['published'])->toBeFalse()
        ->and($outcome->state['params']['scheduled_publish_time'])->toBe($at->getTimestamp());

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/photos')
        && $request['temporary'] === true
        && $request['published'] === false
        && ! isset($request['scheduled_publish_time']));
});

it('honours the v1.0 extra scheduling recipe', function (mixed $published, mixed $time, ?int $expected) {
    $driver = fbDriver();
    $post = fbPost(options: new FacebookOptions(extra: ['published' => $published, 'scheduled_publish_time' => $time]));

    expect($driver->call('scheduledTime', $post))->toBe($expected);
})->with([
    'unix time' => [false, 1893456000, 1893456000],
    'string false' => ['false', '1893456000', 1893456000],
    'date string' => [0, '2030-01-01T00:00:00Z', 1893456000],
    'date object' => [false, new \DateTimeImmutable('@1893456000'), 1893456000],
    'published true' => [true, 1893456000, null],
    'unparsable' => [false, 'soon', null],
]);

it('looks a scheduled post up in /scheduled_posts when recovering', function () {
    $at = CarbonImmutable::now()->addDay();
    fbFake(['GET *v25.0/'.FB_PAGE.'/scheduled_posts*' => Http::response(['data' => [
        ['id' => FB_PAGE.'_777', 'message' => 'Hello Facebook', 'created_time' => gmdate('Y-m-d\TH:i:sO')],
    ]])]);
    $driver = fbDriver();
    $post = fbPost(options: new FacebookOptions(scheduledAt: $at));

    $result = fbResume($driver, $post, $driver->publish($post), [Pending::RECOVERING => true]);

    expect($result->result->platformPostId)->toBe(FB_PAGE.'_777')
        ->and($result->result->state)->toBe(PostResult::STATE_SCHEDULED);
});

it('matches a caption-less photo post by its photo ids when recovering', function () {
    fbFake([
        'POST *v25.0/'.FB_PAGE.'/photos' => fn (Request $request) => Http::response(['id' => str_contains((string) $request['url'], 'a.jpg') ? 'ph-a' : 'ph-b']),
        'GET *v25.0/'.FB_PAGE.'/published_posts*' => Http::response(['data' => [
            ['id' => FB_PAGE.'_1', 'created_time' => gmdate('Y-m-d\TH:i:sO'), 'attachments' => ['data' => [['target' => ['id' => 'other']]]]],
            ['id' => FB_PAGE.'_2', 'created_time' => gmdate('Y-m-d\TH:i:sO'), 'attachments' => ['data' => [[
                'target' => ['id' => FB_PAGE.'_2'],
                'subattachments' => ['data' => [['target' => ['id' => 'ph-a']], ['target' => ['id' => 'ph-b']]]],
            ]]]],
        ]]),
        'GET *?fields=permalink_url*' => Http::response(['permalink_url' => 'https://www.facebook.com/x']),
    ]);
    $driver = fbDriver();
    $post = fbPost(media: [Media::image('https://cdn.example.com/a.jpg'), Media::image('https://cdn.example.com/b.jpg')], caption: '');

    $result = fbResume($driver, $post, $driver->publish($post), [Pending::RECOVERING => true]);

    expect($result->result->platformPostId)->toBe(FB_PAGE.'_2');
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/feed'));
});

it('refuses to guess a caption-less post when the listing shows no attachments', function () {
    fbFake(['GET *v25.0/'.FB_PAGE.'/published_posts*' => Http::response(['data' => [
        ['id' => FB_PAGE.'_1', 'created_time' => gmdate('Y-m-d\TH:i:sO')],
    ]])]);
    $driver = fbDriver();
    $post = fbPost(caption: '');

    expect(fn () => $driver->resume($post, [
        'phase' => 'feed_create',
        'params' => ['message' => '', 'attached_media' => [['media_fbid' => 'ph-a']]],
        'media_ids' => ['ph-a'],
        'since' => time() - 300,
        Pending::RECOVERING => true,
    ]))->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->context['candidates'])->toBe(1));
});

it('creates a caption-less post when the lookup finds no posts and when photo ids do not match', function () {
    fbFake(fbFeedRoutes([
        'GET *v25.0/'.FB_PAGE.'/published_posts*' => Http::sequence()
            ->push(['data' => []])
            ->push(['data' => [['id' => FB_PAGE.'_9', 'created_time' => gmdate('Y-m-d\TH:i:sO'), 'attachments' => ['data' => [['target' => ['id' => 'someone-else']]]]]]]),
    ]));
    $driver = fbDriver();
    $post = fbPost(caption: '');
    $state = [
        'phase' => 'feed_create',
        'params' => ['message' => '', 'attached_media' => [['media_fbid' => 'ph-a']]],
        'media_ids' => ['ph-a'],
        'since' => time() - 300,
        Pending::RECOVERING => true,
    ];

    expect($driver->resume($post, $state)->result->platformPostId)->toBe(FB_FEED_POST_ID)
        ->and($driver->resume($post, $state)->result->platformPostId)->toBe(FB_FEED_POST_ID);
});

it('requires the message and the photos to match when both are known', function () {
    fbFake(fbFeedRoutes([
        'GET *v25.0/'.FB_PAGE.'/published_posts*' => Http::response(['data' => [
            ['id' => FB_PAGE.'_x', 'message' => 'Same words', 'created_time' => gmdate('Y-m-d\TH:i:sO'), 'attachments' => ['data' => [['target' => ['id' => 'not-ours']]]]],
            ['message' => 'Same words', 'created_time' => gmdate('Y-m-d\TH:i:sO')],
            'garbage',
        ]]),
    ]));
    $driver = fbDriver();
    $post = fbPost(caption: 'Same words');

    $result = $driver->resume($post, [
        'phase' => 'feed_create',
        'params' => ['message' => 'Same words', 'attached_media' => [['media_fbid' => 'ours']]],
        'media_ids' => ['ours'],
        'since' => time() - 300,
        Pending::RECOVERING => true,
    ]);

    expect($result->result->platformPostId)->toBe(FB_FEED_POST_ID);
});

it('fails clearly on a feed continuation without params', function () {
    $driver = fbDriver();

    expect(fn () => $driver->resume(fbPost(), ['phase' => 'feed_create']))
        ->toThrow(fn (PermanentException $e) => expect($e->context['missing_field'])->toBe('params'));
});
