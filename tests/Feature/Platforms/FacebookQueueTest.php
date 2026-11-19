<?php

namespace SocialPoster\Tests\Feature\Platforms;

use const SocialPoster\Tests\Fixtures\Facebook\FB_PAGE;
use const SocialPoster\Tests\Fixtures\Facebook\FB_TOKEN;

use ArrayObject;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\CommentFailed;
use SocialPoster\Events\CommentPublished;
use SocialPoster\Events\PostFailed;
use SocialPoster\Events\PostPublished;
use SocialPoster\Jobs\AddFirstCommentJob;
use SocialPoster\Jobs\PublishToConnectionJob;
use SocialPoster\Tests\Fixtures\Facebook\FbFakeInspector;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\SocialPost;

use function SocialPoster\Tests\Fixtures\Facebook\fbConnectionFailure;
use function SocialPoster\Tests\Fixtures\Facebook\fbFake;
use function SocialPoster\Tests\Fixtures\Facebook\fbFixture;
use function SocialPoster\Tests\Fixtures\Facebook\fbStatus;

require_once __DIR__.'/../../Fixtures/facebook/helpers.php';

/*
 * The Facebook driver driven by the real jobs on a database queue, for the
 * failure paths whose outcome depends on how the job retries and reports.
 */

beforeEach(function () {
    config(['social.api.facebook.graph_version' => 'v25.0']);
    Sleep::fake();

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
});

afterEach(fn () => Carbon::setTestNow());

/** Run the queued jobs in order, jumping the clock to each delayed one. */
function fbqRun(int $max = 80): int
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

/** @return ArrayObject<int, object> */
function fbqEvents(): ArrayObject
{
    $log = new ArrayObject;

    foreach ([PostPublished::class, PostFailed::class, CommentPublished::class, CommentFailed::class] as $class) {
        Event::listen($class, function ($event) use ($log) {
            $log[] = $event;
        });
    }

    return $log;
}

/**
 * @param  ArrayObject<int, object>  $log
 * @return list<object>
 */
function fbqOf(ArrayObject $log, string $class): array
{
    return array_values(array_filter($log->getArrayCopy(), fn ($event) => $event instanceof $class));
}

function fbqCredentials(): Credentials
{
    return new Credentials(['account_id' => FB_PAGE, 'page_access_token' => FB_TOKEN]);
}

it('adds the first comment once when the comment POST times out after Facebook created it', function () {
    $log = fbqEvents();
    $url = 'https://graph.facebook.com/v25.0/'.FB_PAGE.'_555/comments';

    fbFake([
        'GET *v25.0/'.FB_PAGE.'_555/comments*' => Http::sequence()
            ->push(['data' => []])
            ->push(['data' => [['id' => '555_901', 'message' => 'First!', 'from' => ['id' => FB_PAGE]]]]),
        'POST *v25.0/'.FB_PAGE.'_555/comments' => Http::sequence()
            ->pushResponse(fbConnectionFailure()(new Request(new PsrRequest('POST', $url))))
            ->push(['id' => '555_999']),
        'GET *fields=permalink_url*' => Http::response(['permalink_url' => 'https://www.facebook.com/c']),
    ]);

    dispatch(new AddFirstCommentJob(
        Platform::Facebook,
        FB_PAGE.'_555',
        'First!',
        fbqCredentials(),
        [],
        new SocialPost(caption: 'Hello', comment: 'First!'),
        PostResult::success(Platform::Facebook, FB_PAGE.'_555'),
        'fb-queue-comment',
    ));
    fbqRun();

    $published = fbqOf($log, CommentPublished::class);

    expect(Http::recorded(fn (Request $request) => $request->method() === 'POST'))->toHaveCount(1)
        ->and($published)->toHaveCount(1)
        ->and($published[0]->commentId)->toBe('555_901')
        ->and(fbqOf($log, CommentFailed::class))->toBe([]);
});

it('reports a /feed post as possibly published when the re-create after a timeout is refused as a duplicate', function () {
    $log = fbqEvents();
    $url = 'https://graph.facebook.com/v25.0/'.FB_PAGE.'/feed';

    fbFake([
        'POST *v25.0/'.FB_PAGE.'/feed' => Http::sequence()
            ->pushResponse(fbConnectionFailure()(new Request(new PsrRequest('POST', $url))))
            ->push(fbFixture('duplicate_post'), 400),
        'GET *v25.0/'.FB_PAGE.'/published_posts*' => Http::response(['data' => []]),
    ]);

    dispatch(new PublishToConnectionJob(Platform::Facebook, new SocialPost(caption: 'Hello Facebook'), fbqCredentials(), true, null, 0, 'corr', 'fb-queue-506'));
    fbqRun();

    $failed = fbqOf($log, PostFailed::class);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->possiblyPublished)->toBeTrue()
        ->and($failed[0]->exception->reason)->toBe(FailureReason::DuplicateContent)
        ->and(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/feed')))->toHaveCount(2);
});

it('never reports a Reel Facebook accepted as failed when a throttled status read outlasts the poll', function () {
    $log = fbqEvents();
    app()->instance(MediaInspector::class, new FbFakeInspector([
        'https://cdn.example.com/vertical.mp4' => new MediaMetadata(width: 1080, height: 1920, durationSeconds: 30, frameRate: 30),
    ]));

    fbFake([
        'POST *v25.0/'.FB_PAGE.'/video_reels' => fn (Request $request) => ($request['upload_phase'] ?? null) === 'start'
            ? Http::response(['video_id' => '7788', 'upload_url' => 'https://rupload.facebook.com/video-upload/v25.0/7788'])
            : Http::response(['success' => true, 'post_id' => '9911']),
        'POST https://rupload.facebook.com/video-upload/v25.0/7788' => Http::response(['success' => true]),
        'GET *v25.0/7788?fields=status*' => Http::sequence()
            ->push(fbStatus('upload_complete', 'complete', 'not_started'))
            ->push(fbFixture('throttle_page'), 400)
            ->push(fbStatus('processing', 'complete', 'in_progress')),
        'GET *fields=permalink_url*' => Http::response(['permalink_url' => 'https://www.facebook.com/reel/9911']),
    ]);

    dispatch(new PublishToConnectionJob(Platform::Facebook, new SocialPost(media: [Media::video('https://cdn.example.com/vertical.mp4')], caption: 'Reel'), fbqCredentials(), true, null, 0, 'corr', 'fb-queue-reel'));
    fbqRun();

    $published = fbqOf($log, PostPublished::class);

    expect(fbqOf($log, PostFailed::class))->toBe([])
        ->and($published)->toHaveCount(1)
        ->and($published[0]->result->platformPostId)->toBe(FB_PAGE.'_9911')
        ->and($published[0]->result->state)->toBe(PostResult::STATE_PROCESSING);
});
