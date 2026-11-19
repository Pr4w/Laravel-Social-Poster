<?php

/*
 * The Instagram driver run through the real PublishToConnectionJob on a
 * database queue: what the consumer is told (PostFailed::$possiblyPublished),
 * what the idempotency store keeps, and how many media_publish calls go out
 * when the publish commit meets an ambiguous or failed answer.
 */

namespace SocialPoster\Tests\Feature\Platforms\InstagramJob;

use const SocialPoster\Tests\Fixtures\Instagram\IG_LISTING;
use const SocialPoster\Tests\Fixtures\Instagram\IG_MEDIA;
use const SocialPoster\Tests\Fixtures\Instagram\IG_PUBLISH;

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
use SocialPoster\Contracts\AtomicIdempotencyStore;
use SocialPoster\Contracts\IdempotencyStore;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\CommentFailed;
use SocialPoster\Events\CommentPublished;
use SocialPoster\Events\PostFailed;
use SocialPoster\Events\PostPublished;
use SocialPoster\Jobs\AddFirstCommentJob;
use SocialPoster\Jobs\PublishToConnectionJob;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\SocialPost;

use function SocialPoster\Tests\Fixtures\Instagram\igCreds;
use function SocialPoster\Tests\Fixtures\Instagram\igErr;
use function SocialPoster\Tests\Fixtures\Instagram\igFake;
use function SocialPoster\Tests\Fixtures\Instagram\igRes;
use function SocialPoster\Tests\Fixtures\Instagram\igSent;
use function SocialPoster\Tests\Fixtures\Instagram\igSeq;
use function SocialPoster\Tests\Fixtures\Instagram\igStatus;

require_once __DIR__.'/../../Fixtures/instagram/helpers.php';

const IG_KEY = 'ig-job-key';

function igQueue(): void
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
function igWork(int $max = 200): int
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

function igEvents(): ArrayObject
{
    $log = new ArrayObject;

    foreach ([PostPublished::class, PostFailed::class] as $class) {
        Event::listen($class, function ($event) use ($log) {
            $log[] = $event;
        });
    }

    return $log;
}

/** @return list<PostFailed> */
function igFailures(ArrayObject $log): array
{
    return array_values(array_filter($log->getArrayCopy(), fn ($event) => $event instanceof PostFailed));
}

function igPhotoJob(): PublishToConnectionJob
{
    return new PublishToConnectionJob(
        Platform::Instagram,
        new SocialPost(media: [Media::image('https://cdn.example.com/photo.jpg')], caption: 'Hello #instagram'),
        Credentials::make(igCreds()),
        true,
        null,
        0,
        'corr-ig',
        IG_KEY,
    );
}

function igStatusOf(string $key): ?string
{
    $store = app(IdempotencyStore::class);

    return $store instanceof AtomicIdempotencyStore ? $store->status($key) : null;
}

/** A media_publish whose answer was lost after the request went out. */
function igTimedOut(): \Closure
{
    return fn (Request $request) => (Http::failedConnection('cURL error 28: Operation timed out after 120001 milliseconds with 0 bytes received'))($request);
}

beforeEach(function () {
    config(['social.api.instagram.graph_version' => 'v25.0']);
    Sleep::fake();
    igQueue();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('reports a throttled publish followed by the daily limit as not published, so the app can reschedule it', function () {
    $log = igEvents();

    igFake([
        IG_MEDIA => igRes(['id' => 'c1']),
        IG_PUBLISH => igSeq(igErr('app_throttle'), igErr('daily_limit')),
        'GET /c1' => igStatus('FINISHED'),
        IG_LISTING => igRes(['data' => []]),
    ]);

    dispatch(igPhotoJob());
    igWork();

    $failed = igFailures($log);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->exception->reason)->toBe(FailureReason::RateLimited)
        ->and($failed[0]->exception->context['daily_limit'] ?? null)->toBeTrue()
        ->and($failed[0]->possiblyPublished)->toBeFalse()
        ->and(igStatusOf(IG_KEY))->not->toBe(AtomicIdempotencyStore::STATUS_FAILED);

    // The reschedule at retry_at reuses the key: it publishes again rather
    // than being refused as an unknown outcome.
    dispatch(igPhotoJob());
    igWork();

    $failed = igFailures($log);

    expect($failed)->toHaveCount(2)
        ->and($failed[1]->exception->reason)->toBe(FailureReason::RateLimited)
        ->and(igSent(IG_MEDIA))->toHaveCount(2);
});

it('reports a post whose replacement containers cannot be created as not published', function () {
    $log = igEvents();

    igFake([
        IG_MEDIA => igSeq(igRes(['id' => 'c1']), igErr('graph_503')),
        IG_PUBLISH => igErr('container_expired'),
        'GET /c1' => igStatus('EXPIRED'),
    ]);

    dispatch(igPhotoJob());
    igWork();

    $failed = igFailures($log);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->exception->reason)->toBe(FailureReason::ServerError)
        ->and($failed[0]->possiblyPublished)->toBeFalse()
        ->and(igSent(IG_PUBLISH))->toHaveCount(1)
        ->and(igStatusOf(IG_KEY))->not->toBe(AtomicIdempotencyStore::STATUS_FAILED);
});

it('never publishes a second container when the container of an ambiguous publish cannot be found', function () {
    $log = igEvents();

    igFake([
        IG_MEDIA => igSeq(igRes(['id' => 'c1']), igRes(['id' => 'c2'])),
        IG_PUBLISH => igSeq(igTimedOut(), igRes(['id' => 'm2'])),
        'GET /c1' => igErr('media_not_found'),
        IG_LISTING => igRes(['data' => []]),
        'GET /m2' => igRes(['permalink' => 'https://www.instagram.com/p/M2/']),
    ]);

    dispatch(igPhotoJob());
    igWork();

    $failed = igFailures($log);
    $published = array_filter($log->getArrayCopy(), fn ($event) => $event instanceof PostPublished);

    expect($published)->toBe([])
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->possiblyPublished)->toBeTrue()
        ->and($failed[0]->exception->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and(igSent(IG_PUBLISH))->toHaveCount(1)
        ->and(igSent(IG_MEDIA))->toHaveCount(1)
        ->and(igStatusOf(IG_KEY))->toBe(AtomicIdempotencyStore::STATUS_FAILED);
});

it('reports an ambiguous publish it could not verify as possibly published and keeps the key', function (string $fixture) {
    $log = igEvents();

    igFake([
        IG_MEDIA => igRes(['id' => 'c1']),
        IG_PUBLISH => igTimedOut(),
        'GET /c1' => igErr($fixture),
        IG_LISTING => igRes(['data' => []]),
    ]);

    dispatch(igPhotoJob());
    igWork();

    $failed = igFailures($log);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->possiblyPublished)->toBeTrue()
        ->and(igSent(IG_PUBLISH))->toHaveCount(1)
        ->and(igStatusOf(IG_KEY))->toBe(AtomicIdempotencyStore::STATUS_FAILED);
})->with([
    'token rejected' => ['token_invalid'],
    'throttled reads' => ['user_throttle'],
    'outage' => ['graph_503'],
]);

// --- First comment: an attempt whose answer was lost is never posted twice -------

/** The first-comment job of a live photo, as the publish job queues it. */
function igCommentJob(): AddFirstCommentJob
{
    return new AddFirstCommentJob(
        Platform::Instagram,
        '17900000000000042',
        'Link in bio https://example.com',
        Credentials::make(igCreds()),
        [],
        new SocialPost(media: [Media::image('https://cdn.example.com/photo.jpg')], caption: 'Hello #instagram', comment: 'Link in bio https://example.com'),
        PostResult::success(Platform::Instagram, '17900000000000042', null, ['media_type' => 'IMAGE'], PostResult::STATE_PUBLISHED),
        IG_KEY,
    );
}

function igCommentEvents(): ArrayObject
{
    $log = new ArrayObject;

    foreach ([CommentPublished::class, CommentFailed::class] as $class) {
        Event::listen($class, function ($event) use ($log) {
            $log[] = $event;
        });
    }

    return $log;
}

it('finds the comment of an attempt whose answer was lost instead of commenting twice', function (\Closure $lost) {
    $log = igCommentEvents();

    igFake([
        'GET /17900000000000042/comments' => igSeq(
            igRes(['data' => []]),
            // Instagram stored the first attempt's comment.
            igRes(['data' => [['id' => '17890000000000099', 'text' => 'Link in bio https://example.com', 'timestamp' => gmdate('Y-m-d\TH:i:sO'), 'user' => ['id' => '17841400000000001']]]]),
        ),
        'POST /17900000000000042/comments' => $lost,
    ]);

    dispatch(igCommentJob());
    igWork();

    expect(igSent('POST /17900000000000042/comments'))->toHaveCount(1)
        ->and(igSent('GET /17900000000000042/comments'))->toHaveCount(2)
        ->and($log->getArrayCopy())->toHaveCount(1)
        ->and($log[0])->toBeInstanceOf(CommentPublished::class)
        ->and($log[0]->commentId)->toBe('17890000000000099')
        ->and($log[0]->payload['recovered'])->toBeTrue()
        ->and(app(IdempotencyStore::class)->completed(AddFirstCommentJob::commentKeyFor(IG_KEY))?->platformPostId)->toBe('17890000000000099')
        ->and(DB::table('jobs')->count())->toBe(0);
})->with([
    'connection lost after sending' => [igTimedOut()],
    '5xx after Instagram created it' => [igErr('graph_503')],
]);

it('comments again when the lookup shows the lost attempt never landed', function () {
    $log = igCommentEvents();

    igFake([
        'GET /17900000000000042/comments' => igRes(['data' => [['id' => 'c-fan', 'text' => 'Link in bio https://example.com', 'from' => ['id' => '17841499999999999', 'username' => 'a.fan']]]]),
        'POST /17900000000000042/comments' => igSeq(igTimedOut(), igRes(['id' => '17890000000000100'])),
    ]);

    dispatch(igCommentJob());
    igWork();

    expect(igSent('POST /17900000000000042/comments'))->toHaveCount(2)
        ->and(igSent('GET /17900000000000042/comments'))->toHaveCount(2)
        ->and($log->getArrayCopy())->toHaveCount(1)
        ->and($log[0])->toBeInstanceOf(CommentPublished::class)
        ->and($log[0]->commentId)->toBe('17890000000000100')
        ->and($log[0]->payload)->not->toHaveKey('recovered');
});
