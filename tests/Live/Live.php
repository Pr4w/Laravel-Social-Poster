<?php

namespace SocialPoster\Tests\Live;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SocialPoster\ValueObjects\PostResult;

/**
 * Helpers for the opt-in live checks in tests/Live (see README.md there).
 *
 * Every live test file asks Live::env() for its platform's credentials when
 * Pest collects it. Without them the file registers one skipped placeholder
 * and stops, so a normal test run sends nothing anywhere. With them, the
 * read-only probes run; anything that creates content on the platform also
 * needs SOCIAL_LIVE_PUBLISH=1.
 *
 * Observations (status, headers, bodies) are appended as JSON lines to
 * SOCIAL_LIVE_OUTPUT (default: the system temp dir), one file per platform,
 * with tokens redacted, so the checks that need a human eye can be read
 * afterwards.
 */
final class Live
{
    /**
     * The platform's SOCIAL_LIVE_<PLATFORM>_<KEY> variables, or null when a
     * required one is missing. Optional keys are null when unset.
     *
     * @param  list<string>  $required
     * @param  list<string>  $optional
     * @return array<string, ?string>|null
     */
    public static function env(string $platform, array $required, array $optional = []): ?array
    {
        $values = [];

        foreach ($required as $key) {
            $value = self::read("SOCIAL_LIVE_{$platform}_{$key}");

            if ($value === null) {
                return null;
            }

            $values[$key] = $value;
        }

        foreach ($optional as $key) {
            $values[$key] = self::read("SOCIAL_LIVE_{$platform}_{$key}");
        }

        return $values;
    }

    /** @param  list<string>  $required */
    public static function skipMessage(string $platform, array $required): string
    {
        $names = array_map(fn (string $key) => "SOCIAL_LIVE_{$platform}_{$key}", $required);

        return 'Live checks are opt-in: set '.implode(', ', $names).' (see tests/Live/README.md).';
    }

    /** Whether tests that create content on the platform may run. */
    public static function publishing(): bool
    {
        return filter_var(self::read('SOCIAL_LIVE_PUBLISH') ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** Skip the current test unless SOCIAL_LIVE_PUBLISH=1. */
    public static function requirePublishing(): void
    {
        if (! self::publishing()) {
            test()->markTestSkipped('Creates content on the platform: set SOCIAL_LIVE_PUBLISH=1 to run it.');
        }
    }

    /** Skip the current test unless the optional value is set. */
    public static function requireValue(?string $value, string $variable): string
    {
        if ($value === null) {
            test()->markTestSkipped("Set {$variable} to run this check.");
        }

        return (string) $value;
    }

    /**
     * Undo the unit-test isolation for a live run: real HTTP, and the
     * private-network guard back on.
     */
    public static function boot(): void
    {
        Http::preventStrayRequests(false);

        config([
            'social.media.block_private_networks' => true,
            'social.error_logging' => true,
        ]);
    }

    /**
     * Append what the platform answered to the platform's observation log.
     * Returns the decoded body for convenience.
     */
    public static function record(string $platform, string $check, Response|PostResult|array|string|null $observed): mixed
    {
        $entry = ['at' => gmdate(DATE_ATOM), 'check' => $check];

        if ($observed instanceof Response) {
            $entry += [
                'status' => $observed->status(),
                'headers' => self::safeHeaders($observed->headers()),
                'body' => $observed->json() ?? self::redact(mb_substr($observed->body(), 0, 4000)),
            ];
            $decoded = $observed->json();
        } elseif ($observed instanceof PostResult) {
            $entry['result'] = $observed->toArray();
            $decoded = $observed;
        } else {
            $entry['observed'] = $observed;
            $decoded = $observed;
        }

        $dir = self::read('SOCIAL_LIVE_OUTPUT') ?? sys_get_temp_dir().'/social-poster-live';
        @mkdir($dir, 0775, true);

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        file_put_contents($dir.'/'.strtolower($platform).'.jsonl', self::redact((string) $line).PHP_EOL, FILE_APPEND);

        return $decoded;
    }

    /** A caption unique to this run, so lookups find exactly the post just made. */
    public static function caption(string $prefix = 'social-poster live check'): string
    {
        return $prefix.' '.gmdate('Y-m-d H:i:s').' #'.bin2hex(random_bytes(3));
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @return array<string, list<string>>
     */
    private static function safeHeaders(array $headers): array
    {
        $secret = ['authorization', 'set-cookie', 'cookie', 'dpop', 'x-api-key'];

        return array_filter($headers, fn (string $name) => ! in_array(strtolower($name), $secret, true), ARRAY_FILTER_USE_KEY);
    }

    private static function redact(string $text): string
    {
        return (string) preg_replace('/((?:access_token|token|key|signature|password|accessJwt|refreshJwt)["=:\s]+"?)[^&\s"]+/i', '$1[redacted]', $text);
    }

    private static function read(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
