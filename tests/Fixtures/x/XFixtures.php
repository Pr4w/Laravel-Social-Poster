<?php

namespace SocialPoster\Tests\Fixtures\x;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\SocialPost;

/**
 * Shared helpers for the X driver tests: production-shaped response bodies
 * (the *.json files next to this class), tiny temp media files, prepared posts
 * and multipart inspection.
 */
final class XFixtures
{
    /** A 1x1 PNG, for content-sniffing tests. */
    public const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private static array $files = [];

    /** @return array<string, mixed> */
    public static function json(string $name): array
    {
        $decoded = json_decode((string) file_get_contents(__DIR__.'/'.$name.'.json'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** A temp file with the given extension ('' for none) and contents. */
    public static function file(string $extension, string $contents = 'abcdefghij'): string
    {
        $path = sys_get_temp_dir().'/sp-x-'.bin2hex(random_bytes(6)).($extension !== '' ? '.'.$extension : '');
        file_put_contents($path, $contents);
        self::$files[] = $path;

        return $path;
    }

    public static function png(): string
    {
        return self::file('png', (string) base64_decode(self::PNG_1X1));
    }

    public static function cleanup(): void
    {
        foreach (self::$files as $path) {
            @unlink($path);
        }

        self::$files = [];
    }

    /** @param  array<string, mixed>  $extra */
    public static function credentials(array $extra = []): Credentials
    {
        return new Credentials(array_merge(['access_token' => 'x-token'], $extra));
    }

    /**
     * @param  Media[]  $media
     * @param  array<string, mixed>  $credentials  Merged over the default token.
     */
    public static function post(
        array $media = [],
        ?string $caption = 'Hello from the package',
        ?PlatformOptions $options = null,
        ?string $comment = null,
        array $credentials = [],
        ?Credentials $using = null,
    ): PreparedPost {
        return new PreparedPost(
            Platform::X,
            new SocialPost(media: $media, caption: $caption, options: $options, comment: $comment),
            $using ?? self::credentials($credentials),
        );
    }

    /** The contents of a multipart field, or null when the request has none. */
    public static function field(Request $request, string $name): mixed
    {
        foreach ((array) $request->data() as $part) {
            if (is_array($part) && ($part['name'] ?? null) === $name) {
                $contents = $part['contents'] ?? null;

                return is_object($contents) && method_exists($contents, '__toString') ? (string) $contents : $contents;
            }
        }

        return null;
    }

    /** The URL path of a request, without the host or query. */
    public static function path(Request $request): string
    {
        return (string) parse_url($request->url(), PHP_URL_PATH);
    }

    /**
     * Http::fake() routes for the whole media + post surface of the default
     * host. $overrides come first, so they win over the defaults.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function routes(array $overrides = []): array
    {
        return $overrides + [
            'api.x.com/2/media/upload/initialize' => Http::response(self::json('media_initialized'), 200),
            'api.x.com/2/media/upload/*/append' => Http::response('', 204),
            'api.x.com/2/media/upload/*/finalize' => Http::response(self::json('finalize_without_processing_info'), 200),
            'api.x.com/2/media/upload?*' => Http::response(self::json('status_succeeded'), 200),
            'api.x.com/2/media/upload' => Http::response(self::json('image_uploaded'), 200),
            'api.x.com/2/media/metadata' => Http::response(self::json('metadata_created'), 200),
            'api.x.com/2/tweets' => Http::response(self::json('tweet_created'), 201),
        ];
    }

    /**
     * A fake route that fails like cURL (a rejected ConnectException, which the
     * client turns into a ConnectionException). Works on every supported
     * Laravel version, unlike Http::failedConnection().
     */
    public static function connectionFailure(string $message = 'cURL error 28: Operation timed out after 120001 milliseconds with 0 bytes received'): \Closure
    {
        return static fn (Request $request) => Create::rejectionFor(new ConnectException($message, $request->toPsrRequest()));
    }

    /**
     * Every recorded request whose URL path ends with $suffix, in order.
     *
     * @return list<Request>
     */
    public static function sent(string $suffix): array
    {
        return Http::recorded(fn (Request $request) => str_ends_with(self::path($request), $suffix))
            ->map(fn (array $pair) => $pair[0])
            ->values()
            ->all();
    }
}
