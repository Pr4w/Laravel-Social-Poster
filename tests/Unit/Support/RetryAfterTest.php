<?php

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use SocialPoster\Support\RetryAfter;

const RETRY_AFTER_NOW = 1_800_000_000;

/** @param array<string, string|array<int, string>> $headers */
function retryAfterResponse(array $headers): Response
{
    return new Response(new PsrResponse(429, $headers, ''));
}

it('parses Retry-After as delta seconds', function (string $value, ?int $expected) {
    expect(RetryAfter::parseRetryAfter($value, RETRY_AFTER_NOW))->toBe($expected);
})->with([
    'integer' => ['120', 120],
    'zero' => ['0', 0],
    'padded' => [' 30 ', 30],
    'decimal rounds up' => ['1.2', 2],
    'negative clamps to zero' => ['-5', 0],
    'blank' => ['', null],
    'garbage' => ['soon', null],
]);

it('parses Retry-After as an HTTP-date', function () {
    $future = gmdate('D, d M Y H:i:s', RETRY_AFTER_NOW + 90).' GMT';
    $past = gmdate('D, d M Y H:i:s', RETRY_AFTER_NOW - 90).' GMT';

    expect(RetryAfter::parseRetryAfter($future, RETRY_AFTER_NOW))->toBe(90)
        ->and(RetryAfter::parseRetryAfter($past, RETRY_AFTER_NOW))->toBe(0);
});

it('parses reset headers as epoch seconds, epoch milliseconds or small deltas', function (string $value, ?int $expected) {
    expect(RetryAfter::parseReset($value, RETRY_AFTER_NOW))->toBe($expected);
})->with([
    'epoch seconds (X)' => [(string) (RETRY_AFTER_NOW + 600), 600],
    'epoch milliseconds' => [(string) ((RETRY_AFTER_NOW + 45) * 1000), 45],
    'epoch in the past' => [(string) (RETRY_AFTER_NOW - 10), 0],
    'delta seconds (IETF draft)' => ['60', 60],
    'not a number' => ['tomorrow', null],
]);

it('prefers Retry-After, then the reset headers in order', function () {
    expect(RetryAfter::fromHeaders([
        'Retry-After' => '15',
        'x-rate-limit-reset' => (string) (RETRY_AFTER_NOW + 600),
    ], RETRY_AFTER_NOW))->toBe(15)
        ->and(RetryAfter::fromHeaders([
            'x-ratelimit-reset' => (string) (RETRY_AFTER_NOW + 300),
            'x-rate-limit-reset' => (string) (RETRY_AFTER_NOW + 600),
        ], RETRY_AFTER_NOW))->toBe(600)
        ->and(RetryAfter::fromHeaders([
            'RateLimit-Reset' => (string) (RETRY_AFTER_NOW + 30),
        ], RETRY_AFTER_NOW))->toBe(30);
});

it('falls through an unreadable Retry-After to a reset header', function () {
    expect(RetryAfter::fromHeaders([
        'Retry-After' => 'later',
        'x-ratelimit-reset' => (string) (RETRY_AFTER_NOW + 120),
    ], RETRY_AFTER_NOW))->toBe(120);
});

it('returns null when the response says nothing', function () {
    expect(RetryAfter::fromHeaders([], RETRY_AFTER_NOW))->toBeNull()
        ->and(RetryAfter::fromHeaders(['Retry-After' => ''], RETRY_AFTER_NOW))->toBeNull()
        ->and(RetryAfter::fromHeaders(['Retry-After' => []], RETRY_AFTER_NOW))->toBeNull()
        ->and(RetryAfter::fromResponse(retryAfterResponse([])))->toBeNull()
        ->and(RetryAfter::fromResponse(null))->toBeNull();
});

it('reads Laravel and PSR-7 responses, header names case-insensitively', function () {
    $laravel = retryAfterResponse(['RETRY-AFTER' => '42']);
    $psr = new PsrResponse(429, ['X-Rate-Limit-Reset' => (string) (RETRY_AFTER_NOW + 75)]);

    expect(RetryAfter::fromResponse($laravel, RETRY_AFTER_NOW))->toBe(42)
        ->and(RetryAfter::fromResponse($psr, RETRY_AFTER_NOW))->toBe(75)
        ->and(RetryAfter::fromHeaders(['retry-after' => ['7', '9']], RETRY_AFTER_NOW))->toBe(7);
});

it('measures against the current time by default', function () {
    $seconds = RetryAfter::fromHeaders(['x-rate-limit-reset' => (string) (time() + 100)]);

    expect($seconds)->toBeGreaterThanOrEqual(98)->toBeLessThanOrEqual(100);
});
