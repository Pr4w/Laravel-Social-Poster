<?php

namespace SocialPoster\Tests\Unit\Concerns;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Concerns\TalksToMetaGraph;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\PublishOutcome;
use SocialPoster\ValueObjects\SocialPost;
use Throwable;

/** A Meta driver whose Instagram Login accounts live on another host. */
class TalksToMetaGraphTestDriver extends AbstractPlatform
{
    use TalksToMetaGraph;

    public string $statusField = 'status';

    public function platform(): Platform
    {
        return Platform::Threads;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(new CaptionRules(0, 500), new MediaRules(0, 10));
    }

    public function publish(PreparedPost $post): PublishOutcome
    {
        throw new RuntimeException('not used');
    }

    public function call(string $method, mixed ...$args): mixed
    {
        return $this->{$method}(...$args);
    }

    protected function graphBase(): string
    {
        return 'https://graph.meta.test/v25.0';
    }

    protected function graphBaseFor(PreparedPost $post): string
    {
        return $post->credentials->get('login') === 'instagram'
            ? 'https://graph.instagram.test/v25.0'
            : $this->graphBase();
    }

    protected function containerStatusField(): string
    {
        return $this->statusField;
    }
}

function mgDriver(): TalksToMetaGraphTestDriver
{
    return new TalksToMetaGraphTestDriver;
}

/** @param array<string, mixed> $credentials */
function mgPost(array $credentials = ['account_id' => '1784', 'access_token' => 'tok']): PreparedPost
{
    return new PreparedPost(Platform::Threads, new SocialPost, new Credentials($credentials));
}

/**
 * A production-shaped Graph error response.
 *
 * @param  array<string, mixed>  $error
 * @param  array<string, string>  $headers
 */
function mgError(array $error, int $status = 400, array $headers = []): Response
{
    $body = json_encode(['error' => $error + ['type' => 'OAuthException', 'fbtrace_id' => 'AbCdEf123']]);

    return new Response(new PsrResponse($status, $headers + ['Content-Type' => 'application/json'], $body));
}

function mgCatch(callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('Expected an exception.');
}

// --- classifyMetaError ----------------------------------------------------------

it('classifies Graph errors with the shared Meta table', function (array $error, int $status, string $class, FailureReason $reason, ?int $retryAfter, array $context) {
    $e = mgDriver()->call('classifyMetaError', mgError($error, $status));

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason);

    if ($e instanceof TemporaryException) {
        expect($e->retryAfter)->toBe($retryAfter);
    }

    foreach ($context as $key => $value) {
        expect($e->context)->toHaveKey($key, $value);
    }
})->with([
    '190 expired, no subcode' => [['message' => 'Error validating access token: Session has expired on Tuesday.', 'code' => 190], 400, PermanentException::class, FailureReason::InvalidToken, null, ['terminal' => false, 'reresolvable' => true, 'code' => 190]],
    '190/458 app removed' => [['message' => 'Error validating access token: The user has not authorized application 123.', 'code' => 190, 'error_subcode' => 458], 400, PermanentException::class, FailureReason::InvalidToken, null, ['terminal' => true, 'subcode' => 458]],
    '190/459 checkpoint' => [['message' => 'Error validating access token: You cannot access the app till you log in to www.facebook.com and follow the instructions given.', 'code' => 190, 'error_subcode' => 459], 400, PermanentException::class, FailureReason::AccountRestricted, null, ['terminal' => false]],
    '190/460 password changed' => [['message' => 'Error validating access token: The session has been invalidated because the user changed their password.', 'code' => 190, 'error_subcode' => 460], 400, PermanentException::class, FailureReason::InvalidToken, null, ['terminal' => true]],
    '190/463 expired' => [['message' => 'Error validating access token: Session has expired.', 'code' => 190, 'error_subcode' => 463], 400, PermanentException::class, FailureReason::InvalidToken, null, ['terminal' => true]],
    '190/464 unconfirmed' => [['message' => 'Error validating access token: Sessions for the user are not allowed because the user is not a confirmed user.', 'code' => 190, 'error_subcode' => 464], 400, PermanentException::class, FailureReason::AccountRestricted, null, ['terminal' => false]],
    '190/467 invalid' => [['message' => 'Invalid OAuth access token - Cannot parse access token', 'code' => 190, 'error_subcode' => 467], 400, PermanentException::class, FailureReason::InvalidToken, null, ['terminal' => true]],
    '190/492 lost page role' => [['message' => 'Error validating access token: The user is not an admin of the Page.', 'code' => 190, 'error_subcode' => 492], 400, PermanentException::class, FailureReason::InsufficientPermissions, null, ['terminal' => true]],
    '102 session' => [['message' => 'Session key invalid or no longer valid', 'code' => 102], 400, PermanentException::class, FailureReason::InvalidToken, null, ['terminal' => false]],
    'not authorized application text' => [['message' => 'Error validating access token: The user has not authorized application 9.', 'code' => 100], 400, PermanentException::class, FailureReason::InvalidToken, null, []],
    '4 app throttle' => [['message' => '(#4) Application request limit reached', 'code' => 4, 'is_transient' => true], 400, TemporaryException::class, FailureReason::RateLimited, 900, ['code' => 4]],
    '17 user throttle' => [['message' => '(#17) User request limit reached', 'code' => 17], 400, TemporaryException::class, FailureReason::RateLimited, 900, []],
    '32 page throttle' => [['message' => '(#32) Page request limit reached', 'code' => 32], 400, TemporaryException::class, FailureReason::RateLimited, 300, []],
    '341 app limit' => [['message' => '(#341) Application limit reached', 'code' => 341], 400, TemporaryException::class, FailureReason::RateLimited, 300, []],
    '613 rate limit' => [['message' => '(#613) Calls to this api have exceeded the rate limit.', 'code' => 613], 400, TemporaryException::class, FailureReason::RateLimited, 900, []],
    '80001 page BUC' => [['message' => '(#80001) There have been too many calls to this Page account. Wait a bit and try again.', 'code' => 80001], 400, TemporaryException::class, FailureReason::RateLimited, 900, []],
    'rate limit text' => [['message' => 'Calls to this api have exceeded the rate limit.', 'code' => 100], 400, TemporaryException::class, FailureReason::RateLimited, 300, []],
    'is_transient' => [['message' => 'An unexpected error has occurred. Please retry your request later.', 'code' => 100, 'is_transient' => true], 400, TemporaryException::class, FailureReason::ServerError, 60, ['is_transient' => true]],
    '1 unknown' => [['message' => 'An unknown error has occurred.', 'code' => 1], 400, TemporaryException::class, FailureReason::ServerError, 60, []],
    '2 service' => [['message' => 'Service temporarily unavailable', 'code' => 2], 400, TemporaryException::class, FailureReason::ServerError, 60, []],
    '-1 instagram' => [['message' => 'Unexpected error', 'code' => -1, 'error_subcode' => 2207001], 400, TemporaryException::class, FailureReason::ServerError, 60, ['code' => -1, 'subcode' => 2207001]],
    '-2 instagram' => [['message' => 'Timeout', 'code' => -2, 'error_subcode' => 2207003], 400, TemporaryException::class, FailureReason::ServerError, 60, []],
    '500 json' => [['message' => 'Please reduce the amount of data you are asking for', 'code' => 0], 500, TemporaryException::class, FailureReason::ServerError, 60, ['http_status' => 500]],
    '3 capability' => [['message' => '(#3) Application does not have the capability to make this API call.', 'code' => 3], 400, PermanentException::class, FailureReason::InsufficientPermissions, null, []],
    '10 permission' => [['message' => '(#10) Application does not have permission for this action', 'code' => 10], 400, PermanentException::class, FailureReason::InsufficientPermissions, null, []],
    '200 permission' => [['message' => '(#200) Requires pages_manage_engagement permission to manage the object', 'code' => 200], 403, PermanentException::class, FailureReason::InsufficientPermissions, null, []],
    '299 permission range' => [['message' => '(#299) Requires extended permission: manage_pages', 'code' => 299], 400, PermanentException::class, FailureReason::InsufficientPermissions, null, []],
    '100/33 lost access' => [['message' => "Unsupported post request. Object with ID '1784' does not exist, cannot be loaded due to missing permissions, or does not support this operation.", 'code' => 100, 'error_subcode' => 33], 400, PermanentException::class, FailureReason::InsufficientPermissions, null, ['subcode' => 33]],
    '368 blocked' => [['message' => 'It looks like you were misusing this feature by going too fast.', 'code' => 368, 'error_user_title' => 'Action Blocked'], 400, PermanentException::class, FailureReason::AccountRestricted, null, ['retry_later' => true, 'error_user_title' => 'Action Blocked']],
    '506 duplicate' => [['message' => 'Duplicate status message', 'code' => 506], 400, PermanentException::class, FailureReason::DuplicateContent, null, []],
]);

it('leaves product-specific and unknown errors to the driver', function (Response $response) {
    expect(mgDriver()->call('classifyMetaError', $response))->toBeNull();
})->with([
    'plain 100' => fn () => mgError(['message' => '(#100) Invalid parameter', 'code' => 100]),
    'instagram 2207026 on code 9' => fn () => mgError(['message' => 'The video format is not supported.', 'code' => 9, 'error_subcode' => 2207026]),
    'non-json 400' => fn () => new Response(new PsrResponse(400, [], 'Bad Request')),
    'empty 401' => fn () => new Response(new PsrResponse(401, [], '')),
]);

it('treats a non-JSON 5xx as a transient outage', function () {
    $e = mgDriver()->call('classifyMetaError', new Response(new PsrResponse(502, ['Content-Type' => 'text/html'], '<html>Bad Gateway</html>')));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::ServerError)
        ->and($e->retryAfter)->toBe(60)
        ->and($e->getMessage())->toContain('HTTP 502');
});

it('waits the estimated time to regain access from the usage headers', function () {
    $buc = json_encode(['1784' => [
        ['type' => 'pages', 'call_count' => 100, 'total_cputime' => 25, 'total_time' => 25, 'estimated_time_to_regain_access' => 19],
        ['type' => 'pages', 'call_count' => 100, 'total_cputime' => 25, 'total_time' => 25, 'estimated_time_to_regain_access' => 4],
    ]]);

    $fromBuc = mgDriver()->call('classifyMetaError', mgError(['message' => '(#80001) too many calls', 'code' => 80001], 400, ['X-Business-Use-Case-Usage' => $buc]));
    $fromApp = mgDriver()->call('classifyMetaError', mgError(['message' => '(#4) limit', 'code' => 4], 400, ['X-App-Usage' => json_encode(['call_count' => 100, 'estimated_time_to_regain_access' => 2])]));
    $zero = mgDriver()->call('classifyMetaError', mgError(['message' => '(#32) limit', 'code' => 32], 400, ['X-Business-Use-Case-Usage' => json_encode(['1' => [['estimated_time_to_regain_access' => 0]]])]));
    $retryAfter = mgDriver()->call('classifyMetaError', mgError(['message' => 'Too many', 'code' => 0], 429, ['Retry-After' => '45']));

    expect($fromBuc->retryAfter)->toBe(19 * 60)
        ->and($fromApp->retryAfter)->toBe(120)
        ->and($zero->retryAfter)->toBe(300)
        ->and($retryAfter->reason)->toBe(FailureReason::RateLimited)
        ->and($retryAfter->retryAfter)->toBe(45);
});

it('shows the user message but matches on the raw message', function () {
    $e = mgDriver()->call('classifyMetaError', mgError([
        'message' => 'Error validating access token: Session has expired.',
        'code' => 190,
        'error_user_title' => 'Session expired',
        'error_user_msg' => 'Your session has expired. Please log in again.',
    ]));

    expect($e->getMessage())->toBe('Your session has expired. Please log in again.')
        ->and($e->context['raw_message'])->toBe('Error validating access token: Session has expired.')
        ->and($e->context['error_user_title'])->toBe('Session expired')
        ->and($e->context['fbtrace_id'])->toBe('AbCdEf123')
        ->and($e->context['error']['code'])->toBe(190);

    // 'rate limit' in the localised user message alone must not classify it as a throttle.
    expect(mgDriver()->call('classifyMetaError', mgError([
        'message' => '(#100) Invalid parameter',
        'code' => 100,
        'error_user_msg' => 'You hit a rate limit for this parameter',
    ])))->toBeNull();
});

it('names the reconnect reason in the overridden token messages', function () {
    expect(mgDriver()->call('classifyMetaError', mgError(['message' => 'x', 'code' => 190, 'error_subcode' => 460]))->getMessage())->toContain('changed their password')
        ->and(mgDriver()->call('classifyMetaError', mgError(['message' => 'x', 'code' => 190, 'error_subcode' => 459]))->getMessage())->toContain('facebook.com')
        ->and(mgDriver()->call('classifyMetaError', mgError(['message' => 'x', 'code' => 190, 'error_subcode' => 492]))->getMessage())->toContain('role on this Page');
});

// --- Hosts, containers and permalinks ------------------------------------------

it('builds edges on the per-post host and refuses a missing account id', function () {
    $driver = mgDriver();

    expect($driver->call('edge', mgPost(), '/threads'))->toBe('https://graph.meta.test/v25.0/1784/threads')
        ->and($driver->call('edge', mgPost(['account_id' => 9, 'login' => 'instagram']), '/media'))->toBe('https://graph.instagram.test/v25.0/9/media');

    $e = mgCatch(fn () => $driver->call('edge', mgPost(['access_token' => 'tok']), '/threads'));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Misconfigured)
        ->and($e->context['missing_credentials'])->toBe(['account_id']);
});

it('reads container state without assuming IN_PROGRESS', function () {
    Http::fake([
        'graph.meta.test/v25.0/c-error*' => Http::response(['status' => 'ERROR', 'error_message' => 'FAILED_PROCESSING_VIDEO', 'id' => 'c-error']),
        'graph.meta.test/v25.0/c-missing*' => Http::response(['id' => 'c-missing']),
        'graph.meta.test/v25.0/c-published*' => Http::response(['status' => 'PUBLISHED', 'id' => 'c-published']),
    ]);

    $error = mgDriver()->call('containerState', mgPost(), 'c-error');
    $missing = mgDriver()->call('containerState', mgPost(), 'c-missing');

    expect($error)->toBe(['status' => 'ERROR', 'error_message' => 'FAILED_PROCESSING_VIDEO', 'raw' => ['status' => 'ERROR', 'error_message' => 'FAILED_PROCESSING_VIDEO', 'id' => 'c-error']])
        ->and($missing['status'])->toBeNull()
        ->and($missing['error_message'])->toBeNull()
        ->and(mgDriver()->call('containerStatus', mgPost(), 'c-published'))->toBe('PUBLISHED')
        ->and(mgDriver()->call('containerStatus', mgPost(), 'c-missing'))->toBeNull();

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), 'c-error') && $query['fields'] === 'status,error_message';
    });

    // containerStatus() asks for the status field only, as 1.0 did.
    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), 'c-published') && $query['fields'] === 'status';
    });
});

it('reads an Instagram-style status_code container and its status description', function () {
    Http::fake([
        'graph.instagram.test/v25.0/*' => Http::response([
            'status_code' => 'ERROR',
            'status' => 'Error: Media upload has failed with error code 2207026',
            'id' => '1790',
        ]),
    ]);

    $driver = mgDriver();
    $driver->statusField = 'status_code';

    $state = $driver->call('containerState', mgPost(['account_id' => '1', 'access_token' => 't', 'login' => 'instagram']), '1790');

    expect($state['status'])->toBe('ERROR')
        ->and($state['error_message'])->toBe('Error: Media upload has failed with error code 2207026');

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['fields'] === 'status_code,status';
    });
});

it('maps a failed container read through the driver error table', function () {
    Http::fake(['graph.meta.test/*' => Http::response(['error' => ['message' => '(#4) limit', 'code' => 4]], 400)]);

    $e = mgCatch(fn () => mgDriver()->call('containerState', mgPost(), 'c1'));

    // The default classifyError knows nothing of Meta codes; a driver calls classifyMetaError first.
    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->context['http_status'])->toBe(400)
        ->and($e->context['code'])->toBe(4);
});

it('fetches permalinks best-effort on the per-post host', function () {
    Http::fake([
        'graph.instagram.test/v25.0/m1*' => Http::response(['permalink' => 'https://www.instagram.com/p/abc/']),
        'graph.meta.test/v25.0/m2*' => Http::response(['error' => ['message' => 'nope', 'code' => 100]], 400),
        'graph.meta.test/v25.0/m3*' => Http::response(['permalink' => '']),
    ]);

    $driver = mgDriver();

    expect($driver->call('fetchPermalink', mgPost(['account_id' => '1', 'access_token' => 't', 'login' => 'instagram']), 'm1'))->toBe('https://www.instagram.com/p/abc/')
        ->and($driver->call('fetchPermalink', mgPost(), 'm2'))->toBeNull()
        ->and($driver->call('fetchPermalink', mgPost(), 'm3'))->toBeNull()
        ->and($driver->call('fetchPermalink', mgPost(), ' '))->toBeNull();
});

it('defaults graphBaseFor to graphBase and reads the publish timeout from config', function () {
    $plain = new class extends TalksToMetaGraphTestDriver
    {
        protected function graphBaseFor(PreparedPost $post): string
        {
            return $this->graphBase();
        }
    };

    expect($plain->call('graphBaseFor', mgPost(['login' => 'instagram'])))->toBe('https://graph.meta.test/v25.0')
        ->and($plain->call('publishTimeout'))->toBe(120);

    config(['social.publish_timeout' => 90]);
    expect($plain->call('publishTimeout'))->toBe(90);

    config(['social.api.threads.publish_timeout' => 200]);
    expect($plain->call('publishTimeout'))->toBe(200);
});

it('uses the default graphBaseFor of the trait', function () {
    $driver = new class extends AbstractPlatform
    {
        use TalksToMetaGraph;

        public function platform(): Platform
        {
            return Platform::Facebook;
        }

        public function capabilities(): Capabilities
        {
            return new Capabilities(new CaptionRules(0, 500), new MediaRules(0, 10));
        }

        public function publish(PreparedPost $post): PublishOutcome
        {
            throw new RuntimeException('not used');
        }

        public function base(PreparedPost $post): string
        {
            return $this->graphBaseFor($post);
        }

        protected function graphBase(): string
        {
            return 'https://graph.facebook.test/v25.0';
        }
    };

    expect($driver->base(mgPost()))->toBe('https://graph.facebook.test/v25.0');
});
