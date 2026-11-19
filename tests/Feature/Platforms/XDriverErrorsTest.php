<?php

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Tests\Fixtures\x\XFixtures;
use SocialPoster\Tests\Fixtures\x\XTestDriver;
use SocialPoster\ValueObjects\Credentials;

/** A client Response for an X error fixture (or a raw string body). */
function xErrorResponse(string $fixture, int $status, array $headers = []): Response
{
    $body = str_starts_with($fixture, 'raw:') ? substr($fixture, 4) : (string) json_encode(XFixtures::json($fixture));

    return new Response(new PsrResponse($status, $headers + ['Content-Type' => 'application/json'], $body));
}

function xClassify(string $fixture, int $status, array $headers = []): SocialPosterException
{
    return (new XTestDriver)->call('classifyError', xErrorResponse($fixture, $status, $headers));
}

it('maps X error responses', function (string $fixture, int $status, string $class, FailureReason $reason, string $message) {
    $exception = xClassify($fixture, $status);

    expect($exception)->toBeInstanceOf($class)
        ->and($exception->reason)->toBe($reason)
        ->and($exception->platform)->toBe(Platform::X)
        ->and($exception->getMessage())->toContain($message)
        ->and($exception->context['status'])->toBe($status)
        ->and($exception->context['error'])->toBe(str_starts_with($fixture, 'raw:') ? [] : XFixtures::json($fixture));
})->with([
    'credits depleted (402)' => ['error_credits_depleted', 402, PermanentException::class, FailureReason::QuotaExceeded, 'does not have any credits'],
    'bare 402' => ['error_payment_required', 402, PermanentException::class, FailureReason::QuotaExceeded, 'lack of API credits'],
    'credits mentioned on a 403' => ['error_credits_depleted', 403, PermanentException::class, FailureReason::QuotaExceeded, 'credits'],
    'usage capped (429)' => ['error_usage_capped', 429, PermanentException::class, FailureReason::QuotaExceeded, 'Usage cap exceeded'],
    'service unavailable (503)' => ['error_service_unavailable', 503, TemporaryException::class, FailureReason::ServerError, 'temporarily unavailable (HTTP 503)'],
    'non-JSON 502' => ['raw:<html>Bad gateway</html>', 502, TemporaryException::class, FailureReason::ServerError, 'HTTP 502'],
    'locked account' => ['error_locked', 403, PermanentException::class, FailureReason::AccountRestricted, 'temporarily locked'],
    'suspended account' => ['error_suspended', 403, PermanentException::class, FailureReason::AccountRestricted, 'suspended'],
    'blocked is not locked' => ['error_blocked', 403, PermanentException::class, FailureReason::InsufficientPermissions, 'blocked from viewing'],
    'client not enrolled' => ['error_client_forbidden', 403, PermanentException::class, FailureReason::Misconfigured, 'attached to a Project'],
    'app-only token' => ['error_unsupported_authentication', 403, PermanentException::class, FailureReason::Misconfigured, 'Application-Only'],
    'unauthorized (401)' => ['error_unauthorized', 401, PermanentException::class, FailureReason::InvalidToken, 'rejected the access token'],
    'duplicate content' => ['error_duplicate', 403, PermanentException::class, FailureReason::DuplicateContent, 'duplicate content'],
    'reply restriction' => ['error_reply_restricted', 403, PermanentException::class, FailureReason::InvalidContent, 'Reply to this conversation is not allowed'],
    'video above the account limit' => ['error_video_too_long', 403, PermanentException::class, FailureReason::MediaRejected, 'video duration'],
    'other 403' => ['error_forbidden', 403, PermanentException::class, FailureReason::InsufficientPermissions, 'not permitted to perform this action'],
    'bare 403' => ['error_forbidden_bare', 403, PermanentException::class, FailureReason::InsufficientPermissions, 'may lack a required permission'],
    'invalid media ids (400)' => ['error_invalid_media_ids', 400, PermanentException::class, FailureReason::MediaRejected, 'Your media IDs are invalid.'],
    'text too long (400)' => ['error_text_too_long', 400, PermanentException::class, FailureReason::InvalidContent, 'Your Tweet text is too long'],
    'reply target gone (400)' => ['error_reply_target_missing', 400, PermanentException::class, FailureReason::NotFound, 'does not exist'],
    'bare 400 (base table)' => ['error_bad_request_bare', 400, PermanentException::class, FailureReason::Unknown, 'HTTP 400'],
    'not found (404, base table)' => ['error_not_found', 404, PermanentException::class, FailureReason::NotFound, 'Could not find media'],
    'unmapped 409 (base table)' => ['error_bad_request_bare', 409, PermanentException::class, FailureReason::Unknown, 'HTTP 409'],
]);

it('prefers errors[0].message over the generic detail', function () {
    $exception = xClassify('error_invalid_media_ids', 400);

    expect($exception->getMessage())->toBe('Your media IDs are invalid.')
        ->and($exception->context['problem_type'])->toBe('https://api.twitter.com/2/problems/invalid-request');
});

it('retries a 5xx after 60s, or what Retry-After asks', function () {
    expect(xClassify('error_service_unavailable', 503)->retryAfter)->toBe(60)
        ->and(xClassify('error_service_unavailable', 500, ['Retry-After' => '10'])->retryAfter)->toBe(10);

    config(['social.api.x.server_error_retry' => 15]);

    expect(xClassify('error_service_unavailable', 503)->retryAfter)->toBe(15);
});

it('waits for the 15-minute window to reset on a 429', function () {
    $exception = xClassify('error_too_many_requests', 429, [
        'x-rate-limit-limit' => '100',
        'x-rate-limit-remaining' => '0',
        'x-rate-limit-reset' => (string) (time() + 300),
    ]);

    expect($exception)->toBeInstanceOf(TemporaryException::class)
        ->and($exception->reason)->toBe(FailureReason::RateLimited)
        ->and($exception->retryAfter)->toBeGreaterThanOrEqual(299)->toBeLessThanOrEqual(300)
        ->and($exception->getMessage())->toBe('X rate limit reached.')
        ->and($exception->context['rate_limit_bucket'])->toBe('window')
        ->and($exception->context['retry_at'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/');
});

it('reports an exhausted 24-hour bucket as a permanent rate limit with a retry hint', function () {
    $reset = time() + 72000;
    $exception = xClassify('error_too_many_requests', 429, [
        'x-rate-limit-remaining' => '42',
        'x-rate-limit-reset' => (string) (time() + 600),
        'x-user-limit-24hour-limit' => '17',
        'x-user-limit-24hour-remaining' => '0',
        'x-user-limit-24hour-reset' => (string) $reset,
    ]);

    expect($exception)->toBeInstanceOf(PermanentException::class)
        ->and($exception->reason)->toBe(FailureReason::RateLimited)
        ->and($exception->context['rate_limit_bucket'])->toBe('user_24h')
        ->and($exception->context['retry_after_hint'])->toBeGreaterThanOrEqual(71999)->toBeLessThanOrEqual(72000)
        ->and($exception->context['retry_at'])->toBe(gmdate('Y-m-d\TH:i:s\Z', $exception->context['retry_after_hint'] + time()))
        ->and($exception->getMessage())->toContain('resets at');
});

it('takes the latest reset among the exhausted 24-hour buckets', function () {
    $exception = xClassify('error_too_many_requests', 429, [
        'x-user-limit-24hour-remaining' => '0',
        'x-user-limit-24hour-reset' => (string) (time() + 600),
        'x-app-limit-24hour-remaining' => '0',
        'x-app-limit-24hour-reset' => (string) (time() + 1800),
    ]);

    expect($exception)->toBeInstanceOf(TemporaryException::class)
        ->and($exception->retryAfter)->toBeGreaterThanOrEqual(1799)->toBeLessThanOrEqual(1800)
        ->and($exception->context['rate_limit_bucket'])->toBe('app_24h');
});

it('ignores a 24-hour bucket that is not exhausted', function () {
    $exception = xClassify('error_too_many_requests', 429, [
        'x-user-limit-24hour-remaining' => '3',
        'x-user-limit-24hour-reset' => (string) (time() + 72000),
        'x-rate-limit-reset' => (string) (time() + 120),
    ]);

    expect($exception)->toBeInstanceOf(TemporaryException::class)
        ->and($exception->context['rate_limit_bucket'])->toBe('window');
});

it('falls back to Retry-After, then to 120s, on a 429', function () {
    expect(xClassify('error_too_many_requests', 429, ['Retry-After' => '45'])->retryAfter)->toBe(45)
        ->and(xClassify('error_too_many_requests', 429)->retryAfter)->toBe(120)
        ->and(xClassify('error_too_many_requests', 429)->context['rate_limit_bucket'])->toBe('unknown')
        // A reset in the past still waits a second.
        ->and(xClassify('error_too_many_requests', 429, ['x-rate-limit-reset' => (string) (time() - 5)])->retryAfter)->toBe(1);
});

it('honours api.x.max_rate_limit_wait', function () {
    config(['social.api.x.max_rate_limit_wait' => 100]);

    $exception = xClassify('error_too_many_requests', 429, ['x-rate-limit-reset' => (string) (time() + 300)]);

    expect($exception)->toBeInstanceOf(PermanentException::class)
        ->and($exception->reason)->toBe(FailureReason::RateLimited)
        ->and($exception->context)->toHaveKey('retry_after_hint');
});

it('carries the usage-cap period and scope', function () {
    $exception = xClassify('error_usage_capped', 429);

    expect($exception->context)->toMatchArray(['usage_capped' => true, 'period' => 'Monthly', 'scope' => 'Product']);
});

it('enriches the exception and logs it through mapError', function () {
    config(['social.error_logging' => true]);
    Log::spy();

    $exception = (new XTestDriver)->call('mapError', xErrorResponse('error_duplicate', 403));

    expect($exception->reason)->toBe(FailureReason::DuplicateContent)
        ->and($exception->context['http_status'])->toBe(403);
    Log::shouldHaveReceived('error')->once();
});

it('asks for a re-resolve on a 401 when a resolver is bound', function () {
    app()->instance(CredentialsResolver::class, new class implements CredentialsResolver
    {
        public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
        {
            return $dispatched;
        }
    });

    $exception = (new XTestDriver)->call('mapError', xErrorResponse('error_unauthorized', 401));

    expect($exception)->toBeInstanceOf(TemporaryException::class)
        ->and($exception->reason)->toBe(FailureReason::InvalidToken)
        ->and($exception->context['reresolve'])->toBeTrue()
        ->and($exception->getPrevious())->toBeInstanceOf(PermanentException::class);
});

it('surfaces the mapped reason from a real create', function () {
    Http::fake(XFixtures::routes(['api.x.com/2/tweets' => Http::response(XFixtures::json('error_credits_depleted'), 402)]));

    expect(fn () => (new XTestDriver)->resume(XFixtures::post(), ['phase' => 'tweet', 'media_ids' => []]))
        ->toThrow(fn (PermanentException $e) => expect($e->reason)->toBe(FailureReason::QuotaExceeded)
            ->and($e->reason->category())->toBe('operator'));
});
