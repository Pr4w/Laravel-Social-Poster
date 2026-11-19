<?php

/*
 * The YouTube driver run through the real PublishToConnectionJob on a
 * database queue: what the consumer is told (PostPublished, or PostFailed
 * and its possiblyPublished flag) and what the idempotency store keeps when
 * the finalize stage meets an expiring token or a definitive rejection.
 */

namespace SocialPoster\Tests\Feature\Platforms\YouTubeJob;

use ArrayObject;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use RuntimeException;
use SocialPoster\Contracts\AtomicIdempotencyStore;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Contracts\IdempotencyStore;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\CommentFailed;
use SocialPoster\Events\CommentPublished;
use SocialPoster\Events\PostFailed;
use SocialPoster\Events\PostPublished;
use SocialPoster\Jobs\AddFirstCommentJob;
use SocialPoster\Jobs\PublishToConnectionJob;
use SocialPoster\SocialManager;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\SocialPost;

const YT_KEY = 'yt-job-key';
const YT_VIDEO = 'dQw4w9WgXcQ';
const YT_SESSION = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=SESSION-ONE';

/** Hands out the dispatched credentials, or $fresh from the $after-th call on. */
class YouTubeJobResolver implements CredentialsResolver
{
    public int $calls = 0;

    /** @param  array<string, mixed>|null  $fresh */
    public function __construct(private readonly ?array $fresh = null, private readonly int $after = 2) {}

    public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
    {
        $this->calls++;

        return $this->fresh !== null && $this->calls >= $this->after ? new Credentials($this->fresh) : $dispatched;
    }
}

function ytQueue(): void
{
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    config([
        'queue.default' => 'database',
        'queue.connections.database' => [
            'driver' => 'database', 'connection' => null, 'table' => 'jobs',
            'queue' => 'default', 'retry_after' => 90000, 'after_commit' => false,
        ],
    ]);
}

/** Run the queued jobs like a worker, travelling in time to the delayed ones. */
function ytWork(int $max = 50): int
{
    $worker = app('queue.worker');
    $options = new WorkerOptions('default', 0, 512, 0, 0, 0, true);
    $ran = 0;

    while ($ran < $max) {
        $next = DB::table('jobs')->whereNull('reserved_at')->orderBy('available_at')->orderBy('id')->first();

        if ($next === null) {
            break;
        }

        if ($next->available_at > now()->getTimestamp()) {
            Carbon::setTestNow(Carbon::createFromTimestamp($next->available_at));
        }

        $worker->runNextJob('database', 'default,social', $options);
        $ran++;
    }

    return $ran;
}

function ytEvents(): ArrayObject
{
    $log = new ArrayObject;

    foreach ([PostPublished::class, PostFailed::class, CommentPublished::class, CommentFailed::class] as $class) {
        Event::listen($class, function ($event) use ($log) {
            $log[] = $event;
        });
    }

    return $log;
}

/** @return list<object> */
function ytOf(ArrayObject $log, string $class): array
{
    return array_values(array_filter($log->getArrayCopy(), fn ($event) => $event instanceof $class));
}

function ytStatusOf(string $key): ?string
{
    $store = app(IdempotencyStore::class);

    return $store instanceof AtomicIdempotencyStore ? $store->status($key) : null;
}

/** @return array<string, mixed> */
function ytFixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/youtube/'.$name), true);
}

/** @return list<Request> */
function ytSent(string $needle, ?string $method = null): array
{
    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => str_contains($request->url(), $needle) && ($method === null || $request->method() === $method))
        ->values()
        ->all();
}

/** A small local video (the re-post opens a real session for it). */
function ytVideoFile(): string
{
    $path = sys_get_temp_dir().'/sp-yt-job-'.bin2hex(random_bytes(4)).'.mp4';
    file_put_contents($path, str_repeat('V', 4096));

    return $path;
}

/**
 * @param  array<string, mixed>|null  $state
 * @param  array<string, mixed>  $credentials
 */
function ytJob(?array $state, bool $committed, array $credentials = [], ?string $video = null): PublishToConnectionJob
{
    $job = new PublishToConnectionJob(
        Platform::YouTube,
        new SocialPost(media: [Media::video($video ?? ytVideoFile())], caption: 'A description', title: 'My video'),
        new Credentials($credentials + ['access_token' => 'ya29.test-token', 'channel_id' => 'UCuAXFkgsw1L7xaCfnd5JJOw']),
        true,
        $state,
        $state === null ? 0 : 3,
        'corr-yt',
        YT_KEY,
    );
    $job->committed = $committed;

    return $job;
}

/** @return array<string, mixed> */
function ytFinalizeState(): array
{
    return [
        'stage' => 'finalize',
        'video_id' => YT_VIDEO,
        'payload' => ['upload_status' => 'uploaded', 'privacy_status' => 'public'],
        'result_state' => PostResult::STATE_PUBLISHED,
        'await' => true,
        'thumbnail' => false,
        'thumbnail_tries' => 0,
        'playlists' => [],
        'polls' => 0,
        'finalize_started_at' => time(),
        'deadline' => time() + 4200,
    ];
}

beforeEach(function () {
    Sleep::fake();
    ytQueue();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('publishes a live video whose re-resolved token is still expiring in the finalize stage, with a warning', function () {
    $log = ytEvents();
    app()->instance(CredentialsResolver::class, $resolver = new YouTubeJobResolver);
    Http::fake();

    dispatch(ytJob(ytFinalizeState(), true, ['expires_at' => time() - 10]));
    ytWork();

    $published = ytOf($log, PostPublished::class);

    expect(ytOf($log, PostFailed::class))->toBe([])
        ->and($published)->toHaveCount(1)
        ->and($published[0]->result->platformPostId)->toBe(YT_VIDEO)
        ->and($published[0]->result->payload['follow_up_warning'])->toContain('expired')
        ->and($resolver->calls)->toBeGreaterThanOrEqual(3)
        ->and(ytStatusOf(YT_KEY))->toBe(AtomicIdempotencyStore::STATUS_PUBLISHED);

    Http::assertNothingSent();
});

it('finishes the finalize stage with the token a later run re-resolves', function () {
    $log = ytEvents();
    app()->instance(CredentialsResolver::class, new YouTubeJobResolver([
        'access_token' => 'ya29.fresh',
        'channel_id' => 'UCuAXFkgsw1L7xaCfnd5JJOw',
        'expires_at' => time() + 7200,
    ]));
    Http::fake(['https://www.googleapis.com/youtube/v3/videos*' => Http::response(ytFixture('videos_list_succeeded.json'), 200)]);

    dispatch(ytJob(ytFinalizeState(), true, ['expires_at' => time() - 10]));
    ytWork();

    $published = ytOf($log, PostPublished::class);
    $polls = ytSent('/youtube/v3/videos', 'GET');

    expect(ytOf($log, PostFailed::class))->toBe([])
        ->and($published)->toHaveCount(1)
        ->and($published[0]->result->payload)->not->toHaveKey('follow_up_warning')
        ->and($published[0]->result->payload['processing_status'])->toBe('succeeded')
        ->and($polls)->toHaveCount(1)
        ->and($polls[0]->header('Authorization'))->toBe(['Bearer ya29.fresh']);
});

it('reports a video YouTube rejected in the finalize stage as not published, so the same key can post again', function () {
    $log = ytEvents();
    $listed = ytFixture('videos_list_processing.json');
    $listed['items'][0]['status'] = ['uploadStatus' => 'rejected', 'rejectionReason' => 'length', 'privacyStatus' => 'public'];

    Http::fake(['https://www.googleapis.com/youtube/v3/videos*' => Http::response($listed, 200)]);

    dispatch(ytJob(ytFinalizeState(), true));
    ytWork();

    $failed = ytOf($log, PostFailed::class);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->exception->reason)->toBe(FailureReason::MediaRejected)
        ->and($failed[0]->exception->context['video_id'])->toBe(YT_VIDEO)
        ->and($failed[0]->possiblyPublished)->toBeFalse()
        ->and(ytStatusOf(YT_KEY))->not->toBe(AtomicIdempotencyStore::STATUS_FAILED);

    // The user verifies the channel (or trims the video) and posts again with the same key.
    Http::fake([
        'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=*' => Http::response(ytFixture('video_inserted.json'), 201),
        'https://www.googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => YT_SESSION]),
    ]);

    dispatch(ytJob(null, false));
    ytWork();

    $published = ytOf($log, PostPublished::class);

    expect(ytOf($log, PostFailed::class))->toHaveCount(1)
        ->and($published)->toHaveCount(1)
        ->and($published[0]->result->platformPostId)->toBe(YT_VIDEO)
        ->and(ytSent('/upload/youtube/v3/videos', 'POST'))->toHaveCount(1);
});

it('reports a definitive refusal the session replays after a lost final chunk as not published', function () {
    $log = ytEvents();
    $video = ytVideoFile();

    Http::fake([
        'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=*' => Http::response(ytFixture('upload_limit_exceeded.json'), 400),
    ]);

    $state = [
        'stage' => 'upload', 'session' => YT_SESSION, 'size' => 4096, 'mime' => 'video/mp4',
        'offset' => null, 'range_reads' => false, 'restarts' => 0, 'stalls' => 1, 'final_sent' => true,
        'requested_privacy' => 'public', 'upload_started_at' => time(), 'deadline' => time() + 3600,
    ];

    dispatch(ytJob($state, true, [], $video));
    ytWork();

    $failed = ytOf($log, PostFailed::class);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->exception->reason)->toBe(FailureReason::RateLimited)
        ->and($failed[0]->exception->context['upload_limit'])->toBeTrue()
        ->and($failed[0]->possiblyPublished)->toBeFalse()
        ->and(ytStatusOf(YT_KEY))->not->toBe(AtomicIdempotencyStore::STATUS_FAILED);
});

it('keeps the idempotency key of a session that expired after the final chunk may have arrived', function () {
    $log = ytEvents();

    Http::fake([
        'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=*' => Http::response('Not Found', 404),
        'https://www.googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => YT_SESSION]),
    ]);

    $state = [
        'stage' => 'upload', 'session' => YT_SESSION, 'size' => 4096, 'mime' => 'video/mp4',
        'offset' => null, 'range_reads' => false, 'restarts' => 0, 'stalls' => 3, 'final_sent' => true,
        'requested_privacy' => 'public', 'upload_started_at' => time(), 'deadline' => time() + 3600,
    ];

    dispatch(ytJob($state, true));
    ytWork();

    $failed = ytOf($log, PostFailed::class);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->exception->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($failed[0]->possiblyPublished)->toBeTrue()
        ->and(ytSent('/upload/youtube/v3/videos', 'POST'))->toBe([])
        ->and(ytStatusOf(YT_KEY))->toBe(AtomicIdempotencyStore::STATUS_FAILED);
});

it('finishes an upload spread over several job runs from the copy publish() kept, after the signed URL expired', function () {
    $log = ytEvents();
    $dir = sys_get_temp_dir().'/sp-yt-job-tmp-'.bin2hex(random_bytes(4));
    $chunk = 262144;
    $content = str_repeat('A', $chunk).str_repeat('B', $chunk);
    $reads = 0;
    $puts = 0;

    config([
        'social.temp_dir' => $dir,
        'social.api.youtube.range_reads' => false,
        'social.api.youtube.chunk_bytes' => $chunk,
    ]);

    Http::fake(function (Request $request) use ($content, $chunk, &$reads, &$puts) {
        $url = $request->url();

        if (str_contains($url, 'cdn.example.com')) {
            // Valid for the first download only, like an S3 presigned URL that
            // expires (the inspector's HEAD is refused too).
            $download = $request->method() === 'GET' && ++$reads === 1;

            return $download ? Http::response($content, 200) : Http::response('<Error><Code>AccessDenied</Code></Error>', 403);
        }

        if ($request->method() === 'POST' && str_contains($url, '/upload/youtube/v3/videos')) {
            return Http::response('', 200, ['Location' => YT_SESSION]);
        }

        if ($request->method() === 'PUT' && str_contains($url, 'upload_id=')) {
            return match (++$puts) {
                // The first run stalls, so the rest is sent by a later job run.
                1 => Http::response(ytFixture('backend_error.json'), 503),
                2 => Http::response('', 308),
                3 => Http::response('', 308, ['Range' => 'bytes=0-'.($chunk - 1)]),
                default => Http::response(ytFixture('video_inserted.json'), 201),
            };
        }

        throw new RuntimeException("Unexpected request: {$request->method()} {$url}");
    });

    try {
        dispatch(ytJob(null, false, [], 'https://cdn.example.com/videos/talk.mp4?X-Amz-Expires=3600&X-Amz-Signature=abc'));
        $runs = ytWork();

        $published = ytOf($log, PostPublished::class);
        $chunks = ytSent('upload_id=', 'PUT');

        expect(ytOf($log, PostFailed::class))->toBe([])
            ->and($published)->toHaveCount(1)
            ->and($published[0]->result->platformPostId)->toBe(YT_VIDEO)
            ->and($runs)->toBeGreaterThanOrEqual(3)
            ->and($reads)->toBe(1)
            ->and($chunks)->toHaveCount(4)
            ->and($chunks[3]->body())->toBe(str_repeat('B', $chunk))
            ->and(glob($dir.'/sp_*'))->toBe([]);
    } finally {
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }
});

// --- A run the queue killed ------------------------------------------------------------------

/** @return array<string, mixed> */
function ytUploadState(): array
{
    return [
        'stage' => 'upload', 'session' => YT_SESSION, 'size' => 4096, 'mime' => 'video/mp4',
        'offset' => 0, 'range_reads' => false, 'restarts' => 0, 'stalls' => 0,
        'requested_privacy' => 'public', 'upload_started_at' => time(), 'deadline' => time() + 3600,
    ];
}

it('runs an upload run the queue killed again, which finds the video its last chunk created', function () {
    $log = ytEvents();

    Http::fake(function (Request $request) {
        if ($request->method() === 'POST' && str_contains($request->url(), '/upload/youtube/v3/videos')) {
            return Http::response('', 200, ['Location' => YT_SESSION]);
        }

        if ($request->method() === 'PUT' && $request->header('Content-Range')[0] === 'bytes */4096') {
            // The killed run's last chunk arrived: the session replays the video.
            return Http::response(ytFixture('video_inserted.json'), 201);
        }

        throw new RuntimeException("Unexpected request: {$request->method()} {$request->url()}");
    });

    dispatch(ytJob(null, false));
    $first = json_decode((string) DB::table('jobs')->value('payload'), true);

    // publish() opens the session and queues the first upload run.
    ytWork(1);
    $upload = DB::table('jobs')->first();

    expect($first['failOnTimeout'])->toBeTrue()
        ->and(json_decode((string) $upload->payload, true)['failOnTimeout'])->toBeFalse();

    // That run is killed while it sends the last chunk: the worker had taken
    // it once, and the queue hands it out again once retry_after passed.
    DB::table('jobs')->where('id', $upload->id)->update(['attempts' => 1]);
    ytWork();

    $published = ytOf($log, PostPublished::class);

    expect(ytOf($log, PostFailed::class))->toBe([])
        ->and($published)->toHaveCount(1)
        ->and($published[0]->result->platformPostId)->toBe(YT_VIDEO)
        ->and(ytSent('upload_id=', 'PUT'))->toHaveCount(1)
        ->and(ytStatusOf(YT_KEY))->toBe(AtomicIdempotencyStore::STATUS_PUBLISHED);
});

it('reports an upload run the queue gave up on after kills as possibly published, and keeps the key', function () {
    $log = ytEvents();

    $job = ytJob(ytUploadState(), false);
    $job->failOnTimeout = false;
    $job->failed(new TimeoutExceededException('PublishToConnectionJob has timed out.'));

    $failed = ytOf($log, PostFailed::class);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->exception->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($failed[0]->possiblyPublished)->toBeTrue()
        ->and($failed[0]->exception->getMessage())->toContain('may have sent its last part')
        ->and(ytStatusOf(YT_KEY))->toBe(AtomicIdempotencyStore::STATUS_FAILED);
});

it('still reports a killed upload run queued before runs were retried as a plain timeout', function () {
    $log = ytEvents();

    ytJob(ytUploadState(), false)->failed(new TimeoutExceededException('PublishToConnectionJob has timed out.'));

    $failed = ytOf($log, PostFailed::class);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->exception->reason)->toBe(FailureReason::Timeout)
        ->and($failed[0]->possiblyPublished)->toBeFalse();
});

it('queues only the upload stage to run again after a kill', function () {
    $driver = app(SocialManager::class)->driver('youtube');

    expect($driver->retriesKilledRun(ytUploadState()))->toBeTrue()
        ->and($driver->retriesKilledRun(ytFinalizeState()))->toBeFalse()
        ->and($driver->retriesKilledRun([]))->toBeFalse();
});

// --- First comment recovery ------------------------------------------------------------------

/** A first-comment job for YT_VIDEO, as the publish job queues it. */
function ytCommentJob(string $text = 'First!'): AddFirstCommentJob
{
    return new AddFirstCommentJob(
        Platform::YouTube,
        YT_VIDEO,
        $text,
        new Credentials(['access_token' => 'ya29.test-token', 'channel_id' => 'UCuAXFkgsw1L7xaCfnd5JJOw']),
        [],
        new SocialPost(media: [Media::video('https://cdn.example.com/v.mp4')], caption: 'A description', title: 'My video'),
        PostResult::success(Platform::YouTube, YT_VIDEO, 'https://www.youtube.com/watch?v='.YT_VIDEO, ['privacy_status' => 'public'], PostResult::STATE_PUBLISHED),
        'yt-comment-key',
        'corr-yt',
    );
}

/**
 * A commentThreads.list answer holding one top-level comment.
 *
 * @return array<string, mixed>
 */
function ytCommentList(string $id, string $author, string $text): array
{
    return [
        'kind' => 'youtube#commentThreadListResponse',
        'items' => [[
            'kind' => 'youtube#commentThread',
            'id' => $id,
            'snippet' => [
                'channelId' => 'UCuAXFkgsw1L7xaCfnd5JJOw',
                'videoId' => YT_VIDEO,
                'topLevelComment' => [
                    'kind' => 'youtube#comment',
                    'id' => $id,
                    'snippet' => [
                        'authorChannelId' => ['value' => $author],
                        'videoId' => YT_VIDEO,
                        'textDisplay' => $text,
                        'textOriginal' => $text,
                    ],
                ],
            ],
        ]],
    ];
}

it('finds the comment an attempt that lost its answer added, instead of commenting twice', function (mixed $lost) {
    $log = ytEvents();
    $inserts = 0;

    Http::fake(function (Request $request) use (&$inserts, $lost) {
        if ($request->method() === 'POST' && str_contains($request->url(), '/commentThreads')) {
            // The comment is created, but the answer never reaches the worker.
            return ++$inserts === 1 ? $lost($request) : Http::response(['id' => 'UgzSecond'], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/commentThreads')) {
            return Http::response(ytCommentList('UgzFirst', 'UCuAXFkgsw1L7xaCfnd5JJOw', 'First!'), 200);
        }

        throw new RuntimeException("Unexpected request: {$request->method()} {$request->url()}");
    });

    dispatch(ytCommentJob());
    ytWork();

    $published = ytOf($log, CommentPublished::class);
    $lists = ytSent('/commentThreads', 'GET');
    parse_str((string) parse_url($lists[0]->url(), PHP_URL_QUERY), $query);

    expect($inserts)->toBe(1)
        ->and(ytOf($log, CommentFailed::class))->toBe([])
        ->and($published)->toHaveCount(1)
        ->and($published[0]->commentId)->toBe('UgzFirst')
        ->and($published[0]->url)->toBe('https://www.youtube.com/watch?v='.YT_VIDEO.'&lc=UgzFirst')
        ->and($published[0]->payload)->toMatchArray(['recovered' => true, 'thread_id' => 'UgzFirst', 'video_id' => YT_VIDEO])
        ->and($lists)->toHaveCount(1)
        ->and($query)->toMatchArray(['part' => 'snippet', 'videoId' => YT_VIDEO, 'order' => 'time', 'textFormat' => 'plainText']);
})->with([
    '5xx' => [fn () => fn (Request $request) => Http::response(ytFixture('backend_error.json'), 503)],
    'connection dropped after sending' => [fn () => fn (Request $request) => Create::rejectionFor(
        new ConnectException('cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received', $request->toPsrRequest()),
    )],
]);

it('comments again in a recovery when the earlier attempt left no comment', function () {
    $log = ytEvents();
    $inserts = 0;

    Http::fake(function (Request $request) use (&$inserts) {
        if ($request->method() === 'POST' && str_contains($request->url(), '/commentThreads')) {
            return ++$inserts === 1 ? Http::response(ytFixture('backend_error.json'), 503) : Http::response(['id' => 'UgzSecond'], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/commentThreads')) {
            // Another viewer's identical comment, and ours with other text: neither is this comment.
            $list = ytCommentList('UgzOther', 'UCsomeoneElse', 'First!');
            $list['items'][] = ytCommentList('UgzOlder', 'UCuAXFkgsw1L7xaCfnd5JJOw', 'Another comment')['items'][0];

            return Http::response($list, 200);
        }

        throw new RuntimeException("Unexpected request: {$request->method()} {$request->url()}");
    });

    dispatch(ytCommentJob());
    ytWork();

    $published = ytOf($log, CommentPublished::class);

    expect($inserts)->toBe(2)
        ->and($published)->toHaveCount(1)
        ->and($published[0]->commentId)->toBe('UgzSecond')
        ->and($published[0]->payload)->not->toHaveKey('recovered');
});
