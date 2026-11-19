<?php

namespace SocialPoster\Tests\Feature\Platforms\XRecovery;

use ArrayObject;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\PostFailed;
use SocialPoster\Events\PostPublished;
use SocialPoster\Events\PostRetrying;
use SocialPoster\Jobs\PublishToConnectionJob;
use SocialPoster\Tests\Fixtures\x\XFixtures;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\SocialPost;

/*
 * The X commit protocol end to end: a real PublishToConnectionJob on a
 * database queue, run like a worker, with the real XDriver. An ambiguous
 * POST /2/tweets (connection lost after sending) must come back as a
 * `_recovering` run of the 'tweet' phase, and the driver must then either
 * prove the earlier attempt landed or refuse to post blind.
 */

/** Run queued jobs like a worker, travelling in time to delayed ones. */
function runQueue(int $max = 20): void
{
    $worker = app('queue.worker');
    $options = new WorkerOptions('default', 0, 512, 0, 0, 0, true);

    for ($ran = 0; $ran < $max; $ran++) {
        $next = DB::table('jobs')->whereNull('reserved_at')->orderBy('available_at')->orderBy('id')->first();

        if ($next === null) {
            return;
        }

        if ($next->available_at > now()->getTimestamp()) {
            Carbon::setTestNow(Carbon::createFromTimestamp($next->available_at));
        }

        $worker->runNextJob('database', 'default,social', $options);
    }
}

/** @return list<object> */
function eventsOf(ArrayObject $log, string $class): array
{
    return array_values(array_filter($log->getArrayCopy(), fn ($event) => $event instanceof $class));
}

/**
 * Fake the X API: POST /2/tweets loses its connection after sending on the
 * first call, then answers with $then. Counts the creates in $creates.
 *
 * @param  array<string, mixed>  $routes
 */
function fakeX(ArrayObject $creates, mixed $then, array $routes = []): void
{
    Http::fake(XFixtures::routes($routes + [
        'api.x.com/2/tweets' => function (Request $request) use ($creates, $then) {
            $creates[] = $request->data();

            return count($creates) === 1 ? (XFixtures::connectionFailure())($request) : $then;
        },
    ]));
}

/** @param  list<Media>  $media */
function dispatchX(?string $caption, array $media = []): void
{
    dispatch(new PublishToConnectionJob(
        Platform::X,
        new SocialPost(media: $media, caption: $caption),
        Credentials::make(['access_token' => 'x-token', 'user_id' => '1']),
        true,
        null,
        0,
        'corr-x',
        'key-x',
    ));
}

beforeEach(function () {
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

    $this->events = new ArrayObject;
    Event::listen([PostPublished::class, PostFailed::class, PostRetrying::class], fn ($event) => $this->events[] = $event);
    $this->creates = new ArrayObject;
});

afterEach(function () {
    Carbon::setTestNow();
    XFixtures::cleanup();
});

it('recovers a post with text whose create lost its connection: the re-send is refused as a duplicate', function () {
    fakeX($this->creates, Http::response(XFixtures::json('error_duplicate'), 403));

    dispatchX('Hello from the package');
    runQueue();

    $published = eventsOf($this->events, PostPublished::class);

    expect(eventsOf($this->events, PostFailed::class))->toBe([])
        ->and(eventsOf($this->events, PostRetrying::class))->toHaveCount(1)
        ->and($this->creates)->toHaveCount(2)
        ->and($published)->toHaveCount(1)
        ->and($published[0]->result->platformPostId)->toBeNull()
        ->and($published[0]->result->payload)->toMatchArray(['recovered' => true, 'possibly_duplicate' => true]);
});

it('never re-sends a text-less post whose create lost its connection', function () {
    config(['social.api.x.require_text' => false]);
    fakeX($this->creates, Http::response(XFixtures::json('tweet_created'), 201));

    dispatchX(null, [Media::image(XFixtures::png())]);
    runQueue();

    $failed = eventsOf($this->events, PostFailed::class);

    expect(eventsOf($this->events, PostPublished::class))->toBe([])
        ->and($this->creates)->toHaveCount(1)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->possiblyPublished)->toBeTrue()
        ->and($failed[0]->exception->reason)->toBe(FailureReason::OutcomeUnknown);
});

it('never re-sends a text-less post when verify_reads cannot read the timeline', function () {
    config(['social.api.x.require_text' => false, 'social.api.x.verify_reads' => true]);
    fakeX($this->creates, Http::response(XFixtures::json('tweet_created'), 201), [
        'api.x.com/2/users/1/tweets*' => Http::response(XFixtures::json('error_forbidden'), 403),
    ]);

    dispatchX(null, [Media::image(XFixtures::png())]);
    runQueue();

    $failed = eventsOf($this->events, PostFailed::class);

    expect(eventsOf($this->events, PostPublished::class))->toBe([])
        ->and($this->creates)->toHaveCount(1)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->possiblyPublished)->toBeTrue()
        ->and($failed[0]->exception->reason)->toBe(FailureReason::OutcomeUnknown);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/2/users/1/tweets'));
});

it('recovers a text-less post through verify_reads when the timeline shows it', function () {
    config(['social.api.x.require_text' => false, 'social.api.x.verify_reads' => true]);
    fakeX($this->creates, Http::response(XFixtures::json('tweet_created'), 201), [
        'api.x.com/2/media/upload' => Http::response(['data' => ['id' => '1880028106020515842']], 200),
        'api.x.com/2/users/1/tweets*' => Http::response(XFixtures::json('user_tweets_match_media'), 200),
    ]);

    dispatchX(null, [Media::image(XFixtures::png())]);
    runQueue();

    $published = eventsOf($this->events, PostPublished::class);

    expect(eventsOf($this->events, PostFailed::class))->toBe([])
        ->and($this->creates)->toHaveCount(1)
        ->and($published)->toHaveCount(1)
        ->and($published[0]->result->platformPostId)->toBe('1880028106020515850')
        ->and($published[0]->result->payload)->toMatchArray(['recovered' => true, 'verified' => true]);
});
