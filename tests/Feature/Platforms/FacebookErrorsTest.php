<?php

namespace SocialPoster\Tests\Feature\Platforms;

use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\ValueObjects\Credentials;

use function SocialPoster\Tests\Fixtures\Facebook\fbDriver;
use function SocialPoster\Tests\Fixtures\Facebook\fbFixture;
use function SocialPoster\Tests\Fixtures\Facebook\fbResponse;

require_once __DIR__.'/../../Fixtures/facebook/helpers.php';

it('classifies Facebook errors', function (string $fixture, int $status, string $class, FailureReason $reason, ?int $retryAfter, array $context) {
    $exception = fbDriver()->classify(fbResponse(fbFixture($fixture), $status));

    expect($exception)->toBeInstanceOf($class)
        ->and($exception->reason)->toBe($reason)
        ->and($exception->platform)->toBe(Platform::Facebook)
        ->and($exception->context)->toMatchArray($context + ['http_status' => $status]);

    if ($exception instanceof TemporaryException) {
        expect($exception->retryAfter)->toBe($retryAfter);
    }
})->with([
    'password changed (190/460)' => ['token_password_changed', 400, PermanentException::class, FailureReason::InvalidToken, null, ['code' => 190, 'subcode' => 460, 'terminal' => true]],
    'expired token (190/463)' => ['token_expired', 400, PermanentException::class, FailureReason::InvalidToken, null, ['subcode' => 463, 'terminal' => true]],
    'invalid session (102)' => ['session_invalid', 400, PermanentException::class, FailureReason::InvalidToken, null, ['code' => 102, 'terminal' => false, 'reresolvable' => true]],
    'checkpoint (190/459)' => ['token_checkpoint', 400, PermanentException::class, FailureReason::AccountRestricted, null, ['subcode' => 459]],
    'lost Page role (190/492)' => ['token_lost_page_role', 400, PermanentException::class, FailureReason::InsufficientPermissions, null, ['subcode' => 492, 'terminal' => true]],
    'app throttle (4)' => ['throttle_app', 400, TemporaryException::class, FailureReason::RateLimited, 900, ['code' => 4]],
    'Page throttle (80001)' => ['throttle_page', 400, TemporaryException::class, FailureReason::RateLimited, 900, ['code' => 80001]],
    'user throttle (32)' => ['throttle_user', 400, TemporaryException::class, FailureReason::RateLimited, 300, ['code' => 32]],
    'unknown error (1)' => ['unknown_error', 500, TemporaryException::class, FailureReason::ServerError, 60, ['code' => 1]],
    'service unavailable (2)' => ['service_unavailable', 503, TemporaryException::class, FailureReason::ServerError, 60, ['code' => 2]],
    'permission (200)' => ['permission_200', 403, PermanentException::class, FailureReason::InsufficientPermissions, null, ['code' => 200]],
    'permission (10)' => ['permission_10', 403, PermanentException::class, FailureReason::InsufficientPermissions, null, ['code' => 10]],
    'policy block (368)' => ['policy_block', 400, PermanentException::class, FailureReason::AccountRestricted, null, ['code' => 368, 'retry_later' => true]],
    'duplicate (506)' => ['duplicate_post', 400, PermanentException::class, FailureReason::DuplicateContent, null, ['code' => 506]],
    'download timeout (2207003)' => ['download_timeout', 400, TemporaryException::class, FailureReason::Timeout, 30, ['subcode' => 2207003]],
    'reel aspect ratio (100/1363040)' => ['reel_aspect_ratio', 400, PermanentException::class, FailureReason::MediaRejected, null, ['subcode' => 1363040]],
    'invalid image (324)' => ['invalid_image', 400, PermanentException::class, FailureReason::MediaRejected, null, ['code' => 324]],
    'link not fetched (1609005)' => ['link_unavailable', 400, PermanentException::class, FailureReason::MediaRejected, null, ['code' => 1609005]],
    'invalid parameter (100)' => ['invalid_parameter', 400, PermanentException::class, FailureReason::Unknown, null, ['code' => 100]],
    'upload processing failed' => ['rupload_processing_failed', 400, TemporaryException::class, FailureReason::ServerError, 60, ['upload_error' => 'ProcessingFailedError']],
    'upload fetch failed' => ['rupload_fetch_failed', 400, PermanentException::class, FailureReason::MediaRejected, null, ['upload_error' => 'MediaFetchError']],
    'upload token' => ['rupload_oauth', 401, PermanentException::class, FailureReason::InvalidToken, null, ['upload_error' => 'OAuthException']],
]);

it('waits as long as the Business Use Case header asks', function () {
    $usage = json_encode(['1029384756' => [
        ['type' => 'pages', 'call_count' => 100, 'total_cputime' => 30, 'total_time' => 40, 'estimated_time_to_regain_access' => 3],
        ['type' => 'pages', 'call_count' => 100, 'total_cputime' => 30, 'total_time' => 40, 'estimated_time_to_regain_access' => 12],
    ]]);

    $exception = fbDriver()->classify(fbResponse(fbFixture('throttle_page'), 400, ['X-Business-Use-Case-Usage' => (string) $usage]));

    expect($exception)->toBeInstanceOf(TemporaryException::class)->and($exception->retryAfter)->toBe(720);
});

it('gives readable messages for the Reels codes, in code or subcode', function (int $code, bool $asSubcode, string $words) {
    $error = ['message' => 'Invalid parameter', 'type' => 'OAuthException', 'code' => $asSubcode ? 100 : $code];

    if ($asSubcode) {
        $error['error_subcode'] = $code;
    }

    $exception = fbDriver()->classify(fbResponse(['error' => $error]));

    expect($exception->reason)->toBe(FailureReason::MediaRejected)
        ->and($exception->getMessage())->toContain($words)->toContain('Invalid parameter');
})->with([
    [1363040, true, 'aspect ratio'],
    [1363127, false, 'resolution'],
    [1363128, true, 'length'],
    [1363129, false, 'frame rate'],
]);

it('explains a code 100 with Facebook\'s own message and the technical one', function () {
    $exception = fbDriver()->classify(fbResponse(fbFixture('invalid_parameter')));

    expect($exception->getMessage())->toBe('The specified scheduled publish time is invalid. ((#100) Invalid parameter)');

    $plain = fbDriver()->classify(fbResponse(['error' => ['message' => '(#100) Param message must be a string', 'code' => 100]]));

    expect($plain->getMessage())->toBe('(#100) Param message must be a string');
});

it('treats a rate-limit message and an HTML outage as temporary', function () {
    $throttle = fbDriver()->classify(fbResponse(['error' => ['message' => 'Calls to this api have exceeded the rate limit.', 'code' => 9999]]));
    $outage = fbDriver()->classify(fbResponse('<html><body>502 Bad Gateway</body></html>', 502, ['Content-Type' => 'text/html']));

    expect($throttle)->toBeInstanceOf(TemporaryException::class)->and($throttle->reason)->toBe(FailureReason::RateLimited)
        ->and($outage)->toBeInstanceOf(TemporaryException::class)->and($outage->reason)->toBe(FailureReason::ServerError);
});

it('treats a retriable or 5xx upload error as temporary', function () {
    $retriable = fbDriver()->classify(fbResponse(['debug_info' => ['retriable' => true, 'type' => 'PartialRequestError', 'message' => 'Partial request']]));
    $server = fbDriver()->classify(fbResponse(['debug_info' => ['type' => 'UnknownError']], 503));

    expect($retriable)->toBeInstanceOf(TemporaryException::class)->and($retriable->retryAfter)->toBe(60)
        ->and($server)->toBeInstanceOf(TemporaryException::class);
});

it('falls back to the HTTP status for unmapped errors', function () {
    $unknown = fbDriver()->classify(fbResponse(['error' => ['message' => 'Something odd', 'type' => 'GraphMethodException', 'code' => 12345]], 400));
    $missing = fbDriver()->classify(fbResponse(['error' => ['message' => 'Unsupported get request. Object with ID does not exist', 'code' => 12345]], 404));

    expect($unknown)->toBeInstanceOf(PermanentException::class)
        ->and($unknown->reason)->toBe(FailureReason::Unknown)
        ->and($unknown->getMessage())->toBe('Something odd (HTTP 400)')
        ->and($missing->reason)->toBe(FailureReason::NotFound);
});

it('retries a non-terminal token rejection once when a credentials resolver is bound', function () {
    app()->instance(CredentialsResolver::class, new class implements CredentialsResolver
    {
        public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
        {
            return $dispatched;
        }
    });

    $session = fbDriver()->classify(fbResponse(fbFixture('session_invalid')));
    $dead = fbDriver()->classify(fbResponse(fbFixture('token_password_changed')));

    expect($session)->toBeInstanceOf(TemporaryException::class)
        ->and($session->context['reresolve'])->toBeTrue()
        ->and($session->retryAfter)->toBe(0)
        ->and($dead)->toBeInstanceOf(PermanentException::class);
});
