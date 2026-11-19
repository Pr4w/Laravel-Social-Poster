<?php

namespace SocialPoster\Tests\Feature\Platforms;

use const SocialPoster\Tests\Fixtures\Facebook\FB_PAGE;
use const SocialPoster\Tests\Fixtures\Facebook\FB_TOKEN;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SocialPoster\Capabilities\CommentRules;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\CommentSkipped;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\Facebook\FacebookOptions;
use SocialPoster\Support\Text\MbStrlenCounter;
use SocialPoster\ValueObjects\CommentResult;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\PostResult;

use function SocialPoster\Tests\Fixtures\Facebook\fbDriver;
use function SocialPoster\Tests\Fixtures\Facebook\fbFake;
use function SocialPoster\Tests\Fixtures\Facebook\fbFixture;
use function SocialPoster\Tests\Fixtures\Facebook\fbPost;
use function SocialPoster\Tests\Fixtures\Facebook\fbQuery;

require_once __DIR__.'/../../Fixtures/facebook/helpers.php';

const FB_PARENT = '1029384756_555';

beforeEach(fn () => config(['social.api.facebook.graph_version' => 'v25.0']));

it('allows 8000 characters with both comment scopes on a feed post', function () {
    $rules = fbDriver()->commentRules(fbPost(), PostResult::success(Platform::Facebook, FB_PARENT));

    expect($rules)->toBeInstanceOf(CommentRules::class)
        ->and($rules->max)->toBe(8000)
        ->and($rules->counter)->toBeInstanceOf(MbStrlenCounter::class)
        ->and($rules->minDelaySeconds)->toBe(0)
        ->and($rules->unknownScopePolicy)->toBe(CommentRules::ATTEMPT)
        ->and($rules->requiredScopes)->toBe([['pages_manage_engagement'], ['pages_read_user_content']])
        ->and($rules->fits(str_repeat('é', 8000)))->toBeTrue()
        ->and($rules->fits(str_repeat('é', 8001)))->toBeFalse();
});

it('takes no comment on stories or scheduled posts', function () {
    $driver = fbDriver();

    expect($driver->commentRules(fbPost(options: new FacebookOptions(isStory: true))))->toBeNull()
        ->and($driver->commentRules(fbPost(options: new FacebookOptions(scheduledAt: CarbonImmutable::now()->addDay()))))->toBeNull()
        ->and($driver->commentRules(fbPost(options: new FacebookOptions(extra: ['published' => false, 'scheduled_publish_time' => time() + 86400]))))->toBeNull()
        ->and($driver->commentRules(fbPost(), PostResult::success(Platform::Facebook, FB_PARENT, state: PostResult::STATE_SCHEDULED)))->toBeNull()
        ->and($driver->commentRules(fbPost(), PostResult::success(Platform::Facebook, FB_PARENT, state: PostResult::STATE_DRAFT)))->toBeNull()
        ->and($driver->commentRules(fbPost()))->not->toBeNull();
});

it('skips the comment when the token is known to lack a comment scope', function () {
    $rules = fbDriver()->commentRules(fbPost());

    expect($rules->skipReason(new Credentials(['scopes' => ['pages_manage_posts', 'pages_manage_engagement']])))->toBe(CommentSkipped::REASON_MISSING_SCOPE)
        ->and($rules->skipReason(new Credentials(['scopes' => ['pages_manage_engagement', 'pages_read_user_content']])))->toBeNull()
        ->and($rules->skipReason(new Credentials))->toBeNull();
});

it('honours a configured comment limit', function () {
    config(['social.api.facebook.comment_max' => 5000]);

    expect(fbDriver()->commentRules(fbPost())->max)->toBe(5000);
});

it('comments on the parent post with the Page token and returns its id and link', function () {
    fbFake([
        'GET *v25.0/'.FB_PARENT.'/comments*' => Http::response(['data' => [['id' => '555_1', 'message' => 'Nice', 'from' => ['id' => '42']]]]),
        'POST *v25.0/'.FB_PARENT.'/comments' => Http::response(['id' => '555_901']),
        'GET *v25.0/555_901?fields=permalink_url*' => Http::response(['permalink_url' => '/'.FB_PAGE.'/posts/555?comment_id=901']),
    ]);

    $result = fbDriver()->addFirstComment(fbPost(), PostResult::success(Platform::Facebook, FB_PARENT), "  First!  \n");

    expect($result)->toBeInstanceOf(CommentResult::class)
        ->and($result->id)->toBe('555_901')
        ->and($result->url)->toBe('https://www.facebook.com/'.FB_PAGE.'/posts/555?comment_id=901')
        ->and($result->payload)->toBe(['id' => '555_901']);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://graph.facebook.com/v25.0/'.FB_PARENT.'/comments'
        && $request->hasHeader('Authorization', 'Bearer '.FB_TOKEN)
        && $request['message'] === 'First!');
});

it('returns the comment without a link when the permalink cannot be read', function () {
    fbFake([
        'POST *v25.0/7788/comments' => Http::response(['id' => '7788_1']),
        'GET *' => Http::response(fbFixture('permission_10'), 403),
    ]);

    $result = fbDriver()->addFirstComment(fbPost(), PostResult::success(Platform::Facebook, '7788', idKind: 'video_id'), 'On a video');

    expect($result->id)->toBe('7788_1')->and($result->url)->toBeNull();
});

it('reports comment failures through the Meta classifier', function (string $fixture, int $status, string $class, FailureReason $reason) {
    fbFake([
        'GET *v25.0/'.FB_PARENT.'/comments*' => Http::response(['data' => []]),
        'POST *v25.0/'.FB_PARENT.'/comments' => Http::response(fbFixture($fixture), $status),
    ]);

    expect(fn () => fbDriver()->addFirstComment(fbPost(), PostResult::success(Platform::Facebook, FB_PARENT), 'Hi'))
        ->toThrow(fn (SocialPosterException $e) => expect($e)->toBeInstanceOf($class)->and($e->reason)->toBe($reason));
})->with([
    'throttled' => ['throttle_app', 400, TemporaryException::class, FailureReason::RateLimited],
    'outage' => ['service_unavailable', 503, TemporaryException::class, FailureReason::ServerError],
    'user token on a Page' => ['permission_200', 403, PermanentException::class, FailureReason::InsufficientPermissions],
    'dead token' => ['token_password_changed', 400, PermanentException::class, FailureReason::InvalidToken],
]);

it('treats a comment answer without an id as possibly posted', function () {
    fbFake([
        'GET *v25.0/'.FB_PARENT.'/comments*' => Http::response(['data' => []]),
        'POST *v25.0/'.FB_PARENT.'/comments' => Http::response(['success' => true]),
    ]);

    expect(fn () => fbDriver()->addFirstComment(fbPost(), PostResult::success(Platform::Facebook, FB_PARENT), 'Hi'))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::OutcomeUnknown)->and($e->possiblyPublished())->toBeTrue());
});

it('refuses a blank parent id and missing credentials before any request', function () {
    fbFake([]);
    $driver = fbDriver();

    expect(fn () => $driver->addFirstComment(fbPost(), PostResult::success(Platform::Facebook, ' '), 'Hi'))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::NotFound))
        ->and(fn () => $driver->addFirstComment(fbPost(credentials: ['account_id' => FB_PAGE]), PostResult::success(Platform::Facebook, FB_PARENT), 'Hi'))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::Misconfigured));

    Http::assertNothingSent();
});

it('keeps the v1.0 comment() adapter', function () {
    fbFake([
        'POST *v25.0/'.FB_PARENT.'/comments' => Http::response(['id' => '555_902']),
        'GET *' => Http::response(['permalink_url' => 'https://www.facebook.com/c']),
    ]);

    expect(fbDriver()->comment(fbPost(), FB_PARENT, 'Legacy'))->toBe('555_902');
});

it('returns the comment an earlier attempt already added instead of posting it again', function () {
    fbFake([
        'GET *v25.0/'.FB_PARENT.'/comments*' => Http::response(['data' => [
            ['id' => '555_950', 'message' => 'Someone else', 'from' => ['id' => '42']],
            ['id' => '555_901', 'message' => "First!\r\n", 'from' => ['id' => FB_PAGE], 'created_time' => gmdate('Y-m-d\TH:i:sO')],
        ]]),
        'GET *v25.0/555_901?fields=permalink_url*' => Http::response(['permalink_url' => 'https://www.facebook.com/c/901']),
    ]);

    $result = fbDriver()->addFirstComment(fbPost(), PostResult::success(Platform::Facebook, FB_PARENT), ' First! ');

    expect($result->id)->toBe('555_901')
        ->and($result->url)->toBe('https://www.facebook.com/c/901')
        ->and($result->payload['recovered'])->toBeTrue();

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), FB_PARENT.'/comments?')
        && fbQuery($request)['order'] === 'reverse_chronological'
        && str_contains((string) fbQuery($request)['fields'], 'from'));
});

it('does not take another account\'s identical comment for its own', function () {
    fbFake([
        'GET *v25.0/'.FB_PARENT.'/comments*' => Http::response(['data' => [['id' => '555_950', 'message' => 'First!', 'from' => ['id' => '42']]]]),
        'POST *v25.0/'.FB_PARENT.'/comments' => Http::response(['id' => '555_901']),
        'GET *v25.0/555_901?fields=permalink_url*' => Http::response([], 400),
    ]);

    expect(fbDriver()->addFirstComment(fbPost(), PostResult::success(Platform::Facebook, FB_PARENT), 'First!')->id)->toBe('555_901');
});

it('waits for a throttled comment lookup rather than posting blind', function () {
    fbFake(['GET *v25.0/'.FB_PARENT.'/comments*' => Http::response(fbFixture('throttle_page'), 400)]);

    expect(fn () => fbDriver()->addFirstComment(fbPost(), PostResult::success(Platform::Facebook, FB_PARENT), 'First!'))
        ->toThrow(fn (TemporaryException $e) => expect($e->reason)->toBe(FailureReason::RateLimited));

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});
