<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\Bluesky\BlueskyDriver;
use SocialPoster\Platforms\Bluesky\BlueskyOptions;
use SocialPoster\Support\DPoP;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\Pending;

require_once __DIR__.'/../../Fixtures/bluesky/helpers.php';

const BSKY_VIDEO_BLOB = [
    '$type' => 'blob',
    'ref' => ['$link' => 'bafkreihbvqytxxcvwqr7gjqcrg2yqpqqzmrdqjvhbjkqkdxyb5g4vtrjau'],
    'mimeType' => 'video/mp4',
    'size' => 5242880,
];

/** @param array<string, mixed> $extra */
function bskyVideoFakes(array $extra = []): void
{
    Http::fake($extra + [
        '*/xrpc/com.atproto.server.getServiceAuth*' => Http::response(bskyFixture('service_auth.json')),
        'video.bsky.app/xrpc/app.bsky.video.getUploadLimits' => Http::response(['canUpload' => true, 'remainingDailyVideos' => 24, 'remainingDailyBytes' => 10_000_000_000]),
        'video.bsky.app/xrpc/app.bsky.video.uploadVideo*' => Http::response(bskyFixture('upload_video_job.json')),
        'video.bsky.app/xrpc/app.bsky.video.getJobStatus*' => Http::response(bskyFixture('job_status_completed.json')),
        '*/xrpc/com.atproto.repo.uploadBlob' => Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkcaption'], 'mimeType' => 'text/vtt', 'size' => 30]]),
        '*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord(),
    ]);
}

beforeEach(function () {
    Cache::flush();
    config(['social.authorizers' => []]);
});

afterEach(function () {
    bskyCleanup();
});

it('uploads the video with a service token for the real PDS and waits for the job', function () {
    bskyVideoFakes();
    $video = bskyFile(str_repeat("\0", 2048), 'mp4');
    $driver = bskyDriver();
    $post = bskyPost('A clip', [Media::video($video)]);

    $pending = $driver->publish($post);

    $auth = bskySent('getServiceAuth');
    $upload = bskySent('uploadVideo')[0];
    parse_str((string) parse_url($auth[1]->url(), PHP_URL_QUERY), $query);
    parse_str((string) parse_url($upload->url(), PHP_URL_QUERY), $uploadQuery);

    expect($pending)->toBeInstanceOf(Pending::class)
        ->and($pending->commits)->toBeFalse()
        ->and($pending->committed)->toBeFalse()
        ->and($pending->recheckAfter)->toBe(5)
        ->and($pending->deadline)->toBeGreaterThan(time() + 1700)
        ->and($pending->state['phase'])->toBe('await_video')
        ->and($pending->state['job_id'])->toBe('N3d1PKq9xgdCSGuTNOSpw')
        ->and($pending->state['polls'])->toBe(1)
        ->and($pending->state['deadline'])->toBe($pending->deadline)
        // The pre-flight token, then the upload token.
        ->and($auth)->toHaveCount(2)
        ->and($query['aud'])->toBe('did:web:morel.us-east.host.bsky.network')
        ->and($query['lxm'])->toBe('com.atproto.repo.uploadBlob')
        ->and((int) $query['exp'])->toBeGreaterThanOrEqual(time() + 1790)
        ->and($upload->method())->toBe('POST')
        ->and($uploadQuery)->toBe(['did' => BSKY_DID, 'name' => basename($video)])
        ->and($upload->header('Authorization'))->toBe(['Bearer '.bskyFixture('service_auth.json')['token']])
        ->and($upload->header('Content-Type'))->toBe(['video/mp4'])
        ->and($upload->body())->toBe(str_repeat("\0", 2048));
});

it('never sends DPoP to the video service, even for DPoP accounts', function () {
    bskyVideoFakes();
    $key = DPoP::generateKey();

    bskyDriver()->publish(bskyPost('x', [Media::video(bskyFile('v', 'mp4'))], null, ['access_jwt' => null, 'access_token' => 'oauth', 'dpop_private_key' => $key]));

    expect(bskySent('getServiceAuth')[0]->hasHeader('DPoP'))->toBeTrue()
        ->and(bskySent('video.bsky.app/xrpc/app.bsky.video.getUploadLimits')[0]->hasHeader('DPoP'))->toBeFalse()
        ->and(bskySent('uploadVideo')[0]->hasHeader('DPoP'))->toBeFalse()
        ->and(bskySent('uploadVideo')[0]->header('Authorization')[0])->toStartWith('Bearer ');
});

it('polls the job without auth, then creates the post with the video embed', function () {
    bskyVideoFakes([
        'video.bsky.app/xrpc/app.bsky.video.getJobStatus*' => Http::sequence()
            ->push(bskyFixture('job_status_encoding.json'))
            ->push(bskyFixture('job_status_completed.json')),
    ]);
    $video = bskyFile('v', 'mp4');
    $driver = bskyDriver([$video => new MediaMetadata(width: 1080, height: 1920, rotation: 90)]);
    $post = bskyPost('A clip', [Media::video($video, null, '  A cat on a sofa  ')], new BlueskyOptions(videoPresentation: 'gif'));

    $first = $driver->publish($post);
    $second = $driver->resume($post, $first->state);
    $commit = $driver->resume($post, $second->state);
    $result = bskyPublished($driver->resume($post, $commit->state));

    $poll = bskySent('getJobStatus')[0];
    $record = bskySent('createRecord')[0]->data()['record'];

    expect($second)->toBeInstanceOf(Pending::class)
        ->and($second->state['phase'])->toBe('await_video')
        ->and($second->state['polls'])->toBe(2)
        ->and($second->deadline)->toBe($first->deadline)
        ->and($poll->url())->toBe('https://video.bsky.app/xrpc/app.bsky.video.getJobStatus?jobId=N3d1PKq9xgdCSGuTNOSpw')
        ->and($poll->hasHeader('Authorization'))->toBeFalse()
        ->and($commit->commits)->toBeTrue()
        ->and($commit->state['phase'])->toBe('create')
        ->and($commit->state['video'])->toBe(['blob' => BSKY_VIDEO_BLOB])
        ->and($commit->state)->not->toHaveKeys(['job_id', 'polls'])
        ->and($commit->state['rkey'])->toBe($first->state['rkey'])
        ->and($record['embed'])->toBe([
            '$type' => 'app.bsky.embed.video',
            'video' => BSKY_VIDEO_BLOB,
            'aspectRatio' => ['width' => 1080, 'height' => 1920],
            'alt' => 'A cat on a sofa',
            'presentation' => 'gif',
        ])
        ->and($result->platformPostId)->toBe('at://'.BSKY_DID.'/app.bsky.feed.post/'.$first->state['rkey']);
});

it('creates the post at once when the service already has the video', function () {
    bskyVideoFakes(['video.bsky.app/xrpc/app.bsky.video.uploadVideo*' => Http::response(bskyFixture('upload_video_already_exists.json'), 409)]);

    $pending = bskyDriver()->publish(bskyPost('x', [Media::video(bskyFile('v', 'mp4'))]));

    expect($pending->commits)->toBeTrue()
        ->and($pending->state['phase'])->toBe('create')
        ->and($pending->state['video']['blob'])->toBe(BSKY_VIDEO_BLOB)
        ->and(bskySent('getJobStatus'))->toBe([]);
});

it('uploads WebVTT captions with the video record', function () {
    bskyVideoFakes(['video.bsky.app/xrpc/app.bsky.video.uploadVideo*' => Http::response(['jobStatus' => ['jobId' => 'j', 'state' => 'JOB_STATE_COMPLETED', 'blob' => BSKY_VIDEO_BLOB]])]);
    $vtt = bskyFile("WEBVTT\n\n00:00.000 --> 00:01.000\nHello", 'vtt');

    bskyRun(bskyDriver(), bskyPost('x', [Media::video(bskyFile('v', 'mp4'))], new BlueskyOptions(videoCaptions: ['en' => $vtt])));

    $upload = bskySent('/xrpc/com.atproto.repo.uploadBlob')[0];
    $embed = bskySent('createRecord')[0]->data()['record']['embed'];

    expect($upload->header('Content-Type'))->toBe(['text/vtt'])
        ->and($upload->body())->toBe(file_get_contents($vtt))
        ->and($embed['captions'])->toBe([['lang' => 'en', 'file' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkcaption'], 'mimeType' => 'text/vtt', 'size' => 30]]]);
});

it('refuses a caption file over 20,000 bytes', function () {
    bskyVideoFakes(['video.bsky.app/xrpc/app.bsky.video.uploadVideo*' => Http::response(bskyFixture('upload_video_already_exists.json'), 409)]);
    $vtt = bskyFile(str_repeat('a', 20001), 'vtt');

    $e = bskyCatch(fn () => bskyRun(bskyDriver(), bskyPost('x', [Media::video(bskyFile('v', 'mp4'))], new BlueskyOptions(videoCaptions: ['en' => $vtt]))));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::InvalidContent)
        ->and(bskySent('createRecord'))->toBe([]);
});

it('stops before uploading when the account cannot upload today', function (string $message, FailureReason $reason) {
    bskyVideoFakes(['video.bsky.app/xrpc/app.bsky.video.getUploadLimits' => Http::response(['canUpload' => false, 'remainingDailyVideos' => 0, 'remainingDailyBytes' => 0, 'message' => $message])]);

    $e = bskyCatch(fn () => bskyDriver()->publish(bskyPost('x', [Media::video(bskyFile('v', 'mp4'))])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe($reason)
        ->and($e->context['upload_limits'])->toBeTrue()
        ->and(bskySent('uploadVideo'))->toBe([]);
})->with([
    'daily quota' => ['You have exceeded your daily upload limit', FailureReason::RateLimited],
    'email' => ['Please verify your email address before uploading', FailureReason::AccountRestricted],
    'unspecified' => ['Not allowed', FailureReason::RateLimited],
]);

it('gives a daily-limit refusal a retry hint', function () {
    bskyVideoFakes(['video.bsky.app/xrpc/app.bsky.video.getUploadLimits' => Http::response(['canUpload' => false, 'error' => 'Daily video limit reached'])]);

    $e = bskyCatch(fn () => bskyDriver()->publish(bskyPost('x', [Media::video(bskyFile('v', 'mp4'))])));

    expect($e->context['retry_after_hint'])->toBe(86400)
        ->and($e->context['retry_at'])->toBe(gmdate('Y-m-d\TH:i:s\Z', time() + 86400));
});

it('ignores a failing pre-flight and can be turned off', function () {
    bskyVideoFakes(['video.bsky.app/xrpc/app.bsky.video.getUploadLimits' => Http::response('Bad Gateway', 502)]);

    $pending = bskyDriver()->publish(bskyPost('x', [Media::video(bskyFile('v', 'mp4'))]));

    expect($pending->state['phase'])->toBe('await_video');

    config(['social.api.bluesky.check_upload_limits' => false]);
    bskyVideoFakes();

    bskyDriver()->publish(bskyPost('x', [Media::video(bskyFile('v', 'mp4'))]));

    expect(bskySent('video.bsky.app/xrpc/app.bsky.video.getUploadLimits'))->toBe([])
        ->and(bskySent('getServiceAuth'))->toHaveCount(1);
});

it('ignores a pre-flight whose service token is refused', function () {
    bskyVideoFakes(['*/xrpc/com.atproto.server.getServiceAuth*' => function (Request $request) {
        return str_contains($request->url(), 'getUploadLimits')
            ? Http::response(['error' => 'InvalidRequest', 'message' => 'unsupported lxm'], 400)
            : Http::response(bskyFixture('service_auth.json'));
    }]);

    expect(bskyDriver()->publish(bskyPost('x', [Media::video(bskyFile('v', 'mp4'))]))->state['phase'])->toBe('await_video');
});

it('encodes a non-default PDS port in the audience', function () {
    bskyVideoFakes();

    bskyDriver()->publish(bskyPost('x', [Media::video(bskyFile('v', 'mp4'))], null, ['pds' => 'https://pds.example.com:2583']));

    parse_str((string) parse_url(bskySent('getServiceAuth')[1]->url(), PHP_URL_QUERY), $query);

    expect($query['aud'])->toBe('did:web:pds.example.com%3A2583')
        ->and(bskySent('getServiceAuth')[1]->url())->toStartWith('https://pds.example.com:2583/xrpc/');
});

it('never mints the upload token for the entryway while the PLC directory is down', function () {
    bskyVideoFakes(['plc.directory/*' => Http::response('upstream error', 503)]);

    $e = bskyCatch(fn () => bskyDriver()->publish(bskyPost('x', [Media::video(bskyFile('v', 'mp4'))], null, ['pds' => 'https://bsky.social'])));

    $audiences = array_map(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['aud'] ?? null;
    }, bskySent('getServiceAuth'));

    // A retry once the directory answers, not an upload that cannot work.
    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->context['pds_resolution'])->toBeTrue()
        ->and($audiences)->not->toContain('did:web:bsky.social')
        ->and(bskySent('uploadVideo'))->toBe([]);
});

it('needs the account PDS for a video when the DID document names none', function () {
    bskyVideoFakes(['plc.directory/*' => Http::response(['id' => BSKY_DID, 'service' => []])]);

    $e = bskyCatch(fn () => bskyDriver()->publish(bskyPost('x', [Media::video(bskyFile('v', 'mp4'))], null, ['pds' => 'https://bsky.social'])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Misconfigured)
        ->and($e->context['missing_credentials'])->toBe(['pds'])
        ->and(bskySent('uploadVideo'))->toBe([]);
});

it('maps a refused service token', function () {
    bskyVideoFakes(['*/xrpc/com.atproto.server.getServiceAuth*' => Http::response(['error' => 'InvalidToken', 'message' => 'Bad token scope'], 401)]);

    $e = bskyCatch(fn () => bskyDriver()->publish(bskyPost('x', [Media::video(bskyFile('v', 'mp4'))])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::InvalidToken)
        ->and(bskySent('uploadVideo'))->toBe([]);
});

it('classifies upload refusals by the video-service message', function (int $status, array $body, string $class, FailureReason $reason) {
    bskyVideoFakes(['video.bsky.app/xrpc/app.bsky.video.uploadVideo*' => Http::response($body, $status)]);

    $e = bskyCatch(fn () => bskyDriver()->publish(bskyPost('x', [Media::video(bskyFile('v', 'mp4'))])));

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason)
        ->and($e->context['surface'] ?? null)->toBe('video');
})->with([
    'unconfirmed email' => [400, ['error' => 'InvalidRequest', 'message' => 'Your email has not been confirmed. Please confirm your email to upload videos.'], PermanentException::class, FailureReason::AccountRestricted],
    'daily limit' => [400, ['error' => 'InvalidRequest', 'message' => 'Video upload limit reached for today'], PermanentException::class, FailureReason::RateLimited],
    'service down' => [503, ['error' => 'InternalServerError', 'message' => 'Internal Server Error'], TemporaryException::class, FailureReason::ServerError],
    'token expired' => [401, ['error' => 'ExpiredToken', 'message' => 'Token has expired'], PermanentException::class, FailureReason::InvalidToken],
]);

it('retries an upload that answered 200 without a job id', function () {
    bskyVideoFakes(['video.bsky.app/xrpc/app.bsky.video.uploadVideo*' => Http::response(['did' => BSKY_DID])]);

    $e = bskyCatch(fn () => bskyDriver()->publish(bskyPost('x', [Media::video(bskyFile('v', 'mp4'))])));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->context['missing_field'])->toBe('jobId');
});

it('fails the post when processing fails, with the export hint', function () {
    bskyVideoFakes(['video.bsky.app/xrpc/app.bsky.video.getJobStatus*' => Http::response(bskyFixture('job_status_failed.json'))]);
    $driver = bskyDriver();
    $post = bskyPost('x', [Media::video(bskyFile('v', 'mp4'))]);

    $e = bskyCatch(fn () => $driver->resume($post, $driver->publish($post)->state));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toBe('Bluesky could not process the video (Unsupported video codec). Export it as an MP4 (H.264) and try again.')
        ->and($e->context['failure_code'])->toBe('validation_failure')
        ->and($e->context['job_id'])->toBe('N3d1PKq9xgdCSGuTNOSpw');
});

it('reads an email refusal out of a failed job', function () {
    bskyVideoFakes(['video.bsky.app/xrpc/app.bsky.video.getJobStatus*' => Http::response(['jobStatus' => ['jobId' => 'j', 'state' => 'JOB_STATE_FAILED', 'error' => 'Verify your email to post videos']])]);
    $driver = bskyDriver();
    $post = bskyPost('x', [Media::video(bskyFile('v', 'mp4'))]);

    $e = bskyCatch(fn () => $driver->resume($post, $driver->publish($post)->state));

    expect($e->reason)->toBe(FailureReason::AccountRestricted);
});

it('fails at upload time when the service says the job already failed', function () {
    bskyVideoFakes(['video.bsky.app/xrpc/app.bsky.video.uploadVideo*' => Http::response(['jobId' => 'j', 'state' => 'JOB_STATE_FAILED', 'error' => 'Could not decode the video'])]);

    $e = bskyCatch(fn () => bskyDriver()->publish(bskyPost('x', [Media::video(bskyFile('v', 'mp4'))])));

    expect($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toBe('Bluesky could not process the video (Could not decode the video).');
});

it('retries a failing status poll', function () {
    bskyVideoFakes(['video.bsky.app/xrpc/app.bsky.video.getJobStatus*' => Http::response('Bad Gateway', 502)]);
    $driver = bskyDriver();
    $post = bskyPost('x', [Media::video(bskyFile('v', 'mp4'))]);

    $e = bskyCatch(fn () => $driver->resume($post, $driver->publish($post)->state));

    expect($e)->toBeInstanceOf(TemporaryException::class)->and($e->retryAfter)->toBe(60);
});

it('refuses an await_video state without a job id', function () {
    Http::fake();

    $e = bskyCatch(fn () => bskyDriver()->resume(bskyPost('x'), ['phase' => 'await_video']));

    expect($e)->toBeInstanceOf(PermanentException::class);
    Http::assertNothingSent();
});

it('slows its polling down as the job runs, and speeds up near the end', function (int $polls, mixed $progress, int $expected) {
    $probe = new class extends BlueskyDriver
    {
        public function recheck(int $polls, mixed $progress): int
        {
            return $this->videoRecheck($polls, $progress);
        }
    };

    expect($probe->recheck($polls, $progress))->toBe($expected);
})->with([
    [0, null, 5],
    [5, 30, 5],
    [6, 30, 10],
    [17, null, 10],
    [18, 50, 30],
    [40, 95, 5],
]);

it('names the upload after the file, safely', function () {
    $probe = new class extends BlueskyDriver
    {
        public function name(Media $media): string
        {
            return $this->videoName($media);
        }
    };

    expect($probe->name(Media::video('https://cdn.example.com/a b/My clip (final).mp4?sig=1')))->toBe('My-clip-final-.mp4')
        ->and($probe->name(Media::video('/tmp/clip')))->toBe('clip.mp4')
        ->and($probe->name(Media::video('/tmp/...')))->toBe('video.mp4');
});
