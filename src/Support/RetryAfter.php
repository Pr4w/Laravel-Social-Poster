<?php

namespace SocialPoster\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * Reads how long a platform asked us to wait, from the headers every platform
 * spells differently:
 *
 * - `Retry-After` (RFC 9110): delta-seconds or an HTTP-date;
 * - `x-rate-limit-reset` (X), `x-ratelimit-reset` and `ratelimit-reset`
 *   (Bluesky, Pinterest, the IETF RateLimit draft): an absolute unix time, or a
 *   delta in seconds when the value is too small to be a timestamp.
 *
 * Returns whole seconds (0 when the moment already passed) or null when the
 * response says nothing. Callers clamp the value before using it as a queue
 * delay (AbstractPlatform::clampDelay(), the jobs' own clamps).
 */
final class RetryAfter
{
    /** Headers tried after Retry-After, in order. */
    public const RESET_HEADERS = ['x-rate-limit-reset', 'x-ratelimit-reset', 'ratelimit-reset'];

    /** Values at or above this are unix timestamps (2001-09-09), below are deltas. */
    private const EPOCH_THRESHOLD = 1_000_000_000;

    /** Values at or above this are unix timestamps in milliseconds. */
    private const EPOCH_MS_THRESHOLD = 1_000_000_000_000;

    /**
     * @param  mixed  $response  An Illuminate\Http\Client\Response or any PSR-7 response.
     * @param  ?int  $now  Unix time to measure against (tests); defaults to time().
     */
    public static function fromResponse($response, ?int $now = null): ?int
    {
        $headers = [];

        try {
            if (is_object($response) && method_exists($response, 'headers')) {
                $headers = (array) $response->headers();
            } elseif ($response instanceof ResponseInterface) {
                $headers = $response->getHeaders();
            }
        } catch (\Throwable) {
            return null;
        }

        return self::fromHeaders($headers, $now);
    }

    /**
     * @param  array<string, string|array<int, string>>  $headers  Header name => value(s); names are case-insensitive.
     */
    public static function fromHeaders(array $headers, ?int $now = null): ?int
    {
        $normalised = [];

        foreach ($headers as $name => $value) {
            $value = is_array($value) ? reset($value) : $value;

            if (is_scalar($value) && trim((string) $value) !== '') {
                $normalised[strtolower((string) $name)] = trim((string) $value);
            }
        }

        if (isset($normalised['retry-after'])) {
            $seconds = self::parseRetryAfter($normalised['retry-after'], $now);

            if ($seconds !== null) {
                return $seconds;
            }
        }

        foreach (self::RESET_HEADERS as $name) {
            if (isset($normalised[$name])) {
                $seconds = self::parseReset($normalised[$name], $now);

                if ($seconds !== null) {
                    return $seconds;
                }
            }
        }

        return null;
    }

    /** Retry-After: delta-seconds, or an HTTP-date. */
    public static function parseRetryAfter(string $value, ?int $now = null): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return max(0, (int) ceil((float) $value));
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - ($now ?? time()));
    }

    /** A reset header: unix seconds or milliseconds, or a delta for small values. */
    public static function parseReset(string $value, ?int $now = null): ?int
    {
        $value = trim($value);

        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if ($number >= self::EPOCH_MS_THRESHOLD) {
            $number /= 1000;
        }

        if ($number >= self::EPOCH_THRESHOLD) {
            return max(0, (int) ceil($number) - ($now ?? time()));
        }

        return max(0, (int) ceil($number));
    }
}
