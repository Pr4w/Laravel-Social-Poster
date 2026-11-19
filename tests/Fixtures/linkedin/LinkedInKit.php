<?php

namespace SocialPoster\Tests\Fixtures\linkedin;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SocialPoster\Contracts\MediaGateway;
use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\SocialPost;
use Throwable;

/**
 * Shared helpers for the LinkedIn driver tests (tests/Feature/Platforms/LinkedIn*Test.php).
 */
final class LinkedInKit
{
    public const TOKEN = 'li-test-token-AQXdSP';

    public const PERSON = 'urn:li:person:8675309Ab_c';

    public const ORG = 'urn:li:organization:2414183';

    public const SHARE = 'urn:li:share:7012345678901234567';

    public const IMAGE = 'urn:li:image:C4E10AQFoyyAjHPMQuQ';

    public const VIDEO = 'urn:li:video:C5505AQH-oV1qvnFtKA';

    public const DOCUMENT = 'urn:li:document:D5510AQGkL7fCqWxHjw';

    /** @var list<string> */
    private static array $files = [];

    public static function driver(?MediaInspector $inspector = null, ?MediaGateway $gateway = null): TestLinkedInDriver
    {
        return new TestLinkedInDriver($inspector, $gateway);
    }

    /**
     * @param  array<int, Media>  $media
     * @param  array<string, mixed>|null  $credentials
     */
    public static function post(
        array $media = [],
        ?string $caption = 'Hello LinkedIn',
        ?PlatformOptions $options = null,
        ?array $credentials = null,
        ?string $comment = null,
        ?string $title = null,
    ): PreparedPost {
        return new PreparedPost(
            Platform::LinkedIn,
            new SocialPost($media, $caption, $title, $options, [], $comment),
            new Credentials($credentials ?? self::credentials()),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function credentials(array $overrides = []): array
    {
        return array_replace(['access_token' => self::TOKEN, 'author' => self::PERSON], $overrides);
    }

    /** @return array<string, mixed> */
    public static function json(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__."/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, string> $headers */
    public static function fixture(string $name, int $status = 200, array $headers = []): PromiseInterface
    {
        return Http::response(self::json($name), $status, $headers);
    }

    public static function created(string $urn = self::SHARE, string $header = 'x-restli-id'): PromiseInterface
    {
        return Http::response('', 201, [$header => $urn]);
    }

    /** A temp file of $bytes bytes whose content encodes each byte's offset, so ranges are checkable. */
    public static function file(int $bytes, string $extension): string
    {
        $path = sys_get_temp_dir().'/sp-li-'.bin2hex(random_bytes(6)).'.'.$extension;
        $content = '';

        for ($i = 0; strlen($content) < $bytes; $i++) {
            $content .= str_pad((string) $i, 8, '.', STR_PAD_LEFT);
        }

        file_put_contents($path, substr($content, 0, $bytes));
        self::$files[] = $path;

        return $path;
    }

    public static function image(?string $alt = null): Media
    {
        return Media::image(self::file(2048, 'jpg'), $alt);
    }

    public static function cleanup(): void
    {
        foreach (self::$files as $file) {
            @unlink($file);
        }

        self::$files = [];
    }

    public static function catch(callable $fn): Throwable
    {
        try {
            $fn();
        } catch (Throwable $e) {
            return $e;
        }

        throw new RuntimeException('Expected an exception.');
    }

    /**
     * A fake that fails at the transport level the way cURL does: "cURL error 6"
     * (DNS, never sent) or "cURL error 28 … bytes received" (read timeout, sent).
     */
    public static function connectionFailure(string $message, ?int $errno = null): Closure
    {
        return static function (Request $request) use ($message, $errno): never {
            throw new ConnectException(
                $message,
                new PsrRequest($request->method(), $request->url()),
                null,
                $errno !== null ? ['errno' => $errno] : [],
            );
        };
    }

    public static function inspector(?MediaMetadata $meta): MediaInspector
    {
        return new class($meta) implements MediaInspector
        {
            public function __construct(private readonly ?MediaMetadata $meta) {}

            public function supports(Media $media): bool
            {
                return $this->meta !== null;
            }

            public function inspect(Media $media): MediaMetadata
            {
                return $this->meta ?? new MediaMetadata;
            }
        };
    }

    /** All recorded requests whose URL contains $needle. */
    public static function sent(string $needle, ?string $method = null): int
    {
        return Http::recorded(static fn (Request $request) => str_contains($request->url(), $needle)
            && ($method === null || $request->method() === $method))->count();
    }
}
