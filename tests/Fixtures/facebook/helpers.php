<?php

/*
 * Shared helpers for the tests/Feature/Platforms/Facebook*Test.php files.
 * Loaded with require_once; everything lives in its own namespace.
 */

namespace SocialPoster\Tests\Fixtures\Facebook;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use SocialPoster\Contracts\AuthorizesRequests;
use SocialPoster\Contracts\MediaGateway;
use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Enums\Platform;
use SocialPoster\Platforms\Facebook\FacebookDriver;
use SocialPoster\Platforms\Facebook\FacebookOptions;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\PublishOutcome;
use SocialPoster\ValueObjects\SocialPost;
use Throwable;

const FB_GRAPH = 'https://graph.facebook.com/v25.0';
const FB_PAGE = '1029384756';
const FB_TOKEN = 'EAAPageToken123';
const FB_RUPLOAD = 'https://rupload.facebook.com/video-upload/v25.0/7788';

/** Metadata per media source; anything else is unknown to it. */
class FbFakeInspector implements MediaInspector
{
    /** @param array<string, MediaMetadata> $meta */
    public function __construct(public array $meta = []) {}

    public function supports(Media $media): bool
    {
        return isset($this->meta[$media->source]);
    }

    public function inspect(Media $media): MediaMetadata
    {
        return $this->meta[$media->source];
    }
}

/** An app-supplied authorizer that marks its requests. */
class FbTestAuthorizer implements AuthorizesRequests
{
    public function request(Platform $platform, Credentials $credentials): PendingRequest
    {
        return Http::withToken((string) $credentials->get('page_access_token'))->withHeaders(['X-Test-Authorizer' => 'yes']);
    }
}

/** A gateway that passes URLs through but cannot fetch the bytes of one source. */
class FbFailingGateway implements MediaGateway
{
    public function __construct(public string $failing, public Throwable $error) {}

    public function canProvideUrl(Media $media): bool
    {
        return true;
    }

    public function url(Media $media): string
    {
        return $media->source;
    }

    public function path(Media $media): string
    {
        if ($media->source === $this->failing) {
            throw $this->error;
        }

        return $media->source;
    }

    public function cleanup(): void {}
}

/** The real driver, with the protected hooks reachable from the tests. */
class FbProbeDriver extends FacebookDriver
{
    /** @param mixed $response */
    public function classify($response): mixed
    {
        return $this->mapError($response);
    }

    public function call(string $method, mixed ...$args): mixed
    {
        return $this->{$method}(...$args);
    }
}

/** @param array<string, MediaMetadata> $meta */
function fbDriver(array $meta = [], ?MediaGateway $gateway = null): FbProbeDriver
{
    return new FbProbeDriver(new FbFakeInspector($meta), $gateway);
}

/**
 * @param  Media[]  $media
 * @param  array<string, mixed>|null  $credentials
 */
function fbPost(
    array $media = [],
    ?string $caption = 'Hello Facebook',
    ?FacebookOptions $options = null,
    ?array $credentials = null,
    ?string $title = null,
    ?string $comment = null,
): PreparedPost {
    return new PreparedPost(
        Platform::Facebook,
        new SocialPost($media, $caption, $title, $options, [], $comment),
        new Credentials($credentials ?? ['account_id' => FB_PAGE, 'page_access_token' => FB_TOKEN]),
    );
}

function fbUrl(string $path = ''): string
{
    return FB_GRAPH.'/'.ltrim($path, '/');
}

/** @return array<string, mixed> */
function fbFixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__.'/'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * A client Response, for driving classifyError() directly.
 *
 * @param  array<string, mixed>|string  $body
 * @param  array<string, string>  $headers
 */
function fbResponse(array|string $body, int $status = 400, array $headers = []): Response
{
    if (is_array($body)) {
        $headers += ['Content-Type' => 'application/json'];
        $body = (string) json_encode($body);
    }

    return new Response(new PsrResponse($status, $headers, $body));
}

/**
 * A fake that fails the connection the way cURL does. Error 28 after sending
 * is ambiguous; error 6 (DNS) means the request never left.
 */
function fbConnectionFailure(string $message = 'cURL error 28: Operation timed out after 120001 milliseconds with 0 bytes received'): Closure
{
    return static fn (Request $request) => Create::rejectionFor(new ConnectException($message, $request->toPsrRequest()));
}

/**
 * Route fake requests by "METHOD pattern", where pattern is matched with
 * Str::is against the URL-decoded request URL (query included). Values are a
 * response promise, or a callable taking the Request (sequences included).
 * An unmatched request fails the test loudly. Each call starts from a fresh
 * client factory, so a test can swap its fakes (Http::fake() alone appends).
 *
 * @param  array<string, mixed>  $routes
 */
function fbFake(array $routes): void
{
    Http::swap(new Factory(app('events')));
    Http::preventStrayRequests();

    Http::fake(static function (Request $request) use ($routes) {
        $url = urldecode($request->url());

        foreach ($routes as $key => $value) {
            [$method, $pattern] = explode(' ', $key, 2);

            if (strtoupper($method) === $request->method() && Str::is($pattern, $url)) {
                return is_callable($value) ? $value($request) : $value;
            }
        }

        throw new RuntimeException("Unexpected request: {$request->method()} {$url}");
    });
}

/**
 * The query string of a recorded request ($request['x'] only reads bodies).
 *
 * @return array<string, mixed>
 */
function fbQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

/**
 * A Video node status body (GET /{video-id}?fields=status).
 *
 * @param  array<string, mixed>  $overrides  Merged into the status object.
 * @return array<string, mixed>
 */
function fbStatus(
    string $video = 'processing',
    string $uploading = 'complete',
    string $processing = 'in_progress',
    string $publishing = 'not_started',
    ?string $publishStatus = null,
    array $overrides = [],
): array {
    $publishingPhase = ['status' => $publishing];

    if ($publishStatus !== null) {
        $publishingPhase['publish_status'] = $publishStatus;
    }

    return [
        'status' => array_replace_recursive([
            'video_status' => $video,
            'uploading_phase' => ['status' => $uploading, 'bytes_transferred' => 50002],
            'processing_phase' => ['status' => $processing],
            'publishing_phase' => $publishingPhase,
        ], $overrides),
        'id' => '7788',
    ];
}

/**
 * Continue a Pending as the job would: its state plus the reserved keys the
 * job injects.
 *
 * @param  array<string, mixed>  $reserved
 */
function fbResume(FacebookDriver $driver, PreparedPost $post, PublishOutcome $outcome, array $reserved = []): PublishOutcome
{
    if (! $outcome instanceof Pending) {
        throw new RuntimeException('Expected a Pending outcome, got '.$outcome::class);
    }

    return $driver->resume($post, $reserved + $outcome->state);
}

/** A small real file with the given extension, removed at shutdown. */
function fbTempFile(string $extension, string $contents = 'fake-bytes'): string
{
    $path = tempnam(sys_get_temp_dir(), 'fbtest_');
    $target = $path.'.'.$extension;
    rename($path, $target);
    file_put_contents($target, $contents);

    register_shutdown_function(static fn () => @unlink($target));

    return $target;
}
