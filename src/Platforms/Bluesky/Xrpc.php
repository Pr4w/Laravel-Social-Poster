<?php

namespace SocialPoster\Platforms\Bluesky;

use Illuminate\Http\Client\Response;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Support\RetryAfter;
use Throwable;

/**
 * XRPC details BlueskyDriver and DefaultBlueskyAuthorizer share, so the two
 * never disagree on them: what a usable PDS URL is, and how a 429 is handled.
 *
 * @internal
 */
final class Xrpc
{
    /** The longest window of the PDS write budgets (35,000 points a day). */
    public const DAY = 86400;

    /**
     * A PDS origin from a credential: http(s) only, without a trailing '/' or
     * '/xrpc' (the XRPC path is appended to it). Null for anything else, so the
     * caller falls back to the DID document or the entryway.
     */
    public static function pdsUrl(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('~^https?://~i', trim($value)) !== 1) {
            return null;
        }

        return (string) preg_replace('~/xrpc/?$~', '', rtrim(trim($value), '/'));
    }

    /**
     * A 429 (or RateLimitExceeded). A reset within $maxWait seconds is waited
     * out in the queue (Temporary; 300 s when the response names no reset). A
     * reset farther away is never waited out (owner decision on daily quotas):
     * Permanent RateLimited with retry_at / retry_after_hint, and daily_limit
     * when the ratelimit-policy window is a day (the PDS write budget is
     * 35,000 points a day, createSession 300 a day), or, without a policy, when
     * the reset is over an hour away (the hourly windows cannot be).
     *
     * @param  string  $message  What was limited, without a final full stop.
     * @param  array<string, mixed>  $context
     */
    public static function rateLimited(Response $response, string $message, int $maxWait, array $context = []): TemporaryException|PermanentException
    {
        $wait = RetryAfter::fromResponse($response);
        $window = self::policyWindow($response);
        $context += $window !== null ? ['rate_limit_window' => $window] : [];

        if ($wait === null || $wait <= max(1, $maxWait)) {
            return new TemporaryException($message.'.', Platform::Bluesky, FailureReason::RateLimited, $wait !== null ? max(1, $wait) : 300, $context);
        }

        $retryAt = gmdate('Y-m-d\TH:i:s\Z', time() + $wait);
        $daily = $window !== null ? $window >= self::DAY : $wait > 3600;

        return new PermanentException(
            $message.($daily ? '; this is the daily limit' : '').'. It resets at '.$retryAt.': try again after that.',
            Platform::Bluesky,
            FailureReason::RateLimited,
            $context + [
                'daily_limit' => $daily,
                'retry_after_hint' => $wait,
                'retry_at' => $retryAt,
            ],
        );
    }

    /** The window (w=, in seconds) of the ratelimit-policy header, e.g. "35000;w=86400". */
    public static function policyWindow(Response $response): ?int
    {
        try {
            $policy = $response->header('ratelimit-policy');
        } catch (Throwable) {
            return null;
        }

        return preg_match('/;\s*w=(\d+)/i', $policy, $match) === 1 ? (int) $match[1] : null;
    }
}
