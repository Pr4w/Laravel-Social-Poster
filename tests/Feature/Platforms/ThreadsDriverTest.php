<?php

namespace SocialPoster\Tests\Feature\Platforms\ThreadsDriverTest;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use SocialPoster\Contracts\AuthorizesRequests;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\Instagram\InstagramOptions;
use SocialPoster\Platforms\Threads\ThreadsDriver;
use SocialPoster\Platforms\Threads\ThreadsOptions;
use SocialPoster\Platforms\Threads\ThreadsPoll;
use SocialPoster\Platforms\Threads\ThreadsReplyControl;
use SocialPoster\Tests\ThreadsFixtures\ThreadsKit as K;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\Published;
use SocialPoster\ValueObjects\PublishOutcome;
use SocialPoster\ValueObjects\RawOptions;

require_once __DIR__.'/../../Fixtures/threads/ThreadsKit.php';

beforeEach(function () {
    Carbon::setTestNow('2026-11-18 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

const IMG_A = 'https://cdn.example.com/a.jpg';
const IMG_B = 'https://cdn.example.com/b.png';
const VID = 'https://cdn.example.com/clip.mp4';

/** Route a carousel item create by its URL to an id. */
function childRoute(array $idsByUrl): \Closure
{
    return static function (Request $request) use ($idsByUrl) {
        $url = $request['image_url'] ?? $request['video_url'] ?? null;

        return Http::response(['id' => $idsByUrl[$url] ?? 'UNKNOWN'], 200);
    };
}

// --- Text, image and video singles ------------------------------------------------

it('publishes a text post through create, await and publish', function () {
    K::fake([
        'POST 1784/threads' => [['id' => 'C1']],
        'GET C1' => [['status' => 'FINISHED', 'id' => 'C1']],
        'POST 1784/threads_publish' => [['id' => '17890001']],
        'GET 17890001' => [['permalink' => 'https://www.threads.net/@acme/post/DAbc123', 'id' => '17890001']],
    ]);

    $driver = K::driver();
    $post = K::post(caption: '  Hello Threads  ');

    $pending = $driver->publish($post);

    expect($pending)->toBeInstanceOf(Pending::class)
        ->and($pending->recheckAfter)->toBe(30)
        ->and($pending->commits)->toBeFalse()
        ->and($pending->state)->toMatchArray([
            'phase' => 'await',
            'kind' => 'text',
            'container_id' => 'C1',
            'created_at' => Carbon::now()->getTimestamp(),
            'text' => 'Hello Threads',
        ]);

    $create = K::sent('POST 1784/threads')[0];
    expect($create->data())->toBe([
        'media_type' => 'TEXT',
        'text' => 'Hello Threads',
        'access_token' => K::TOKEN,
    ])->and($create->hasHeader('Authorization', 'Bearer '.K::TOKEN))->toBeTrue();

    $commit = $driver->resume($post, $pending->state);

    expect($commit)->toBeInstanceOf(Pending::class)
        ->and($commit->commits)->toBeTrue()
        ->and($commit->recheckAfter)->toBe(0)
        ->and($commit->state['phase'])->toBe('publish');

    $published = $driver->resume($post, $commit->state);

    expect($published)->toBeInstanceOf(Published::class)
        ->and($published->result->platformPostId)->toBe('17890001')
        ->and($published->result->url)->toBe('https://www.threads.net/@acme/post/DAbc123')
        ->and($published->result->state)->toBe(PostResult::STATE_PUBLISHED)
        ->and($published->result->payload['container_id'])->toBe('C1')
        ->and($published->result->payload['response'])->toBe(['id' => '17890001']);

    $publish = K::sent('POST 1784/threads_publish')[0];
    expect($publish->data())->toBe(['creation_id' => 'C1', 'access_token' => K::TOKEN]);

    // GETs carry the token only in the header, never in the URL.
    $status = K::sent('GET C1')[0];
    expect(K::query($status))->toBe(['fields' => 'status,error_message'])
        ->and($status->url())->not->toContain('access_token');
});

it('creates an image container with caption and alt text', function () {
    K::fake(['POST 1784/threads' => [['id' => 'C2']]]);

    $pending = K::driver()->publish(K::post([Media::image(IMG_A, '  A red bicycle  ')], 'Caption'));

    expect($pending->state['kind'])->toBe('image')
        ->and($pending->recheckAfter)->toBe(30)
        ->and(K::sent('POST 1784/threads')[0]->data())->toBe([
            'media_type' => 'IMAGE',
            'image_url' => IMG_A,
            'alt_text' => 'A red bicycle',
            'text' => 'Caption',
            'access_token' => K::TOKEN,
        ]);
});

it('creates an image container without text when the caption is blank', function () {
    K::fake(['POST 1784/threads' => [['id' => 'C2']]]);

    K::driver()->publish(K::post([Media::image(IMG_A)], '   '));

    expect(K::sent('POST 1784/threads')[0]->data())->not->toHaveKey('text')
        ->and(K::sent('POST 1784/threads')[0]->data())->not->toHaveKey('alt_text');
});

it('polls a video at the video cadence and warns that the thumbnail is ignored', function () {
    K::fake([
        'POST 1784/threads' => [['id' => 'V1']],
        'GET V1' => [['status' => 'IN_PROGRESS'], ['status' => 'FINISHED']],
        'POST 1784/threads_publish' => [['id' => '1790']],
        'GET 1790' => [['permalink' => 'https://www.threads.net/@acme/post/V']],
    ]);

    $driver = K::driver();
    $post = K::post([Media::video(VID, 'https://cdn.example.com/cover.jpg', 'Clip alt')], 'Video');

    $pending = $driver->publish($post);
    expect($pending->state['kind'])->toBe('video')
        ->and(K::sent('POST 1784/threads')[0]->data())->toMatchArray(['media_type' => 'VIDEO', 'video_url' => VID, 'alt_text' => 'Clip alt']);

    $waiting = $driver->resume($post, $pending->state);
    expect($waiting)->toBeInstanceOf(Pending::class)
        ->and($waiting->commits)->toBeFalse()
        ->and($waiting->recheckAfter)->toBe(30)
        ->and($waiting->state['polls'])->toBe(1);

    $commit = $driver->resume($post, $waiting->state);
    $published = $driver->resume($post, $commit->state);

    expect($published->result->payload['thumbnail_warning'])->toContain('thumbnail was ignored')
        ->and($published->result->platformPostId)->toBe('1790');
});

it('polls text and image containers every 10 seconds after the first check', function () {
    K::fake([
        'POST 1784/threads' => [['id' => 'C1']],
        'GET C1' => [['status' => 'IN_PROGRESS']],
    ]);

    $driver = K::driver();
    $post = K::post([Media::image(IMG_A)]);
    $next = $driver->resume($post, $driver->publish($post)->state);

    expect($next->recheckAfter)->toBe(10)->and($next->state['phase'])->toBe('await');
});

it('honours the configured cadence', function () {
    config(['social.api.threads.initial_check_after' => 45, 'social.api.threads.poll_interval' => 7]);
    K::fake(['POST 1784/threads' => [['id' => 'C1']], 'GET C1' => [['status' => 'IN_PROGRESS']]]);

    $driver = K::driver();
    $post = K::post();
    $first = $driver->publish($post);

    expect($first->recheckAfter)->toBe(45)
        ->and($driver->resume($post, $first->state)->recheckAfter)->toBe(7);
});

it('rejects a document before any request', function () {
    K::fake([]);

    $e = K::catch(fn () => K::driver()->publish(K::post([Media::document('https://cdn.example.com/deck.pdf')])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected);
    Http::assertNothingSent();
});

it('rejects a document inside a carousel before any request', function () {
    K::fake([]);

    $e = K::catch(fn () => K::driver()->publish(K::post([Media::image(IMG_A), Media::document('https://cdn.example.com/deck.pdf')])));

    expect($e->reason)->toBe(FailureReason::MediaRejected);
    Http::assertNothingSent();
});

it('fails fast with Misconfigured when credentials are missing', function (array $credentials, array $missing) {
    K::fake([]);
    $driver = K::driver();

    foreach ([
        fn () => $driver->publish(K::post(credentials: $credentials)),
        fn () => $driver->resume(K::post(credentials: $credentials), ['phase' => 'await', 'container_id' => 'C1']),
    ] as $call) {
        $e = K::catch($call);

        expect($e)->toBeInstanceOf(PermanentException::class)
            ->and($e->reason)->toBe(FailureReason::Misconfigured)
            ->and($e->context['missing_credentials'])->toBe($missing);
    }

    Http::assertNothingSent();
})->with([
    'no account id' => [['access_token' => 'tok'], ['account_id']],
    'no token' => [['account_id' => '1784'], ['access_token']],
    'blank both' => [['account_id' => ' ', 'access_token' => ''], ['account_id', 'access_token']],
]);

it('requires an id on every create', function () {
    K::fake(['POST 1784/threads' => [['success' => true]]]);

    $e = K::catch(fn () => K::driver()->publish(K::post()));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::ServerError)
        ->and($e->context['missing_field'])->toBe('id');
});

// --- Options and extra ------------------------------------------------------------

it('sends typed post-level options on a text post', function () {
    K::fake(['POST 1784/threads' => [['id' => 'C1']]]);

    $options = new ThreadsOptions(
        replyControl: ThreadsReplyControl::AccountsYouFollow,
        topicTag: '#Travel Tips',
        linkAttachment: 'https://example.com/article',
        quotePostId: '1788',
        replyToId: '1777',
        locationId: '1234',
        allowlistedCountryCodes: ['US', ' ca '],
        madeWithAi: true,
        spoilerMedia: true,
        pollAttachment: new ThreadsPoll('Yes', 'No'),
        extra: ['enable_reply_approvals' => true, 'auto_publish_text' => true, 'media_type' => 'IMAGE'],
    );

    K::driver()->publish(K::post(caption: 'Vote', options: $options));

    expect(K::sent('POST 1784/threads')[0]->data())->toBe([
        'enable_reply_approvals' => true,
        'media_type' => 'TEXT',
        'reply_control' => 'accounts_you_follow',
        'topic_tag' => 'Travel Tips',
        'link_attachment' => 'https://example.com/article',
        'poll_attachment' => ['option_a' => 'Yes', 'option_b' => 'No'],
        'quote_post_id' => '1788',
        'reply_to_id' => '1777',
        'location_id' => '1234',
        'allowlisted_country_codes' => 'US,CA',
        'is_made_with_ai' => true,
        'text' => 'Vote',
        'access_token' => K::TOKEN,
    ]);
});

it('keeps text-only options off media posts and marks spoilers', function () {
    K::fake(['POST 1784/threads' => [['id' => 'C1']]]);

    $options = new ThreadsOptions(linkAttachment: 'https://example.com', spoilerMedia: true, pollAttachment: new ThreadsPoll('A', 'B'));

    K::driver()->publish(K::post([Media::image(IMG_A)], 'x', $options));

    $data = K::sent('POST 1784/threads')[0]->data();
    expect($data['is_spoiler_media'])->toBeTrue()
        ->and($data)->not->toHaveKey('link_attachment')
        ->and($data)->not->toHaveKey('poll_attachment');
});

it('merges RawOptions extra but strips auto_publish_text', function () {
    K::fake(['POST 1784/threads' => [['id' => 'C1']]]);

    K::driver()->publish(K::post(options: new RawOptions(Platform::Threads, [
        'auto_publish_text' => true,
        'text_entities' => [['entity_type' => 'SPOILER', 'offset' => 0, 'length' => 5]],
        'text' => 'overridden?',
        'access_token' => 'leak',
    ])));

    $data = K::sent('POST 1784/threads')[0]->data();
    expect($data)->not->toHaveKey('auto_publish_text')
        ->and($data['text_entities'])->toHaveCount(1)
        ->and($data['text'])->toBe('Hello Threads')
        ->and($data['access_token'])->toBe(K::TOKEN);
});

it('points a refused location-tagged post at threads_location_tagging', function (array $media, array $routes) {
    K::fake($routes);
    $driver = K::driver();
    $post = K::post($media, 'Here', new ThreadsOptions(locationId: '1234'));

    $e = K::catch(function () use ($driver, $post, $media) {
        $outcome = $driver->publish($post);

        // A carousel is refused when its parent is created.
        if (count($media) > 1) {
            $driver->resume($post, $outcome->state);
        }
    });

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::InsufficientPermissions)
        ->and($e->getMessage())->toContain('threads_location_tagging')
        ->and($e->context['required_scope'])->toBe('threads_location_tagging')
        ->and($e->context['code'])->toBe(10);
})->with([
    'text post' => [[], ['POST 1784/threads' => [K::error('permission-code-10', 403)]]],
    'carousel parent' => [[Media::image(IMG_A), Media::image(IMG_B)], [
        'POST 1784/threads' => [static fn (Request $r) => $r['media_type'] === 'CAROUSEL'
            ? Http::response(K::errorBody('permission-code-10'), 403)
            : Http::response(['id' => $r['image_url'] === IMG_A ? 'K1' : 'K2'])],
        'GET K1' => [['status' => 'FINISHED']],
        'GET K2' => [['status' => 'FINISHED']],
    ]],
]);

it('does not blame threads_location_tagging for a post without a location tag', function () {
    K::fake(['POST 1784/threads' => [K::error('permission-code-10', 403)]]);

    $e = K::catch(fn () => K::driver()->publish(K::post()));

    expect($e->reason)->toBe(FailureReason::InsufficientPermissions)
        ->and($e->context)->not->toHaveKey('required_scope')
        ->and($e->getMessage())->not->toContain('threads_location_tagging');
});

it('never applies options meant for another platform', function () {
    K::fake(['POST 1784/threads' => [['id' => 'C1']]]);

    K::driver()->publish(K::post(options: new InstagramOptions(extra: ['share_to_feed' => true])));
    K::driver()->publish(K::post(options: new RawOptions(Platform::X, ['reply_settings' => 'following'])));

    foreach (K::sent('POST 1784/threads') as $request) {
        expect($request->data())->toBe(['media_type' => 'TEXT', 'text' => 'Hello Threads', 'access_token' => K::TOKEN]);
    }
});

it('sends no access_token body field when the token is resolved by an authorizer', function () {
    K::fake(['POST 1784/threads' => [['id' => 'C1']]]);

    config(['social.authorizers.threads' => new class implements AuthorizesRequests
    {
        public function request(Platform $platform, Credentials $credentials): PendingRequest
        {
            return Http::withHeaders(['X-Signed' => 'yes']);
        }
    }]);

    // A custom driver whose token key is unset: only account_id is required.
    $driver = new class extends ThreadsDriver
    {
        protected function requiredCredentials(): array
        {
            return ['account_id' => 'id'];
        }
    };

    $driver->publish(K::post(credentials: ['account_id' => '1784']));

    $request = K::sent('POST 1784/threads')[0];
    expect($request->data())->not->toHaveKey('access_token')
        ->and($request->hasHeader('X-Signed', 'yes'))->toBeTrue();
});

it('creates carousel items through the authorizer, one by one, when one is configured', function () {
    K::fake(['POST 1784/threads' => [['id' => 'K1'], ['id' => 'K2']]]);

    config(['social.authorizers.threads' => new class implements AuthorizesRequests
    {
        public function request(Platform $platform, Credentials $credentials): PendingRequest
        {
            return Http::withHeaders(['X-Signed' => 'yes']);
        }
    }]);

    $pending = K::driver()->publish(K::post([Media::image('https://cdn.example.com/a.jpg'), Media::image('https://cdn.example.com/b.png')]));

    expect($pending->state['children'])->toBe(['K1', 'K2']);

    foreach (K::sent('POST 1784/threads') as $request) {
        expect($request->hasHeader('X-Signed', 'yes'))->toBeTrue()
            ->and($request->hasHeader('Authorization'))->toBeFalse()
            ->and($request['access_token'])->toBe(K::TOKEN);
    }
});

// --- Carousels ----------------------------------------------------------------------

it('creates carousel items concurrently, in source order, with no post-level fields', function () {
    K::fake([
        'POST 1784/threads' => [childRoute([IMG_A => 'K1', IMG_B => 'K2', VID => 'K3'])],
    ]);

    $options = new ThreadsOptions(madeWithAi: true, extra: ['enable_reply_approvals' => true]);
    $pending = K::driver()->publish(K::post([
        Media::image(IMG_A, 'first'),
        Media::image(IMG_B),
        Media::video(VID),
    ], 'Carousel', $options));

    expect($pending->state)->toMatchArray([
        'phase' => 'await_children',
        'kind' => 'carousel',
        'children' => ['K1', 'K2', 'K3'],
        'pending' => ['K1', 'K2', 'K3'],
        'video_children' => ['K3'],
        'text' => 'Carousel',
    ])->and($pending->recheckAfter)->toBe(30);

    $items = K::sent('POST 1784/threads');
    expect($items)->toHaveCount(3);

    $bodies = array_map(fn (Request $r) => $r->data(), $items);
    usort($bodies, fn ($a, $b) => strcmp($a['image_url'] ?? $a['video_url'], $b['image_url'] ?? $b['video_url']));

    expect($bodies[0])->toBe(['is_carousel_item' => true, 'media_type' => 'IMAGE', 'image_url' => IMG_A, 'alt_text' => 'first', 'access_token' => K::TOKEN])
        ->and($bodies[1])->toBe(['is_carousel_item' => true, 'media_type' => 'IMAGE', 'image_url' => IMG_B, 'access_token' => K::TOKEN]);

    foreach ($items as $item) {
        expect($item->hasHeader('Authorization', 'Bearer '.K::TOKEN))->toBeTrue();
    }
});

it('waits on every item, images included, then creates the parent and publishes', function () {
    K::fake([
        'POST 1784/threads' => [
            childRoute([IMG_A => 'K1', IMG_B => 'K2']),
            childRoute([IMG_A => 'K1', IMG_B => 'K2']),
            ['id' => 'P1'],
        ],
        'GET K1' => [['status' => 'FINISHED']],
        'GET K2' => [['status' => 'IN_PROGRESS'], ['status' => 'FINISHED']],
        'GET P1' => [['status' => 'FINISHED']],
        'POST 1784/threads_publish' => [['id' => '1799']],
        'GET 1799' => [['permalink' => 'https://www.threads.net/@acme/post/C']],
    ]);

    $driver = K::driver();
    $options = new ThreadsOptions(replyControl: ThreadsReplyControl::MentionedOnly, madeWithAi: true, extra: ['enable_reply_approvals' => true, 'auto_publish_text' => true]);
    $post = K::post([Media::image(IMG_A), Media::image(IMG_B)], 'Two pictures', $options);

    $pending = $driver->publish($post);
    expect($pending->recheckAfter)->toBe(10);

    $waiting = $driver->resume($post, $pending->state);
    expect($waiting->state['pending'])->toBe(['K2'])
        ->and($waiting->recheckAfter)->toBe(10);

    $parent = $driver->resume($post, $waiting->state);
    expect($parent->state)->toMatchArray([
        'phase' => 'await',
        'kind' => 'carousel',
        'container_id' => 'P1',
        'children' => ['K1', 'K2'],
        'text' => 'Two pictures',
    ])->and($parent->recheckAfter)->toBe(10);

    $parentBody = K::sent('POST 1784/threads')[2]->data();
    expect($parentBody)->toBe([
        'enable_reply_approvals' => true,
        'reply_control' => 'mentioned_only',
        'is_made_with_ai' => true,
        'media_type' => 'CAROUSEL',
        'children' => 'K1,K2',
        'text' => 'Two pictures',
        'access_token' => K::TOKEN,
    ]);

    // K1 was FINISHED on the first poll and is not polled again.
    expect(K::sent('GET K1'))->toHaveCount(1)->and(K::sent('GET K2'))->toHaveCount(2);

    $published = $driver->resume($post, $driver->resume($post, $parent->state)->state);
    expect($published->result->platformPostId)->toBe('1799')
        ->and($published->result->payload['children'])->toBe(['K1', 'K2'])
        ->and($published->result->payload['container_id'])->toBe('P1');
});

it('keeps polling a carousel at the video cadence while a video item is pending', function () {
    K::fake(['GET K3' => [['status' => 'IN_PROGRESS']], 'GET K1' => [['status' => 'FINISHED']]]);

    $next = K::driver()->resume(K::post([Media::image(IMG_A), Media::video(VID)]), [
        'phase' => 'await_children', 'children' => ['K1', 'K3'], 'pending' => ['K1', 'K3'], 'video_children' => ['K3'],
    ]);

    expect($next->recheckAfter)->toBe(30)->and($next->state['pending'])->toBe(['K3']);
});

it('creates the carousel items one by one when pooling is off', function () {
    config(['social.api.threads.pool_children' => false]);
    K::fake(['POST 1784/threads' => [['id' => 'K1'], ['id' => 'K2']]]);

    $pending = K::driver()->publish(K::post([Media::image(IMG_A), Media::image(IMG_B)]));

    expect($pending->state['children'])->toBe(['K1', 'K2'])
        ->and(K::sent('POST 1784/threads')[0]['image_url'])->toBe(IMG_A)
        ->and(K::sent('POST 1784/threads')[1]['image_url'])->toBe(IMG_B);
});

it('maps a refused carousel item', function () {
    K::fake([
        'POST 1784/threads' => [static fn (Request $r) => $r['image_url'] === IMG_B
            ? K::error('download-timeout-2207003')($r)
            : Http::response(['id' => 'K1'])],
    ]);

    $e = K::catch(fn () => K::driver()->publish(K::post([Media::image(IMG_A), Media::image(IMG_B)])));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->retryAfter)->toBe(60);
});

it('turns a pooled transport failure into a connection TemporaryException', function () {
    K::fake([
        'POST 1784/threads' => [static fn (Request $r) => $r['image_url'] === IMG_B
            ? K::timeout()($r)
            : Http::response(['id' => 'K1'])],
    ]);

    $e = K::catch(fn () => K::driver()->publish(K::post([Media::image(IMG_A), Media::image(IMG_B)])));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::Network)
        ->and($e->context['connection'])->toBeTrue()
        ->and($e->getMessage())->not->toContain(K::TOKEN);
});

it('requires an id on every carousel item', function () {
    K::fake(['POST 1784/threads' => [['id' => '']]]);

    $e = K::catch(fn () => K::driver()->publish(K::post([Media::image(IMG_A), Media::image(IMG_B)])));

    expect($e)->toBeInstanceOf(TemporaryException::class)->and($e->getMessage())->toContain('carousel item container');
});

it('re-creates only the carousel item that failed to download', function () {
    K::fake([
        'GET K1' => [['status' => 'FINISHED']],
        'GET K2' => [['status' => 'ERROR', 'error_message' => 'FAILED_DOWNLOADING_VIDEO']],
        'POST 1784/threads' => [['id' => 'K2b']],
    ]);

    $next = K::driver()->resume(K::post([Media::image(IMG_A), Media::video(VID, null, 'alt')]), [
        'phase' => 'await_children', 'kind' => 'carousel', 'children' => ['K1', 'K2'], 'pending' => ['K1', 'K2'], 'video_children' => ['K2'],
    ]);

    expect($next->state)->toMatchArray([
        'phase' => 'await_children',
        'children' => ['K1', 'K2b'],
        'video_children' => ['K2b'],
        'restarts' => 1,
    ])->and($next->state['pending'])->toBe(['K1', 'K2b'])
        ->and($next->recheckAfter)->toBe(30)
        ->and(K::sent('POST 1784/threads')[0]->data())->toBe([
            'is_carousel_item' => true, 'media_type' => 'VIDEO', 'video_url' => VID, 'alt_text' => 'alt', 'access_token' => K::TOKEN,
        ]);
});

it('fails a carousel item with a permanent container error', function () {
    K::fake(['GET K1' => [['status' => 'ERROR', 'error_message' => 'INVALID_DURATION']]]);

    $e = K::catch(fn () => K::driver()->resume(K::post([Media::video(VID), Media::image(IMG_A)]), [
        'phase' => 'await_children', 'children' => ['K1', 'K2'], 'pending' => ['K1', 'K2'],
    ]));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain('carousel item 1')
        ->and($e->getMessage())->toContain('300 seconds')
        ->and($e->context)->toMatchArray(['container_id' => 'K1', 'error_message' => 'INVALID_DURATION', 'container_status' => 'ERROR']);
});

it('stops re-creating a carousel item after two restarts', function () {
    K::fake(['GET K1' => [['status' => 'ERROR', 'error_message' => 'UNKNOWN']]]);

    $e = K::catch(fn () => K::driver()->resume(K::post([Media::video(VID), Media::image(IMG_A)]), [
        'phase' => 'await_children', 'children' => ['K1', 'K2'], 'pending' => ['K1'], 'restarts' => 2,
    ]));

    expect($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain('after 3 attempts');
});

it('re-creates an expired carousel item once, then times out', function () {
    K::fake([
        'GET K1' => [['status' => 'EXPIRED']],
        'POST 1784/threads' => [['id' => 'K1b']],
    ]);
    $driver = K::driver();
    $post = K::post([Media::image(IMG_A), Media::image(IMG_B)]);
    $state = ['phase' => 'await_children', 'children' => ['K1', 'K2'], 'pending' => ['K1']];

    $next = $driver->resume($post, $state);
    expect($next->state['children'])->toBe(['K1b', 'K2'])->and($next->state['expirations'])->toBe(1);

    $e = K::catch(fn () => $driver->resume($post, ['expirations' => 1] + $state));
    expect($e)->toBeInstanceOf(PermanentException::class)->and($e->reason)->toBe(FailureReason::Timeout);
});

it('restarts the whole carousel when a failed item cannot be matched to its media', function () {
    K::fake([
        'GET K1' => [['status' => 'ERROR']],
        'POST 1784/threads' => [childRoute([IMG_A => 'N1', IMG_B => 'N2', VID => 'N3'])],
    ]);

    // Three media but only two children recorded: rebuild everything.
    $next = K::driver()->resume(K::post([Media::image(IMG_A), Media::image(IMG_B), Media::video(VID)]), [
        'phase' => 'await_children', 'children' => ['K1', 'K2'], 'pending' => ['K1'],
    ]);

    expect($next->state)->toMatchArray(['phase' => 'await_children', 'children' => ['N1', 'N2', 'N3'], 'restarts' => 1]);
});

it('creates the parent anyway when items report no status for 12 polls', function () {
    K::fake([
        'GET K1' => [['id' => 'K1']],
        'GET K2' => [['status' => 'FINISHED']],
        'POST 1784/threads' => [['id' => 'P9']],
    ]);
    $driver = K::driver();
    $post = K::post([Media::image(IMG_A), Media::image(IMG_B)]);
    $state = ['phase' => 'await_children', 'children' => ['K1', 'K2'], 'pending' => ['K1', 'K2']];

    $next = $driver->resume($post, $state);
    expect($next->state['phase'])->toBe('await_children')
        ->and($next->state['missing_polls'])->toBe(1)
        ->and($next->state['pending'])->toBe(['K1']);

    $parent = $driver->resume($post, ['missing_polls' => 11] + $next->state);
    expect($parent->state)->toMatchArray(['phase' => 'await', 'container_id' => 'P9']);
});

it('resets the missing-status budget while another item is still processing', function () {
    K::fake(['GET K1' => [['id' => 'K1']], 'GET K2' => [['status' => 'IN_PROGRESS']]]);

    $next = K::driver()->resume(K::post([Media::image(IMG_A), Media::image(IMG_B)]), [
        'phase' => 'await_children', 'children' => ['K1', 'K2'], 'pending' => ['K1', 'K2'], 'missing_polls' => 11,
    ]);

    expect($next->state['phase'])->toBe('await_children')->and($next->state)->not->toHaveKey('missing_polls');
});

it('restarts the whole carousel when the parent container fails to process', function () {
    K::fake([
        'GET P1' => [['status' => 'ERROR', 'error_message' => 'FAILED_DOWNLOADING_VIDEO']],
        'POST 1784/threads' => [childRoute([IMG_A => 'N1', VID => 'N2'])],
    ]);

    $next = K::driver()->resume(K::post([Media::image(IMG_A), Media::video(VID)], 'Again'), [
        'phase' => 'await', 'kind' => 'carousel', 'container_id' => 'P1', 'children' => ['K1', 'K2'], 'created_at' => 1, 'text' => 'Again',
    ]);

    expect($next->state)->toMatchArray([
        'phase' => 'await_children',
        'children' => ['N1', 'N2'],
        'video_children' => ['N2'],
        'restarts' => 1,
        'created_at' => Carbon::now()->getTimestamp(),
    ]);
});

it('fails when the continuation has no carousel items', function () {
    $e = K::catch(fn () => K::driver()->resume(K::post(), ['phase' => 'await_children', 'children' => []]));

    expect($e)->toBeInstanceOf(PermanentException::class);
});

// --- Container statuses ---------------------------------------------------------------

it('recovers a post that is already PUBLISHED instead of publishing it again', function () {
    $created = Carbon::now()->getTimestamp();

    K::fake([
        'GET C1' => [['status' => 'PUBLISHED']],
        'GET 1784/threads' => [['data' => [
            ['id' => '1700', 'text' => 'Hello Threads', 'timestamp' => '2026-11-18T09:30:00+0000', 'permalink' => 'https://www.threads.net/@acme/post/OLD'],
            ['id' => '1701', 'text' => 'Other', 'timestamp' => '2026-11-18T10:01:00+0000'],
            ['id' => '1702', 'text' => "Hello Threads\r\n", 'timestamp' => '2026-11-18T10:01:30+0000', 'permalink' => 'https://www.threads.net/@acme/post/NEW'],
        ]]],
    ]);

    $result = K::driver()->resume(K::post(), [
        'phase' => 'await', 'kind' => 'text', 'container_id' => 'C1', 'created_at' => $created, 'text' => 'Hello Threads',
    ]);

    expect($result)->toBeInstanceOf(Published::class)
        ->and($result->result->platformPostId)->toBe('1702')
        ->and($result->result->url)->toBe('https://www.threads.net/@acme/post/NEW')
        ->and($result->result->payload)->toMatchArray(['container_id' => 'C1', 'recovered' => true])
        ->and($result->result->payload)->not->toHaveKey('lookup_failed');

    expect(K::query(K::sent('GET 1784/threads')[0]))->toBe(['fields' => 'id,text,timestamp,permalink', 'limit' => '25']);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'threads_publish'));
});

it('fetches the permalink of a recovered post that came without one', function () {
    K::fake([
        'GET C1' => [['status' => 'PUBLISHED']],
        'GET 1784/threads' => [['data' => [['id' => '1702', 'text' => 'Hello Threads', 'timestamp' => '2026-11-18T10:00:10+0000']]]],
        'GET 1702' => [['permalink' => 'https://www.threads.net/@acme/post/P']],
    ]);

    $result = K::driver()->resume(K::post(), ['phase' => 'await', 'container_id' => 'C1', 'created_at' => Carbon::now()->getTimestamp(), 'text' => 'Hello Threads']);

    expect($result->result->url)->toBe('https://www.threads.net/@acme/post/P');
});

it('reports a recovered post with a null id when it cannot be told apart', function (array $state, array $routes, bool $lookupFailed) {
    K::fake(['GET C1' => [['status' => 'PUBLISHED']]] + $routes);

    // The last of the 3 lookups (social.api.threads.recovery_lookups): two came up empty before.
    $result = K::driver()->resume(K::post(), ['phase' => 'await', 'container_id' => 'C1', 'lookup_attempts' => 2] + $state);

    expect($result)->toBeInstanceOf(Published::class)
        ->and($result->result->platformPostId)->toBeNull()
        ->and($result->result->success)->toBeTrue()
        ->and($result->result->payload['recovered'])->toBeTrue()
        ->and($result->result->payload['lookup_failed'] ?? false)->toBe($lookupFailed);
})->with([
    'no text to match' => [['text' => '', 'created_at' => 1], [], false],
    'nothing matches' => [['text' => 'Mine', 'created_at' => 1], ['GET 1784/threads' => [['data' => [['id' => '1', 'text' => 'Theirs', 'timestamp' => '2026-11-18T10:00:00+0000']]]]], false],
    'too old' => [['text' => 'Mine', 'created_at' => 1_800_000_000], ['GET 1784/threads' => [['data' => [['id' => '1', 'text' => 'Mine', 'timestamp' => '2026-11-18T10:00:00+0000']]]]], false],
    'bad timestamp' => [['text' => 'Mine', 'created_at' => 1], ['GET 1784/threads' => [['data' => [['id' => '1', 'text' => 'Mine', 'timestamp' => 'not a date']]]]], false],
    'blank id' => [['text' => 'Mine'], ['GET 1784/threads' => [['data' => [['id' => '', 'text' => 'Mine']]]]], false],
    'lookup refused' => [['text' => 'Mine'], ['GET 1784/threads' => [K::error('rate-limit-code-4')]], true],
    'lookup timed out' => [['text' => 'Mine'], ['GET 1784/threads' => [K::timeout()]], true],
]);

it('reports a post without text as recovered at once, without a lookup', function () {
    K::fake(['GET C1' => [['status' => 'PUBLISHED']]]);

    $result = K::driver()->resume(K::post([Media::image(IMG_A)], null), ['phase' => 'await', 'container_id' => 'C1', 'text' => '', 'created_at' => 1]);

    expect($result)->toBeInstanceOf(Published::class)
        ->and($result->result->platformPostId)->toBeNull()
        ->and(K::routes())->toBe(['GET C1']);
});

it('looks the post up again when the recovery lookup fails or finds nothing', function (array $route, int $recheckAfter) {
    K::fake(['GET C1' => [['status' => 'PUBLISHED']], 'GET 1784/threads' => $route]);

    $next = K::driver()->resume(K::post(), [
        'phase' => 'await', 'kind' => 'text', 'container_id' => 'C1', 'created_at' => Carbon::now()->getTimestamp(), 'text' => 'Hello Threads',
    ]);

    expect($next)->toBeInstanceOf(Pending::class)
        ->and($next->committed)->toBeTrue()
        ->and($next->commits)->toBeFalse()
        ->and($next->recheckAfter)->toBe($recheckAfter)
        ->and($next->state)->toMatchArray(['phase' => 'await', 'container_id' => 'C1', 'publish_sent' => true, 'lookup_attempts' => 1]);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'threads_publish'));
})->with([
    // A throttle's wait is honoured, up to a minute.
    'lookup throttled' => [[K::error('rate-limit-code-4')], 60],
    'lookup throttled briefly' => [[K::json(K::errorBody('rate-limit-code-4'), 400, ['Retry-After' => '20'])], 20],
    'lookup timed out' => [[K::timeout()], 10],
    'lookup server error' => [[K::error('unexpected-error', 500)], 60],
    'not listed yet' => [[['data' => []]], 10],
]);

it('finds the post on a later lookup after a throttled one', function () {
    K::fake([
        'POST 1784/threads_publish' => [K::timeout()],
        'GET C1' => [['status' => 'PUBLISHED']],
        'GET 1784/threads' => [K::error('rate-limit-code-4'), ['data' => [['id' => '1703', 'text' => 'Hello Threads', 'timestamp' => '2026-11-18T10:00:35+0000', 'permalink' => 'https://t/1703']]]],
    ]);
    $driver = K::driver();
    $post = K::post();

    $verify = $driver->resume($post, ['phase' => 'publish', 'container_id' => 'C1', 'created_at' => Carbon::now()->getTimestamp(), 'text' => 'Hello Threads']);
    $retry = $driver->resume($post, $verify->state);
    $result = $driver->resume($post, $retry->state);

    expect($retry)->toBeInstanceOf(Pending::class)
        ->and($retry->committed)->toBeTrue()
        ->and($result)->toBeInstanceOf(Published::class)
        ->and($result->result->platformPostId)->toBe('1703')
        ->and($result->result->url)->toBe('https://t/1703')
        ->and($result->result->payload)->toMatchArray(['container_id' => 'C1', 'recovered' => true])
        ->and($result->result->payload)->not->toHaveKey('lookup_failed')
        ->and(K::sent('POST 1784/threads_publish'))->toHaveCount(1);
});

it('gives up on a refused lookup at once, since it will not clear', function () {
    K::fake(['GET C1' => [['status' => 'PUBLISHED']], 'GET 1784/threads' => [K::error('permission-code-10', 403)]]);

    $result = K::driver()->resume(K::post(), ['phase' => 'await', 'container_id' => 'C1', 'text' => 'Hello Threads']);

    expect($result)->toBeInstanceOf(Published::class)
        ->and($result->result->platformPostId)->toBeNull()
        ->and($result->result->payload['lookup_failed'])->toBeTrue();
});

it('honours the configured number of recovery lookups', function () {
    config(['social.api.threads.recovery_lookups' => 1]);
    K::fake(['GET C1' => [['status' => 'PUBLISHED']], 'GET 1784/threads' => [K::timeout()]]);

    $result = K::driver()->resume(K::post(), ['phase' => 'await', 'container_id' => 'C1', 'text' => 'Hello Threads']);

    expect($result)->toBeInstanceOf(Published::class)
        ->and($result->result->platformPostId)->toBeNull()
        ->and($result->result->payload['lookup_failed'])->toBeTrue();
});

it('recovers a post published as a reply from the replies edge', function (ThreadsOptions|RawOptions $options, array $routes) {
    K::fake(['GET C1' => [['status' => 'PUBLISHED']]] + $routes);

    $result = K::driver()->resume(K::post(caption: 'Part 2', options: $options), [
        'phase' => 'await', 'kind' => 'text', 'container_id' => 'C1', 'created_at' => Carbon::now()->getTimestamp(), 'text' => 'Part 2',
    ]);

    expect($result)->toBeInstanceOf(Published::class)
        ->and($result->result->platformPostId)->toBe('1790')
        ->and($result->result->url)->toBe('https://t/1790')
        ->and(K::query(K::sent('GET 1784/replies')[0]))->toBe(['fields' => 'id,text,timestamp,permalink', 'limit' => '25']);
})->with([
    'typed replyToId' => [new ThreadsOptions(replyToId: '1777'), [
        'GET 1784/replies' => [['data' => [['id' => '1790', 'text' => 'Part 2', 'timestamp' => '2026-11-18T10:00:05+0000', 'permalink' => 'https://t/1790']]]],
    ]],
    'raw reply_to_id' => [new RawOptions(Platform::Threads, ['reply_to_id' => '1777']), [
        'GET 1784/replies' => [['data' => [['id' => '1790', 'text' => 'Part 2', 'timestamp' => '2026-11-18T10:00:05+0000', 'permalink' => 'https://t/1790']]]],
    ]],
    'listed on the threads edge after all' => [new ThreadsOptions(replyToId: '1777'), [
        'GET 1784/replies' => [['data' => []]],
        'GET 1784/threads' => [['data' => [['id' => '1790', 'text' => 'Part 2', 'timestamp' => '2026-11-18T10:00:05+0000', 'permalink' => 'https://t/1790']]]],
    ]],
]);

it('looks a non-reply post up on the threads edge only', function () {
    K::fake(['GET C1' => [['status' => 'PUBLISHED']], 'GET 1784/threads' => [['data' => [['id' => '1702', 'text' => 'Hello Threads', 'permalink' => 'https://t/1702']]]]]);

    K::driver()->resume(K::post(), ['phase' => 'await', 'container_id' => 'C1', 'text' => 'Hello Threads']);

    expect(K::routes())->toBe(['GET C1', 'GET 1784/threads']);
});

it('matches a legacy state without created_at on text alone', function () {
    K::fake([
        'GET C1' => [['status' => 'PUBLISHED']],
        'GET 1784/threads' => [['data' => [['id' => '1600', 'text' => 'Hello Threads', 'timestamp' => '2020-01-01T00:00:00+0000', 'permalink' => 'https://t/1600']]]],
    ]);

    $result = K::driver()->resume(K::post(), ['phase' => 'await', 'container_id' => 'C1', 'text' => 'Hello Threads']);

    expect($result->result->platformPostId)->toBe('1600');
});

it('restarts once when the container EXPIRED, then times out', function () {
    K::fake([
        'GET C1' => [['status' => 'EXPIRED']],
        'POST 1784/threads' => [['id' => 'C2']],
    ]);
    $driver = K::driver();
    $post = K::post();

    $restarted = $driver->resume($post, ['phase' => 'await', 'kind' => 'text', 'container_id' => 'C1', 'created_at' => 1, 'text' => 'Hello Threads', 'polls' => 9]);

    expect($restarted->state)->toMatchArray([
        'phase' => 'await',
        'container_id' => 'C2',
        'expirations' => 1,
        'created_at' => Carbon::now()->getTimestamp(),
    ])->and($restarted->state)->not->toHaveKey('polls')
        ->and($restarted->recheckAfter)->toBe(30);

    $e = K::catch(fn () => $driver->resume($post, ['phase' => 'await', 'container_id' => 'C1', 'expirations' => 1]));
    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Timeout)
        ->and($e->context['container_status'])->toBe('EXPIRED');
});

it('restarts on a retryable container ERROR, at most twice', function (?string $errorMessage) {
    K::fake([
        'GET C1' => [['status' => 'ERROR', 'error_message' => $errorMessage]],
        'POST 1784/threads' => [['id' => 'C2']],
    ]);
    $driver = K::driver();
    $post = K::post([Media::video(VID)]);

    $first = $driver->resume($post, ['phase' => 'await', 'kind' => 'video', 'container_id' => 'C1']);
    expect($first->state)->toMatchArray(['container_id' => 'C2', 'restarts' => 1, 'kind' => 'video']);

    $second = $driver->resume($post, ['phase' => 'await', 'kind' => 'video', 'container_id' => 'C1', 'restarts' => 1]);
    expect($second->state['restarts'])->toBe(2);

    $e = K::catch(fn () => $driver->resume($post, ['phase' => 'await', 'kind' => 'video', 'container_id' => 'C1', 'restarts' => 2]));
    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain('the video after 3 attempts');
})->with(['FAILED_DOWNLOADING_VIDEO', 'UNKNOWN', null]);

it('fails on a permanent container ERROR with a readable reason', function (string $code, string $fragment) {
    K::fake(['GET C1' => [['status' => 'ERROR', 'error_message' => $code]]]);

    $e = K::catch(fn () => K::driver()->resume(K::post([Media::video(VID)]), ['phase' => 'await', 'kind' => 'video', 'container_id' => 'C1']));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain('Threads rejected the video')
        ->and($e->getMessage())->toContain($fragment)
        ->and($e->context['error_message'])->toBe($code);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
})->with([
    ['INVALID_ASPEC_RATIO', '0.01:1 and 10:1'],
    ['INVALID_BIT_RATE', 'bit rate'],
    ['INVALID_FRAME_RATE', '23 and 60 fps'],
    ['INVALID_AUDIO_CHANNELS', 'mono or stereo'],
    ['INVALID_AUDIO_CHANNEL_LAYOUT', 'channel layout'],
    ['FAILED_PROCESSING_AUDIO', 'AAC'],
    ['FAILED_PROCESSING_VIDEO', 'H.264 or HEVC'],
    ['SOMETHING_NEW', 'refused'],
]);

it('publishes anyway after 12 polls without a status, but never while IN_PROGRESS', function () {
    K::fake(['GET C1' => [['id' => 'C1']]]);
    $driver = K::driver();
    $post = K::post();

    $next = $driver->resume($post, ['phase' => 'await', 'container_id' => 'C1']);
    expect($next->commits)->toBeFalse()
        ->and($next->state['missing_polls'])->toBe(1)
        ->and($next->recheckAfter)->toBe(10);

    $commit = $driver->resume($post, ['phase' => 'await', 'container_id' => 'C1', 'missing_polls' => 11]);
    expect($commit->commits)->toBeTrue()->and($commit->state['phase'])->toBe('publish');
});

it('keeps waiting on IN_PROGRESS however long it takes', function () {
    K::fake(['GET C1' => [['status' => 'IN_PROGRESS']]]);

    $next = K::driver()->resume(K::post(), ['phase' => 'await', 'container_id' => 'C1', 'missing_polls' => 50, 'polls' => 80]);

    expect($next->commits)->toBeFalse()->and($next->state['polls'])->toBe(81)
        ->and($next->state)->not->toHaveKey('missing_polls');
});

it('fails when the continuation lost its container id', function () {
    $e = K::catch(fn () => K::driver()->resume(K::post(), ['phase' => 'await']));

    expect($e)->toBeInstanceOf(PermanentException::class)->and($e->getMessage())->toContain('container id');
});

it('rejects an unknown phase', function () {
    $e = K::catch(fn () => K::driver()->resume(K::post(), ['phase' => 'teleport']));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->context['phase'])->toBe('teleport');
});

// --- Publish (commit phase) --------------------------------------------------------------

it('goes back to a committed await when threads_publish times out, then recovers', function () {
    K::fake([
        'POST 1784/threads_publish' => [K::timeout()],
        'GET C1' => [['status' => 'PUBLISHED']],
        'GET 1784/threads' => [['data' => [['id' => '1703', 'text' => 'Hello Threads', 'timestamp' => '2026-11-18T10:00:05+0000', 'permalink' => 'https://t/1703']]]],
    ]);
    $driver = K::driver();
    $post = K::post();

    $verify = $driver->resume($post, ['phase' => 'publish', 'container_id' => 'C1', 'created_at' => Carbon::now()->getTimestamp(), 'text' => 'Hello Threads']);

    expect($verify)->toBeInstanceOf(Pending::class)
        ->and($verify->committed)->toBeTrue()
        ->and($verify->commits)->toBeFalse()
        ->and($verify->state)->toMatchArray(['phase' => 'await', 'publish_sent' => true]);

    $result = $driver->resume($post, $verify->state);
    expect($result->result->platformPostId)->toBe('1703');
    expect(K::sent('POST 1784/threads_publish'))->toHaveCount(1);
});

it('verifies the container instead of publishing blind after an unclear threads_publish refusal', function (string $fixture, int $status) {
    K::fake([
        'POST 1784/threads_publish' => [K::error($fixture, $status)],
        'GET C1' => [['status' => 'PUBLISHED']],
        'GET 1784/threads' => [['data' => [['id' => '1704', 'text' => 'Hello Threads', 'timestamp' => Carbon::now()->addSeconds(5)->format('Y-m-d\TH:i:sO'), 'permalink' => 'https://t/1704']]]],
    ]);
    $driver = K::driver();
    $post = K::post();

    $verify = $driver->resume($post, ['phase' => 'publish', 'container_id' => 'C1', 'created_at' => Carbon::now()->getTimestamp(), 'since' => Carbon::now()->getTimestamp(), 'text' => 'Hello Threads']);

    expect($verify)->toBeInstanceOf(Pending::class)
        ->and($verify->committed)->toBeTrue()
        ->and($verify->state)->toMatchArray(['phase' => 'await', 'publish_sent' => true]);

    $result = $driver->resume($post, $verify->state);

    expect($result->result->platformPostId)->toBe('1704')
        ->and(K::sent('POST 1784/threads_publish'))->toHaveCount(1);
})->with([
    'transient code 1 with HTTP 400' => ['unknown-error-code-1', 400],
    'unexpected error with HTTP 500' => ['unexpected-error', 500],
]);

it('lets a threads_publish that never left the client bubble, so the chain is not marked committed', function (array $state, array $routes) {
    K::fake(['POST 1784/threads_publish' => [K::refused()]] + $routes);

    $e = K::catch(fn () => K::driver()->resume(K::post(), ['phase' => 'publish', 'container_id' => 'C1', 'text' => 'Hello Threads'] + $state));

    // The job turns it into TemporaryException::connection(request_sent: false) and re-runs the phase.
    expect($e)->toBeInstanceOf(ConnectionException::class)
        ->and(TemporaryException::requestNeverSent($e))->toBeTrue()
        ->and(TemporaryException::connection(Platform::Threads, $e)->context['request_sent'])->toBeFalse()
        ->and(K::sent('POST 1784/threads_publish'))->toHaveCount(1);
})->with([
    'first try' => [[], []],
    'recovering run' => [[Pending::RECOVERING => true], ['GET C1' => [['status' => 'FINISHED']]]],
]);

it('publishes again after a lost answer when the container is still FINISHED', function () {
    K::fake(['GET C1' => [['status' => 'FINISHED']]]);

    $commit = K::driver()->resume(K::post(), ['phase' => 'await', 'container_id' => 'C1', 'publish_sent' => true]);

    expect($commit->commits)->toBeTrue()->and($commit->state)->not->toHaveKey('publish_sent');
});

it('stays committed while a possibly-published container is unclear', function () {
    K::fake(['GET C1' => [['status' => 'IN_PROGRESS']]]);

    $next = K::driver()->resume(K::post(), ['phase' => 'await', 'container_id' => 'C1', 'publish_sent' => true]);

    expect($next->committed)->toBeTrue();
});

it('verifies on the container when a recovering publish runs', function (string $status, string $expect) {
    K::fake([
        'GET C1' => [['status' => $status]],
        'POST 1784/threads_publish' => [['id' => '1704']],
        'GET 1704' => [['permalink' => 'https://t/1704']],
        'GET 1784/threads' => [['data' => [['id' => '1709', 'text' => 'Hello Threads', 'permalink' => 'https://t/1709']]]],
    ]);

    $outcome = K::driver()->resume(K::post(), [
        'phase' => 'publish', 'container_id' => 'C1', 'text' => 'Hello Threads',
        Pending::RECOVERING => true, Pending::COMMITTING_SINCE => 1, Pending::ATTEMPT => 2,
    ]);

    match ($expect) {
        'published' => expect($outcome)->toBeInstanceOf(Published::class)
            ->and($outcome->result->platformPostId)->toBe('1704')
            ->and(K::sent('POST 1784/threads_publish'))->toHaveCount(1),
        'recovered' => expect($outcome)->toBeInstanceOf(Published::class)
            ->and($outcome->result->payload['recovered'])->toBeTrue()
            ->and($outcome->result->platformPostId)->toBe('1709')
            ->and(K::sent('POST 1784/threads_publish'))->toHaveCount(0),
        'waiting' => expect($outcome)->toBeInstanceOf(Pending::class)
            ->and($outcome->state['phase'])->toBe('await')
            ->and($outcome->state)->not->toHaveKey(Pending::RECOVERING)
            ->and(K::sent('POST 1784/threads_publish'))->toHaveCount(0),
    };
})->with([
    'not published yet' => ['FINISHED', 'published'],
    'already live' => ['PUBLISHED', 'recovered'],
    'still processing' => ['IN_PROGRESS', 'waiting'],
]);

it('publishes from a recovering run once the missing-status budget is spent', function () {
    K::fake([
        'GET C1' => [['id' => 'C1']],
        'POST 1784/threads_publish' => [['id' => '1705']],
        'GET 1705' => [['permalink' => 'https://t/1705']],
    ]);

    $outcome = K::driver()->resume(K::post(), ['phase' => 'publish', 'container_id' => 'C1', 'missing_polls' => 11, Pending::RECOVERING => true]);

    expect($outcome)->toBeInstanceOf(Published::class)->and($outcome->result->platformPostId)->toBe('1705');
});

it('retries 4279009 Media Not Found on publish', function () {
    K::fake(['POST 1784/threads_publish' => [K::error('media-not-found-4279009')]]);

    $e = K::catch(fn () => K::driver()->resume(K::post(), ['phase' => 'publish', 'container_id' => 'C1']));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->retryAfter)->toBe(30)
        ->and($e->context['subcode'])->toBe(4279009);
});

it('verifies on the container when threads_publish answers without an id', function () {
    K::fake(['POST 1784/threads_publish' => [['success' => true]]]);
    $driver = K::driver();

    $verify = $driver->resume(K::post(), ['phase' => 'publish', 'container_id' => 'C1']);
    expect($verify)->toBeInstanceOf(Pending::class)
        ->and($verify->committed)->toBeTrue()
        ->and($verify->state)->toMatchArray(['phase' => 'await', 'verify_rounds' => 1, 'publish_sent' => true]);

    $e = K::catch(fn () => $driver->resume(K::post(), ['phase' => 'publish', 'container_id' => 'C1', 'verify_rounds' => 3]));
    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->possiblyPublished())->toBeTrue();
});

it('still returns the post when the permalink cannot be fetched', function () {
    K::fake([
        'POST 1784/threads_publish' => [['id' => '1706']],
        'GET 1706' => [K::error('rate-limit-code-4')],
    ]);

    $result = K::driver()->resume(K::post(), ['phase' => 'publish', 'container_id' => 'C1']);

    expect($result->result->platformPostId)->toBe('1706')->and($result->result->url)->toBeNull();
});

it('keeps the v1.0 publishContainer() entry point', function () {
    K::fake(['POST 1784/threads_publish' => [['id' => '1707']], 'GET 1707' => [['permalink' => 'https://t/1707']]]);

    $driver = new class extends ThreadsDriver
    {
        public function callPublishContainer(PreparedPost $post, string $id): PublishOutcome
        {
            return $this->publishContainer($post, $id);
        }
    };

    expect($driver->callPublishContainer(K::post(), 'C9')->result->platformPostId)->toBe('1707')
        ->and(K::sent('POST 1784/threads_publish')[0]['creation_id'])->toBe('C9');
});

it('creates through the v1.0 startSingle() and startCarousel() hooks, so subclass overrides still apply', function () {
    K::fake([
        'POST 1784/threads' => [childRoute([IMG_A => 'K1', IMG_B => 'K2']), childRoute([IMG_A => 'K1', IMG_B => 'K2'])],
        'GET C0' => [['status' => 'EXPIRED']],
    ]);

    $driver = new class extends ThreadsDriver
    {
        /** @var list<string> */
        public array $calls = [];

        protected function startSingle(PreparedPost $post, ?Media $item): PublishOutcome
        {
            $this->calls[] = 'single';

            return parent::startSingle($post, $item);
        }

        protected function startCarousel(PreparedPost $post): PublishOutcome
        {
            $this->calls[] = 'carousel';

            return parent::startCarousel($post);
        }
    };

    $driver->publish(K::post());
    $driver->publish(K::post([Media::image(IMG_A), Media::image(IMG_B)]));

    // A restart (fresh container after EXPIRED) goes through the hook too and keeps its counter.
    $restarted = $driver->resume(K::post(), ['phase' => 'await', 'kind' => 'text', 'container_id' => 'C0', 'created_at' => 1, 'text' => 'Hello Threads']);

    expect($driver->calls)->toBe(['single', 'carousel', 'single'])
        ->and($restarted->state)->toMatchArray(['phase' => 'await', 'expirations' => 1]);
});

it('carries the restart counters over a v1.0 startSingle() override that rebuilds the state', function () {
    K::fake(['GET C0' => [['status' => 'ERROR', 'error_message' => 'UNKNOWN']]]);

    $driver = new class extends ThreadsDriver
    {
        protected function startSingle(PreparedPost $post, ?Media $item): PublishOutcome
        {
            return $this->pending(['phase' => 'await_publish', 'container' => 'LEGACY'], 30);
        }
    };

    $restarted = $driver->resume(K::post(), ['phase' => 'await', 'container_id' => 'C0', 'restarts' => 1]);

    expect($restarted)->toBeInstanceOf(Pending::class)
        ->and($restarted->state)->toMatchArray(['container' => 'LEGACY', 'restarts' => 2]);
});

// --- Legacy v1.0 continuations ---------------------------------------------------------------

it('resumes a v1.0 await_publish continuation by reading the status first', function (string $status) {
    K::fake([
        'GET C1' => [['status' => $status]],
        'GET 1784/threads' => [['data' => []]],
    ]);

    $outcome = K::driver()->resume(K::post(), ['phase' => 'await_publish', 'container' => 'C1']);

    if ($status === 'FINISHED') {
        expect($outcome->commits)->toBeTrue()
            ->and($outcome->state)->toMatchArray(['phase' => 'publish', 'container_id' => 'C1'])
            ->and($outcome->state)->not->toHaveKey('container');
    } else {
        expect($outcome)->toBeInstanceOf(Published::class)
            ->and($outcome->result->payload['container_id'])->toBe('C1');
    }

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'threads_publish'));
})->with(['FINISHED', 'PUBLISHED']);

it('resumes a v1.0 video await continuation keyed by container', function () {
    K::fake(['GET V1' => [['status' => 'IN_PROGRESS']]]);

    $next = K::driver()->resume(K::post([Media::video(VID)]), ['phase' => 'await', 'container' => 'V1']);

    expect($next->state)->toMatchArray(['phase' => 'await', 'container_id' => 'V1', 'kind' => 'video'])
        ->and($next->recheckAfter)->toBe(30);
});

it('resumes a v1.0 await_children continuation and polls every item', function () {
    K::fake([
        'GET K1' => [['status' => 'FINISHED']],
        'GET K2' => [['status' => 'FINISHED']],
        'POST 1784/threads' => [['id' => 'P1']],
    ]);

    $next = K::driver()->resume(K::post([Media::image(IMG_A), Media::video(VID)], 'Legacy'), [
        'phase' => 'await_children',
        'children' => ['K1', 'K2'],
        'pending_videos' => ['K2'],
        'text' => 'Legacy',
    ]);

    expect(K::sent('GET K1'))->toHaveCount(1)
        ->and($next->state)->toMatchArray(['phase' => 'await', 'container_id' => 'P1', 'kind' => 'carousel'])
        ->and(K::sent('POST 1784/threads')[0]->data())->toMatchArray(['media_type' => 'CAROUSEL', 'children' => 'K1,K2', 'text' => 'Legacy']);
});

it('strips job-owned keys from the state it carries forward', function () {
    K::fake(['GET C1' => [['status' => 'IN_PROGRESS']]]);

    $next = K::driver()->resume(K::post(), [
        'phase' => 'await', 'container_id' => 'C1',
        Pending::STARTED_AT => 1, Pending::ATTEMPT => 3, Pending::RESTARTS => 1,
    ]);

    expect(array_filter(array_keys($next->state), fn ($k) => str_starts_with($k, '_')))->toBe([]);
});

// --- Quota ---------------------------------------------------------------------------------

it('reads the publishing quota', function () {
    K::fake(['GET 1784/threads_publishing_limit' => [['data' => [[
        'quota_usage' => 4,
        'config' => ['quota_total' => 250, 'quota_duration' => 86400],
        'reply_quota_usage' => 1,
        'reply_config' => ['quota_total' => 1000, 'quota_duration' => 86400],
    ]]]]]);

    $quota = K::driver()->publishingQuota(K::post());

    expect($quota)->toMatchArray([
        'quota_usage' => 4, 'quota_total' => 250, 'quota_duration' => 86400,
        'reply_quota_usage' => 1, 'reply_quota_total' => 1000, 'reply_quota_duration' => 86400,
    ])->and(K::query(K::sent('GET 1784/threads_publishing_limit')[0]))->toBe(['fields' => 'quota_usage,config,reply_quota_usage,reply_config']);
});

it('maps a refused quota read', function () {
    K::fake(['GET 1784/threads_publishing_limit' => [K::error('token-invalid-190')]]);

    expect(K::catch(fn () => K::driver()->publishingQuota(K::post()))->reason)->toBe(FailureReason::InvalidToken);
});

it('refuses a post up front when the quota pre-flight finds it used up', function () {
    config(['social.api.threads.check_quota' => true]);
    K::fake(['GET 1784/threads_publishing_limit' => [['data' => [['quota_usage' => 250, 'config' => ['quota_total' => 250, 'quota_duration' => 86400]]]]]]);

    $e = K::catch(fn () => K::driver()->publish(K::post()));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::RateLimited)
        ->and($e->context)->toMatchArray([
            'quota_usage' => 250,
            'quota_total' => 250,
            'retry_after_hint' => 3600,
            'retry_at' => '2026-11-18T11:00:00Z',
        ]);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('publishes when the quota pre-flight has room or cannot be read', function (array $route) {
    config(['social.api.threads.check_quota' => true]);
    K::fake(['GET 1784/threads_publishing_limit' => $route, 'POST 1784/threads' => [['id' => 'C1']]]);

    expect(K::driver()->publish(K::post()))->toBeInstanceOf(Pending::class);
})->with([
    'room left' => [[['data' => [['quota_usage' => 3, 'config' => ['quota_total' => 250]]]]]],
    'no data' => [[['data' => []]]],
    'refused' => [[K::error('rate-limit-code-4')]],
]);

it('does not read the quota unless asked', function () {
    K::fake(['POST 1784/threads' => [['id' => 'C1']]]);

    K::driver()->publish(K::post());

    expect(K::routes())->toBe(['POST 1784/threads']);
});
