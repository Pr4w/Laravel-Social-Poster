<?php

/*
 * The TikTok driver run through the real PublishToConnectionJob on a database
 * queue: what the consumer is told (PostFailed::$possiblyPublished) when a
 * FILE_UPLOAD fails, and whether the same post can then be dispatched again.
 */

namespace SocialPoster\Tests\Feature\Platforms\TikTokJob;

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
use SocialPoster\Jobs\PublishToConnectionJob;
use SocialPoster\Platforms\TikTok\TikTokOptions;
use SocialPoster\Platforms\TikTok\TikTokPrivacy;
use SocialPoster\Platforms\TikTok\TikTokTarget;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\SocialPost;

const API = 'https://open.tiktokapis.com/v2';
const UPLOAD_URL = 'https://open-upload.tiktokapis.com/video/?upload_id=67890&upload_token=Xza123';

function ttjQueue(): void
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
function ttjWork(int $max = 100): int
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

function ttjOk(array $data = []): array
{
    return ['data' => $data, 'error' => ['code' => 'ok', 'message' => '', 'log_id' => '20221118093231010245136104']];
}

beforeEach(function () {
    Sleep::fake();
    config()->set('social.api.tiktok.chunk_retry_ms', 0);

    $this->tmp = sys_get_temp_dir().'/sp-tiktok-job-'.bin2hex(random_bytes(6));
    mkdir($this->tmp, 0777, true);
    config()->set('social.temp_dir', $this->tmp);
});

afterEach(function () {
    foreach (glob($this->tmp.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->tmp);
    Carbon::setTestNow();
});

it('uploads every chunk and publishes, sending the last chunk from a committed run', function () {
    ttjQueue();

    $published = new ArrayObject;
    Event::listen(PostPublished::class, function (PostPublished $event) use ($published) {
        $published[] = $event;
    });

    $path = $this->tmp.'/clip.mp4';
    $handle = fopen($path, 'wb');
    ftruncate($handle, 70 * 1024 * 1024);
    fclose($handle);

    $sequence = Http::sequence();

    foreach (range(1, 6) as $_) {
        $sequence->push('', 206);
    }

    Http::fake([
        API.'/post/publish/inbox/video/init/' => Http::response(ttjOk(['publish_id' => 'v_inbox_file~v2.7301234567890123457', 'upload_url' => UPLOAD_URL])),
        'https://open-upload.tiktokapis.com/*' => $sequence->push('', 201),
        API.'/post/publish/status/fetch/' => Http::response(ttjOk(['status' => 'SEND_TO_USER_INBOX'])),
    ]);

    dispatch(new PublishToConnectionJob(
        Platform::TikTok,
        new SocialPost(media: [Media::video($path)]),
        Credentials::make(['access_token' => 'tt-token', 'open_id' => 'open-123']),
        true,
    ));
    ttjWork();

    $ranges = Http::recorded(fn (Request $request) => $request->method() === 'PUT')
        ->map(fn ($pair) => $pair[0]->header('Content-Range')[0])
        ->values()
        ->all();

    expect($published)->toHaveCount(1)
        ->and($published[0]->result->platformPostId)->toBe('v_inbox_file~v2.7301234567890123457')
        ->and($ranges)->toHaveCount(7)
        ->and($ranges[6])->toBe('bytes 62914560-73400319/73400320');
});

it('reports an upload that fails before its last chunk as not published, and lets the post be dispatched again', function () {
    ttjQueue();

    $failures = new ArrayObject;
    Event::listen(PostFailed::class, function (PostFailed $event) use ($failures) {
        $failures[] = $event;
    });

    // 70 MB (sparse): seven chunks of 10 MB. TikTok publishes nothing before the last one.
    $path = $this->tmp.'/clip.mp4';
    $handle = fopen($path, 'wb');
    ftruncate($handle, 70 * 1024 * 1024);
    fclose($handle);

    Http::fake([
        API.'/post/publish/creator_info/query/' => Http::response(ttjOk([
            'creator_username' => 'notion.scheduler',
            'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'],
        ])),
        API.'/post/publish/video/init/' => Http::response(ttjOk(['publish_id' => 'v_pub_file~v2.7301234567890123457', 'upload_url' => UPLOAD_URL])),
        'https://open-upload.tiktokapis.com/*' => Http::response('', 400),
    ]);

    $job = fn () => new PublishToConnectionJob(
        Platform::TikTok,
        new SocialPost(
            media: [Media::video($path)],
            caption: 'Hello',
            options: new TikTokOptions(target: TikTokTarget::DirectPost, privacyLevel: TikTokPrivacy::PublicToEveryone),
        ),
        Credentials::make(['access_token' => 'tt-token', 'open_id' => 'open-123']),
        true,
    );

    dispatch($job());
    ttjWork();

    // The same post again (same idempotency key): it is not refused as "may be live".
    dispatch($job());
    ttjWork();

    $inits = Http::recorded(fn (Request $request) => $request->url() === API.'/post/publish/video/init/');
    $puts = Http::recorded(fn (Request $request) => $request->method() === 'PUT')->values();

    expect($failures)->toHaveCount(2)
        ->and($failures[0]->possiblyPublished)->toBeFalse()
        ->and($failures[0]->exception->getMessage())->toContain('HTTP 400')
        ->and($failures[1]->possiblyPublished)->toBeFalse()
        ->and($failures[1]->exception->reason)->not->toBe(FailureReason::OutcomeUnknown)
        ->and($inits)->toHaveCount(2)
        ->and($puts)->toHaveCount(2)
        ->and($puts[0][0]->header('Content-Range'))->toBe(['bytes 0-10485759/73400320']);
});

it('reports a post TikTok says FAILED as not published, so it can be dispatched again', function (string $failReason, int $expectedInits) {
    ttjQueue();
    config()->set('social.api.tiktok.max_restarts', 2);

    $failures = new ArrayObject;
    Event::listen(PostFailed::class, function (PostFailed $event) use ($failures) {
        $failures[] = $event;
    });

    Http::fake([
        API.'/post/publish/creator_info/query/' => Http::response(ttjOk([
            'creator_username' => 'notion.scheduler',
            'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'],
            'max_video_post_duration_sec' => 600,
        ])),
        API.'/post/publish/video/init/' => Http::response(ttjOk(['publish_id' => 'v_pub_url~v2.7301234567890123457'])),
        API.'/post/publish/status/fetch/' => Http::response(ttjOk(['status' => 'FAILED', 'fail_reason' => $failReason])),
    ]);

    $job = fn () => new PublishToConnectionJob(
        Platform::TikTok,
        new SocialPost(
            media: [Media::video('https://cdn.example.com/clip.mp4')],
            caption: 'Hello',
            options: new TikTokOptions(target: TikTokTarget::DirectPost, privacyLevel: TikTokPrivacy::PublicToEveryone),
        ),
        Credentials::make(['access_token' => 'tt-token', 'open_id' => 'open-123']),
        true,
        correlationId: 'tt-failed',
    );

    dispatch($job());
    ttjWork();

    $inits = Http::recorded(fn (Request $request) => $request->url() === API.'/post/publish/video/init/');

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->possiblyPublished)->toBeFalse()
        ->and($inits)->toHaveCount($expectedInits);

    // The same post again is not refused as "may be live".
    dispatch($job());
    ttjWork();

    expect($failures)->toHaveCount(2)
        ->and($failures[1]->exception->reason)->not->toBe(FailureReason::OutcomeUnknown);
})->with([
    'not restartable' => ['file_format_check_failed', 1],
    'restartable, restarts used up' => ['video_pull_failed', 3],
]);

it('starts a FILE_UPLOAD over through the job when the init returned no upload URL (nothing can post)', function () {
    ttjQueue();

    $published = new ArrayObject;
    Event::listen(PostPublished::class, function (PostPublished $event) use ($published) {
        $published[] = $event;
    });

    $path = $this->tmp.'/small.mp4';
    file_put_contents($path, str_repeat('v', 2048));

    Http::fake([
        API.'/post/publish/inbox/video/init/' => Http::sequence()
            ->push(ttjOk(['publish_id' => 'v_inbox_file~v2.1']))
            ->push(ttjOk(['publish_id' => 'v_inbox_file~v2.2', 'upload_url' => UPLOAD_URL])),
        'https://open-upload.tiktokapis.com/*' => Http::response('', 201),
        API.'/post/publish/status/fetch/' => Http::response(ttjOk(['status' => 'SEND_TO_USER_INBOX'])),
    ]);

    dispatch(new PublishToConnectionJob(
        Platform::TikTok,
        new SocialPost(media: [Media::video($path)]),
        Credentials::make(['access_token' => 'tt-token', 'open_id' => 'open-123']),
        true,
    ));
    ttjWork();

    expect($published)->toHaveCount(1)
        ->and($published[0]->result->platformPostId)->toBe('v_inbox_file~v2.2')
        ->and(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/inbox/video/init/')))->toHaveCount(2);
});
