<?php

namespace SocialPoster\Tests\ThreadsFixtures;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;
use SocialPoster\Platforms\Threads\ThreadsDriver;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\SocialPost;
use Throwable;

/**
 * Shared helpers for the Threads driver tests. Loaded with require_once (its
 * namespace deliberately maps to no PSR-4 path, so it can never load twice).
 *
 * fake() routes requests by "METHOD path" (the path after the Graph version,
 * e.g. "POST 1784/threads", "GET C1"). Each route holds a list of responses
 * served in order, the last one repeating. A plain array is a 200 JSON body;
 * a Closure receives the Request. Unrouted requests are stray and fail.
 */
final class ThreadsKit
{
    public const ACCOUNT = '1784';

    public const TOKEN = 'THAAtok123';

    public const BASE = 'https://graph.threads.net/v1.0/';

    /** @param array<string, MediaMetadata> $metadata */
    public static function driver(array $metadata = []): ThreadsDriver
    {
        return new ThreadsDriver($metadata === [] ? null : self::inspector($metadata));
    }

    /**
     * @param  Media[]  $media
     * @param  array<string, mixed>|null  $credentials
     */
    public static function post(
        array $media = [],
        ?string $caption = 'Hello Threads',
        ?PlatformOptions $options = null,
        ?array $credentials = null,
        ?string $comment = null,
    ): PreparedPost {
        return new PreparedPost(
            Platform::Threads,
            new SocialPost(media: $media, caption: $caption, options: $options, comment: $comment),
            new Credentials($credentials ?? ['account_id' => self::ACCOUNT, 'access_token' => self::TOKEN]),
        );
    }

    /** @param array<string, MediaMetadata> $bySource */
    public static function inspector(array $bySource): MediaInspector
    {
        return new class($bySource) implements MediaInspector
        {
            /** @param array<string, MediaMetadata> $bySource */
            public function __construct(private array $bySource) {}

            public function supports(Media $media): bool
            {
                return isset($this->bySource[$media->source]);
            }

            public function inspect(Media $media): MediaMetadata
            {
                return $this->bySource[$media->source];
            }
        };
    }

    /** @param array<string, list<array<string, mixed>|Closure>> $routes */
    public static function fake(array $routes): void
    {
        $served = [];

        Http::fake(function (Request $request) use ($routes, &$served) {
            $key = $request->method().' '.self::path($request->url());

            if (! array_key_exists($key, $routes)) {
                return null;
            }

            $list = array_values($routes[$key]);
            $index = min($served[$key] ?? 0, count($list) - 1);
            $served[$key] = ($served[$key] ?? 0) + 1;
            $spec = $list[$index];

            return $spec instanceof Closure ? $spec($request) : Http::response($spec, 200);
        });
    }

    /**
     * @param  array<string, mixed>|string  $body
     * @param  array<string, string>  $headers
     */
    public static function json(array|string $body, int $status = 200, array $headers = []): Closure
    {
        return static fn () => Http::response($body, $status, $headers);
    }

    /** A Graph error body from tests/Fixtures/threads/errors/<name>.json. */
    public static function error(string $name, int $status = 400): Closure
    {
        return self::json(self::errorBody($name), $status);
    }

    /** @return array<string, mixed> */
    public static function errorBody(string $name): array
    {
        $path = __DIR__.'/errors/'.$name.'.json';
        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Bad fixture {$path}");
        }

        return $decoded;
    }

    /** A transport failure (the request may have been sent: a read timeout). */
    public static function timeout(): Closure
    {
        return static fn (Request $request) => Create::rejectionFor(new ConnectException(
            'cURL error 28: Operation timed out after 120001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '.$request->url(),
            $request->toPsrRequest(),
        ));
    }

    /** A transport failure before anything was sent (cURL 7, connection refused). */
    public static function refused(): Closure
    {
        return static fn (Request $request) => Create::rejectionFor(new ConnectException(
            'cURL error 7: Failed to connect to graph.threads.net port 443 after 3 ms: Connection refused (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '.$request->url(),
            $request->toPsrRequest(),
            null,
            ['errno' => 7],
        ));
    }

    /**
     * The recorded requests for one route, in order.
     *
     * @return list<Request>
     */
    public static function sent(string $key): array
    {
        $requests = [];

        foreach (Http::recorded() as [$request]) {
            if ($request->method().' '.self::path($request->url()) === $key) {
                $requests[] = $request;
            }
        }

        return $requests;
    }

    /** Every recorded route key, in order. @return list<string> */
    public static function routes(): array
    {
        $keys = [];

        foreach (Http::recorded() as [$request]) {
            $keys[] = $request->method().' '.self::path($request->url());
        }

        return $keys;
    }

    /** Run $fn and return what it threw. */
    public static function catch(callable $fn): Throwable
    {
        try {
            $fn();
        } catch (Throwable $e) {
            return $e;
        }

        throw new RuntimeException('Expected an exception.');
    }

    /** "https://graph.threads.net/v1.0/1784/threads?x=y" → "1784/threads" */
    public static function path(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return (string) preg_replace('#^/v[0-9.]+/#', '', $path);
    }

    /** The query parameters of a recorded request. @return array<string, mixed> */
    public static function query(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }
}
