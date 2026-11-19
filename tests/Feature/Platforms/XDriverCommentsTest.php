<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SocialPoster\Capabilities\CommentRules;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\CommentSkipped;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\X\XOptions;
use SocialPoster\Platforms\X\XPoll;
use SocialPoster\Platforms\X\XReplySettings;
use SocialPoster\Support\Text\XWeightedCounter;
use SocialPoster\Tests\Fixtures\x\XFixtures;
use SocialPoster\Tests\Fixtures\x\XTestDriver;
use SocialPoster\ValueObjects\CommentResult;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\PostResult;

function xParent(?string $id = '1880028106020515840', ?string $state = PostResult::STATE_PUBLISHED): PostResult
{
    return PostResult::success(Platform::X, $id, $id !== null ? 'https://x.com/i/status/'.$id : null, state: $state);
}

it('declares the reply rules: 280 weighted, tweet scopes, attempted when scopes are unknown', function () {
    $rules = (new XTestDriver)->commentRules(XFixtures::post(), xParent());

    expect($rules)->toBeInstanceOf(CommentRules::class)
        ->and($rules->max)->toBe(280)
        ->and($rules->counter)->toBeInstanceOf(XWeightedCounter::class)
        ->and($rules->minDelaySeconds)->toBe(0)
        ->and($rules->requiredScopes)->toBe([['tweet.read'], ['tweet.write'], ['users.read']])
        ->and($rules->unknownScopePolicy)->toBe(CommentRules::ATTEMPT)
        ->and($rules->skipReason(new Credentials(['access_token' => 't'])))->toBeNull()
        ->and($rules->skipReason(new Credentials(['scopes' => 'tweet.read users.read'])))->toBe(CommentSkipped::REASON_MISSING_SCOPE)
        ->and($rules->skipReason(new Credentials(['scopes' => 'tweet.read tweet.write users.read'])))->toBeNull()
        ->and($rules->fits(str_repeat('😀', 140)))->toBeTrue()
        ->and($rules->fits(str_repeat('😀', 141)))->toBeFalse();
});

it('takes no comment on a scheduled, draft or failed parent', function () {
    $driver = new XTestDriver;

    expect($driver->commentRules(XFixtures::post()))->not->toBeNull()
        ->and($driver->commentRules(XFixtures::post(), xParent(state: PostResult::STATE_SCHEDULED)))->toBeNull()
        ->and($driver->commentRules(XFixtures::post(), xParent(state: PostResult::STATE_DRAFT)))->toBeNull()
        ->and($driver->commentRules(XFixtures::post(), PostResult::failed(Platform::X, new PermanentException('no'))))->toBeNull()
        ->and($driver->commentRules(XFixtures::post(), xParent(state: null)))->not->toBeNull();
});

it('reads the comment limit from config', function () {
    config(['social.api.x.max_characters' => 4000]);
    expect((new XTestDriver)->commentRules(XFixtures::post())->max)->toBe(4000);

    config(['social.api.x.max_comment_characters' => 500]);
    expect((new XTestDriver)->commentRules(XFixtures::post())->max)->toBe(500);
});

it('posts the first comment as a self-reply, without the post options', function () {
    Http::fake(['api.x.com/2/tweets' => Http::response(XFixtures::json('reply_created'), 201)]);
    $context = XFixtures::post(options: new XOptions(
        replySettings: XReplySettings::Following,
        poll: new XPoll(['A', 'B']),
        extra: ['nullcast' => true, 'reply' => ['exclude_reply_user_ids' => ['1']]],
    ));

    $result = (new XTestDriver)->addFirstComment($context, xParent(), "  First comment \n");

    expect($result)->toBeInstanceOf(CommentResult::class)
        ->and($result->id)->toBe('1880028106020515899')
        ->and($result->url)->toBe('https://x.com/i/status/1880028106020515899')
        ->and($result->payload['in_reply_to_tweet_id'])->toBe('1880028106020515840')
        ->and($result->payload['data']['text'])->toBe('First comment');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.x.com/2/tweets'
        && $request->data() === ['text' => 'First comment', 'reply' => ['in_reply_to_tweet_id' => '1880028106020515840']]
        && $request->hasHeader('Authorization', 'Bearer x-token'));
    Http::assertSentCount(1);
});

it('maps comment refusals like posts', function (string $fixture, int $status, string $class, FailureReason $reason) {
    Http::fake(['api.x.com/2/tweets' => Http::response(XFixtures::json($fixture), $status)]);

    expect(fn () => (new XTestDriver)->addFirstComment(XFixtures::post(), xParent(), 'Hi'))
        ->toThrow(fn (SocialPosterException $e) => expect($e)->toBeInstanceOf($class)
            ->and($e->reason)->toBe($reason));
})->with([
    'duplicate' => ['error_duplicate', 403, PermanentException::class, FailureReason::DuplicateContent],
    'rate limited' => ['error_too_many_requests', 429, TemporaryException::class, FailureReason::RateLimited],
    'server error' => ['error_service_unavailable', 503, TemporaryException::class, FailureReason::ServerError],
    'token rejected' => ['error_unauthorized', 401, PermanentException::class, FailureReason::InvalidToken],
    'reply restricted' => ['error_reply_restricted', 403, PermanentException::class, FailureReason::InvalidContent],
    'parent deleted' => ['error_reply_target_missing', 400, PermanentException::class, FailureReason::NotFound],
    'credits' => ['error_credits_depleted', 402, PermanentException::class, FailureReason::QuotaExceeded],
]);

it('never retries a reply that came back without an id', function () {
    Http::fake(['api.x.com/2/tweets' => Http::response(XFixtures::json('tweet_created_without_id'), 201)]);

    expect(fn () => (new XTestDriver)->addFirstComment(XFixtures::post(), xParent(), 'Hi'))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::OutcomeUnknown)
            ->and($e->possiblyPublished())->toBeTrue());
});

it('refuses to reply without a parent id, a token, or with an expired token', function (PostResult $parent, array $credentials, FailureReason $reason) {
    Http::fake();

    expect(fn () => (new XTestDriver)->addFirstComment(XFixtures::post(using: new Credentials($credentials)), $parent, 'Hi'))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe($reason));
    Http::assertNothingSent();
})->with([
    'no parent id' => [fn () => xParent(null), ['access_token' => 't'], FailureReason::NotFound],
    'no token' => [fn () => xParent(), ['user_id' => '1'], FailureReason::Misconfigured],
    'expired token' => [fn () => xParent(), ['access_token' => 't', 'expires_at' => time() - 1], FailureReason::InvalidToken],
]);
