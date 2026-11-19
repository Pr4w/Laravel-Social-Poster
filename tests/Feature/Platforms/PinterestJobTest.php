<?php

/*
 * The Pinterest driver run through the real PublishToConnectionJob on a
 * database queue: the create and verify steps that follow a video's
 * processing window must not inherit its deadline (the job applies the last
 * declared deadline to every later step).
 */

namespace SocialPoster\Tests\Feature\Platforms\PinterestJob;

use ArrayObject;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\PostFailed;
use SocialPoster\Events\PostPublished;
use SocialPoster\Jobs\PublishToConnectionJob;
use SocialPoster\Tests\PinterestFixtures\PinterestKit as K;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\SocialPost;

require_once __DIR__.'/../../Fixtures/pinterest/PinterestKit.php';

function pinQueue(): void
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
function pinWork(int $max = 100): int
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

function pinEvents(): ArrayObject
{
    $log = new ArrayObject;

    foreach ([PostPublished::class, PostFailed::class] as $class) {
        Event::listen($class, function ($event) use ($log) {
            $log[] = $event;
        });
    }

    return $log;
}

/**
 * @param  class-string  $class
 * @return list<object>
 */
function pinOf(ArrayObject $log, string $class): array
{
    return array_values(array_filter($log->getArrayCopy(), fn ($event) => $event instanceof $class));
}

function pinVideoJob(): PublishToConnectionJob
{
    return new PublishToConnectionJob(
        Platform::Pinterest,
        new SocialPost(
            media: [Media::video(K::file('mp4', str_repeat('v', 2048)))],
            caption: 'New spring collection is live: https://example.com/spring.',
            title: 'Spring collection',
        ),
        Credentials::make(K::credentials()),
        true,
        null,
        0,
        'corr-pin',
        'pin-job-key',
    );
}

beforeEach(function () {
    Sleep::fake();
    Carbon::setTestNow('2026-11-18 10:00:00');
    pinQueue();
    // The processing window ends one minute after the upload.
    config(['social.api.pinterest.video_max_wait' => 60]);
});

afterEach(function () {
    Carbon::setTestNow();
    K::cleanupFiles();
});

it('pins a video whose processing finished on the last look before the deadline', function () {
    $log = pinEvents();
    $processing = K::body('media_status_processing');

    K::fake([
        'POST media' => [K::body('media_registered')],
        'POST s3' => [K::s3Ok()],
        // Five polls 10 s apart, then the run at the deadline sees it done.
        'GET media/'.K::MEDIA_ID => [$processing, $processing, $processing, $processing, $processing, K::body('media_status_succeeded')],
        'POST pins' => [K::json(['id' => K::PIN_ID], 201)],
    ]);

    dispatch(pinVideoJob());
    pinWork();

    $published = pinOf($log, PostPublished::class);

    expect(pinOf($log, PostFailed::class))->toBe([])
        ->and($published)->toHaveCount(1)
        ->and($published[0]->result->platformPostId)->toBe(K::PIN_ID)
        ->and(K::sent('POST pins'))->toHaveCount(1);
});

it('keeps verifying an ambiguous video create past the processing deadline and adopts the Pin', function () {
    $log = pinEvents();
    $processing = K::body('media_status_processing');

    K::fake([
        'POST media' => [K::body('media_registered')],
        'POST s3' => [K::s3Ok()],
        'GET media/'.K::MEDIA_ID => [$processing, $processing, $processing, $processing, K::body('media_status_succeeded')],
        'POST pins' => [K::timeout()],
        // The first lookup lands after the processing deadline and fails.
        'GET boards/'.K::BOARD.'/pins' => [K::error('service_unavailable', 503), K::body('board_pins_match')],
    ]);

    dispatch(pinVideoJob());
    pinWork();

    $published = pinOf($log, PostPublished::class);

    expect(pinOf($log, PostFailed::class))->toBe([])
        ->and($published)->toHaveCount(1)
        ->and($published[0]->result->platformPostId)->toBe(K::PIN_ID)
        ->and($published[0]->result->payload['recovered'])->toBeTrue()
        ->and(K::sent('POST pins'))->toHaveCount(1)
        ->and(K::sent('GET boards/'.K::BOARD.'/pins'))->toHaveCount(2);
});

it('never reports an ambiguous video create as a clean timeout when the board cannot be read', function () {
    config(['social.api.pinterest.verify_retries' => 1]);
    $log = pinEvents();
    $processing = K::body('media_status_processing');

    K::fake([
        'POST media' => [K::body('media_registered')],
        'POST s3' => [K::s3Ok()],
        'GET media/'.K::MEDIA_ID => [$processing, $processing, $processing, $processing, K::body('media_status_succeeded')],
        'POST pins' => [K::timeout()],
        'GET boards/'.K::BOARD.'/pins' => [K::error('service_unavailable', 503)],
    ]);

    dispatch(pinVideoJob());
    pinWork();

    $failed = pinOf($log, PostFailed::class);

    expect(pinOf($log, PostPublished::class))->toBe([])
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->exception->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($failed[0]->possiblyPublished)->toBeTrue()
        ->and(K::sent('POST pins'))->toHaveCount(1);
});
