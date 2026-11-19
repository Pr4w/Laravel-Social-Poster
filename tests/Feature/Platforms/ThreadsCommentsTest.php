<?php

namespace SocialPoster\Tests\Feature\Platforms\ThreadsCommentsTest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use SocialPoster\Capabilities\CommentRules;
use SocialPoster\Contracts\SupportsFirstComment;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\CommentSkipped;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\Threads\ThreadsOptions;
use SocialPoster\Support\Text\ThreadsCounter;
use SocialPoster\Tests\ThreadsFixtures\ThreadsKit as K;
use SocialPoster\ValueObjects\CommentResult;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;

require_once __DIR__.'/../../Fixtures/threads/ThreadsKit.php';

beforeEach(function () {
    Sleep::fake();
});

function parentPost(?string $id = '17890001', ?string $state = PostResult::STATE_PUBLISHED): PostResult
{
    return PostResult::success(Platform::Threads, $id, 'https://www.threads.net/@acme/post/X', state: $state);
}

it('implements the first-comment contract', function () {
    expect(K::driver())->toBeInstanceOf(SupportsFirstComment::class);
});

it('declares the reply rules', function () {
    $rules = K::driver()->commentRules(K::post(), parentPost());

    expect($rules)->toBeInstanceOf(CommentRules::class)
        ->and($rules->max)->toBe(500)
        ->and($rules->counter)->toBeInstanceOf(ThreadsCounter::class)
        ->and($rules->minDelaySeconds)->toBe(0)
        ->and($rules->requiredScopes)->toBe([['threads_manage_replies']])
        ->and($rules->unknownScopePolicy)->toBe(CommentRules::SKIP)
        ->and(K::driver()->commentRules(K::post()))->toBeInstanceOf(CommentRules::class);
});

it('skips the reply when scopes are unknown or missing, as the app does', function () {
    $rules = K::driver()->commentRules(K::post());

    expect($rules->skipReason(new Credentials(['access_token' => 't'])))->toBe(CommentSkipped::REASON_MISSING_SCOPE)
        ->and($rules->skipReason(new Credentials(['scopes' => ['threads_basic', 'threads_content_publish']])))->toBe(CommentSkipped::REASON_MISSING_SCOPE)
        ->and($rules->skipReason(new Credentials(['scopes' => ['threads_manage_replies']])))->toBeNull();
});

it('can be told to attempt replies with unknown scopes', function () {
    config(['social.api.threads.comment_unknown_scopes' => 'attempt']);

    expect(K::driver()->commentRules(K::post())->unknownScopePolicy)->toBe(CommentRules::ATTEMPT);

    config(['social.api.threads.comment_unknown_scopes' => 'nonsense']);

    expect(K::driver()->commentRules(K::post())->unknownScopePolicy)->toBe(CommentRules::SKIP);
});

it('counts the reply like a post', function () {
    $rules = K::driver()->commentRules(K::post());

    expect($rules->fits(str_repeat('👍', 125)))->toBeTrue()
        ->and($rules->fits(str_repeat('👍', 126)))->toBeFalse();
});

it('takes no reply on a post that is not live', function () {
    expect(K::driver()->commentRules(K::post(), parentPost(state: PostResult::STATE_PROCESSING)))->toBeNull();
});

it('posts the reply as a TEXT container, checks it, then publishes it', function () {
    K::fake([
        'POST 1784/threads' => [['id' => 'R1']],
        'GET R1' => [['status' => 'FINISHED', 'id' => 'R1']],
        'POST 1784/threads_publish' => [['id' => '17890099']],
        'GET 17890099' => [['permalink' => 'https://www.threads.net/@acme/post/R']],
    ]);

    $result = K::driver()->addFirstComment(K::post(), parentPost(), '  First!  ');

    expect($result)->toBeInstanceOf(CommentResult::class)
        ->and($result->id)->toBe('17890099')
        ->and($result->url)->toBe('https://www.threads.net/@acme/post/R')
        ->and($result->payload)->toBe(['container_id' => 'R1', 'response' => ['id' => '17890099']]);

    expect(K::sent('POST 1784/threads')[0]->data())->toBe([
        'media_type' => 'TEXT',
        'text' => 'First!',
        'reply_to_id' => '17890001',
        'access_token' => K::TOKEN,
    ])->and(K::sent('POST 1784/threads_publish')[0]->data())->toBe(['creation_id' => 'R1', 'access_token' => K::TOKEN])
        ->and(K::routes())->toBe(['POST 1784/threads', 'GET R1', 'POST 1784/threads_publish', 'GET 17890099']);

    Sleep::assertNeverSlept();
});

it('never sends the post options or extra with the reply', function () {
    K::fake([
        'POST 1784/threads' => [['id' => 'R1']],
        'GET R1' => [['status' => 'FINISHED']],
        'POST 1784/threads_publish' => [['id' => '1']],
        'GET 1' => [['permalink' => 'https://t/1']],
    ]);

    $post = K::post(options: new ThreadsOptions(madeWithAi: true, extra: ['topic_tag' => 'x']));
    K::driver()->addFirstComment($post, parentPost(), 'Hi');

    expect(K::sent('POST 1784/threads')[0]->data())->toBe(['media_type' => 'TEXT', 'text' => 'Hi', 'reply_to_id' => '17890001', 'access_token' => K::TOKEN]);
});

it('waits 2 seconds between status reads while the reply is processing', function () {
    K::fake([
        'POST 1784/threads' => [['id' => 'R1']],
        'GET R1' => [['status' => 'IN_PROGRESS'], ['status' => 'IN_PROGRESS'], ['status' => 'FINISHED']],
        'POST 1784/threads_publish' => [['id' => '2']],
        'GET 2' => [['permalink' => 'https://t/2']],
    ]);

    expect(K::driver()->addFirstComment(K::post(), parentPost(), 'Hi')->id)->toBe('2');

    Sleep::assertSleptTimes(2);
    expect(K::sent('GET R1'))->toHaveCount(3);
});

it('retries with a new container when the reply is still processing after 5 reads', function () {
    K::fake([
        'POST 1784/threads' => [['id' => 'R1']],
        'GET R1' => [['status' => 'IN_PROGRESS']],
    ]);

    $e = K::catch(fn () => K::driver()->addFirstComment(K::post(), parentPost(), 'Hi'));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->retryAfter)->toBe(30)
        ->and($e->context['container_id'])->toBe('R1')
        ->and(K::sent('GET R1'))->toHaveCount(5);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'threads_publish'));
    Sleep::assertSleptTimes(4);
});

it('retries when the reply container expired', function () {
    K::fake(['POST 1784/threads' => [['id' => 'R1']], 'GET R1' => [['status' => 'EXPIRED']]]);

    $e = K::catch(fn () => K::driver()->addFirstComment(K::post(), parentPost(), 'Hi'));

    expect($e)->toBeInstanceOf(TemporaryException::class)->and($e->context['container_status'])->toBe('EXPIRED');
});

it('fails the reply when its container is in ERROR', function () {
    K::fake(['POST 1784/threads' => [['id' => 'R1']], 'GET R1' => [['status' => 'ERROR', 'error_message' => 'UNKNOWN']]]);

    $e = K::catch(fn () => K::driver()->addFirstComment(K::post(), parentPost(), 'Hi'));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain('reply');
});

it('publishes anyway when the reply status cannot be read', function () {
    K::fake([
        'POST 1784/threads' => [['id' => 'R1']],
        'GET R1' => [['id' => 'R1'], K::error('unexpected-error', 500), ['id' => 'R1']],
        'POST 1784/threads_publish' => [['id' => '3']],
        'GET 3' => [['permalink' => 'https://t/3']],
    ]);

    expect(K::driver()->addFirstComment(K::post(), parentPost(), 'Hi')->id)->toBe('3')
        ->and(K::sent('GET R1'))->toHaveCount(5);
});

it('stops on a permanent status read failure', function () {
    K::fake(['POST 1784/threads' => [['id' => 'R1']], 'GET R1' => [K::error('token-session-expired-463')]]);

    expect(K::catch(fn () => K::driver()->addFirstComment(K::post(), parentPost(), 'Hi'))->reason)->toBe(FailureReason::InvalidToken);
});

it('honours the configured reply polling', function () {
    config(['social.api.threads.reply_status_polls' => 2, 'social.api.threads.reply_status_interval' => 0]);
    K::fake(['POST 1784/threads' => [['id' => 'R1']], 'GET R1' => [['status' => 'IN_PROGRESS']]]);

    K::catch(fn () => K::driver()->addFirstComment(K::post(), parentPost(), 'Hi'));

    expect(K::sent('GET R1'))->toHaveCount(2);
    Sleep::assertNeverSlept();
});

it('recovers a reply whose publish answer was lost', function () {
    K::fake([
        'POST 1784/threads' => [['id' => 'R1']],
        'GET R1' => [['status' => 'FINISHED'], ['status' => 'PUBLISHED']],
        'POST 1784/threads_publish' => [K::timeout()],
    ]);

    $result = K::driver()->addFirstComment(K::post(), parentPost(), 'Hi');

    expect($result->id)->toBeNull()
        ->and($result->payload)->toBe(['container_id' => 'R1', 'recovered' => true])
        ->and(K::sent('POST 1784/threads_publish'))->toHaveCount(1);
});

it('retries a lost reply publish when the container is still FINISHED after every read', function () {
    K::fake([
        'POST 1784/threads' => [['id' => 'R1']],
        'GET R1' => [['status' => 'FINISHED']],
        'POST 1784/threads_publish' => [K::timeout()],
    ]);

    $e = K::catch(fn () => K::driver()->addFirstComment(K::post(), parentPost(), 'Hi'));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::Network)
        ->and($e->context['connection'])->toBeTrue()
        // Verified unpublished: the comment job retries it as a clean attempt, not a recovery.
        ->and($e->context['possibly_published'])->toBeFalse()
        ->and($e->possiblyPublished())->toBeFalse()
        ->and($e->getMessage())->not->toContain(K::TOKEN)
        // One read before the publish, then 5 reads 2 s apart to verify it.
        ->and(K::sent('GET R1'))->toHaveCount(6)
        ->and(K::sent('POST 1784/threads_publish'))->toHaveCount(1);
    Sleep::assertSleptTimes(4);
});

it('never retries a reply whose lost publish cannot be verified', function (array $publish) {
    K::fake([
        'POST 1784/threads' => [['id' => 'R1']],
        'GET R1' => [['status' => 'FINISHED'], K::timeout()],
        'POST 1784/threads_publish' => $publish,
    ]);

    $e = K::catch(fn () => K::driver()->addFirstComment(K::post(), parentPost(), 'Hi'));

    // A blind retry would create and publish a second reply container.
    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->possiblyPublished())->toBeTrue()
        ->and($e->context['container_id'])->toBe('R1')
        ->and($e->getMessage())->not->toContain(K::TOKEN)
        ->and(K::sent('GET R1'))->toHaveCount(6)
        ->and(K::sent('POST 1784/threads_publish'))->toHaveCount(1);
})->with([
    'timed out' => [[K::timeout()]],
    'server error' => [[K::error('unexpected-error', 500)]],
]);

it('verifies the reply container when threads_publish fails in a way that may hide a publish', function (\Closure $refusal) {
    K::fake([
        'POST 1784/threads' => [['id' => 'R1']],
        'GET R1' => [['status' => 'FINISHED'], K::error('unexpected-error', 500), ['status' => 'PUBLISHED']],
        'POST 1784/threads_publish' => [$refusal],
    ]);

    $result = K::driver()->addFirstComment(K::post(), parentPost(), 'Hi');

    expect($result->id)->toBeNull()
        ->and($result->payload)->toBe(['container_id' => 'R1', 'recovered' => true])
        ->and(K::sent('GET R1'))->toHaveCount(3)
        ->and(K::sent('POST 1784/threads_publish'))->toHaveCount(1);
})->with([
    'HTTP 500 unexpected error' => [K::error('unexpected-error', 500)],
    'non-JSON 502' => [K::json('<html>Bad Gateway</html>', 502)],
    'transient code 1' => [K::error('unknown-error-code-1', 400)],
    'read timeout' => [K::timeout()],
]);

it('retries a reply whose failed publish provably did not go out', function () {
    K::fake([
        'POST 1784/threads' => [['id' => 'R1']],
        'GET R1' => [['status' => 'FINISHED']],
        'POST 1784/threads_publish' => [K::error('unexpected-error', 500)],
    ]);

    $e = K::catch(fn () => K::driver()->addFirstComment(K::post(), parentPost(), 'Hi'));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->retryAfter)->toBe(90)
        ->and($e->context['container_id'])->toBe('R1')
        ->and($e->context['possibly_published'])->toBeFalse()
        ->and(K::sent('GET R1'))->toHaveCount(6);
});

it('retries a reply publish that never left the client without reading the container again', function () {
    K::fake([
        'POST 1784/threads' => [['id' => 'R1']],
        'GET R1' => [['status' => 'FINISHED']],
        'POST 1784/threads_publish' => [K::refused()],
    ]);

    $e = K::catch(fn () => K::driver()->addFirstComment(K::post(), parentPost(), 'Hi'));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::Network)
        ->and($e->context['request_sent'])->toBeFalse()
        ->and(K::sent('GET R1'))->toHaveCount(1);
});

function recoveringParent(string $id = '17890001'): PostResult
{
    return PostResult::success(Platform::Threads, $id, 'https://www.threads.net/@acme/post/X', [Pending::RECOVERING => true], PostResult::STATE_PUBLISHED);
}

it('looks for its own reply in a recovery and returns it instead of replying again', function () {
    K::fake(['GET 1784/replies' => [['data' => [
        // The same text on another post (a reused first comment) is not this reply.
        ['id' => 'R8', 'text' => 'Read more: https://acme.test', 'replied_to' => ['id' => '17880000'], 'permalink' => 'https://www.threads.net/@acme/post/R8'],
        ['id' => 'R9', 'text' => "Read more: https://acme.test\n", 'replied_to' => ['id' => '17890001'], 'permalink' => 'https://www.threads.net/@acme/post/R9'],
    ]]]]);

    $result = K::driver()->addFirstComment(K::post(), recoveringParent(), 'Read more: https://acme.test');

    parse_str((string) parse_url(K::sent('GET 1784/replies')[0]->url(), PHP_URL_QUERY), $query);

    expect($result->id)->toBe('R9')
        ->and($result->url)->toBe('https://www.threads.net/@acme/post/R9')
        ->and($result->payload)->toMatchArray(['recovered' => true])
        ->and($query['fields'])->toContain('replied_to')
        ->and(K::routes())->toBe(['GET 1784/replies']);
});

it('replies in a recovery when the account has no such reply yet', function () {
    K::fake([
        'GET 1784/replies' => [['data' => [['id' => 'R8', 'text' => 'Hi', 'replied_to' => ['id' => '17880000']]]]],
        'POST 1784/threads' => [['id' => 'R1']],
        'GET R1' => [['status' => 'FINISHED']],
        'POST 1784/threads_publish' => [['id' => 'R2']],
        'GET R2' => [['permalink' => 'https://www.threads.net/@acme/post/R2']],
    ]);

    $result = K::driver()->addFirstComment(K::post(), recoveringParent(), 'Hi');

    expect($result->id)->toBe('R2')
        ->and($result->url)->toBe('https://www.threads.net/@acme/post/R2')
        ->and($result->payload)->not->toHaveKey('recovered')
        ->and(K::routes())->toBe(['GET 1784/replies', 'POST 1784/threads', 'GET R1', 'POST 1784/threads_publish', 'GET R2']);
});

it('never replies in a recovery it cannot check', function (\Closure $read, string $class, FailureReason $reason) {
    K::fake(['GET 1784/replies' => [$read]]);

    $e = K::catch(fn () => K::driver()->addFirstComment(K::post(), recoveringParent(), 'Hi'));

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason)
        ->and($e->getMessage())->not->toContain(K::TOKEN)
        ->and(K::sent('POST 1784/threads'))->toBe([]);

    if ($e instanceof PermanentException) {
        expect($e->possiblyPublished())->toBeTrue()
            ->and($e->context['recovering'])->toBeTrue();
    }
})->with([
    'replies not readable (no threads_read_replies)' => [K::error('permission-code-10', 403), PermanentException::class, FailureReason::OutcomeUnknown],
    'expired token' => [K::error('token-invalid-190', 401), PermanentException::class, FailureReason::OutcomeUnknown],
    'temporary error' => [K::error('unexpected-error', 500), TemporaryException::class, FailureReason::ServerError],
    'throttled' => [K::error('rate-limit-code-4', 400), TemporaryException::class, FailureReason::RateLimited],
]);

it('points a refused reply at threads_manage_replies', function (string $route) {
    K::fake([
        'POST 1784/threads' => [$route === 'create' ? K::error('permission-code-10', 403) : K::json(['id' => 'R1'])],
        'GET R1' => [['status' => 'FINISHED']],
        'POST 1784/threads_publish' => [K::error('permission-code-200')],
    ]);

    $e = K::catch(fn () => K::driver()->addFirstComment(K::post(), parentPost(), 'Hi'));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::InsufficientPermissions)
        ->and($e->context['required_scope'])->toBe('threads_manage_replies')
        ->and($e->getMessage())->toContain('threads_manage_replies');
})->with(['create', 'publish']);

it('retries the reply on 4279009 and throttles', function (string $fixture, int $retryAfter) {
    K::fake([
        'POST 1784/threads' => [['id' => 'R1']],
        'GET R1' => [['status' => 'FINISHED']],
        'POST 1784/threads_publish' => [K::error($fixture)],
    ]);

    $e = K::catch(fn () => K::driver()->addFirstComment(K::post(), parentPost(), 'Hi'));

    // An explicit refusal cannot hide a publish: no extra status read.
    expect($e)->toBeInstanceOf(TemporaryException::class)->and($e->retryAfter)->toBe($retryAfter)
        ->and(K::sent('GET R1'))->toHaveCount(1);
})->with([
    ['media-not-found-4279009', 30],
    ['rate-limit-code-4', 900],
    ['rate-limit-code-32', 300],
]);

it('reports an ambiguous reply publish without an id as possibly published', function () {
    K::fake([
        'POST 1784/threads' => [['id' => 'R1']],
        'GET R1' => [['status' => 'FINISHED']],
        'POST 1784/threads_publish' => [['success' => true]],
    ]);

    $e = K::catch(fn () => K::driver()->addFirstComment(K::post(), parentPost(), 'Hi'));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->possiblyPublished())->toBeTrue();
});

it('requires an id on the reply container', function () {
    K::fake(['POST 1784/threads' => [['id' => null]]]);

    expect(K::catch(fn () => K::driver()->addFirstComment(K::post(), parentPost(), 'Hi')))->toBeInstanceOf(TemporaryException::class);
});

it('refuses a reply without a parent id or text, before any request', function (?string $parentId, string $text, FailureReason $reason) {
    K::fake([]);

    $e = K::catch(fn () => K::driver()->addFirstComment(K::post(), parentPost($parentId), $text));

    expect($e)->toBeInstanceOf(PermanentException::class)->and($e->reason)->toBe($reason);
    Http::assertNothingSent();
})->with([
    'no parent id' => [null, 'Hi', FailureReason::NotFound],
    'blank parent id' => ['  ', 'Hi', FailureReason::NotFound],
    'blank text' => ['17890001', "  \n ", FailureReason::InvalidContent],
]);

it('checks credentials before replying', function () {
    K::fake([]);

    $e = K::catch(fn () => K::driver()->addFirstComment(K::post(credentials: ['access_token' => 't']), parentPost(), 'Hi'));

    expect($e->reason)->toBe(FailureReason::Misconfigured);
    Http::assertNothingSent();
});
