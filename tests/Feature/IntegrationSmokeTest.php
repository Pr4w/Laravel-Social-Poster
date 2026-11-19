<?php

namespace SocialPoster\Tests\Feature\IntegrationSmoke;

use ArrayObject;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Enums\MediaType;
use SocialPoster\Events\PostFailed;
use SocialPoster\Events\PostPublished;
use SocialPoster\Events\PostQueued;
use SocialPoster\Facades\SocialPoster;
use SocialPoster\PostBuilder;
use SocialPoster\SocialManager;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\PostResult;

/*
 * End-to-end smoke test of every first-party driver through the public API:
 * SocialPoster::on(<platform>)->using([...])->...->postNow() and ->post() on
 * the 'sync' queue (a real PublishToConnectionJob, serialised, encrypted and
 * run with its continuations), against faked HTTP. Each platform gets the
 * simplest post it accepts: text where it can, one image or one small video
 * where it must. Driver details are covered by tests/Feature/Platforms; this
 * file proves the pieces (provider, manager, builder, job, store, driver,
 * events) still fit together.
 */

/** Plausible metadata for any media, so validation never reaches ffprobe or the network. */
class SmokeInspector implements MediaInspector
{
    public function supports(Media $media): bool
    {
        return true;
    }

    public function inspect(Media $media): MediaMetadata
    {
        return $media->type === MediaType::Video
            ? new MediaMetadata(4096, 1920, 1080, 12.0, 'video/mp4', 'h264', 2_000_000, 30.0, 0)
            : new MediaMetadata(250_000, 1080, 1080, null, 'image/jpeg');
    }
}

/** "METHOD host/path" of a request, without the query string. */
function smokeKey(Request $request): string
{
    $parts = parse_url($request->url());

    return $request->method().' '.($parts['host'] ?? '').($parts['path'] ?? '');
}

/**
 * Route faked requests by "METHOD host/path" (no query string). A route is a
 * list of responses served in order (the last one repeats). Any other request
 * is a stray request and fails the test with its URL.
 *
 * @param  array<string, list<mixed>>  $routes
 */
function smokeFake(array $routes): void
{
    $served = [];

    Http::fake(function (Request $request) use ($routes, &$served) {
        $key = smokeKey($request);

        if (! array_key_exists($key, $routes)) {
            return null;
        }

        $list = $routes[$key];
        $index = min($served[$key] ?? 0, count($list) - 1);
        $served[$key] = ($served[$key] ?? 0) + 1;
        $response = $list[$index];

        return $response instanceof Closure ? $response($request) : $response;
    });
}

/** A local video file of a few kilobytes, removed after the test. */
function smokeVideo(): string
{
    $dir = config('social.temp_dir');
    $path = $dir.'/smoke_'.bin2hex(random_bytes(4)).'.mp4';
    file_put_contents($path, str_repeat("\x00\x00\x00\x18ftypmp42", 256));

    return $path;
}

/**
 * The simplest valid post per platform: credentials, content, HTTP routes and
 * the id the platform answers with.
 *
 * @return array{credentials: array<string, string>, caption: ?string, title: ?string, media: list<Media>, routes: array<string, list<mixed>>, id: string, state: string}
 */
function smokeCase(string $platform): array
{
    return match ($platform) {
        'facebook' => [
            'credentials' => ['account_id' => '1029384756', 'page_access_token' => 'EAAPageToken123'],
            'caption' => 'Hello from the smoke test',
            'title' => null,
            'media' => [],
            'routes' => [
                'POST graph.facebook.com/v25.0/1029384756/feed' => [Http::response(['id' => '1029384756_555'])],
                'GET graph.facebook.com/v25.0/1029384756_555' => [Http::response(['id' => '1029384756_555', 'permalink_url' => 'https://www.facebook.com/1029384756/posts/555'])],
            ],
            'id' => '1029384756_555',
            'state' => PostResult::STATE_PUBLISHED,
        ],
        'instagram' => [
            'credentials' => ['account_id' => '17841400000000001', 'access_token' => 'EAAGtoken123'],
            'caption' => 'Hello from the smoke test',
            'title' => null,
            'media' => [Media::image('https://cdn.example.com/photo.jpg')],
            'routes' => [
                'POST graph.facebook.com/v25.0/17841400000000001/media' => [Http::response(['id' => 'c1'])],
                'POST graph.facebook.com/v25.0/17841400000000001/media_publish' => [Http::response(['id' => 'm1'])],
                'GET graph.facebook.com/v25.0/m1' => [Http::response(['id' => 'm1', 'permalink' => 'https://www.instagram.com/p/ABC/'])],
            ],
            'id' => 'm1',
            'state' => PostResult::STATE_PUBLISHED,
        ],
        'threads' => [
            'credentials' => ['account_id' => '1784', 'access_token' => 'THAAtok123'],
            'caption' => 'Hello from the smoke test',
            'title' => null,
            'media' => [],
            'routes' => [
                'POST graph.threads.net/v1.0/1784/threads' => [Http::response(['id' => 'C1'])],
                'GET graph.threads.net/v1.0/C1' => [Http::response(['id' => 'C1', 'status' => 'FINISHED'])],
                'POST graph.threads.net/v1.0/1784/threads_publish' => [Http::response(['id' => '17890001'])],
                'GET graph.threads.net/v1.0/17890001' => [Http::response(['id' => '17890001', 'permalink' => 'https://www.threads.net/@acme/post/DAbc123'])],
            ],
            'id' => '17890001',
            'state' => PostResult::STATE_PUBLISHED,
        ],
        'x' => [
            'credentials' => ['access_token' => 'x-token', 'user_id' => '1450000000000000000'],
            'caption' => 'Hello from the smoke test',
            'title' => null,
            'media' => [],
            'routes' => [
                'POST api.x.com/2/tweets' => [Http::response(['data' => ['id' => '1880028106020515840', 'text' => 'Hello from the smoke test']], 201)],
            ],
            'id' => '1880028106020515840',
            'state' => PostResult::STATE_PUBLISHED,
        ],
        'linkedin' => [
            'credentials' => ['access_token' => 'li-test-token', 'author' => 'urn:li:person:8675309Ab_c'],
            'caption' => 'Hello from the smoke test',
            'title' => null,
            'media' => [],
            'routes' => [
                'POST api.linkedin.com/rest/posts' => [Http::response('', 201, ['x-restli-id' => 'urn:li:share:7012345678901234567'])],
            ],
            'id' => 'urn:li:share:7012345678901234567',
            'state' => PostResult::STATE_PUBLISHED,
        ],
        'tiktok' => [
            'credentials' => ['access_token' => 'tt-token', 'open_id' => 'open-123'],
            'caption' => 'Hello from the smoke test',
            'title' => null,
            'media' => [Media::image('https://cdn.example.com/photo.jpg')],
            'routes' => [
                'POST open.tiktokapis.com/v2/post/publish/content/init/' => [Http::response([
                    'data' => ['publish_id' => 'p_pub_url~v2.7301234567890123456'],
                    'error' => ['code' => 'ok', 'message' => '', 'log_id' => '202611180932310102451361041207F2F3'],
                ])],
                'POST open.tiktokapis.com/v2/post/publish/status/fetch/' => [Http::response([
                    'data' => ['status' => 'SEND_TO_USER_INBOX', 'uploaded_bytes' => 0, 'downloaded_bytes' => 250000],
                    'error' => ['code' => 'ok', 'message' => '', 'log_id' => '202611180932310102451361041207F2F5'],
                ])],
            ],
            'id' => 'p_pub_url~v2.7301234567890123456',
            'state' => PostResult::STATE_DRAFT,
        ],
        'youtube' => [
            'credentials' => ['access_token' => 'ya29.test-token', 'channel_id' => 'UCuAXFkgsw1L7xaCfnd5JJOw'],
            'caption' => 'A description',
            'title' => 'Smoke test',
            'media' => [Media::video(smokeVideo())],
            'routes' => [
                'POST www.googleapis.com/upload/youtube/v3/videos' => [Http::response('', 200, [
                    'Location' => 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=SMOKE',
                ])],
                'PUT www.googleapis.com/upload/youtube/v3/videos' => [Http::response([
                    'kind' => 'youtube#video',
                    'id' => 'dQw4w9WgXcQ',
                    'snippet' => ['channelId' => 'UCuAXFkgsw1L7xaCfnd5JJOw', 'title' => 'Smoke test'],
                    'status' => ['uploadStatus' => 'uploaded', 'privacyStatus' => 'public'],
                ], 201)],
            ],
            'id' => 'dQw4w9WgXcQ',
            'state' => PostResult::STATE_PUBLISHED,
        ],
        'bluesky' => [
            'credentials' => ['did' => 'did:plc:4vx5zk2wrb7qmaz3rdc6yjfo', 'pds' => 'https://morel.us-east.host.bsky.network', 'access_jwt' => 'session-jwt'],
            'caption' => 'Hello from the smoke test',
            'title' => null,
            'media' => [],
            'routes' => [
                'POST morel.us-east.host.bsky.network/xrpc/com.atproto.repo.createRecord' => [fn (Request $request) => Http::response([
                    'uri' => 'at://'.$request['repo'].'/'.$request['collection'].'/'.$request['rkey'],
                    'cid' => 'bafyreie5737gdxlw5i64vzichcalba3z2v5n6icifvx5xytvske7mr3hpm',
                    'commit' => ['cid' => 'bafyreihj7ivxh7ziaqzbbvy4p3zcm4hcfofjv6g6jhqgnzf5t3hhcrvyq4', 'rev' => '3m5kqzw7zdc2a'],
                    'validationStatus' => 'valid',
                ])],
            ],
            'id' => 'at://did:plc:4vx5zk2wrb7qmaz3rdc6yjfo/app.bsky.feed.post/',
            'state' => PostResult::STATE_PUBLISHED,
        ],
        'pinterest' => [
            'credentials' => ['access_token' => 'pina_AMAtok123', 'board_id' => '987654321012345678'],
            'caption' => 'Hello from the smoke test',
            'title' => 'Smoke test',
            'media' => [Media::image('https://cdn.example.com/photo.jpg')],
            'routes' => [
                'POST api.pinterest.com/v5/pins' => [Http::response(['id' => '813744226420795884', 'board_id' => '987654321012345678', 'created_at' => '2026-11-18T10:00:02'], 201)],
            ],
            'id' => '813744226420795884',
            'state' => PostResult::STATE_PUBLISHED,
        ],
        default => throw new RuntimeException("No smoke case for [{$platform}]."),
    };
}

/** Builder for the case, through the facade as an application would write it. */
function smokeBuilder(string $platform, array $case): PostBuilder
{
    $builder = SocialPoster::on($platform)->using($case['credentials'])->caption($case['caption']);

    if ($case['title'] !== null) {
        $builder->title($case['title']);
    }

    return $case['media'] === [] ? $builder : $builder->media(...$case['media']);
}

/** Every faked route was called: the platform's whole happy path ran. */
function expectEveryRouteCalled(array $case): void
{
    $called = Http::recorded()->map(fn (array $pair) => smokeKey($pair[0]))->unique()->values()->all();

    expect($called)->toEqualCanonicalizing(array_keys($case['routes']));
}

function expectSmokeId(string $platform, ?string $id, array $case): void
{
    // Bluesky ids end in a time-based record key.
    $platform === 'bluesky'
        ? expect($id)->toStartWith($case['id'])
        : expect($id)->toBe($case['id']);
}

dataset('platforms', ['facebook', 'instagram', 'threads', 'x', 'linkedin', 'tiktok', 'youtube', 'bluesky', 'pinterest']);

beforeEach(function () {
    Sleep::fake();

    $this->tmp = sys_get_temp_dir().'/sp-smoke-'.bin2hex(random_bytes(6));
    mkdir($this->tmp, 0777, true);
    config(['social.temp_dir' => $this->tmp]);

    app()->instance(MediaInspector::class, new SmokeInspector);

    $this->events = new ArrayObject;
    Event::listen([PostQueued::class, PostPublished::class, PostFailed::class], fn ($event) => $this->events[] = $event);
});

afterEach(function () {
    foreach (glob($this->tmp.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->tmp);
});

it('registers every first-party platform the smoke test covers', function () {
    expect(app(SocialManager::class)->registered())
        ->toEqualCanonicalizing(['facebook', 'instagram', 'threads', 'x', 'linkedin', 'tiktok', 'youtube', 'bluesky', 'pinterest']);
});

it('publishes the simplest post through postNow()', function (string $platform) {
    $case = smokeCase($platform);
    smokeFake($case['routes']);

    $results = smokeBuilder($platform, $case)->postNow();
    $result = $results[$platform] ?? null;

    $failures = array_values(array_filter((array) $this->events->getArrayCopy(), fn ($e) => $e instanceof PostFailed));

    expect($failures)->toBe([])
        ->and(array_keys($results))->toBe([$platform])
        ->and($result)->toBeInstanceOf(PostResult::class)
        ->and($result->success)->toBeTrue()
        ->and($result->state)->toBe($case['state'])
        ->and($result->platform->value)->toBe($platform);

    expectSmokeId($platform, $result->platformPostId, $case);
    expectEveryRouteCalled($case);

    $published = array_values(array_filter((array) $this->events->getArrayCopy(), fn ($e) => $e instanceof PostPublished));

    expect($published)->toHaveCount(1)
        ->and($published[0]->result->platformPostId)->toBe($result->platformPostId);
})->with('platforms');

it('publishes the simplest post through the queue on the sync connection', function (string $platform) {
    config(['queue.default' => 'sync', 'social.connection' => null]);

    $case = smokeCase($platform);
    smokeFake($case['routes']);

    $queued = smokeBuilder($platform, $case)->post();

    $events = (array) $this->events->getArrayCopy();
    $failures = array_values(array_filter($events, fn ($e) => $e instanceof PostFailed));
    $published = array_values(array_filter($events, fn ($e) => $e instanceof PostPublished));

    expect($failures)->toBe([])
        ->and($queued->queued($platform))->toBeTrue()
        ->and($queued->hasFailures())->toBeFalse()
        ->and(array_values(array_filter($events, fn ($e) => $e instanceof PostQueued)))->toHaveCount(1)
        ->and($published)->toHaveCount(1)
        ->and($published[0]->result->platform->value)->toBe($platform)
        ->and($published[0]->result->state)->toBe($case['state'])
        ->and($published[0]->idempotencyKey)->toBe($queued->key($platform))
        ->and($published[0]->correlationId)->toBe($queued->correlationId);

    expectSmokeId($platform, $published[0]->result->platformPostId, $case);
    expectEveryRouteCalled($case);

    // The job recorded the publish: a second identical dispatch replays it.
    expect(SocialPoster::status($queued->key($platform)))->toBe('published')
        ->and(SocialPoster::outcome($queued->key($platform))?->platformPostId)->toBe($published[0]->result->platformPostId);
})->with('platforms');
