<?php

use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use SocialPoster\Contracts\IdempotencyStore;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\PostFailed;
use SocialPoster\Events\PostPublished;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Jobs\PublishToConnectionJob;
use SocialPoster\Platforms\LinkedIn\LinkedInOptions;
use SocialPoster\SocialManager;
use SocialPoster\Tests\Fixtures\linkedin\LinkedInKit as Kit;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\Published;
use SocialPoster\ValueObjects\SocialPost;

/*
 * Recovery after an ambiguous create, staged-upload progress, link-card fetch
 * limits and the create step's own deadline.
 */

afterEach(fn () => Kit::cleanup());

/** The URN in tests/Fixtures/linkedin/error_422_duplicate.json. */
const LR_DUPLICATE_URN = 'urn:li:share:7012345678901234000';

/**
 * A fake for /rest/posts: GET lists $elements, POST answers with $create.
 *
 * @param  list<array<string, mixed>>  $elements
 */
function lrPosts(array $elements, mixed $create = null): void
{
    Http::fake(['api.linkedin.com/rest/posts*' => fn (Request $r) => $r->method() === 'GET'
        ? Http::response(['elements' => $elements])
        : ($create ?? Kit::created())]);
}

/**
 * A body produced on demand, counting the bytes handed out, so a test can tell
 * how much of it was read.
 */
function lrBigBody(int &$produced, string $head = '', int $total = 20_000_000): PumpStream
{
    return new PumpStream(function (int $length) use (&$produced, $head, $total) {
        if ($produced >= $total) {
            return false;
        }

        $chunk = $produced === 0 && $head !== '' ? $head : str_repeat('x', $length);
        $produced += strlen($chunk);

        return $chunk;
    });
}

// --- Recovery: matching the post an interrupted run created --------------------------------

it('recognises its post when LinkedIn lists hashtags as templates', function () {
    config(['social.api.linkedin.verify_reads' => true]);

    $post = Kit::post(caption: 'Follow best practices #coding and ＃日本');
    $pending = Kit::driver()->publish($post);

    expect($pending->state['commentary'])->toBe('Follow best practices #coding and ＃日本');

    // GET shape from the Posts API docs ("Hashtag a keyword"): hashtags come back as {hashtag|\#|tag}.
    lrPosts([[
        'id' => 'urn:li:share:6864768486615375872',
        'commentary' => 'Follow best practices {hashtag|\\#|coding} and {hashtag|＃|日本}',
        'createdAt' => (time() - 20) * 1000,
    ]]);

    $outcome = Kit::driver()->resume($post, $pending->state + [Pending::RECOVERING => true, Pending::COMMITTING_SINCE => time() - 30]);

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->platformPostId)->toBe('urn:li:share:6864768486615375872')
        ->and($outcome->result->payload['recovered'])->toBeTrue()
        ->and(Kit::sent('rest/posts', 'POST'))->toBe(0);
});

it('normalises hashtag templates, escapes and spacing before comparing commentary', function () {
    $driver = Kit::driver();

    expect($driver->call('normaliseCommentary', "Ship {hashtag|\\#|my\\_tag}  \\(now\\)\n"))->toBe('Ship #my_tag (now)')
        ->and($driver->call('normaliseCommentary', 'Ship #my\\_tag \\(now\\)'))->toBe('Ship #my_tag (now)')
        ->and($driver->call('normaliseCommentary', '{hashtag|＃|日本}'))->toBe('＃日本')
        // A literal, escaped "{hashtag|#|x}" typed by the user is text, not a template.
        ->and($driver->call('normaliseCommentary', '\\{hashtag\\|#\\|x\\}'))->toBe('{hashtag|#|x}');
});

it('lists the author posts in the author view, so a post still processing is found', function () {
    config(['social.api.linkedin.verify_reads' => true]);
    lrPosts([['id' => Kit::SHARE, 'commentary' => 'Hello LinkedIn', 'createdAt' => time() * 1000, 'lifecycleState' => 'PUBLISH_REQUESTED']]);

    $outcome = Kit::driver()->resume(Kit::post(), ['phase' => 'create_post', 'commentary' => 'Hello LinkedIn', 'content' => null, Pending::RECOVERING => true]);

    expect($outcome->result->platformPostId)->toBe(Kit::SHARE);
    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r['q'] === 'author' && $r['viewContext'] === 'AUTHOR');
});

it('never adopts a reshare of another post during recovery', function () {
    config(['social.api.linkedin.verify_reads' => true]);

    $post = Kit::post(caption: '', options: new LinkedInOptions(reshareOf: 'urn:li:share:2222'));
    $pending = Kit::driver()->publish($post);

    // The author reshared a DIFFERENT post (parent 1111) moments ago; ours (parent 2222) was never created.
    lrPosts([[
        'id' => 'urn:li:share:7777', 'commentary' => '', 'createdAt' => (time() - 20) * 1000,
        'reshareContext' => ['parent' => 'urn:li:share:1111', 'root' => 'urn:li:share:1111'],
    ]]);

    $outcome = Kit::driver()->resume($post, $pending->state + [Pending::RECOVERING => true, Pending::COMMITTING_SINCE => time() - 30]);

    expect($outcome->result->platformPostId)->toBe(Kit::SHARE)
        ->and(Kit::sent('rest/posts', 'POST'))->toBe(1);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['reshareContext'] === ['parent' => 'urn:li:share:2222']);
});

it('adopts the recovered reshare of the same post', function () {
    config(['social.api.linkedin.verify_reads' => true]);

    $post = Kit::post(caption: '', options: new LinkedInOptions(reshareOf: 'urn:li:share:2222'));
    $pending = Kit::driver()->publish($post);

    lrPosts([
        ['id' => 'urn:li:share:7777', 'commentary' => '', 'createdAt' => (time() - 20) * 1000, 'reshareContext' => ['parent' => 'urn:li:share:1111']],
        ['id' => 'urn:li:share:8888', 'commentary' => '', 'createdAt' => (time() - 10) * 1000, 'reshareContext' => ['parent' => 'urn:li:share:2222', 'root' => 'urn:li:share:2222']],
    ]);

    $outcome = Kit::driver()->resume($post, $pending->state + [Pending::RECOVERING => true, Pending::COMMITTING_SINCE => time() - 30]);

    expect($outcome->result->platformPostId)->toBe('urn:li:share:8888')
        ->and(Kit::sent('rest/posts', 'POST'))->toBe(0);
});

it('never takes a reshare for a plain post with the same text, or the reverse', function () {
    $driver = Kit::driver();
    $since = time() - 30;
    $text = ['commentary' => 'Same words'];
    $reshare = ['commentary' => 'Same words', 'reshareContext' => ['parent' => 'urn:li:share:1']];

    expect($driver->call('matchesPost', $reshare, $text, $since))->toBeFalse()
        ->and($driver->call('matchesPost', $text, $reshare, $since))->toBeFalse()
        ->and($driver->call('matchesPost', $reshare, $reshare, $since))->toBeTrue();
});

it('ignores an identical post created before the attempt began', function () {
    config(['social.api.linkedin.verify_reads' => true]);

    // The same text went out five minutes earlier (an earlier, separate publish).
    lrPosts([['id' => 'urn:li:share:1', 'commentary' => 'Hello LinkedIn', 'createdAt' => (time() - 300) * 1000]]);

    $outcome = Kit::driver()->resume(Kit::post(), [
        'phase' => 'create_post', 'commentary' => 'Hello LinkedIn', 'content' => null, 'since' => time() - 30,
        Pending::RECOVERING => true, Pending::COMMITTING_SINCE => time() - 30,
    ]);

    expect($outcome->result->platformPostId)->toBe(Kit::SHARE)
        ->and(Kit::sent('rest/posts', 'POST'))->toBe(1);
});

it('verifies against the time of the lost create, not the time of the verify run', function () {
    config(['social.api.linkedin.verify_reads' => true]);
    $lostAt = time() - 900; // a queue backlog delayed the verify run by 15 minutes

    lrPosts([['id' => 'urn:li:share:42', 'commentary' => 'Hello LinkedIn', 'createdAt' => ($lostAt + 2) * 1000]]);

    // The job stamps a fresh _committing_since on the verify run (it is a new commit step).
    $outcome = Kit::driver()->resume(Kit::post(), [
        'phase' => 'create_post', 'commentary' => 'Hello LinkedIn', 'content' => null, 'since' => $lostAt, 'verify' => true,
        Pending::RECOVERING => false, Pending::COMMITTING_SINCE => time(),
    ]);

    expect($outcome->result->platformPostId)->toBe('urn:li:share:42')
        ->and(Kit::sent('rest/posts', 'POST'))->toBe(0);
});

it('keeps the earliest attempt time when the create is lost again in a verify run', function () {
    config(['social.api.linkedin.verify_reads' => true]);
    $lostAt = time() - 900;

    Http::fake(['api.linkedin.com/rest/posts*' => fn (Request $r) => $r->method() === 'GET'
        ? Http::response(['elements' => []])
        : Kit::connectionFailure('cURL error 56: Recv failure: Connection reset by peer')($r)]);

    $again = Kit::driver()->resume(Kit::post(), [
        'phase' => 'create_post', 'commentary' => 'Hello LinkedIn', 'content' => null, 'since' => $lostAt, 'verify' => true,
        Pending::COMMITTING_SINCE => time(),
    ]);

    expect($again)->toBeInstanceOf(Pending::class)
        ->and($again->commits)->toBeTrue()
        ->and($again->state['since'])->toBe($lostAt)
        ->and($again->state['verify'])->toBeTrue();
});

// --- Recovery: failures in the run after an ambiguous create -------------------------------

it('adopts the post a duplicate refusal names after an ambiguous create', function () {
    config(['social.api.linkedin.verify_reads' => true]);

    // The verify read misses the post (still processing), the re-POST is refused as a duplicate.
    lrPosts([], Http::response(Kit::json('error_422_duplicate'), 422));

    $outcome = Kit::driver()->resume(Kit::post(), [
        'phase' => 'create_post', 'commentary' => 'Hello LinkedIn', 'content' => null, 'since' => time() - 30, 'verify' => true,
    ]);

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->platformPostId)->toBe(LR_DUPLICATE_URN)
        ->and($outcome->result->payload['recovered'])->toBeTrue()
        ->and($outcome->result->payload['duplicate_refused'])->toBeTrue();
});

it('still reports a duplicate refusal of a first create as a clean failure', function () {
    Http::fake(['api.linkedin.com/rest/posts' => Http::response(Kit::json('error_422_duplicate'), 422)]);

    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post(), ['phase' => 'create_post', 'commentary' => 'Hello LinkedIn', 'content' => null]));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::DuplicateContent)
        ->and($e->context['existing_urn'])->toBe(LR_DUPLICATE_URN)
        ->and($e->possiblyPublished())->toBeFalse();
});

it('flags every failure of a verify run as possibly published', function (array $credentials, ?string $fixture, int $status, string $class) {
    config(['social.api.linkedin.verify_reads' => true]);
    lrPosts([], $fixture !== null ? Http::response(Kit::json($fixture), $status) : null);

    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post(credentials: Kit::credentials($credentials)), [
        'phase' => 'create_post', 'commentary' => 'Hello LinkedIn', 'content' => null, 'since' => time() - 30, 'verify' => true,
    ]));

    expect($e)->toBeInstanceOf($class)
        ->and($e->possiblyPublished())->toBeTrue();
})->with([
    'refused re-create' => [[], 'error_422_input', 422, PermanentException::class],
    'server error on the re-create' => [[], 'error_500', 500, TemporaryException::class],
    'expired token before the read' => [['expires_at' => 1_000_000_000], null, 0, PermanentException::class],
]);

it('reports a lost create whose re-create is refused as a duplicate as published, end to end', function () {
    Sleep::fake();
    config(['social.api.linkedin.verify_reads' => true]);
    Event::fake([PostFailed::class, PostPublished::class]);

    $posts = 0;
    Http::fake(['api.linkedin.com/rest/posts*' => function (Request $r) use (&$posts) {
        if ($r->method() === 'GET') {
            return Http::response(['elements' => []]);
        }

        if (++$posts === 1) {
            Kit::connectionFailure('cURL error 56: Recv failure: Connection reset by peer')($r);
        }

        return Http::response(Kit::json('error_422_duplicate'), 422);
    }]);

    dispatch(new PublishToConnectionJob(Platform::LinkedIn, new SocialPost([], 'Ship it'), Credentials::make(Kit::credentials()), false, null, 0, 'corr', 'li-recovery-1'));

    Event::assertNotDispatched(PostFailed::class);
    Event::assertDispatched(PostPublished::class, fn (PostPublished $e) => $e->result->platformPostId === LR_DUPLICATE_URN);
    expect($posts)->toBe(2);
});

it('reports a failed verify run as possibly published, end to end', function () {
    Sleep::fake();
    config(['social.api.linkedin.verify_reads' => true]);
    Event::fake([PostFailed::class, PostPublished::class]);

    $posts = 0;
    Http::fake(['api.linkedin.com/rest/posts*' => function (Request $r) use (&$posts) {
        if ($r->method() === 'GET') {
            return Http::response(['elements' => []]);
        }

        if (++$posts === 1) {
            Kit::connectionFailure('cURL error 56: Recv failure: Connection reset by peer')($r);
        }

        return Http::response(Kit::json('error_422_input'), 422);
    }]);

    dispatch(new PublishToConnectionJob(Platform::LinkedIn, new SocialPost([], 'Ship it'), Credentials::make(Kit::credentials()), false, null, 0, 'corr', 'li-recovery-2'));

    Event::assertDispatched(PostFailed::class, fn (PostFailed $e) => $e->possiblyPublished === true);
    Event::assertNotDispatched(PostPublished::class);
});

// --- The create step's own deadline --------------------------------------------------------

it('gives the create step its own window when the media turns available at the deadline', function (string $phase, string $key, string $urn, string $fixture) {
    Http::fake(["api.linkedin.com/rest/{$key}s/*" => Kit::fixture($fixture)]);

    $media = $key === 'video' ? Media::video(Kit::file(200000, 'mp4')) : Media::document(Kit::file(500, 'pdf'));
    $create = Kit::driver()->resume(Kit::post([$media]), ['phase' => $phase, $key => $urn, 'commentary' => 'Hello LinkedIn', 'deadline' => time()]);

    expect($create)->toBeInstanceOf(Pending::class)
        ->and($create->commits)->toBeTrue()
        ->and($create->state['phase'])->toBe('create_post')
        ->and($create->deadline)->toBeGreaterThanOrEqual(time() + 60);
})->with([
    'video' => ['await_video', 'video', Kit::VIDEO, 'video_status_available'],
    'document' => ['await_document', 'document', Kit::DOCUMENT, 'document_status_available'],
]);

it('never shortens the processing deadline for the create step', function () {
    Http::fake(['api.linkedin.com/rest/videos/*' => Kit::fixture('video_status_available')]);
    $deadline = time() + 3000;

    $create = Kit::driver()->resume(Kit::post([Media::video(Kit::file(200000, 'mp4'))]), ['phase' => 'await_video', 'video' => Kit::VIDEO, 'deadline' => $deadline]);

    expect($create->deadline)->toBe($deadline);
});

it('gives the verify step after a lost create its own window', function () {
    config(['social.api.linkedin.verify_reads' => true]);
    Http::fake(['api.linkedin.com/rest/posts' => Kit::connectionFailure('cURL error 56: Recv failure: Connection reset by peer')]);

    $verify = Kit::driver()->resume(Kit::post(), ['phase' => 'create_post', 'commentary' => 'Hello LinkedIn', 'content' => null, 'since' => time() - 5]);

    expect($verify->state['verify'])->toBeTrue()
        ->and($verify->deadline)->toBeGreaterThanOrEqual(time() + 60);
});

it('creates a video post that turned available on the last look before the deadline', function () {
    Queue::fake();
    Event::fake([PostFailed::class, PostPublished::class]);
    config(['queue.default' => 'redis']);
    Http::fake(['api.linkedin.com/rest/videos/*' => Kit::fixture('video_status_available')]);

    $deadline = time();
    $post = new SocialPost([Media::video(Kit::file(200000, 'mp4'))], 'Video');
    $job = new PublishToConnectionJob(Platform::LinkedIn, $post, Credentials::make(Kit::credentials()), true,
        ['phase' => 'await_video', 'video' => Kit::VIDEO, 'commentary' => 'Video', 'deadline' => $deadline], 200, 'corr', 'li-recovery-3');
    $job->deadline = $deadline;
    $job->startedAt = $deadline - 3700;

    $job->handle(app(SocialManager::class), app('events'), app(IdempotencyStore::class));

    Event::assertNotDispatched(PostFailed::class);
    Queue::assertPushed(PublishToConnectionJob::class, fn (PublishToConnectionJob $next) => ($next->state['phase'] ?? null) === 'create_post' && $next->commits);
});

// --- Staged video upload: progress on every run ---------------------------------------------

it('sends at least one part per run even when the run budget is already spent', function () {
    Http::fake([
        'api.linkedin.com/rest/videos?action=initializeUpload' => Kit::fixture('video_init'),
        'www.linkedin.com/dms-uploads/*' => fn (Request $r) => Http::response('', 200, ['ETag' => 'etag-'.substr($r->url(), -1)]),
        'api.linkedin.com/rest/videos?action=finalizeUpload' => Http::response('', 200),
    ]);

    $driver = Kit::driver();
    $post = Kit::post([Media::video(Kit::file(200000, 'mp4'))]);

    // Resolving the media (a slow download) used the whole upload_run_seconds budget.
    $driver->partBudget = 0;
    $first = $driver->publish($post);

    $driver->partBudget = 0;
    $second = $driver->resume($post, $first->state);

    expect($first->state['next'])->toBe(1)
        ->and($second->state['next'])->toBe(2)
        ->and(Kit::sent('dms-uploads', 'PUT'))->toBe(2);
});

// --- Link cards: bounded reads ---------------------------------------------------------------

it('reads at most a megabyte of a link card page', function () {
    $produced = 0;
    $head = '<html><head><meta property="og:title" content="Big page"></head><body>';
    Http::fake(['example.com/*' => Http::response(lrBigBody($produced, $head), 200, ['Content-Type' => 'text/html; charset=utf-8'])]);

    $pending = Kit::driver()->publish(Kit::post(caption: 'Read https://example.com/big', options: new LinkedInOptions(autoLinkCard: true)));

    expect($pending->state['content'])->toBe(['article' => ['source' => 'https://example.com/big', 'title' => 'Big page']])
        ->and($produced)->toBeLessThan(1_200_000);
});

it('never reads the body of a linked file that is not a web page', function () {
    $produced = 0;
    Http::fake(['example.com/*' => Http::response(lrBigBody($produced, '%PDF-1.7'), 200, ['Content-Type' => 'application/pdf'])]);

    $pending = Kit::driver()->publish(Kit::post(caption: 'Download https://example.com/report.pdf', options: new LinkedInOptions(autoLinkCard: true)));

    expect($pending->state['content'])->toBeNull()
        ->and($produced)->toBe(0);
});

it('stops reading a card thumbnail past link_card_image_max_bytes', function () {
    config(['social.api.linkedin.link_card_image_max_bytes' => 1000]);
    $produced = 0;
    Http::fake(['cdn.example.com/*' => Http::response(lrBigBody($produced), 200, ['Content-Type' => 'image/png'])]);

    expect(Kit::driver()->call('fetchRemoteImage', 'https://cdn.example.com/huge.png'))->toBeNull()
        ->and($produced)->toBeLessThan(100_000);
});

it('refuses a card thumbnail that announces a size over the cap without reading it', function () {
    config(['social.api.linkedin.link_card_image_max_bytes' => 1000]);
    $produced = 0;
    Http::fake(['cdn.example.com/*' => Http::response(lrBigBody($produced), 200, ['Content-Type' => 'image/png', 'Content-Length' => '5000000'])]);

    expect(Kit::driver()->call('fetchRemoteImage', 'https://cdn.example.com/huge.png'))->toBeNull()
        ->and($produced)->toBe(0);
});

it('still returns a card thumbnail within the cap', function () {
    Http::fake(['cdn.example.com/*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);

    expect(Kit::driver()->call('fetchRemoteImage', 'https://cdn.example.com/ok.png'))->toBe('PNGDATA');
});

it('aborts card downloads on the wire: wrong type or announced size at the headers, too many bytes while receiving', function () {
    $driver = Kit::driver();
    $page = $driver->call('cardFetchOptions', ['text/html', 'application/xhtml+xml'], 4_000_000);
    $image = $driver->call('cardFetchOptions', ['image/'], 1000);

    $headers = fn (array $options, array $h) => Kit::catch(function () use ($options, $h) {
        $options['on_headers'](new PsrResponse(200, $h));

        throw new RuntimeException('accepted');
    })->getMessage();

    $progress = fn (array $options, int $total, int $downloaded) => Kit::catch(function () use ($options, $total, $downloaded) {
        $options['progress']($total, $downloaded, 0, 0);

        throw new RuntimeException('accepted');
    })->getMessage();

    expect($headers($page, ['Content-Type' => 'text/html; charset=utf-8']))->toBe('accepted')
        ->and($headers($page, []))->toBe('accepted')
        ->and($headers($page, ['Content-Type' => 'application/pdf']))->not->toBe('accepted')
        ->and($headers($image, ['Content-Type' => 'image/png', 'Content-Length' => '900']))->toBe('accepted')
        ->and($headers($image, ['Content-Type' => 'image/png', 'Content-Length' => '1001']))->not->toBe('accepted')
        ->and($headers($image, ['Content-Type' => 'text/html']))->not->toBe('accepted')
        ->and($progress($image, 0, 500))->toBe('accepted')
        ->and($progress($image, 0, 1001))->not->toBe('accepted')
        ->and($progress($page, 0, 3_000_000))->toBe('accepted')
        ->and($progress($page, 0, 4_000_001))->not->toBe('accepted')
        ->and($page['allow_redirects']['max'])->toBe(3);
});
