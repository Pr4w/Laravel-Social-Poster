<?php

/*
 * Shared helpers for the Instagram driver tests (tests/Feature/Platforms/Instagram*Test.php).
 * Namespaced so they can never clash with another driver's helpers.
 */

namespace SocialPoster\Tests\Fixtures\Instagram;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Contracts\MediaGateway;
use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Enums\Platform;
use SocialPoster\Gateways\LocalMediaGateway;
use SocialPoster\Platforms\Instagram\InstagramDriver;
use SocialPoster\Platforms\Instagram\InstagramOptions;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\SocialPost;
use Throwable;

const IG_ACCOUNT = '17841400000000001';
const FB_HOST = 'https://graph.facebook.com/v25.0';
const IG_HOST = 'https://graph.instagram.com/v25.0';

/** igFake() route keys for the account edges. */
const IG_MEDIA = 'POST /'.IG_ACCOUNT.'/media';
const IG_PUBLISH = 'POST /'.IG_ACCOUNT.'/media_publish';
const IG_LISTING = 'GET /'.IG_ACCOUNT.'/media';

/**
 * A state as the publish phase receives it after publish() of a photo.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function igPublishState(array $overrides = []): array
{
    return array_replace([
        'phase' => 'publish',
        'container_id' => 'c1',
        'media_type' => 'IMAGE',
        'caption' => 'Hello #instagram',
        'since' => time() - 600,
        'created_at' => time() - 300,
        'restarts' => 0,
        'ready' => true,
    ], $overrides);
}

/** Bind an app CredentialsResolver, so a non-terminal token rejection asks for a re-resolve. */
function igBindResolver(): void
{
    app()->instance(CredentialsResolver::class, new class implements CredentialsResolver
    {
        public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
        {
            return $dispatched;
        }
    });
}

/** The driver with its protected helpers callable from tests. */
class InstagramDriverProbe extends InstagramDriver
{
    public function call(string $method, mixed ...$args): mixed
    {
        return $this->{$method}(...$args);
    }
}

/** An inspector that knows the metadata of a fixed set of sources. */
final class FakeInspector implements MediaInspector
{
    /** @param array<string, MediaMetadata> $bySource */
    public function __construct(private array $bySource = []) {}

    public function supports(Media $media): bool
    {
        return isset($this->bySource[$media->source]);
    }

    public function inspect(Media $media): MediaMetadata
    {
        return $this->bySource[$media->source];
    }
}

/** A gateway that can publish every file (local ones on a fake CDN). */
final class PublishingGateway implements MediaGateway
{
    public function canProvideUrl(Media $media): bool
    {
        return true;
    }

    public function url(Media $media): string
    {
        return $media->isRemote() ? $media->source : 'https://rehost.example.com/'.basename($media->source);
    }

    public function path(Media $media): string
    {
        return $media->source;
    }

    public function cleanup(): void {}
}

function igDriver(?MediaInspector $inspector = null, ?MediaGateway $gateway = null): InstagramDriverProbe
{
    return new InstagramDriverProbe($inspector, $gateway ?? new LocalMediaGateway);
}

/** @return array<string, mixed> */
function igCreds(array $overrides = []): array
{
    return array_replace(['account_id' => IG_ACCOUNT, 'access_token' => 'EAAGtoken123'], $overrides);
}

/**
 * @param  Media[]  $media
 * @param  array<string, mixed>|null  $credentials
 */
function igPost(
    array $media = [],
    ?string $caption = 'Hello #instagram',
    ?InstagramOptions $options = null,
    ?array $credentials = null,
    ?string $comment = null,
): PreparedPost {
    $media = $media === [] ? [Media::image('https://cdn.example.com/photo.jpg')] : $media;

    return new PreparedPost(
        Platform::Instagram,
        new SocialPost(media: $media, caption: $caption, options: $options, comment: $comment),
        new Credentials($credentials ?? igCreds()),
    );
}

/** A fresh JSON response per request. */
function igRes(mixed $body = [], int $status = 200, array $headers = []): Closure
{
    return static fn () => Http::response($body, $status, $headers);
}

/** Responses served in order; the last one repeats. */
function igSeq(Closure ...$steps): Closure
{
    $index = 0;

    return static function (Request $request) use (&$index, $steps) {
        $step = $steps[min($index, count($steps) - 1)];
        $index++;

        return $step($request);
    };
}

/** A recorded production-shaped error response from errors.json. */
function igErr(string $name): Closure
{
    $fixture = igFixture($name);

    return static fn () => Http::response($fixture['body'], $fixture['status'], is_array($fixture['body']) ? [] : ['Content-Type' => 'text/html']);
}

/** @return array{status: int, body: mixed} */
function igFixture(string $name): array
{
    static $fixtures = null;

    $fixtures ??= json_decode((string) file_get_contents(__DIR__.'/errors.json'), true, 512, JSON_THROW_ON_ERROR);

    if (! isset($fixtures[$name])) {
        throw new RuntimeException("Unknown Instagram error fixture [{$name}].");
    }

    return $fixtures[$name];
}

/** The fixture as an Illuminate response, for calling classifyError() directly. */
function igErrorResponse(string $name, array $headers = []): Response
{
    $fixture = igFixture($name);
    $body = is_array($fixture['body']) ? json_encode($fixture['body']) : (string) $fixture['body'];
    $type = is_array($fixture['body']) ? 'application/json' : 'text/html';

    return new Response(new PsrResponse($fixture['status'], $headers + ['Content-Type' => $type], $body));
}

/**
 * Route faked Graph requests by "METHOD /path" (host and version stripped,
 * query ignored). An unrouted request fails the test as a stray request.
 *
 * @param  array<string, Closure>  $routes
 */
function igFake(array $routes): void
{
    Http::fake(static function (Request $request) use ($routes): ?PromiseInterface {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $path = (string) preg_replace('#^/v\d+(\.\d+)?#', '', $path);
        $key = strtoupper($request->method()).' '.$path;

        return isset($routes[$key]) ? $routes[$key]($request) : null;
    });
}

/** The requests sent to a "METHOD /path" route, in order. */
function igSent(string $route): array
{
    [$method, $path] = explode(' ', $route, 2);

    return Http::recorded(static function (Request $request) use ($method, $path) {
        $requestPath = (string) preg_replace('#^/v\d+(\.\d+)?#', '', (string) parse_url($request->url(), PHP_URL_PATH));

        return strtoupper($request->method()) === $method && $requestPath === $path;
    })->map(static fn (array $pair) => $pair[0])->values()->all();
}

/** A Graph status read answer. */
function igStatus(?string $statusCode, ?string $status = null): Closure
{
    return igRes(array_filter(['status_code' => $statusCode, 'status' => $status, 'id' => 'c1'], static fn ($v) => $v !== null));
}

function igCatch(callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('Expected an exception.');
}

/** An ISO-8601 Graph timestamp $secondsAgo seconds in the past. */
function igTime(int $secondsAgo = 0): string
{
    return gmdate('Y-m-d\TH:i:sO', time() - $secondsAgo);
}
