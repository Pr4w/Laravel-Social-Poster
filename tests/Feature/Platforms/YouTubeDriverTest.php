<?php

namespace SocialPoster\Tests\Feature\Platforms;

use Carbon\CarbonImmutable;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\DecoratedAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Contracts\SupportsFirstComment;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Gateways\LocalMediaGateway;
use SocialPoster\Platforms\YouTube\YouTubeDriver;
use SocialPoster\Platforms\YouTube\YouTubeOptions;
use SocialPoster\Platforms\YouTube\YouTubePrivacy;
use SocialPoster\SocialManager;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\Published;
use SocialPoster\ValueObjects\PublishOutcome;
use SocialPoster\ValueObjects\SocialPost;
use Throwable;

/** The YouTube driver with a controllable per-run chunk budget and access to the error mapping. */
class YouTubeTestDriver extends YouTubeDriver
{
    /** Chunks one run may send (null: the real time budget). */
    public ?int $chunksPerRun = null;

    private ?float $run = null;

    private int $checks = 0;

    protected function budgetLeft(float $startedAt): bool
    {
        if ($this->chunksPerRun === null) {
            return parent::budgetLeft($startedAt);
        }

        if ($this->run !== $startedAt) {
            $this->run = $startedAt;
            $this->checks = 0;
        }

        return ++$this->checks < $this->chunksPerRun;
    }

    public function classify(Response $response, string $operation = 'upload'): TemporaryException|PermanentException
    {
        return $this->mapError($response, $operation);
    }

    public function legacyThumbnail(PreparedPost $post, string $videoId, Media $thumbnail): ?string
    {
        return $this->setThumbnail($post, $videoId, $thumbnail);
    }
}

class YouTubeTestResolver implements CredentialsResolver
{
    public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
    {
        return $dispatched;
    }
}

/** A gateway whose staging copy is slow (a large object copied from S3, a full re-download). */
class YouTubeSlowGateway extends LocalMediaGateway
{
    public int $stagings = 0;

    public function path(Media $media): string
    {
        $this->stagings++;
        usleep(1_100_000);

        return parent::path($media);
    }
}

/** Counts the times the driver asks the gateway for a local copy of the video. */
class YouTubeCountingGateway extends LocalMediaGateway
{
    public int $copies = 0;

    public function path(Media $media): string
    {
        $this->copies++;

        return parent::path($media);
    }
}

/** Fakes, files and builders shared by the tests below. */
final class YouTubeTestKit
{
    public const SESSION = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=SESSION-ONE';

    public const SESSION_TWO = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=SESSION-TWO';

    public const VIDEO_ID = 'dQw4w9WgXcQ';

    public const CHUNK = 262144;

    public const SOURCE = 'https://cdn.example.com/videos/clip.mp4';

    /** @var list<string> */
    public static array $files = [];

    public static string $dir = '';

    /**
     * Route every request by kind, answering from per-route queues in order. A
     * Closure route answers every request of that kind; an unexpected request
     * fails the test.
     *
     * @param  array<string, list<mixed>|Closure>  $routes
     */
    public static function fake(array $routes): void
    {
        $queues = $routes;

        Http::fake(function (Request $request) use (&$queues) {
            $key = self::key($request);
            $route = $queues[$key] ?? null;

            if ($route instanceof Closure) {
                return $route($request);
            }

            if ($route === null || $route === []) {
                throw new RuntimeException("Unexpected YouTube test request [{$key}]: {$request->method()} {$request->url()}");
            }

            $next = array_shift($queues[$key]);

            return $next instanceof Closure ? $next($request) : $next;
        });
    }

    public static function key(Request $request): string
    {
        $url = $request->url();
        $method = $request->method();

        return match (true) {
            str_contains($url, 'cdn.example.com') => 'SOURCE',
            $method === 'PUT' && str_contains($url, 'upload_id=') => 'PUT session',
            $method === 'POST' && str_contains($url, '/upload/youtube/v3/videos') => 'POST upload',
            str_contains($url, '/thumbnails/set') => 'POST thumbnail',
            $method === 'GET' && str_contains($url, '/playlistItems') => 'GET playlist',
            str_contains($url, '/playlistItems') => 'POST playlist',
            $method === 'GET' && str_contains($url, '/commentThreads') => 'GET comments',
            str_contains($url, '/commentThreads') => 'POST comment',
            str_contains($url, '/youtube/v3/channels') => 'GET channels',
            $method === 'GET' && str_contains($url, '/youtube/v3/videos') => 'GET videos',
            default => "{$method} {$url}",
        };
    }

    /** @return list<Request> */
    public static function sent(string $key): array
    {
        return Http::recorded()
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => self::key($request) === $key)
            ->values()
            ->all();
    }

    public static function opened(string $session = self::SESSION): mixed
    {
        return Http::response('', 200, ['Location' => $session]);
    }

    public static function incomplete(?int $lastByte): mixed
    {
        return Http::response('', 308, $lastByte === null ? [] : ['Range' => "bytes=0-{$lastByte}"]);
    }

    /** @param  array<string, mixed>  $overrides */
    public static function video(array $overrides = [], int $status = 201): mixed
    {
        return Http::response(array_replace_recursive(self::json('video_inserted.json'), $overrides), $status);
    }

    /** @param  array<string, mixed>  $overrides */
    public static function listed(string $fixture, array $overrides = []): mixed
    {
        $body = self::json($fixture);

        if ($overrides !== []) {
            $body['items'][0] = array_replace_recursive($body['items'][0], $overrides);
        }

        return Http::response($body, 200);
    }

    /** @param  array<string, string>  $headers */
    public static function error(string $fixture, int $status, array $headers = []): mixed
    {
        return Http::response(self::json($fixture.'.json'), $status, $headers);
    }

    public static function drop(string $message = 'cURL error 28: Operation timed out after 300001 milliseconds with 1048576 bytes received'): Closure
    {
        return fn (Request $request) => Create::rejectionFor(new ConnectException($message, $request->toPsrRequest()));
    }

    /** @return array<string, mixed> */
    public static function json(string $fixture): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/youtube/'.$fixture), true);
    }

    /** A temp file of $size bytes whose content changes every 1000 bytes (so slices are checkable). */
    public static function file(int $size, string $extension = 'mp4'): string
    {
        $path = self::$dir.'/yt_'.bin2hex(random_bytes(4)).'.'.$extension;
        $content = '';

        for ($i = 0; strlen($content) < $size; $i++) {
            $content .= str_pad((string) $i, 1000, chr(65 + ($i % 26)));
        }

        file_put_contents($path, substr($content, 0, $size));
        self::$files[] = $path;

        return $path;
    }

    /** The bytes a source server would hold, matching file(). */
    public static function content(int $size): string
    {
        $content = '';

        for ($i = 0; strlen($content) < $size; $i++) {
            $content .= str_pad((string) $i, 1000, chr(65 + ($i % 26)));
        }

        return substr($content, 0, $size);
    }

    /** A source host honouring Range requests over $content. */
    public static function rangeServer(string $content, string $etag = '"v1"', bool $ranges = true): Closure
    {
        return function (Request $request) use ($content, $etag, $ranges) {
            $range = $request->header('Range')[0] ?? null;

            if (! $ranges || $range === null || preg_match('/bytes=(\d+)-(\d+)/', $range, $m) !== 1) {
                return Http::response($content, 200, ['ETag' => $etag]);
            }

            $start = (int) $m[1];
            $end = min((int) $m[2], strlen($content) - 1);

            return Http::response(substr($content, $start, $end - $start + 1), 206, [
                'Content-Range' => "bytes {$start}-{$end}/".strlen($content),
                'ETag' => $etag,
            ]);
        };
    }

    /**
     * Media::fromDisk() on a cloud-like disk (not a local adapter, like S3)
     * holding $content: the gateway stream-copies it, with no HTTP request.
     */
    public static function cloudVideo(string $content): Media
    {
        $name = 'yt_cloud_'.bin2hex(random_bytes(4)).'.mp4';
        file_put_contents(self::$dir.'/'.$name, $content);

        $adapter = new class(new LocalFilesystemAdapter(self::$dir)) extends DecoratedAdapter {};
        Storage::set('sp-yt-cloud', new class(new Filesystem($adapter), $adapter) extends FilesystemAdapter
        {
            public function url($path)
            {
                return 'https://bucket.example.com/'.$path;
            }
        });

        return Media::fromDisk('sp-yt-cloud', $name);
    }

    /**
     * A copy an earlier upload run kept (sp_youtube_<16 hex>), holding
     * $content, and the state entry that names it.
     *
     * @return array{path: string, size: int, mtime: int}
     */
    public static function keptCopy(string $content): array
    {
        $path = self::$dir.'/sp_youtube_'.bin2hex(random_bytes(8)).'.mp4';
        file_put_contents($path, $content);
        clearstatcache(true, $path);

        return ['path' => $path, 'size' => strlen($content), 'mtime' => (int) filemtime($path)];
    }

    /** @return list<string> */
    public static function keptCopies(): array
    {
        return glob(self::$dir.'/sp_youtube_*') ?: [];
    }

    public static function driver(): YouTubeTestDriver
    {
        return new YouTubeTestDriver(null, new LocalMediaGateway(self::$dir));
    }

    /** @param  array<string, mixed>  $credentials */
    public static function post(
        ?YouTubeOptions $options = null,
        ?Media $video = null,
        array $credentials = [],
        ?string $title = 'My video',
        ?string $caption = 'A description',
    ): PreparedPost {
        $video ??= Media::video(self::file(3 * self::CHUNK - 1000));

        return new PreparedPost(
            Platform::YouTube,
            new SocialPost(media: [$video], caption: $caption, title: $title, options: $options),
            new Credentials($credentials + ['access_token' => 'ya29.test-token', 'channel_id' => 'UCuAXFkgsw1L7xaCfnd5JJOw']),
        );
    }

    /**
     * Drive publish() then resume() until a terminal outcome, the way the job
     * does (attempt 1 on every continuation).
     *
     * @return list<PublishOutcome>
     */
    public static function run(YouTubeDriver $driver, PreparedPost $post, int $max = 20): array
    {
        $outcome = $driver->publish($post);
        $steps = [$outcome];

        while ($outcome instanceof Pending && count($steps) < $max) {
            $outcome = $driver->resume($post, $outcome->state + [Pending::ATTEMPT => 1]);
            $steps[] = $outcome;
        }

        return $steps;
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

    /** @param  array<string, mixed>  $state */
    public static function uploadState(array $state = []): array
    {
        return $state + [
            'stage' => 'upload',
            'session' => self::SESSION,
            'size' => 3 * self::CHUNK - 1000,
            'mime' => 'video/mp4',
            'offset' => 0,
            'range_reads' => false,
            'restarts' => 0,
            'stalls' => 0,
            'requested_privacy' => 'public',
            'deadline' => time() + 3600,
        ];
    }

    /** @param  array<string, mixed>  $state */
    public static function finalizeState(array $state = []): array
    {
        return $state + [
            'stage' => 'finalize',
            'video_id' => self::VIDEO_ID,
            'payload' => ['upload_status' => 'uploaded', 'privacy_status' => 'public'],
            'result_state' => PostResult::STATE_PUBLISHED,
            'await' => true,
            'thumbnail' => false,
            'thumbnail_tries' => 0,
            'playlists' => [],
            'polls' => 0,
            'finalize_started_at' => time(),
            'deadline' => time() + 4200,
        ];
    }
}

beforeEach(function () {
    YouTubeTestKit::$dir = sys_get_temp_dir().'/sp-yt-'.bin2hex(random_bytes(4));
    @mkdir(YouTubeTestKit::$dir, 0775, true);
    YouTubeTestKit::$files = [];

    config([
        'social.temp_dir' => YouTubeTestKit::$dir,
        'social.api.youtube.chunk_bytes' => YouTubeTestKit::CHUNK,
    ]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    array_map('unlink', glob(YouTubeTestKit::$dir.'/*') ?: []);
    @rmdir(YouTubeTestKit::$dir);
});

// --- publish(): the session ------------------------------------------------------------

it('opens a resumable session with the metadata and returns the upload stage before sending any byte', function () {
    YouTubeTestKit::fake(['POST upload' => [YouTubeTestKit::opened()]]);

    $post = YouTubeTestKit::post();
    $outcome = YouTubeTestKit::driver()->publish($post);

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->recheckAfter)->toBe(0)
        ->and($outcome->commits)->toBeFalse()
        ->and($outcome->committed)->toBeFalse()
        ->and($outcome->deadline)->toBeGreaterThanOrEqual(time() + 3600)
        ->and($outcome->state)->toMatchArray([
            'stage' => 'upload',
            'session' => YouTubeTestKit::SESSION,
            'size' => 3 * YouTubeTestKit::CHUNK - 1000,
            'mime' => 'video/mp4',
            'offset' => 0,
            'range_reads' => false,
            'restarts' => 0,
            'requested_privacy' => 'public',
        ])
        ->and($outcome->state['deadline'])->toBe($outcome->deadline)
        ->and($outcome->state)->not->toHaveKey('path');

    $open = YouTubeTestKit::sent('POST upload')[0];
    parse_str((string) parse_url($open->url(), PHP_URL_QUERY), $query);

    expect($query)->toBe(['uploadType' => 'resumable', 'part' => 'snippet,status'])
        ->and($open->header('X-Upload-Content-Length'))->toBe([(string) (3 * YouTubeTestKit::CHUNK - 1000)])
        ->and($open->header('X-Upload-Content-Type'))->toBe(['video/mp4'])
        ->and($open->header('Authorization'))->toBe(['Bearer ya29.test-token'])
        ->and($open->data())->toBe([
            'snippet' => ['title' => 'My video', 'description' => 'A description'],
            'status' => ['privacyStatus' => 'public', 'selfDeclaredMadeForKids' => false],
        ])
        ->and(YouTubeTestKit::sent('PUT session'))->toBe([]);
});

it('builds the body from typed options, merges extra per part and lifts extra query parameters', function () {
    YouTubeTestKit::fake(['POST upload' => [YouTubeTestKit::opened()]]);

    $options = new YouTubeOptions(
        privacyStatus: YouTubePrivacy::Unlisted,
        tags: ['travel', 'road trip'],
        categoryId: '19',
        madeForKids: true,
        extra: [
            'snippet' => ['title' => 'ignored: the driver wins', 'customField' => 'kept'],
            'status' => ['privacyStatus' => 'public', 'publicStatsViewable' => false],
            'recordingDetails' => ['recordingDate' => '2026-11-01T10:00:00Z'],
            'localizations' => ['fr' => ['title' => 'Ma vidéo', 'description' => 'Une description']],
            'contentDetails' => ['caption' => 'true'],
            'query' => [
                'onBehalfOfContentOwner' => 'owner-1',
                'onBehalfOfContentOwnerChannel' => 'UCowner',
                'uploadType' => 'multipart',
                'part' => 'id',
            ],
        ],
        notifySubscribers: false,
        containsSyntheticMedia: true,
        embeddable: false,
        license: 'creativeCommon',
        defaultLanguage: 'en',
        defaultAudioLanguage: 'en-US',
    );

    $outcome = YouTubeTestKit::driver()->publish(YouTubeTestKit::post($options));

    $open = YouTubeTestKit::sent('POST upload')[0];
    parse_str((string) parse_url($open->url(), PHP_URL_QUERY), $query);

    expect($query)->toBe([
        'uploadType' => 'resumable',
        'part' => 'snippet,status,recordingDetails,localizations',
        'onBehalfOfContentOwner' => 'owner-1',
        'onBehalfOfContentOwnerChannel' => 'UCowner',
        'notifySubscribers' => 'false',
    ])
        ->and($open->data())->toBe([
            'snippet' => [
                'title' => 'My video',
                'customField' => 'kept',
                'description' => 'A description',
                'tags' => ['travel', 'road trip'],
                'categoryId' => '19',
                'defaultLanguage' => 'en',
                'defaultAudioLanguage' => 'en-US',
            ],
            'status' => [
                'privacyStatus' => 'unlisted',
                'publicStatsViewable' => false,
                'selfDeclaredMadeForKids' => true,
                'containsSyntheticMedia' => true,
                'embeddable' => false,
                'license' => 'creativeCommon',
            ],
            'recordingDetails' => ['recordingDate' => '2026-11-01T10:00:00Z'],
            'localizations' => ['fr' => ['title' => 'Ma vidéo', 'description' => 'Une description']],
        ])
        ->and($outcome->state['ignored_parts'])->toBe(['contentDetails'])
        ->and($outcome->state['requested_privacy'])->toBe('unlisted');
});

it('schedules with a typed publishAt as a private upload in UTC', function () {
    YouTubeTestKit::fake(['POST upload' => [YouTubeTestKit::opened()]]);

    $at = CarbonImmutable::parse('2030-07-01 11:30:00', 'Europe/Paris');
    YouTubeTestKit::driver()->publish(YouTubeTestKit::post(new YouTubeOptions(publishAt: $at)));

    expect(YouTubeTestKit::sent('POST upload')[0]->data()['status'])->toBe([
        'privacyStatus' => 'private',
        'selfDeclaredMadeForKids' => false,
        'publishAt' => '2030-07-01T09:30:00Z',
    ]);
});

it('keeps the 1.0 extra publishAt route working by uploading privately when no privacy is given', function () {
    YouTubeTestKit::fake(['POST upload' => [YouTubeTestKit::opened()]]);

    YouTubeTestKit::driver()->publish(YouTubeTestKit::post(new YouTubeOptions(extra: ['status' => ['publishAt' => '2030-07-01T09:00:00Z']])));

    expect(YouTubeTestKit::sent('POST upload')[0]->data()['status'])->toBe([
        'publishAt' => '2030-07-01T09:00:00Z',
        'privacyStatus' => 'private',
        'selfDeclaredMadeForKids' => false,
    ]);
});

it('accepts the 1.0 positional options constructor', function () {
    $options = new YouTubeOptions(YouTubePrivacy::Unlisted, ['a'], '22', true, null, ['status' => ['embeddable' => false]]);

    expect($options->privacyStatus)->toBe(YouTubePrivacy::Unlisted)
        ->and($options->tags)->toBe(['a'])
        ->and($options->categoryId)->toBe('22')
        ->and($options->madeForKids)->toBeTrue()
        ->and($options->extra())->toBe(['status' => ['embeddable' => false]])
        ->and($options->platform())->toBe(Platform::YouTube)
        ->and((new YouTubeOptions)->categoryId)->toBeNull()
        ->and((new YouTubeOptions)->resolvedPrivacy())->toBe(YouTubePrivacy::Public);
});

it('refuses an empty video file before opening a session', function () {
    YouTubeTestKit::fake([]);

    $post = YouTubeTestKit::post(video: Media::video(YouTubeTestKit::file(0)));
    $e = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->publish($post));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected);

    Http::assertNothingSent();
});

it('fails as Misconfigured without an access token, before any request', function () {
    YouTubeTestKit::fake([]);

    $post = new PreparedPost(Platform::YouTube, new SocialPost(media: [Media::video(YouTubeTestKit::file(1000))], title: 'T'), new Credentials(['channel_id' => 'UC1']));
    $e = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->publish($post));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Misconfigured)
        ->and($e->context['missing_credentials'])->toBe(['access_token']);

    Http::assertNothingSent();
});

it('never spends a videos.insert call on an expiring token', function () {
    YouTubeTestKit::fake([]);
    $post = YouTubeTestKit::post(credentials: ['expires_at' => time() + 30]);

    $permanent = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->publish($post));

    app()->instance(CredentialsResolver::class, new YouTubeTestResolver);
    $temporary = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->publish($post));

    expect($permanent)->toBeInstanceOf(PermanentException::class)
        ->and($permanent->reason)->toBe(FailureReason::InvalidToken)
        ->and($permanent->context['token_expired'])->toBeTrue()
        ->and($temporary)->toBeInstanceOf(TemporaryException::class)
        ->and($temporary->context['reresolve'])->toBeTrue();

    Http::assertNothingSent();
});

it('retries a session that came back without a Location, and refuses an untrusted upload host', function () {
    YouTubeTestKit::fake(['POST upload' => [
        Http::response('', 200),
        Http::response('', 200, ['Location' => 'https://evil.example.com/upload?upload_id=1']),
    ]]);

    $driver = YouTubeTestKit::driver();
    $post = YouTubeTestKit::post();

    $missing = YouTubeTestKit::catch(fn () => $driver->publish($post));
    $untrusted = YouTubeTestKit::catch(fn () => $driver->publish($post));

    expect($missing)->toBeInstanceOf(TemporaryException::class)
        ->and($missing->reason)->toBe(FailureReason::ServerError)
        ->and($untrusted)->toBeInstanceOf(PermanentException::class)
        ->and($untrusted->reason)->toBe(FailureReason::Misconfigured)
        ->and($untrusted->context['session_host'])->toBe('evil.example.com');
});

it('reports a daily quota on the session as permanent with retry_at at the next Pacific midnight', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 12:00:00', 'UTC'));
    YouTubeTestKit::fake(['POST upload' => [YouTubeTestKit::error('quota_exceeded', 403)]]);

    $e = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->publish(YouTubeTestKit::post()));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::RateLimited)
        ->and($e->context['retry_at'])->toBe('2026-11-19T08:00:00Z')
        ->and($e->context['retry_after_hint'])->toBe(20 * 3600)
        ->and($e->context['quota'])->toBe('daily')
        ->and($e->context['reason'])->toBe('quotaExceeded')
        ->and($e->context['http_status'])->toBe(403)
        ->and($e->getMessage())->toContain('midnight Pacific Time');
});

// --- resume('upload'): chunks ----------------------------------------------------------

it('uploads in 256 KiB-multiple chunks with Content-Range, then publishes the video', function () {
    $size = 3 * YouTubeTestKit::CHUNK - 1000;

    YouTubeTestKit::fake([
        'POST upload' => [YouTubeTestKit::opened()],
        'PUT session' => [
            YouTubeTestKit::incomplete(YouTubeTestKit::CHUNK - 1),
            YouTubeTestKit::incomplete(2 * YouTubeTestKit::CHUNK - 1),
            YouTubeTestKit::video(),
        ],
    ]);

    $post = YouTubeTestKit::post();
    $content = file_get_contents($post->media()[0]->source);
    $steps = YouTubeTestKit::run(YouTubeTestKit::driver(), $post);
    $result = end($steps);

    expect($steps)->toHaveCount(2)
        ->and($result)->toBeInstanceOf(Published::class)
        ->and($result->result->platformPostId)->toBe(YouTubeTestKit::VIDEO_ID)
        ->and($result->result->url)->toBe('https://www.youtube.com/watch?v='.YouTubeTestKit::VIDEO_ID)
        ->and($result->result->state)->toBe(PostResult::STATE_PUBLISHED)
        ->and($result->result->idKind)->toBe('video_id')
        ->and($result->result->payload)->toBe([
            'upload_status' => 'uploaded',
            'privacy_status' => 'public',
            'channel_id' => 'UCuAXFkgsw1L7xaCfnd5JJOw',
        ]);

    $puts = YouTubeTestKit::sent('PUT session');

    expect($puts)->toHaveCount(3)
        ->and($puts[0]->header('Content-Range'))->toBe(['bytes 0-262143/'.$size])
        ->and($puts[0]->header('Content-Length'))->toBe(['262144'])
        ->and($puts[0]->header('Content-Type'))->toBe(['video/mp4'])
        ->and($puts[0]->header('Authorization'))->toBe(['Bearer ya29.test-token'])
        ->and($puts[0]->body())->toBe(substr($content, 0, YouTubeTestKit::CHUNK))
        ->and($puts[1]->header('Content-Range'))->toBe(['bytes 262144-524287/'.$size])
        ->and($puts[1]->body())->toBe(substr($content, YouTubeTestKit::CHUNK, YouTubeTestKit::CHUNK))
        ->and($puts[2]->header('Content-Range'))->toBe(['bytes 524288-'.($size - 1).'/'.$size])
        ->and($puts[2]->header('Content-Length'))->toBe([(string) ($size - 2 * YouTubeTestKit::CHUNK)])
        ->and($puts[2]->body())->toBe(substr($content, 2 * YouTubeTestKit::CHUNK));
});

it('rounds the configured chunk size down to a multiple of 256 KiB', function () {
    config(['social.api.youtube.chunk_bytes' => YouTubeTestKit::CHUNK + 1000]);

    YouTubeTestKit::fake([
        'POST upload' => [YouTubeTestKit::opened()],
        'PUT session' => [YouTubeTestKit::incomplete(YouTubeTestKit::CHUNK - 1), YouTubeTestKit::incomplete(2 * YouTubeTestKit::CHUNK - 1), YouTubeTestKit::video()],
    ]);

    YouTubeTestKit::run(YouTubeTestKit::driver(), YouTubeTestKit::post());

    expect(YouTubeTestKit::sent('PUT session')[0]->header('Content-Length'))->toBe(['262144']);
});

it('hands over to the next run when the per-run budget is spent, without asking the session again', function () {
    YouTubeTestKit::fake([
        'POST upload' => [YouTubeTestKit::opened()],
        'PUT session' => [
            YouTubeTestKit::incomplete(YouTubeTestKit::CHUNK - 1),
            YouTubeTestKit::incomplete(2 * YouTubeTestKit::CHUNK - 1),
            YouTubeTestKit::video(),
        ],
    ]);

    $driver = YouTubeTestKit::driver();
    $driver->chunksPerRun = 1;
    $steps = YouTubeTestKit::run($driver, YouTubeTestKit::post());

    expect($steps)->toHaveCount(4)
        ->and($steps[1])->toBeInstanceOf(Pending::class)
        ->and($steps[1]->recheckAfter)->toBe(0)
        ->and($steps[1]->state['offset'])->toBe(YouTubeTestKit::CHUNK)
        ->and($steps[1]->deadline)->toBe($steps[0]->deadline)
        ->and($steps[2]->state['offset'])->toBe(2 * YouTubeTestKit::CHUNK)
        ->and($steps[3])->toBeInstanceOf(Published::class)
        ->and(YouTubeTestKit::sent('PUT session'))->toHaveCount(3);
});

it('leaves the last chunk to a fresh run when it may not finish inside the job timeout', function () {
    // A run killed while it sends the last chunk could not say whether the
    // video exists: the job timeout (200 s) leaves no room for that chunk's
    // 300 s timeout after this run's work, so a new run sends it.
    config(['social.job_timeout' => 200]);

    YouTubeTestKit::fake([
        'POST upload' => [YouTubeTestKit::opened()],
        'PUT session' => [
            YouTubeTestKit::incomplete(YouTubeTestKit::CHUNK - 1),
            YouTubeTestKit::incomplete(2 * YouTubeTestKit::CHUNK - 1),
            YouTubeTestKit::video(),
        ],
    ]);

    $steps = YouTubeTestKit::run(YouTubeTestKit::driver(), YouTubeTestKit::post());
    $puts = YouTubeTestKit::sent('PUT session');

    expect($steps)->toHaveCount(3)
        ->and($steps[1])->toBeInstanceOf(Pending::class)
        ->and($steps[1]->recheckAfter)->toBe(0)
        ->and($steps[1]->state['offset'])->toBe(2 * YouTubeTestKit::CHUNK)
        ->and($steps[1]->committed)->toBeFalse()
        ->and($steps[2])->toBeInstanceOf(Published::class)
        ->and($puts)->toHaveCount(3)
        ->and($puts[2]->header('Content-Range')[0])->toStartWith('bytes '.(2 * YouTubeTestKit::CHUNK).'-');
});

it('always sends the last chunk from a run that has sent nothing yet, so a hand-over cannot repeat', function () {
    config(['social.job_timeout' => 200]);

    YouTubeTestKit::fake(['PUT session' => [YouTubeTestKit::video()]]);

    $outcome = YouTubeTestKit::driver()->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState(['offset' => 2 * YouTubeTestKit::CHUNK]));

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and(YouTubeTestKit::sent('PUT session'))->toHaveCount(1);
});

it('starts the per-run budget once the source is staged, so a slow copy still sends several chunks per run', function () {
    config(['social.api.youtube.upload_run_seconds' => 1]);

    YouTubeTestKit::fake(['PUT session' => [
        YouTubeTestKit::incomplete(YouTubeTestKit::CHUNK - 1),
        YouTubeTestKit::incomplete(2 * YouTubeTestKit::CHUNK - 1),
        YouTubeTestKit::video(),
    ]]);

    $gateway = new YouTubeSlowGateway(YouTubeTestKit::$dir);
    $outcome = (new YouTubeDriver(null, $gateway))->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState());

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($gateway->stagings)->toBe(1)
        ->and(YouTubeTestKit::sent('PUT session'))->toHaveCount(3);
});

it('continues from what the session stored when it kept fewer bytes than were sent', function () {
    YouTubeTestKit::fake([
        'POST upload' => [YouTubeTestKit::opened()],
        'PUT session' => [
            YouTubeTestKit::incomplete(99999),
            YouTubeTestKit::incomplete(99999 + YouTubeTestKit::CHUNK),
            YouTubeTestKit::incomplete(99999 + 2 * YouTubeTestKit::CHUNK),
            YouTubeTestKit::video(),
        ],
    ]);

    YouTubeTestKit::run(YouTubeTestKit::driver(), YouTubeTestKit::post());
    $puts = YouTubeTestKit::sent('PUT session');

    expect($puts[1]->header('Content-Range')[0])->toStartWith('bytes 100000-362143/')
        ->and($puts[2]->header('Content-Range')[0])->toStartWith('bytes 362144-624287/')
        ->and($puts[3]->header('Content-Range')[0])->toBe('bytes 624288-785431/785432');
});

it('starts again at byte 0 when a 308 carries no Range header', function () {
    YouTubeTestKit::fake([
        'PUT session' => [
            YouTubeTestKit::incomplete(null),
            YouTubeTestKit::incomplete(YouTubeTestKit::CHUNK - 1),
            YouTubeTestKit::incomplete(2 * YouTubeTestKit::CHUNK - 1),
            YouTubeTestKit::video(),
        ],
    ]);

    $post = YouTubeTestKit::post();
    $outcome = YouTubeTestKit::driver()->resume($post, YouTubeTestKit::uploadState());
    $puts = YouTubeTestKit::sent('PUT session');

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($puts[0]->header('Content-Range')[0])->toStartWith('bytes 0-')
        ->and($puts[1]->header('Content-Range')[0])->toStartWith('bytes 0-');
});

it('asks the session where it is on a repeated run, then resumes from its Range', function (array $flags) {
    $size = 3 * YouTubeTestKit::CHUNK - 1000;

    YouTubeTestKit::fake([
        'PUT session' => [
            YouTubeTestKit::incomplete(2 * YouTubeTestKit::CHUNK - 1),
            YouTubeTestKit::video(),
        ],
    ]);

    $outcome = YouTubeTestKit::driver()->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState($flags));
    $puts = YouTubeTestKit::sent('PUT session');

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($puts[0]->header('Content-Range'))->toBe(['bytes */'.$size])
        ->and($puts[0]->header('Content-Length'))->toBe(['0'])
        ->and($puts[0]->body())->toBe('')
        ->and($puts[1]->header('Content-Range'))->toBe(['bytes 524288-'.($size - 1).'/'.$size]);
})->with([
    'redelivered (attempt 2)' => [[Pending::ATTEMPT => 2, 'offset' => 0]],
    'recovering' => [[Pending::RECOVERING => true, 'offset' => YouTubeTestKit::CHUNK]],
    'offset unknown' => [['offset' => null]],
]);

it('takes the finished video from the session instead of uploading it twice', function () {
    YouTubeTestKit::fake(['PUT session' => [YouTubeTestKit::video([], 200)]]);

    $outcome = YouTubeTestKit::driver()->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState([Pending::RECOVERING => true]));

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->platformPostId)->toBe(YouTubeTestKit::VIDEO_ID)
        ->and(YouTubeTestKit::sent('PUT session'))->toHaveCount(1)
        ->and(YouTubeTestKit::sent('POST upload'))->toBe([]);
});

it('opens a new session when the session expired, at most twice', function () {
    YouTubeTestKit::fake([
        'PUT session' => [Http::response('', 404), Http::response('Gone', 410)],
        'POST upload' => [YouTubeTestKit::opened(YouTubeTestKit::SESSION_TWO)],
    ]);

    $driver = YouTubeTestKit::driver();
    $post = YouTubeTestKit::post();
    $outcome = $driver->resume($post, YouTubeTestKit::uploadState(['offset' => null, 'stalls' => 2]));

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->recheckAfter)->toBe(0)
        ->and($outcome->committed)->toBeFalse()
        ->and($outcome->state['session'])->toBe(YouTubeTestKit::SESSION_TWO)
        ->and($outcome->state['offset'])->toBe(0)
        ->and($outcome->state['restarts'])->toBe(1)
        ->and($outcome->state['stalls'])->toBe(0)
        ->and($outcome->state)->not->toHaveKey('final_sent')
        ->and(YouTubeTestKit::sent('POST upload')[0]->header('X-Upload-Content-Length'))->toBe([(string) (3 * YouTubeTestKit::CHUNK - 1000)]);

    $e = YouTubeTestKit::catch(fn () => $driver->resume($post, YouTubeTestKit::uploadState(['offset' => null, 'restarts' => 2])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::NotFound)
        ->and($e->context['session_expired'])->toBeTrue()
        ->and($e->possiblyPublished())->toBeFalse()
        ->and(YouTubeTestKit::sent('POST upload'))->toHaveCount(1);
});

it('never uploads again when the session expired after the final chunk may have arrived', function (int $status) {
    YouTubeTestKit::fake(['PUT session' => [Http::response('', $status)]]);

    $e = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->resume(
        YouTubeTestKit::post(),
        YouTubeTestKit::uploadState(['offset' => null, 'stalls' => 2, 'final_sent' => true]),
    ));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->possiblyPublished())->toBeTrue()
        ->and($e->context['session_expired'])->toBeTrue()
        ->and($e->getMessage())->toContain('check the channel')
        ->and(YouTubeTestKit::sent('POST upload'))->toBe([])
        ->and(YouTubeTestKit::sent('PUT session'))->toHaveCount(1);
})->with([404, 410]);

it('classifies the original error a finished session returns, without opening a new session', function () {
    YouTubeTestKit::fake(['PUT session' => [YouTubeTestKit::error('upload_limit_exceeded', 400)]]);

    $e = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState(['offset' => null])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::RateLimited)
        ->and($e->context['upload_limit'])->toBeTrue()
        ->and($e->context)->toHaveKey('possibly_published')
        ->and($e->possiblyPublished())->toBeFalse()
        ->and(YouTubeTestKit::sent('POST upload'))->toBe([]);
});

it('marks only the final verdicts of videos.insert as definitely not published', function (string $fixture, int $status, ?bool $possibly) {
    YouTubeTestKit::fake(['PUT session' => [YouTubeTestKit::error($fixture, $status)]]);

    $e = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState(['offset' => null, 'final_sent' => true])));

    expect($e)->toBeInstanceOf(PermanentException::class);

    $possibly === null
        ? expect($e->context)->not->toHaveKey('possibly_published')
        : expect($e->context['possibly_published'])->toBe($possibly);
})->with([
    'invalid title' => ['invalid_title', 400, false],
    'no media' => ['media_body_required', 400, false],
    'daily quota' => ['quota_exceeded', 403, false],
    'expired token' => ['auth_error_expired', 401, null],
    'scope' => ['insufficient_permissions', 403, null],
    'unknown 400' => ['bad_request_unknown', 400, null],
]);

it('keeps the session and backs off on a 5xx, honouring Retry-After', function () {
    YouTubeTestKit::fake(['PUT session' => [YouTubeTestKit::error('backend_error', 503, ['Retry-After' => '120'])]]);

    $outcome = YouTubeTestKit::driver()->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState());

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->recheckAfter)->toBe(120)
        ->and($outcome->committed)->toBeFalse()
        ->and($outcome->state['session'])->toBe(YouTubeTestKit::SESSION)
        ->and($outcome->state['offset'])->toBeNull()
        ->and($outcome->state['stalls'])->toBe(1);
});

it('keeps the session after a dropped connection and marks a lost final chunk as possibly committed', function () {
    YouTubeTestKit::fake([
        'PUT session' => [
            YouTubeTestKit::drop(),
            YouTubeTestKit::incomplete(2 * YouTubeTestKit::CHUNK - 1),
            YouTubeTestKit::drop(),
            YouTubeTestKit::video(),
        ],
    ]);

    $driver = YouTubeTestKit::driver();
    $post = YouTubeTestKit::post();

    $first = $driver->resume($post, YouTubeTestKit::uploadState());

    expect($first)->toBeInstanceOf(Pending::class)
        ->and($first->committed)->toBeFalse()
        ->and($first->recheckAfter)->toBe(5)
        ->and($first->state['offset'])->toBeNull()
        ->and($first->state)->not->toHaveKey('final_sent');

    // The next run asks the session (two chunks arrived), sends the last one, and the connection drops again.
    $second = $driver->resume($post, $first->state + [Pending::ATTEMPT => 1]);

    expect($second)->toBeInstanceOf(Pending::class)
        ->and($second->committed)->toBeTrue()
        ->and($second->state['final_sent'])->toBeTrue()
        ->and($second->state['stalls'])->toBe(2)
        ->and($second->recheckAfter)->toBe(10)
        ->and(YouTubeTestKit::sent('PUT session')[1]->header('Content-Range')[0])->toStartWith('bytes */');

    $third = $driver->resume($post, $second->state + [Pending::ATTEMPT => 1]);

    expect($third)->toBeInstanceOf(Published::class);
});

it('turns repeated stalls into a TemporaryException that still keeps the session', function () {
    config(['social.api.youtube.max_upload_stalls' => 2]);
    YouTubeTestKit::fake(['PUT session' => [YouTubeTestKit::error('backend_error', 502), YouTubeTestKit::drop()]]);

    $driver = YouTubeTestKit::driver();
    $http = YouTubeTestKit::catch(fn () => $driver->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState(['stalls' => 2])));
    $network = YouTubeTestKit::catch(fn () => $driver->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState(['stalls' => 2, 'final_sent' => true, 'offset' => null])));

    expect($http)->toBeInstanceOf(TemporaryException::class)
        ->and($http->reason)->toBe(FailureReason::ServerError)
        ->and($http->context['session_kept'])->toBeTrue()
        ->and($network)->toBeInstanceOf(TemporaryException::class)
        ->and($network->reason)->toBe(FailureReason::Network)
        ->and($network->context['possibly_published'])->toBeTrue();
});

it('keeps the session on a 401 and re-resolves once when a resolver is bound', function () {
    YouTubeTestKit::fake(['PUT session' => [YouTubeTestKit::error('auth_error_expired', 401), YouTubeTestKit::error('auth_error_expired', 401)]]);

    $driver = YouTubeTestKit::driver();
    $permanent = YouTubeTestKit::catch(fn () => $driver->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState()));

    app()->instance(CredentialsResolver::class, new YouTubeTestResolver);
    $temporary = YouTubeTestKit::catch(fn () => $driver->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState()));

    expect($permanent)->toBeInstanceOf(PermanentException::class)
        ->and($permanent->reason)->toBe(FailureReason::InvalidToken)
        ->and($permanent->context['token_expired'])->toBeTrue()
        ->and($permanent->context)->not->toHaveKey('possibly_published')
        ->and($temporary)->toBeInstanceOf(TemporaryException::class)
        ->and($temporary->retryAfter)->toBe(0)
        ->and($temporary->context['reresolve'])->toBeTrue()
        ->and(YouTubeTestKit::sent('POST upload'))->toBe([]);
});

it('checks the session when a chunk sent from an unconfirmed offset is refused', function () {
    YouTubeTestKit::fake([
        'PUT session' => [
            Http::response('Invalid request. According to the Content-Range header, the upload offset is 0 byte(s), which exceeds already uploaded size of 262144 byte(s).', 400),
            YouTubeTestKit::incomplete(2 * YouTubeTestKit::CHUNK - 1),
            YouTubeTestKit::video(),
        ],
    ]);

    $outcome = YouTubeTestKit::driver()->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState());
    $puts = YouTubeTestKit::sent('PUT session');

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($puts[1]->header('Content-Range')[0])->toStartWith('bytes */')
        ->and($puts[2]->header('Content-Range')[0])->toStartWith('bytes 524288-');
});

it('asks the session for the final response when every byte is acknowledged without a video', function () {
    YouTubeTestKit::fake([
        'PUT session' => [
            YouTubeTestKit::incomplete(3 * YouTubeTestKit::CHUNK - 1001),
            YouTubeTestKit::video(),
        ],
    ]);

    $outcome = YouTubeTestKit::driver()->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState(['offset' => 2 * YouTubeTestKit::CHUNK]));

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and(YouTubeTestKit::sent('PUT session')[1]->header('Content-Range')[0])->toStartWith('bytes */');
});

it('backs off, possibly committed, when the session holds every byte but no video yet', function () {
    $size = 3 * YouTubeTestKit::CHUNK - 1000;

    YouTubeTestKit::fake(['PUT session' => [
        YouTubeTestKit::incomplete($size - 1),
        YouTubeTestKit::incomplete($size - 1),
        YouTubeTestKit::incomplete($size - 1),
    ]]);

    $driver = YouTubeTestKit::driver();
    $asked = $driver->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState(['offset' => null]));

    expect($asked)->toBeInstanceOf(Pending::class)
        ->and($asked->committed)->toBeTrue()
        ->and($asked->state['final_sent'])->toBeTrue()
        ->and($asked->state['offset'])->toBeNull()
        ->and(YouTubeTestKit::sent('PUT session'))->toHaveCount(1);

    $sent = $driver->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState(['offset' => 2 * YouTubeTestKit::CHUNK]));
    $puts = YouTubeTestKit::sent('PUT session');

    expect($sent)->toBeInstanceOf(Pending::class)
        ->and($sent->committed)->toBeTrue()
        ->and($puts)->toHaveCount(3)
        ->and($puts[1]->header('Content-Range')[0])->toStartWith('bytes 524288-')
        ->and($puts[2]->header('Content-Range')[0])->toStartWith('bytes */');
});

it('reads remote videos through the gateway when range reads are turned off, and honours a configured upload window', function () {
    config(['social.api.youtube.range_reads' => false, 'social.api.youtube.upload_window' => 7200]);
    $size = YouTubeTestKit::CHUNK;

    YouTubeTestKit::fake([
        'SOURCE' => YouTubeTestKit::rangeServer(YouTubeTestKit::content($size)),
        'POST upload' => [YouTubeTestKit::opened()],
    ]);

    $outcome = YouTubeTestKit::driver()->publish(YouTubeTestKit::post(video: Media::video(YouTubeTestKit::SOURCE)));

    // The download is kept for the upload runs (under its own name, so the
    // gateway's cleanup leaves it), and nothing else stays behind.
    expect($outcome->state['range_reads'])->toBeFalse()
        ->and($outcome->state['size'])->toBe($size)
        ->and($outcome->deadline - $outcome->state['upload_started_at'])->toBe(7200)
        ->and(YouTubeTestKit::sent('SOURCE'))->toHaveCount(1)
        ->and(YouTubeTestKit::sent('SOURCE')[0]->hasHeader('Range'))->toBeFalse()
        ->and(YouTubeTestKit::keptCopies())->toBe([$outcome->state['staged']['path']])
        ->and($outcome->state['staged']['size'])->toBe($size)
        ->and(file_get_contents($outcome->state['staged']['path']))->toBe(YouTubeTestKit::content($size))
        ->and(glob(YouTubeTestKit::$dir.'/sp_[0-9a-f]*'))->toBe([]);
});

it('fails when the local video changed size between runs', function () {
    YouTubeTestKit::fake([]);

    $e = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState(['size' => 999999])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->context['source_changed'])->toBeTrue();

    Http::assertNothingSent();
});

it('treats a finished upload without a usable video id as possibly published', function (mixed $response) {
    YouTubeTestKit::fake(['PUT session' => [$response]]);

    $e = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState(['offset' => null])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->possiblyPublished())->toBeTrue();
})->with([
    'no id' => fn () => Http::response(['kind' => 'youtube#video'], 201),
    'not a video id' => fn () => Http::response(['id' => 'abc'], 201),
    'html' => fn () => Http::response('<html>proxy</html>', 200),
]);

it('reports an upload YouTube rejected right away as a definitive failure with the video id', function () {
    YouTubeTestKit::fake(['PUT session' => [YouTubeTestKit::video(['status' => ['uploadStatus' => 'rejected', 'rejectionReason' => 'duplicate']])]]);

    $e = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState(['offset' => null])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::DuplicateContent)
        ->and($e->context['video_id'])->toBe(YouTubeTestKit::VIDEO_ID)
        ->and($e->context)->toHaveKey('possibly_published')
        ->and($e->possiblyPublished())->toBeFalse();
});

it('restarts the publish when the upload state is incomplete, and refuses unknown stages', function () {
    YouTubeTestKit::fake([]);

    $driver = YouTubeTestKit::driver();
    $restart = YouTubeTestKit::catch(fn () => $driver->resume(YouTubeTestKit::post(), ['stage' => 'upload', 'size' => 10]));
    $unknown = YouTubeTestKit::catch(fn () => $driver->resume(YouTubeTestKit::post(), ['stage' => 'wat']));

    expect($restart)->toBeInstanceOf(TemporaryException::class)
        ->and($restart->context['restart'])->toBeTrue()
        ->and($unknown)->toBeInstanceOf(PermanentException::class)
        ->and($unknown->getMessage())->toContain('wat');
});

it("accepts 'phase' as an alias of 'stage'", function () {
    YouTubeTestKit::fake(['PUT session' => [YouTubeTestKit::video([], 200)]]);

    $state = YouTubeTestKit::uploadState(['offset' => null]);
    unset($state['stage']);

    expect(YouTubeTestKit::driver()->resume(YouTubeTestKit::post(), $state + ['phase' => 'upload']))->toBeInstanceOf(Published::class);
});

it('warns when YouTube applied another privacy, and reports private and scheduled results', function () {
    YouTubeTestKit::fake(['PUT session' => [
        YouTubeTestKit::video(['status' => ['privacyStatus' => 'private']]),
        YouTubeTestKit::video(['status' => ['privacyStatus' => 'private', 'publishAt' => '2030-07-01T09:00:00Z']]),
    ]]);

    $driver = YouTubeTestKit::driver();
    $forced = $driver->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState(['offset' => null]));

    $at = CarbonImmutable::parse('2030-07-01 09:00:00', 'UTC');
    $scheduled = $driver->resume(YouTubeTestKit::post(new YouTubeOptions(publishAt: $at)), YouTubeTestKit::uploadState(['offset' => null, 'requested_privacy' => 'private']));

    expect($forced->result->state)->toBe(PostResult::STATE_PRIVATE)
        ->and($forced->result->payload['privacy_status'])->toBe('private')
        ->and($forced->result->payload['privacy_warning'])->toContain('Requested public but YouTube applied private')
        ->and($scheduled->result->state)->toBe(PostResult::STATE_SCHEDULED)
        ->and($scheduled->result->isScheduled())->toBeTrue()
        ->and($scheduled->result->payload['scheduled_for'])->toBe('2030-07-01T09:00:00Z')
        ->and($scheduled->result->payload)->not->toHaveKey('privacy_warning');
});

it('lists extra parts YouTube would ignore in the payload', function () {
    YouTubeTestKit::fake(['PUT session' => [YouTubeTestKit::video()]]);

    $outcome = YouTubeTestKit::driver()->resume(YouTubeTestKit::post(), YouTubeTestKit::uploadState(['offset' => null, 'ignored_parts' => ['contentDetails']]));

    expect($outcome->result->payload['ignored_extra_parts'])->toBe(['contentDetails']);
});

// --- Remote sources ----------------------------------------------------------------------

it('reads a remote video with Range requests, never sending the YouTube token to the media host', function () {
    $size = 3 * YouTubeTestKit::CHUNK - 1000;
    $content = YouTubeTestKit::content($size);

    YouTubeTestKit::fake([
        'SOURCE' => YouTubeTestKit::rangeServer($content),
        'POST upload' => [YouTubeTestKit::opened()],
        'PUT session' => [
            YouTubeTestKit::incomplete(YouTubeTestKit::CHUNK - 1),
            YouTubeTestKit::incomplete(2 * YouTubeTestKit::CHUNK - 1),
            YouTubeTestKit::video(),
        ],
    ]);

    $post = YouTubeTestKit::post(video: Media::video(YouTubeTestKit::SOURCE));
    $steps = YouTubeTestKit::run(YouTubeTestKit::driver(), $post);

    expect($steps[0]->state)->toMatchArray(['size' => $size, 'etag' => '"v1"', 'range_reads' => true])
        ->and(end($steps))->toBeInstanceOf(Published::class)
        ->and(YouTubeTestKit::sent('POST upload')[0]->header('X-Upload-Content-Length'))->toBe([(string) $size]);

    $reads = YouTubeTestKit::sent('SOURCE');
    $puts = YouTubeTestKit::sent('PUT session');

    expect(array_map(fn (Request $r) => $r->header('Range')[0], $reads))->toBe([
        'bytes=0-0',
        'bytes=0-262143',
        'bytes=262144-524287',
        'bytes=524288-'.($size - 1),
    ])
        ->and(array_filter($reads, fn (Request $r) => $r->hasHeader('Authorization')))->toBe([])
        ->and($puts[1]->body())->toBe(substr($content, YouTubeTestKit::CHUNK, YouTubeTestKit::CHUNK))
        ->and($puts[2]->body())->toBe(substr($content, 2 * YouTubeTestKit::CHUNK))
        ->and(glob(YouTubeTestKit::$dir.'/sp_*'))->toBe([]);
});

it('fails when the remote video changes during the upload', function (Closure $server, string $why) {
    $size = 3 * YouTubeTestKit::CHUNK - 1000;

    YouTubeTestKit::fake(['SOURCE' => $server($size)]);

    $state = YouTubeTestKit::uploadState(['range_reads' => true, 'etag' => '"v1"']);
    $e = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->resume(YouTubeTestKit::post(video: Media::video(YouTubeTestKit::SOURCE)), $state));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->context['source_changed'])->toBeTrue()
        ->and($e->getMessage())->toContain($why);
})->with([
    'etag' => [fn (int $size) => YouTubeTestKit::rangeServer(YouTubeTestKit::content($size), '"v2"'), 'ETag'],
    'size' => [fn (int $size) => YouTubeTestKit::rangeServer(YouTubeTestKit::content($size + 5)), 'size'],
    'shorter' => [fn (int $size) => fn () => Http::response('', 416), 'shorter'],
]);

it('maps source failures during the upload', function (int $status, string $class, FailureReason $reason) {
    YouTubeTestKit::fake(['SOURCE' => fn () => Http::response('nope', $status)]);

    $e = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->resume(
        YouTubeTestKit::post(video: Media::video(YouTubeTestKit::SOURCE)),
        YouTubeTestKit::uploadState(['range_reads' => true]),
    ));

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason)
        ->and($e->context['media_download'])->toBeTrue();
})->with([
    'gone' => [404, PermanentException::class, FailureReason::MediaRejected],
    'down' => [503, TemporaryException::class, FailureReason::Network],
]);

it('reports a signed source URL that stopped being valid mid-upload as expired, never naming the URL', function (bool $ranges, int $status) {
    YouTubeTestKit::fake(['SOURCE' => fn () => Http::response('<Error><Code>AccessDenied</Code><Message>Request has expired</Message></Error>', $status)]);

    $url = YouTubeTestKit::SOURCE.'?X-Amz-Signature=secret-signature&X-Amz-Expires=3600';
    $e = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->resume(
        YouTubeTestKit::post(video: Media::video($url)),
        YouTubeTestKit::uploadState(['range_reads' => $ranges, 'offset' => YouTubeTestKit::CHUNK]),
    ));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->context['source_url_expired'])->toBeTrue()
        ->and($e->context['http_status'])->toBe($status)
        ->and($e->context['url_host'])->toBe('cdn.example.com')
        ->and($e->getMessage())->toContain('stopped being valid')
        ->and($e->getMessage())->toContain('Media::fromDisk()')
        ->and($e->getMessage())->not->toContain('secret-signature')
        ->and(json_encode($e->context))->not->toContain('secret-signature')
        ->and(YouTubeTestKit::sent('PUT session'))->toBe([]);
})->with([
    'range read, 403' => [true, 403],
    'range read, 401' => [true, 401],
    'range read, 410' => [true, 410],
    'staged download, 403' => [false, 403],
]);

it('stages remote videos through the gateway when the host does not serve ranges', function () {
    $size = 2 * YouTubeTestKit::CHUNK;
    $content = YouTubeTestKit::content($size);

    YouTubeTestKit::fake([
        'SOURCE' => YouTubeTestKit::rangeServer($content, ranges: false),
        'POST upload' => [YouTubeTestKit::opened()],
        'PUT session' => [YouTubeTestKit::incomplete(YouTubeTestKit::CHUNK - 1), YouTubeTestKit::video()],
    ]);

    $steps = YouTubeTestKit::run(YouTubeTestKit::driver(), YouTubeTestKit::post(video: Media::video(YouTubeTestKit::SOURCE)));

    expect($steps[0]->state['range_reads'])->toBeFalse()
        ->and($steps[0]->state['size'])->toBe($size)
        ->and(end($steps))->toBeInstanceOf(Published::class)
        ->and(YouTubeTestKit::sent('PUT session')[1]->body())->toBe(substr($content, YouTubeTestKit::CHUNK))
        ->and(glob(YouTubeTestKit::$dir.'/sp_*'))->toBe([]);
});

it('falls back to staging the file when the host stops honouring ranges mid-upload', function () {
    $size = 2 * YouTubeTestKit::CHUNK;
    $content = YouTubeTestKit::content($size);

    YouTubeTestKit::fake([
        'SOURCE' => YouTubeTestKit::rangeServer($content, ranges: false),
        'PUT session' => [YouTubeTestKit::incomplete(YouTubeTestKit::CHUNK - 1), YouTubeTestKit::video()],
    ]);

    $outcome = YouTubeTestKit::driver()->resume(
        YouTubeTestKit::post(video: Media::video(YouTubeTestKit::SOURCE)),
        YouTubeTestKit::uploadState(['range_reads' => true, 'size' => $size]),
    );

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and(YouTubeTestKit::sent('PUT session')[0]->body())->toBe(substr($content, 0, YouTubeTestKit::CHUNK));
});

it('copies a source it cannot read in ranges once, and keeps the copy for every upload run', function (string $kind, int $sourceReads) {
    $size = 3 * YouTubeTestKit::CHUNK - 1000;
    $content = YouTubeTestKit::content($size);

    if ($kind === 'range reads off') {
        config(['social.api.youtube.range_reads' => false]);
    }

    $video = $kind === 'cloud disk' ? YouTubeTestKit::cloudVideo($content) : Media::video(YouTubeTestKit::SOURCE);

    YouTubeTestKit::fake([
        'SOURCE' => YouTubeTestKit::rangeServer($content, ranges: $kind === 'range reads off'),
        'POST upload' => [YouTubeTestKit::opened()],
        'PUT session' => [
            YouTubeTestKit::incomplete(YouTubeTestKit::CHUNK - 1),
            YouTubeTestKit::incomplete(2 * YouTubeTestKit::CHUNK - 1),
            YouTubeTestKit::video(),
        ],
    ]);

    $gateway = new YouTubeCountingGateway(YouTubeTestKit::$dir);
    $driver = new YouTubeTestDriver(null, $gateway);
    $driver->chunksPerRun = 1;
    $post = YouTubeTestKit::post(video: $video);

    $steps = [$driver->publish($post)];
    $kept = [YouTubeTestKit::keptCopies()];

    while (end($steps) instanceof Pending && count($steps) < 10) {
        $steps[] = $driver->resume($post, end($steps)->state + [Pending::ATTEMPT => 1]);
        $kept[] = YouTubeTestKit::keptCopies();
    }

    $puts = YouTubeTestKit::sent('PUT session');

    expect($steps)->toHaveCount(4)
        ->and(end($steps))->toBeInstanceOf(Published::class)
        ->and($gateway->copies)->toBe(1)
        ->and(YouTubeTestKit::sent('SOURCE'))->toHaveCount($sourceReads)
        // One copy, the same one for every run, deleted once the upload finished.
        ->and($kept[0])->toHaveCount(1)
        ->and($kept[1])->toBe($kept[0])
        ->and($kept[2])->toBe($kept[0])
        ->and($kept[3])->toBe([])
        ->and($steps[2]->state['staged']['path'])->toBe($kept[0][0])
        ->and($puts[0]->body())->toBe(substr($content, 0, YouTubeTestKit::CHUNK))
        ->and($puts[1]->body())->toBe(substr($content, YouTubeTestKit::CHUNK, YouTubeTestKit::CHUNK))
        ->and($puts[2]->body())->toBe(substr($content, 2 * YouTubeTestKit::CHUNK))
        ->and(glob(YouTubeTestKit::$dir.'/sp_*'))->toBe([]);
})->with([
    // The cloud disk is stream-copied (no HTTP); the host without ranges is probed, then downloaded once.
    'cloud disk' => ['cloud disk', 0],
    'host without ranges' => ['host without ranges', 2],
    'range reads off' => ['range reads off', 1],
]);

it('finishes the upload from the kept copy after a signed source URL expired', function () {
    config(['social.api.youtube.range_reads' => false]);
    $size = 3 * YouTubeTestKit::CHUNK - 1000;
    $content = YouTubeTestKit::content($size);
    $reads = 0;

    YouTubeTestKit::fake([
        // Valid for the first read only, then 'Request has expired'.
        'SOURCE' => function () use ($content, &$reads) {
            return ++$reads === 1
                ? Http::response($content, 200)
                : Http::response('<Error><Code>AccessDenied</Code><Message>Request has expired</Message></Error>', 403);
        },
        'POST upload' => [YouTubeTestKit::opened()],
        'PUT session' => [
            YouTubeTestKit::incomplete(YouTubeTestKit::CHUNK - 1),
            YouTubeTestKit::incomplete(2 * YouTubeTestKit::CHUNK - 1),
            YouTubeTestKit::video(),
        ],
    ]);

    $driver = YouTubeTestKit::driver();
    $driver->chunksPerRun = 1;
    $steps = YouTubeTestKit::run($driver, YouTubeTestKit::post(video: Media::video(YouTubeTestKit::SOURCE.'?X-Amz-Expires=3600&X-Amz-Signature=abc')));

    expect(end($steps))->toBeInstanceOf(Published::class)
        ->and($steps)->toHaveCount(4)
        ->and($reads)->toBe(1)
        ->and(YouTubeTestKit::sent('PUT session')[2]->body())->toBe(substr($content, 2 * YouTubeTestKit::CHUNK))
        ->and(glob(YouTubeTestKit::$dir.'/sp_*'))->toBe([]);
});

it('deletes the kept copy when the upload fails for good or starts over, and keeps it for a retry', function (Closure $response, array $state, bool $kept) {
    $size = 3 * YouTubeTestKit::CHUNK - 1000;
    $copy = YouTubeTestKit::keptCopy(YouTubeTestKit::content($size));

    YouTubeTestKit::fake([
        'PUT session' => [$response()],
        'POST upload' => [YouTubeTestKit::opened(YouTubeTestKit::SESSION_TWO)],
    ]);

    $outcome = null;

    try {
        $outcome = YouTubeTestKit::driver()->resume(
            YouTubeTestKit::post(video: Media::video(YouTubeTestKit::SOURCE)),
            YouTubeTestKit::uploadState($state + ['staged' => $copy, 'size' => $size]),
        );
    } catch (SocialPosterException) {
        // the outcome under test is the file
    }

    expect(is_file($copy['path']))->toBe($kept);

    if ($outcome instanceof Pending) {
        expect($outcome->state['staged'])->toBe($copy);
    }
})->with([
    'daily quota (permanent)' => [fn () => YouTubeTestKit::error('quota_exceeded', 403), [], false],
    'session expired after the last chunk (permanent)' => [fn () => Http::response('', 404), ['offset' => null, 'final_sent' => true], false],
    'incomplete state (restart from scratch)' => [fn () => YouTubeTestKit::video(), ['session' => ''], false],
    'server error (same session later)' => [fn () => YouTubeTestKit::error('backend_error', 503), [], true],
    'dropped connection (same session later)' => [fn () => YouTubeTestKit::drop(), [], true],
    'expired session (new session, same file)' => [fn () => Http::response('', 404), ['offset' => null], true],
]);

it('copies the source again when the kept copy is gone or changed, and never touches a file that is not its own copy', function (Closure $tamper) {
    $size = 3 * YouTubeTestKit::CHUNK - 1000;
    $content = YouTubeTestKit::content($size);
    $copy = $tamper(YouTubeTestKit::keptCopy($content), $content);

    YouTubeTestKit::fake([
        'SOURCE' => YouTubeTestKit::rangeServer($content, ranges: false),
        'POST upload' => [YouTubeTestKit::opened()],
        'PUT session' => [YouTubeTestKit::incomplete(YouTubeTestKit::CHUNK - 1)],
    ]);

    $driver = YouTubeTestKit::driver();
    $driver->chunksPerRun = 1;
    $outcome = $driver->resume(
        YouTubeTestKit::post(video: Media::video(YouTubeTestKit::SOURCE)),
        YouTubeTestKit::uploadState(['staged' => $copy, 'size' => $size]),
    );

    $fresh = $outcome->state['staged'];

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and(YouTubeTestKit::sent('SOURCE'))->toHaveCount(1)
        ->and($fresh['path'])->not->toBe($copy['path'])
        ->and(YouTubeTestKit::keptCopies())->toBe([$fresh['path']])
        ->and(file_get_contents($fresh['path']))->toBe($content)
        ->and(YouTubeTestKit::sent('PUT session')[0]->body())->toBe(substr($content, 0, YouTubeTestKit::CHUNK));

    if (! str_starts_with(basename($copy['path']), 'sp_youtube_')) {
        // A path the state names but this driver never made is neither read nor deleted.
        expect(is_file($copy['path']))->toBeTrue();
    }
})->with([
    'gone (another worker host)' => [fn (array $copy) => tap($copy, fn () => unlink($copy['path']))],
    'other size' => [fn (array $copy, string $content) => tap($copy, fn () => file_put_contents($copy['path'], $content.'tail'))],
    'other mtime' => [fn (array $copy) => tap($copy, fn () => touch($copy['path'], $copy['mtime'] - 120))],
    'not its own copy' => [function (array $copy, string $content) {
        // Same size and mtime, but a name this driver never gives its copies.
        unlink($copy['path']);
        $own = YouTubeTestKit::file(strlen($content));
        touch($own, $copy['mtime']);

        return ['path' => $own] + $copy;
    }],
]);

it('deletes the copy publish() made when the upload session cannot be opened', function (Closure $response) {
    config(['social.api.youtube.range_reads' => false]);

    YouTubeTestKit::fake([
        'SOURCE' => YouTubeTestKit::rangeServer(YouTubeTestKit::content(YouTubeTestKit::CHUNK)),
        'POST upload' => [$response()],
    ]);

    $e = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->publish(YouTubeTestKit::post(video: Media::video(YouTubeTestKit::SOURCE))));

    expect($e)->toBeInstanceOf(SocialPosterException::class)
        ->and(glob(YouTubeTestKit::$dir.'/sp_*'))->toBe([]);
})->with([
    'permanent' => [fn () => YouTubeTestKit::error('quota_exceeded', 403)],
    'temporary' => [fn () => YouTubeTestKit::error('backend_error', 503)],
]);

// --- Thumbnails, processing and playlists ------------------------------------------------

it('sets the thumbnail of a long-form video right after the upload', function () {
    YouTubeTestKit::fake([
        'PUT session' => [YouTubeTestKit::video()],
        'POST thumbnail' => [Http::response(['kind' => 'youtube#thumbnailSetResponse', 'items' => []], 200)],
    ]);

    $image = YouTubeTestKit::file(4000, 'png');
    $video = Media::video(YouTubeTestKit::file(3 * YouTubeTestKit::CHUNK - 1000));
    $post = YouTubeTestKit::post(new YouTubeOptions(thumbnail: Media::image($image)), $video);

    $outcome = YouTubeTestKit::driver()->resume($post, YouTubeTestKit::uploadState(['offset' => null, 'likely_short' => false]));
    $thumbnail = YouTubeTestKit::sent('POST thumbnail')[0];

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->payload['likely_short'])->toBeFalse()
        ->and($outcome->result->payload)->not->toHaveKey('thumbnail_warning')
        ->and($thumbnail->url())->toBe('https://www.googleapis.com/upload/youtube/v3/thumbnails/set?videoId='.YouTubeTestKit::VIDEO_ID)
        ->and($thumbnail->header('Content-Type'))->toBe(['image/png'])
        ->and($thumbnail->body())->toBe(file_get_contents($image))
        ->and(YouTubeTestKit::sent('GET videos'))->toBe([]);
});

it('waits for a likely Short to finish processing before setting its thumbnail', function () {
    YouTubeTestKit::fake([
        'PUT session' => [YouTubeTestKit::video()],
        'GET videos' => [
            YouTubeTestKit::listed('videos_list_processing.json'),
            YouTubeTestKit::listed('videos_list_succeeded.json'),
        ],
        'POST thumbnail' => [Http::response([], 200)],
    ]);

    $driver = YouTubeTestKit::driver();
    $post = YouTubeTestKit::post(video: Media::video(YouTubeTestKit::file(3 * YouTubeTestKit::CHUNK - 1000), YouTubeTestKit::file(2000, 'jpg')));

    $uploaded = $driver->resume($post, YouTubeTestKit::uploadState(['offset' => null, 'likely_short' => true]));

    expect($uploaded)->toBeInstanceOf(Pending::class)
        ->and($uploaded->committed)->toBeTrue()
        ->and($uploaded->recheckAfter)->toBe(30)
        ->and($uploaded->deadline)->toBe($uploaded->state['deadline'])
        ->and($uploaded->deadline)->toBeGreaterThanOrEqual(time() + 3600 + 600)
        ->and($uploaded->state)->toMatchArray(['stage' => 'finalize', 'video_id' => YouTubeTestKit::VIDEO_ID, 'await' => true, 'thumbnail' => true])
        ->and(YouTubeTestKit::sent('POST thumbnail'))->toBe([]);

    $processing = $driver->resume($post, $uploaded->state + [Pending::ATTEMPT => 1]);

    expect($processing)->toBeInstanceOf(Pending::class)
        ->and($processing->committed)->toBeTrue()
        ->and($processing->state['polls'])->toBe(1)
        ->and($processing->state['payload']['processing_status'])->toBe('processing');

    $done = $driver->resume($post, $processing->state);
    $list = YouTubeTestKit::sent('GET videos')[0];
    parse_str((string) parse_url($list->url(), PHP_URL_QUERY), $query);

    expect($done)->toBeInstanceOf(Published::class)
        ->and($done->result->payload)->toMatchArray([
            'likely_short' => true,
            'shorts_url' => 'https://www.youtube.com/shorts/'.YouTubeTestKit::VIDEO_ID,
            'processing_status' => 'succeeded',
            'upload_status' => 'processed',
        ])
        ->and($done->result->url)->toBe('https://www.youtube.com/watch?v='.YouTubeTestKit::VIDEO_ID)
        ->and($query)->toBe(['part' => 'status,processingDetails', 'id' => YouTubeTestKit::VIDEO_ID])
        ->and(YouTubeTestKit::sent('POST thumbnail')[0]->header('Content-Type'))->toBe(['image/jpeg']);
});

it('waits for processing when the shape is unknown and a thumbnail is set, or when asked to', function (?YouTubeOptions $options, bool $thumbnail) {
    YouTubeTestKit::fake(['PUT session' => [YouTubeTestKit::video()]]);

    $video = Media::video(YouTubeTestKit::file(3 * YouTubeTestKit::CHUNK - 1000), $thumbnail ? YouTubeTestKit::file(100, 'jpg') : null);
    $outcome = YouTubeTestKit::driver()->resume(YouTubeTestKit::post($options, $video), YouTubeTestKit::uploadState(['offset' => null]));

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->state['await'])->toBeTrue()
        ->and($outcome->state['thumbnail'])->toBe($thumbnail);
})->with([
    'thumbnail, unknown shape' => [null, true],
    'waitForProcessing' => [new YouTubeOptions(waitForProcessing: true), false],
]);

it('fails the publish on a definitive processing outcome', function (array $overrides, FailureReason $reason, string $message) {
    YouTubeTestKit::fake(['GET videos' => [YouTubeTestKit::listed('videos_list_processing.json', $overrides)]]);

    $e = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->resume(YouTubeTestKit::post(), YouTubeTestKit::finalizeState()));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe($reason)
        ->and($e->context['video_id'])->toBe(YouTubeTestKit::VIDEO_ID)
        ->and($e->context)->toHaveKey('possibly_published')
        ->and($e->possiblyPublished())->toBeFalse()
        ->and($e->getMessage())->toContain($message);
})->with([
    'duplicate' => [['status' => ['uploadStatus' => 'rejected', 'rejectionReason' => 'duplicate']], FailureReason::DuplicateContent, 'duplicate'],
    'suspended' => [['status' => ['uploadStatus' => 'rejected', 'rejectionReason' => 'uploaderAccountSuspended']], FailureReason::AccountRestricted, 'suspended'],
    'closed' => [['status' => ['uploadStatus' => 'rejected', 'rejectionReason' => 'uploaderAccountClosed']], FailureReason::AccountRestricted, 'closed'],
    'too long' => [['status' => ['uploadStatus' => 'rejected', 'rejectionReason' => 'length']], FailureReason::MediaRejected, '15 minutes'],
    'copyright' => [['status' => ['uploadStatus' => 'rejected', 'rejectionReason' => 'copyright']], FailureReason::MediaRejected, 'copyright'],
    'failed' => [['status' => ['uploadStatus' => 'failed', 'failureReason' => 'codec']], FailureReason::MediaRejected, 'codec'],
    'deleted' => [['status' => ['uploadStatus' => 'deleted']], FailureReason::MediaRejected, 'deleted'],
    'terminated' => [['processingDetails' => ['processingStatus' => 'terminated']], FailureReason::MediaRejected, 'terminated'],
]);

it('publishes with a warning when processing outlasts the poll cap, still setting the thumbnail', function () {
    config(['social.api.youtube.max_processing_polls' => 2]);

    YouTubeTestKit::fake([
        'GET videos' => [YouTubeTestKit::listed('videos_list_processing.json')],
        'POST thumbnail' => [Http::response([], 200)],
    ]);

    $post = YouTubeTestKit::post(new YouTubeOptions(thumbnail: Media::image(YouTubeTestKit::file(100, 'jpg'))));
    $outcome = YouTubeTestKit::driver()->resume($post, YouTubeTestKit::finalizeState(['polls' => 1, 'thumbnail' => true]));

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->payload['processing_warning'])->toContain('still processing')
        ->and($outcome->result->payload['thumbnail_warning'])->toContain('before YouTube finished processing')
        ->and(YouTubeTestKit::sent('POST thumbnail'))->toHaveCount(1);
});

it('keeps polling when the status call fails, without failing the live video', function (mixed $response) {
    YouTubeTestKit::fake(['GET videos' => [$response]]);

    $outcome = YouTubeTestKit::driver()->resume(YouTubeTestKit::post(), YouTubeTestKit::finalizeState(['polls' => 3]));

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->committed)->toBeTrue()
        ->and($outcome->state['polls'])->toBe(4)
        ->and($outcome->recheckAfter)->toBe(30);
})->with([
    'server error' => fn () => YouTubeTestKit::error('backend_error', 500),
    'dropped' => fn () => YouTubeTestKit::drop(),
    'not listed yet' => fn () => Http::response(['items' => []], 200),
]);

it('slows the processing polls down as they add up', function () {
    YouTubeTestKit::fake(['GET videos' => [YouTubeTestKit::listed('videos_list_processing.json')]]);

    $outcome = YouTubeTestKit::driver()->resume(YouTubeTestKit::post(), YouTubeTestKit::finalizeState(['polls' => 9]));

    expect($outcome->recheckAfter)->toBe(90);
});

it('retries a thumbnail YouTube cannot see yet once, then warns', function () {
    YouTubeTestKit::fake([
        'PUT session' => [YouTubeTestKit::video()],
        'POST thumbnail' => [YouTubeTestKit::error('video_not_found', 404), YouTubeTestKit::error('thumbnail_rate_limited', 429)],
    ]);

    $driver = YouTubeTestKit::driver();
    $post = YouTubeTestKit::post(new YouTubeOptions(thumbnail: Media::image(YouTubeTestKit::file(100, 'jpg'))));

    $first = $driver->resume($post, YouTubeTestKit::uploadState(['offset' => null, 'likely_short' => false]));

    expect($first)->toBeInstanceOf(Pending::class)
        ->and($first->state)->toMatchArray(['stage' => 'finalize', 'await' => false, 'thumbnail' => true, 'thumbnail_tries' => 1]);

    $second = $driver->resume($post, $first->state);

    expect($second)->toBeInstanceOf(Published::class)
        ->and($second->result->payload['thumbnail_warning'])->toContain('too many thumbnails')
        ->and($second->result->payload['thumbnail_error_reason'])->toBe('uploadRateLimitExceeded')
        ->and(YouTubeTestKit::sent('GET videos'))->toBe([]);
});

it('warns at once when the channel cannot set custom thumbnails', function () {
    YouTubeTestKit::fake([
        'PUT session' => [YouTubeTestKit::video()],
        'POST thumbnail' => [YouTubeTestKit::error('thumbnail_forbidden', 403)],
    ]);

    $post = YouTubeTestKit::post(new YouTubeOptions(thumbnail: Media::image(YouTubeTestKit::file(100, 'jpg'))));
    $outcome = YouTubeTestKit::driver()->resume($post, YouTubeTestKit::uploadState(['offset' => null, 'likely_short' => false]));

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->payload['thumbnail_warning'])->toContain("doesn't have permissions")
        ->and($outcome->result->payload['thumbnail_error_reason'])->toBe('forbidden');
});

it('keeps the 1.0 setThumbnail() hook', function () {
    YouTubeTestKit::fake(['POST thumbnail' => [Http::response([], 200), YouTubeTestKit::error('thumbnail_forbidden', 403)]]);

    $driver = YouTubeTestKit::driver();
    $image = Media::image(YouTubeTestKit::file(100, 'jpg'));

    expect($driver->legacyThumbnail(YouTubeTestKit::post(), YouTubeTestKit::VIDEO_ID, $image))->toBeNull()
        ->and($driver->legacyThumbnail(YouTubeTestKit::post(), YouTubeTestKit::VIDEO_ID, $image))->toContain("doesn't have permissions")
        ->and($driver->legacyThumbnail(YouTubeTestKit::post(), YouTubeTestKit::VIDEO_ID, Media::image(YouTubeTestKit::$dir.'/missing.jpg')))->toContain('not found');
});

it('adds the video to playlists right after the upload, retrying one YouTube cannot see yet', function () {
    YouTubeTestKit::fake([
        'PUT session' => [YouTubeTestKit::video()],
        'POST playlist' => [
            Http::response(['kind' => 'youtube#playlistItem', 'id' => 'UExpdGVtMQ'], 200),
            YouTubeTestKit::error('video_not_found', 404),
            YouTubeTestKit::error('playlist_not_found', 404),
            Http::response(['kind' => 'youtube#playlistItem', 'id' => 'UExpdGVtMg'], 200),
        ],
    ]);

    $driver = YouTubeTestKit::driver();
    $post = YouTubeTestKit::post(new YouTubeOptions(playlistIds: ['PL1', 'PL2', 'PL3', 'PL1', '']));

    $first = $driver->resume($post, YouTubeTestKit::uploadState(['offset' => null]));

    expect($first)->toBeInstanceOf(Pending::class)
        ->and($first->state['playlists'])->toBe([['id' => 'PL2', 'tries' => 1]])
        ->and($first->state['await'])->toBeFalse();

    $done = $driver->resume($post, $first->state);
    $insert = YouTubeTestKit::sent('POST playlist')[0];

    expect($done)->toBeInstanceOf(Published::class)
        ->and($done->result->payload['playlist_items'])->toBe(['PL1' => 'UExpdGVtMQ', 'PL2' => 'UExpdGVtMg'])
        ->and($done->result->payload['playlist_warnings'])->toBe(['PL3' => "The playlist identified with the request's playlistId parameter cannot be found."])
        ->and($insert->url())->toBe('https://www.googleapis.com/youtube/v3/playlistItems?part=snippet')
        ->and($insert->data())->toBe(['snippet' => ['playlistId' => 'PL1', 'resourceId' => ['kind' => 'youtube#video', 'videoId' => YouTubeTestKit::VIDEO_ID]]])
        ->and(YouTubeTestKit::sent('GET videos'))->toBe([]);
});

it('skips playlists the token cannot write and never retries a playlist insert lost to the network', function () {
    YouTubeTestKit::fake([
        'PUT session' => [YouTubeTestKit::video(), YouTubeTestKit::video()],
        'POST playlist' => [YouTubeTestKit::drop()],
    ]);

    $driver = YouTubeTestKit::driver();
    $options = new YouTubeOptions(playlistIds: ['PL1']);

    $noScope = $driver->resume(
        YouTubeTestKit::post($options, credentials: ['scopes' => 'https://www.googleapis.com/auth/youtube.upload']),
        YouTubeTestKit::uploadState(['offset' => null]),
    );
    $dropped = $driver->resume(YouTubeTestKit::post($options), YouTubeTestKit::uploadState(['offset' => null]));

    expect($noScope)->toBeInstanceOf(Published::class)
        ->and($noScope->result->payload['playlist_warnings']['PL1'])->toContain('youtube.force-ssl scope')
        ->and($dropped)->toBeInstanceOf(Published::class)
        ->and($dropped->result->payload['playlist_warnings']['PL1'])->toContain('may or may not have been added')
        ->and(YouTubeTestKit::sent('POST playlist'))->toHaveCount(1);
});

it('never fails a live video when the token expires before the follow-ups', function () {
    YouTubeTestKit::fake([]);

    $driver = YouTubeTestKit::driver();
    $post = YouTubeTestKit::post(credentials: ['expires_at' => time() - 10]);

    $published = $driver->resume($post, YouTubeTestKit::finalizeState());

    expect($published)->toBeInstanceOf(Published::class)
        ->and($published->result->platformPostId)->toBe(YouTubeTestKit::VIDEO_ID)
        ->and($published->result->payload['follow_up_warning'])->toContain('expired');

    Http::assertNothingSent();
});

it('waits for a fresh token twice in the finalize stage when a resolver is bound, then publishes with a warning, never throwing', function () {
    YouTubeTestKit::fake([]);
    app()->instance(CredentialsResolver::class, new YouTubeTestResolver);

    $driver = YouTubeTestKit::driver();
    $post = YouTubeTestKit::post(credentials: ['expires_at' => time() + 30]);

    $first = $driver->resume($post, YouTubeTestKit::finalizeState(['polls' => 4]));

    expect($first)->toBeInstanceOf(Pending::class)
        ->and($first->committed)->toBeTrue()
        ->and($first->commits)->toBeFalse()
        ->and($first->recheckAfter)->toBe(30)
        ->and($first->deadline)->toBe($first->state['deadline'])
        ->and($first->state['token_waits'])->toBe(1)
        ->and($first->state['polls'])->toBe(4);

    $second = $driver->resume($post, $first->state + [Pending::ATTEMPT => 1]);

    expect($second)->toBeInstanceOf(Pending::class)
        ->and($second->state['token_waits'])->toBe(2);

    $third = $driver->resume($post, $second->state);

    expect($third)->toBeInstanceOf(Published::class)
        ->and($third->result->payload['follow_up_warning'])->toContain('expired');

    Http::assertNothingSent();
});

it('forgets the token waits once a fresh token arrives', function () {
    YouTubeTestKit::fake(['GET videos' => [YouTubeTestKit::listed('videos_list_processing.json')]]);
    app()->instance(CredentialsResolver::class, new YouTubeTestResolver);

    $outcome = YouTubeTestKit::driver()->resume(YouTubeTestKit::post(), YouTubeTestKit::finalizeState(['token_waits' => 2]));

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->state)->not->toHaveKey('token_waits')
        ->and($outcome->state['polls'])->toBe(1);
});

it('sends onBehalfOfContentOwner with the follow-up calls of a content-owner upload', function () {
    YouTubeTestKit::fake([
        'PUT session' => [YouTubeTestKit::video()],
        'POST playlist' => [Http::response(['kind' => 'youtube#playlistItem', 'id' => 'UExpdGVtMQ'], 200)],
        'GET videos' => [YouTubeTestKit::listed('videos_list_succeeded.json')],
        'POST thumbnail' => [Http::response([], 200)],
    ]);

    $driver = YouTubeTestKit::driver();
    $post = YouTubeTestKit::post(new YouTubeOptions(
        thumbnail: Media::image(YouTubeTestKit::file(100, 'jpg')),
        extra: ['query' => ['onBehalfOfContentOwner' => 'CMS-owner', 'onBehalfOfContentOwnerChannel' => 'UCcontent']],
        playlistIds: ['PL1'],
        waitForProcessing: true,
    ));

    $uploaded = $driver->resume($post, YouTubeTestKit::uploadState(['offset' => null, 'likely_short' => false]));
    $done = $driver->resume($post, $uploaded->state);

    $query = function (string $key): array {
        parse_str((string) parse_url(YouTubeTestKit::sent($key)[0]->url(), PHP_URL_QUERY), $query);

        return $query;
    };

    expect($done)->toBeInstanceOf(Published::class)
        ->and($done->result->payload['playlist_items'])->toBe(['PL1' => 'UExpdGVtMQ'])
        ->and($query('POST playlist'))->toBe(['part' => 'snippet', 'onBehalfOfContentOwner' => 'CMS-owner'])
        ->and($query('GET videos'))->toBe(['part' => 'status,processingDetails', 'id' => YouTubeTestKit::VIDEO_ID, 'onBehalfOfContentOwner' => 'CMS-owner'])
        ->and($query('POST thumbnail'))->toBe(['videoId' => YouTubeTestKit::VIDEO_ID, 'onBehalfOfContentOwner' => 'CMS-owner']);
});

it('never retries a playlist insert that failed with a server error, since it may have been added', function () {
    YouTubeTestKit::fake([
        'PUT session' => [YouTubeTestKit::video()],
        'POST playlist' => [YouTubeTestKit::error('backend_error', 503)],
    ]);

    $outcome = YouTubeTestKit::driver()->resume(YouTubeTestKit::post(new YouTubeOptions(playlistIds: ['PL1'])), YouTubeTestKit::uploadState(['offset' => null]));

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->payload['playlist_warnings']['PL1'])->toContain('may or may not have been added')
        ->and(YouTubeTestKit::sent('POST playlist'))->toHaveCount(1);
});

it('checks the playlist before adding the video on a run that may repeat an earlier one', function () {
    YouTubeTestKit::fake([
        'PUT session' => [YouTubeTestKit::video([], 200), YouTubeTestKit::video([], 200), YouTubeTestKit::video([], 200)],
        'GET playlist' => [
            Http::response(['kind' => 'youtube#playlistItemListResponse', 'items' => [['kind' => 'youtube#playlistItem', 'id' => 'UExpdGVtMQ']]], 200),
            Http::response(['kind' => 'youtube#playlistItemListResponse', 'items' => []], 200),
            YouTubeTestKit::error('backend_error', 500),
        ],
        'POST playlist' => [Http::response(['kind' => 'youtube#playlistItem', 'id' => 'UExpdGVtMg'], 200)],
    ]);

    $driver = YouTubeTestKit::driver();
    $post = YouTubeTestKit::post(new YouTubeOptions(playlistIds: ['PL1']));

    $already = $driver->resume($post, YouTubeTestKit::uploadState([Pending::ATTEMPT => 2]));
    $missing = $driver->resume($post, YouTubeTestKit::uploadState([Pending::RECOVERING => true]));
    $unknown = $driver->resume($post, YouTubeTestKit::uploadState([Pending::ATTEMPT => 3]));

    parse_str((string) parse_url(YouTubeTestKit::sent('GET playlist')[0]->url(), PHP_URL_QUERY), $query);

    expect($already)->toBeInstanceOf(Published::class)
        ->and($already->result->payload['playlist_items'])->toBe(['PL1' => 'UExpdGVtMQ'])
        ->and($missing->result->payload['playlist_items'])->toBe(['PL1' => 'UExpdGVtMg'])
        ->and($unknown->result->payload)->not->toHaveKey('playlist_items')
        ->and($unknown->result->payload['playlist_warnings']['PL1'])->toContain('could not check')
        ->and($query)->toBe(['part' => 'id', 'playlistId' => 'PL1', 'videoId' => YouTubeTestKit::VIDEO_ID, 'maxResults' => '1'])
        ->and(YouTubeTestKit::sent('POST playlist'))->toHaveCount(1);
});

it('checks the playlist before a finalize retry on a redelivered run', function () {
    YouTubeTestKit::fake([
        'GET playlist' => [Http::response(['items' => [['kind' => 'youtube#playlistItem', 'id' => 'UExpdGVtMQ']]], 200)],
    ]);

    $outcome = YouTubeTestKit::driver()->resume(
        YouTubeTestKit::post(new YouTubeOptions(playlistIds: ['PL1'])),
        YouTubeTestKit::finalizeState(['await' => false, 'playlists' => [['id' => 'PL1', 'tries' => 1]], Pending::ATTEMPT => 2]),
    );

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->payload['playlist_items'])->toBe(['PL1' => 'UExpdGVtMQ'])
        ->and(YouTubeTestKit::sent('POST playlist'))->toBe([]);
});

it('reports a finalize state without a video id as possibly published', function () {
    $e = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->resume(YouTubeTestKit::post(), YouTubeTestKit::finalizeState(['video_id' => ''])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->possiblyPublished())->toBeTrue();
});

// --- Validation ------------------------------------------------------------------------------

it('accepts a valid post', function () {
    $post = YouTubeTestKit::post(new YouTubeOptions(tags: ['a', 'b c'], categoryId: '22', publishAt: now()->addDay(), license: 'youtube'));

    expect(YouTubeTestKit::driver()->validate($post)->all())->toBe([]);
});

it('validates titles and descriptions the way YouTube counts them', function (?string $title, ?string $caption, string $key, string $message) {
    $errors = YouTubeTestKit::driver()->validate(YouTubeTestKit::post(title: $title, caption: $caption));

    expect($errors->get($key))->toContain($message);
})->with([
    'no title' => [null, 'x', 'title', 'YouTube uploads need a title. Set it with ->title().'],
    'long title' => [str_repeat('é', 101), 'x', 'title', "YouTube titles can't be longer than 100 characters."],
    'angle title' => ['A -> B', 'x', 'title', "YouTube titles can't contain < or >."],
    'angle description' => ['T', 'I <3 this', 'caption', "YouTube descriptions can't contain < or >."],
    'description bytes' => ['T', str_repeat('é', 2600), 'caption', 'Caption exceeds the 5000-byte limit (currently 5200 bytes of UTF-8).'],
]);

it('validates tags, category, licence, playlists and scheduling', function (YouTubeOptions $options, string $key, string $message) {
    $errors = YouTubeTestKit::driver()->validate(YouTubeTestKit::post($options));

    expect(implode(' | ', $errors->get($key)))->toContain($message);
})->with([
    'tags total' => [fn () => new YouTubeOptions(tags: array_fill(0, 50, 'nine char')), 'tags', 'yours total 599'],
    'empty tag' => [fn () => new YouTubeOptions(tags: ['ok', ' ']), 'tags', 'Tag #1 is empty.'],
    'angle tag' => [fn () => new YouTubeOptions(tags: ['<b>']), 'tags', "can't contain < or >"],
    'category' => [fn () => new YouTubeOptions(categoryId: 'People'), 'categoryId', 'numeric'],
    'licence' => [fn () => new YouTubeOptions(license: 'YouTube'), 'license', "'youtube' or 'creativeCommon'"],
    'playlist' => [fn () => new YouTubeOptions(playlistIds: ['PL1', ' ']), 'playlistIds', 'Playlist id #1 is empty.'],
    'past' => [fn () => new YouTubeOptions(publishAt: now()->subMinute()), 'publishAt', 'must be in the future'],
    'public scheduled' => [fn () => new YouTubeOptions(privacyStatus: YouTubePrivacy::Public, publishAt: now()->addDay()), 'publishAt', 'must be private until its publish time'],
    'unlisted legacy scheduled' => [fn () => new YouTubeOptions(privacyStatus: YouTubePrivacy::Unlisted, extra: ['status' => ['publishAt' => '2030-01-01T00:00:00Z']]), 'publishAt', 'got unlisted'],
]);

it('counts tags like YouTube: commas between tags and quotes around tags with spaces', function () {
    expect((new YouTubeOptions(tags: ['ab', 'c d', 'e']))->tagsLength())->toBe(2 + 1 + 5 + 1 + 1)
        ->and((new YouTubeOptions(tags: array_fill(0, 50, 'abcdefghi')))->validate()->has('tags'))->toBeFalse();
});

it('validates the YouTube thumbnail option', function (Closure $post, string $message) {
    $errors = YouTubeTestKit::driver()->validate($post());

    expect(implode(' | ', $errors->get('thumbnail')))->toContain($message);
})->with([
    'extension' => [fn () => YouTubeTestKit::post(new YouTubeOptions(thumbnail: Media::image('https://cdn.example.com/cover.webp'))), 'JPG or PNG'],
    'size' => [fn () => YouTubeTestKit::post(new YouTubeOptions(thumbnail: Media::image('https://cdn.example.com/cover.jpg', meta: ['size' => 52428801]))), 'larger than 50MB'],
    'mime' => [fn () => YouTubeTestKit::post(new YouTubeOptions(thumbnail: Media::image('https://cdn.example.com/cover.jpg', meta: ['mime' => 'image/webp']))), 'got image/webp'],
]);

it('accepts a YouTubeOptions thumbnail without an extension, as the shared thumbnail is', function () {
    $post = YouTubeTestKit::post(new YouTubeOptions(thumbnail: Media::image('https://cdn.example.com/covers/cover-abc', meta: ['size' => 1000])));

    expect(YouTubeTestKit::driver()->validate($post)->get('thumbnail'))->toBe([]);
});

it('never refuses a post over the shared video thumbnail, which 1.0 ignored', function (string $cover) {
    $post = YouTubeTestKit::post(video: Media::video('https://cdn.example.com/clip.mp4', $cover));

    expect(YouTubeTestKit::driver()->validate($post)->get('thumbnail'))->toBe([]);
})->with([
    'webp' => 'https://images.example.com/cover.webp',
    'gif' => 'https://cdn.example.com/cover.gif',
    'no extension' => 'https://images.example.com/cover-abc',
]);

it('skips a shared video thumbnail YouTube cannot take with a warning, without waiting for processing', function () {
    YouTubeTestKit::fake(['PUT session' => [YouTubeTestKit::video()]]);

    $post = YouTubeTestKit::post(video: Media::video(YouTubeTestKit::file(3 * YouTubeTestKit::CHUNK - 1000), 'https://images.example.com/cover.webp'));
    $outcome = YouTubeTestKit::driver()->resume($post, YouTubeTestKit::uploadState(['offset' => null, 'likely_short' => true]));

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->payload['thumbnail_warning'])->toContain('JPG or PNG')
        ->and(YouTubeTestKit::sent('POST thumbnail'))->toBe([])
        ->and(YouTubeTestKit::sent('GET videos'))->toBe([]);
});

it('sends a shared video thumbnail without an extension as application/octet-stream', function () {
    $jpeg = "\xFF\xD8\xFF\xE0".str_repeat('J', 200);

    YouTubeTestKit::fake([
        'PUT session' => [YouTubeTestKit::video()],
        'SOURCE' => fn () => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
        'POST thumbnail' => [Http::response([], 200)],
    ]);

    $post = YouTubeTestKit::post(video: Media::video(YouTubeTestKit::file(3 * YouTubeTestKit::CHUNK - 1000), 'https://cdn.example.com/covers/cover-abc'));
    $outcome = YouTubeTestKit::driver()->resume($post, YouTubeTestKit::uploadState(['offset' => null, 'likely_short' => false]));
    $thumbnail = YouTubeTestKit::sent('POST thumbnail')[0];

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->payload)->not->toHaveKey('thumbnail_warning')
        ->and($thumbnail->header('Content-Type'))->toBe(['application/octet-stream'])
        ->and($thumbnail->body())->toBe($jpeg);
});

it('accepts thumbnails above the old 2MB limit', function () {
    $post = YouTubeTestKit::post(new YouTubeOptions(thumbnail: Media::image('https://cdn.example.com/cover.jpg', meta: ['size' => 3 * 1048576, 'mime' => 'image/jpeg'])));

    expect(YouTubeTestKit::driver()->validate($post)->has('thumbnail'))->toBeFalse();
});

it('validates extra query parameters, localizations, the video and the granted scopes', function (Closure $post, string $key, string $message) {
    $errors = YouTubeTestKit::driver()->validate($post());

    expect(implode(' | ', $errors->get($key)))->toContain($message);
})->with([
    'owner without channel' => [fn () => YouTubeTestKit::post(new YouTubeOptions(extra: ['query' => ['onBehalfOfContentOwner' => 'o']])), 'extra.query', 'onBehalfOfContentOwnerChannel'],
    'query not an array' => [fn () => YouTubeTestKit::post(new YouTubeOptions(extra: ['query' => 'notifySubscribers=false'])), 'extra.query', 'must be an array'],
    'localizations' => [fn () => YouTubeTestKit::post(new YouTubeOptions(extra: ['localizations' => ['fr' => ['title' => 'T']]])), 'extra.localizations', 'default language'],
    'too long' => [fn () => YouTubeTestKit::post(video: Media::video('https://cdn.example.com/clip.mp4', meta: ['duration' => 43201])), 'media.0', 'maximum is 43200s'],
    'image' => [fn () => YouTubeTestKit::post(video: Media::image('https://cdn.example.com/cover.jpg')), 'media.0', 'image media is not supported'],
    'scopes' => [fn () => YouTubeTestKit::post(credentials: ['scopes' => 'https://www.googleapis.com/auth/youtube.readonly']), 'credentials.scopes', 'youtube.upload'],
]);

it('accepts localizations with a default language and scopes in either spelling', function () {
    $driver = YouTubeTestKit::driver();

    $localized = YouTubeTestKit::post(new YouTubeOptions(defaultLanguage: 'en', extra: ['localizations' => ['fr' => ['title' => 'T']]]));
    $short = YouTubeTestKit::post(credentials: ['scopes' => ['youtube.upload']]);
    $full = YouTubeTestKit::post(credentials: ['scopes' => 'openid https://www.googleapis.com/auth/youtube']);

    expect($driver->validate($localized)->all())->toBe([])
        ->and($driver->validate($short)->all())->toBe([])
        ->and($driver->validate($full)->all())->toBe([]);
});

// --- Error table ---------------------------------------------------------------------------

it('maps Google errors to failure reasons', function (string $fixture, int $status, string $class, FailureReason $reason, array $context, string $operation = 'upload') {
    $response = new Response(new PsrResponse($status, ['Content-Type' => 'application/json'], (string) file_get_contents(__DIR__.'/../../Fixtures/youtube/'.$fixture.'.json')));

    $e = YouTubeTestKit::driver()->classify($response, $operation);

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason)
        ->and($e->platform)->toBe(Platform::YouTube)
        ->and($e->context['status'])->toBe($status)
        ->and($e->context['http_status'])->toBe($status)
        ->and($e->context['error'])->toBeArray()
        ->and($e->context)->toMatchArray($context);
})->with([
    'daily limit' => ['daily_limit_exceeded', 403, PermanentException::class, FailureReason::RateLimited, ['quota' => 'daily', 'reason' => 'dailyLimitExceeded']],
    'rate limit 403' => ['rate_limit_exceeded', 403, TemporaryException::class, FailureReason::RateLimited, ['reason' => 'rateLimitExceeded']],
    'rate limit 429' => ['rate_limit_429', 429, TemporaryException::class, FailureReason::RateLimited, ['google_status' => 'RESOURCE_EXHAUSTED']],
    'resource exhausted' => ['resource_exhausted_status_only', 429, TemporaryException::class, FailureReason::RateLimited, ['reason' => 'RATE_LIMIT_EXCEEDED']],
    'upload limit' => ['upload_limit_exceeded', 400, PermanentException::class, FailureReason::RateLimited, ['upload_limit' => true]],
    'title' => ['invalid_title', 400, PermanentException::class, FailureReason::InvalidContent, ['field' => 'title', 'location' => 'body.snippet.title']],
    'description' => ['invalid_description', 400, PermanentException::class, FailureReason::InvalidContent, ['field' => 'description']],
    'tags' => ['invalid_tags', 400, PermanentException::class, FailureReason::InvalidContent, ['field' => 'tags']],
    'category' => ['invalid_category_id', 400, PermanentException::class, FailureReason::InvalidContent, ['field' => 'categoryId']],
    'publishAt' => ['invalid_publish_at', 400, PermanentException::class, FailureReason::InvalidContent, ['field' => 'publishAt']],
    'language' => ['default_language_not_set', 400, PermanentException::class, FailureReason::InvalidContent, ['field' => 'defaultLanguage']],
    'recording' => ['invalid_recording_details', 400, PermanentException::class, FailureReason::InvalidContent, ['field' => 'recordingDetails']],
    'game rating' => ['invalid_video_game_rating', 400, PermanentException::class, FailureReason::InvalidContent, ['field' => 'videoGameRating']],
    'metadata' => ['invalid_video_metadata', 400, PermanentException::class, FailureReason::InvalidContent, ['field' => 'metadata']],
    'no media' => ['media_body_required', 400, PermanentException::class, FailureReason::MediaRejected, ['reason' => 'mediaBodyRequired']],
    'filename' => ['invalid_filename', 400, PermanentException::class, FailureReason::MediaRejected, ['reason' => 'invalidFilename']],
    'privacy' => ['forbidden_privacy_setting', 403, PermanentException::class, FailureReason::InsufficientPermissions, ['field' => 'privacyStatus', 'reconnect' => false]],
    'license' => ['forbidden_license_setting', 403, PermanentException::class, FailureReason::InsufficientPermissions, ['field' => 'license', 'reconnect' => false]],
    'api disabled' => ['access_not_configured', 403, PermanentException::class, FailureReason::Misconfigured, ['developer_config' => true, 'error_info' => ['SERVICE_DISABLED']]],
    'account disabled' => ['account_disabled', 403, PermanentException::class, FailureReason::AccountRestricted, ['reason' => 'accountDisabled']],
    'no channel' => ['youtube_signup_required', 401, PermanentException::class, FailureReason::AccountRestricted, ['reason' => 'youtubeSignupRequired']],
    'expired token' => ['auth_error_expired', 401, PermanentException::class, FailureReason::InvalidToken, ['token_expired' => true, 'reason' => 'authError', 'google_status' => 'UNAUTHENTICATED']],
    'scope' => ['insufficient_permissions', 403, PermanentException::class, FailureReason::InsufficientPermissions, ['missing_scope' => true]],
    'forbidden' => ['forbidden', 403, PermanentException::class, FailureReason::InsufficientPermissions, ['reason' => 'forbidden']],
    'backend' => ['backend_error', 500, TemporaryException::class, FailureReason::ServerError, ['reason' => 'backendError']],
    'not found' => ['video_not_found', 404, PermanentException::class, FailureReason::NotFound, ['reason' => 'videoNotFound']],
    'unknown 400' => ['bad_request_unknown', 400, PermanentException::class, FailureReason::Unknown, ['reason' => 'badRequest']],
    'comment: video processing' => ['video_not_found', 404, TemporaryException::class, FailureReason::NotFound, ['video_processing' => true], 'comment'],
    'comment: forbidden' => ['comment_forbidden', 403, PermanentException::class, FailureReason::AccountRestricted, ['comments_forbidden' => true], 'comment'],
    'comment: disabled' => ['comments_disabled', 403, PermanentException::class, FailureReason::InvalidContent, ['comments_disabled' => true], 'comment'],
    'comment: ineligible' => ['ineligible_account', 403, PermanentException::class, FailureReason::AccountRestricted, ['reason' => 'ineligibleAccount'], 'comment'],
    'comment: too long' => ['comment_text_too_long', 400, PermanentException::class, FailureReason::InvalidContent, ['field' => 'comment'], 'comment'],
    'comment: ids missing' => ['channel_or_video_id_missing', 400, PermanentException::class, FailureReason::Misconfigured, ['reason' => 'channelOrVideoIdMissing'], 'comment'],
]);

it('waits 60 seconds on short-term limits unless Google says otherwise', function () {
    $driver = YouTubeTestKit::driver();
    $body = (string) file_get_contents(__DIR__.'/../../Fixtures/youtube/rate_limit_429.json');

    $default = $driver->classify(new Response(new PsrResponse(429, [], $body)));
    $header = $driver->classify(new Response(new PsrResponse(429, ['Retry-After' => '17'], $body)));

    expect($default->retryAfter)->toBe(60)
        ->and($header->retryAfter)->toBe(17);
});

it('handles error bodies that are not JSON', function () {
    $e = YouTubeTestKit::driver()->classify(new Response(new PsrResponse(502, ['Content-Type' => 'text/html'], '<html>Bad Gateway</html>')));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::ServerError)
        ->and($e->context['error'])->toBe([]);
});

it('re-resolves an expired token once when a resolver is bound', function () {
    app()->instance(CredentialsResolver::class, new YouTubeTestResolver);

    $body = (string) file_get_contents(__DIR__.'/../../Fixtures/youtube/auth_error_expired.json');
    $e = YouTubeTestKit::driver()->classify(new Response(new PsrResponse(401, [], $body)));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::InvalidToken)
        ->and($e->context['reresolve'])->toBeTrue()
        ->and($e->getPrevious())->toBeInstanceOf(PermanentException::class);
});

// --- First comment -------------------------------------------------------------------------

it('declares comment rules, and none for videos that cannot take comments', function () {
    $driver = YouTubeTestKit::driver();
    $rules = $driver->commentRules(YouTubeTestKit::post());
    $published = PostResult::success(Platform::YouTube, YouTubeTestKit::VIDEO_ID, payload: ['privacy_status' => 'public'], state: PostResult::STATE_PUBLISHED);

    expect($driver)->toBeInstanceOf(SupportsFirstComment::class)
        ->and($rules->max)->toBe(10000)
        ->and($rules->minDelaySeconds)->toBe(60)
        ->and($rules->unknownScopePolicy)->toBe('attempt')
        ->and($rules->requiredScopes)->toBe([['https://www.googleapis.com/auth/youtube.force-ssl', 'youtube.force-ssl']])
        ->and($driver->commentRules(YouTubeTestKit::post(), $published))->not->toBeNull()
        ->and($driver->commentRules(YouTubeTestKit::post(new YouTubeOptions(privacyStatus: YouTubePrivacy::Unlisted))))->not->toBeNull()
        ->and($driver->commentRules(YouTubeTestKit::post(new YouTubeOptions(madeForKids: true))))->toBeNull()
        ->and($driver->commentRules(YouTubeTestKit::post(new YouTubeOptions(privacyStatus: YouTubePrivacy::Private))))->toBeNull()
        ->and($driver->commentRules(YouTubeTestKit::post(new YouTubeOptions(publishAt: now()->addDay()))))->toBeNull()
        ->and($driver->commentRules(YouTubeTestKit::post(new YouTubeOptions(extra: ['status' => ['publishAt' => '2030-01-01T00:00:00Z']]))))->toBeNull()
        ->and($driver->commentRules(YouTubeTestKit::post(), PostResult::success(Platform::YouTube, 'x', state: PostResult::STATE_PRIVATE)))->toBeNull()
        ->and($driver->commentRules(YouTubeTestKit::post(), PostResult::success(Platform::YouTube, 'x', state: PostResult::STATE_SCHEDULED)))->toBeNull()
        ->and($driver->commentRules(YouTubeTestKit::post(), PostResult::success(Platform::YouTube, 'x', payload: ['privacy_status' => 'private'])))->toBeNull();

    config(['social.api.youtube.comment_delay' => 120]);

    expect($driver->commentRules(YouTubeTestKit::post())->minDelaySeconds)->toBe(120);
});

it('adds the first comment as a comment thread on the video', function () {
    YouTubeTestKit::fake(['POST comment' => [Http::response([
        'kind' => 'youtube#commentThread',
        'id' => 'UgzThread',
        'snippet' => [
            'channelId' => 'UCuAXFkgsw1L7xaCfnd5JJOw',
            'videoId' => YouTubeTestKit::VIDEO_ID,
            'topLevelComment' => ['kind' => 'youtube#comment', 'id' => 'UgzThread', 'snippet' => ['textOriginal' => 'First!']],
        ],
    ], 200)]]);

    $parent = PostResult::success(Platform::YouTube, YouTubeTestKit::VIDEO_ID);
    $comment = YouTubeTestKit::driver()->addFirstComment(YouTubeTestKit::post(), $parent, '  First!  ');
    $request = YouTubeTestKit::sent('POST comment')[0];

    expect($comment->id)->toBe('UgzThread')
        ->and($comment->url)->toBe('https://www.youtube.com/watch?v='.YouTubeTestKit::VIDEO_ID.'&lc=UgzThread')
        ->and($comment->payload)->toBe(['thread_id' => 'UgzThread', 'comment_id' => 'UgzThread', 'channel_id' => 'UCuAXFkgsw1L7xaCfnd5JJOw', 'video_id' => YouTubeTestKit::VIDEO_ID])
        ->and($request->url())->toBe('https://www.googleapis.com/youtube/v3/commentThreads?part=snippet')
        ->and($request->data())->toBe(['snippet' => [
            'channelId' => 'UCuAXFkgsw1L7xaCfnd5JJOw',
            'videoId' => YouTubeTestKit::VIDEO_ID,
            'topLevelComment' => ['snippet' => ['textOriginal' => 'First!']],
        ]]);
});

it('finds the channel from the upload payload, then from channels.list', function () {
    YouTubeTestKit::fake([
        'GET channels' => [Http::response(['items' => [['kind' => 'youtube#channel', 'id' => 'UCfromList']]], 200), Http::response(['items' => []], 200)],
        'POST comment' => [Http::response(['id' => 'Ugz1'], 200), Http::response(['id' => 'Ugz2'], 200)],
    ]);

    $driver = YouTubeTestKit::driver();
    $post = new PreparedPost(Platform::YouTube, new SocialPost(title: 'T'), new Credentials(['access_token' => 'ya29.x']));

    $driver->addFirstComment($post, PostResult::success(Platform::YouTube, YouTubeTestKit::VIDEO_ID, payload: ['channel_id' => 'UCfromUpload']), 'Hi');
    $driver->addFirstComment($post, PostResult::success(Platform::YouTube, YouTubeTestKit::VIDEO_ID), 'Hi');
    $none = YouTubeTestKit::catch(fn () => $driver->addFirstComment($post, PostResult::success(Platform::YouTube, YouTubeTestKit::VIDEO_ID), 'Hi'));

    $comments = YouTubeTestKit::sent('POST comment');
    parse_str((string) parse_url(YouTubeTestKit::sent('GET channels')[0]->url(), PHP_URL_QUERY), $query);

    expect($comments[0]->data()['snippet']['channelId'])->toBe('UCfromUpload')
        ->and($comments[1]->data()['snippet']['channelId'])->toBe('UCfromList')
        ->and($query)->toBe(['part' => 'id', 'mine' => 'true'])
        ->and($none)->toBeInstanceOf(PermanentException::class)
        ->and($none->reason)->toBe(FailureReason::AccountRestricted);
});

it('maps first-comment failures', function (mixed $response, string $class, FailureReason $reason) {
    YouTubeTestKit::fake(['POST comment' => [$response]]);

    $e = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->addFirstComment(YouTubeTestKit::post(), PostResult::success(Platform::YouTube, YouTubeTestKit::VIDEO_ID), 'Hi'));

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason)
        ->and($e->reason->requiresReconnect())->toBeFalse();
})->with([
    'still processing' => [fn () => YouTubeTestKit::error('video_not_found', 404), TemporaryException::class, FailureReason::NotFound],
    'forbidden' => [fn () => YouTubeTestKit::error('comment_forbidden', 403), PermanentException::class, FailureReason::AccountRestricted],
    'disabled' => [fn () => YouTubeTestKit::error('comments_disabled', 403), PermanentException::class, FailureReason::InvalidContent],
    'quota' => [fn () => YouTubeTestKit::error('quota_exceeded', 403), PermanentException::class, FailureReason::RateLimited],
    'no id' => [fn () => Http::response(['kind' => 'youtube#commentThread'], 200), PermanentException::class, FailureReason::OutcomeUnknown],
]);

it('looks for its own comment in a recovery and returns it instead of commenting again', function () {
    YouTubeTestKit::fake(['GET comments' => [Http::response(['items' => [[
        'id' => 'UgzThread',
        'snippet' => ['topLevelComment' => ['id' => 'UgzComment', 'snippet' => [
            'authorChannelId' => ['value' => 'UCuAXFkgsw1L7xaCfnd5JJOw'],
            'textOriginal' => "Line one\nLine two",
        ]]],
    ]]], 200)]]);

    $parent = PostResult::success(Platform::YouTube, YouTubeTestKit::VIDEO_ID, payload: [Pending::RECOVERING => true]);
    $comment = YouTubeTestKit::driver()->addFirstComment(YouTubeTestKit::post(), $parent, " Line one\r\nLine two ");

    expect($comment->id)->toBe('UgzComment')
        ->and($comment->payload)->toMatchArray(['thread_id' => 'UgzThread', 'comment_id' => 'UgzComment', 'recovered' => true])
        ->and(YouTubeTestKit::sent('POST comment'))->toBe([]);
});

it('never comments in a recovery it cannot check', function (mixed $response, string $class, FailureReason $reason) {
    YouTubeTestKit::fake(['GET comments' => [$response]]);

    $parent = PostResult::success(Platform::YouTube, YouTubeTestKit::VIDEO_ID, payload: [Pending::RECOVERING => true]);
    $e = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->addFirstComment(YouTubeTestKit::post(), $parent, 'Hi'));

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason)
        ->and(YouTubeTestKit::sent('POST comment'))->toBe([]);

    if ($e instanceof PermanentException) {
        expect($e->possiblyPublished())->toBeTrue()
            ->and($e->context['recovering'])->toBeTrue();
    }
})->with([
    'read refused' => [fn () => YouTubeTestKit::error('comment_forbidden', 403), PermanentException::class, FailureReason::OutcomeUnknown],
    'daily quota' => [fn () => YouTubeTestKit::error('quota_exceeded', 403), PermanentException::class, FailureReason::OutcomeUnknown],
    'backend error' => [fn () => YouTubeTestKit::error('backend_error', 503), TemporaryException::class, FailureReason::ServerError],
]);

it('refuses a comment without text, video or fresh token before calling YouTube', function () {
    YouTubeTestKit::fake([]);
    $driver = YouTubeTestKit::driver();
    $parent = PostResult::success(Platform::YouTube, YouTubeTestKit::VIDEO_ID);

    $empty = YouTubeTestKit::catch(fn () => $driver->addFirstComment(YouTubeTestKit::post(), $parent, '   '));
    $noVideo = YouTubeTestKit::catch(fn () => $driver->addFirstComment(YouTubeTestKit::post(), PostResult::success(Platform::YouTube), 'Hi'));
    $expired = YouTubeTestKit::catch(fn () => $driver->addFirstComment(YouTubeTestKit::post(credentials: ['expires_at' => time()]), $parent, 'Hi'));

    expect($empty->reason)->toBe(FailureReason::InvalidContent)
        ->and($noVideo->reason)->toBe(FailureReason::NotFound)
        ->and($expired->reason)->toBe(FailureReason::InvalidToken);

    Http::assertNothingSent();
});

// --- Identity, scopes, registration ----------------------------------------------------------

it('identifies the account by channel_id and declares scopes per feature', function () {
    $driver = YouTubeTestKit::driver();

    expect($driver->accountKey(new Credentials(['channel_id' => 'UC1', 'access_token' => 't'])))->toBe('UC1')
        ->and($driver->accountKey(new Credentials(['access_token' => 't'])))->toBe('')
        ->and($driver->scopesFor(Feature::Publish))->toBe([[
            'https://www.googleapis.com/auth/youtube.upload',
            'https://www.googleapis.com/auth/youtube',
            'https://www.googleapis.com/auth/youtube.force-ssl',
            'https://www.googleapis.com/auth/youtubepartner',
            'youtube.upload', 'youtube', 'youtube.force-ssl', 'youtubepartner',
        ]])
        ->and($driver->scopesFor(Feature::Comment))->toBe([['https://www.googleapis.com/auth/youtube.force-ssl', 'youtube.force-ssl']])
        ->and($driver->scopesFor(Feature::Video))->toBe([])
        ->and($driver->missingScopes(Feature::Publish, new Credentials(['scopes' => 'youtube.readonly'])))->toHaveCount(1);
});

it('is the registered YouTube driver', function () {
    $driver = app(SocialManager::class)->platform('youtube');

    expect($driver)->toBeInstanceOf(YouTubeDriver::class)
        ->and($driver)->toBeInstanceOf(SupportsFirstComment::class)
        ->and($driver->platform())->toBe(Platform::YouTube)
        ->and($driver->capabilities()->supportsTitle)->toBeTrue()
        ->and($driver->capabilities()->caption->maxBytes)->toBe(5000);
});

it('only throws package exceptions from the upload path', function () {
    YouTubeTestKit::fake(['POST upload' => [YouTubeTestKit::error('access_not_configured', 403)]]);

    $e = YouTubeTestKit::catch(fn () => YouTubeTestKit::driver()->publish(YouTubeTestKit::post()));

    expect($e)->toBeInstanceOf(SocialPosterException::class)
        ->and($e->reason)->toBe(FailureReason::Misconfigured);
});
