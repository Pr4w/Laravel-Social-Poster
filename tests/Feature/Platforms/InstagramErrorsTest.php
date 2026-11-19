<?php

namespace SocialPoster\Tests\Feature\Platforms;

use const SocialPoster\Tests\Fixtures\Instagram\IG_MEDIA;

use GuzzleHttp\Psr7\Response;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;

use function SocialPoster\Tests\Fixtures\Instagram\igBindResolver;
use function SocialPoster\Tests\Fixtures\Instagram\igCatch;
use function SocialPoster\Tests\Fixtures\Instagram\igCreds;
use function SocialPoster\Tests\Fixtures\Instagram\igDriver;
use function SocialPoster\Tests\Fixtures\Instagram\igErr;
use function SocialPoster\Tests\Fixtures\Instagram\igErrorResponse;
use function SocialPoster\Tests\Fixtures\Instagram\igFake;
use function SocialPoster\Tests\Fixtures\Instagram\igPost;

require_once __DIR__.'/../../Fixtures/instagram/helpers.php';

beforeEach(function () {
    config(['social.api.instagram.graph_version' => 'v25.0']);
});

/*
 * Every row of the Instagram table, from production-shaped Graph bodies
 * (tests/Fixtures/instagram/errors.json). Subcodes are classified before the
 * generic code they ride on, so 2207051 (code 4) is a spam block, not throttling.
 */
it('classifies Instagram errors', function (string $fixture, string $class, FailureReason $reason, ?int $retryAfter, array $context) {
    $e = igDriver()->call('classifyError', igErrorResponse($fixture), igPost());

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason);

    if ($e instanceof TemporaryException) {
        expect($e->retryAfter)->toBe($retryAfter);
    }

    foreach ($context as $key => $value) {
        expect($e->context)->toHaveKey($key, $value);
    }
})->with([
    // Instagram subcodes
    '2207042 daily limit' => ['daily_limit', PermanentException::class, FailureReason::RateLimited, null, ['daily_limit' => true, 'retry_after_hint' => 86400, 'subcode' => 2207042]],
    '2207052 media not fetchable' => ['media_fetch_failed', PermanentException::class, FailureReason::MediaRejected, null, ['subcode' => 2207052, 'code' => 9004]],
    '2207020 container expired' => ['container_expired', TemporaryException::class, FailureReason::ServerError, 60, ['restart' => true]],
    '2207008 builder expired' => ['media_builder_expired', TemporaryException::class, FailureReason::ServerError, 60, ['restart' => true]],
    '2207053 unknown upload' => ['unknown_upload', TemporaryException::class, FailureReason::ServerError, 60, ['restart' => true]],
    '2207006 media not found' => ['media_not_found', TemporaryException::class, FailureReason::ServerError, 60, ['restart' => true]],
    '2207001 server error' => ['server_error', TemporaryException::class, FailureReason::ServerError, 60, ['subcode' => 2207001]],
    '2207003 download timeout' => ['download_timeout', TemporaryException::class, FailureReason::ServerError, 60, []],
    '2207032 create failed' => ['create_failed', TemporaryException::class, FailureReason::ServerError, 60, []],
    '2207027 not ready' => ['not_ready', TemporaryException::class, FailureReason::ServerError, 60, ['not_ready' => true]],
    '2207026 video format' => ['unsupported_video', PermanentException::class, FailureReason::MediaRejected, null, ['code' => 352]],
    '2207004 image too large' => ['image_too_large', PermanentException::class, FailureReason::MediaRejected, null, []],
    '2207005 image format' => ['image_format', PermanentException::class, FailureReason::MediaRejected, null, []],
    '2207009 aspect ratio' => ['aspect_ratio', PermanentException::class, FailureReason::MediaRejected, null, []],
    '2207028 carousel size' => ['carousel_count', PermanentException::class, FailureReason::MediaRejected, null, []],
    '2207023 media type' => ['unknown_media_type', PermanentException::class, FailureReason::MediaRejected, null, []],
    '2207010 caption' => ['caption_too_long', PermanentException::class, FailureReason::InvalidContent, null, []],
    '2207040 tags' => ['too_many_tags', PermanentException::class, FailureReason::InvalidContent, null, []],
    '2207057 thumb offset (code 1, not transient)' => ['bad_thumb_offset', PermanentException::class, FailureReason::InvalidContent, null, ['code' => 1]],
    '2207035 product tags' => ['product_tags', PermanentException::class, FailureReason::InvalidContent, null, []],
    '2207050 restricted (1.0 row)' => ['restricted', PermanentException::class, FailureReason::AccountRestricted, null, []],
    '2207051 spam block (code 4)' => ['spam_block', PermanentException::class, FailureReason::AccountRestricted, null, ['spam_block' => true, 'code' => 4]],
    '2207069 too many actions (1.0 row)' => ['too_many_actions', TemporaryException::class, FailureReason::RateLimited, 90, []],
    // 1.0 message rows
    'not an Instagram Business (1.0 row)' => ['not_business', PermanentException::class, FailureReason::AccountRestricted, null, []],
    'unexpected error (1.0 row)' => ['unexpected_error', TemporaryException::class, FailureReason::ServerError, 60, []],
    'reduce the amount of data' => ['reduce_data', TemporaryException::class, FailureReason::ServerError, 60, []],
    // Shared Meta table
    '190/463 expired token' => ['token_expired', PermanentException::class, FailureReason::InvalidToken, null, ['terminal' => true]],
    '190 invalid token' => ['token_invalid', PermanentException::class, FailureReason::InvalidToken, null, ['terminal' => false, 'reresolvable' => true]],
    '190/460 password changed' => ['password_changed', PermanentException::class, FailureReason::InvalidToken, null, ['terminal' => true]],
    '4 app throttle' => ['app_throttle', TemporaryException::class, FailureReason::RateLimited, 900, []],
    '17 user throttle' => ['user_throttle', TemporaryException::class, FailureReason::RateLimited, 900, []],
    '10 permission' => ['permission', PermanentException::class, FailureReason::InsufficientPermissions, null, []],
    '200 comment permission' => ['comment_permission', PermanentException::class, FailureReason::InsufficientPermissions, null, []],
    '100/33 lost access' => ['object_missing', PermanentException::class, FailureReason::InsufficientPermissions, null, []],
    'transient 503' => ['graph_503', TemporaryException::class, FailureReason::ServerError, 60, []],
    'html 502' => ['html_502', TemporaryException::class, FailureReason::ServerError, 60, []],
    // Fallback by HTTP status
    'unmapped 400' => ['invalid_parameter', PermanentException::class, FailureReason::Unknown, null, ['status' => 400]],
]);

it('writes readable messages and keeps Meta’s own text', function () {
    $media = igDriver()->call('classifyError', igErrorResponse('media_fetch_failed'));
    $daily = igDriver()->call('classifyError', igErrorResponse('daily_limit'));

    expect($media->getMessage())
        ->toStartWith('Instagram could not download the media; the URL must be public and long-lived.')
        ->toContain('could not be fetched from this uri')
        ->and($media->context)->toMatchArray([
            'error_user_title' => 'Media download has failed.',
            'raw_message' => 'Only photo or video can be accepted as media type.',
            'fbtrace_id' => 'D7eF8gH9',
        ])
        ->and($daily->context['retry_at'])->toBe(gmdate('Y-m-d\TH:i:s\Z', strtotime($daily->context['retry_at'])))
        ->and(strtotime($daily->context['retry_at']))->toBeGreaterThan(time() + 86000);
});

it('honours a configured daily-limit retry hint', function () {
    config(['social.api.instagram.daily_limit_retry_after' => 7200]);

    $e = igDriver()->call('classifyError', igErrorResponse('daily_limit'));

    expect($e->context['retry_after_hint'])->toBe(7200);
});

it('calls a 190 caused by a host and token mismatch Misconfigured, not a dead token', function (array $credentials) {
    $e = igDriver()->call('classifyError', igErrorResponse('cannot_parse_token'), igPost(credentials: igCreds($credentials)));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Misconfigured)
        ->and($e->context['host_mismatch'])->toBeTrue()
        ->and($e->getMessage())->toContain("'login'");
})->with([
    'facebook login with an Instagram Login token' => [['login' => 'facebook', 'access_token' => 'IGAAJtoken']],
    'instagram login with a Facebook token' => [['login' => 'instagram', 'access_token' => 'EAAJtoken']],
]);

it('keeps a 190 as InvalidToken when the login matches the token, or nothing says which', function (array $credentials, bool $withPost) {
    $post = igPost(credentials: igCreds($credentials));
    $e = $withPost
        ? igDriver()->call('classifyError', igErrorResponse('cannot_parse_token'), $post)
        : igDriver()->call('classifyError', igErrorResponse('cannot_parse_token'));

    expect($e->reason)->toBe(FailureReason::InvalidToken);
})->with([
    'detected from the token' => [['access_token' => 'IGAAJtoken'], true],
    'explicit and matching' => [['login' => 'instagram', 'access_token' => 'IGAAJtoken'], true],
    'opaque token' => [['login' => 'instagram', 'access_token' => 'opaque'], true],
    'no post given' => [['login' => 'facebook', 'access_token' => 'IGAAJtoken'], false],
]);

it('reports the mismatch from a real request, without a re-resolve', function () {
    igBindResolver();
    igFake([IG_MEDIA => igErr('cannot_parse_token')]);

    $e = igCatch(fn () => igDriver()->publish(igPost(credentials: igCreds(['login' => 'facebook', 'access_token' => 'IGAAJtoken']))));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Misconfigured)
        ->and($e->context)->toMatchArray(['http_status' => 400, 'code' => 190, 'trace_id' => 'A9fK2Lm0'])
        ->and($e->context)->not->toHaveKey('reresolve');
});

it('enriches mapped errors with the HTTP status and identifiers', function () {
    igFake([IG_MEDIA => igErr('unsupported_video')]);

    $e = igCatch(fn () => igDriver()->publish(igPost()));

    expect($e->context)->toMatchArray([
        'http_status' => 400,
        'code' => 352,
        'subcode' => 2207026,
        'trace_id' => 'M7nO8pQ9',
    ]);
});

it('keeps tokens out of exception messages and contexts', function () {
    igFake([IG_MEDIA => igErr('token_invalid')]);

    $e = igCatch(fn () => igDriver()->publish(igPost(credentials: igCreds(['access_token' => 'EAAsecret-value']))));

    expect($e->getMessage())->not->toContain('EAAsecret-value')
        ->and(json_encode($e->context))->not->toContain('EAAsecret-value');
});

it('falls back to the HTTP status for an empty error body', function (int $status, string $class, FailureReason $reason) {
    $response = new \Illuminate\Http\Client\Response(new Response($status, ['Content-Type' => 'application/json'], '{}'));

    $e = igDriver()->call('classifyError', $response, igPost());

    expect($e)->toBeInstanceOf($class)->and($e->reason)->toBe($reason);
})->with([
    '401' => [401, PermanentException::class, FailureReason::InvalidToken],
    '404' => [404, PermanentException::class, FailureReason::NotFound],
    '429' => [429, TemporaryException::class, FailureReason::RateLimited],
    '500' => [500, TemporaryException::class, FailureReason::ServerError],
]);
