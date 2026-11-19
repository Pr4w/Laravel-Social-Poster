<?php

/*
 * Shared helpers for the Bluesky test files (required with require_once, so
 * every name here is unique to Bluesky: bsky* functions, Bsky* classes).
 */

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SocialPoster\Contracts\AuthorizesRequests;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;
use SocialPoster\Gateways\LocalMediaGateway;
use SocialPoster\Platforms\Bluesky\BlueskyDriver;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\Published;
use SocialPoster\ValueObjects\PublishOutcome;
use SocialPoster\ValueObjects\SocialPost;

const BSKY_DID = 'did:plc:4vx5zk2wrb7qmaz3rdc6yjfo';

const BSKY_PDS = 'https://morel.us-east.host.bsky.network';

const BSKY_CID = 'bafyreie5737gdxlw5i64vzichcalba3z2v5n6icifvx5xytvske7mr3hpm';

/** Metadata by media source; anything else is "unknown" (no ffprobe needed). */
class BskyFakeInspector implements MediaInspector
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

/** An app-supplied authorizer (e.g. a laravel-social-tokens delegate). */
class BskyTestAuthorizer implements AuthorizesRequests
{
    public function request(Platform $platform, Credentials $credentials): PendingRequest
    {
        return Http::withHeaders(['Authorization' => 'Delegated '.$credentials->get('connection_id')]);
    }
}

class BskyTestResolver implements CredentialsResolver
{
    public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
    {
        return $dispatched;
    }
}

/** @return array<string, mixed>|string */
function bskyFixture(string $name): array|string
{
    $contents = (string) file_get_contents(__DIR__.'/'.$name);

    return str_ends_with($name, '.json') ? json_decode($contents, true) : $contents;
}

/**
 * Default bearer credentials; null in $overrides removes a key.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bskyCredentials(array $overrides = []): array
{
    return array_filter(
        array_merge(['did' => BSKY_DID, 'pds' => BSKY_PDS, 'access_jwt' => 'session-jwt'], $overrides),
        static fn ($value) => $value !== null,
    );
}

/**
 * @param  Media[]  $media
 * @param  array<string, mixed>  $credentials
 */
function bskyPost(?string $caption = 'Hello Bluesky', array $media = [], ?PlatformOptions $options = null, array $credentials = []): PreparedPost
{
    return new PreparedPost(Platform::Bluesky, new SocialPost($media, $caption, null, $options), new Credentials(bskyCredentials($credentials)));
}

/** @param array<string, MediaMetadata> $meta */
function bskyDriver(array $meta = []): BlueskyDriver
{
    return new BlueskyDriver(new BskyFakeInspector($meta), new LocalMediaGateway);
}

/** A temp file removed by bskyCleanup(). */
function bskyFile(string $contents, string $extension): string
{
    $path = tempnam(sys_get_temp_dir(), 'sp-bsky-');
    @unlink($path);
    $path .= '.'.$extension;
    file_put_contents($path, $contents);
    $GLOBALS['bsky_temp_files'][] = $path;

    return $path;
}

/** A real (tiny) image so finfo and GD see the right format. */
function bskyImage(string $extension = 'png', int $width = 4, int $height = 3): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));
    ob_start();
    match ($extension) {
        'jpg', 'jpeg' => imagejpeg($image),
        'webp' => imagewebp($image),
        'gif' => imagegif($image),
        default => imagepng($image),
    };

    return bskyFile((string) ob_get_clean(), $extension);
}

/** PNG bytes of random noise (compresses badly, so it stays big). */
function bskyNoisePng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            imagesetpixel($image, $x, $y, random_int(0, 0xFFFFFF));
        }
    }

    ob_start();
    imagepng($image, null, 0);

    return (string) ob_get_clean();
}

function bskyCleanup(): void
{
    foreach ($GLOBALS['bsky_temp_files'] ?? [] as $file) {
        @unlink($file);
    }

    $GLOBALS['bsky_temp_files'] = [];
}

function bskyCatch(callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('Expected an exception.');
}

/** A fake response that fails at the transport level (no HTTP response). */
function bskyConnectionFailure(string $message = 'cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received'): Closure
{
    return static fn (Request $request) => Create::rejectionFor(new ConnectException($message, $request->toPsrRequest()));
}

/** createRecord answering with the record key it was sent. */
function bskyCreateRecord(): Closure
{
    return static function (Request $request) {
        $data = $request->data();

        return Http::response([
            'uri' => 'at://'.$data['repo'].'/'.$data['collection'].'/'.$data['rkey'],
            'cid' => BSKY_CID,
            'commit' => ['cid' => 'bafyreihj7ivxh7ziaqzbbvy4p3zcm4hcfofjv6g6jhqgnzf5t3hhcrvyq4', 'rev' => '3m5kqzw7zdc2a'],
            'validationStatus' => 'valid',
        ]);
    };
}

/**
 * Requests whose URL contains $needle, in order.
 *
 * @return list<Request>
 */
function bskySent(string $needle): array
{
    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => str_contains($request->url(), $needle))
        ->values()
        ->all();
}

/** Run publish() and every resume() until the post is published (no job: _recovering never set). */
function bskyRun(BlueskyDriver $driver, PreparedPost $post, int $maxSteps = 10): PostResult
{
    $outcome = $driver->publish($post);

    for ($step = 0; $outcome instanceof Pending && $step < $maxSteps; $step++) {
        $outcome = $driver->resume($post, $outcome->state);
    }

    if (! $outcome instanceof Published) {
        throw new RuntimeException('The post did not publish within '.$maxSteps.' steps.');
    }

    return $outcome->result;
}

/** The single Published result of an outcome (fails loudly otherwise). */
function bskyPublished(PublishOutcome $outcome): PostResult
{
    if (! $outcome instanceof Published) {
        throw new RuntimeException('Expected a Published outcome, got '.get_debug_type($outcome).'.');
    }

    return $outcome->result;
}
