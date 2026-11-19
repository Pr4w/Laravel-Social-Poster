<?php

namespace SocialPoster\Tests\Feature\Platforms\TikTokDriverTest;

use Composer\Autoload\ClassLoader;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Contracts\MediaGateway;
use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Contracts\MediaTransformer;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\MediaType;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Gateways\FilesystemMediaGateway;
use SocialPoster\Gateways\LocalMediaGateway;
use SocialPoster\Jobs\PublishToConnectionJob;
use SocialPoster\Platforms\TikTok\TikTokDriver;
use SocialPoster\Platforms\TikTok\TikTokOptions;
use SocialPoster\Platforms\TikTok\TikTokPrivacy;
use SocialPoster\Platforms\TikTok\TikTokTarget;
use SocialPoster\Platforms\TikTok\TikTokTransfer;
use SocialPoster\Tests\Fixtures\TikTok\LegacyDirectPostInfoDriver;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\Published;
use SocialPoster\ValueObjects\SocialPost;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * A driver with tiny chunks (4 bytes, single chunk up to 8) so the chunked
 * upload can be exercised with 10-byte files, and a switchable run budget.
 */
class TikTokTestDriver extends TikTokDriver
{
    protected int $uploadChunkSize = 4;

    protected int $maxSingleChunk = 8;

    protected int $minChunkSize = 1;

    public ?bool $budget = null;

    protected function budgetLeft(float $startedAt): bool
    {
        return $this->budget ?? parent::budgetLeft($startedAt);
    }

    public function call(string $method, mixed ...$args): mixed
    {
        return $this->{$method}(...$args);
    }
}

class TikTokTestInspector implements MediaInspector
{
    public function __construct(private MediaMetadata $meta) {}

    public function supports(Media $media): bool
    {
        return true;
    }

    public function inspect(Media $media): MediaMetadata
    {
        return $this->meta;
    }
}

/** Pretends to convert PNGs: returns a local .jpg file. */
class TikTokTestTransformer implements MediaTransformer
{
    public function __construct(private string $converted) {}

    public function transform(Media $media, Platform $platform, Capabilities $caps): Media
    {
        return $media->extension() === 'png' ? Media::image($this->converted) : $media;
    }
}

class TikTokTestResolver implements CredentialsResolver
{
    public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
    {
        return $dispatched;
    }
}

const API = 'https://open.tiktokapis.com/v2';
const UPLOAD_URL = 'https://open-upload.tiktokapis.com/video/?upload_id=67890&upload_token=Xza123';
const VIDEO_URL = 'https://cdn.example.com/media/clip.mp4';
const PUBLISH_ID = 'v_pub_url~v2.7301234567890123456';

function ttDriver(?MediaGateway $gateway = null, ?MediaInspector $inspector = null): TikTokTestDriver
{
    return new TikTokTestDriver($inspector, $gateway ?? new LocalMediaGateway(config('social.temp_dir')));
}

/** @param  Media[]  $media */
function ttPost(array $media, ?string $caption = null, ?TikTokOptions $options = null, ?string $title = null, array $credentials = []): PreparedPost
{
    $credentials = $credentials === [] ? ['access_token' => 'tt-token', 'open_id' => 'open-123'] : $credentials;

    return new PreparedPost(
        Platform::TikTok,
        new SocialPost(media: $media, caption: $caption, title: $title, options: $options),
        Credentials::make($credentials),
    );
}

function ttDirect(TikTokPrivacy $privacy = TikTokPrivacy::PublicToEveryone, mixed ...$args): TikTokOptions
{
    return new TikTokOptions(...['target' => TikTokTarget::DirectPost, 'privacyLevel' => $privacy, ...$args]);
}

function ttFixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/tiktok/'.$name), true);
}

function ttOk(array $data = []): array
{
    return ['data' => $data, 'error' => ['code' => 'ok', 'message' => '', 'log_id' => '20221118093231010245136104']];
}

function ttError(string $code, string $message = 'TikTok error.'): array
{
    return ['data' => (object) [], 'error' => ['code' => $code, 'message' => $message, 'log_id' => '20221118093231010245136105']];
}

function ttStatus(string $status, array $extra = []): array
{
    return ttOk(['status' => $status] + $extra);
}

/** A 10-byte local video file. */
function ttLocalVideo(string $content = '0123456789', string $name = 'clip.mp4'): string
{
    $path = config('social.temp_dir').'/'.$name;
    file_put_contents($path, $content);

    return $path;
}

function ttCatch(callable $callback): SocialPosterException
{
    try {
        $callback();
    } catch (SocialPosterException $e) {
        return $e;
    }

    throw new \RuntimeException('Expected a SocialPosterException.');
}

function ttValidate(PreparedPost $post, ?TikTokDriver $driver = null): array
{
    return ($driver ?? ttDriver())->validate($post)->toArray();
}

/** The init-phase state publish() hands over, for a pulled video. */
function ttInitState(array $overrides = []): array
{
    return array_replace([
        'phase' => 'init',
        'direct' => false,
        'photo' => false,
        'started_at' => time(),
        'deadline' => time() + 3900,
        'endpoint' => '/post/publish/inbox/video/init/',
        'transfer' => 'PULL_FROM_URL',
        'auto' => true,
        'body' => ['source_info' => ['source' => 'PULL_FROM_URL', 'video_url' => VIDEO_URL]],
    ], $overrides);
}

function ttAwaitState(array $overrides = []): array
{
    return array_replace([
        'phase' => 'await_publish',
        'direct' => true,
        'photo' => false,
        'privacy' => 'PUBLIC_TO_EVERYONE',
        'username' => 'notion.scheduler',
        'publish_id' => PUBLISH_ID,
        'deadline' => time() + 3900,
        'polls' => 0,
        'transfer' => 'PULL_FROM_URL',
    ], $overrides);
}

function ttUploadState(string $path, array $overrides = [], array $upload = []): array
{
    return array_replace([
        'phase' => 'upload',
        'direct' => false,
        'photo' => false,
        'publish_id' => 'v_inbox_file~v2.7301234567890123457',
        'upload_url' => UPLOAD_URL,
        'deadline' => time() + 3900,
        'transfer' => 'FILE_UPLOAD',
        'upload' => array_replace(['path' => $path, 'owned' => false, 'size' => 10, 'chunk_size' => 4, 'total_chunks' => 2, 'mime' => 'video/mp4', 'offset' => 0], $upload),
    ], $overrides);
}

beforeEach(function () {
    Sleep::fake();
    config()->set('social.api.tiktok.chunk_retry_ms', 0);

    $this->tmp = sys_get_temp_dir().'/sp-tiktok-'.bin2hex(random_bytes(6));
    mkdir($this->tmp, 0777, true);
    config()->set('social.temp_dir', $this->tmp);
});

afterEach(function () {
    foreach (glob($this->tmp.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->tmp);
});

// --- Identity, scopes, rules -------------------------------------------------------

it('identifies the account by open_id and needs only the access token', function () {
    $driver = ttDriver();

    expect($driver->accountKey(Credentials::make(['access_token' => 'a', 'open_id' => 'open-1', 'expires_at' => 1])))->toBe('open-1')
        ->and($driver->accountKey(Credentials::make(['access_token' => 'a'])))->toBe('')
        ->and($driver->scopesFor(Feature::Publish))->toBe([['video.publish', 'video.upload']])
        ->and($driver->scopesFor(Feature::Video))->toBe([['video.publish', 'video.upload']])
        ->and($driver->scopesFor(Feature::Comment))->toBe([]);

    $e = ttCatch(fn () => $driver->publish(ttPost([Media::video(VIDEO_URL)], credentials: ['open_id' => 'open-1'])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Misconfigured)
        ->and($e->context['missing_credentials'])->toBe(['access_token']);

    $e = ttCatch(fn () => $driver->resume(ttPost([Media::video(VIDEO_URL)], credentials: ['open_id' => 'open-1']), ttAwaitState()));
    expect($e->reason)->toBe(FailureReason::Misconfigured);

    Http::assertNothingSent();
});

it('declares the documented limits, counting captions in UTF-16', function () {
    $caps = ttDriver()->capabilities();

    expect($caps->caption->max)->toBe(2200)
        ->and($caps->caption->counter->count('😀'))->toBe(2)
        ->and($caps->media->maxCount)->toBe(35)
        ->and($caps->media->image->extensions)->toBe(['jpg', 'jpeg', 'webp'])
        ->and($caps->media->image->convert)->toBe(['png' => 'jpg'])
        ->and($caps->media->image->maxWidth)->toBeNull()
        ->and($caps->media->video->minFrameRate)->toBe(23.0)
        ->and($caps->media->video->maxFrameRate)->toBe(60.0)
        ->and($caps->media->video->codecs)->toBe(['h264', 'hevc', 'vp8', 'vp9'])
        ->and($caps->supportsTitle)->toBeTrue()
        ->and($caps->supportsCarousel)->toBeTrue();

    config()->set('social.api.tiktok.video_title_limit', 150);
    config()->set('social.api.tiktok.photo_max_width', 1080);
    config()->set('social.api.tiktok.video_codecs', ['h264']);

    $caps = ttDriver()->capabilities();

    expect($caps->caption->max)->toBe(150)
        ->and($caps->media->image->maxWidth)->toBe(1080)
        ->and($caps->media->video->codecs)->toBe(['h264']);
});

// --- Validation ------------------------------------------------------------------------

it('limits a photo description to 4000 UTF-16 units and its title to 90', function () {
    $photo = Media::image('https://cdn.example.com/a.jpg');

    expect(ttValidate(ttPost([$photo], str_repeat('a', 4000), title: str_repeat('t', 90))))->toBe([]);

    $errors = ttValidate(ttPost([$photo], str_repeat('a', 3999).'😀', title: str_repeat('t', 89).'😀'));

    expect($errors)->toHaveKeys(['caption', 'title'])
        ->and($errors['caption'][0])->toContain('4000')
        ->and($errors['title'][0])->toContain('90');
});

it('limits a direct video caption to 2200 but not an inbox video caption, which is not sent', function () {
    $video = Media::video(VIDEO_URL);

    expect(ttValidate(ttPost([$video], str_repeat('a', 2200), ttDirect())))->toBe([])
        ->and(ttValidate(ttPost([$video], str_repeat('a', 2201), ttDirect())))->toHaveKey('caption')
        ->and(ttValidate(ttPost([$video], str_repeat('a', 5000))))->toBe([]);
});

it('requires a privacy level for direct posts', function () {
    $errors = ttValidate(ttPost([Media::video(VIDEO_URL)], 'hi', new TikTokOptions(target: TikTokTarget::DirectPost)));

    expect($errors)->toHaveKey('privacy_level');
});

it('requires pullable https photos and pull-only videos', function () {
    $local = $this->tmp.'/photo.jpg';
    file_put_contents($local, 'jpg');

    expect(ttValidate(ttPost([Media::image($local)])))->toHaveKey('media.0')
        ->and(ttValidate(ttPost([Media::image('http://cdn.example.com/a.jpg')]))['media.0'][0])->toContain('HTTPS')
        ->and(ttValidate(ttPost([Media::video('http://cdn.example.com/a.mp4')])))->toBe([])
        ->and(ttValidate(ttPost([Media::video('http://cdn.example.com/a.mp4')], options: new TikTokOptions(transfer: TikTokTransfer::PullFromUrl)))['media.0'][0])->toContain('FileUpload');

    $video = ttLocalVideo();
    expect(ttValidate(ttPost([Media::video($video)], options: new TikTokOptions(transfer: TikTokTransfer::PullFromUrl)))['media.0'][0])->toContain('PullFromUrl needs a public URL')
        ->and(ttValidate(ttPost([Media::video($video)])))->toBe([]);

    // Without a gateway the media itself must be remote.
    $bare = new TikTokTestDriver;
    expect($bare->validate(ttPost([Media::image($local)]))->toArray())->toHaveKey('media.0')
        ->and($bare->validate(ttPost([Media::image('https://cdn.example.com/a.jpg')]))->toArray())->toBe([]);
});

it('checks the photo cover index, carousel shape and draft-video extra', function () {
    $a = Media::image('https://cdn.example.com/a.jpg');
    $b = Media::image('https://cdn.example.com/b.webp');

    expect(ttValidate(ttPost([$a, $b], options: new TikTokOptions(photoCoverIndex: 1))))->toBe([])
        ->and(ttValidate(ttPost([$a, $b], options: new TikTokOptions(photoCoverIndex: 2))))->toHaveKey('photo_cover_index')
        ->and(ttValidate(ttPost([$a, Media::video(VIDEO_URL)])))->toHaveKey('media.1')
        ->and(ttValidate(ttPost(array_fill(0, 36, $a))))->toHaveKey('media')
        ->and(ttValidate(ttPost([Media::video(VIDEO_URL)], options: new TikTokOptions(extra: ['is_aigc' => true]))))->toHaveKey('extra')
        ->and(ttValidate(ttPost([Media::video(VIDEO_URL)], options: ttDirect(TikTokPrivacy::PublicToEveryone, extra: ['is_aigc' => true]))))->toBe([])
        ->and(ttValidate(ttPost([])))->toHaveKey('media');
});

it('merges the options validation', function () {
    $errors = ttValidate(ttPost(
        [Media::video(VIDEO_URL)],
        options: ttDirect(TikTokPrivacy::SelfOnly, coverTimestampMs: -1, brandContent: true),
    ));

    expect($errors)->toHaveKeys(['cover_timestamp_ms', 'brand_content']);
});

it('checks frame rate and codec against TikTok\'s video spec', function () {
    $inspector = new TikTokTestInspector(new MediaMetadata(durationSeconds: 20, frameRate: 20.0, codec: 'av1'));
    $errors = ttValidate(ttPost([Media::video(VIDEO_URL)]), ttDriver(inspector: $inspector));

    expect(implode(' ', $errors['media.0']))->toContain('frame rate')->toContain('codec av1');

    $inspector = new TikTokTestInspector(new MediaMetadata(durationSeconds: 20, frameRate: 29.97, codec: 'hevc'));
    expect(ttValidate(ttPost([Media::video(VIDEO_URL)]), ttDriver(inspector: $inspector)))->toBe([]);
});

it('rejects PNG photos without a transformer, with a hint', function () {
    $errors = ttValidate(ttPost([Media::image('https://cdn.example.com/a.png')]));

    expect($errors['media.0'][0])->toContain('Unsupported file type .png')->toContain('ImageConvertingTransformer');
});

it('accepts PNG photos a transformer converts only when the gateway can host the local JPG', function () {
    $jpg = $this->tmp.'/converted.jpg';
    file_put_contents($jpg, 'jpg');
    app()->instance(MediaTransformer::class, new TikTokTestTransformer($jpg));

    $png = Media::image('https://cdn.example.com/a.png');

    expect(ttValidate(ttPost([$png]))['media.0'][0])->toContain('FilesystemMediaGateway')
        ->and(ttValidate(ttPost([$png]), ttDriver(new FilesystemMediaGateway($this->tmp, 'public'))))->toBe([])
        ->and(ttValidate(ttPost([$png]), ttDriver(new FilesystemMediaGateway($this->tmp, 'public', policy: 'never')))['media.0'][0])->toContain('FilesystemMediaGateway');
});

it('checks the scope the target needs when the token lists its scopes', function () {
    $video = Media::video(VIDEO_URL);
    $creds = fn (array $scopes) => ['access_token' => 'a', 'open_id' => 'o', 'scopes' => $scopes];

    expect(ttValidate(ttPost([$video], options: ttDirect(), credentials: $creds(['video.upload'])))['credentials.scopes'][0])->toContain('video.publish')
        ->and(ttValidate(ttPost([$video], credentials: $creds(['video.publish'])))['credentials.scopes'][0])->toContain('video.upload')
        ->and(ttValidate(ttPost([$video], credentials: $creds(['user.info.basic']))))->toHaveKey('credentials.scopes')
        ->and(ttValidate(ttPost([$video], options: ttDirect(), credentials: $creds(['video.publish', 'user.info.basic']))))->toBe([])
        ->and(ttValidate(ttPost([$video], credentials: $creds(['video.upload']))))->toBe([]);
});

// --- Publish: drafts ------------------------------------------------------------------

it('uploads a pulled video to the inbox as a draft through a commit step', function () {
    Http::fake([
        API.'/post/publish/inbox/video/init/' => Http::response(ttFixture('init_pull.json')),
        API.'/post/publish/status/fetch/' => Http::sequence()
            ->push(ttStatus('PROCESSING_DOWNLOAD', ['downloaded_bytes' => 100]))
            ->push(ttStatus('SEND_TO_USER_INBOX')),
    ]);

    $driver = ttDriver();
    $post = ttPost([Media::video(VIDEO_URL, 'https://cdn.example.com/thumb.jpg')], 'A caption the inbox never sees');

    $outcome = $driver->publish($post);

    // The create runs in its own (commit) step, after nothing was sent to TikTok.
    Http::assertNothingSent();
    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->commits)->toBeTrue()
        ->and($outcome->committed)->toBeFalse()
        ->and($outcome->recheckAfter)->toBe(0)
        ->and($outcome->deadline)->toBe($outcome->state['deadline'])
        ->and($outcome->state['phase'])->toBe('init')
        ->and($outcome->state['body'])->toBe(['source_info' => ['source' => 'PULL_FROM_URL', 'video_url' => VIDEO_URL]]);

    $outcome = $driver->resume($post, $outcome->state + [Pending::ATTEMPT => 1]);

    Http::assertSent(fn (Request $r) => $r->url() === API.'/post/publish/inbox/video/init/'
        && $r->hasHeader('Authorization', 'Bearer tt-token')
        && $r->data() === ['source_info' => ['source' => 'PULL_FROM_URL', 'video_url' => VIDEO_URL]]);

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->commits)->toBeFalse()
        ->and($outcome->committed)->toBeTrue()
        ->and($outcome->recheckAfter)->toBe(15)
        ->and($outcome->state['phase'])->toBe('await_publish')
        ->and($outcome->state['publish_id'])->toBe(PUBLISH_ID)
        ->and($outcome->state)->not->toHaveKeys(['body', '_attempt'])
        ->and($outcome->deadline)->toBeGreaterThanOrEqual(time() + 3890);

    $outcome = $driver->resume($post, $outcome->state);

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->state['polls'])->toBe(1)
        ->and($outcome->state['progress'])->toBe(['status' => 'PROCESSING_DOWNLOAD', 'downloaded_bytes' => 100]);

    $outcome = $driver->resume($post, $outcome->state);

    expect($outcome)->toBeInstanceOf(Published::class);

    $result = $outcome->result;

    expect($result->platformPostId)->toBe(PUBLISH_ID)
        ->and($result->url)->toBeNull()
        ->and($result->state)->toBe(PostResult::STATE_DRAFT)
        ->and($result->isDraft())->toBeTrue()
        ->and($result->idKind)->toBe('publish_id')
        ->and($result->payload)->toMatchArray([
            'status' => 'SEND_TO_USER_INBOX',
            'publish_id' => PUBLISH_ID,
            'post_id' => null,
            'transfer' => 'PULL_FROM_URL',
            'caption_ignored' => true,
        ])
        // A draft takes no cover timestamp: the advice points to the app.
        ->and($result->payload['thumbnail_warning'])->toContain('in the TikTok app')
        ->and($result->payload['thumbnail_warning'])->toContain('direct posts only');
});

it('sends a draft photo carousel as MEDIA_UPLOAD with description and title, never the brand toggles', function () {
    Http::fake([API.'/post/publish/content/init/' => Http::response(ttFixture('init_pull.json'))]);

    $driver = ttDriver();
    $post = ttPost(
        [Media::image('https://cdn.example.com/a.jpg'), Media::image('https://cdn.example.com/b.webp')],
        'A long description',
        new TikTokOptions(photoCoverIndex: 1, extra: ['brand_content_toggle' => true, 'brand_organic_toggle' => true, 'custom' => 'x']),
        'Short title',
    );

    $outcome = $driver->publish($post);
    $driver->resume($post, $outcome->state);

    Http::assertSent(fn (Request $r) => $r->url() === API.'/post/publish/content/init/' && $r->data() === [
        'post_info' => ['custom' => 'x', 'description' => 'A long description', 'title' => 'Short title'],
        'source_info' => [
            'source' => 'PULL_FROM_URL',
            'photo_cover_index' => 1,
            'photo_images' => ['https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.webp'],
        ],
        'post_mode' => 'MEDIA_UPLOAD',
        'media_type' => 'PHOTO',
    ]);
});

it('sends an empty draft post_info as a JSON object', function () {
    Http::fake([API.'/post/publish/content/init/' => Http::response(ttFixture('init_pull.json'))]);

    $driver = ttDriver();
    $post = ttPost([Media::image('https://cdn.example.com/a.jpg')], '   ');

    $driver->resume($post, $driver->publish($post)->state);

    Http::assertSent(fn (Request $r) => str_contains($r->body(), '"post_info":{}'));
});

// --- Publish: direct ------------------------------------------------------------------

it('direct-posts a public video, waits for the public id and builds the permalink', function () {
    Http::fake([
        API.'/post/publish/creator_info/query/' => Http::response(ttFixture('creator_info.json')),
        API.'/post/publish/video/init/' => Http::response(ttFixture('init_pull.json')),
        API.'/post/publish/status/fetch/' => Http::sequence()
            ->push(ttStatus('PROCESSING_DOWNLOAD'))
            ->push(ttStatus('PUBLISH_COMPLETE', ['publicaly_available_post_id' => []]))
            ->push(ttStatus('PUBLISH_COMPLETE', ['publicaly_available_post_id' => []]))
            ->push(ttFixture('status_complete.json')),
    ]);

    $driver = ttDriver(inspector: new TikTokTestInspector(new MediaMetadata(durationSeconds: 120)));
    $post = ttPost([Media::video(VIDEO_URL)], 'Hello 👋', ttDirect(coverTimestampMs: 1500, disableComment: true, isAigc: true));

    $outcome = $driver->publish($post);

    Http::assertSent(fn (Request $r) => $r->url() === API.'/post/publish/creator_info/query/');
    expect($outcome->state)->toMatchArray(['privacy' => 'PUBLIC_TO_EVERYONE', 'username' => 'notion.scheduler', 'direct' => true])
        ->and($outcome->state['body']['post_info'])->toBe([
            'brand_content_toggle' => false,
            'brand_organic_toggle' => false,
            'is_aigc' => true,
            'privacy_level' => 'PUBLIC_TO_EVERYONE',
            'title' => 'Hello 👋',
            'disable_comment' => true,
            'disable_stitch' => true, // forced off by the creator
            'video_cover_timestamp_ms' => 1500,
        ]);

    $outcome = $driver->resume($post, $outcome->state);
    expect($outcome->state['phase'])->toBe('await_publish');

    $outcome = $driver->resume($post, $outcome->state);
    expect($outcome->state['phase'])->toBe('await_publish');

    $outcome = $driver->resume($post, $outcome->state);
    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->state['phase'])->toBe('await_public_id')
        ->and($outcome->committed)->toBeTrue()
        ->and($outcome->recheckAfter)->toBe(30)
        ->and($outcome->deadline)->toBe($outcome->state['complete_at'] + 600 + 60);

    $outcome = $driver->resume($post, $outcome->state);
    expect($outcome->state['phase'])->toBe('await_public_id');

    $outcome = $driver->resume($post, $outcome->state);

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->platformPostId)->toBe('7301234567890123456')
        ->and($outcome->result->idKind)->toBe('video_id')
        ->and($outcome->result->url)->toBe('https://www.tiktok.com/@notion.scheduler/video/7301234567890123456')
        ->and($outcome->result->isPublished())->toBeTrue()
        ->and($outcome->result->payload)->toMatchArray([
            'status' => 'PUBLISH_COMPLETE',
            'publish_id' => PUBLISH_ID,
            'post_id' => '7301234567890123456',
            'privacy' => 'PUBLIC_TO_EVERYONE',
            'creator_username' => 'notion.scheduler',
        ]);
});

it('returns the publish_id as processing when the public id does not come in time', function () {
    $driver = ttDriver();
    $post = ttPost([Media::video(VIDEO_URL)], options: ttDirect());

    $outcome = $driver->resume($post, ttAwaitState(['phase' => 'await_public_id', 'complete_at' => time() - 601]));

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->platformPostId)->toBe(PUBLISH_ID)
        ->and($outcome->result->idKind)->toBe('publish_id')
        ->and($outcome->result->isProcessing())->toBeTrue()
        ->and($outcome->result->payload['public_id_pending'])->toBeTrue();

    Http::assertNothingSent();
});

it('keeps waiting for the public id through transient errors and gives up gracefully on permanent ones', function () {
    $driver = ttDriver();
    $post = ttPost([Media::video(VIDEO_URL)], options: ttDirect());
    $state = ttAwaitState(['phase' => 'await_public_id', 'complete_at' => time()]);

    Http::fake([API.'/post/publish/status/fetch/' => Http::sequence()
        ->push(ttError('internal_error', 'Something is wrong.'), 500)
        ->push(ttError('rate_limit_exceeded'), 429)
        ->push(ttStatus('PUBLISH_COMPLETE'))
        ->push(ttError('access_token_invalid', 'The access token is invalid.'), 401),
    ]);

    expect($driver->resume($post, $state))->toBeInstanceOf(Pending::class)
        ->and($driver->resume($post, $state)->recheckAfter)->toBe(60);

    $next = $driver->resume($post, $state);
    expect($next)->toBeInstanceOf(Pending::class)->and($next->state['polls'])->toBe(1);

    $outcome = $driver->resume($post, $state);
    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->payload)->toMatchArray(['public_id_pending' => true, 'public_id_error' => 'access_token_invalid']);
});

it('does not wait for the public id when public_id_wait is 0', function () {
    config()->set('social.api.tiktok.public_id_wait', 0);
    Http::fake([API.'/post/publish/status/fetch/' => Http::response(ttStatus('PUBLISH_COMPLETE'))]);

    $outcome = ttDriver()->resume(ttPost([Media::video(VIDEO_URL)]), ttAwaitState());

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->idKind)->toBe('publish_id')
        ->and($outcome->result->isPublished())->toBeTrue();
});

it('reports a failure TikTok raises while the public id is awaited', function () {
    Http::fake([API.'/post/publish/status/fetch/' => Http::response(ttStatus('FAILED', ['fail_reason' => 'spam_risk_text']))]);

    $e = ttCatch(fn () => ttDriver()->resume(ttPost([Media::video(VIDEO_URL)]), ttAwaitState(['phase' => 'await_public_id', 'complete_at' => time()])));

    expect($e->reason)->toBe(FailureReason::InvalidContent);
});

it('never re-publishes a live post when TikTok reports FAILED while its public id is awaited', function (string $reason) {
    Http::fake([API.'/post/publish/status/fetch/' => Http::response(ttStatus('FAILED', ['fail_reason' => $reason]))]);

    // await_public_id starts only after PUBLISH_COMPLETE: the post went live.
    $e = ttCatch(fn () => ttDriver()->resume(ttPost([Media::video(VIDEO_URL)]), ttAwaitState(['phase' => 'await_public_id', 'complete_at' => time()])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->context)->not->toHaveKey('restart')
        ->and($e->possiblyPublished())->toBeTrue()
        ->and($e->context)->toMatchArray(['fail_reason' => $reason, 'publish_id' => PUBLISH_ID]);
})->with(['internal', 'video_pull_failed', 'photo_pull_failed', 'spam_risk_text']);

it('keeps waiting for the public id after a connection failure', function () {
    Http::fake(fn (Request $request) => throw new ConnectException('cURL error 28: Operation timed out', new PsrRequest('POST', $request->url())));

    $outcome = ttDriver()->resume(ttPost([Media::video(VIDEO_URL)]), ttAwaitState(['phase' => 'await_public_id', 'complete_at' => time()]));

    expect($outcome)->toBeInstanceOf(Pending::class)->and($outcome->recheckAfter)->toBe(30);
});

it('reports a private or followers-only direct post as live with its publish_id', function (string $privacy, string $state) {
    Http::fake([API.'/post/publish/status/fetch/' => Http::response(ttStatus('PUBLISH_COMPLETE', ['publicaly_available_post_id' => []]))]);

    $outcome = ttDriver()->resume(ttPost([Media::video(VIDEO_URL)]), ttAwaitState(['privacy' => $privacy]));

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->state)->toBe($state)
        ->and($outcome->result->platformPostId)->toBe(PUBLISH_ID)
        ->and($outcome->result->idKind)->toBe('publish_id');
})->with([
    'self only' => ['SELF_ONLY', PostResult::STATE_PRIVATE],
    'followers' => ['FOLLOWER_OF_CREATOR', PostResult::STATE_PUBLISHED],
]);

it('direct-posts a photo with the caption as description and ->title() as title', function () {
    Http::fake([
        API.'/post/publish/creator_info/query/' => Http::response(ttFixture('creator_info.json')),
        API.'/post/publish/content/init/' => Http::response(ttFixture('init_pull.json')),
    ]);

    $driver = ttDriver();
    $post = ttPost(
        [Media::image('https://cdn.example.com/a.jpg')],
        str_repeat('d', 300),
        ttDirect(TikTokPrivacy::MutualFollowFriends, autoAddMusic: true, disableDuet: true, brandOrganic: true),
        'Photo title',
    );

    $driver->resume($post, $driver->publish($post)->state);

    Http::assertSent(fn (Request $r) => $r->url() === API.'/post/publish/content/init/' && $r->data() === [
        'post_info' => [
            'brand_content_toggle' => false,
            'brand_organic_toggle' => true,
            'privacy_level' => 'MUTUAL_FOLLOW_FRIENDS',
            'description' => str_repeat('d', 300),
            'title' => 'Photo title',
            'auto_add_music' => true,
        ],
        'source_info' => ['source' => 'PULL_FROM_URL', 'photo_cover_index' => 0, 'photo_images' => ['https://cdn.example.com/a.jpg']],
        'post_mode' => 'DIRECT_POST',
        'media_type' => 'PHOTO',
    ]);
});

it('labels an AI-generated photo post with a top-level is_aigc, direct or draft', function () {
    Http::fake([
        API.'/post/publish/creator_info/query/' => Http::response(ttFixture('creator_info.json')),
        API.'/post/publish/content/init/' => Http::response(ttFixture('init_pull.json')),
    ]);

    $driver = ttDriver();
    $photo = Media::image('https://cdn.example.com/a.jpg');
    $post = ttPost([$photo], 'ai art', ttDirect(isAigc: true));

    // content/init documents is_aigc next to media_type and post_mode, not in post_info.
    $direct = $driver->publish($post)->state;
    $draft = $driver->publish(ttPost([$photo], 'ai art', new TikTokOptions(isAigc: true)))->state['body'];
    $unset = $driver->publish(ttPost([$photo], 'ai art', ttDirect()))->state['body'];

    expect($direct['body']['is_aigc'])->toBeTrue()
        ->and($direct['body']['post_info'])->not->toHaveKey('is_aigc')
        ->and($draft['is_aigc'])->toBeTrue()
        ->and($draft['post_info'])->not->toHaveKey('is_aigc')
        ->and($unset)->not->toHaveKey('is_aigc');

    $driver->resume($post, $direct);

    Http::assertSent(fn (Request $r) => $r->url() === API.'/post/publish/content/init/' && ($r->data()['is_aigc'] ?? null) === true);
});

it('still loads a v1.0 subclass that overrides directPostInfo(), and honours the override', function () {
    $fixture = __DIR__.'/../../Fixtures/tiktok/LegacyDirectPostInfoDriver.php';
    $vendor = collect(ClassLoader::getRegisteredLoaders())
        ->filter(fn (ClassLoader $loader) => isset($loader->getPrefixesPsr4()['SocialPoster\\']))
        ->keys()
        ->first();
    $autoload = $vendor.'/autoload.php';

    // In its own process: an incompatible override is a fatal error at load time.
    $process = new Process([PHP_BINARY, '-r', 'require $argv[1]; require $argv[2]; echo "loaded";', $autoload, $fixture]);
    $process->run();

    expect(trim($process->getOutput()))->toBe('loaded', $process->getErrorOutput().$process->getOutput());

    require_once $fixture;

    Http::fake([API.'/post/publish/creator_info/query/' => Http::response(ttFixture('creator_info.json'))]);

    $driver = new LegacyDirectPostInfoDriver(null, new LocalMediaGateway(config('social.temp_dir')));
    $info = $driver->publish(ttPost([Media::video(VIDEO_URL)], 'Caption', ttDirect()))->state['body']['post_info'];

    expect($info)->toMatchArray(['legacy_marker' => true, 'privacy_level' => 'PUBLIC_TO_EVERYONE', 'title' => 'Caption']);
});

it('layers brand defaults under extra() and the typed disclosure options', function () {
    Http::fake([API.'/post/publish/creator_info/query/' => Http::response(ttFixture('creator_info.json'))]);
    $video = Media::video(VIDEO_URL);

    $info = ttDriver()->publish(ttPost([$video], 'The caption', ttDirect(extra: ['brand_organic_toggle' => true, 'title' => 'ignored', 'privacy_level' => 'SELF_ONLY'])))->state['body']['post_info'];
    expect($info)->toMatchArray(['brand_content_toggle' => false, 'brand_organic_toggle' => true, 'title' => 'The caption', 'privacy_level' => 'PUBLIC_TO_EVERYONE']);

    $info = ttDriver()->publish(ttPost([$video], options: ttDirect(brandContent: true, extra: ['brand_content_toggle' => false])))->state['body']['post_info'];
    expect($info['brand_content_toggle'])->toBeTrue();

    config()->set('social.api.tiktok.send_brand_defaults', false);

    $info = ttDriver()->publish(ttPost([$video], options: ttDirect()))->state['body']['post_info'];
    expect($info)->not->toHaveKeys(['brand_content_toggle', 'brand_organic_toggle', 'is_aigc']);
});

it('falls back to an allowed privacy level only when asked to', function () {
    Http::fake([API.'/post/publish/creator_info/query/' => Http::response(ttOk([
        'creator_username' => 'notion.scheduler',
        'privacy_level_options' => ['FOLLOWER_OF_CREATOR', 'SELF_ONLY'],
    ]))]);
    $video = Media::video(VIDEO_URL);

    $e = ttCatch(fn () => ttDriver()->publish(ttPost([$video], options: ttDirect())));
    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::InsufficientPermissions)
        ->and($e->getMessage())->toContain('FOLLOWER_OF_CREATOR, SELF_ONLY');

    $state = ttDriver()->publish(ttPost([$video], options: ttDirect(privacyFallbacks: [TikTokPrivacy::MutualFollowFriends, TikTokPrivacy::SelfOnly])))->state;
    expect($state)->toMatchArray(['privacy' => 'SELF_ONLY', 'privacy_fallback_used' => 'SELF_ONLY', 'privacy_requested' => 'PUBLIC_TO_EVERYONE'])
        ->and($state['body']['post_info']['privacy_level'])->toBe('SELF_ONLY');

    // Branded content can never fall back to SELF_ONLY.
    $e = ttCatch(fn () => ttDriver()->publish(ttPost([$video], options: ttDirect(brandContent: true, privacyFallbacks: [TikTokPrivacy::SelfOnly]))));
    expect($e->reason)->toBe(FailureReason::InsufficientPermissions);

    $e = ttCatch(fn () => ttDriver()->publish(ttPost([$video], options: ttDirect(TikTokPrivacy::SelfOnly, brandContent: true))));
    expect($e->reason)->toBe(FailureReason::InvalidContent);

    $e = ttCatch(fn () => ttDriver()->publish(ttPost([$video], options: new TikTokOptions(target: TikTokTarget::DirectPost))));
    expect($e->reason)->toBe(FailureReason::Validation);
});

it('uses the chosen privacy when creator_info lists no options', function () {
    Http::fake([API.'/post/publish/creator_info/query/' => Http::response(ttOk(['creator_username' => '']))]);

    $state = ttDriver()->publish(ttPost([Media::video(VIDEO_URL)], options: ttDirect(TikTokPrivacy::FollowerOfCreator)))->state;

    expect($state['privacy'])->toBe('FOLLOWER_OF_CREATOR')
        ->and($state)->not->toHaveKeys(['username', 'privacy_fallback_used']);
});

it('refuses a video longer than the creator may post, before the init', function () {
    Http::fake([API.'/post/publish/creator_info/query/' => Http::response(ttFixture('creator_info.json'))]);

    $driver = ttDriver(inspector: new TikTokTestInspector(new MediaMetadata(durationSeconds: 301)));
    $e = ttCatch(fn () => $driver->publish(ttPost([Media::video(VIDEO_URL)], options: ttDirect())));

    expect($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain('300s')
        ->and($e->context['max_video_post_duration_sec'])->toBe(300);

    Http::assertSentCount(1);
});

it('stops at creator_info errors TikTok returns with HTTP 200', function () {
    Http::fake([API.'/post/publish/creator_info/query/' => Http::response(ttError('spam_risk_too_many_posts', 'The user has reached the daily post cap.'))]);

    $e = ttCatch(fn () => ttDriver()->publish(ttPost([Media::video(VIDEO_URL)], options: ttDirect())));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::RateLimited)
        ->and($e->context['retry_after_hint'])->toBe(86400)
        ->and($e->context['retry_at'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/')
        ->and($e->context['code'])->toBe('spam_risk_too_many_posts')
        ->and($e->context['trace_id'])->toBe('20221118093231010245136105');
});

it('converts PNG photos through the transformer and pulls the converted URL', function () {
    $jpg = $this->tmp.'/converted.jpg';
    file_put_contents($jpg, 'jpg-bytes');
    app()->instance(MediaTransformer::class, new TikTokTestTransformer($jpg));

    $gateway = new class extends LocalMediaGateway
    {
        public function canProvideUrl(Media $media): bool
        {
            return true;
        }

        public function url(Media $media): string
        {
            return $media->isRemote() ? $media->source : 'https://media.example.com/'.basename($media->source);
        }
    };

    $outcome = ttDriver($gateway)->publish(ttPost([Media::image('https://cdn.example.com/a.png'), Media::image('https://cdn.example.com/b.jpg')]));

    expect($outcome->state['body']['source_info']['photo_images'])->toBe(['https://media.example.com/converted.jpg', 'https://cdn.example.com/b.jpg']);
});

it('refuses to hand TikTok a non-https photo URL at publish time', function () {
    $gateway = new class extends LocalMediaGateway
    {
        public function canProvideUrl(Media $media): bool
        {
            return true;
        }

        public function url(Media $media): string
        {
            return 'http://insecure.example.com/a.jpg';
        }
    };

    $e = ttCatch(fn () => ttDriver($gateway)->publish(ttPost([Media::image('https://cdn.example.com/a.jpg')])));

    expect($e->reason)->toBe(FailureReason::MediaRejected)->and($e->getMessage())->toContain('HTTPS');

    $e = ttCatch(fn () => ttDriver($gateway)->publish(ttPost([Media::video('https://cdn.example.com/a.mp4')], options: new TikTokOptions(transfer: TikTokTransfer::PullFromUrl))));
    expect($e->reason)->toBe(FailureReason::MediaRejected);
});

it('refuses a post without media at publish time', function () {
    $e = ttCatch(fn () => ttDriver()->publish(ttPost([])));

    expect($e->reason)->toBe(FailureReason::Validation);
});

// --- Init (the commit step) -----------------------------------------------------------

it('never repeats an interrupted pull init: the outcome is unknown', function () {
    Http::fake();

    $e = ttCatch(fn () => ttDriver()->resume(ttPost([Media::video(VIDEO_URL)]), ttInitState([Pending::RECOVERING => true])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->possiblyPublished())->toBeTrue()
        ->and($e->context['target'])->toBe('drafts');

    Http::assertNothingSent();
});

it('repeats an interrupted FILE_UPLOAD init, which cannot have posted anything', function () {
    Http::fake([API.'/post/publish/inbox/video/init/' => Http::response(ttFixture('init_file_upload.json'))]);
    $path = ttLocalVideo();

    $state = ttInitState([
        Pending::RECOVERING => true,
        'transfer' => 'FILE_UPLOAD',
        'body' => ['source_info' => ['source' => 'FILE_UPLOAD', 'video_size' => 10, 'chunk_size' => 10, 'total_chunk_count' => 1]],
        'upload' => ['path' => $path, 'owned' => false, 'size' => 10, 'chunk_size' => 10, 'total_chunks' => 1, 'mime' => 'video/mp4', 'offset' => 0],
    ]);

    $outcome = ttDriver()->resume(ttPost([Media::video($path)]), $state);

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->state['phase'])->toBe('upload')
        ->and($outcome->state['upload_url'])->toBe(UPLOAD_URL)
        ->and($outcome->state)->not->toHaveKey(Pending::RECOVERING)
        ->and($outcome->committed)->toBeTrue()
        ->and($outcome->recheckAfter)->toBe(0);
});

it('waits as a fresh commit step when TikTok refuses the init with a code, up to init_retries', function () {
    Http::fake([API.'/post/publish/inbox/video/init/' => Http::response(ttError('rate_limit_exceeded', 'Rate limit exceeded.'), 429)]);
    config()->set('social.api.tiktok.init_retries', 2);

    $driver = ttDriver();
    $post = ttPost([Media::video(VIDEO_URL)]);

    $first = $driver->resume($post, ttInitState());
    expect($first)->toBeInstanceOf(Pending::class)
        ->and($first->commits)->toBeTrue()
        ->and($first->recheckAfter)->toBe(60)
        ->and($first->state['init_attempts'])->toBe(1)
        ->and($first->state['phase'])->toBe('init');

    $second = $driver->resume($post, $first->state);
    expect($second->state['init_attempts'])->toBe(2);

    $e = ttCatch(fn () => $driver->resume($post, $second->state));
    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::RateLimited)
        ->and($e->getMessage())->toContain('giving up');
});

it('clamps a long init wait to what the queue can delay', function () {
    Http::fake([API.'/post/publish/inbox/video/init/' => Http::response(ttError('spam_risk_too_many_pending_share', 'Too many pending shares.'), 403)]);

    $outcome = ttDriver()->resume(ttPost([Media::video(VIDEO_URL)]), ttInitState());

    expect($outcome)->toBeInstanceOf(Pending::class)->and($outcome->recheckAfter)->toBe(900);
});

it('throws a permanent init refusal and leaves an ambiguous one to the job', function () {
    Http::fake([API.'/post/publish/inbox/video/init/' => Http::sequence()
        ->push(ttError('spam_risk_user_banned_from_posting', 'The user is banned from making new posts.'), 403)
        ->push('<html>Bad gateway</html>', 502),
    ]);

    $post = ttPost([Media::video(VIDEO_URL)]);

    $e = ttCatch(fn () => ttDriver()->resume($post, ttInitState()));
    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::AccountRestricted)
        ->and($e->context['sent']['source_info']['video_url'])->toBe(VIDEO_URL);

    $e = ttCatch(fn () => ttDriver()->resume($post, ttInitState()));
    expect($e)->toBeInstanceOf(TemporaryException::class)->and($e->reason)->toBe(FailureReason::ServerError);
});

it('redacts query strings of the media URLs it keeps in the error context', function () {
    Http::fake([API.'/post/publish/inbox/video/init/' => Http::response(ttError('invalid_params', 'The request post info is empty or incorrect.'), 400)]);
    $signed = 'https://bucket.example.com/clip.mp4?X-Amz-Signature=abc&X-Amz-Expires=600';

    $e = ttCatch(fn () => ttDriver()->resume(ttPost([Media::video($signed)]), ttInitState(['body' => ['source_info' => ['source' => 'PULL_FROM_URL', 'video_url' => $signed]]])));

    expect($e->reason)->toBe(FailureReason::InvalidContent)
        ->and($e->getMessage())->toBe('The request post info is empty or incorrect.')
        ->and($e->context['sent']['source_info']['video_url'])->toBe('https://bucket.example.com/clip.mp4?[redacted]');
});

it('retries a rejected init token once when a credentials resolver is bound', function () {
    app()->instance(CredentialsResolver::class, new TikTokTestResolver);
    Http::fake([API.'/post/publish/inbox/video/init/' => Http::response(ttError('access_token_invalid', 'The access token is invalid or not found in the request.'), 401)]);

    $driver = ttDriver();
    $post = ttPost([Media::video(VIDEO_URL)]);

    $retry = $driver->resume($post, ttInitState());
    expect($retry)->toBeInstanceOf(Pending::class)
        ->and($retry->commits)->toBeTrue()
        ->and($retry->state['init_reresolved'])->toBeTrue();

    $e = ttCatch(fn () => $driver->resume($post, $retry->state));
    expect($e)->toBeInstanceOf(PermanentException::class)->and($e->reason)->toBe(FailureReason::InvalidToken);
});

it('treats a pull init without a publish_id as a possibly live post', function () {
    Http::fake([API.'/post/publish/inbox/video/init/' => Http::response(ttOk([]))]);

    $e = ttCatch(fn () => ttDriver()->resume(ttPost([Media::video(VIDEO_URL)]), ttInitState()));

    expect($e->reason)->toBe(FailureReason::OutcomeUnknown)->and($e->possiblyPublished())->toBeTrue();
});

it('restarts a FILE_UPLOAD init that returned no upload_url, at most max_restarts times', function () {
    Http::fake([API.'/post/publish/inbox/video/init/' => Http::response(ttOk(['publish_id' => 'v_inbox_file~1']))]);
    $path = ttLocalVideo();
    $state = ttInitState(['transfer' => 'FILE_UPLOAD', 'body' => ['source_info' => ['source' => 'FILE_UPLOAD']]]);

    $e = ttCatch(fn () => ttDriver()->resume(ttPost([Media::video($path)]), $state));
    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->context['restart'])->toBeTrue()
        ->and($e->context['publish_id'])->toBe('v_inbox_file~1');

    $e = ttCatch(fn () => ttDriver()->resume(ttPost([Media::video($path)]), $state + [Pending::RESTARTS => 2]));
    expect($e)->toBeInstanceOf(PermanentException::class)->and($e->context['restarts'])->toBe(2);
});

it('deletes its kept copy of the video when the init ends for good, and keeps it while the init waits', function () {
    Http::fake([
        VIDEO_URL => fn () => Http::response('abcdefghij', 200),
        API.'/post/publish/inbox/video/init/' => Http::sequence()
            ->push(ttError('invalid_param', 'The request post info is empty or incorrect.'), 400)
            ->push(ttOk(['publish_id' => 'v_inbox_file~1'])) // no upload_url: a fresh publish
            ->push(ttError('rate_limit_exceeded', 'Rate limit exceeded.'), 429)
            ->push(ttError('spam_risk_too_many_posts', 'The daily post cap is reached.'), 403),
    ]);

    $driver = ttDriver();
    $post = ttPost([Media::video(VIDEO_URL)], options: new TikTokOptions(transfer: TikTokTransfer::FileUpload));
    $kept = fn () => glob($this->tmp.'/sp_*') ?: [];

    // A permanent refusal: no later run reads the copy.
    $state = $driver->publish($post)->state;
    expect($state['upload']['owned'])->toBeTrue()->and($kept())->toHaveCount(1);

    $e = ttCatch(fn () => $driver->resume($post, $state));
    expect($e)->toBeInstanceOf(PermanentException::class)->and($kept())->toBe([]);

    // A restart downloads the video again.
    $e = ttCatch(fn () => $driver->resume($post, $driver->publish($post)->state));
    expect($e->context['restart'])->toBeTrue()->and($kept())->toBe([]);

    // A refusal the init waits out keeps the copy for the next try; a permanent
    // refusal on that try deletes it.
    $outcome = $driver->resume($post, $driver->publish($post)->state);
    expect($outcome)->toBeInstanceOf(Pending::class)->and($kept())->toHaveCount(1);

    $e = ttCatch(fn () => $driver->resume($post, $outcome->state));
    expect($e->reason)->toBe(FailureReason::RateLimited)->and($kept())->toBe([]);
});

it('refuses an incomplete init state', function () {
    $e = ttCatch(fn () => ttDriver()->resume(ttPost([Media::video(VIDEO_URL)]), ['phase' => 'init']));

    expect($e)->toBeInstanceOf(PermanentException::class)->and($e->getMessage())->toContain('incomplete');
});

it('falls back to FILE_UPLOAD once when TikTok refuses to pull from an unverified host', function () {
    Http::fake([
        API.'/post/publish/inbox/video/init/' => Http::sequence()
            ->push(ttError('url_ownership_unverified', 'Please review our URL ownership verification rules.'), 403)
            ->push(ttFixture('init_file_upload.json')),
        VIDEO_URL => Http::response('0123456789', 200, ['Content-Type' => 'video/mp4']),
        'https://open-upload.tiktokapis.com/*' => Http::sequence()->push('', 206)->push('', 201),
    ]);

    $driver = ttDriver();
    $post = ttPost([Media::video(VIDEO_URL)]);

    $outcome = $driver->resume($post, ttInitState());

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->commits)->toBeFalse()
        ->and($outcome->committed)->toBeFalse()
        ->and($outcome->state['phase'])->toBe('prepare_upload')
        ->and($outcome->state['fallback'])->toBe('url_ownership_unverified');

    $outcome = $driver->resume($post, $outcome->state);

    // The video was downloaded and moved under the driver's own name.
    $path = $outcome->state['upload']['path'];
    expect($outcome->commits)->toBeTrue()
        ->and($outcome->state['transfer'])->toBe('FILE_UPLOAD')
        ->and($outcome->state['body']['source_info'])->toBe(['source' => 'FILE_UPLOAD', 'video_size' => 10, 'chunk_size' => 4, 'total_chunk_count' => 2])
        ->and($outcome->state['upload']['owned'])->toBeTrue()
        ->and(basename($path))->toStartWith('sp_tiktok_')
        ->and(file_get_contents($path))->toBe('0123456789');

    $outcome = $driver->resume($post, $outcome->state);
    expect($outcome->state['phase'])->toBe('upload');

    // The first chunk, then the hand-over to a committed run for the last one.
    $outcome = $driver->resume($post, $outcome->state);
    expect($outcome->state['upload']['offset'])->toBe(4)->and(file_exists($path))->toBeTrue();

    $outcome = $driver->resume($post, $outcome->state);

    expect($outcome->state['phase'])->toBe('await_publish')
        ->and(file_exists($path))->toBeFalse(); // the owned copy is removed once sent
});

it('does not fall back from an unverified host twice, nor without Auto, nor for photos', function () {
    Http::fake([API.'/post/publish/*' => Http::response(ttError('url_ownership_unverified', 'Please review our URL ownership verification rules.'), 403)]);

    $driver = ttDriver();
    $post = ttPost([Media::video(VIDEO_URL)]);

    $e = ttCatch(fn () => $driver->resume($post, ttInitState(['fallback' => 'url_ownership_unverified'])));
    expect($e->reason)->toBe(FailureReason::MediaRejected);

    $e = ttCatch(fn () => $driver->resume($post, ttInitState(['auto' => false])));
    expect($e->reason)->toBe(FailureReason::MediaRejected)->and($e->getMessage())->toContain('verified');

    $e = ttCatch(fn () => $driver->resume(ttPost([Media::image('https://cdn.example.com/a.jpg')]), ttInitState(['photo' => true, 'endpoint' => '/post/publish/content/init/'])));
    expect($e->reason)->toBe(FailureReason::MediaRejected);
});

it('needs the video for the upload fallback', function () {
    $e = ttCatch(fn () => ttDriver()->resume(ttPost([Media::image('https://cdn.example.com/a.jpg')]), ttInitState(['phase' => 'prepare_upload'])));

    expect($e)->toBeInstanceOf(PermanentException::class);
});

// --- FILE_UPLOAD ---------------------------------------------------------------------

it('uploads a local video in chunks in its own phase, without a bearer token', function () {
    Http::fake([
        API.'/post/publish/inbox/video/init/' => Http::response(ttFixture('init_file_upload.json')),
        'https://open-upload.tiktokapis.com/*' => Http::sequence()->push('', 206)->push('', 201),
    ]);

    $path = ttLocalVideo('0123456789', 'clip.mov');
    $driver = ttDriver();
    $post = ttPost([Media::video($path)]);

    $outcome = $driver->publish($post);

    expect($outcome->state['transfer'])->toBe('FILE_UPLOAD')
        ->and($outcome->state['body']['source_info'])->toBe(['source' => 'FILE_UPLOAD', 'video_size' => 10, 'chunk_size' => 4, 'total_chunk_count' => 2])
        ->and($outcome->state['upload'])->toMatchArray(['path' => $path, 'owned' => false, 'mime' => 'video/quicktime']);

    $outcome = $driver->resume($post, $outcome->state);
    Http::assertSentCount(1); // the init only: bytes go in the next step

    // The first chunk; the last one goes out in a committed run of its own.
    $outcome = $driver->resume($post, $outcome->state);
    expect($outcome->state['phase'])->toBe('upload')->and($outcome->committed)->toBeTrue();

    $outcome = $driver->resume($post, $outcome->state);

    $puts = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => $r->method() === 'PUT')->values();

    expect($puts)->toHaveCount(2)
        ->and($puts[0]->header('Content-Range'))->toBe(['bytes 0-3/10'])
        ->and($puts[0]->body())->toBe('0123')
        ->and($puts[1]->header('Content-Range'))->toBe(['bytes 4-9/10'])
        ->and($puts[1]->body())->toBe('456789')
        ->and($puts[1]->header('Content-Type'))->toBe(['video/quicktime'])
        ->and($puts[0]->hasHeader('Authorization'))->toBeFalse()
        ->and($outcome->state['phase'])->toBe('await_publish')
        ->and($outcome->state)->not->toHaveKey('upload_url')
        ->and($outcome->committed)->toBeTrue()
        ->and(file_exists($path))->toBeTrue(); // the caller's own file is never deleted
});

it('hands over to the next run when the per-run budget is spent', function () {
    Http::fake(['https://open-upload.tiktokapis.com/*' => Http::sequence()->push('', 206)->push('', 201)]);

    $path = ttLocalVideo();
    $driver = ttDriver();
    $driver->budget = false;
    $post = ttPost([Media::video($path)]);

    $outcome = $driver->resume($post, ttUploadState($path));

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->state['phase'])->toBe('upload')
        ->and($outcome->state['upload']['offset'])->toBe(4)
        ->and($outcome->recheckAfter)->toBe(0)
        ->and($outcome->committed)->toBeTrue();

    $outcome = $driver->resume($post, $outcome->state);

    expect($outcome->state['phase'])->toBe('await_publish');
    Http::assertSentCount(2);
});

it('retries a chunk on 5xx and connection errors before giving up for this run', function () {
    $calls = 0;
    Http::fake(function (Request $request) use (&$calls) {
        $calls++;

        if ($calls === 2) {
            throw new ConnectException('cURL error 56: Connection reset by peer', new PsrRequest('PUT', $request->url()));
        }

        return match ($calls) {
            1 => Http::response('', 503),
            3 => Http::response('', 206),
            default => Http::response('', 201),
        };
    });

    $path = ttLocalVideo();
    $outcome = ttDriver()->resume(ttPost([Media::video($path)]), ttUploadState($path));

    expect($outcome->state['phase'])->toBe('await_publish')->and($calls)->toBe(4);
});

it('fails the run (not the upload) when a chunk keeps failing', function () {
    $path = ttLocalVideo();

    Http::fake(['https://open-upload.tiktokapis.com/*' => Http::response('', 500)]);
    $e = ttCatch(fn () => ttDriver()->resume(ttPost([Media::video($path)]), ttUploadState($path)));
    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::ServerError)
        ->and($e->context)->not->toHaveKey('restart');
    Http::assertSentCount(3);

    Http::fake(fn (Request $request) => throw new ConnectException('cURL error 28: Operation timed out', new PsrRequest('PUT', $request->url())));
    $e = ttCatch(fn () => ttDriver()->resume(ttPost([Media::video($path)]), ttUploadState($path)));
    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::Network)
        ->and($e->getMessage())->not->toContain('Xza123');
});

it('resyncs from Content-Range on 416', function () {
    Http::fake(['https://open-upload.tiktokapis.com/*' => Http::sequence()
        ->push('', 416, ['Content-Range' => 'bytes 0-3/10'])
        ->push('', 201),
    ]);

    $path = ttLocalVideo();
    $outcome = ttDriver()->resume(ttPost([Media::video($path)]), ttUploadState($path, upload: ['offset' => 0]));

    $puts = collect(Http::recorded())->map(fn ($pair) => $pair[0]);

    expect($outcome->state['phase'])->toBe('await_publish')
        ->and($puts[1]->header('Content-Range'))->toBe(['bytes 4-9/10']);
});

it('resyncs when the last chunk is answered without 201', function () {
    Http::fake(['https://open-upload.tiktokapis.com/*' => Http::sequence()
        ->push('', 206, ['Content-Range' => 'bytes 0-3/10'])
        ->push('', 201),
    ]);

    $path = ttLocalVideo();
    $outcome = ttDriver()->resume(ttPost([Media::video($path)]), ttUploadState($path, upload: ['offset' => 4]));

    expect($outcome->state['phase'])->toBe('await_publish');
    Http::assertSentCount(2);
});

it('treats a plain 2xx on the last chunk as complete', function () {
    Http::fake(['https://open-upload.tiktokapis.com/*' => Http::sequence()->push('', 206)->push('', 200)]);
    $path = ttLocalVideo();

    $outcome = ttDriver()->resume(ttPost([Media::video($path)]), ttUploadState($path));

    expect($outcome->state['phase'])->toBe('await_publish');
});

it('gives up resyncing after three disagreements in one run', function () {
    Http::fake(['https://open-upload.tiktokapis.com/*' => Http::response('', 416, ['Content-Range' => 'bytes 0-3/10'])]);
    $path = ttLocalVideo();

    $e = ttCatch(fn () => ttDriver()->resume(ttPost([Media::video($path)]), ttUploadState($path)));

    expect($e)->toBeInstanceOf(TemporaryException::class)->and($e->context['restart'])->toBeTrue();
    Http::assertSentCount(4);
});

it('completes when TikTok reports holding every byte', function () {
    Http::fake(['https://open-upload.tiktokapis.com/*' => Http::response('', 416, ['Content-Range' => 'bytes 0-9/10'])]);
    $path = ttLocalVideo();

    $outcome = ttDriver()->resume(ttPost([Media::video($path)]), ttUploadState($path));

    expect($outcome->state['phase'])->toBe('await_publish');
});

it('reads the received range from Content-Range or Range', function () {
    $driver = ttDriver();
    $received = fn (array $headers) => $driver->call('receivedBytes', new \Illuminate\Http\Client\Response(new Response(416, $headers)));

    expect($received(['Content-Range' => 'bytes 0-1023/2048']))->toBe(1024)
        ->and($received(['Content-Range' => 'bytes */2048']))->toBe(0)
        ->and($received(['Range' => 'bytes=0-99']))->toBe(100)
        ->and($received([]))->toBeNull();
});

it('restarts when a chunk reads short (the file changed under the upload)', function () {
    Http::fake();
    $path = ttLocalVideo();

    $driver = new class(null, new LocalMediaGateway(config('social.temp_dir'))) extends TikTokTestDriver
    {
        protected function readChunk(string $path, int $offset, int $length): string
        {
            return substr(parent::readChunk($path, $offset, $length), 1);
        }
    };

    $e = ttCatch(fn () => $driver->resume(ttPost([Media::video($path)]), ttUploadState($path)));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->context)->toMatchArray(['restart' => true, 'read' => 3, 'expected' => 4]);
    Http::assertNothingSent();
});

it('restarts when TikTok and the upload cannot agree on the received bytes', function () {
    Http::fake(['https://open-upload.tiktokapis.com/*' => Http::response('', 416)]);
    $path = ttLocalVideo();

    $e = ttCatch(fn () => ttDriver()->resume(ttPost([Media::video($path)]), ttUploadState($path)));

    expect($e)->toBeInstanceOf(TemporaryException::class)->and($e->context['restart'])->toBeTrue();
});

it('maps chunk failures: 403/404 restart, 400 is permanent', function (int $status, string $class, ?bool $restart) {
    Http::fake(['https://open-upload.tiktokapis.com/*' => Http::response('', $status)]);
    $path = ttLocalVideo();

    $e = ttCatch(fn () => ttDriver()->resume(ttPost([Media::video($path)]), ttUploadState($path)));

    expect($e)->toBeInstanceOf($class)
        ->and($e->context['restart'] ?? null)->toBe($restart)
        ->and($e->context['http_status'])->toBe($status);
})->with([
    'expired upload url' => [403, TemporaryException::class, true],
    'no upload task' => [404, TemporaryException::class, true],
    'malformed' => [400, PermanentException::class, null],
    'unexpected' => [409, PermanentException::class, null],
    'throttled' => [429, TemporaryException::class, null],
]);

it('gives up restarting an expired upload after max_restarts', function () {
    Http::fake(['https://open-upload.tiktokapis.com/*' => Http::response('', 403)]);
    $path = ttLocalVideo();

    $e = ttCatch(fn () => ttDriver()->resume(ttPost([Media::video($path)]), ttUploadState($path, [Pending::RESTARTS => 2])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::NotFound)
        ->and($e->getMessage())->toContain('Gave up after 2');
});

it('keeps a multi-chunk upload uncommitted until the run that sends the last chunk', function () {
    Http::fake([
        API.'/post/publish/inbox/video/init/' => Http::response(ttFixture('init_file_upload.json')),
        'https://open-upload.tiktokapis.com/*' => Http::sequence()->push('', 206)->push('', 201),
    ]);

    $path = ttLocalVideo();
    $driver = ttDriver();
    $post = ttPost([Media::video($path)]);

    // Two chunks: TikTok starts publishing only once the last one arrives.
    $outcome = $driver->resume($post, $driver->publish($post)->state);
    expect($outcome->state['phase'])->toBe('upload')
        ->and($outcome->committed)->toBeFalse()
        ->and($outcome->state['upload']['committed'])->toBeFalse();

    // The first chunk goes out uncommitted; the last one waits for a committed run.
    $outcome = $driver->resume($post, $outcome->state);
    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->state['phase'])->toBe('upload')
        ->and($outcome->state['upload']['offset'])->toBe(4)
        ->and($outcome->state['upload']['committed'])->toBeTrue()
        ->and($outcome->committed)->toBeTrue()
        ->and($outcome->recheckAfter)->toBe(0);
    Http::assertSentCount(2); // the init and the first chunk

    $outcome = $driver->resume($post, $outcome->state);
    expect($outcome->state['phase'])->toBe('await_publish')->and($outcome->committed)->toBeTrue();
    Http::assertSentCount(3);

    // A single-chunk upload is committed from the start: its first chunk is the last.
    $state = ttInitState([
        'transfer' => 'FILE_UPLOAD',
        'body' => ['source_info' => ['source' => 'FILE_UPLOAD', 'video_size' => 10, 'chunk_size' => 10, 'total_chunk_count' => 1]],
        'upload' => ['path' => $path, 'owned' => false, 'size' => 10, 'chunk_size' => 10, 'total_chunks' => 1, 'mime' => 'video/mp4', 'offset' => 0],
    ]);
    $outcome = $driver->resume($post, $state);
    expect($outcome->committed)->toBeTrue()->and($outcome->state['upload']['committed'])->toBeTrue();
});

it('reports an upload failure before the last chunk as not published', function (int $status, string $class) {
    Http::fake(['https://open-upload.tiktokapis.com/*' => Http::response('', $status)]);
    $path = ttLocalVideo();
    $post = ttPost([Media::video($path)]);

    $e = ttCatch(fn () => ttDriver()->resume($post, ttUploadState($path, upload: ['committed' => false])));

    expect($e)->toBeInstanceOf($class)
        ->and($e->context)->toHaveKey('possibly_published')
        ->and($e->possiblyPublished())->toBeFalse();

    // Once the last chunk may have gone out, the job's committed flag decides.
    $e = ttCatch(fn () => ttDriver()->resume($post, ttUploadState($path, upload: ['offset' => 4, 'committed' => true])));
    expect($e->context)->not->toHaveKey('possibly_published');
})->with([
    'malformed chunk' => [400, PermanentException::class],
    'expired upload url' => [403, TemporaryException::class],
    'upload host down' => [500, TemporaryException::class],
]);

it('reports a lost connection before the last chunk as not published', function () {
    Http::fake(fn (Request $request) => throw new ConnectException('cURL error 56: Connection reset by peer', new PsrRequest('PUT', $request->url())));
    $path = ttLocalVideo();

    $e = ttCatch(fn () => ttDriver()->resume(ttPost([Media::video($path)]), ttUploadState($path, upload: ['committed' => false])));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::Network)
        ->and($e->context)->toHaveKey('possibly_published')
        ->and($e->possiblyPublished())->toBeFalse();
});

it('never retries a chunk past the run budget, so a stalled upload host cannot outlive the job timeout', function () {
    // A clock that advances only when a PUT takes time.
    $driver = new class(null, new LocalMediaGateway(config('social.temp_dir'))) extends TikTokTestDriver
    {
        public float $clock = 0.0;

        protected function budgetLeft(float $startedAt): bool
        {
            return $this->clock < $this->runBudgetSeconds();
        }
    };

    $timeout = (int) config('social.upload_timeout', 300);
    $puts = 0;

    Http::fake(function (Request $request) use ($driver, $timeout, &$puts) {
        $puts++;

        if ($puts === 1) {
            $driver->clock += 239; // the first chunk ends just inside the run budget

            return Http::response('', 206);
        }

        $driver->clock += $timeout; // every attempt at the last chunk stalls until upload_timeout

        throw new ConnectException('cURL error 28: Operation timed out', new PsrRequest('PUT', $request->url()));
    });

    $path = ttLocalVideo();
    $e = ttCatch(fn () => $driver->resume(ttPost([Media::video($path)]), ttUploadState($path)));

    // The run hands back to the queue (a retry) instead of being killed mid-upload.
    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::Network)
        ->and($puts)->toBe(2)
        ->and($driver->clock)->toBeLessThan((float) PublishToConnectionJob::configuredTimeout(Platform::TikTok));
});

it('downloads remote media for FileUpload and keeps the copy across runs', function () {
    Http::fake([
        VIDEO_URL => fn () => Http::response('abcdefghij', 200),
        'https://open-upload.tiktokapis.com/*' => Http::sequence()->push('', 206)->push('', 201),
    ]);

    $driver = ttDriver();
    $driver->budget = false;
    $post = ttPost([Media::video(VIDEO_URL)], options: new TikTokOptions(transfer: TikTokTransfer::FileUpload));

    $outcome = $driver->publish($post);
    $path = $outcome->state['upload']['path'];

    expect($outcome->state['upload']['owned'])->toBeTrue()->and(file_exists($path))->toBeTrue();

    $state = ttUploadState($path, upload: ['owned' => true]);
    $outcome = $driver->resume($post, $state);

    expect($outcome->state['upload']['offset'])->toBe(4)->and(file_exists($path))->toBeTrue();

    // The copy vanished (another worker host): it is fetched again.
    unlink($path);
    $outcome = $driver->resume($post, $outcome->state);

    expect($outcome->state['phase'])->toBe('await_publish');
    expect(glob($this->tmp.'/sp_*'))->toBe([]);
});

it('uses FILE_UPLOAD for an http video under Auto', function () {
    Http::fake(['http://cdn.example.com/*' => Http::response('abcdefghij', 200)]);

    $gateway = new class(config('social.temp_dir')) extends LocalMediaGateway
    {
        public function url(Media $media): string
        {
            return $media->source;
        }
    };

    $outcome = ttDriver($gateway)->publish(ttPost([Media::video('http://cdn.example.com/clip.mp4')]));

    expect($outcome->state['transfer'])->toBe('FILE_UPLOAD');
});

it('restarts when the video changed size between runs', function () {
    $path = ttLocalVideo();
    $driver = ttDriver();

    $e = ttCatch(fn () => $driver->resume(ttPost([Media::video($path)]), ttUploadState($path, upload: ['size' => 12, 'total_chunks' => 3])));

    expect($e)->toBeInstanceOf(TemporaryException::class)->and($e->context['restart'])->toBeTrue();

    $e = ttCatch(fn () => $driver->resume(ttPost([Media::video($path)]), ttUploadState($path, ['upload_url' => ''])));
    expect($e->context['restart'])->toBeTrue();
});

it('rejects an empty local video before the init', function () {
    $path = ttLocalVideo('');

    $e = ttCatch(fn () => ttDriver()->publish(ttPost([Media::video($path)])));

    expect($e->reason)->toBe(FailureReason::MediaRejected);
    Http::assertNothingSent();
});

it('needs a gateway to upload a remote video', function () {
    $e = ttCatch(fn () => (new TikTokTestDriver)->publish(ttPost([Media::video(VIDEO_URL)], options: new TikTokOptions(transfer: TikTokTransfer::FileUpload))));

    expect($e->reason)->toBe(FailureReason::Misconfigured);
});

it('plans chunks the way TikTok requires', function () {
    $driver = new TikTokDriver;
    $plan = fn (int $size) => (fn () => $this->chunkPlan($size))->call($driver);

    expect($plan(1000))->toBe([1000, 1])
        ->and($plan(67108864))->toBe([67108864, 1])
        ->and($plan(104857600))->toBe([10485760, 10])
        ->and($plan(4294967296))->toBe([10485760, 409]);

    config()->set('social.api.tiktok.chunk_size', 67108864);
    expect($plan(100000000))->toBe([50000000, 2]);

    config()->set('social.api.tiktok.chunk_size', 1);
    expect($plan(104857600))->toBe([5242880, 20]);
});

// --- Status poll ------------------------------------------------------------------------

it('backs off the status poll from 15s to 120s', function () {
    $driver = ttDriver();

    expect(array_map(fn ($polls) => $driver->call('pollDelay', $polls), [0, 3, 4, 8, 12, 16, 400]))
        ->toBe([15, 15, 30, 60, 120, 120, 120]);
});

it('waits on a status rate limit without spending a retry', function () {
    Http::fake([API.'/post/publish/status/fetch/' => Http::sequence()
        ->push(ttError('rate_limit_exceeded', 'Rate limit exceeded.'), 429)
        ->push(ttError('rate_limit_exceeded', 'Rate limit exceeded.'), 200)
        ->push(ttError('rate_limit_exceeded'), 429, ['Retry-After' => '120']),
    ]);

    $driver = ttDriver();
    $post = ttPost([Media::video(VIDEO_URL)]);

    expect($driver->resume($post, ttAwaitState())->recheckAfter)->toBe(60)
        ->and($driver->resume($post, ttAwaitState())->recheckAfter)->toBe(60)
        ->and($driver->resume($post, ttAwaitState())->recheckAfter)->toBe(120);
});

it('maps FAILED fail_reasons', function (string $reason, FailureReason $expected, string $message) {
    Http::fake([API.'/post/publish/status/fetch/' => Http::response(ttStatus('FAILED', ['fail_reason' => $reason]))]);

    $e = ttCatch(fn () => ttDriver()->resume(ttPost([Media::video(VIDEO_URL)]), ttAwaitState()));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe($expected)
        ->and($e->getMessage())->toContain($message)
        ->and($e->context)->toMatchArray(['publish_id' => PUBLISH_ID, 'fail_reason' => $reason]);
})->with([
    ['file_format_check_failed', FailureReason::MediaRejected, 'format'],
    ['duration_check_failed', FailureReason::MediaRejected, 'duration'],
    ['frame_rate_check_failed', FailureReason::MediaRejected, '23 and 60 fps'],
    ['picture_size_check_failed', FailureReason::MediaRejected, '1080p'],
    ['auth_removed', FailureReason::InvalidToken, 'removed'],
    ['spam_risk_too_many_posts', FailureReason::RateLimited, 'daily post limit'],
    ['spam_risk_user_banned_from_posting', FailureReason::AccountRestricted, 'banned'],
    ['spam_risk_text', FailureReason::InvalidContent, 'text as spam'],
    ['spam_risk', FailureReason::InvalidContent, 'as spam'],
    ['publish_cancelled', FailureReason::Unknown, 'cancelled'],
    ['something_new', FailureReason::MediaRejected, 'something_new'],
]);

it('adds the reconnect and reschedule hints to FAILED reasons', function () {
    Http::fake([API.'/post/publish/status/fetch/' => Http::sequence()
        ->push(ttStatus('FAILED', ['fail_reason' => 'auth_removed']))
        ->push(ttStatus('FAILED', ['fail_reason' => 'spam_risk_too_many_posts']))
        ->push(ttStatus('FAILED')),
    ]);

    $driver = ttDriver();
    $post = ttPost([Media::video(VIDEO_URL)]);

    expect(ttCatch(fn () => $driver->resume($post, ttAwaitState()))->isTerminal())->toBeTrue()
        ->and(ttCatch(fn () => $driver->resume($post, ttAwaitState()))->context['retry_after_hint'])->toBe(86400)
        ->and(ttCatch(fn () => $driver->resume($post, ttAwaitState()))->context['fail_reason'])->toBe('unknown');
});

it('restarts the publish for retryable fail_reasons, at most max_restarts times', function (string $reason, FailureReason $expected) {
    Http::fake([API.'/post/publish/status/fetch/' => Http::response(ttStatus('FAILED', ['fail_reason' => $reason]))]);
    $driver = ttDriver();
    $post = ttPost([Media::video(VIDEO_URL)]);

    $e = ttCatch(fn () => $driver->resume($post, ttAwaitState([Pending::RESTARTS => 1])));
    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe($expected)
        ->and($e->retryAfter)->toBe(60)
        ->and($e->context)->toMatchArray(['restart' => true, 'restarts' => 1, 'fail_reason' => $reason, 'publish_id' => PUBLISH_ID]);

    $e = ttCatch(fn () => $driver->resume($post, ttAwaitState([Pending::RESTARTS => 2])));
    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe($expected)
        ->and($e->context)->toMatchArray(['fail_reason' => $reason, 'publish_id' => PUBLISH_ID, 'restarts' => 2]);

    config()->set('social.api.tiktok.max_restarts', 0);
    expect(ttCatch(fn () => $driver->resume($post, ttAwaitState())))->toBeInstanceOf(PermanentException::class);
})->with([
    ['internal', FailureReason::ServerError],
    ['video_pull_failed', FailureReason::MediaRejected],
    ['photo_pull_failed', FailureReason::MediaRejected],
]);

it('maps status errors without mistaking a publish_id error for a bad token', function (string $code, int $status, FailureReason $expected) {
    Http::fake([API.'/post/publish/status/fetch/' => Http::response(ttError($code, 'TikTok says no.'), $status)]);

    $e = ttCatch(fn () => ttDriver()->resume(ttPost([Media::video(VIDEO_URL)]), ttAwaitState()));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe($expected)
        ->and($e->context['publish_id'])->toBe(PUBLISH_ID)
        ->and($e->possiblyPublished())->toBeTrue();
})->with([
    ['token_not_authorized_for_specified_publish_id', 400, FailureReason::NotFound],
    ['invalid_publish_id', 400, FailureReason::NotFound],
    ['access_token_invalid', 401, FailureReason::InvalidToken],
]);

it('takes one last look past the deadline, then stops polling with the publish_id for reconciliation', function () {
    Http::fake([API.'/post/publish/status/fetch/' => Http::sequence()
        ->push(ttFixture('status_complete.json'))
        ->push(ttStatus('PROCESSING_DOWNLOAD'))
        ->push(ttError('rate_limit_exceeded', 'Rate limit exceeded.'), 429),
    ]);

    $driver = ttDriver();
    $post = ttPost([Media::video(VIDEO_URL)]);

    // The job's last continuation runs at (or just after) the deadline: a post
    // that went live in the final interval is reported as published.
    $outcome = $driver->resume($post, ttAwaitState(['deadline' => time() - 1]));
    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->platformPostId)->toBe('7301234567890123456');

    foreach ([1, 2] as $_) {
        $e = ttCatch(fn () => $driver->resume($post, ttAwaitState(['deadline' => time() - 1])));

        expect($e->reason)->toBe(FailureReason::ProcessingTimeout)
            ->and($e->possiblyPublished())->toBeTrue()
            ->and($e->context['publish_id'])->toBe(PUBLISH_ID);
    }

    Http::assertSentCount(3);
});

it('still resumes a 1.0 continuation state', function () {
    Http::fake([API.'/post/publish/status/fetch/' => Http::sequence()
        ->push(ttStatus('PROCESSING_UPLOAD'))
        ->push(ttFixture('status_complete.json'))
        ->push(ttStatus('PUBLISH_COMPLETE'))
        ->push(ttStatus('SEND_TO_USER_INBOX')),
    ]);

    $legacy = ['phase' => 'await_publish', 'publish_id' => PUBLISH_ID, 'direct' => true];
    $driver = ttDriver();
    $post = ttPost([Media::video(VIDEO_URL)], options: ttDirect());

    $pending = $driver->resume($post, $legacy);
    expect($pending)->toBeInstanceOf(Pending::class)
        ->and($pending->deadline)->toBeNull()
        ->and($pending->state['polls'])->toBe(1);

    $done = $driver->resume($post, $legacy);
    expect($done->result->platformPostId)->toBe('7301234567890123456')
        ->and($done->result->url)->toBeNull()
        ->and($done->result->payload['publish_id'])->toBe(PUBLISH_ID);

    $done = $driver->resume($post, $legacy);
    expect($done->result->platformPostId)->toBe(PUBLISH_ID)
        ->and($done->result->isPublished())->toBeTrue();

    // A 1.0 init that returned no publish_id kept '' in its state.
    $e = ttCatch(fn () => $driver->resume($post, ['phase' => 'await_publish', 'publish_id' => '', 'direct' => false]));
    expect($e->reason)->toBe(FailureReason::OutcomeUnknown);

    $e = ttCatch(fn () => $driver->resume($post, ['phase' => 'publish_retry']));
    expect($e->getMessage())->toBe('Unknown TikTok publish phase.');

    expect($driver->resume($post, ['phase' => 'await', 'publish_id' => PUBLISH_ID]))->toBeInstanceOf(Published::class);
});

it('accepts a public id sent as a string', function () {
    $driver = ttDriver();

    expect($driver->call('publicPostId', ['publicaly_available_post_id' => ['7301234567890123456']]))->toBe('7301234567890123456')
        ->and($driver->call('publicPostId', ['publicaly_available_post_id' => [7301234567890123456]]))->toBe('7301234567890123456')
        ->and($driver->call('publicPostId', ['publicly_available_post_id' => '123']))->toBe('123')
        ->and($driver->call('publicPostId', ['publicaly_available_post_id' => ['abc']]))->toBeNull()
        ->and($driver->call('publicPostId', []))->toBeNull();
});

it('builds permalinks only from a numeric id and a valid username', function () {
    expect(TikTokDriver::permalink('notion.scheduler', '7301234567890123456'))->toBe('https://www.tiktok.com/@notion.scheduler/video/7301234567890123456')
        ->and(TikTokDriver::permalink('a', '1'))->toBeNull()
        ->and(TikTokDriver::permalink('bad name', '1'))->toBeNull()
        ->and(TikTokDriver::permalink('ok_name', '12a'))->toBeNull()
        ->and(TikTokDriver::permalink(null, '1'))->toBeNull()
        ->and(TikTokDriver::permalink('ok_name', null))->toBeNull();
});

// --- Error table ---------------------------------------------------------------------

it('classifies TikTok error codes exactly', function (string $code, int $status, string $class, FailureReason $reason, ?int $retryAfter) {
    Http::fake([API.'/post/publish/creator_info/query/' => Http::response(ttError($code, 'TikTok message.'), $status)]);

    $e = ttCatch(fn () => ttDriver()->publish(ttPost([Media::video(VIDEO_URL)], options: ttDirect())));

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason)
        ->and($e->context['code'])->toBe($code)
        ->and($e->context['http_status'])->toBe($status);

    if ($e instanceof TemporaryException) {
        expect($e->retryAfter)->toBe($retryAfter);
    }
})->with([
    ['access_token_invalid', 401, PermanentException::class, FailureReason::InvalidToken, null],
    ['scope_not_authorized', 401, PermanentException::class, FailureReason::InsufficientPermissions, null],
    ['spam_risk_user_banned_from_posting', 200, PermanentException::class, FailureReason::AccountRestricted, null],
    ['spam_risk_too_many_posts', 403, PermanentException::class, FailureReason::RateLimited, null],
    ['spam_risk_too_many_pending_share', 403, TemporaryException::class, FailureReason::RateLimited, 3600],
    ['app_version_check_failed', 400, PermanentException::class, FailureReason::AccountRestricted, null],
    ['invalid_param', 400, PermanentException::class, FailureReason::InvalidContent, null],
    ['invalid_params', 400, PermanentException::class, FailureReason::InvalidContent, null],
    ['url_ownership_unverified', 403, PermanentException::class, FailureReason::MediaRejected, null],
    ['reached_active_user_cap', 200, PermanentException::class, FailureReason::RateLimited, null],
    ['privacy_level_option_mismatch', 403, PermanentException::class, FailureReason::InsufficientPermissions, null],
    ['unaudited_client_can_only_post_to_private_accounts', 403, PermanentException::class, FailureReason::InsufficientPermissions, null],
    ['rate_limit_exceeded', 429, TemporaryException::class, FailureReason::RateLimited, 60],
    ['internal_error', 500, TemporaryException::class, FailureReason::ServerError, 60],
    // Codes TikTok may add later fall back to substrings, then the HTTP status.
    ['user_rate_limit_hit', 400, TemporaryException::class, FailureReason::RateLimited, 60],
    ['internal_timeout', 400, TemporaryException::class, FailureReason::ServerError, 60],
    ['access_token_expired', 401, PermanentException::class, FailureReason::InvalidToken, null],
    ['scope_permission_missed', 403, PermanentException::class, FailureReason::InsufficientPermissions, null],
    ['spam_risk_new_thing', 403, TemporaryException::class, FailureReason::RateLimited, 1800],
    ['brand_new_code', 503, TemporaryException::class, FailureReason::ServerError, 60],
    ['brand_new_code', 404, PermanentException::class, FailureReason::NotFound, null],
    ['brand_new_code', 400, PermanentException::class, FailureReason::Unknown, null],
]);

it('marks privacy refusals as not fixed by a reconnect', function (string $code) {
    Http::fake([API.'/post/publish/video/init/' => Http::response(ttError($code, 'Refused.'), 403)]);

    $post = ttPost([Media::video(VIDEO_URL)], options: ttDirect());
    $e = ttCatch(fn () => ttDriver()->resume($post, ttInitState(['direct' => true, 'endpoint' => '/post/publish/video/init/'])));

    expect($e->reason)->toBe(FailureReason::InsufficientPermissions)
        ->and($e->needsReconnect())->toBeFalse();
})->with(['privacy_level_option_mismatch', 'unaudited_client_can_only_post_to_private_accounts']);

it('hands the client\'s daily quota of active publishing users back for rescheduling, never waiting in the queue', function () {
    Http::fake([
        API.'/post/publish/creator_info/query/' => Http::response(ttError('reached_active_user_cap', 'The daily quota for active publishing users from your client is reached.')),
        API.'/post/publish/video/init/' => Http::response(ttError('reached_active_user_cap', 'The daily quota for active publishing users from your client is reached.'), 403),
    ]);

    $post = ttPost([Media::video(VIDEO_URL)], options: ttDirect());

    $atCreatorInfo = ttCatch(fn () => ttDriver()->publish($post));
    $atInit = ttCatch(fn () => ttDriver()->resume($post, ttInitState(['direct' => true, 'endpoint' => '/post/publish/video/init/'])));

    foreach ([$atCreatorInfo, $atInit] as $e) {
        expect($e)->toBeInstanceOf(PermanentException::class)
            ->and($e->reason)->toBe(FailureReason::RateLimited)
            ->and($e->context['retry_after_hint'])->toBe(86400)
            ->and($e->context['retry_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
    }

    Http::assertSentCount(2); // one creator_info, one init: nothing waited out
});

it('treats an error without a code as transient, except a 401', function () {
    Http::fake([API.'/post/publish/creator_info/query/' => Http::sequence()
        ->push('', 401)
        ->push(['error' => ['message' => 'oops']], 400)
        ->push('', 429, ['Retry-After' => '30']),
    ]);

    $post = ttPost([Media::video(VIDEO_URL)], options: ttDirect());

    $e = ttCatch(fn () => ttDriver()->publish($post));
    expect($e)->toBeInstanceOf(PermanentException::class)->and($e->reason)->toBe(FailureReason::InvalidToken);

    $e = ttCatch(fn () => ttDriver()->publish($post));
    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::ServerError)
        ->and($e->retryAfter)->toBe(60);

    $e = ttCatch(fn () => ttDriver()->publish($post));
    expect($e->reason)->toBe(FailureReason::RateLimited)->and($e->retryAfter)->toBe(30);
});

it('re-resolves a rejected token through the job when a resolver is bound', function () {
    app()->instance(CredentialsResolver::class, new TikTokTestResolver);
    Http::fake([API.'/post/publish/status/fetch/' => Http::response(ttError('access_token_invalid', 'Invalid token.'), 401)]);

    $e = ttCatch(fn () => ttDriver()->resume(ttPost([Media::video(VIDEO_URL)]), ttAwaitState()));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->context['reresolve'])->toBeTrue()
        ->and($e->retryAfter)->toBe(0);
});

it('refuses to publish with a token about to expire', function () {
    $e = ttCatch(fn () => ttDriver()->publish(ttPost([Media::video(VIDEO_URL)], credentials: ['access_token' => 'a', 'open_id' => 'o', 'expires_at' => time() + 10])));

    expect($e->reason)->toBe(FailureReason::InvalidToken)->and($e->context['token_expired'])->toBeTrue();
    Http::assertNothingSent();
});

it('treats every media item of a multi-item post as a photo', function () {
    $driver = ttDriver();

    expect($driver->call('isPhotoPost', ttPost([Media::image('https://a.example.com/a.jpg')])))->toBeTrue()
        ->and($driver->call('isPhotoPost', ttPost([Media::video(VIDEO_URL)])))->toBeFalse()
        ->and($driver->call('isPhotoPost', ttPost([])))->toBeFalse()
        ->and($driver->call('utf16Length', 'a😀'))->toBe(3)
        ->and($driver->call('videoMime', Media::video('https://a.example.com/a.webm')))->toBe('video/webm')
        ->and($driver->call('videoMime', new Media('https://a.example.com/a', MediaType::Video)))->toBe('video/mp4');
});

it('wraps unexpected throwables from the upload without deleting the caller\'s file', function () {
    Http::fake(['https://open-upload.tiktokapis.com/*' => Http::response('', 400)]);
    $path = ttLocalVideo();

    try {
        ttDriver()->resume(ttPost([Media::video($path)]), ttUploadState($path));
    } catch (Throwable) {
        //
    }

    expect(file_exists($path))->toBeTrue();
});
