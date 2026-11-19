<?php

namespace SocialPoster\Tests\Feature\Platforms\ThreadsErrorsTest;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Tests\ThreadsFixtures\ThreadsKit as K;
use SocialPoster\ValueObjects\Credentials;

require_once __DIR__.'/../../Fixtures/threads/ThreadsKit.php';

/** Publish a text post whose container create answers $response, and return what it threw. */
function failCreate(\Closure $response): \Throwable
{
    K::fake(['POST 1784/threads' => [$response]]);

    return K::catch(fn () => K::driver()->publish(K::post()));
}

it('classifies production-shaped Threads errors', function (string $fixture, int $status, string $class, FailureReason $reason, ?int $retryAfter, array $context, ?string $message) {
    $e = failCreate(K::error($fixture, $status));

    expect($e)->toBeInstanceOf($class)
        ->and($e->platform)->toBe(Platform::Threads)
        ->and($e->reason)->toBe($reason)
        ->and($e->context['http_status'])->toBe($status);

    if ($e instanceof TemporaryException) {
        expect($e->retryAfter)->toBe($retryAfter);
    }

    foreach ($context as $key => $value) {
        expect($e->context)->toHaveKey($key, $value);
    }

    if ($message !== null) {
        expect($e->getMessage())->toContain($message);
    }
})->with([
    'media not found 4279009' => ['media-not-found-4279009', 400, TemporaryException::class, FailureReason::ServerError, 30, ['subcode' => 4279009, 'code' => 24], 'Media Not Found'],
    'download timeout 2207003 (transient code -2)' => ['download-timeout-2207003', 400, TemporaryException::class, FailureReason::ServerError, 60, ['subcode' => 2207003], 'still processing'],
    'carousel item not ready 4279004' => ['carousel-item-not-ready-4279004', 400, TemporaryException::class, FailureReason::ServerError, 60, ['subcode' => 4279004], 'still processing'],
    'unexpected error (code 2) waits 90s' => ['unexpected-error', 500, TemporaryException::class, FailureReason::ServerError, 90, [], 'busy'],
    'unexpected error on HTTP 400' => ['unexpected-error', 400, TemporaryException::class, FailureReason::ServerError, 90, [], null],
    'unknown error code 1' => ['unknown-error-code-1', 400, TemporaryException::class, FailureReason::ServerError, 60, ['code' => 1], null],
    'session expired 190/463' => ['token-session-expired-463', 400, PermanentException::class, FailureReason::InvalidToken, null, ['code' => 190, 'subcode' => 463, 'terminal' => true], 'Session has expired'],
    'app not authorized 190/458' => ['token-not-authorized-458', 400, PermanentException::class, FailureReason::InvalidToken, null, ['terminal' => true], 'has not authorized'],
    'invalid token 190' => ['token-invalid-190', 400, PermanentException::class, FailureReason::InvalidToken, null, ['terminal' => false, 'reresolvable' => true], null],
    'app request limit 4' => ['rate-limit-code-4', 400, TemporaryException::class, FailureReason::RateLimited, 900, ['code' => 4], null],
    'rate limit 613' => ['rate-limit-code-613', 400, TemporaryException::class, FailureReason::RateLimited, 900, [], null],
    'page limit 32' => ['rate-limit-code-32', 400, TemporaryException::class, FailureReason::RateLimited, 300, [], null],
    'permission 10' => ['permission-code-10', 403, PermanentException::class, FailureReason::InsufficientPermissions, null, ['code' => 10], 'threads_content_publish'],
    'permission 200' => ['permission-code-200', 400, PermanentException::class, FailureReason::InsufficientPermissions, null, ['code' => 200], 'threads_manage_replies'],
    'link limit' => ['link-limit-exceeded', 400, PermanentException::class, FailureReason::InvalidContent, null, ['link_limit_exceeded' => true], 'at most 5 links'],
    'invalid parameter falls through' => ['invalid-parameter-100', 400, PermanentException::class, FailureReason::Unknown, null, ['code' => 100], 'reply_control is invalid'],
]);

it('uses the user-facing message but keeps the raw one', function () {
    $e = failCreate(K::error('invalid-parameter-100'));

    expect($e->getMessage())->toContain('The parameter reply_control is invalid.')
        ->and($e->context['code'])->toBe(100)
        ->and($e->context['trace_id'])->toBe('N4o5P6q7R8s9T0u1V2w3X4y');
});

it('waits for the throttle estimate in the usage headers', function () {
    $e = failCreate(K::json(K::errorBody('rate-limit-code-4'), 400, [
        'X-App-Usage' => json_encode(['call_count' => 100, 'total_time' => 20, 'total_cputime' => 15, 'estimated_time_to_regain_access' => 7]),
    ]));

    expect($e)->toBeInstanceOf(TemporaryException::class)->and($e->retryAfter)->toBe(420);
});

it('honours Retry-After on an HTTP 429', function () {
    $e = failCreate(K::json(['error' => ['message' => 'Too many calls', 'type' => 'OAuthException']], 429, ['Retry-After' => '120']));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::RateLimited)
        ->and($e->retryAfter)->toBe(120);
});

it('treats a non-JSON 5xx as a temporary server error', function () {
    $e = failCreate(K::json('<html><body>502 Bad Gateway</body></html>', 502, ['Content-Type' => 'text/html']));

    expect($e)->toBeInstanceOf(TemporaryException::class)->and($e->reason)->toBe(FailureReason::ServerError);
});

it('maps a bare 401 to InvalidToken through the base table', function () {
    $e = failCreate(K::json('Unauthorized', 401, ['Content-Type' => 'text/plain']));

    expect($e)->toBeInstanceOf(PermanentException::class)->and($e->reason)->toBe(FailureReason::InvalidToken);
});

it('maps a bare 404 to NotFound through the base table', function () {
    $e = failCreate(K::json('', 404));

    expect($e)->toBeInstanceOf(PermanentException::class)->and($e->reason)->toBe(FailureReason::NotFound);
});

it('retries a non-terminal token rejection once when a resolver can refresh it', function () {
    app()->instance(CredentialsResolver::class, new class implements CredentialsResolver
    {
        public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
        {
            return $dispatched;
        }
    });

    K::fake(['POST 1784/threads' => [K::error('token-invalid-190'), K::error('token-not-authorized-458')]]);

    $e = K::catch(fn () => K::driver()->publish(K::post()));
    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::InvalidToken)
        ->and($e->retryAfter)->toBe(0)
        ->and($e->context['reresolve'])->toBeTrue()
        ->and($e->getPrevious())->toBeInstanceOf(PermanentException::class);

    // A terminal rejection (app removed) is never retried.
    $terminal = K::catch(fn () => K::driver()->publish(K::post()));
    expect($terminal)->toBeInstanceOf(PermanentException::class)
        ->and($terminal->isTerminal())->toBeTrue();
});

it('classifies a status read failure the same way', function () {
    K::fake(['GET C1' => [K::error('rate-limit-code-613')]]);

    $e = K::catch(fn () => K::driver()->resume(K::post(), ['phase' => 'await', 'container_id' => 'C1']));

    expect($e)->toBeInstanceOf(TemporaryException::class)->and($e->reason)->toBe(FailureReason::RateLimited);
});

it('lets a transport failure on a container create bubble for the job', function () {
    K::fake(['POST 1784/threads' => [K::timeout()]]);

    $e = K::catch(fn () => K::driver()->publish(K::post()));

    expect($e)->toBeInstanceOf(ConnectionException::class);
    Http::assertSentCount(1);
});

it('names the configured link limit when Threads refuses a post for its links', function () {
    config(['social.api.threads.max_links' => 10]);

    $e = failCreate(K::error('link-limit-exceeded', 400));

    expect($e->getMessage())->toContain('at most 10 links');
});
