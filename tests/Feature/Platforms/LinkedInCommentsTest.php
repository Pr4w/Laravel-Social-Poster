<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use SocialPoster\Capabilities\CommentRules;
use SocialPoster\Contracts\SupportsComments;
use SocialPoster\Contracts\SupportsFirstComment;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\CommentSkipped;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Tests\Fixtures\linkedin\LinkedInKit as Kit;
use SocialPoster\ValueObjects\CommentResult;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;

afterEach(function () {
    Carbon::setTestNow();
    Kit::cleanup();
});

function liParent(?string $urn = Kit::SHARE, ?string $state = null): PostResult
{
    return PostResult::success(Platform::LinkedIn, $urn, null, [], $state);
}

const LI_COMMENTS_URL = 'https://api.linkedin.com/rest/socialActions/urn%3Ali%3Ashare%3A7012345678901234567/comments';

it('implements both the v2 and the legacy comment contracts', function () {
    expect(Kit::driver())->toBeInstanceOf(SupportsFirstComment::class)
        ->toBeInstanceOf(SupportsComments::class);
});

it('declares the comment rules: 1,250 characters, a minute after the post, scopes by author type', function () {
    $person = Kit::driver()->commentRules(Kit::post());
    $page = Kit::driver()->commentRules(Kit::post(credentials: Kit::credentials(['author' => Kit::ORG])), liParent());

    expect($person)->toBeInstanceOf(CommentRules::class)
        ->and($person->max)->toBe(1250)
        ->and($person->minDelaySeconds)->toBe(60)
        ->and($person->unknownScopePolicy)->toBe(CommentRules::ATTEMPT)
        ->and($person->requiredScopes)->toBe([['w_member_social_feed', 'w_member_social']])
        ->and($page->requiredScopes)->toBe([['w_organization_social_feed', 'w_organization_social']])
        ->and(Kit::driver()->scopesFor(Feature::Comment))->toBe([['w_member_social_feed', 'w_member_social', 'w_organization_social_feed', 'w_organization_social']]);
});

it('skips the comment only when the listed scopes cannot cover it', function () {
    $rules = fn (string $scopes) => Kit::driver()->commentRules(Kit::post(credentials: Kit::credentials(['scopes' => $scopes])));

    expect($rules('w_member_social')->skipReason(new Credentials(['scopes' => 'w_member_social'])))->toBeNull()
        ->and($rules('openid profile')->skipReason(new Credentials(['scopes' => 'openid profile'])))->toBe(CommentSkipped::REASON_MISSING_SCOPE)
        ->and($rules('openid')->skipReason(new Credentials))->toBeNull();
});

it('takes no comment on a post that is not live', function (PostResult $parent) {
    expect(Kit::driver()->commentRules(Kit::post(), $parent))->toBeNull();
})->with([
    'scheduled' => fn () => liParent(state: PostResult::STATE_SCHEDULED),
    'draft' => fn () => liParent(state: PostResult::STATE_DRAFT),
    'failed' => fn () => PostResult::failed(Platform::LinkedIn, new PermanentException('x', Platform::LinkedIn)),
]);

it('reads the comment limits and delay from config', function () {
    config(['social.api.linkedin.comment_max_characters' => 500, 'social.api.linkedin.comment_min_delay' => 120]);

    $rules = Kit::driver()->commentRules(Kit::post());

    expect($rules->max)->toBe(500)
        ->and($rules->delay(10))->toBe(120);
});

it('comments as the author with plain, unescaped text', function () {
    Http::fake([LI_COMMENTS_URL => Http::response(Kit::json('comment_created'), 201, ['x-restli-id' => '7012399999999999999'])]);

    $result = Kit::driver()->addFirstComment(Kit::post(), liParent(), 'Thanks (all) @you #1 C# *really*');

    Http::assertSent(fn (Request $r) => $r->url() === LI_COMMENTS_URL
        && $r->method() === 'POST'
        && $r->hasHeader('LinkedIn-Version', '202605')
        && $r->hasHeader('X-Restli-Protocol-Version', '2.0.0')
        && $r->hasHeader('Authorization', 'Bearer '.Kit::TOKEN)
        && $r->data() === [
            'actor' => Kit::PERSON,
            'object' => Kit::SHARE,
            'message' => ['text' => 'Thanks (all) @you #1 C# *really*'],
        ]);

    $urn = 'urn:li:comment:(urn:li:activity:7012345678901234999,7012399999999999999)';

    expect($result)->toBeInstanceOf(CommentResult::class)
        ->and($result->id)->toBe('7012399999999999999')
        ->and($result->url)->toBe('https://www.linkedin.com/feed/update/'.Kit::SHARE.'/?commentUrn='.rawurlencode($urn))
        ->and($result->payload['comment_id'])->toBe('7012399999999999999')
        ->and($result->payload['comment_urn'])->toBe($urn)
        ->and($result->payload['response']['object'])->toBe(Kit::SHARE);
});

it('reads the comment id from the body when the header is missing, and the legacy adapter returns it', function () {
    Http::fake([LI_COMMENTS_URL => Http::response(Kit::json('comment_created'), 201)]);

    $id = Kit::driver()->comment(Kit::post(), Kit::SHARE, 'Hi');

    expect($id)->toBe('7012399999999999999');
});

it('falls back to the comment urn, and to no id at all, without failing', function () {
    Http::fake([LI_COMMENTS_URL => Http::sequence()
        ->push(['commentUrn' => 'urn:li:comment:(urn:li:activity:1,2)'], 201)
        ->push('', 201)]);

    $urnOnly = Kit::driver()->comment(Kit::post(), Kit::SHARE, 'Hi');
    $nothing = Kit::driver()->addFirstComment(Kit::post(), liParent(), 'Hi');

    expect($urnOnly)->toBe('urn:li:comment:(urn:li:activity:1,2)')
        ->and($nothing->id)->toBeNull()
        ->and($nothing->url)->toBeNull();
});

it('retries comment errors LinkedIn means as temporary', function (string $fixture, int $status, array $headers, FailureReason $reason, int $min, int $max) {
    Http::fake([LI_COMMENTS_URL => Http::response(Kit::json($fixture), $status, $headers)]);

    $e = Kit::catch(fn () => Kit::driver()->addFirstComment(Kit::post(), liParent(), 'Hi'));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe($reason)
        ->and($e->retryAfter)->toBeGreaterThanOrEqual($min)->toBeLessThanOrEqual($max)
        ->and($e->context['operation'])->toBe('comment');
})->with([
    'activity not propagated yet' => ['error_404_comment', 404, [], FailureReason::NotFound, 60, 60],
    'comment create throttled' => ['error_429_comment', 429, [], FailureReason::RateLimited, 60, 60],
    'long Retry-After is capped' => ['error_429_infra', 429, ['Retry-After' => '900'], FailureReason::RateLimited, 300, 300],
    'server error' => ['error_500', 500, [], FailureReason::ServerError, 0, PHP_INT_MAX],
]);

it('flags the not-yet-propagated 404', function () {
    Http::fake([LI_COMMENTS_URL => Http::response(Kit::json('error_404_comment'), 404)]);

    $e = Kit::catch(fn () => Kit::driver()->addFirstComment(Kit::post(), liParent(), 'Hi'));

    expect($e->context['activity_not_ready'])->toBeTrue();
});

it('gives up on a daily comment limit with its reset time', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-18 08:00:00', 'UTC'));
    Http::fake([LI_COMMENTS_URL => Http::response(Kit::json('error_429_day'), 429)]);

    $e = Kit::catch(fn () => Kit::driver()->addFirstComment(Kit::post(), liParent(), 'Hi'));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::RateLimited)
        ->and($e->context['retry_at'])->toBe('2026-11-19T00:00:00Z');
});

it('explains a refused page comment with the comment scopes and roles', function () {
    Http::fake([LI_COMMENTS_URL => Http::response(Kit::json('error_403_access_denied'), 403)]);

    $page = Kit::catch(fn () => Kit::driver()->addFirstComment(Kit::post(credentials: Kit::credentials(['author' => Kit::ORG])), liParent(), 'Hi'));
    $member = Kit::catch(fn () => Kit::driver()->addFirstComment(Kit::post(), liParent(), 'Hi'));

    expect($page->reason)->toBe(FailureReason::InsufficientPermissions)
        ->and($page->getMessage())->toContain('w_organization_social_feed')
        ->and($page->getMessage())->toContain('RECRUITING_POSTER')
        ->and($member->getMessage())->toContain('w_member_social_feed');
});

it('never retries a comment whose response was lost, but retries one that never left', function (string $message, string $class, ?FailureReason $reason) {
    Http::fake([LI_COMMENTS_URL => Kit::connectionFailure($message)]);

    $e = Kit::catch(fn () => Kit::driver()->addFirstComment(Kit::post(), liParent(), 'Hi'));

    expect($e)->toBeInstanceOf($class);

    if ($reason !== null) {
        expect($e->reason)->toBe($reason)
            ->and($e->possiblyPublished())->toBeTrue();
    } else {
        expect($e->context['request_sent'])->toBeFalse();
    }
})->with([
    'read timeout' => ['cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received', PermanentException::class, FailureReason::OutcomeUnknown],
    'dns failure' => ['cURL error 6: Could not resolve host: api.linkedin.com', TemporaryException::class, null],
]);

function liRecovering(): PostResult
{
    return PostResult::success(Platform::LinkedIn, Kit::SHARE, null, [Pending::RECOVERING => true], PostResult::STATE_PUBLISHED);
}

/** @return array<string, mixed> */
function liListedComment(string $actor, string $text, string $id): array
{
    return [
        'actor' => $actor,
        'commentUrn' => "urn:li:comment:(urn:li:activity:7012345678901234999,{$id})",
        'created' => ['actor' => $actor, 'time' => 1763452800000],
        'id' => $id,
        'message' => ['attributes' => [], 'text' => $text],
        'object' => 'urn:li:activity:7012345678901234999',
    ];
}

it('looks for its own comment in a recovery and returns it instead of commenting again', function () {
    Http::fake(fn (Request $request) => $request->method() === 'GET' && $request->url() === LI_COMMENTS_URL
        ? Http::response(['elements' => [
            // Someone else's identical comment is not this one.
            liListedComment('urn:li:person:someoneElse', 'Thanks for reading!', '7012300000000000001'),
            liListedComment(Kit::PERSON, "Thanks for reading!\n", '7012399999999999999'),
        ]], 200)
        : Http::response(['message' => 'unexpected'], 500));

    $result = Kit::driver()->addFirstComment(Kit::post(), liRecovering(), 'Thanks for reading!');
    $urn = 'urn:li:comment:(urn:li:activity:7012345678901234999,7012399999999999999)';

    expect($result->id)->toBe('7012399999999999999')
        ->and($result->url)->toBe('https://www.linkedin.com/feed/update/'.Kit::SHARE.'/?commentUrn='.rawurlencode($urn))
        ->and($result->payload)->toMatchArray(['recovered' => true, 'comment_urn' => $urn]);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('comments in a recovery when the earlier attempt left no comment', function () {
    Http::fake(fn (Request $request) => $request->method() === 'GET'
        ? Http::response(['elements' => [liListedComment(Kit::PERSON, 'Another comment', '7012300000000000002')]], 200)
        : Http::response(Kit::json('comment_created'), 201, ['x-restli-id' => '7012399999999999999']));

    $result = Kit::driver()->addFirstComment(Kit::post(), liRecovering(), 'Thanks for reading!');

    expect($result->id)->toBe('7012399999999999999')
        ->and($result->payload)->not->toHaveKey('recovered');

    Http::assertSentCount(2);
});

it('never comments in a recovery it cannot check', function (string $fixture, int $status, string $class, FailureReason $reason) {
    Http::fake(fn (Request $request) => $request->method() === 'GET'
        ? Http::response(Kit::json($fixture), $status)
        : Http::response(Kit::json('comment_created'), 201));

    $e = Kit::catch(fn () => Kit::driver()->addFirstComment(Kit::post(), liRecovering(), 'Hi'));

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason);

    if ($e instanceof PermanentException) {
        expect($e->possiblyPublished())->toBeTrue()
            ->and($e->context['recovering'])->toBeTrue()
            ->and($e->context['operation'])->toBe('comment');
    }

    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
})->with([
    'comments not readable (no r_*_social_feed)' => ['error_403_access_denied', 403, PermanentException::class, FailureReason::OutcomeUnknown],
    'server error' => ['error_500', 500, TemporaryException::class, FailureReason::ServerError],
]);

it('skips the recovery read, with no request, when the listed scopes cannot read comments', function (string $author, string $scopes, array $missing) {
    Http::fake();

    $post = Kit::post(credentials: Kit::credentials(['author' => $author, 'scopes' => $scopes]));
    $e = Kit::catch(fn () => Kit::driver()->addFirstComment($post, liRecovering(), 'Hi'));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->possiblyPublished())->toBeTrue()
        ->and($e->needsReconnect())->toBeFalse()
        ->and($e->context)->toMatchArray([
            'recovering' => true,
            'operation' => 'comment',
            'lookup_skipped' => true,
            'lookup_reason' => FailureReason::InsufficientPermissions->value,
            'missing_scopes' => $missing,
        ]);

    Http::assertNothingSent();
})->with([
    'member' => [Kit::PERSON, 'r_basicprofile w_member_social w_member_social_feed', [['r_member_social_feed', 'r_member_social']]],
    'page' => [Kit::ORG, 'rw_organization_admin,w_organization_social,w_organization_social_feed', [['r_organization_social_feed', 'r_organization_social']]],
]);

it('reads the comments in a recovery when the listed scopes can, or are unknown', function (array $scopes) {
    Http::fake(fn (Request $request) => $request->method() === 'GET' && $request->url() === LI_COMMENTS_URL
        ? Http::response(['elements' => [liListedComment(Kit::ORG, 'Hi', '7012399999999999999')]], 200)
        : Http::response(['message' => 'unexpected'], 500));

    $post = Kit::post(credentials: Kit::credentials(['author' => Kit::ORG] + $scopes));
    $result = Kit::driver()->addFirstComment($post, liRecovering(), 'Hi');

    expect($result->payload['recovered'] ?? null)->toBeTrue();

    Http::assertSentCount(1);
})->with([
    'r_organization_social_feed' => [['scopes' => 'w_organization_social_feed r_organization_social_feed']],
    'r_organization_social' => [['scopes' => ['w_organization_social', 'r_organization_social']]],
    'scopes not listed' => [[]],
]);

it('refuses to comment without a post urn', function () {
    Http::fake();

    $e = Kit::catch(fn () => Kit::driver()->addFirstComment(Kit::post(), liParent(' '), 'Hi'));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::NotFound);

    Http::assertNothingSent();
});

it('validates the first comment length before publishing', function () {
    $ok = Kit::driver()->validate(Kit::post(comment: str_repeat('a', 1250)));
    $long = Kit::driver()->validate(Kit::post(comment: str_repeat('é', 1251)));
    $blank = Kit::driver()->validate(Kit::post(comment: '   '));

    expect($ok->has('comment'))->toBeFalse()
        ->and($long->first('comment'))->toBe('Comment exceeds the 1250 character limit (currently 1251).')
        ->and($blank->has('comment'))->toBeFalse();
});
