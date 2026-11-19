<?php

namespace SocialPoster\Tests\Feature\Platforms;

use const SocialPoster\Tests\Fixtures\Instagram\FB_HOST;
use const SocialPoster\Tests\Fixtures\Instagram\IG_ACCOUNT;
use const SocialPoster\Tests\Fixtures\Instagram\IG_HOST;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SocialPoster\Capabilities\CommentRules;
use SocialPoster\Contracts\SupportsComments;
use SocialPoster\Contracts\SupportsFirstComment;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\CommentSkipped;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\Instagram\InstagramOptions;
use SocialPoster\Support\Text\MbStrlenCounter;
use SocialPoster\ValueObjects\CommentResult;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\PostResult;

use function SocialPoster\Tests\Fixtures\Instagram\igCatch;
use function SocialPoster\Tests\Fixtures\Instagram\igCreds;
use function SocialPoster\Tests\Fixtures\Instagram\igDriver;
use function SocialPoster\Tests\Fixtures\Instagram\igErr;
use function SocialPoster\Tests\Fixtures\Instagram\igFake;
use function SocialPoster\Tests\Fixtures\Instagram\igPost;
use function SocialPoster\Tests\Fixtures\Instagram\igRes;
use function SocialPoster\Tests\Fixtures\Instagram\igSent;
use function SocialPoster\Tests\Fixtures\Instagram\igTime;

require_once __DIR__.'/../../Fixtures/instagram/helpers.php';

beforeEach(function () {
    config(['social.api.instagram.graph_version' => 'v25.0']);
});

function igParent(?string $id = '17900000000000042', ?string $state = null, array $payload = ['media_type' => 'IMAGE']): PostResult
{
    return PostResult::success(Platform::Instagram, $id, null, $payload, $state);
}

it('implements both the 1.1 comment contract and the 1.0 one', function () {
    expect(igDriver())->toBeInstanceOf(SupportsFirstComment::class)
        ->and(igDriver())->toBeInstanceOf(SupportsComments::class);
});

it('allows a 2200-character first comment with the comment scope of the account login', function (array $credentials, array $scopes) {
    $rules = igDriver()->commentRules(igPost(credentials: igCreds($credentials)));

    expect($rules)->toBeInstanceOf(CommentRules::class)
        ->and($rules->max)->toBe(2200)
        ->and($rules->counter)->toBeInstanceOf(MbStrlenCounter::class)
        ->and($rules->minDelaySeconds)->toBe(0)
        ->and($rules->unknownScopePolicy)->toBe(CommentRules::ATTEMPT)
        ->and($rules->requiredScopes)->toBe($scopes);
})->with([
    'facebook login' => [['access_token' => 'EAAx'], [['instagram_manage_comments']]],
    'instagram login' => [['login' => 'instagram'], [['instagram_business_manage_comments']]],
    'unknown login' => [['access_token' => 'opaque'], [['instagram_manage_comments', 'instagram_business_manage_comments']]],
]);

it('reads the comment limit and delay from config', function () {
    config(['social.api.instagram.comment_max_length' => 1000, 'social.api.instagram.comment_min_delay' => 60]);

    $rules = igDriver()->commentRules(igPost(), igParent());

    expect($rules->max)->toBe(1000)->and($rules->minDelaySeconds)->toBe(60);
});

it('takes no first comment on stories or on posts that are not live', function (?InstagramOptions $options, ?PostResult $parent) {
    expect(igDriver()->commentRules(igPost(options: $options), $parent))->toBeNull();
})->with([
    'story' => [new InstagramOptions(isStory: true), null],
    'story parent' => [null, igParent(payload: ['media_type' => 'STORIES'])],
    'scheduled parent' => [null, igParent(state: PostResult::STATE_SCHEDULED)],
    'draft parent' => [null, igParent(state: PostResult::STATE_DRAFT)],
    'failed parent' => [null, PostResult::failed(Platform::Instagram, new PermanentException('x'))],
]);

it('lets the job skip a comment the token is known not to allow', function () {
    $missing = igDriver()->commentRules(igPost(credentials: igCreds(['scopes' => 'instagram_basic,instagram_content_publish'])));
    $granted = igDriver()->commentRules(igPost(credentials: igCreds(['scopes' => ['instagram_basic', 'instagram_manage_comments']])));
    $unknown = igDriver()->commentRules(igPost());

    expect($missing->skipReason(new Credentials(igCreds(['scopes' => 'instagram_basic']))))->toBe(CommentSkipped::REASON_MISSING_SCOPE)
        ->and($granted->skipReason(new Credentials(igCreds(['scopes' => ['instagram_manage_comments']]))))->toBeNull()
        ->and($unknown->skipReason(new Credentials(igCreds())))->toBeNull()
        ->and($unknown->errors(str_repeat('a', 2201)))->not->toBe([])
        ->and($unknown->fits(str_repeat('é', 2200)))->toBeTrue();
});

it('posts the first comment on the media with the user token', function () {
    igFake([
        'GET /17900000000000042/comments' => igRes(['data' => []]),
        'POST /17900000000000042/comments' => igRes(['id' => '17890000000000099']),
    ]);

    $result = igDriver()->addFirstComment(igPost(), igParent(), 'First! https://example.com');

    expect($result)->toBeInstanceOf(CommentResult::class)
        ->and($result->id)->toBe('17890000000000099')
        ->and($result->url)->toBeNull()
        ->and($result->payload)->toBe(['media_id' => '17900000000000042', 'response' => ['id' => '17890000000000099']]);

    $request = igSent('POST /17900000000000042/comments')[0];

    expect($request->url())->toBe(FB_HOST.'/17900000000000042/comments')
        ->and($request->data())->toBe(['message' => 'First! https://example.com'])
        ->and($request->hasHeader('Authorization', 'Bearer EAAGtoken123'))->toBeTrue();
});

it('comments on graph.instagram.com for Instagram Login accounts', function () {
    igFake([
        'GET /17900000000000042/comments' => igRes(['data' => []]),
        'POST /17900000000000042/comments' => igRes(['id' => 'c-1']),
    ]);

    igDriver()->addFirstComment(igPost(credentials: igCreds(['access_token' => 'IGAAJtoken'])), igParent(), 'Hi');

    expect(igSent('POST /17900000000000042/comments')[0]->url())->toBe(IG_HOST.'/17900000000000042/comments')
        ->and(igSent('GET /17900000000000042/comments')[0]->url())->toStartWith(IG_HOST.'/17900000000000042/comments?');
});

it('keeps the 1.0 comment() adapter', function () {
    igFake(['GET /m1/comments' => igRes(['data' => []]), 'POST /m1/comments' => igRes(['id' => 'c-9'])]);

    expect(igDriver()->comment(igPost(), 'm1', 'Hello'))->toBe('c-9');
});

it('returns a null id when Graph accepts a comment without one', function () {
    igFake(['GET /m1/comments' => igRes(['data' => []]), 'POST /m1/comments' => igRes(['success' => true])]);

    $result = igDriver()->addFirstComment(igPost(), igParent('m1'), 'Hello');

    expect($result->id)->toBeNull()->and($result->payload['response'])->toBe(['success' => true]);
});

it('classifies comment failures with the shared Meta table', function (string $fixture, string $class, FailureReason $reason) {
    igFake(['GET /m1/comments' => igRes(['data' => []]), 'POST /m1/comments' => igErr($fixture)]);

    $e = igCatch(fn () => igDriver()->addFirstComment(igPost(), igParent('m1'), 'Hello'));

    expect($e)->toBeInstanceOf($class)->and($e->reason)->toBe($reason);
})->with([
    'app throttle' => ['app_throttle', TemporaryException::class, FailureReason::RateLimited],
    'user throttle' => ['user_throttle', TemporaryException::class, FailureReason::RateLimited],
    'outage' => ['graph_503', TemporaryException::class, FailureReason::ServerError],
    'missing comment permission' => ['comment_permission', PermanentException::class, FailureReason::InsufficientPermissions],
    'dead token' => ['password_changed', PermanentException::class, FailureReason::InvalidToken],
    'spam block' => ['spam_block', PermanentException::class, FailureReason::AccountRestricted],
]);

it('lets a dropped connection reach the comment job', function () {
    igFake(['GET /m1/comments' => igRes(['data' => []]), 'POST /m1/comments' => Http::failedConnection()]);

    expect(igCatch(fn () => igDriver()->addFirstComment(igPost(), igParent('m1'), 'Hello')))->toBeInstanceOf(ConnectionException::class);
});

it('refuses to comment without a media id or credentials, before any request', function (?string $id, array $credentials, FailureReason $reason) {
    Http::fake();

    $e = igCatch(fn () => igDriver()->addFirstComment(igPost(credentials: $credentials), igParent($id), 'Hello'));

    expect($e)->toBeInstanceOf(PermanentException::class)->and($e->reason)->toBe($reason);
    Http::assertNothingSent();
})->with([
    'no media id' => [null, igCreds(), FailureReason::NotFound],
    'blank media id' => ['  ', igCreds(), FailureReason::NotFound],
    'no token' => ['m1', ['account_id' => '1'], FailureReason::Misconfigured],
]);

// --- Duplicate protection: an earlier attempt's comment is found, not posted again ---

it('returns the comment an earlier attempt already added instead of posting it again', function () {
    igFake(['GET /17900000000000042/comments' => igRes(['data' => [
        ['id' => '17890000000000050', 'text' => 'First! https://example.com', 'timestamp' => igTime(5), 'from' => ['id' => '17841499999999999', 'username' => 'a.fan']],
        ['id' => '17890000000000099', 'text' => "First! https://example.com\r\n", 'timestamp' => igTime(40), 'from' => ['id' => IG_ACCOUNT, 'username' => 'brand'], 'user' => ['id' => IG_ACCOUNT]],
    ]])]);

    $result = igDriver()->addFirstComment(igPost(), igParent(), ' First! https://example.com ');

    expect($result->id)->toBe('17890000000000099')
        ->and($result->url)->toBeNull()
        ->and($result->payload)->toBe(['media_id' => '17900000000000042', 'recovered' => true]);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');

    $lookup = igSent('GET /17900000000000042/comments')[0];
    parse_str((string) parse_url($lookup->url(), PHP_URL_QUERY), $query);

    expect($lookup->url())->toStartWith(FB_HOST.'/17900000000000042/comments?')
        ->and(explode(',', (string) $query['fields']))->toContain('id', 'text', 'from', 'user')
        ->and((int) $query['limit'])->toBe(25)
        ->and($lookup->hasHeader('Authorization', 'Bearer EAAGtoken123'))->toBeTrue();
});

it('recognises its own comment by the user field, the author id, or text alone when no author is shown', function (array $author) {
    igFake(['GET /m1/comments' => igRes(['data' => [['id' => 'c-own', 'text' => 'Link in bio', 'timestamp' => igTime(10)] + $author]])]);

    $result = igDriver()->addFirstComment(igPost(), igParent('m1'), 'Link in bio');

    expect($result->id)->toBe('c-own')->and($result->payload['recovered'])->toBeTrue();
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
})->with([
    // Graph returns `user` only for the app user's own comments; from.id is an IGSID.
    'user object, IGSID author' => [['user' => ['id' => IG_ACCOUNT], 'from' => ['id' => '9876543210', 'username' => 'brand']]],
    'user as a bare id' => [['user' => IG_ACCOUNT]],
    'user of another id kind' => [['user' => ['id' => '9876543210'], 'from' => ['id' => '9876543210']]],
    'author id' => [['from' => ['id' => IG_ACCOUNT, 'username' => 'brand']]],
    'no author shown' => [[]],
]);

it('does not take another account\'s identical comment for its own', function () {
    igFake([
        'GET /m1/comments' => igRes(['data' => [
            ['id' => 'c-fan', 'text' => 'Link in bio', 'timestamp' => igTime(3), 'from' => ['id' => '17841499999999999', 'username' => 'a.fan']],
            ['id' => 'c-other', 'text' => 'Link in bio', 'username' => 'another.fan'],
            ['id' => 'c-own-other-text', 'text' => 'Something else', 'user' => ['id' => IG_ACCOUNT]],
        ]]),
        'POST /m1/comments' => igRes(['id' => 'c-new']),
    ]);

    $result = igDriver()->addFirstComment(igPost(), igParent('m1'), 'Link in bio');

    expect($result->id)->toBe('c-new')
        ->and($result->payload)->not->toHaveKey('recovered')
        ->and(igSent('POST /m1/comments'))->toHaveCount(1);
});

it('looks before it posts, and posts when the lookup finds nothing', function (mixed $listing) {
    igFake([
        'GET /m1/comments' => igRes($listing),
        'POST /m1/comments' => igRes(['id' => 'c-new']),
    ]);

    expect(igDriver()->addFirstComment(igPost(), igParent('m1'), 'Hello')->id)->toBe('c-new');

    $sent = Http::recorded()->map(fn (array $pair) => $pair[0]->method())->values()->all();

    expect($sent)->toBe(['GET', 'POST']);
})->with([
    'no comments' => [['data' => []]],
    'no data key' => [['id' => 'm1']],
    'malformed items' => [['data' => ['oops', ['text' => 'Hello'], ['id' => '', 'text' => 'Hello']]]],
]);

it('waits for a throttled or failing lookup rather than commenting blind', function (string $fixture, FailureReason $reason) {
    igFake(['GET /m1/comments' => igErr($fixture)]);

    $e = igCatch(fn () => igDriver()->addFirstComment(igPost(), igParent('m1'), 'Hello'));

    expect($e)->toBeInstanceOf(TemporaryException::class)->and($e->reason)->toBe($reason);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
})->with([
    'app throttle' => ['app_throttle', FailureReason::RateLimited],
    'outage' => ['graph_503', FailureReason::ServerError],
    'html 502' => ['html_502', FailureReason::ServerError],
]);

it('lets a dropped lookup connection reach the comment job without commenting', function () {
    igFake(['GET /m1/comments' => Http::failedConnection()]);

    expect(igCatch(fn () => igDriver()->addFirstComment(igPost(), igParent('m1'), 'Hello')))->toBeInstanceOf(ConnectionException::class);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('still comments when the lookup is refused, as before the lookup existed', function (string $fixture) {
    igFake([
        'GET /m1/comments' => igErr($fixture),
        'POST /m1/comments' => igRes(['id' => 'c-new']),
    ]);

    expect(igDriver()->addFirstComment(igPost(), igParent('m1'), 'Hello')->id)->toBe('c-new');
})->with(['invalid_parameter', 'object_missing', 'permission']);
