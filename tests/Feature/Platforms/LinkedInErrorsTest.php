<?php

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Tests\Fixtures\linkedin\LinkedInKit as Kit;
use SocialPoster\ValueObjects\Credentials;

afterEach(function () {
    Carbon::setTestNow();
    Kit::cleanup();
});

/**
 * POST /rest/posts answers with $fixture (a JSON fixture name or a raw body);
 * returns what the create step throws.
 *
 * @param  array<string, string>  $headers
 * @param  array<string, mixed>  $credentials
 */
function liCreateError(string $fixture, int $status, array $headers = [], array $credentials = []): Throwable
{
    $body = is_file(__DIR__."/../../Fixtures/linkedin/{$fixture}.json") ? Kit::json($fixture) : $fixture;

    Http::fake(['api.linkedin.com/rest/posts' => Http::response($body, $status, $headers)]);

    return Kit::catch(fn () => Kit::driver()->resume(
        Kit::post(credentials: Kit::credentials($credentials)),
        ['phase' => 'create_post', 'commentary' => 'Hello', 'content' => null],
    ));
}

it('maps LinkedIn errors on the post create', function (string $fixture, int $status, string $class, FailureReason $reason, string $message, array $context) {
    $e = liCreateError($fixture, $status);

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason)
        ->and($e->getMessage())->toContain($message)
        ->and($e->context['http_status'])->toBe($status)
        ->and($e->context['operation'] ?? 'post')->toBe('post');

    foreach ($context as $key => $value) {
        expect($e->context)->toHaveKey($key)
            ->and($e->context[$key])->toBe($value);
    }
})->with([
    'revoked token' => ['error_401_revoked', 401, PermanentException::class, FailureReason::InvalidToken, 'has been revoked by the user', ['terminal' => true, 'code' => 'REVOKED_ACCESS_TOKEN', 'service_error_code' => 65601]],
    'expired token' => ['error_401_expired', 401, PermanentException::class, FailureReason::InvalidToken, 'has expired', ['token_expired' => true]],
    'empty token' => ['error_401_empty', 401, PermanentException::class, FailureReason::InvalidToken, 'Empty oauth2 access token', ['code' => 'EMPTY_ACCESS_TOKEN']],
    '401 without body' => ['', 401, PermanentException::class, FailureReason::InvalidToken, 'HTTP 401', []],
    'access denied (member)' => ['error_403_access_denied', 403, PermanentException::class, FailureReason::InsufficientPermissions, 'Posting as a member needs the w_member_social scope', ['code' => 'ACCESS_DENIED']],
    'restricted member' => ['error_403_restricted', 403, PermanentException::class, FailureReason::AccountRestricted, 'restricted', ['code' => 'RESTRICTED_MEMBER']],
    'sunset version' => ['error_426_version', 426, PermanentException::class, FailureReason::Misconfigured, 'SOCIAL_LINKEDIN_VERSION', ['linkedin_version' => '202605']],
    'missing version' => ['error_400_version_missing', 400, PermanentException::class, FailureReason::Misconfigured, 'config social.api.linkedin.version', []],
    'write conflict' => ['error_409_conflict', 409, TemporaryException::class, FailureReason::ServerError, 'write conflict', []],
    'timeout' => ['error_408_timeout', 408, TemporaryException::class, FailureReason::ServerError, 'Request timed out', []],
    'server error' => ['error_500', 500, TemporaryException::class, FailureReason::ServerError, 'Internal Server Error', []],
    'maintenance' => ['error_503_maintenance', 503, TemporaryException::class, FailureReason::ServerError, 'under maintenance', []],
    'gateway html' => ['<html>504 Gateway Time-out</html>', 504, TemporaryException::class, FailureReason::ServerError, 'HTTP 504', []],
    'input errors' => ['error_422_input', 422, PermanentException::class, FailureReason::InvalidContent, 'Invalid value for field; wrong type or other syntax error; Field Value exceeds maximum length', ['input_errors' => ['INVALID_VALUE_FOR_FIELD', 'FIELD_LENGTH_TOO_LONG'], 'field_paths' => ['/commentary', '/commentary']]],
    'content input error' => ['error_422_content', 422, PermanentException::class, FailureReason::MediaRejected, 'Invalid URN', ['field_paths' => ['/content/media/id']]],
    'media still uploading' => ['error_400_media_waiting', 400, TemporaryException::class, FailureReason::ServerError, 'not finished receiving the media', []],
    'media processing failed' => ['error_400_media_failed', 400, PermanentException::class, FailureReason::MediaRejected, 'could not process the media', ['code' => 'MEDIA_ASSET_PROCESSING_FAILED']],
    'duplicate' => ['error_422_duplicate', 422, PermanentException::class, FailureReason::DuplicateContent, 'duplicate of urn:li:share:7012345678901234000', ['existing_urn' => 'urn:li:share:7012345678901234000']],
    'unmapped 400' => ['error_400_bad_request', 400, PermanentException::class, FailureReason::Unknown, 'Invalid visibility for author type (HTTP 400)', ['code' => 'BAD_REQUEST']],
    'not found' => ['error_404_video', 404, PermanentException::class, FailureReason::NotFound, 'Not found', []],
]);

it('keeps the raw error body in the context as v1.0 did', function () {
    $e = liCreateError('error_403_access_denied', 403);

    expect($e->context['error'])->toBe(Kit::json('error_403_access_denied'))
        ->and($e->context['status'])->toBe(403);
});

it('gives page authors the organization scope and roles in the 403 hint', function () {
    $e = liCreateError('error_403_access_denied', 403, credentials: ['author' => Kit::ORG]);

    expect($e->getMessage())->toContain('w_organization_social scope')
        ->and($e->getMessage())->toContain('ADMINISTRATOR, DIRECT_SPONSORED_CONTENT_POSTER or CONTENT_ADMIN');
});

it('waits for the short throttles', function (string $fixture, array $headers, int $min, int $max) {
    $e = liCreateError($fixture, 429, $headers);

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::RateLimited)
        ->and($e->retryAfter)->toBeGreaterThanOrEqual($min)->toBeLessThanOrEqual($max)
        ->and($e->context['daily_limit'])->toBeFalse();
})->with([
    'infrastructure protection' => ['error_429_infra', [], 60, 300],
    'with Retry-After' => ['error_429_infra', ['Retry-After' => '42'], 42, 42],
    'throttle message on another status' => ['{"status":400,"message":"Request throttled, try later"}', [], 60, 300],
]);

it('fails a daily limit permanently with the reset time when it is far away', function (string $fixture) {
    Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00', 'UTC'));

    $e = liCreateError($fixture, 429);

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::RateLimited)
        ->and($e->context['daily_limit'])->toBeTrue()
        ->and($e->context['retry_at'])->toBe('2026-11-19T00:00:00Z')
        ->and($e->context['retry_after_hint'])->toBe(43200)
        ->and($e->getMessage())->toContain('2026-11-19 00:00 UTC');
})->with(['error_429_day', 'error_429_member_day']);

it('waits for a daily limit that resets within max_rate_limit_wait', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-18 23:45:00', 'UTC'));

    $e = liCreateError('error_429_day', 429);

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->context['daily_limit'])->toBeTrue()
        ->and($e->retryAfter)->toBeGreaterThanOrEqual(900 + 30)->toBeLessThanOrEqual(900 + 300);
});

it('honours a raised max_rate_limit_wait and a Retry-After on daily limits', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00', 'UTC'));
    config(['social.api.linkedin.max_rate_limit_wait' => 86400]);

    $raised = liCreateError('error_429_day', 429);

    expect($raised)->toBeInstanceOf(TemporaryException::class)
        ->and($raised->retryAfter)->toBeGreaterThanOrEqual(43230)->toBeLessThanOrEqual(43500);

    config(['social.api.linkedin.max_rate_limit_wait' => 3600]);
    Http::fake(['api.linkedin.com/rest/images*' => Http::response(Kit::json('error_429_day'), 429, ['Retry-After' => '120'])]);

    $header = Kit::catch(fn () => Kit::driver()->publish(Kit::post([Kit::image()])));

    expect($header)->toBeInstanceOf(TemporaryException::class)
        ->and($header->retryAfter)->toBe(120)
        ->and($header->context['operation'])->toBe('upload');
});

it('fails a daily limit whose Retry-After is past max_rate_limit_wait with retry_at, never waiting in the queue', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00', 'UTC'));
    Http::fake(['api.linkedin.com/rest/images*' => Http::response(Kit::json('error_429_day'), 429, ['Retry-After' => '7200'])]);

    $e = Kit::catch(fn () => Kit::driver()->publish(Kit::post([Kit::image()])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::RateLimited)
        ->and($e->context['daily_limit'])->toBeTrue()
        ->and($e->context['retry_at'])->toBe('2026-11-18T14:00:00Z')
        ->and($e->context['retry_after_hint'])->toBe(7200);
});

function liBindResolver(): void
{
    app()->instance(CredentialsResolver::class, new class implements CredentialsResolver
    {
        public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
        {
            return $dispatched;
        }
    });
}

it('retries an expired token once with re-resolved credentials when a resolver is bound', function () {
    liBindResolver();

    $expired = liCreateError('error_401_expired', 401);

    expect($expired)->toBeInstanceOf(TemporaryException::class)
        ->and($expired->context['reresolve'])->toBeTrue()
        ->and($expired->retryAfter)->toBe(0)
        ->and($expired->getPrevious())->toBeInstanceOf(PermanentException::class);
});

it('never re-resolves a revoked token', function () {
    liBindResolver();

    $revoked = liCreateError('error_401_revoked', 401);

    expect($revoked)->toBeInstanceOf(PermanentException::class)
        ->and($revoked->isTerminal())->toBeTrue();
});

it('falls back to the base classification with the LinkedIn context added', function () {
    $e = Kit::driver()->call('classifyError', new Illuminate\Http\Client\Response(new Response(418, [], '{"message":"teapot"}')), 'upload');

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Unknown)
        ->and($e->getMessage())->toBe('teapot (HTTP 418)')
        ->and($e->context['operation'])->toBe('upload')
        ->and($e->context['error'])->toBe(['message' => 'teapot']);
});
