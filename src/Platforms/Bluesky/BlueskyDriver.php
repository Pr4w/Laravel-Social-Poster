<?php

namespace SocialPoster\Platforms\Bluesky;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\MessageBag;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\CommentRules;
use SocialPoster\Capabilities\ImageRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Capabilities\VideoRules;
use SocialPoster\Contracts\AuthorizesRequests;
use SocialPoster\Contracts\SupportsFirstComment;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\MediaType;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\Support\PublicUrl;
use SocialPoster\Support\RetryAfter;
use SocialPoster\Support\Text\GraphemeCounter;
use SocialPoster\Support\Text\Text;
use SocialPoster\ValueObjects\CommentResult;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\PublishOutcome;
use SocialPoster\ValueObjects\SocialPost;
use Throwable;

/**
 * Bluesky (AT Protocol). Posts are app.bsky.feed.post records written to the
 * account's own PDS (credential 'pds', else resolved from the DID document)
 * in the repo named by the credential 'did'.
 *
 * Auth goes through a request authorizer: config('social.authorizers.bluesky')
 * when the app binds one (e.g. a laravel-social-tokens delegate that renews
 * tokens on demand), else DefaultBlueskyAuthorizer, which signs OAuth DPoP
 * requests itself whenever the credentials carry a dpop_private_key, and also
 * takes a bearer access_jwt or (opt-in) an app password.
 *
 * Flow:
 * - publish() prepares everything that is safe to repeat: the record key (a
 *   TID), the text and its facets (mentions resolved), the thread root of a
 *   replyTo, the link card. A video is uploaded to video.bsky.app and polled
 *   (await_video).
 * - The create is its own commit phase: images are uploaded and the record
 *   written in the same step (the PDS garbage-collects blobs no record
 *   references), with createdAt stamped then. The record key is fixed in the
 *   state, so a repeat can never make a second post: a recovering run (or a
 *   failed create) looks the record up with getRecord first, and a create on
 *   an existing key is refused by the PDS. A failure before the create request
 *   is sent is flagged possibly_published = false. Reply/quote gates are
 *   written atomically with applyWrites.
 * - A blob that expired before the create (BlobNotFound) restarts the publish.
 *
 * Text: 300 graphemes and 3000 UTF-8 bytes, links counted in full. Media: up
 * to 4 images (jpg/png/webp, 1,000,000 bytes each), or one mp4 video
 * (100,000,000 bytes / 180 s by default; bsky.social allows 300 MB / 10 min,
 * raise video_max_bytes / video_max_duration to use them). Never both.
 *
 * The first comment is a reply (SupportsFirstComment) with strong refs to the
 * post and its thread root.
 *
 * @see https://docs.bsky.app/docs/advanced-guides/posts
 */
class BlueskyDriver extends AbstractPlatform implements SupportsFirstComment
{
    public const POST_COLLECTION = 'app.bsky.feed.post';

    public const MAX_GRAPHEMES = 300;

    public const MAX_BYTES = 3000;

    public const MAX_IMAGES = 4;

    /** XRPC error codes that mean the token or session is not accepted. */
    protected const AUTH_CODES = ['ExpiredToken', 'InvalidToken', 'AuthenticationRequired', 'AuthMissing', 'AuthFactorTokenRequired', 'BadJwt', 'BadJwtSignature'];

    /** XRPC error codes that mean the account itself is not allowed to post. */
    protected const ACCOUNT_CODES = ['AccountTakedown', 'AccountDeactivated', 'RepoTakendown', 'RepoDeactivated', 'RepoSuspended'];

    /** XRPC error codes for a transient upstream problem. */
    protected const SERVER_CODES = ['UpstreamFailure', 'UpstreamTimeout', 'NotEnoughResources', 'InternalServerError'];

    /** Converters that ran during this publish (cleaned up afterwards). */
    private array $transformers = [];

    public function platform(): Platform
    {
        return Platform::Bluesky;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            caption: $this->captionRules(),
            media: new MediaRules(minCount: 0, maxCount: self::MAX_IMAGES, image: $this->imageRules(), video: $this->videoRules()),
        );
    }

    /** One video alone, or up to 4 images (shown as a grid, not a carousel). */
    protected function rulesFor(PreparedPost $post): Capabilities
    {
        return new Capabilities(
            caption: $this->captionRules(),
            media: $this->videoOf($post) !== null
                ? new MediaRules(minCount: 0, maxCount: 1, image: $this->imageRules(), video: $this->videoRules())
                : new MediaRules(minCount: 0, maxCount: self::MAX_IMAGES, image: $this->imageRules()),
        );
    }

    protected function captionRules(): CaptionRules
    {
        return new CaptionRules(min: 0, max: self::MAX_GRAPHEMES, counter: new GraphemeCounter, maxBytes: self::MAX_BYTES);
    }

    /**
     * The images embed accepts jpg/png/webp blobs of image_max_bytes (decimal
     * 1,000,000 by default, the production-proven value; the current lexicon
     * allows 2,000,000). HEIC/AVIF/BMP/TIFF pass when a converting transformer
     * is bound. GIFs are refused with their own message (they would not animate).
     */
    protected function imageRules(): ImageRules
    {
        return new ImageRules(
            extensions: ['jpg', 'jpeg', 'png', 'webp'],
            types: ['image/jpeg', 'image/png', 'image/webp'],
            maxSizeBytes: (int) $this->apiConfig('image_max_bytes', 1_000_000),
            convert: ['heic' => 'jpg', 'heif' => 'jpg', 'avif' => 'jpg', 'bmp' => 'jpg', 'tif' => 'jpg', 'tiff' => 'jpg'],
        );
    }

    protected function videoRules(): VideoRules
    {
        return new VideoRules(
            extensions: ['mp4'],
            types: ['video/mp4'],
            maxSizeBytes: (int) $this->apiConfig('video_max_bytes', 100_000_000),
            minDuration: 1,
            maxDuration: (float) $this->apiConfig('video_max_duration', 180),
        );
    }

    /** The grapheme and byte limits are checked in validatePlatformRules(), on the text as sent. */
    protected function validateCaption(?string $caption, Capabilities $caps, MessageBag $errors): void
    {
        //
    }

    /** A GIF gets one clear message instead of the generic "unsupported type". */
    protected function validateMediaItem(int $index, Media $media, Capabilities $caps, MessageBag $errors): void
    {
        if ($media->type === MediaType::Image && $media->extension() === 'gif') {
            $errors->add("media.{$index}", "Bluesky doesn't animate GIF images: convert the GIF to an MP4 video (BlueskyOptions videoPresentation 'gif' makes it loop like one).");

            return;
        }

        parent::validateMediaItem($index, $media, $caps, $errors);
    }

    protected function validatePlatformRules(PreparedPost $post, MessageBag $errors): void
    {
        $options = $this->options($post);
        $text = BlueskyRichText::displayText(trim((string) $post->caption()), $options !== null && $options->shortenLinks);

        $this->validateText('caption', 'Caption', $text, self::MAX_GRAPHEMES, new GraphemeCounter, $errors, 0, self::MAX_BYTES);

        $media = $post->media();
        $videos = count(array_filter($media, static fn (Media $item) => $item->type === MediaType::Video));

        if (trim($text) === '' && $media === [] && $options?->quote === null) {
            $errors->add('caption', 'Bluesky posts without media should at least contain text.');
        }

        if ($videos > 0 && count($media) > $videos) {
            $errors->add('media', 'A Bluesky post can have one video, or up to 4 images, not both.');
        }

        $altMax = (int) $this->apiConfig('alt_text_max', 2000);

        foreach ($media as $index => $item) {
            if ($item->altText !== null && Text::graphemeCount(trim($item->altText)) > $altMax) {
                $errors->add("media.{$index}", "Bluesky alt text is limited to {$altMax} characters.");
            }
        }

        if ($options?->linkCard !== null && $media !== []) {
            $errors->add('linkCard', "A link card can't be combined with images or a video.");
        } elseif ($options?->linkCard !== null && $this->quoteEmbed($options) !== null) {
            // A post has one embed: the quote would win and the card be dropped.
            $errors->add('linkCard', "A link card can't be combined with a quoted post.");
        }

        if ($options !== null && $options->videoCaptions !== [] && $videos === 0) {
            $errors->add('videoCaptions', 'Video captions need a video in the post.');
        }

        $credentials = $post->credentials;

        if (! $this->usesCustomAuthorizer()
            && $credentials->filled('access_token')
            && ! $credentials->filled('dpop_private_key')
            && ! $credentials->filled('access_jwt')
            && strtolower((string) $credentials->get('token_type')) === 'dpop') {
            $errors->add('credentials.dpop_private_key', 'This Bluesky access token is DPoP-bound (token_type DPoP): pass the dpop_private_key it was issued for.');
        }
    }

    // --- Credentials ----------------------------------------------------------------

    /**
     * 'did' always; a token or app password unless the app bound its own
     * authorizer (which then decides what it needs, e.g. a connection id).
     */
    protected function requiredCredentials(): array
    {
        $required = ['did' => 'the account DID (did:plc:… or did:web:…), the repo posts are written to'];

        if (! $this->usesCustomAuthorizer()) {
            $required['access_token|access_jwt|app_password'] = 'an OAuth access_token (with dpop_private_key when DPoP-bound), a session access_jwt, or identifier + app_password';
        }

        return $required;
    }

    protected function identityKeys(): array
    {
        return ['did'];
    }

    /**
     * Every atproto OAuth grant includes 'atproto'. The write permission itself
     * is 'transition:generic' or a granular repo:… scope whose spelling varies,
     * so only 'atproto' is declared: a stricter list would refuse valid grants.
     */
    protected function requiredScopes(Feature $feature, Credentials $credentials): array
    {
        return match ($feature) {
            Feature::Publish, Feature::Comment => [['atproto']],
            default => [],
        };
    }

    /**
     * Requests to the PDS: the configured authorizer when the app bound one,
     * else DefaultBlueskyAuthorizer (DPoP when the credentials carry a key,
     * bearer, or app-password session). Never used for video.bsky.app.
     *
     * @return PendingRequest
     */
    protected function http(PreparedPost $post)
    {
        if ($this->authorizerConfigured()) {
            return parent::http($post)->acceptJson();
        }

        return $this->defaultAuthorizer()
            ->request(Platform::Bluesky, $post->credentials)
            ->connectTimeout((int) $this->apiConfig('connect_timeout', 10))
            ->timeout((int) $this->apiConfig('timeout', config('social.http_timeout', 60)))
            ->acceptJson();
    }

    protected function defaultAuthorizer(): AuthorizesRequests
    {
        return app(DefaultBlueskyAuthorizer::class);
    }

    protected function authorizerConfigured(): bool
    {
        $configured = config('social.authorizers.bluesky');

        return $configured !== null && $configured !== '' && $configured !== false;
    }

    /** True when the app bound an authorizer other than DefaultBlueskyAuthorizer. */
    protected function usesCustomAuthorizer(): bool
    {
        $configured = config('social.authorizers.bluesky');

        if (is_object($configured)) {
            return ! $configured instanceof DefaultBlueskyAuthorizer;
        }

        return is_string($configured) && $configured !== '' && ! is_a($configured, DefaultBlueskyAuthorizer::class, true);
    }

    // --- Publish ----------------------------------------------------------------------

    public function publish(PreparedPost $post): PublishOutcome
    {
        $this->assertCredentials($post);
        $this->did($post);

        $options = $this->options($post);
        $caption = trim((string) $post->caption());

        try {
            $rich = $this->richText()->build($caption, $options);

            // No createdAt here: recordFor() stamps it when the record is
            // written, since feeds sort by it (min of indexedAt and createdAt).
            $state = [
                'phase' => 'create',
                'rkey' => Tid::next(),
                'text' => $rich['text'],
                'facets' => $rich['facets'],
            ];

            $root = $this->threadRoot($post, $options);

            if ($root !== null) {
                $state['reply_root'] = $root;
            }

            $video = $this->videoOf($post);

            if ($video !== null) {
                return $this->startVideo($post, $video, $state);
            }

            if ($this->imagesOf($post) === [] && $this->quoteEmbed($options) === null) {
                $card = $this->linkCard($post, $options, $caption);

                if ($card !== null) {
                    $state['embed'] = $card;
                }
            }

            return $this->commit($state);
        } finally {
            $this->releaseMedia();
        }
    }

    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        $this->assertCredentials($post);

        $phase = is_string($state['phase'] ?? null) ? $state['phase'] : '';
        $recovering = Pending::isRecovering($state);
        $restarts = (int) ($state[Pending::RESTARTS] ?? 0);
        $clean = Pending::withoutReservedKeys($state);

        return match ($phase) {
            'create' => $this->createPost($post, $clean, $recovering, $restarts),
            // Spec name of the explicit verify step: same as a recovering create.
            'verify_post' => $this->createPost($post, ['phase' => 'create'] + $clean, true, $restarts),
            'await_video' => $this->awaitVideo($post, $clean),
            'restart' => $this->publish($post),
            default => throw new PermanentException(
                "Unknown Bluesky publish phase '{$phase}'.",
                Platform::Bluesky,
                FailureReason::Unknown,
                ['phase' => $phase],
            ),
        };
    }

    // --- Video ------------------------------------------------------------------------

    /**
     * Upload the video to the video service with a service token for the PDS
     * (aud did:web:<PDS host>, lxm uploadBlob). The service hands back a job, or
     * at once the blob when it already processed the same file (an
     * 'already_exists' error that still carries it: the blob decides, not the
     * HTTP status).
     *
     * @param  array<string, mixed>  $state
     */
    protected function startVideo(PreparedPost $post, Media $video, array $state): PublishOutcome
    {
        $did = $this->did($post);
        $aud = $this->pdsServiceDid($post); // first: no pre-flight while the PDS is unknown
        $this->assertCanUploadVideo($post);

        $token = $this->serviceToken($post, $aud, 'com.atproto.repo.uploadBlob');

        $response = $this->videoRequest($token)
            ->timeout((int) $this->apiConfig('upload_timeout', config('social.upload_timeout', 300)))
            ->withBody($this->stream($video), 'video/mp4')
            ->post($this->videoServiceUrl('app.bsky.video.uploadVideo').'?'.http_build_query([
                'did' => $did,
                'name' => $this->videoName($video),
            ]));

        $job = $this->jobStatus($response, false);

        if (! $this->filledArray($job['blob'] ?? null) && ! is_string($job['jobId'] ?? null) && ($job['state'] ?? null) !== 'JOB_STATE_FAILED') {
            if ($this->ok($response)) {
                $this->requireId($response, 'video upload job', 'jobId');
            }

            throw $this->mapError($response, 'video');
        }

        $state['deadline'] = time() + max(60, (int) $this->apiConfig('video_max_wait', 1800));

        return $this->videoProgress($state, $job);
    }

    /** @param  array<string, mixed>  $state */
    protected function awaitVideo(PreparedPost $post, array $state): PublishOutcome
    {
        $jobId = is_string($state['job_id'] ?? null) ? $state['job_id'] : '';

        if ($jobId === '') {
            throw new PermanentException('The Bluesky video continuation has no job id.', Platform::Bluesky, FailureReason::Unknown, ['phase' => 'await_video']);
        }

        $response = Http::acceptJson()
            ->connectTimeout((int) $this->apiConfig('connect_timeout', 10))
            ->timeout(30)
            ->get($this->videoServiceUrl('app.bsky.video.getJobStatus'), ['jobId' => $jobId]);

        if (! $this->ok($response)) {
            throw $this->mapError($response, 'video');
        }

        $job = $this->jobStatus($response, true);
        $job['jobId'] ??= $jobId;

        return $this->videoProgress($state, $job);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $job
     */
    protected function videoProgress(array $state, array $job): PublishOutcome
    {
        if ($this->filledArray($job['blob'] ?? null)) {
            unset($state['job_id'], $state['polls']);
            $state['phase'] = 'create';
            $state['video'] = ['blob' => $job['blob']];

            return $this->commit($state);
        }

        if (($job['state'] ?? null) === 'JOB_STATE_FAILED') {
            throw $this->videoJobFailed($job);
        }

        $polls = (int) ($state['polls'] ?? 0);
        $state['phase'] = 'await_video';
        $state['job_id'] = (string) ($job['jobId'] ?? $state['job_id'] ?? '');
        $state['polls'] = $polls + 1;

        $deadline = is_int($state['deadline'] ?? null) ? $state['deadline'] : null;

        return PublishOutcome::pending($state, $this->videoRecheck($polls, $job['progress'] ?? null), false, $deadline);
    }

    /** Poll every 5 s at first, then 10 s, then 30 s; 5 s again once nearly done. */
    protected function videoRecheck(int $polls, mixed $progress): int
    {
        if (is_numeric($progress) && (int) $progress >= 90) {
            return 5;
        }

        return match (true) {
            $polls < 6 => 5,
            $polls < 18 => 10,
            default => 30,
        };
    }

    /**
     * The JobStatus object: uploadVideo returns it at the top level,
     * getJobStatus wraps it in {jobStatus}.
     *
     * @return array<string, mixed>
     */
    protected function jobStatus($response, bool $wrapped): array
    {
        try {
            $json = $response->json();
        } catch (Throwable) {
            return [];
        }

        if (! is_array($json)) {
            return [];
        }

        if (is_array($json['jobStatus'] ?? null)) {
            return $json['jobStatus'];
        }

        return $wrapped ? [] : $json;
    }

    /**
     * Best-effort pre-flight (app.bsky.video.getUploadLimits, as the Bluesky app
     * does): an account that cannot upload now (daily limit, unverified email)
     * fails before the upload with a structured answer. Any problem with the
     * pre-flight itself is ignored; the upload then reports it.
     */
    protected function assertCanUploadVideo(PreparedPost $post): void
    {
        if (! filter_var($this->apiConfig('check_upload_limits', true), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        try {
            $auth = $this->http($post)->get($this->xrpc($post, 'com.atproto.server.getServiceAuth'), [
                'aud' => (string) $this->apiConfig('video_service_did', 'did:web:video.bsky.app'),
                'lxm' => 'app.bsky.video.getUploadLimits',
                'exp' => time() + 300,
            ]);

            $token = $auth->successful() ? $auth->json('token') : null;

            if (! is_string($token) || $token === '') {
                return;
            }

            $limits = $this->videoRequest($token)->timeout(30)->get($this->videoServiceUrl('app.bsky.video.getUploadLimits'));
        } catch (Throwable) {
            return;
        }

        if (! $limits->successful() || $limits->json('canUpload') !== false) {
            return;
        }

        $message = $limits->json('message') ?? $limits->json('error');
        $message = is_string($message) ? $message : null;

        throw $this->videoRefusal($message, $limits, ['upload_limits' => true])
            ?? $this->dailyVideoLimit($message, $limits, ['upload_limits' => true]);
    }

    protected function serviceToken(PreparedPost $post, string $aud, string $lxm, int $ttl = 1800): string
    {
        $response = $this->http($post)->get($this->xrpc($post, 'com.atproto.server.getServiceAuth'), [
            'aud' => $aud,
            'lxm' => $lxm,
            'exp' => time() + $ttl,
        ]);

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        return $this->requireId($response, 'service token', 'token');
    }

    /** video.bsky.app takes a plain bearer service token, never DPoP. */
    protected function videoRequest(?string $token = null): PendingRequest
    {
        $request = Http::acceptJson()
            ->connectTimeout((int) $this->apiConfig('connect_timeout', 10))
            ->timeout(60);

        return $token !== null ? $request->withToken($token) : $request;
    }

    protected function videoServiceUrl(string $nsid): string
    {
        return rtrim((string) $this->apiConfig('video_service', 'https://video.bsky.app'), '/').'/xrpc/'.$nsid;
    }

    /**
     * did:web of the real PDS host (a non-default port is %3A-encoded): the
     * service token's audience. Never the entryway, which the video service
     * does not accept: when the DID document cannot be read, the lookup
     * failure is thrown (a retry), not a token minted for an upload that
     * cannot work.
     */
    protected function pdsServiceDid(PreparedPost $post): string
    {
        $pds = $this->pds($post, false);
        $host = (string) parse_url($pds, PHP_URL_HOST);
        $port = parse_url($pds, PHP_URL_PORT);

        return 'did:web:'.$host.(is_int($port) ? '%3A'.$port : '');
    }

    protected function videoName(Media $video): string
    {
        $path = parse_url($video->source, PHP_URL_PATH);
        $name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', basename(is_string($path) ? $path : $video->source));
        $name = trim($name, '.-');

        if ($name === '') {
            $name = 'video';
        }

        return str_ends_with(strtolower($name), '.mp4') ? $name : $name.'.mp4';
    }

    /** @param  array<string, mixed>  $job */
    protected function videoJobFailed(array $job): PermanentException
    {
        $message = trim((string) ($job['message'] ?? $job['error'] ?? ''));
        $context = array_filter([
            'job_state' => 'JOB_STATE_FAILED',
            'job_id' => $job['jobId'] ?? null,
            'error' => $job['error'] ?? null,
            'failure_code' => $job['failureCode'] ?? null,
        ], static fn ($value) => $value !== null);

        $refusal = $this->videoRefusal($message !== '' ? $message : null, null, $context);

        if ($refusal !== null) {
            return $refusal;
        }

        $hint = ($job['failureCode'] ?? null) === 'validation_failure' ? ' Export it as an MP4 (H.264) and try again.' : '';

        return new PermanentException(
            'Bluesky could not process the video'.($message !== '' ? ' ('.rtrim($message, '.').')' : '').'.'.$hint,
            Platform::Bluesky,
            FailureReason::MediaRejected,
            $context,
        );
    }

    /**
     * Classify a video-service refusal by its message (the service does not
     * use specific codes): unverified email, daily quota, or a file the service
     * will not take. Null when the message says none of these.
     *
     * @param  array<string, mixed>  $context
     */
    protected function videoRefusal(?string $message, $response, array $context = []): ?PermanentException
    {
        $lower = strtolower((string) $message);

        if ($lower === '') {
            return null;
        }

        if (str_contains($lower, 'email')) {
            return new PermanentException(
                'Bluesky only lets accounts with a verified email post videos ('.rtrim((string) $message, '.').'). Verify the email in Bluesky (Settings → Account), then try again.',
                Platform::Bluesky,
                FailureReason::AccountRestricted,
                $context + ['email_unverified' => true],
            );
        }

        if (preg_match('/\b(daily|per day|today|quota|24 ?h)/', $lower) === 1) {
            return $this->dailyVideoLimit($message, $response, $context);
        }

        if (preg_match('/(too large|too big|too long|file size|exceeds the maximum|duration)/', $lower) === 1) {
            return new PermanentException(
                'Bluesky refused the video ('.rtrim((string) $message, '.').').',
                Platform::Bluesky,
                FailureReason::MediaRejected,
                $context,
            );
        }

        // An upload quota names what it counts. A bare "Rate Limit Exceeded" is
        // the ordinary short-window throttle: left to the 429 row (temporary).
        if (str_contains($lower, 'limit') && ! str_contains($lower, 'rate limit') && preg_match('/(video|bytes|upload)/', $lower) === 1) {
            return $this->dailyVideoLimit($message, $response, $context);
        }

        return null;
    }

    /**
     * The daily video quota (about 25 videos / 10 GB per day on bsky.social):
     * permanent, with a hint of when to try again, so the queue never waits.
     *
     * @param  array<string, mixed>  $context
     */
    protected function dailyVideoLimit(?string $message, $response, array $context = []): PermanentException
    {
        $wait = $response !== null ? RetryAfter::fromResponse($response) : null;
        $wait = $wait !== null && $wait > 0 ? $wait : 86400;

        return new PermanentException(
            "Bluesky's daily video limit is reached for this account".($message !== null && trim($message) !== '' ? ' ('.rtrim(trim($message), '.').')' : '').'; post the video later.',
            Platform::Bluesky,
            FailureReason::RateLimited,
            $context + [
                'daily_limit' => true,
                'retry_after_hint' => $wait,
                'retry_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $wait),
            ],
        );
    }

    // --- Create (commit phase) ----------------------------------------------------

    /**
     * Upload the images (and captions) and write the record under the state's
     * record key. When the job flags a possible repeat, the record is looked up
     * first; a failed create is looked up too before it is reported, since the
     * PDS refuses a second create on the same key.
     *
     * @param  array<string, mixed>  $state
     */
    protected function createPost(PreparedPost $post, array $state, bool $verifyFirst, int $restarts = 0): PublishOutcome
    {
        $did = $this->did($post);
        $rkey = is_string($state['rkey'] ?? null) ? $state['rkey'] : '';

        if (! Tid::isValid($rkey)) {
            throw new PermanentException('The Bluesky continuation has no valid record key.', Platform::Bluesky, FailureReason::Unknown, ['phase' => 'create']);
        }

        // The lookup proved the record (deterministic rkey) does not exist yet.
        $absent = false;

        if ($verifyFirst) {
            $existing = $this->findRecord($post, $did, self::POST_COLLECTION, $rkey);

            if ($existing !== null) {
                return $this->published($this->postResult($did, $rkey, $existing, ['recovered' => true]));
            }

            $absent = true;
        }

        $options = $this->options($post);
        $gated = $options !== null && $options->hasGates();

        try {
            // Downloads, blob uploads, the PDS lookup and an app-password
            // login all happen before the create is sent: a failure there
            // provably posted nothing, which the job must not call ambiguous.
            try {
                $record = $this->recordFor($post, $state, $this->embedFor($post, $state));
                [$request, $url, $body] = $this->writeRequest($post, $did, $rkey, $record);
            } catch (SocialPosterException $e) {
                throw $this->beforeCreate($e);
            } catch (ConnectionException $e) {
                throw $this->beforeCreate(TemporaryException::connection(Platform::Bluesky, $e));
            }

            $response = $request->post($url, $body);
        } finally {
            $this->releaseMedia();
        }

        if ($this->ok($response)) {
            $uriPath = $gated ? 'results.0.uri' : 'uri';
            $uri = $response->json($uriPath);

            if (! is_string($uri) || trim($uri) === '') {
                // Accepted without a uri: look before calling the outcome unknown.
                $existing = $this->findRecord($post, $did, self::POST_COLLECTION, $rkey, true);

                if ($existing !== null) {
                    return $this->published($this->postResult($did, $rkey, $existing, ['recovered' => true]));
                }

                $uri = $this->requireId($response, 'post', $uriPath, true);
            }

            return $this->published($this->postResult($did, $rkey, [
                'uri' => $uri,
                'cid' => $response->json($gated ? 'results.0.cid' : 'cid'),
                'validationStatus' => $response->json($gated ? 'results.0.validationStatus' : 'validationStatus'),
                'value' => $record,
            ]));
        }

        // A clear refusal after a lookup that found nothing: no post exists,
        // so the job may forget the key (or start over) instead of keeping a
        // possibly-published record. A 5xx may still have created it.
        $refusedForGood = $absent && $response->clientError();

        if ($this->isBlobNotFound($response)) {
            $error = $this->blobExpired($response, $restarts);

            throw $refusedForGood ? $error->withFlags(['possibly_published' => false]) : $error;
        }

        if ($this->mayAlreadyExist($response)) {
            $existing = $this->findRecord($post, $did, self::POST_COLLECTION, $rkey, true);

            if ($existing !== null) {
                return $this->published($this->postResult($did, $rkey, $existing, ['recovered' => true]));
            }
        }

        $error = $this->mapError($response);

        throw $refusedForGood ? $error->withFlags(['possibly_published' => false]) : $error;
    }

    /**
     * A failure inside the create phase but before the create request was
     * sent: nothing can have been posted. Flagged so the job neither calls the
     * post possibly published nor keeps its idempotency key as "may be live"
     * (a recovering run reaches this only after its lookup found no record).
     */
    protected function beforeCreate(SocialPosterException $e): SocialPosterException
    {
        return $e->withFlags(['possibly_published' => false, 'request_sent' => false]);
    }

    /**
     * The write request, ready to send: createRecord, or applyWrites when the
     * post carries a threadgate or a postgate (written on the same record
     * key, atomically). Building it may already talk to the network (PDS
     * lookup, app-password login); sending it is the create.
     *
     * @param  array<string, mixed>  $record
     * @return array{0: PendingRequest, 1: string, 2: array<string, mixed>}
     */
    protected function writeRequest(PreparedPost $post, string $did, string $rkey, array $record): array
    {
        $options = $this->options($post);

        if ($options === null || ! $options->hasGates()) {
            return [$this->http($post), $this->xrpc($post, 'com.atproto.repo.createRecord'), [
                'repo' => $did,
                'collection' => self::POST_COLLECTION,
                'rkey' => $rkey,
                'record' => $record,
            ]];
        }

        $uri = "at://{$did}/".self::POST_COLLECTION."/{$rkey}";
        $createdAt = (string) $record['createdAt'];
        $writes = [[
            '$type' => 'com.atproto.repo.applyWrites#create',
            'collection' => self::POST_COLLECTION,
            'rkey' => $rkey,
            'value' => $record,
        ]];

        if ($options->replyControl !== null) {
            $writes[] = [
                '$type' => 'com.atproto.repo.applyWrites#create',
                'collection' => 'app.bsky.feed.threadgate',
                'rkey' => $rkey,
                'value' => [
                    '$type' => 'app.bsky.feed.threadgate',
                    'post' => $uri,
                    'allow' => $this->threadgateRules($options->replyControl),
                    'createdAt' => $createdAt,
                ],
            ];
        }

        if ($options->disableQuotes) {
            $writes[] = [
                '$type' => 'com.atproto.repo.applyWrites#create',
                'collection' => 'app.bsky.feed.postgate',
                'rkey' => $rkey,
                'value' => [
                    '$type' => 'app.bsky.feed.postgate',
                    'post' => $uri,
                    'embeddingRules' => [['$type' => 'app.bsky.feed.postgate#disableRule']],
                    'createdAt' => $createdAt,
                ],
            ];
        }

        return [$this->http($post), $this->xrpc($post, 'com.atproto.repo.applyWrites'), [
            'repo' => $did,
            'writes' => $writes,
        ]];
    }

    /**
     * @param  list<string>  $rules
     * @return list<array<string, string>>
     */
    protected function threadgateRules(array $rules): array
    {
        $allow = [];

        foreach (array_slice($rules, 0, BlueskyOptions::MAX_GATE_RULES) as $rule) {
            $allow[] = match (BlueskyOptions::normaliseRule((string) $rule)) {
                'mention' => ['$type' => 'app.bsky.feed.threadgate#mentionRule'],
                'following' => ['$type' => 'app.bsky.feed.threadgate#followingRule'],
                'followers' => ['$type' => 'app.bsky.feed.threadgate#followerRule'],
                default => ['$type' => 'app.bsky.feed.threadgate#listRule', 'list' => (string) $rule],
            };
        }

        return $allow;
    }

    /**
     * The post record. The options' extra() fields are merged in, but never over
     * the driver's own keys.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>|null  $embed
     * @return array<string, mixed>
     */
    protected function recordFor(PreparedPost $post, array $state, ?array $embed): array
    {
        $options = $this->options($post);

        $record = [
            '$type' => self::POST_COLLECTION,
            // Stamped when the record is written (after the uploads), not when
            // the post was prepared: video processing and retries can sit in
            // between. The fixed record key, not the bytes, makes a repeat safe.
            'createdAt' => $this->timestamp(),
            'text' => (string) ($state['text'] ?? ''),
        ];

        if (is_array($state['facets'] ?? null) && $state['facets'] !== []) {
            $record['facets'] = array_values($state['facets']);
        }

        if ($embed !== null) {
            $record['embed'] = $embed;
        }

        $reply = $this->replyFor($options, is_array($state['reply_root'] ?? null) ? $state['reply_root'] : null);

        if ($reply !== null) {
            $record['reply'] = $reply;
        }

        $langs = $this->langs($options);

        if ($langs !== []) {
            $record['langs'] = $langs;
        }

        if ($options !== null && $options->selfLabels !== []) {
            $record['labels'] = [
                '$type' => 'com.atproto.label.defs#selfLabels',
                'values' => array_map(static fn ($label) => ['val' => (string) $label], array_values(array_unique($options->selfLabels))),
            ];
        }

        if ($options !== null && $options->normalisedTags() !== []) {
            $record['tags'] = array_slice($options->normalisedTags(), 0, BlueskyOptions::MAX_TAGS);
        }

        $extra = $this->extraPayload($post);

        foreach (['$type', 'text', 'createdAt', 'facets', 'embed', 'reply'] as $protected) {
            unset($extra[$protected]);
        }

        return array_merge($extra, $record);
    }

    /**
     * The single embed slot: video, else images, else the link card / quote
     * computed in publish(). A quote with media becomes recordWithMedia.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    protected function embedFor(PreparedPost $post, array $state): ?array
    {
        $options = $this->options($post);
        $quote = $this->quoteEmbed($options);
        $media = null;

        if (is_array($state['video'] ?? null) && $this->filledArray($state['video']['blob'] ?? null)) {
            $media = $this->videoEmbed($post, $state['video']['blob'], $options);
        } elseif ($this->imagesOf($post) !== []) {
            $media = $this->imagesEmbed($post);
        }

        if ($media !== null) {
            return $quote === null ? $media : [
                '$type' => 'app.bsky.embed.recordWithMedia',
                'record' => $quote,
                'media' => $media,
            ];
        }

        if ($quote !== null) {
            return $quote;
        }

        return is_array($state['embed'] ?? null) ? $state['embed'] : null;
    }

    /** @return array<string, mixed> */
    protected function imagesEmbed(PreparedPost $post): array
    {
        $images = [];
        $max = (int) $this->apiConfig('image_max_bytes', 1_000_000);

        foreach (array_values($this->imagesOf($post)) as $index => $media) {
            $source = $this->transformed($post, $media);
            $bytes = $this->bytes($source);

            // Validation can be skipped, and the inspector may not reach the file.
            if (strlen($bytes) > $max) {
                throw new PermanentException(
                    'Image '.($index + 1).' is '.strlen($bytes)." bytes; Bluesky accepts at most {$max} bytes per image. Compress it and try again.",
                    Platform::Bluesky,
                    FailureReason::MediaRejected,
                    ['media_index' => $index, 'size_bytes' => strlen($bytes), 'max_bytes' => $max],
                );
            }

            $image = [
                'alt' => trim((string) $media->altText),
                'image' => $this->uploadBlob($post, $bytes, $this->mimeOf($bytes, $source), 'image'),
            ];

            $aspect = $this->aspectRatio($media);

            if ($aspect !== null) {
                $image['aspectRatio'] = $aspect;
            }

            $images[] = $image;
        }

        return ['$type' => 'app.bsky.embed.images', 'images' => $images];
    }

    /**
     * @param  array<string, mixed>  $blob
     * @return array<string, mixed>
     */
    protected function videoEmbed(PreparedPost $post, array $blob, ?BlueskyOptions $options): array
    {
        $embed = ['$type' => 'app.bsky.embed.video', 'video' => $blob];
        $video = $this->videoOf($post);

        if ($video !== null) {
            $aspect = $this->aspectRatio($video);

            if ($aspect !== null) {
                $embed['aspectRatio'] = $aspect;
            }

            if (trim((string) $video->altText) !== '') {
                $embed['alt'] = trim((string) $video->altText);
            }
        }

        $captions = $this->uploadCaptions($post, $options);

        if ($captions !== []) {
            $embed['captions'] = $captions;
        }

        if ($options?->videoPresentation !== null) {
            $embed['presentation'] = $options->videoPresentation;
        }

        return $embed;
    }

    /** @return list<array{lang: string, file: array<string, mixed>}> */
    protected function uploadCaptions(PreparedPost $post, ?BlueskyOptions $options): array
    {
        $captions = [];

        foreach (array_slice($options !== null ? $options->videoCaptions : [], 0, BlueskyOptions::MAX_CAPTIONS, true) as $lang => $source) {
            $path = $this->mediaPath(new Media((string) $source, MediaType::Document));
            $bytes = @file_get_contents($path);

            if ($bytes === false || strlen($bytes) > BlueskyOptions::MAX_CAPTION_BYTES || trim($bytes) === '') {
                throw new PermanentException(
                    "The '{$lang}' caption file could not be read, is empty, or is over ".BlueskyOptions::MAX_CAPTION_BYTES.' bytes.',
                    Platform::Bluesky,
                    FailureReason::InvalidContent,
                    ['caption_lang' => $lang],
                );
            }

            $captions[] = ['lang' => (string) $lang, 'file' => $this->uploadBlob($post, $bytes, 'text/vtt', 'caption')];
        }

        return $captions;
    }

    /** @return array<string, mixed>|null */
    protected function quoteEmbed(?BlueskyOptions $options): ?array
    {
        $ref = $this->strongRef($options?->quote);

        return $ref !== null ? ['$type' => 'app.bsky.embed.record', 'record' => $ref] : null;
    }

    /**
     * root = the replyTo's own root, else the thread root threadRoot() looked
     * up in publish(), else the parent.
     *
     * @param  array<string, mixed>|null  $threadRoot
     * @return array{root: array{uri: string, cid: string}, parent: array{uri: string, cid: string}}|null
     */
    protected function replyFor(?BlueskyOptions $options, ?array $threadRoot = null): ?array
    {
        $parent = $this->strongRef($options?->replyTo);

        if ($parent === null) {
            return null;
        }

        return [
            'root' => $this->strongRef($options?->replyTo['root'] ?? null) ?? $this->strongRef($threadRoot) ?? $parent,
            'parent' => $parent,
        ];
    }

    /**
     * The thread root of a replyTo that names none: the parent's own
     * reply.root when the parent is itself a reply, else the parent. A reply
     * whose root is its parent while that parent replies to another post is
     * flagged invalidReplyRoot by the AppView and left out of the thread
     * (posting a thread one replyTo at a time does exactly that). Null when
     * there is no replyTo, or it carries its root (no lookup needed).
     *
     * @return array{uri: string, cid: string}|null
     *
     * @throws PermanentException NotFound when the parent post no longer exists.
     */
    protected function threadRoot(PreparedPost $post, ?BlueskyOptions $options): ?array
    {
        $parent = $this->strongRef($options?->replyTo);

        if ($parent === null || $this->strongRef($options?->replyTo['root'] ?? null) !== null) {
            return null;
        }

        $parts = $this->parseAtUri($parent['uri']);

        if ($parts === null) {
            return null;
        }

        $record = $this->findRecord($post, $parts['repo'], $parts['collection'], $parts['rkey']);

        if ($record === null) {
            throw new PermanentException(
                'The Bluesky post to reply to was not found (deleted?).',
                Platform::Bluesky,
                FailureReason::NotFound,
                ['parent_id' => $parent['uri']],
            );
        }

        return $this->strongRef($record['value']['reply']['root'] ?? null) ?? $parent;
    }

    /** @return list<string> */
    protected function langs(?BlueskyOptions $options): array
    {
        if ($options === null || $options->langs === null) {
            return [];
        }

        $langs = array_values(array_filter($options->langs, static fn ($lang) => is_string($lang) && trim($lang) !== ''));

        return array_slice(array_values(array_unique(array_map('trim', $langs))), 0, BlueskyOptions::MAX_LANGS);
    }

    /**
     * The link card: the explicit one from the options, else one built from
     * the first https link (unless linkPreview is off). Best effort.
     *
     * @return array<string, mixed>|null
     */
    protected function linkCard(PreparedPost $post, ?BlueskyOptions $options, string $caption): ?array
    {
        $upload = fn (string $bytes, string $mime): ?array => $this->tryUploadBlob($post, $bytes, $mime);

        if ($options?->linkCard !== null) {
            return $this->linkCardResolver()->fromCard($options->linkCard, $upload);
        }

        if (($options !== null && ! $options->linkPreview) || $caption === '') {
            return null;
        }

        return $this->linkCardResolver()->forText($caption, $upload);
    }

    protected function linkCardResolver(): BlueskyLinkCardResolver
    {
        $userAgent = $this->apiConfig('link_preview_user_agent');

        return new BlueskyLinkCardResolver(
            timeout: (int) $this->apiConfig('link_preview_timeout', 10),
            userAgent: is_string($userAgent) && $userAgent !== '' ? $userAgent : null,
            maxHtmlBytes: (int) $this->apiConfig('link_preview_max_bytes', 1_000_000),
            maxImageBytes: (int) $this->apiConfig('link_preview_image_max_bytes', 5_000_000),
        );
    }

    protected function richText(): BlueskyRichText
    {
        return new BlueskyRichText(
            appview: (string) $this->apiConfig('appview', 'https://public.api.bsky.app'),
            timeout: (int) $this->apiConfig('mention_timeout', 5),
            cacheSeconds: (int) $this->apiConfig('handle_cache_ttl', 86400),
        );
    }

    // --- Blobs ------------------------------------------------------------------------

    /** @return array<string, mixed> The blob object, kept verbatim for the record. */
    protected function uploadBlob(PreparedPost $post, string $bytes, string $mime, string $what): array
    {
        $response = $this->http($post)
            ->timeout((int) $this->apiConfig('blob_timeout', 60))
            ->withBody($bytes, $mime)
            ->post($this->xrpc($post, 'com.atproto.repo.uploadBlob'));

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        $this->requireId($response, "{$what} blob", 'blob.ref.$link');

        return (array) $response->json('blob');
    }

    /** @return array<string, mixed>|null */
    protected function tryUploadBlob(PreparedPost $post, string $bytes, string $mime): ?array
    {
        try {
            return $this->uploadBlob($post, $bytes, $mime, 'link card thumbnail');
        } catch (Throwable) {
            return null;
        }
    }

    protected function isBlobNotFound($response): bool
    {
        $code = $this->xrpcCode($response);
        $message = strtolower((string) $this->xrpcMessage($response));

        return $code === 'BlobNotFound'
            || ($response->status() === 400 && preg_match('/could not find blob|blob not found|blob .{0,80}not found/', $message) === 1);
    }

    /**
     * A blob the PDS garbage-collected before the record referenced it: start
     * over (fresh uploads) a limited number of times, then give up.
     */
    protected function blobExpired($response, int $restarts): TemporaryException|PermanentException
    {
        $context = ['blob_not_found' => true, 'status' => $response->status(), 'http_status' => $response->status(), 'error' => $this->xrpcCode($response)];

        $exception = $restarts < (int) $this->apiConfig('blob_restarts', 1)
            ? new TemporaryException(
                'Bluesky no longer has an uploaded blob (BlobNotFound); starting the publish over.',
                Platform::Bluesky,
                FailureReason::NotFound,
                0,
                $context + ['restart' => true],
            )
            : new PermanentException(
                'Bluesky no longer has the uploaded media (BlobNotFound), even after starting over.',
                Platform::Bluesky,
                FailureReason::MediaRejected,
                $context,
            );

        $this->reportError($response, $exception);

        return $exception;
    }

    // --- Records ----------------------------------------------------------------------

    /**
     * com.atproto.repo.getRecord (public, no auth needed): the record with its
     * uri and cid, or null when it does not exist. With $quiet any other
     * failure is null too (used where the lookup is only a second chance).
     *
     * @return array<string, mixed>|null
     */
    protected function findRecord(PreparedPost $post, string $repo, string $collection, string $rkey, bool $quiet = false): ?array
    {
        try {
            $host = $repo === $this->did($post) ? $this->pds($post) : ($this->resolvePds($repo) ?? rtrim((string) $this->apiConfig('appview', 'https://public.api.bsky.app'), '/'));

            $response = Http::acceptJson()
                ->connectTimeout((int) $this->apiConfig('connect_timeout', 10))
                ->timeout(30)
                ->get($host.'/xrpc/com.atproto.repo.getRecord', ['repo' => $repo, 'collection' => $collection, 'rkey' => $rkey]);
        } catch (Throwable $e) {
            if ($quiet) {
                return null;
            }

            throw $e;
        }

        if ($response->successful()) {
            $json = $response->json();

            return (is_array($json) ? $json : []) + ['uri' => "at://{$repo}/{$collection}/{$rkey}"];
        }

        if ($this->isRecordNotFound($response) || $quiet) {
            return null;
        }

        throw $this->mapError($response);
    }

    protected function isRecordNotFound($response): bool
    {
        return $response->status() === 404
            || $this->xrpcCode($response) === 'RecordNotFound'
            || ($response->status() === 400 && str_contains(strtolower((string) $this->xrpcMessage($response)), 'could not locate record'));
    }

    /**
     * Whether a failed create may hide an existing record (a server error, or
     * the PDS refusing a create on a key that is already taken): worth one
     * lookup. Auth, quota, blob and content refusals are not.
     */
    protected function mayAlreadyExist($response): bool
    {
        $status = (int) $response->status();
        $code = $this->xrpcCode($response);

        if (in_array($status, [401, 403, 413, 429], true)) {
            return false;
        }

        $definite = array_merge(self::AUTH_CODES, self::ACCOUNT_CODES, [
            'RateLimitExceeded', 'BlobTooLarge', 'InvalidMimeType', 'BlobNotFound', 'InsufficientScope', 'InvalidRecord',
            'use_dpop_nonce', 'invalid_dpop_proof', 'RepoNotFound',
        ]);

        return $status >= 400 && ! in_array($code, $definite, true);
    }

    /**
     * @param  array<string, mixed>  $data  {uri, cid, validationStatus?, value?}
     * @param  array<string, mixed>  $extra
     */
    protected function postResult(string $did, string $rkey, array $data, array $extra = []): PostResult
    {
        $uri = is_string($data['uri'] ?? null) && $data['uri'] !== '' ? $data['uri'] : "at://{$did}/".self::POST_COLLECTION."/{$rkey}";
        $root = $this->strongRef(is_array($data['value']['reply']['root'] ?? null) ? $data['value']['reply']['root'] : null);

        $payload = array_filter([
            'uri' => $uri,
            'cid' => is_string($data['cid'] ?? null) ? $data['cid'] : null,
            'did' => $did,
            'rkey' => $rkey,
            'validation_status' => is_string($data['validationStatus'] ?? null) ? $data['validationStatus'] : null,
            'reply_root' => $root,
        ], static fn ($value) => $value !== null) + $extra;

        return PostResult::success(Platform::Bluesky, $uri, $this->permalink($uri), $payload, PostResult::STATE_PUBLISHED, 'at_uri');
    }

    /** https://bsky.app/profile/{did}/post/{rkey} (the DID survives handle changes). */
    protected function permalink(string $uri): ?string
    {
        $parts = $this->parseAtUri($uri);

        if ($parts === null || $parts['collection'] !== self::POST_COLLECTION) {
            return null;
        }

        return rtrim((string) $this->apiConfig('permalink_host', 'https://bsky.app'), '/').'/profile/'.$parts['repo'].'/post/'.$parts['rkey'];
    }

    /** @return array{repo: string, collection: string, rkey: string}|null */
    protected function parseAtUri(string $uri): ?array
    {
        if (preg_match('~^at://([^/]+)/([^/]+)/([^/?#]+)$~', trim($uri), $m) !== 1) {
            return null;
        }

        return ['repo' => $m[1], 'collection' => $m[2], 'rkey' => $m[3]];
    }

    /** @return array{uri: string, cid: string}|null */
    protected function strongRef(mixed $ref): ?array
    {
        if (! is_array($ref) || ! is_string($ref['uri'] ?? null) || ! is_string($ref['cid'] ?? null)) {
            return null;
        }

        $uri = trim($ref['uri']);
        $cid = trim($ref['cid']);

        return $uri !== '' && $cid !== '' ? ['uri' => $uri, 'cid' => $cid] : null;
    }

    // --- First comment ------------------------------------------------------------------

    public function commentRules(PreparedPost $post, ?PostResult $parent = null): ?CommentRules
    {
        return new CommentRules(
            max: self::MAX_GRAPHEMES,
            counter: new GraphemeCounter,
            requiredScopes: $this->requiredScopes(Feature::Comment, $post->credentials),
            unknownScopePolicy: CommentRules::ATTEMPT,
            maxBytes: self::MAX_BYTES,
        );
    }

    /**
     * The first comment is a reply: root = the thread's root (the parent's own
     * reply.root when the parent is itself a reply), parent = the post. The
     * refs come from the parent's payload (uri + cid), or getRecord when the
     * cid is missing. The reply's record key is derived from the parent and the
     * text, so a retried comment job targets the same record: a lost response
     * is looked up, and a repeat create cannot post twice.
     */
    public function addFirstComment(PreparedPost $context, PostResult $parent, string $text): CommentResult
    {
        $this->assertCredentials($context);

        $text = trim($text);
        $errors = $this->commentRules($context, $parent)?->errors($text) ?? [];

        if ($errors !== []) {
            throw new PermanentException(implode(' ', $errors), Platform::Bluesky, FailureReason::InvalidContent, ['comment' => true]);
        }

        $did = $this->did($context);
        [$parentRef, $rootRef] = $this->replyRefs($context, $parent);
        $options = $this->options($context);
        $rich = $this->richText()->build($text, $options, false, false);

        $record = [
            '$type' => self::POST_COLLECTION,
            'createdAt' => $this->timestamp(),
            'text' => $rich['text'],
        ];

        if ($rich['facets'] !== []) {
            $record['facets'] = $rich['facets'];
        }

        $record['reply'] = ['root' => $rootRef, 'parent' => $parentRef];

        $langs = $this->langs($options);

        if ($langs !== []) {
            $record['langs'] = $langs;
        }

        $parentKey = $this->parseAtUri($parentRef['uri'])['rkey'] ?? '';
        $rkey = Tid::derive($parentKey, 'first-comment|'.$parentRef['uri'].'|'.$text) ?? Tid::next();

        return $this->createComment($context, $did, $rkey, $record);
    }

    /**
     * Verify-then-retry, once: after a lost response (connection error) or a
     * server error the reply is looked up by its key, and sent again with the
     * same key only when it does not exist.
     *
     * @param  array<string, mixed>  $record
     */
    protected function createComment(PreparedPost $context, string $did, string $rkey, array $record): CommentResult
    {
        $body = ['repo' => $did, 'collection' => self::POST_COLLECTION, 'rkey' => $rkey, 'record' => $record];

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->http($context)->post($this->xrpc($context, 'com.atproto.repo.createRecord'), $body);
            } catch (ConnectionException $e) {
                if ($attempt > 1) {
                    throw $e;
                }

                $existing = $this->findRecord($context, $did, self::POST_COLLECTION, $rkey);

                if ($existing !== null) {
                    return $this->commentResult($did, $rkey, $existing, true);
                }

                continue;
            }

            if ($this->ok($response)) {
                $uri = $response->json('uri');

                if (! is_string($uri) || trim($uri) === '') {
                    $existing = $this->findRecord($context, $did, self::POST_COLLECTION, $rkey, true);

                    if ($existing !== null) {
                        return $this->commentResult($did, $rkey, $existing, true);
                    }

                    $this->requireId($response, 'reply', 'uri', true); // throws: accepted without an id
                }

                return $this->commentResult($did, $rkey, (array) $response->json());
            }

            if ($this->mayAlreadyExist($response)) {
                $existing = $this->findRecord($context, $did, self::POST_COLLECTION, $rkey, true);

                if ($existing !== null) {
                    return $this->commentResult($did, $rkey, $existing, true);
                }

                if ($response->serverError() && $attempt === 1) {
                    continue;
                }
            }

            throw $this->mapError($response);
        }
    }

    /** @param  array<string, mixed>  $data */
    protected function commentResult(string $did, string $rkey, array $data, bool $recovered = false): CommentResult
    {
        $uri = is_string($data['uri'] ?? null) && $data['uri'] !== '' ? $data['uri'] : "at://{$did}/".self::POST_COLLECTION."/{$rkey}";

        return new CommentResult($uri, $this->permalink($uri), array_filter([
            'uri' => $uri,
            'cid' => is_string($data['cid'] ?? null) ? $data['cid'] : null,
            'did' => $did,
            'rkey' => $rkey,
            'recovered' => $recovered ?: null,
        ], static fn ($value) => $value !== null));
    }

    /**
     * Strong refs to the parent post and the thread root.
     *
     * @return array{0: array{uri: string, cid: string}, 1: array{uri: string, cid: string}}
     */
    protected function replyRefs(PreparedPost $context, PostResult $parent): array
    {
        $payload = $parent->payload;
        $uri = is_string($payload['uri'] ?? null) && $payload['uri'] !== '' ? $payload['uri'] : (string) $parent->platformPostId;
        $parts = $this->parseAtUri($uri);

        if ($parts === null || $parts['collection'] !== self::POST_COLLECTION) {
            throw new PermanentException(
                'The Bluesky post to reply to must be identified by its at:// URI (app.bsky.feed.post).',
                Platform::Bluesky,
                FailureReason::InvalidContent,
                ['parent_id' => $uri],
            );
        }

        $cid = is_string($payload['cid'] ?? null) && trim($payload['cid']) !== '' ? trim($payload['cid']) : null;
        $root = $this->strongRef($payload['reply_root'] ?? null);

        if ($cid === null) {
            $record = $this->findRecord($context, $parts['repo'], $parts['collection'], $parts['rkey']);

            if ($record === null || ! is_string($record['cid'] ?? null) || $record['cid'] === '') {
                throw new PermanentException(
                    'The Bluesky post to reply to was not found (deleted?).',
                    Platform::Bluesky,
                    FailureReason::NotFound,
                    ['parent_id' => $uri],
                );
            }

            $cid = $record['cid'];
            $root = $this->strongRef($record['value']['reply']['root'] ?? null);
        }

        $parentRef = ['uri' => $uri, 'cid' => $cid];

        return [$parentRef, $root ?? $parentRef];
    }

    // --- PDS --------------------------------------------------------------------------

    /**
     * The account's DID, the repo every write goes to.
     *
     * @throws PermanentException Misconfigured when it is not a DID.
     */
    /**
     * The PDS origin for these credentials, as pds() finds it (the 'pds'
     * credential, else the DID document's #atproto_pds service, cached). The
     * default authorizer creates app-password sessions there.
     */
    public function pdsFor(Credentials $credentials): string
    {
        return $this->pds(new PreparedPost(Platform::Bluesky, new SocialPost, $credentials));
    }

    protected function did(PreparedPost $post): string
    {
        $did = trim((string) $post->credentials->get('did'));

        if (preg_match('/^did:[a-z]+:[A-Za-z0-9._:%-]+$/', $did) !== 1) {
            throw new PermanentException(
                'The Bluesky credential "did" must be the account DID (did:plc:… or did:web:…), not a handle.',
                Platform::Bluesky,
                FailureReason::Misconfigured,
                ['missing_credentials' => ['did']],
            );
        }

        return $did;
    }

    /**
     * The account's PDS origin. The 'pds' (or 'pds_url') credential when it is
     * a real PDS; when it is missing or the entryway (bsky.social, which a
     * video service token cannot target), the DID document's #atproto_pds
     * service, cached for an hour.
     *
     * When the DID document cannot be read (or names no PDS), a configured
     * entryway is used as is: it still proxies the XRPC calls. Not with
     * $entrywayFallback false, for the video service token, whose audience
     * must be the real PDS: the lookup failure is thrown then (Temporary when
     * the directory is down, Misconfigured when the document names no PDS).
     */
    protected function pds(PreparedPost $post, bool $entrywayFallback = true): string
    {
        $configured = $this->credentialUrl($post->credentials, 'pds') ?? $this->credentialUrl($post->credentials, 'pds_url');
        $entryway = rtrim((string) $this->apiConfig('entryway', 'https://bsky.social'), '/');

        if ($configured !== null && strcasecmp($configured, $entryway) !== 0) {
            return $configured;
        }

        $did = $this->did($post);
        $fallback = $entrywayFallback ? $configured : null;

        try {
            $resolved = $this->resolvePds($did);
        } catch (SocialPosterException $e) {
            if ($fallback !== null) {
                return $fallback; // the entryway still proxies most calls
            }

            throw $e;
        }

        if ($resolved !== null) {
            return $resolved;
        }

        if ($fallback !== null) {
            return $fallback;
        }

        throw new PermanentException(
            "The Bluesky PDS of {$did} could not be found in its DID document; pass it as the 'pds' credential.",
            Platform::Bluesky,
            FailureReason::Misconfigured,
            ['missing_credentials' => ['pds']],
        );
    }

    /**
     * The #atproto_pds endpoint of a DID document (did:plc through the PLC
     * directory, did:web through /.well-known/did.json), checked with
     * PublicUrl. Null when the document names none.
     *
     * @throws TemporaryException When the directory cannot be reached.
     */
    protected function resolvePds(string $did): ?string
    {
        $cacheKey = 'social-poster:bsky-pds:'.hash('sha256', $did);

        try {
            $cached = Cache::get($cacheKey);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $url = $this->didDocumentUrl($did);

        if ($url === null) {
            return null;
        }

        try {
            PublicUrl::check($url);
        } catch (PermanentException) {
            return null; // a did:web pointing at a private address is not followed
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->withOptions(['allow_redirects' => false])
                ->get($url);
        } catch (ConnectionException $e) {
            throw TemporaryException::connection(Platform::Bluesky, $e)->addContext(['pds_resolution' => true]);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new TemporaryException(
                "The DID document of {$did} could not be read (HTTP {$response->status()}).",
                Platform::Bluesky,
                FailureReason::ServerError,
                RetryAfter::fromResponse($response) ?? 60,
                ['pds_resolution' => true, 'http_status' => $response->status()],
            );
        }

        if (! $response->successful()) {
            return null;
        }

        foreach ((array) $response->json('service', []) as $service) {
            $id = is_array($service) ? (string) ($service['id'] ?? '') : '';
            $endpoint = is_array($service) && is_string($service['serviceEndpoint'] ?? null) ? rtrim(trim($service['serviceEndpoint']), '/') : '';

            if (! str_ends_with($id, '#atproto_pds') || preg_match('~^https?://~i', $endpoint) !== 1) {
                continue;
            }

            try {
                PublicUrl::check($endpoint);
            } catch (PermanentException) {
                return null;
            }

            try {
                Cache::put($cacheKey, $endpoint, max(60, (int) $this->apiConfig('pds_cache_ttl', 3600)));
            } catch (Throwable) {
                // ignore
            }

            return $endpoint;
        }

        return null;
    }

    protected function didDocumentUrl(string $did): ?string
    {
        if (str_starts_with($did, 'did:plc:')) {
            // did:plc identifiers are base32 ([a-z2-7]): safe in a path as written.
            return preg_match('/^did:plc:[a-z2-7]+$/', $did) === 1
                ? rtrim((string) $this->apiConfig('plc_directory', 'https://plc.directory'), '/').'/'.$did
                : null;
        }

        if (str_starts_with($did, 'did:web:')) {
            $segments = array_map('rawurldecode', explode(':', substr($did, strlen('did:web:'))));
            $host = array_shift($segments);

            if ($host === null || $host === '') {
                return null;
            }

            return $segments === []
                ? "https://{$host}/.well-known/did.json"
                : "https://{$host}/".implode('/', array_map('rawurlencode', $segments)).'/did.json';
        }

        return null;
    }

    protected function xrpc(PreparedPost $post, string $nsid): string
    {
        return $this->pds($post).'/xrpc/'.$nsid;
    }

    // --- Helpers ----------------------------------------------------------------------

    protected function options(PreparedPost $post): ?BlueskyOptions
    {
        return $this->optionsOf($post, BlueskyOptions::class);
    }

    protected function videoOf(PreparedPost $post): ?Media
    {
        foreach ($post->media() as $media) {
            if ($media->type === MediaType::Video) {
                return $media;
            }
        }

        return null;
    }

    /** @return list<Media> */
    protected function imagesOf(PreparedPost $post): array
    {
        return array_values(array_filter($post->media(), static fn (Media $media) => $media->type === MediaType::Image));
    }

    /**
     * Display width and height (rotation and EXIF orientation applied by the
     * inspector, or from Media::$meta), or null when unknown.
     *
     * @return array{width: int, height: int}|null
     */
    protected function aspectRatio(Media $media): ?array
    {
        $meta = $this->inspectMedia($media);

        if ($meta === null || ! $meta->width || ! $meta->height || $meta->width < 1 || $meta->height < 1) {
            return null;
        }

        return ['width' => $meta->width, 'height' => $meta->height];
    }

    /** The MIME type from the bytes, else from the extension. */
    protected function mimeOf(string $bytes, Media $media): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $mime = finfo_buffer($finfo, $bytes);
                finfo_close($finfo);

                if (is_string($mime) && str_starts_with($mime, 'image/')) {
                    return $mime;
                }
            }
        }

        return match ($media->extension()) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /** Run the bound MediaTransformer (HEIC → JPEG...) and remember it for cleanup. */
    protected function transformed(PreparedPost $post, Media $media): Media
    {
        $transformer = $this->mediaTransformer();

        if ($transformer === null) {
            return $media;
        }

        $result = $transformer->transform($media, Platform::Bluesky, $this->rulesFor($post));

        if ($result !== $media && method_exists($transformer, 'cleanup')) {
            $this->transformers[] = $transformer;
        }

        return $result;
    }

    /** Delete the temp files of this publish (downloads and conversions). */
    protected function releaseMedia(): void
    {
        $this->gateway()?->cleanup();

        foreach ($this->transformers as $transformer) {
            try {
                $transformer->cleanup();
            } catch (Throwable) {
                // ignore
            }
        }

        $this->transformers = [];
    }

    /** createdAt: UTC with milliseconds and a 'Z'. */
    protected function timestamp(): string
    {
        return now('UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    /** The same normalisation DefaultBlueskyAuthorizer logs in with (Xrpc::pdsUrl()). */
    protected function credentialUrl(Credentials $credentials, string $key): ?string
    {
        return Xrpc::pdsUrl($credentials->get($key));
    }

    protected function filledArray(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }

    protected function xrpcCode($response): ?string
    {
        try {
            $code = $response->json('error');
        } catch (Throwable) {
            return null;
        }

        return is_string($code) && $code !== '' ? $code : null;
    }

    protected function xrpcMessage($response): ?string
    {
        try {
            $message = $response->json('message');
        } catch (Throwable) {
            return null;
        }

        return is_string($message) && trim($message) !== '' ? trim($message) : null;
    }

    // --- Errors -------------------------------------------------------------------------

    /**
     * XRPC errors are {error, message}. $surface is 'video' for video.bsky.app
     * responses, whose refusals (unverified email, daily quota) only show in the
     * message and are checked before the status rows; on the PDS they are not.
     *
     * Order: DPoP nonce (still missing after the middleware's retry) →
     * Temporary 5 s; bad DPoP proof → Misconfigured; takedown / deactivation
     * (a 401 on the PDS) → AccountRestricted; auth codes / 401 → InvalidToken
     * (re-resolved once when a CredentialsResolver is bound); [video: 5xx,
     * email, quota]; 403 / InsufficientScope → InsufficientPermissions; 429 →
     * RateLimited (Temporary until ratelimit-reset, else 300 s; a reset beyond
     * max_rate_limit_wait, 3600 s, such as the daily write budget → Permanent
     * with retry_at, see Xrpc::rateLimited()); 5xx → 60 s; BlobTooLarge /
     * 413 / InvalidMimeType / BlobNotFound → MediaRejected; InvalidRecord →
     * InvalidContent; RepoNotFound → Misconfigured; InvalidRequest → Unknown;
     * the rest by HTTP status.
     */
    protected function classifyError($response, string $surface = 'pds'): TemporaryException|PermanentException
    {
        $status = (int) $response->status();
        $code = $this->xrpcCode($response);
        $message = $this->xrpcMessage($response);
        $detail = $message ?? $code;
        $challenge = strtolower((string) $response->header('WWW-Authenticate'));
        $context = array_filter(['status' => $status, 'http_status' => $status, 'error' => $code, 'surface' => $surface], static fn ($value) => $value !== null);
        $because = $detail !== null ? ' ('.rtrim($detail, '.').')' : '';

        if ($code === 'use_dpop_nonce' || str_contains($challenge, 'use_dpop_nonce')) {
            return new TemporaryException('Bluesky asked for a fresh DPoP nonce again; retrying shortly.', Platform::Bluesky, FailureReason::ServerError, 5, $context + ['dpop_nonce' => true]);
        }

        if ($code === 'invalid_dpop_proof' || str_contains($challenge, 'invalid_dpop_proof')) {
            return new PermanentException(
                'Bluesky rejected the DPoP proof'.$because.': check that dpop_private_key is the key the token was issued for, and that the server clock is right.',
                Platform::Bluesky,
                FailureReason::Misconfigured,
                $context + ['dpop_proof_invalid' => true],
            );
        }

        // Before the 401 row: the PDS refuses a taken-down or deactivated
        // account with a 401 (AuthRequiredError), and reconnecting cannot help.
        if (in_array($code, self::ACCOUNT_CODES, true)) {
            return new PermanentException('The Bluesky account is suspended, taken down or deactivated'.$because.'.', Platform::Bluesky, FailureReason::AccountRestricted, $context);
        }

        if (in_array($code, self::AUTH_CODES, true) || $status === 401 || str_contains($challenge, 'invalid_token')) {
            $flags = $code === 'AuthFactorTokenRequired'
                ? ['terminal' => true]
                : ['reresolvable' => true] + ($code === 'ExpiredToken' || str_contains($challenge, 'expired') ? ['token_expired' => true] : []);

            return new PermanentException(
                'Bluesky rejected the session'.$because.'; reconnect the account.',
                Platform::Bluesky,
                FailureReason::InvalidToken,
                $context + $flags,
            );
        }

        if ($surface === 'video') {
            if ($status >= 500) {
                return new TemporaryException("Bluesky's video service is unavailable".$because.'.', Platform::Bluesky, FailureReason::ServerError, RetryAfter::fromResponse($response) ?? 60, $context);
            }

            $refusal = $this->videoRefusal($message, $response, $context);

            if ($refusal !== null) {
                return $refusal;
            }
        }

        if ($status === 403 || in_array($code, ['InsufficientScope', 'Forbidden'], true)) {
            return new PermanentException('Bluesky refused the request'.$because.'; the session may lack a permission.', Platform::Bluesky, FailureReason::InsufficientPermissions, $context);
        }

        if ($status === 429 || $code === 'RateLimitExceeded') {
            return Xrpc::rateLimited($response, 'Bluesky rate limit reached'.$because, (int) $this->apiConfig('max_rate_limit_wait', 3600), $context);
        }

        if ($status >= 500 || in_array($code, self::SERVER_CODES, true)) {
            return new TemporaryException("Bluesky didn't respond".$because.'.', Platform::Bluesky, FailureReason::ServerError, RetryAfter::fromResponse($response) ?? 60, $context);
        }

        if ($code === 'BlobTooLarge' || $status === 413) {
            return new PermanentException('A file is over Bluesky\'s size limit'.$because.'.', Platform::Bluesky, FailureReason::MediaRejected, $context);
        }

        if (in_array($code, ['InvalidMimeType', 'BlobNotFound'], true)) {
            return new PermanentException('Bluesky refused a media file'.$because.'.', Platform::Bluesky, FailureReason::MediaRejected, $context);
        }

        if ($code === 'InvalidRecord') {
            return new PermanentException('Bluesky refused this post'.$because.'.', Platform::Bluesky, FailureReason::InvalidContent, $context);
        }

        if ($code === 'RepoNotFound') {
            return new PermanentException(
                'Bluesky has no repository for this DID on this PDS'.$because.'; check the did and pds credentials.',
                Platform::Bluesky,
                FailureReason::Misconfigured,
                $context,
            );
        }

        if (in_array($code, ['InvalidRequest', 'InvalidSwap'], true)) {
            return new PermanentException('Bluesky refused this post'.$because.'.', Platform::Bluesky, FailureReason::Unknown, $context);
        }

        return parent::classifyError($response);
    }
}
