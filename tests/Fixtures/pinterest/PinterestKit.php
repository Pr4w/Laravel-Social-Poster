<?php

namespace SocialPoster\Tests\PinterestFixtures;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SocialPoster\Contracts\MediaGateway;
use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;
use SocialPoster\Gateways\LocalMediaGateway;
use SocialPoster\Platforms\Pinterest\PinterestDriver;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\SocialPost;
use Throwable;

/**
 * Shared helpers for the Pinterest driver tests. Loaded with require_once (its
 * namespace maps to no PSR-4 path, so it can never load twice).
 *
 * fake() routes requests by "METHOD path": the path after /v5/ on a Pinterest
 * host ("POST pins", "GET media/5212…", "GET boards/987…/pins"), or "POST s3"
 * for the S3 upload. Each route holds a list of responses served in order, the
 * last one repeating. A plain array is a 200 JSON body; a Closure receives the
 * Request. Unrouted requests are stray and fail.
 */
final class PinterestKit
{
    public const BOARD = '987654321012345678';

    public const SECTION = '5195641648466264082';

    public const TOKEN = 'pina_AMAtok123';

    public const MEDIA_ID = '5212123920968240771';

    public const PIN_ID = '813744226420795884';

    public const S3 = 'https://pinterest-media-upload.s3-accelerate.amazonaws.com/';

    public const IMG_A = 'https://cdn.example.com/spring-a.jpg';

    public const IMG_B = 'https://cdn.example.com/spring-b.png';

    public const IMG_C = 'https://cdn.example.com/spring-c.jpg';

    /** PNG signature + a minimal IHDR, enough for signature sniffing. */
    public const PNG_BYTES = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xde";

    public const JPEG_BYTES = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

    public const WEBP_BYTES = "RIFF\x1a\x00\x00\x00WEBPVP8 \x0e\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";

    /** @var list<string> */
    private static array $tempFiles = [];

    /**
     * @param  array<string, MediaMetadata>  $metadata
     * @param  MediaGateway|false|null  $gateway  null = a LocalMediaGateway; false = no gateway.
     */
    public static function driver(array $metadata = [], MediaGateway|false|null $gateway = null): PinterestDriver
    {
        return new PinterestDriver(
            $metadata === [] ? null : self::inspector($metadata),
            $gateway === false ? null : ($gateway ?? new LocalMediaGateway),
        );
    }

    /**
     * @param  Media[]  $media
     * @param  array<string, mixed>|null  $credentials
     */
    public static function post(
        array $media = [],
        ?string $caption = 'New spring collection is live: https://example.com/spring.',
        ?string $title = 'Spring collection',
        ?PlatformOptions $options = null,
        ?array $credentials = null,
    ): PreparedPost {
        return new PreparedPost(
            Platform::Pinterest,
            new SocialPost(media: $media, caption: $caption, title: $title, options: $options),
            new Credentials($credentials ?? self::credentials()),
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function credentials(array $extra = []): array
    {
        return $extra + ['access_token' => self::TOKEN, 'board_id' => self::BOARD];
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

    /**
     * A gateway that publishes local files at known URLs (like a re-hosting
     * FilesystemMediaGateway) and reads every media from its local path.
     *
     * @param  array<string, string>  $urls  local source => public URL
     * @param  array<string, string>  $downloads  remote source => local file "downloaded" for it
     */
    public static function rehostingGateway(array $urls = [], array $downloads = []): MediaGateway
    {
        return new class($urls, $downloads) implements MediaGateway
        {
            public int $cleanups = 0;

            /** @var list<string> */
            public array $paths = [];

            /**
             * @param  array<string, string>  $urls
             * @param  array<string, string>  $downloads
             */
            public function __construct(private array $urls, private array $downloads) {}

            public function canProvideUrl(Media $media): bool
            {
                return $media->isRemote() || isset($this->urls[$media->source]);
            }

            public function url(Media $media): string
            {
                return $this->urls[$media->source] ?? $media->source;
            }

            public function path(Media $media): string
            {
                $this->paths[] = $media->source;

                if ($media->isRemote()) {
                    return $this->downloads[$media->source] ?? throw new RuntimeException('This test gateway cannot download '.$media->source);
                }

                return $media->source;
            }

            public function cleanup(): void
            {
                $this->cleanups++;
            }
        };
    }

    /** A temp file with the given bytes and extension (removed by cleanupFiles()). */
    public static function file(string $extension, ?string $bytes = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sp_pin_test_');

        if ($path === false) {
            throw new RuntimeException('No temp file.');
        }

        @unlink($path);
        $path .= '.'.$extension;

        file_put_contents($path, $bytes ?? match ($extension) {
            'png' => self::PNG_BYTES,
            'jpg', 'jpeg' => self::JPEG_BYTES,
            'webp' => self::WEBP_BYTES,
            default => str_repeat("\0", 64),
        });

        self::$tempFiles[] = $path;

        return $path;
    }

    public static function cleanupFiles(): void
    {
        foreach (self::$tempFiles as $file) {
            @unlink($file);
        }

        self::$tempFiles = [];
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

    /** A Pinterest {code, message} body from tests/Fixtures/pinterest/errors/<name>.json. */
    public static function error(string $name, int $status = 400, array $headers = []): Closure
    {
        return self::json(self::body('errors/'.$name), $status, $headers);
    }

    /** An S3 XML error from tests/Fixtures/pinterest/errors/<name>.xml. */
    public static function s3Error(string $name, int $status): Closure
    {
        $xml = (string) file_get_contents(__DIR__.'/errors/'.$name.'.xml');

        return static fn () => Http::response($xml, $status, ['Content-Type' => 'application/xml']);
    }

    /** @return array<string, mixed> */
    public static function body(string $name): array
    {
        $path = __DIR__.'/'.$name.'.json';
        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Bad fixture {$path}");
        }

        return $decoded;
    }

    /** The S3 success answer (204, empty body). */
    public static function s3Ok(): Closure
    {
        return static fn () => Http::response('', 204);
    }

    /** A transport failure after the request may have been sent (read timeout). */
    public static function timeout(): Closure
    {
        return static fn (Request $request) => Create::rejectionFor(new ConnectException(
            'cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '.$request->url(),
            $request->toPsrRequest(),
        ));
    }

    /** A transport failure before the request left (DNS). */
    public static function neverSent(): Closure
    {
        return static fn (Request $request) => Create::rejectionFor(new ConnectException(
            'cURL error 6: Could not resolve host: api.pinterest.com (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '.$request->url(),
            $request->toPsrRequest(),
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

    /** "https://api.pinterest.com/v5/pins?x=y" → "pins"; the S3 host → "s3". */
    public static function path(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (str_ends_with($host, 'amazonaws.com')) {
            return 's3';
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        return (string) preg_replace('#^/v5/#', '', $path);
    }

    /** The query parameters of a recorded request. @return array<string, mixed> */
    public static function query(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }
}
