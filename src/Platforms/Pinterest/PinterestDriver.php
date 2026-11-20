<?php

namespace SocialPoster\Platforms\Pinterest;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\MessageBag;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\ImageRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Capabilities\VideoRules;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\Ingestion;
use SocialPoster\Enums\MediaType;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\Support\RetryAfter;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\Published;
use SocialPoster\ValueObjects\PublishOutcome;
use Throwable;
use WeakMap;

/**
 * Pinterest API v5. A Pin is one image, a carousel of 2–5 images, or one
 * video, saved to the board in the credentials (board_id, optionally a
 * board_section_id). Pinterest has no comment API, so there is no first
 * comment. Token refresh is out of scope.
 *
 * Credentials: access_token (OAuth bearer, scopes boards:read, boards:write,
 * pins:read, pins:write; the *_secret variants for secret boards) and the
 * numeric board_id (account_id is accepted as an alias, the production app's
 * name for it). Tokens only work on the host that issued them: config
 * api.pinterest.sandbox (or a per-connection 'sandbox' credential) sends every
 * call to https://api-sandbox.pinterest.com/v5, which is what an app with
 * Trial access must use.
 *
 * Flow:
 * - Images are pulled from a URL (image_url / multiple_image_urls) when the
 *   MediaGateway can provide one, else sent as base64 (JPEG/PNG only); see
 *   PinterestImageTransfer. A carousel is never mixed.
 * - A video is registered (POST /media), uploaded to the S3 address Pinterest
 *   returns (a bare client, never the token; every upload parameter as a form
 *   field, the file last and streamed), then polled through resume()
 *   ('await_media': 10 s six times, then 30 s; 60 s on 429/5xx) until it has
 *   been processed, within api.pinterest.video_max_wait.
 * - The create (POST /pins) is its own commit phase ('pin'). When the previous
 *   run may have created the Pin (the job flags `_recovering`, or the create
 *   got no answer), the board is listed first ('verify_pin') and a Pin with
 *   the same title and description, created since the first attempt, is
 *   adopted instead of creating a duplicate. A Pin without title and
 *   description cannot be told apart: it is reported as OutcomeUnknown
 *   (possibly published) rather than recreated.
 *
 * Settings (config('social.api.pinterest.*'), all optional): base_url,
 * sandbox, sandbox_url, image_transfer (auto), base64_max_bytes (20 MB),
 * image_max_bytes (20 MB), video_max_bytes (2 GB), video_min_duration (4),
 * video_max_duration (900), upload_timeout (social.upload_timeout),
 * pin_timeout (60), video_max_wait (1800), dedupe_on_timeout (true),
 * verify_after (60), verify_retries (5), verify_page_size (50).
 *
 * @see https://developers.pinterest.com/docs/api/v5/pins-create
 * @see https://developers.pinterest.com/docs/api-features/creating-boards-and-pins/
 */
class PinterestDriver extends AbstractPlatform
{
    public const DEFAULT_BASE_URL = 'https://api.pinterest.com/v5';

    public const DEFAULT_SANDBOX_URL = 'https://api-sandbox.pinterest.com/v5';

    public const PERMALINK = 'https://www.pinterest.com/pin/';

    public const MAX_TITLE = 100;

    public const MAX_DESCRIPTION = 800;

    public const MAX_ALT_TEXT = 500;

    public const MAX_LINK = 2048;

    public const MAX_IMAGES = 5;

    /** How far two carousel image ratios may differ (relative) before validate() refuses them. */
    public const CAROUSEL_RATIO_TOLERANCE = 0.01;

    /** Publish phases (Pending::$state['phase']). */
    public const PHASE_AWAIT_MEDIA = 'await_media';

    public const PHASE_PIN = 'pin';

    public const PHASE_VERIFY = 'verify_pin';

    /** Error steps passed to classifyError() through mapError(). */
    public const STEP_PIN = 'pin';

    public const STEP_MEDIA_REGISTER = 'media_register';

    public const STEP_MEDIA_STATUS = 'media_status';

    public const STEP_UPLOAD = 'upload';

    public const STEP_LOOKUP = 'lookup';

    /** A 401 on a video Pin while the token provably works (the missing-cover 401). */
    public const STEP_VIDEO_COVER = 'video_cover';

    protected const KIND_IMAGE = 'image';

    protected const KIND_CAROUSEL = 'carousel';

    protected const KIND_VIDEO = 'video';

    protected const TRANSFER_URL = 'url';

    protected const TRANSFER_BASE64 = 'base64';

    /** Extensions Pinterest takes as base64 bytes (content_type image/jpeg or image/png). */
    protected const BASE64_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    /**
     * S3 error codes that a fresh registration (a new upload policy) and
     * upload can fix. IncompleteBody is a transfer that sent fewer bytes than
     * its Content-Length, not a bad file.
     */
    protected const S3_RETRYABLE = ['AccessDenied', 'ExpiredToken', 'RequestTimeout', 'RequestTimeTooSkewed', 'SlowDown', 'InternalError', 'ServiceUnavailable', 'TokenRefreshRequired', 'IncompleteBody'];

    /** S3 error codes about the file itself. */
    protected const S3_MEDIA = ['EntityTooLarge', 'EntityTooSmall', 'MaxPostPreDataLengthExceeded'];

    /**
     * Pinterest codes reported when its ~7 s create deadline ran out fetching an
     * image URL (community reports; only used to trigger the base64 retry).
     */
    protected const FETCH_TIMEOUT_CODES = [2786, 2787];

    /** @var WeakMap<Media, string>|null local files read during this run */
    private ?WeakMap $localPaths = null;

    public function platform(): Platform
    {
        return Platform::Pinterest;
    }

    protected function ingestion(): Ingestion
    {
        return Ingestion::Both;
    }

    // --- Capabilities -------------------------------------------------------------------

    /** Everything Pinterest takes: one image or video, or a carousel of up to 5 images. */
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            caption: $this->captionRules(),
            media: new MediaRules(minCount: 1, maxCount: self::MAX_IMAGES, image: $this->imageRules(), video: $this->videoRules()),
            supportsTitle: true,
            supportsCarousel: true,
        );
    }

    /**
     * Several items form a carousel: 2 to 5 images, no video. One item is an
     * image or a video. No media is "not enough media": a Pin always has one.
     */
    protected function rulesFor(PreparedPost $post): Capabilities
    {
        if (count($post->media()) > 1) {
            return new Capabilities(
                caption: $this->captionRules(),
                media: new MediaRules(minCount: 2, maxCount: self::MAX_IMAGES, image: $this->imageRules()),
                supportsTitle: true,
                supportsCarousel: true,
            );
        }

        return new Capabilities(
            caption: $this->captionRules(),
            media: new MediaRules(minCount: 1, maxCount: 1, image: $this->imageRules(), video: $this->videoRules()),
            supportsTitle: true,
        );
    }

    /** The description: 800 code points of the trimmed caption, URLs counted in full. */
    protected function captionRules(): CaptionRules
    {
        return new CaptionRules(min: 0, max: self::MAX_DESCRIPTION);
    }

    /** JPEG, PNG and WebP (WebP only by URL), 20 MB by default. */
    protected function imageRules(): ImageRules
    {
        return new ImageRules(
            extensions: ['jpg', 'jpeg', 'png', 'webp'],
            maxSizeBytes: (int) $this->apiConfig('image_max_bytes', 20971520),
        );
    }

    /**
     * MP4/MOV/M4V up to 2 GB, 4 s to video_max_duration (900 s by default; the
     * production app notes the 15-minute maximum is unconfirmed for organic Pins).
     */
    protected function videoRules(): VideoRules
    {
        return new VideoRules(
            extensions: ['mp4', 'mov', 'm4v'],
            types: ['video/mp4', 'video/quicktime', 'video/x-m4v'],
            maxSizeBytes: (int) $this->apiConfig('video_max_bytes', 2147483648),
            minDuration: (float) $this->apiConfig('video_min_duration', 4),
            maxDuration: (float) $this->apiConfig('video_max_duration', 900),
        );
    }

    protected function validatePlatformRules(PreparedPost $post, MessageBag $errors): void
    {
        $options = $this->options($post);
        $media = array_values($post->media());
        $videos = count(array_filter($media, static fn (Media $item) => $item->type === MediaType::Video));
        $isCarousel = count($media) > 1 && $videos === 0;

        $title = trim((string) $post->title());

        if (mb_strlen($title) > self::MAX_TITLE) {
            $errors->add('title', 'Pinterest titles cannot be longer than '.self::MAX_TITLE.' characters (currently '.mb_strlen($title).').');
        }

        if (trim((string) $options?->link) === '' && ($options === null || $options->linkFromCaption)) {
            $link = self::firstLink((string) $post->caption());

            if ($link !== null && strlen($link) > self::MAX_LINK) {
                $errors->add('link', 'The first link in the caption becomes the Pin link, and Pinterest links cannot be longer than '.self::MAX_LINK.' characters; shorten it or set PinterestOptions::$link.');
            }
        }

        if (trim((string) $options?->altText) === '' && isset($media[0])) {
            $alt = trim((string) $media[0]->altText);

            if (mb_strlen($alt) > self::MAX_ALT_TEXT) {
                $errors->add('media.0', 'Pinterest alt text cannot be longer than '.self::MAX_ALT_TEXT.' characters.');
            }
        }

        if (count($media) > 1 && $videos > 0) {
            $errors->add('media', 'A Pin has one video, or 2 to 5 images, never both.');
        }

        if ($videos === 0 && $media !== []) {
            $this->validateImageTransfer($post, $media, $errors);
        }

        if ($videos === 0 && $options?->thumbnail !== null) {
            $errors->add('thumbnail', 'A Pinterest cover image applies only to a video Pin.');
        }

        if ($videos === 0 && $options?->coverKeyFrameTime !== null) {
            $errors->add('cover_key_frame_time', 'A Pinterest cover key frame applies only to a video Pin.');
        }

        if ($options !== null && $options->carouselItems !== []) {
            if (! $isCarousel) {
                $errors->add('carousel_items', 'Pinterest carousel item overrides apply only to a carousel of 2 to 5 images.');
            } else {
                foreach (array_keys($options->carouselItems) as $index) {
                    if (is_int($index) && $index >= count($media)) {
                        $errors->add('carousel_items', "There is no carousel image at position {$index}.");
                    }
                }
            }
        }

        if ($isCarousel) {
            $this->validateCarouselRatios($media, $errors);
        }

        foreach (['board_id' => $this->credentialString($post->credentials, 'board_id') ?? $this->credentialString($post->credentials, 'account_id'), 'board_section_id' => $this->credentialString($post->credentials, 'board_section_id')] as $key => $value) {
            if ($value !== null && preg_match('/^\d+$/', $value) !== 1) {
                $errors->add("credentials.{$key}", "The Pinterest {$key} credential must be numeric.");
            }
        }
    }

    /**
     * Pinterest refuses a carousel whose images do not all share one
     * width/height ratio ("Images must have the same width/height ratios.").
     * Compared on the images whose dimensions are known (the inspector, or
     * Media::$meta), against the first of them, with CAROUSEL_RATIO_TOLERANCE
     * so that rounding (1080x1350 next to 1079x1349) never blocks a carousel
     * Pinterest would take. Unknown dimensions are left to Pinterest.
     *
     * @param  list<Media>  $media
     */
    protected function validateCarouselRatios(array $media, MessageBag $errors): void
    {
        $known = [];

        foreach ($media as $index => $item) {
            $meta = $this->inspectMedia($item);

            if ($meta?->aspectRatio() !== null) {
                $known[$index] = $meta;
            }
        }

        if (count($known) < 2) {
            return;
        }

        $first = array_key_first($known);
        $reference = (float) $known[$first]->aspectRatio();
        $describe = static fn (int $index, MediaMetadata $meta): string => sprintf('image %d is %dx%d', $index + 1, $meta->width, $meta->height);

        foreach ($known as $index => $meta) {
            if (abs((float) $meta->aspectRatio() - $reference) / $reference > self::CAROUSEL_RATIO_TOLERANCE) {
                $errors->add(
                    "media.{$index}",
                    'Every image of a Pinterest carousel must have the same width/height ratio: '
                    .$describe($first, $known[$first]).' but '.$describe($index, $meta).'. Crop them to one shape.',
                );
            }
        }
    }

    /**
     * Whether the images can reach Pinterest the way the transfer mode says: a
     * URL for every image in Url mode; JPEG/PNG bytes whenever base64 is used
     * (Base64 mode, or Auto with an image that has no URL).
     *
     * @param  list<Media>  $media
     */
    protected function validateImageTransfer(PreparedPost $post, array $media, MessageBag $errors): void
    {
        $mode = $this->imageTransferMode($post);
        $allUrls = true;

        foreach ($media as $item) {
            $allUrls = $allUrls && $this->canUrl($item);
        }

        foreach ($media as $index => $item) {
            if ($item->type !== MediaType::Image) {
                continue;
            }

            if ($mode === PinterestImageTransfer::Url && ! $this->canUrl($item)) {
                $errors->add("media.{$index}", 'Pinterest is set to fetch images by URL, but this is a local file: host it publicly, bind a MediaGateway that re-hosts local files (FilesystemMediaGateway), or use PinterestImageTransfer::Base64.');

                continue;
            }

            $base64 = $mode === PinterestImageTransfer::Base64 || ($mode === PinterestImageTransfer::Auto && ! $allUrls);
            $extension = $item->extension();

            if ($base64 && ! in_array($extension, self::BASE64_EXTENSIONS, true) && in_array($extension, (array) $this->imageRules()->extensions, true)) {
                $errors->add("media.{$index}", 'Pinterest can only receive JPG or PNG bytes; host this '.strtoupper($extension === '' ? 'image' : $extension).' publicly or convert it.');
            }
        }
    }

    // --- Credentials, scopes, identity ---------------------------------------------------

    /**
     * The token (unless the app bound its own authorizer in
     * config('social.authorizers.pinterest')) and the numeric board id.
     */
    protected function requiredCredentials(): array
    {
        $required = ['board_id|account_id' => 'the numeric id of the board Pins are saved to'];

        if (! $this->usesAuthorizer()) {
            $required = [$this->tokenKey => 'OAuth access token (boards:read, boards:write, pins:read, pins:write)'] + $required;
        }

        return $required;
    }

    /** One connection is one board (and section): two boards of a user never share a key. */
    protected function identityKeys(): array
    {
        return ['board_id', 'board_section_id'];
    }

    /**
     * account_id is accepted as an alias of board_id (it holds the board id,
     * not the user id), so it identifies the board when board_id is absent.
     */
    public function accountKey(Credentials $credentials): string
    {
        if (! $credentials->filled('board_id')) {
            $alias = $this->credentialString($credentials, 'account_id');

            if ($alias !== null && preg_match('/^\d+$/', $alias) === 1) {
                $credentials = $credentials->with(['board_id' => $alias]);
            }
        }

        return parent::accountKey($credentials);
    }

    /**
     * POST /pins needs boards:read, boards:write, pins:read and pins:write; the
     * video upload (POST /media) pins:read and pins:write. Secret boards also need
     * the *_secret scopes, which cannot be known from the board id alone.
     */
    protected function requiredScopes(Feature $feature, Credentials $credentials): array
    {
        return match ($feature) {
            Feature::Publish => [['boards:read'], ['boards:write'], ['pins:read'], ['pins:write']],
            Feature::Video => [['pins:read'], ['pins:write']],
            default => [],
        };
    }

    /**
     * Fail before any request: a missing token is InvalidToken (the account must
     * be connected), a missing or non-numeric board id, a non-numeric section or
     * a foreign api_base is Misconfigured.
     */
    protected function assertPublishable(PreparedPost $post): void
    {
        $credentials = $post->credentials;

        if (! $this->usesAuthorizer() && ! $credentials->isReference() && ! $credentials->filled($this->tokenKey)) {
            throw new PermanentException(
                'The Pinterest credentials have no access_token; connect the Pinterest account and pass its OAuth token.',
                Platform::Pinterest,
                FailureReason::InvalidToken,
                ['missing_credentials' => [$this->tokenKey]],
            );
        }

        $this->assertCredentials($post);
        $this->boardId($post);
        $this->boardSectionId($post);
        $this->apiBase($post);
        $this->assertTokenFresh($post);
    }

    /** True when config('social.authorizers.pinterest') names the app's own authorizer. */
    protected function usesAuthorizer(): bool
    {
        $configured = config('social.authorizers.pinterest');

        return $configured !== null && $configured !== '' && $configured !== false;
    }

    /** The numeric board id: credential board_id, else its alias account_id. */
    protected function boardId(PreparedPost $post): string
    {
        $id = $this->credentialString($post->credentials, 'board_id') ?? $this->credentialString($post->credentials, 'account_id') ?? '';

        if (preg_match('/^\d+$/', $id) !== 1) {
            throw new PermanentException(
                "The Pinterest board_id credential must be the board's numeric id".($id === '' ? '.' : ", got '{$id}'."),
                Platform::Pinterest,
                FailureReason::Misconfigured,
                ['credential' => 'board_id'],
            );
        }

        return $id;
    }

    /** The board section: PinterestOptions::$boardSectionId, else the credential board_section_id. */
    protected function boardSectionId(PreparedPost $post): ?string
    {
        $option = $this->options($post)?->boardSectionId;
        $id = $option !== null && trim($option) !== '' ? trim($option) : $this->credentialString($post->credentials, 'board_section_id');

        if ($id !== null && preg_match('/^\d+$/', $id) !== 1) {
            throw new PermanentException(
                "The Pinterest board section id must be numeric, got '{$id}'.",
                Platform::Pinterest,
                FailureReason::Misconfigured,
                ['credential' => 'board_section_id'],
            );
        }

        return $id;
    }

    // --- HTTP ---------------------------------------------------------------------------

    /**
     * Requests to the Pinterest API: http() (bearer token or the configured
     * authorizer, default timeouts) on the API host, JSON both ways.
     *
     * @return PendingRequest
     */
    protected function pinterest(PreparedPost $post)
    {
        return $this->http($post)
            ->baseUrl($this->apiBase($post))
            ->acceptJson()
            ->asJson();
    }

    /**
     * The API host, which must be the one that issued the token:
     *
     * 1. credential 'api_base', only an https://*.pinterest.com URL (the token
     *    is never sent anywhere else);
     * 2. credential 'sandbox' (bool) when set, overriding the config;
     * 3. config api.pinterest.sandbox → sandbox_url, else base_url.
     *
     * @throws PermanentException When api_base is not a Pinterest https URL (Misconfigured).
     */
    protected function apiBase(PreparedPost $post): string
    {
        $override = $this->credentialString($post->credentials, 'api_base');

        if ($override !== null) {
            $host = strtolower((string) parse_url($override, PHP_URL_HOST));
            $scheme = strtolower((string) parse_url($override, PHP_URL_SCHEME));

            if ($scheme !== 'https' || ($host !== 'pinterest.com' && ! str_ends_with($host, '.pinterest.com'))) {
                throw new PermanentException(
                    'The Pinterest api_base credential must be an https://….pinterest.com URL.',
                    Platform::Pinterest,
                    FailureReason::Misconfigured,
                    ['credential' => 'api_base', 'api_base_host' => $host],
                );
            }

            return rtrim($override, '/');
        }

        return rtrim($this->usesSandbox($post)
            ? (string) $this->apiConfig('sandbox_url', self::DEFAULT_SANDBOX_URL)
            : (string) $this->apiConfig('base_url', self::DEFAULT_BASE_URL), '/');
    }

    /** The Sandbox host: the credential 'sandbox' when it is a boolean, else config api.pinterest.sandbox. */
    protected function usesSandbox(PreparedPost $post): bool
    {
        $flag = $post->credentials->get('sandbox');

        if ($flag !== null && $flag !== '') {
            $parsed = is_bool($flag) ? $flag : filter_var($flag, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return (bool) filter_var($this->apiConfig('sandbox', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * $path with ?ad_account_id= when the options ask for Business Access.
     *
     * @param  array<string, scalar>  $query
     */
    protected function withAdAccount(PreparedPost $post, string $path, array $query = []): string
    {
        $adAccount = $this->options($post)?->adAccountId;

        if ($adAccount !== null && trim($adAccount) !== '') {
            $query['ad_account_id'] = trim($adAccount);
        }

        return $query === [] ? $path : $path.'?'.http_build_query($query);
    }

    // --- Publish ------------------------------------------------------------------------

    /**
     * A video is registered, uploaded and polled ('await_media'). Images go
     * straight to the create, which runs as its own commit phase ('pin') so a
     * repeat of it is verified first.
     */
    public function publish(PreparedPost $post): PublishOutcome
    {
        $this->assertPublishable($post);

        try {
            $kind = $this->kindOf($post);

            if ($kind === self::KIND_VIDEO) {
                return $this->startVideo($post, array_values($post->media())[0]);
            }

            return $this->commit([
                'phase' => self::PHASE_PIN,
                'kind' => $kind,
                'transfer' => $this->transferFor($post, $this->imagesOf($post)),
                'since' => $this->currentTime(),
            ]);
        } finally {
            $this->releaseMedia();
        }
    }

    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        $phase = is_string($state['phase'] ?? null) ? $state['phase'] : '';

        try {
            $this->assertPublishable($post);
        } catch (SocialPosterException $e) {
            // 'verify_pin' follows a create that may have worked: whatever
            // stops it before the lookup, the Pin may be live.
            throw $phase === self::PHASE_VERIFY ? $e->withFlags(['possibly_published' => true]) : $e;
        }

        $recovering = Pending::isRecovering($state);
        $clean = Pending::withoutReservedKeys($state);

        if (! isset($clean['since']) && isset($state[Pending::COMMITTING_SINCE]) && is_numeric($state[Pending::COMMITTING_SINCE])) {
            $clean['since'] = (int) $state[Pending::COMMITTING_SINCE];
        }

        return match ($phase) {
            self::PHASE_AWAIT_MEDIA => $this->awaitMedia($post, $clean),
            self::PHASE_PIN => $this->createPin($post, $clean, $recovering),
            self::PHASE_VERIFY => $this->createPin($post, $clean, true),
            default => throw new PermanentException(
                "Unknown Pinterest publish phase '{$phase}'.",
                Platform::Pinterest,
                FailureReason::Unknown,
                ['phase' => $phase],
            ),
        };
    }

    /**
     * image (one), carousel (2-5 images) or video (one, alone). Anything else is
     * refused before a request (validation normally reports it first).
     */
    protected function kindOf(PreparedPost $post): string
    {
        $media = array_values($post->media());
        $count = count($media);

        if ($count === 0) {
            throw new PermanentException('A Pin needs one image, a carousel of 2 to 5 images, or one video.', Platform::Pinterest, FailureReason::MediaRejected);
        }

        $videos = 0;

        foreach ($media as $item) {
            if ($item->type === MediaType::Document) {
                throw new PermanentException('Pinterest does not take documents.', Platform::Pinterest, FailureReason::MediaRejected);
            }

            $videos += $item->type === MediaType::Video ? 1 : 0;
        }

        if ($videos > 0) {
            if ($count > 1) {
                throw new PermanentException('A Pin has one video, or 2 to 5 images, never both.', Platform::Pinterest, FailureReason::MediaRejected);
            }

            return self::KIND_VIDEO;
        }

        if ($count > self::MAX_IMAGES) {
            throw new PermanentException('A Pinterest carousel has at most '.self::MAX_IMAGES." images, got {$count}.", Platform::Pinterest, FailureReason::MediaRejected);
        }

        return $count === 1 ? self::KIND_IMAGE : self::KIND_CAROUSEL;
    }

    /** @return list<Media> */
    protected function imagesOf(PreparedPost $post): array
    {
        return array_values(array_filter($post->media(), static fn (Media $item) => $item->type === MediaType::Image));
    }

    // --- Video --------------------------------------------------------------------------

    /**
     * Register the upload, then send the file to S3 with a bare client (the S3
     * policy authorizes it; the Pinterest token must never reach it). Every
     * upload_parameters entry is a form field (Content-Type included, as a field)
     * and the file goes last, streamed. Safe to repeat: an unused registration
     * creates nothing.
     */
    protected function startVideo(PreparedPost $post, Media $video): PublishOutcome
    {
        $register = $this->pinterest($post)->post('media', ['media_type' => 'video']);

        if (! $this->ok($register)) {
            throw $this->mapError($register, self::STEP_MEDIA_REGISTER);
        }

        $mediaId = $this->requireId($register, 'video upload', 'media_id');
        $uploadUrl = $this->requireId($register, 'video upload URL', 'upload_url');
        $fields = [];

        foreach ((array) $register->json('upload_parameters') as $key => $value) {
            if (is_scalar($value)) {
                $fields[(string) $key] = (string) $value;
            }
        }

        $stream = $this->stream($video);

        try {
            $upload = Http::connectTimeout((int) $this->apiConfig('connect_timeout', 10))
                ->timeout($this->uploadTimeout())
                ->attach('file', $stream, $this->videoFilename($video))
                ->post($uploadUrl, $fields);
        } finally {
            $stream->close();
        }

        if (! $this->ok($upload)) {
            throw $this->mapError($upload, self::STEP_UPLOAD);
        }

        $deadline = $this->currentTime() + $this->videoMaxWait();

        return PublishOutcome::pending([
            'phase' => self::PHASE_AWAIT_MEDIA,
            'kind' => self::KIND_VIDEO,
            'media_id' => $mediaId,
            'polls' => 0,
            'deadline' => $deadline,
        ], 10, false, $deadline);
    }

    /**
     * Poll GET /media/{id}: succeeded → the create (commit phase); failed →
     * MediaRejected; registered/processing → poll again (10 s six times, then
     * 30 s). 429 and 5xx wait 60 s without failing the run. Each wait counts
     * against the job's poll budget and the video_max_wait deadline.
     *
     * @param  array<string, mixed>  $state
     */
    protected function awaitMedia(PreparedPost $post, array $state): PublishOutcome
    {
        $mediaId = $this->stateString($state, 'media_id');
        $polls = (int) ($state['polls'] ?? 0);
        $response = $this->pinterest($post)->get('media/'.rawurlencode($mediaId));

        if (! $this->ok($response)) {
            $status = $response->status();

            if ($status === 429 || $status >= 500) {
                $this->assertWithinDeadline($state, $mediaId);

                return $this->waitForMedia(['polls' => $polls + 1] + $state, $this->clampDelay(max(60, $this->retryAfterHeader($response) ?? 60)));
            }

            throw $this->mapError($response, self::STEP_MEDIA_STATUS);
        }

        $status = $response->json('status');

        if ($status === 'succeeded') {
            // Its own deadline: the job would otherwise apply the processing
            // one, and a video processed on the last look would never be pinned.
            return $this->commit([
                'phase' => self::PHASE_PIN,
                'kind' => self::KIND_VIDEO,
                'media_id' => $mediaId,
                'since' => $this->currentTime(),
            ], 0, $this->commitDeadline());
        }

        if ($status === 'failed') {
            throw new PermanentException(
                "Pinterest couldn't process the video. Export it as MP4 (H.264), 4 seconds to 15 minutes long.",
                Platform::Pinterest,
                FailureReason::MediaRejected,
                ['media_id' => $mediaId, 'media' => $response->json()],
            );
        }

        $this->assertWithinDeadline($state, $mediaId);

        return $this->waitForMedia(
            ['polls' => $polls + 1, 'media_status' => is_string($status) ? $status : null] + $state,
            $polls < 6 ? 10 : 30,
        );
    }

    /** @param  array<string, mixed>  $state */
    protected function waitForMedia(array $state, int $recheckAfter): Pending
    {
        $deadline = isset($state['deadline']) && is_numeric($state['deadline']) ? (int) $state['deadline'] : null;

        return PublishOutcome::pending($state, $recheckAfter, false, $deadline);
    }

    /**
     * Stop polling once the deadline passed (the job normally stops first; this
     * keeps a job that does not honour Pending deadlines from polling forever).
     * No Pin exists yet, so nothing may be live.
     *
     * @param  array<string, mixed>  $state
     */
    protected function assertWithinDeadline(array $state, string $mediaId): void
    {
        $deadline = isset($state['deadline']) && is_numeric($state['deadline']) ? (int) $state['deadline'] : null;

        if ($deadline === null || $this->currentTime() <= $deadline) {
            return;
        }

        throw new PermanentException(
            'Pinterest was still processing the video when the wait ran out; no Pin was created.',
            Platform::Pinterest,
            FailureReason::ProcessingTimeout,
            ['media_id' => $mediaId, 'media_status' => $state['media_status'] ?? null],
        );
    }

    /**
     * The video's media_source: video_id plus exactly one cover field, computed
     * here (never kept in the state, so base64 never reaches the queue): the
     * cover image by URL when the gateway can provide one, else as base64
     * (JPEG/PNG), else the frame at coverKeyFrameTime (default 0). The cover is
     * PinterestOptions::$thumbnail, else the video's own Media::video(thumbnail:).
     *
     * @return array<string, mixed>
     */
    protected function videoSource(PreparedPost $post, string $mediaId): array
    {
        $source = ['source_type' => 'video_id', 'media_id' => $mediaId];
        $options = $this->options($post);
        $video = array_values($post->media())[0] ?? null;
        $cover = $options?->thumbnail;

        if ($cover === null && $video !== null && $video->thumbnail !== null && trim($video->thumbnail) !== '') {
            $cover = Media::image(trim($video->thumbnail));
        }

        if ($cover !== null) {
            if ($this->canUrl($cover)) {
                return $source + ['cover_image_url' => $this->mediaUrl($cover)];
            }

            if (in_array($cover->extension(), [...self::BASE64_EXTENSIONS, ''], true)) {
                $encoded = $this->base64Fields($cover);

                return $source + ['cover_image_content_type' => $encoded['content_type'], 'cover_image_data' => $encoded['data']];
            }
        }

        return $source + ['cover_image_key_frame_time' => max(0, $options->coverKeyFrameTime ?? 0)];
    }

    /** A filename with a video extension for the S3 form (S3 keys it by the policy, not the name). */
    protected function videoFilename(Media $video): string
    {
        $extension = $video->extension();
        $extension = in_array($extension, ['mp4', 'mov', 'm4v'], true) ? $extension : 'mp4';
        $name = basename((string) (parse_url($video->source, PHP_URL_PATH) ?: $video->source));
        $name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);

        return $name !== '' && str_ends_with(strtolower($name), '.'.$extension) ? $name : 'video.'.$extension;
    }

    // --- Pin create (commit phase) -----------------------------------------------------

    /**
     * POST /pins. When the previous run may have created the Pin (the job set
     * `_recovering`, or this is 'verify_pin' after a create without answer), the
     * board is looked up first. In Auto transfer mode a URL Pin that Pinterest
     * could not fetch is retried once with base64: at once after a fetch error,
     * or after a board lookup ('verify_pin') after a 5xx, which may come after
     * the Pin was saved.
     *
     * @param  array<string, mixed>  $state
     */
    protected function createPin(PreparedPost $post, array $state, bool $verify): PublishOutcome
    {
        $kind = $this->stateKind($post, $state);
        $state['kind'] = $kind;
        $state['phase'] = self::PHASE_PIN;
        $state['since'] = isset($state['since']) && is_numeric($state['since']) ? (int) $state['since'] : $this->currentTime();

        try {
            if ($verify) {
                if (! $this->dedupeEnabled()) {
                    throw new PermanentException(
                        'An earlier attempt may have created this Pin, and api.pinterest.dedupe_on_timeout is off, so the board is not checked; look at the board before posting it again.',
                        Platform::Pinterest,
                        FailureReason::OutcomeUnknown,
                        ['possibly_published' => true, 'phase' => self::PHASE_PIN],
                    );
                }

                $found = $this->verifyOrRetry($post, $state);

                if ($found instanceof Pending) {
                    return $found;
                }

                if ($found !== null) {
                    return $this->publishedPin($post, $state, $found, ['recovered' => true]);
                }
            }

            if ($kind !== self::KIND_VIDEO) {
                $state['transfer'] = in_array($state['transfer'] ?? null, [self::TRANSFER_URL, self::TRANSFER_BASE64], true)
                    ? $state['transfer']
                    : $this->transferFor($post, $this->imagesOf($post));
            }

            $body = $this->pinBody($post, $kind, $state);
            $response = $this->sendPin($post, $body, $state);

            if ($response instanceof Pending) {
                return $response;
            }

            if (! $this->ok($response) && $this->shouldFallBackToBase64($post, $kind, $state, $response)) {
                $state = ['transfer' => self::TRANSFER_BASE64, 'fell_back' => true] + $state;

                if ($response->status() >= 500) {
                    // A 5xx may come after Pinterest saved the Pin: look at the
                    // board first, and send the base64 create only when the Pin
                    // is not there.
                    return $this->verifyLater($state);
                }

                $body = $this->pinBody($post, $kind, $state);
                $response = $this->sendPin($post, $body, $state);

                if ($response instanceof Pending) {
                    return $response;
                }
            }

            $section = $this->boardSectionId($post) !== null;

            if (! $this->ok($response)) {
                if ($kind === self::KIND_VIDEO && $response->status() === 401 && $this->tokenStillWorks($post, (string) ($state['media_id'] ?? ''))) {
                    throw $this->mapError($response, self::STEP_VIDEO_COVER, $section);
                }

                throw $this->mapError($response, self::STEP_PIN, $section);
            }

            $id = $this->requireId($response, 'Pin', 'id', ambiguous: true);
            $json = $response->json();

            return $this->publishedPin($post, $state, $id, array_filter([
                'cover' => $kind === self::KIND_VIDEO ? $this->coverKind($body) : null,
                'fell_back' => ($state['fell_back'] ?? false) === true ? true : null,
                'response' => is_array($json) ? $json : null,
            ], static fn ($value) => $value !== null));
        } finally {
            $this->releaseMedia();
        }
    }

    /**
     * Send the create. A transport failure that provably never left (DNS,
     * refused, TLS) is rethrown for a plain retry; any other may have created
     * the Pin, so the board is checked a minute later ('verify_pin').
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $state
     * @return Response|Pending
     */
    protected function sendPin(PreparedPost $post, array $body, array $state)
    {
        try {
            return $this->pinterest($post)
                ->timeout($this->pinTimeout())
                ->post($this->withAdAccount($post, 'pins'), $body);
        } catch (ConnectionException $e) {
            return $this->afterAmbiguousCreate($state, $e);
        }
    }

    /**
     * A create that got no answer. When the request never left, rethrow (the job
     * retries it as is). With dedupe_on_timeout off, report OutcomeUnknown.
     * Otherwise look the Pin up after verify_after seconds (Pinterest may not
     * list it at once), at most verify_retries times.
     *
     * @param  array<string, mixed>  $state
     */
    protected function afterAmbiguousCreate(array $state, ConnectionException $e): Pending
    {
        if (TemporaryException::requestNeverSent($e)) {
            throw $e;
        }

        $rounds = (int) ($state['ambiguous_rounds'] ?? 0) + 1;

        if (! $this->dedupeEnabled() || $rounds > $this->verifyRetries()) {
            throw new PermanentException(
                'Pinterest did not answer the Pin create; the Pin may be live, check the board before posting it again.',
                Platform::Pinterest,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'connection' => true, 'request_sent' => true, 'phase' => self::PHASE_PIN, 'ambiguous_rounds' => $rounds],
                $e,
            );
        }

        return $this->verifyLater(['ambiguous_rounds' => $rounds] + $state);
    }

    /**
     * Look the board up after verify_after seconds ('verify_pin', a commit
     * phase: when nothing is found it creates). Every such step declares its
     * own deadline (see commitDeadline()).
     *
     * @param  array<string, mixed>  $state
     */
    protected function verifyLater(array $state): Pending
    {
        return $this->commit(
            ['phase' => self::PHASE_VERIFY] + $state,
            $this->verifyDelay(),
            $this->commitDeadline(),
            possiblySent: true,
        );
    }

    /**
     * Run the board lookup. Returns the Pin id when found, null when the Pin
     * provably does not exist (safe to create), or a Pending that runs the
     * lookup again later when Pinterest could not be read (network, 429, 5xx).
     * After verify_retries unreadable lookups, or when the lookup is refused
     * outright, the outcome is reported as possibly published.
     *
     * @param  array<string, mixed>  $state
     */
    protected function verifyOrRetry(PreparedPost $post, array $state): string|Pending|null
    {
        try {
            return $this->findRecentPin($post, $state);
        } catch (ConnectionException|TemporaryException $e) {
            $failures = (int) ($state['verify_failures'] ?? 0) + 1;

            if ($failures > $this->verifyRetries()) {
                throw new PermanentException(
                    'Pinterest could not be read to check whether an earlier attempt already created this Pin; check the board before posting it again.',
                    Platform::Pinterest,
                    FailureReason::OutcomeUnknown,
                    ['possibly_published' => true, 'phase' => self::PHASE_VERIFY],
                    $e,
                );
            }

            return $this->verifyLater(['verify_failures' => $failures] + $state);
        } catch (PermanentException $e) {
            throw $e->withFlags(['possibly_published' => true]);
        }
    }

    /**
     * Look for the Pin an earlier attempt may have created: on the board (or
     * its section), created since the first attempt (minus a minute of clock
     * skew), with the same trimmed title and description. The link is not
     * compared (Pinterest may normalise it). Best effort: one page of
     * verify_page_size Pins, and a custom-sorted board may list it further down.
     *
     * @param  array<string, mixed>  $state
     *
     * @throws PermanentException When a Pin without title and description cannot be told apart (OutcomeUnknown), or the lookup is refused.
     * @throws TemporaryException When Pinterest could not be read (429, 5xx, unreadable listing).
     * @throws ConnectionException On a transport failure.
     */
    protected function findRecentPin(PreparedPost $post, array $state): ?string
    {
        $board = $this->boardId($post);
        $section = $this->boardSectionId($post);
        $path = $section !== null
            ? 'boards/'.$board.'/sections/'.$section.'/pins'
            : 'boards/'.$board.'/pins';

        $response = $this->pinterest($post)->get($this->withAdAccount($post, $path, [
            'page_size' => max(1, min(250, (int) $this->apiConfig('verify_page_size', 50))),
        ]));

        if (! $this->ok($response)) {
            throw $this->mapError($response, self::STEP_LOOKUP, $section !== null);
        }

        $items = $response->json('items');

        if (! is_array($items)) {
            throw new TemporaryException('Pinterest returned an unreadable board listing.', Platform::Pinterest, FailureReason::ServerError, 60, ['phase' => self::PHASE_VERIFY]);
        }

        $since = (int) ($state['since'] ?? $this->currentTime()) - 60;
        $title = $this->normaliseText($this->sentText($post, 'title'));
        $description = $this->normaliseText($this->sentText($post, 'description'));
        $recent = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $created = $this->timestampOf($item['created_at'] ?? null);

            // An unknown creation time is kept: better adopt than duplicate.
            if ($created === null || $created >= $since) {
                $recent[] = $item;
            }
        }

        if ($title === '' && $description === '') {
            if ($recent === []) {
                return null;
            }

            throw new PermanentException(
                'An earlier attempt may have created this Pin, and a Pin without title or description cannot be told apart from the others saved since; check the board before posting it again.',
                Platform::Pinterest,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'phase' => self::PHASE_VERIFY, 'candidates' => count($recent)],
            );
        }

        foreach ($recent as $item) {
            $id = $item['id'] ?? null;

            if ((! is_string($id) && ! is_int($id)) || trim((string) $id) === '') {
                continue;
            }

            if ($this->normaliseText($item['title'] ?? null) === $title
                && $this->normaliseText($item['description'] ?? null) === $description) {
                return trim((string) $id);
            }
        }

        return null;
    }

    /**
     * True when a 401 on a video Pin came with a token that still works: GET
     * /media/{id} (pins:read, which the poll just used) answers 200. Pinterest
     * is reported to answer a video Pin it cannot build a cover for with 401.
     */
    protected function tokenStillWorks(PreparedPost $post, string $mediaId): bool
    {
        if ($mediaId === '') {
            return false;
        }

        try {
            return $this->ok($this->pinterest($post)->timeout(30)->get('media/'.rawurlencode($mediaId)));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The PinCreate body: board, section, title, description, link and alt text
     * (blanks left out), the media source, and extra() merged in. Driver keys
     * win; extra['media_source'] is deep-merged into the computed source.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function pinBody(PreparedPost $post, string $kind, array $state): array
    {
        $link = $this->resolveLink($post);
        $transfer = (string) ($state['transfer'] ?? self::TRANSFER_URL);

        $fields = array_filter([
            'board_id' => $this->boardId($post),
            'board_section_id' => $this->boardSectionId($post),
            'title' => $this->pinTitle($post),
            'description' => $this->pinDescription($post),
            'link' => $link,
            'alt_text' => $this->altText($post),
        ], static fn ($value) => $value !== null && $value !== '');

        $images = $this->imagesOf($post);

        $source = match ($kind) {
            self::KIND_VIDEO => $this->videoSource($post, $this->stateString($state, 'media_id')),
            self::KIND_CAROUSEL => $this->carouselSource($post, $images, $transfer, $link),
            default => $this->imageSource($images[0] ?? $this->missingImage(), $transfer),
        };

        $extra = $this->extraPayload($post);
        $extraSource = $extra['media_source'] ?? null;
        unset($extra['media_source']);

        $fields['media_source'] = is_array($extraSource) ? array_replace_recursive($extraSource, $source) : $source;

        return array_merge($extra, $fields);
    }

    /** @return array<string, mixed> */
    protected function imageSource(Media $image, string $transfer): array
    {
        if ($transfer === self::TRANSFER_BASE64) {
            return ['source_type' => 'image_base64'] + $this->base64Fields($image);
        }

        return ['source_type' => 'image_url', 'url' => $this->mediaUrl($image)];
    }

    /**
     * 2-5 images, all by URL or all as base64. Each item repeats the Pin link,
     * unless PinterestOptions::$carouselItems overrides its title, description
     * or link.
     *
     * @param  list<Media>  $images
     * @return array<string, mixed>
     */
    protected function carouselSource(PreparedPost $post, array $images, string $transfer, ?string $link): array
    {
        $overrides = $this->options($post)->carouselItems ?? [];
        $items = [];

        foreach ($images as $index => $image) {
            $override = is_array($overrides[$index] ?? null) ? $overrides[$index] : [];

            $item = $transfer === self::TRANSFER_BASE64
                ? $this->base64Fields($image)
                : ['url' => $this->mediaUrl($image)];

            foreach (['title' => null, 'description' => null, 'link' => $link] as $key => $default) {
                $value = isset($override[$key]) && is_string($override[$key]) && trim($override[$key]) !== ''
                    ? trim($override[$key])
                    : $default;

                if ($value !== null && $value !== '') {
                    $item[$key] = $value;
                }
            }

            $items[] = $item;
        }

        return [
            'source_type' => $transfer === self::TRANSFER_BASE64 ? 'multiple_image_base64' : 'multiple_image_urls',
            'items' => $items,
        ];
    }

    /**
     * content_type + data for one image. The type is sniffed from the bytes
     * (a .png that is really a WebP is refused here, not by Pinterest).
     *
     * @return array{content_type: string, data: string}
     *
     * @throws PermanentException When the image is not a JPEG or PNG (MediaRejected).
     */
    protected function base64Fields(Media $image): array
    {
        $bytes = $this->localBytes($image);
        $mime = $this->base64Mime($image, $bytes);

        if ($mime === null) {
            throw new PermanentException(
                'Pinterest can only receive JPG or PNG bytes; host this image publicly or convert it.',
                Platform::Pinterest,
                FailureReason::MediaRejected,
                ['media' => basename((string) (parse_url($image->source, PHP_URL_PATH) ?: $image->source))],
            );
        }

        return ['content_type' => $mime, 'data' => base64_encode($bytes)];
    }

    /** image/jpeg or image/png from the file signature, else from the extension; null otherwise. */
    protected function base64Mime(Media $image, ?string $bytes = null): ?string
    {
        if ($bytes !== null && $bytes !== '') {
            if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
                return 'image/png';
            }

            if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
                return 'image/jpeg';
            }

            if (str_starts_with($bytes, 'GIF8') || (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP')) {
                return null;
            }
        }

        return match ($image->extension()) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => null,
        };
    }

    /**
     * cover_image_url → url, cover_image_data → base64, else key_frame.
     *
     * @param  array<string, mixed>  $body
     */
    protected function coverKind(array $body): string
    {
        $source = is_array($body['media_source'] ?? null) ? $body['media_source'] : [];

        return match (true) {
            isset($source['cover_image_url']) => 'url',
            isset($source['cover_image_data']) => 'base64',
            default => 'key_frame',
        };
    }

    // --- Image transfer -----------------------------------------------------------------

    /** PinterestOptions::$imageTransfer, else config api.pinterest.image_transfer, else Auto. */
    protected function imageTransferMode(PreparedPost $post): PinterestImageTransfer
    {
        $option = $this->options($post)?->imageTransfer;

        if ($option !== null) {
            return $option;
        }

        $configured = $this->apiConfig('image_transfer', PinterestImageTransfer::Auto->value);

        if ($configured instanceof PinterestImageTransfer) {
            return $configured;
        }

        return PinterestImageTransfer::tryFrom(strtolower(trim((string) $configured))) ?? PinterestImageTransfer::Auto;
    }

    /**
     * 'url' or 'base64' for these images (never mixed).
     *
     * @param  list<Media>  $images
     *
     * @throws PermanentException When the mode cannot be honoured (MediaRejected / Misconfigured).
     */
    protected function transferFor(PreparedPost $post, array $images): string
    {
        $mode = $this->imageTransferMode($post);

        if ($mode !== PinterestImageTransfer::Base64) {
            $allUrls = true;

            foreach ($images as $image) {
                $allUrls = $allUrls && $this->canUrl($image);
            }

            if ($allUrls) {
                return self::TRANSFER_URL;
            }

            if ($mode === PinterestImageTransfer::Url) {
                throw new PermanentException(
                    'Pinterest is set to fetch images by URL, but an image is a local file: host it publicly, bind a MediaGateway that re-hosts local files (FilesystemMediaGateway), or use PinterestImageTransfer::Base64.',
                    Platform::Pinterest,
                    FailureReason::MediaRejected,
                );
            }
        }

        foreach ($images as $image) {
            if (! in_array($image->extension(), self::BASE64_EXTENSIONS, true)) {
                throw new PermanentException(
                    'Pinterest can only receive JPG or PNG bytes; host this .'.$image->extension().' publicly or convert it.',
                    Platform::Pinterest,
                    FailureReason::MediaRejected,
                );
            }

            $this->assertReadable($image);
        }

        return self::TRANSFER_BASE64;
    }

    /** Whether Pinterest can fetch this media by URL: the gateway decides; without one, remote media can. */
    protected function canUrl(Media $media): bool
    {
        $gateway = $this->gateway();

        return $gateway !== null ? $gateway->canProvideUrl($media) : $media->isRemote();
    }

    /**
     * Auto mode only, once: a URL image Pin that Pinterest could not fetch (a
     * fetch error, or a 5xx from its ~7 s create deadline, which includes the
     * fetch) is retried with base64, when every image is a JPEG or PNG and they
     * total at most base64_max_bytes. createPin() sends it at once after a
     * fetch error, and only after a board lookup after a 5xx.
     *
     * @param  array<string, mixed>  $state
     * @param  Response  $response
     */
    protected function shouldFallBackToBase64(PreparedPost $post, string $kind, array $state, $response): bool
    {
        if ($kind === self::KIND_VIDEO
            || ($state['transfer'] ?? null) !== self::TRANSFER_URL
            || ($state['fell_back'] ?? false) === true
            || $this->imageTransferMode($post) !== PinterestImageTransfer::Auto) {
            return false;
        }

        $body = $this->jsonBody($response);
        $code = is_numeric($body['code'] ?? null) ? (int) $body['code'] : null;
        $status = (int) $response->status();

        $fetchFailed = $status >= 500
            || $this->isFetchError(strtolower((string) $this->pinterestMessage($body)))
            || in_array($code, self::FETCH_TIMEOUT_CODES, true);

        if (! $fetchFailed) {
            return false;
        }

        $total = 0;
        $cap = max(0, (int) $this->apiConfig('base64_max_bytes', 20971520));

        foreach ($this->imagesOf($post) as $image) {
            if (! in_array($image->extension(), self::BASE64_EXTENSIONS, true)
                || ($image->isRemote() && $this->gateway() === null)) {
                return false;
            }

            try {
                $size = @filesize($this->localPath($image));
            } catch (Throwable) {
                return false;
            }

            if ($size === false) {
                return false;
            }

            $total += $size;

            if ($total > $cap) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remote media is read through the gateway (SSRF-checked download); without
     * one there is no safe way to fetch it.
     *
     * @throws PermanentException Misconfigured.
     */
    protected function assertReadable(Media $media): void
    {
        if ($media->isRemote() && $this->gateway() === null) {
            throw new PermanentException(
                'Sending this image as base64 means downloading it, which needs a MediaGateway (the default LocalMediaGateway downloads remote media).',
                Platform::Pinterest,
                FailureReason::Misconfigured,
            );
        }
    }

    /** The local file of a media item, downloaded at most once per run. */
    protected function localPath(Media $media): string
    {
        $this->localPaths ??= new WeakMap;

        if (! isset($this->localPaths[$media])) {
            $this->assertReadable($media);
            $this->localPaths[$media] = $this->mediaPath($media);
        }

        return $this->localPaths[$media];
    }

    /** @throws PermanentException When the file cannot be read (MediaRejected). */
    protected function localBytes(Media $media): string
    {
        $path = $this->localPath($media);
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw new PermanentException('The media file could not be read.', Platform::Pinterest, FailureReason::MediaRejected, ['path' => basename($path)]);
        }

        return $bytes;
    }

    /** Remove the gateway's temp files and forget the paths read during this run. */
    protected function releaseMedia(): void
    {
        $this->localPaths = null;

        try {
            $this->gateway()?->cleanup();
        } catch (Throwable) {
            // Cleanup must never mask the real outcome.
        }
    }

    // --- Text ---------------------------------------------------------------------------

    protected function pinTitle(PreparedPost $post): ?string
    {
        $title = trim((string) $post->title());

        return $title !== '' ? $title : null;
    }

    /** The trimmed caption, sent verbatim (URLs and hashtags included). */
    protected function pinDescription(PreparedPost $post): ?string
    {
        $description = trim((string) $post->caption());

        return $description !== '' ? $description : null;
    }

    /**
     * The 'title' or 'description' the create sends: the post's own, else
     * PinterestOptions::$extra's (pinBody() lets extra fill a field the post
     * leaves blank). The verify lookup compares these, never the post's alone.
     */
    protected function sentText(PreparedPost $post, string $field): ?string
    {
        $own = $field === 'title' ? $this->pinTitle($post) : $this->pinDescription($post);

        if ($own !== null) {
            return $own;
        }

        $extra = $this->extraPayload($post)[$field] ?? null;

        return is_string($extra) || is_int($extra) || is_float($extra) ? (string) $extra : null;
    }

    /**
     * PinterestOptions::$link, else (unless linkFromCaption is off) the
     * caption's first http(s) URL when it fits the 2048-character limit.
     */
    protected function resolveLink(PreparedPost $post): ?string
    {
        $options = $this->options($post);
        $explicit = $options?->link !== null ? trim($options->link) : '';

        if ($explicit !== '') {
            return $explicit;
        }

        if ($options !== null && ! $options->linkFromCaption) {
            return null;
        }

        $link = self::firstLink((string) $post->caption());

        return $link !== null && strlen($link) <= self::MAX_LINK ? $link : null;
    }

    /** PinterestOptions::$altText, else the first media item's alt text. */
    protected function altText(PreparedPost $post): ?string
    {
        $explicit = trim((string) $this->options($post)?->altText);

        if ($explicit !== '') {
            return $explicit;
        }

        $first = array_values($post->media())[0] ?? null;
        $alt = trim((string) $first?->altText);

        return $alt !== '' ? $alt : null;
    }

    /**
     * The first http(s) URL of a text, as production derives the Pin link:
     * trailing punctuation dropped, and one closing parenthesis too when the URL
     * has more ')' than '(' (a link written in parentheses).
     */
    protected static function firstLink(string $text): ?string
    {
        if (preg_match('~\bhttps?://[^\s<>"]+~iu', $text, $match) !== 1) {
            return null;
        }

        $url = rtrim($match[0], '.,;:!?\'"');

        if (str_ends_with($url, ')') && substr_count($url, '(') < substr_count($url, ')')) {
            $url = substr($url, 0, -1);
        }

        return $url;
    }

    // --- Results ------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $payload
     */
    protected function publishedPin(PreparedPost $post, array $state, string $id, array $payload = []): Published
    {
        $kind = (string) ($state['kind'] ?? self::KIND_IMAGE);

        $base = array_filter([
            'board_id' => $this->boardId($post),
            'board_section_id' => $this->boardSectionId($post),
            'kind' => $kind,
            'media_id' => $state['media_id'] ?? null,
            'link' => $this->resolveLink($post),
            'transfer' => $kind === self::KIND_VIDEO ? 'upload' : ($state['transfer'] ?? null),
            'sandbox' => $this->credentialString($post->credentials, 'api_base') === null && $this->usesSandbox($post) ? true : null,
        ], static fn ($value) => $value !== null && $value !== '');

        return $this->published(PostResult::success(
            Platform::Pinterest,
            $id,
            $this->permalink($id),
            $payload + $base,
            PostResult::STATE_PUBLISHED,
            'pin_id',
        ));
    }

    /** https://www.pinterest.com/pin/{id}/ for a numeric Pin id. */
    protected function permalink(string $id): ?string
    {
        return preg_match('/^\d+$/', $id) === 1 ? self::PERMALINK.$id.'/' : null;
    }

    // --- Errors -------------------------------------------------------------------------

    /**
     * Pinterest errors are a top-level {code, message}; S3 answers the upload
     * in XML. $step says which call failed ('pin', 'media_register',
     * 'media_status', 'upload', 'lookup', 'video_cover'); $section whether a
     * board section was targeted (for the 404 message).
     *
     * @param  Response  $response
     */
    protected function classifyError($response, string $step = self::STEP_PIN, bool $section = false): TemporaryException|PermanentException
    {
        if ($step === self::STEP_UPLOAD) {
            return $this->classifyUploadError($response);
        }

        $status = (int) $response->status();
        $body = $this->jsonBody($response);
        $code = is_numeric($body['code'] ?? null) ? (int) $body['code'] : null;
        $message = $this->pinterestMessage($body);
        $lower = strtolower((string) $message);
        $detail = $message !== null ? ' Pinterest said: '.rtrim($message, '.').'.' : '';
        $context = ['status' => $status, 'step' => $step] + ($code !== null ? ['pinterest_code' => $code] : []);

        if ($status === 401) {
            if ($step === self::STEP_VIDEO_COVER) {
                return new PermanentException(
                    'Pinterest refused the video Pin (HTTP 401) although the token works; give the video a cover image (PinterestOptions::$thumbnail or Media::video(thumbnail:)) and post it again.'.$detail,
                    Platform::Pinterest,
                    FailureReason::MediaRejected,
                    $context + ['cover_required' => true],
                );
            }

            return new PermanentException(
                'Pinterest rejected the access token; the account must be reconnected.'.$detail,
                Platform::Pinterest,
                FailureReason::InvalidToken,
                $context,
            );
        }

        if ($status === 403 && $code === 29) {
            return new PermanentException(
                'This Pinterest app only has Trial access, which can only publish to the Sandbox: set social.api.pinterest.sandbox (or the connection\'s sandbox credential) and use a Sandbox token, or request Standard access.'.$detail,
                Platform::Pinterest,
                FailureReason::InsufficientPermissions,
                $context + ['trial_access' => true],
            );
        }

        if ($status === 404 && $step === self::STEP_MEDIA_STATUS) {
            return new PermanentException(
                'Pinterest no longer knows this video upload; publish it again.'.$detail,
                Platform::Pinterest,
                FailureReason::MediaRejected,
                $context + ['upload_not_found' => true],
            );
        }

        if (($status === 404 && in_array($step, [self::STEP_PIN, self::STEP_LOOKUP, self::STEP_VIDEO_COVER], true))
            || str_contains($lower, 'board not found')
            || str_contains($lower, 'section not found')) {
            $target = $section ? 'board or board section' : 'board';

            return new PermanentException(
                "The Pinterest {$target} no longer exists, or this account can no longer save to it; pick another one or reconnect the account.".$detail,
                Platform::Pinterest,
                FailureReason::NotFound,
                $context + ['target_not_found' => true],
            );
        }

        if (str_contains($lower, 'not permitted')) {
            return new PermanentException(
                'Pinterest does not let this account save Pins there.'.$detail,
                Platform::Pinterest,
                FailureReason::InsufficientPermissions,
                $context,
            );
        }

        if ($status === 429) {
            return new TemporaryException(
                'Pinterest rate limit reached.'.$detail,
                Platform::Pinterest,
                FailureReason::RateLimited,
                $this->retryAfterHeader($response) ?? 300,
                $context,
            );
        }

        if ($status >= 500) {
            // Never under a minute: a create retried after a 5xx looks the
            // board up first, and a new Pin may take that long to be listed.
            return new TemporaryException(
                "Pinterest is temporarily unavailable (HTTP {$status}).".$detail,
                Platform::Pinterest,
                FailureReason::ServerError,
                max(60, $this->retryAfterHeader($response) ?? 60),
                $context,
            );
        }

        if (str_contains($lower, 'same width/height ratio') || (str_contains($lower, 'same') && str_contains($lower, 'aspect ratio'))) {
            // A carousel whose images do not share one shape: nothing was created.
            return new PermanentException(
                'Every image of a Pinterest carousel must have the same width/height ratio; crop them to one shape and post again.'.$detail,
                Platform::Pinterest,
                FailureReason::MediaRejected,
                $context + ['carousel_ratio_mismatch' => true],
            );
        }

        if ($this->isFetchError($lower)) {
            return new TemporaryException(
                'Pinterest could not fetch the image.'.$detail,
                Platform::Pinterest,
                FailureReason::Timeout,
                120,
                $context + ['fetch_failed' => true],
            );
        }

        if ($status === 403 && $step === self::STEP_PIN) {
            // pins/create documents 403 as "the Pin's image is too small, too large or is broken".
            return $this->mentionsPermission($lower)
                ? new PermanentException('Pinterest refused the Pin: the token lacks a permission for this board.'.$detail, Platform::Pinterest, FailureReason::InsufficientPermissions, $context)
                : new PermanentException('Pinterest refused the Pin\'s media: the image is too small, too large or broken.'.$detail, Platform::Pinterest, FailureReason::MediaRejected, $context);
        }

        return parent::classifyError($response);
    }

    /**
     * S3's XML errors from the video upload. The POST policy may have expired
     * or the storage hiccupped: Temporary, and the retried publish() registers
     * again for a fresh policy. A file S3 refuses for its size is MediaRejected.
     *
     * @param  Response  $response
     */
    protected function classifyUploadError($response): TemporaryException|PermanentException
    {
        $status = (int) $response->status();
        $xml = (string) $response->body();
        $code = preg_match('~<Code>\s*([^<]+?)\s*</Code>~i', $xml, $m) === 1 ? $m[1] : null;
        $message = preg_match('~<Message>\s*([^<]+?)\s*</Message>~i', $xml, $m) === 1 ? $m[1] : null;
        $label = trim(($code ?? "HTTP {$status}").($message !== null ? ": {$message}" : ''));
        $context = ['status' => $status, 'step' => self::STEP_UPLOAD] + ($code !== null ? ['s3_code' => $code] : []);

        if ($code !== null && in_array($code, self::S3_MEDIA, true)) {
            return new PermanentException(
                "Pinterest's upload storage refused the video file ({$label}).",
                Platform::Pinterest,
                FailureReason::MediaRejected,
                $context,
            );
        }

        if (($code !== null && in_array($code, self::S3_RETRYABLE, true)) || $status >= 500 || $status === 408 || $status === 429) {
            return new TemporaryException(
                "Pinterest's upload storage did not accept the video ({$label}); it will be registered and uploaded again.",
                Platform::Pinterest,
                FailureReason::ServerError,
                120,
                $context,
            );
        }

        return new PermanentException(
            "Pinterest's upload storage refused the video ({$label}).",
            Platform::Pinterest,
            FailureReason::Unknown,
            $context,
        );
    }

    /**
     * Pinterest's message: top-level message, else error.message / a string error.
     *
     * @param  array<mixed>  $body
     */
    protected function pinterestMessage(array $body): ?string
    {
        foreach ([$body['message'] ?? null, $body['error']['message'] ?? null, $body['error'] ?? null, $body['error_description'] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** Pinterest could not download an image URL (not a bare "download", which matches unrelated errors). */
    protected function isFetchError(string $lower): bool
    {
        foreach (['could not fetch', "couldn't fetch", 'couldn’t fetch', 'unable to reach'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function mentionsPermission(string $lower): bool
    {
        foreach (['permission', 'not allowed', 'not authorized', 'unauthorized', 'scope'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Retry-After header only, in seconds. Pinterest's x-ratelimit-reset
     * comes on ordinary responses too and counts down a quota window (often
     * 1 s), so it is not a wait: reading it would retry a rate limit or an
     * outage within a second.
     *
     * @see https://developers.pinterest.com/docs/reference/rate-limits/
     *
     * @param  Response  $response
     */
    protected function retryAfterHeader($response): ?int
    {
        try {
            $value = $response->header('Retry-After');
        } catch (Throwable) {
            return null;
        }

        return is_string($value) && trim($value) !== '' ? RetryAfter::parseRetryAfter($value) : null;
    }

    /**
     * @param  Response  $response
     * @return array<mixed>
     */
    protected function jsonBody($response): array
    {
        try {
            $json = $response->json();
        } catch (Throwable) {
            return [];
        }

        return is_array($json) ? $json : [];
    }

    // --- Settings & small helpers -------------------------------------------------------

    protected function options(PreparedPost $post): ?PinterestOptions
    {
        return $this->optionsOf($post, PinterestOptions::class);
    }

    /**
     * The S3 POST runs inside one job run, so its timeout is
     * api.pinterest.upload_timeout (default social.upload_timeout, 300 s).
     * When social.job_timeout is set explicitly, the upload never outlives it:
     * it is capped a minute below (room for the register call). Unset, the job
     * derives its timeout from the platform's upload_timeout.
     */
    protected function uploadTimeout(): int
    {
        $configured = $this->apiConfig('upload_timeout');
        $timeout = is_numeric($configured) ? (int) $configured : (int) config('social.upload_timeout', 300);
        $jobTimeout = config('social.job_timeout');

        if (is_numeric($jobTimeout) && (int) $jobTimeout > 0) {
            $timeout = min($timeout, (int) $jobTimeout - 60);
        }

        return max(30, $timeout);
    }

    /** POST /pins (production uses 60 s; Pinterest itself gives up after ~7 s). */
    protected function pinTimeout(): int
    {
        return max(10, (int) $this->apiConfig('pin_timeout', 60));
    }

    protected function dedupeEnabled(): bool
    {
        return (bool) filter_var($this->apiConfig('dedupe_on_timeout', true), FILTER_VALIDATE_BOOLEAN);
    }

    protected function verifyRetries(): int
    {
        return max(0, (int) $this->apiConfig('verify_retries', 5));
    }

    protected function verifyDelay(): int
    {
        return $this->clampDelay((int) $this->apiConfig('verify_after', 60));
    }

    protected function videoMaxWait(): int
    {
        return max(60, (int) $this->apiConfig('video_max_wait', 1800));
    }

    /**
     * The deadline a create or verify step declares (unix time). The job
     * applies the last declared deadline to every later step, so without one
     * of its own a step after the video would inherit video_max_wait: a video
     * processed on the last look would never be pinned, and a verify after an
     * ambiguous create would end as a clean-looking timeout although the Pin
     * may be live. The steps are bounded by verify_retries; this only has to
     * outlast their waits: (verify_retries + 1) × (verify_after + pin_timeout)
     * + 5 minutes, from now.
     */
    protected function commitDeadline(): int
    {
        return $this->currentTime() + ($this->verifyRetries() + 1) * ($this->verifyDelay() + $this->pinTimeout()) + 300;
    }

    protected function currentTime(): int
    {
        return Carbon::now()->getTimestamp();
    }

    /** A Pinterest date-time (ISO 8601; UTC when it carries no offset) as unix time, or null. */
    protected function timestampOf(mixed $value): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, 'UTC')->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    protected function normaliseText(mixed $value): string
    {
        return is_string($value) ? trim(str_replace(["\r\n", "\r"], "\n", $value)) : '';
    }

    /** @param  array<string, mixed>  $state */
    protected function stateKind(PreparedPost $post, array $state): string
    {
        $kind = $state['kind'] ?? null;

        return is_string($kind) && in_array($kind, [self::KIND_IMAGE, self::KIND_CAROUSEL, self::KIND_VIDEO], true) ? $kind : $this->kindOf($post);
    }

    /**
     * A required string from the continuation state.
     *
     * @param  array<string, mixed>  $state
     */
    protected function stateString(array $state, string $key): string
    {
        $value = $state[$key] ?? null;

        if ((is_string($value) || is_int($value)) && trim((string) $value) !== '') {
            return trim((string) $value);
        }

        throw new PermanentException(
            "The Pinterest continuation has no {$key}.",
            Platform::Pinterest,
            FailureReason::Unknown,
            ['phase' => $state['phase'] ?? null, 'missing_field' => $key],
        );
    }

    /** A credential as a trimmed non-blank string, else null. */
    protected function credentialString(Credentials $credentials, string $key): ?string
    {
        $value = $credentials->get($key);

        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function missingImage(): never
    {
        throw new PermanentException('A Pin needs one image, a carousel of 2 to 5 images, or one video.', Platform::Pinterest, FailureReason::MediaRejected);
    }
}
