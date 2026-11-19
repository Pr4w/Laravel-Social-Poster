<?php

namespace SocialPoster\Tests\Feature\Platforms\PinterestErrorsTest;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\Pinterest\PinterestImageTransfer;
use SocialPoster\Platforms\Pinterest\PinterestOptions;
use SocialPoster\Tests\PinterestFixtures\PinterestKit as K;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\SocialPost;

require_once __DIR__.'/../../Fixtures/pinterest/PinterestKit.php';

beforeEach(function () {
    Carbon::setTestNow('2026-11-18 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    K::cleanupFiles();
});

/** The image-pin create step with a canned failure (Url mode, so no base64 retry). */
function createWith(\Closure $failure, ?PreparedPost $post = null): \Throwable
{
    K::fake(['POST pins' => [$failure]]);

    $post ??= K::post([Media::image(K::IMG_A)], options: new PinterestOptions(imageTransfer: PinterestImageTransfer::Url));
    $driver = K::driver();

    return K::catch(fn () => $driver->resume($post, $driver->publish($post)->state));
}

// --- POST /pins error table ------------------------------------------------------------

it('maps a refused token to InvalidToken', function () {
    $e = createWith(K::error('unauthorized', 401));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::InvalidToken)
        ->and($e->isTerminal())->toBeFalse()
        ->and($e->getMessage())->toContain('reconnected')
        ->and($e->getMessage())->toContain('Authentication failed')
        ->and($e->context)->toMatchArray(['status' => 401, 'http_status' => 401, 'step' => 'pin', 'pinterest_code' => 2, 'code' => 2]);
});

it('retries a refused token once with re-resolved credentials when a resolver is bound', function () {
    config(['social.credentials_resolver' => new class implements CredentialsResolver
    {
        public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
        {
            return $dispatched;
        }
    }]);

    $e = createWith(K::error('unauthorized', 401));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::InvalidToken)
        ->and($e->retryAfter)->toBe(0)
        ->and($e->context['reresolve'])->toBeTrue()
        ->and($e->getPrevious())->toBeInstanceOf(PermanentException::class);
});

it('explains Trial access on 403 code 29, before the "not permitted" wording', function () {
    $e = createWith(K::error('trial_access', 403));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::InsufficientPermissions)
        ->and($e->getMessage())->toContain('Trial access')
        ->and($e->getMessage())->toContain('sandbox')
        ->and($e->context['trial_access'])->toBeTrue();
});

it('maps a 403 about the image to MediaRejected', function () {
    $e = createWith(K::error('image_broken', 403));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain('too small, too large or broken');
});

it('maps a 403 about permissions to InsufficientPermissions', function () {
    $e = createWith(K::error('missing_permission', 403));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::InsufficientPermissions);
});

it('maps a 404 to a missing board', function () {
    $e = createWith(K::error('board_not_found', 404));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::NotFound)
        ->and($e->getMessage())->toContain('Pinterest board no longer exists')
        ->and($e->context['target_not_found'])->toBeTrue();
});

it('mentions the section in a 404 when one was targeted', function () {
    $post = K::post([Media::image(K::IMG_A)], options: new PinterestOptions(boardSectionId: K::SECTION, imageTransfer: PinterestImageTransfer::Url));
    $e = createWith(K::json(['code' => 40, 'message' => 'Not found.'], 404), $post);

    expect($e->reason)->toBe(FailureReason::NotFound)
        ->and($e->getMessage())->toContain('board or board section');
});

it('maps "board not found" wording on any status to a missing board', function () {
    $e = createWith(K::json(['code' => 1, 'message' => 'Board not found.'], 400));

    expect($e->reason)->toBe(FailureReason::NotFound);
});

it('maps "not permitted" to InsufficientPermissions', function () {
    $e = createWith(K::error('not_permitted', 400));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::InsufficientPermissions)
        ->and($e->getMessage())->toContain('not permitted to save Pins');
});

/** The rate-limit headers Pinterest sends on ordinary responses (developers.pinterest.com/docs/reference/rate-limits). */
const PIN_RATE_HEADERS = ['x-ratelimit-limit' => '100, 100;w=1, 1000;w=60', 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '1'];

it('waits 300 seconds on a rate limit unless Pinterest sends Retry-After', function (array $headers, int $wait) {
    $e = createWith(K::error('rate_limited', 429, $headers));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::RateLimited)
        ->and($e->retryAfter)->toBe($wait);
})->with([
    'no header' => [[], 300],
    'Retry-After' => [['Retry-After' => '90'], 90],
    // x-ratelimit-reset is a quota-window reset sent on every response, not a wait.
    'x-ratelimit-reset delta' => [['x-ratelimit-reset' => '45'], 300],
    'Pinterest rate-limit headers' => [PIN_RATE_HEADERS, 300],
    'Retry-After beside them' => [PIN_RATE_HEADERS + ['Retry-After' => '120'], 120],
]);

it('retries server errors after 60 seconds', function (\Closure $failure) {
    $e = createWith($failure);

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::ServerError)
        ->and($e->retryAfter)->toBe(60);
})->with([
    '503 code 21' => [K::error('service_unavailable', 503)],
    '500 code 12' => [K::error('internal_error', 500)],
    '502 html' => [K::json('<html>Bad gateway</html>', 502)],
    '503 with x-ratelimit-reset 1' => [K::error('service_unavailable', 503, ['x-ratelimit-remaining' => '99'] + PIN_RATE_HEADERS)],
    '503 with a shorter Retry-After' => [K::error('service_unavailable', 503, ['Retry-After' => '5'])],
]);

it('waits longer on a server error when Retry-After asks for it', function () {
    $e = createWith(K::error('service_unavailable', 503, ['Retry-After' => '180']));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->retryAfter)->toBe(180);
});

it('retries a fetch failure after 120 seconds', function () {
    $e = createWith(K::error('fetch_failed', 400));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::Timeout)
        ->and($e->retryAfter)->toBe(120)
        ->and($e->context['fetch_failed'])->toBeTrue();
});

it('never treats a bare "download" as a fetch failure', function () {
    $e = createWith(K::json(['code' => 1, 'message' => 'Downloads are disabled for this board.'], 400));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Unknown);
});

it('matches the other fetch wordings', function (string $message) {
    $e = createWith(K::json(['code' => 1, 'message' => $message], 400));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::Timeout);
})->with([
    "Sorry, we couldn't fetch the image.",
    'Unable to reach the URL.',
]);

it('leaves an unmapped 400 to the base classification, with Pinterest\'s message', function () {
    Log::spy();
    config(['social.error_logging' => true]);

    $e = createWith(K::error('invalid_parameters', 400));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Unknown)
        ->and($e->getMessage())->toContain('Invalid parameters')
        ->and($e->context['code'])->toBe(1);

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => str_contains($message, 'pinterest API error')
        && $context['unmapped'] === true
        && $context['identifiers']['code'] === 1
        && ! str_contains(json_encode($context), K::TOKEN));
});

it('reads an error.message body', function () {
    $e = createWith(K::json(['error' => ['message' => 'Board not found.']], 400));

    expect($e->reason)->toBe(FailureReason::NotFound);
});

it('falls back to the base classification for an empty body', function () {
    $e = createWith(K::json('', 418));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Unknown)
        ->and($e->getMessage())->toContain('HTTP 418');
});

// --- Credentials -------------------------------------------------------------------------

it('needs an access token before any request', function () {
    K::fake([]);

    $e = K::catch(fn () => K::driver()->publish(K::post([Media::image(K::IMG_A)], credentials: ['board_id' => K::BOARD])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::InvalidToken)
        ->and($e->context['missing_credentials'])->toBe(['access_token']);
    Http::assertNothingSent();
});

it('needs a numeric board id', function (array $credentials, string $message) {
    K::fake([]);

    $e = K::catch(fn () => K::driver()->publish(K::post([Media::image(K::IMG_A)], credentials: $credentials)));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Misconfigured)
        ->and($e->getMessage())->toContain($message);
    Http::assertNothingSent();
})->with([
    'missing' => [['access_token' => K::TOKEN], 'board_id|account_id'],
    'blank' => [['access_token' => K::TOKEN, 'board_id' => '  '], 'board_id|account_id'],
    'a board URL' => [['access_token' => K::TOKEN, 'board_id' => 'acme/spring'], "got 'acme/spring'"],
    'non-numeric section' => [['access_token' => K::TOKEN, 'board_id' => K::BOARD, 'board_section_id' => 'spring'], "section id must be numeric, got 'spring'"],
    'foreign api_base' => [['access_token' => K::TOKEN, 'board_id' => K::BOARD, 'api_base' => 'https://evil.example.com/v5'], 'api_base'],
    'http api_base' => [['access_token' => K::TOKEN, 'board_id' => K::BOARD, 'api_base' => 'http://api.pinterest.com/v5'], 'api_base'],
]);

it('points a dispatched reference to the resolver', function () {
    $e = K::catch(fn () => K::driver()->publish(new PreparedPost(
        Platform::Pinterest,
        new SocialPost(media: [Media::image(K::IMG_A)]),
        Credentials::ref('pinterest:42', ['board_id' => K::BOARD]),
    )));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Misconfigured)
        ->and($e->getMessage())->toContain('CredentialsResolver');
});

it('refuses a token that already expired', function () {
    K::fake([]);

    $e = K::catch(fn () => K::driver()->publish(K::post([Media::image(K::IMG_A)], credentials: K::credentials(['expires_at' => Carbon::now()->subMinute()->getTimestamp()]))));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::InvalidToken)
        ->and($e->context['token_expired'])->toBeTrue();
});

it('checks the credentials again on resume', function () {
    K::fake([]);

    $e = K::catch(fn () => K::driver()->resume(K::post([Media::image(K::IMG_A)], credentials: ['board_id' => K::BOARD]), ['phase' => 'pin']));

    expect($e->reason)->toBe(FailureReason::InvalidToken);
});

// --- Media shapes at publish (validation skipped) ------------------------------------------

it('refuses media Pinterest cannot pin before any request', function (array $media, string $message) {
    K::fake([]);

    $e = K::catch(fn () => K::driver()->publish(K::post($media)));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain($message);
    Http::assertNothingSent();
})->with([
    'nothing' => [[], 'needs one image'],
    'a document' => [[Media::document('https://cdn.example.com/deck.pdf')], 'documents'],
    'video and image' => [[Media::video('https://cdn.example.com/a.mp4'), Media::image(K::IMG_A)], 'never both'],
    'two videos' => [[Media::video('https://cdn.example.com/a.mp4'), Media::video('https://cdn.example.com/b.mp4')], 'never both'],
    'six images' => [array_fill(0, 6, Media::image(K::IMG_A)), 'at most 5 images'],
]);
