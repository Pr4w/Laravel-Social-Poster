<?php

namespace SocialPoster\Platforms\TikTok;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Sleep;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\ImageRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Capabilities\VideoRules;
use SocialPoster\Concerns\UploadsInChunks;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\Ingestion;
use SocialPoster\Enums\MediaType;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Gateways\FilesystemMediaGateway;
use SocialPoster\Gateways\LocalMediaGateway;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\Support\RetryAfter;
use SocialPoster\Support\Text\Utf16Counter;
use SocialPoster\Transformers\ImageConvertingTransformer;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\Published;
use SocialPoster\ValueObjects\PublishOutcome;
use Throwable;

/**
 * TikTok Content Posting API (v2). Two posting targets, chosen via TikTokOptions:
 *
 *  - Drafts (default): uploads to the creator's inbox for them to finish in-app.
 *    Needs only the video.upload scope. No creator-info query, no privacy level.
 *    Video uses /post/publish/inbox/video/init/ (no post_info, so the caption is
 *    not sent); photos use /content/init/ with post_mode=MEDIA_UPLOAD. TikTok
 *    allows at most 5 pending inbox shares per creator per 24 hours.
 *
 *  - DirectPost: publishes straight to the profile. Needs video.publish and an
 *    audited client. Always queries creator-info first (required: it gives the
 *    permitted privacy levels, forced comment/duet/stitch locks, the creator's
 *    maximum video duration and username), requires a privacy level, and
 *    carries a full post_info with the branded-content toggles.
 *
 * Captions are counted in UTF-16 code units: a video caption (post_info.title)
 * up to 2200; a photo caption goes in post_info.description (up to 4000) and the
 * post title (->title()) in post_info.title (up to 90).
 *
 * The publish runs as a sequence of steps, each its own queue run:
 *
 *   publish()        creator_info (direct), media URLs or the local file, the
 *                    request body; then a commit step:
 *   init             the non-idempotent create (video/init, inbox/video/init or
 *                    content/init). TikTok offers no way to look an init up, so
 *                    a repeat of a PULL_FROM_URL init after an ambiguous outcome
 *                    is a PermanentException(OutcomeUnknown) with
 *                    possibly_published. A FILE_UPLOAD init is safe to repeat (no
 *                    post exists until the bytes arrive). Every later Pending is
 *                    committed, except FILE_UPLOAD's until the last chunk is
 *                    next (TikTok publishes nothing without every byte).
 *   upload           FILE_UPLOAD only: chunks PUT to upload_url within a per-run
 *                    budget (social.api.tiktok.upload_run_seconds), offset kept in
 *                    state, each chunk retried on 5xx (within the run budget)
 *                    and resynced from Content-Range on 416. Only a committed
 *                    run sends the last chunk.
 *   await_publish    status/fetch with backoff (15s doubling every 4 polls, up to
 *                    120s) until PUBLISH_COMPLETE / SEND_TO_USER_INBOX / FAILED,
 *                    within a 3900s deadline (TikTok's pull may take an hour).
 *   await_public_id  direct PUBLIC_TO_EVERYONE posts only: until TikTok reports
 *                    the public video id (after moderation), for the permalink;
 *                    at most social.api.tiktok.public_id_wait seconds.
 *
 * Media transfer (TikTokOptions::$transfer): PULL_FROM_URL for public https URLs
 * (always for photos; the host must be verified in the TikTok app), chunked
 * FILE_UPLOAD for local video (and remote video on request, or after an
 * url_ownership_unverified refusal under Auto). PNG photos are converted to JPG
 * through the bound MediaTransformer.
 *
 * TikTok has no comment API, so this driver takes no first comment.
 */
class TikTokDriver extends AbstractPlatform
{
    use UploadsInChunks;

    public const PULL_FROM_URL = 'PULL_FROM_URL';

    public const FILE_UPLOAD = 'FILE_UPLOAD';

    public const PHASE_INIT = 'init';

    public const PHASE_PREPARE_UPLOAD = 'prepare_upload';

    public const PHASE_UPLOAD = 'upload';

    /** The 1.0 phase name, kept so in-flight continuations still resume. */
    public const PHASE_AWAIT = 'await_publish';

    public const PHASE_AWAIT_PUBLIC_ID = 'await_public_id';

    /** Earlier names for a phase (none renamed yet; 'await' is accepted as a courtesy alias). */
    protected const PHASE_ALIASES = ['await' => self::PHASE_AWAIT];

    protected string $api = 'https://open.tiktokapis.com/v2';

    /**
     * Video caption limit in UTF-16 code units (TikTok: 2200). Override with
     * social.api.tiktok.video_title_limit; photos use their own limits.
     */
    protected int $captionLimit = 2200;

    /** FILE_UPLOAD chunk size for files above $maxSingleChunk (TikTok: 5-64 MB). */
    protected int $uploadChunkSize = 10485760;

    /** Files up to this size go as one chunk (TikTok's maximum chunk). */
    protected int $maxSingleChunk = 67108864;

    /** TikTok's smallest chunk (except a whole file sent as one). */
    protected int $minChunkSize = 5242880;

    public function platform(): Platform
    {
        return Platform::TikTok;
    }

    protected function ingestion(): Ingestion
    {
        return Ingestion::Both;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            caption: new CaptionRules(max: $this->videoTitleLimit(), counter: new Utf16Counter),
            media: new MediaRules(minCount: 1, maxCount: 35, image: $this->photoRules(), video: $this->videoRules()),
            supportsTitle: true,
            supportsCarousel: true,
        );
    }

    protected function rulesFor(PreparedPost $post): Capabilities
    {
        $count = count($post->media());

        // More than one item is always a photo carousel: images only, no mixing.
        if ($count > 1) {
            return new Capabilities(
                caption: new CaptionRules(max: $this->photoDescriptionLimit(), counter: new Utf16Counter),
                media: new MediaRules(minCount: 2, maxCount: 35, image: $this->photoRules()),
                supportsTitle: true,
                supportsCarousel: true,
            );
        }

        if ($count === 0) {
            return $this->capabilities();
        }

        // A single item is one photo or one video. An inbox (Drafts) video sends
        // no post_info at all, so its caption is never sent and has no limit.
        $maxCaption = match (true) {
            $this->isPhotoPost($post) => $this->photoDescriptionLimit(),
            $this->target($post) === TikTokTarget::Drafts => PHP_INT_MAX,
            default => $this->videoTitleLimit(),
        };

        return new Capabilities(
            caption: new CaptionRules(max: $maxCaption, counter: new Utf16Counter),
            media: new MediaRules(minCount: 1, maxCount: 1, image: $this->photoRules(), video: $this->videoRules()),
            supportsTitle: true,
            supportsCarousel: true,
        );
    }

    protected function validatePlatformRules(PreparedPost $post, MessageBag $errors): void
    {
        $options = $this->options($post) ?? new TikTokOptions;
        $media = $post->media();
        $isPhoto = $this->isPhotoPost($post);

        // A photo post's title (->title()) is TikTok's post_info.title, capped at 90.
        if ($isPhoto) {
            $this->validateText('title', 'Title', $post->title(), $this->photoTitleLimit(), new Utf16Counter, $errors);
        }

        // Direct posting needs a privacy level; surface it here rather than only
        // failing at publish after the creator-info round trip.
        if ($options->target === TikTokTarget::DirectPost && $options->privacyLevel === null) {
            $errors->add('privacy_level', 'TikTok direct posts require a privacy level. Set it on TikTokOptions.');
        }

        foreach ($media as $index => $item) {
            $pulled = $item->type === MediaType::Image
                || ($item->type === MediaType::Video && $options->transfer === TikTokTransfer::PullFromUrl);

            if (! $pulled) {
                continue;
            }

            if (! $this->canPull($item)) {
                $errors->add(
                    "media.{$index}",
                    $item->type === MediaType::Image
                        ? 'TikTok photos must be pulled from a public URL. Host the image publicly or bind a gateway that can publish local files.'
                        : 'TikTokTransfer::PullFromUrl needs a public URL. Host the video publicly, bind a gateway that can publish local files, or use TikTokTransfer::FileUpload.',
                );

                continue;
            }

            if ($item->isRemote() && ! $this->isHttps($item->source)) {
                $errors->add(
                    "media.{$index}",
                    'TikTok only pulls media over HTTPS, without redirects, from a domain or URL prefix verified in your TikTok app.'
                    .($item->type === MediaType::Video ? ' Use an https URL or TikTokTransfer::FileUpload.' : ' Use an https URL.'),
                );
            }
        }

        if ($isPhoto && $media !== [] && $options->photoCoverIndex >= count($media)) {
            $errors->add('photo_cover_index', 'The TikTok photo cover index ('.$options->photoCoverIndex.') must point at one of the '.count($media).' photos (it is zero-based).');
        }

        // An inbox video carries no post_info, so extra() would be silently lost.
        if (! $isPhoto && $media !== [] && $options->target === TikTokTarget::Drafts && $this->extraPayload($post) !== []) {
            $errors->add('extra', 'TikTok draft videos take no post_info, so extra() has nothing to apply to. Use DirectPost, or let the creator finish the draft in-app.');
        }

        // A PNG is converted to a local JPG, which TikTok can only pull when the
        // gateway can publish local files.
        if ($isPhoto && ! $this->canHostLocalFiles()) {
            $rules = $this->photoRules();

            foreach ($media as $index => $item) {
                $target = $rules->conversionTarget($item->extension());

                if ($target !== null && $this->canTransform($item, $target)) {
                    $errors->add(
                        "media.{$index}",
                        ".{$item->extension()} photos are converted to .{$target} as a local file, which TikTok cannot pull through this gateway: bind FilesystemMediaGateway (re-hosts it on a public disk) or upload a JPG.",
                    );
                }
            }
        }

        $this->validateTargetScope($post, $options, $errors);
    }

    /**
     * The scope the chosen target needs, when the token lists its scopes:
     * DirectPost video.publish, Drafts video.upload. (Holding neither is
     * already reported by the base scope check.)
     */
    protected function validateTargetScope(PreparedPost $post, TikTokOptions $options, MessageBag $errors): void
    {
        $scopes = $post->credentials->scopes();

        if ($scopes === null || array_intersect(['video.publish', 'video.upload'], $scopes) === []) {
            return;
        }

        $direct = $options->target === TikTokTarget::DirectPost;
        $needed = $direct ? 'video.publish' : 'video.upload';

        if (! in_array($needed, $scopes, true)) {
            $errors->add(
                'credentials.scopes',
                $direct
                    ? 'TikTok direct posts need the video.publish scope; this token only has video.upload (Drafts).'
                    : 'TikTok drafts need the video.upload scope; this token only has video.publish (use TikTokTarget::DirectPost or grant video.upload).',
            );
        }
    }

    // --- Credentials, scopes ---------------------------------------------------

    protected function requiredCredentials(): array
    {
        return ['access_token' => 'TikTok user access token'];
    }

    /** The creator's open_id identifies the account (stable across token refreshes). */
    protected function identityKeys(): array
    {
        return ['open_id'];
    }

    /**
     * Publishing needs video.publish (DirectPost) or video.upload (Drafts);
     * which one depends on the options, checked in validateTargetScope().
     * TikTok has no comment API.
     */
    protected function requiredScopes(Feature $feature, Credentials $credentials): array
    {
        return match ($feature) {
            Feature::Publish, Feature::Video => [['video.publish', 'video.upload']],
            default => [],
        };
    }

    // --- Publish ---------------------------------------------------------------

    public function publish(PreparedPost $post): PublishOutcome
    {
        $this->assertCredentials($post);
        $this->assertTokenFresh($post);

        $options = $this->options($post) ?? new TikTokOptions;
        $media = $post->media();

        if ($media === []) {
            throw new PermanentException('A TikTok post needs a video or at least one photo.', Platform::TikTok, FailureReason::Validation);
        }

        $isPhoto = $this->isPhotoPost($post);
        $direct = $options->target === TikTokTarget::DirectPost;
        $now = time();

        $state = [
            'direct' => $direct,
            'photo' => $isPhoto,
            'started_at' => $now,
            'deadline' => $now + $this->maxWait(),
        ];

        $creator = null;

        if ($direct) {
            $creator = $this->queryCreatorInfo($post);
            [$privacy, $fallback] = $this->resolvePrivacy($options, $creator);

            $state['privacy'] = $privacy->value;
            $username = $creator['creator_username'] ?? null;

            if (is_string($username) && $username !== '') {
                $state['username'] = $username;
            }

            if ($fallback !== null) {
                $state['privacy_requested'] = $options->privacyLevel?->value;
                $state['privacy_fallback_used'] = $fallback->value;
            }

            if (! $isPhoto) {
                $this->assertCreatorDuration($media[0], $creator);
            }
        }

        return $isPhoto
            ? $this->preparePhoto($post, $options, $media, $creator, $state)
            : $this->prepareVideo($post, $options, $media[0], $creator, $state);
    }

    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        $this->assertCredentials($post);

        $phase = (string) ($state['phase'] ?? '');
        $phase = static::PHASE_ALIASES[$phase] ?? $phase;

        return match ($phase) {
            self::PHASE_INIT => $this->resumeInit($post, $state),
            self::PHASE_PREPARE_UPLOAD => $this->resumePrepareUpload($post, $state),
            self::PHASE_UPLOAD => $this->resumeUpload($post, $state),
            self::PHASE_AWAIT => $this->resumeAwait($post, $state),
            self::PHASE_AWAIT_PUBLIC_ID => $this->resumeAwaitPublicId($post, $state),
            default => throw new PermanentException(
                'Unknown TikTok publish phase.',
                Platform::TikTok,
                FailureReason::Unknown,
                ['phase' => $phase],
            ),
        };
    }

    // --- Preparing the init (no request to TikTok's create endpoints yet) -----

    /**
     * @param  Media[]  $media
     * @param  array<string, mixed>  $state
     */
    protected function preparePhoto(PreparedPost $post, TikTokOptions $options, array $media, ?array $creator, array $state): Pending
    {
        $caps = $this->rulesFor($post);
        $transformer = $this->mediaTransformer();
        $images = [];

        // TikTok takes JPEG and WebP only, and reads the format from the URL:
        // a PNG is converted first, and the converted file's URL ends in .jpg.
        try {
            foreach ($media as $index => $item) {
                $item = $transformer?->transform($item, Platform::TikTok, $caps) ?? $item;
                $url = $this->mediaUrl($item);

                $this->assertPullable($url, "photo #{$index}");
                $images[] = $url;
            }
        } finally {
            // Downloads made for a conversion are no longer needed once the
            // URLs exist (re-hosted copies are kept by the gateway).
            $this->gateway()?->cleanup();
        }

        // FilesystemMediaGateway copied the converted files to its disk; the
        // local conversions are no longer needed.
        if ($transformer instanceof ImageConvertingTransformer && $this->gateway() instanceof FilesystemMediaGateway) {
            $transformer->cleanup();
        }

        $direct = (bool) $state['direct'];

        $state['phase'] = self::PHASE_INIT;
        $state['transfer'] = self::PULL_FROM_URL;
        $state['endpoint'] = '/post/publish/content/init/';
        $state['body'] = [
            'post_info' => $direct
                ? $this->directPostInfo($post, $options, $creator, video: false)
                : $this->draftPhotoInfo($post),
            'source_info' => [
                'source' => self::PULL_FROM_URL,
                'photo_cover_index' => $options->photoCoverIndex,
                'photo_images' => $images,
            ],
            'post_mode' => $direct ? 'DIRECT_POST' : 'MEDIA_UPLOAD',
            'media_type' => 'PHOTO',
        ];

        // A photo's AI-generated label is a top-level content/init field (in
        // both post modes), not a post_info one as for videos.
        if ($options->isAigc !== null) {
            $state['body']['is_aigc'] = $options->isAigc;
        }

        return $this->commitInit($state);
    }

    /** @param  array<string, mixed>  $state */
    protected function prepareVideo(PreparedPost $post, TikTokOptions $options, Media $video, ?array $creator, array $state): Pending
    {
        $direct = (bool) $state['direct'];

        // Direct post -> /video/init/ with post_info. Draft -> /inbox/video/init/, no post_info.
        $state['endpoint'] = $direct ? '/post/publish/video/init/' : '/post/publish/inbox/video/init/';
        $state['body'] = $direct
            ? ['post_info' => $this->directPostInfo($post, $options, $creator, video: true)]
            : [];

        if ($video->thumbnail !== null && trim($video->thumbnail) !== '') {
            // A draft carries no post_info, so coverTimestampMs cannot reach it.
            $state['thumbnail_warning'] = $direct
                ? 'TikTok has no cover-image upload, so the video thumbnail was not used: pick a frame with TikTokOptions::coverTimestampMs.'
                : 'TikTok has no cover-image upload, so the video thumbnail was not used: the creator picks the cover in the TikTok app when finishing the draft (TikTokOptions::coverTimestampMs applies to direct posts only).';
        }

        if (! $direct && trim((string) $post->caption()) !== '') {
            $state['caption_ignored'] = true; // inbox videos carry no caption; the creator writes it in-app
        }

        $transfer = $this->videoTransfer($video, $options);

        if ($transfer === self::PULL_FROM_URL) {
            $url = $this->mediaUrl($video);

            if ($this->isHttps($url)) {
                $state['transfer'] = self::PULL_FROM_URL;
                $state['auto'] = $options->transfer === TikTokTransfer::Auto;
                $state['body']['source_info'] = ['source' => self::PULL_FROM_URL, 'video_url' => $url];

                return $this->commitInit($state);
            }

            if ($options->transfer === TikTokTransfer::PullFromUrl) {
                $this->assertPullable($url, 'video');
            }

            // Auto: TikTok cannot pull a non-https URL, so upload the bytes.
        }

        return $this->prepareFileUpload($post, $video, $state);
    }

    /**
     * FILE_UPLOAD: a local copy of the video, its size and chunk plan, then the
     * init commit. The copy stays until the upload phase has sent every chunk.
     *
     * @param  array<string, mixed>  $state
     */
    protected function prepareFileUpload(PreparedPost $post, Media $video, array $state): Pending
    {
        $path = null;
        $owned = false;

        try {
            [$path, $owned] = $this->localUploadFile($video);
            $size = $this->uploadSize($path);
        } catch (Throwable $e) {
            if ($owned && $path !== null) {
                @unlink($path);
            }

            throw $e;
        } finally {
            $this->gateway()?->cleanup();
        }

        [$chunkSize, $totalChunks] = $this->chunkPlan($size);

        $state['phase'] = self::PHASE_INIT;
        $state['transfer'] = self::FILE_UPLOAD;
        $state['body'] = (array) ($state['body'] ?? []);
        $state['body']['source_info'] = [
            'source' => self::FILE_UPLOAD,
            'video_size' => $size,
            'chunk_size' => $chunkSize,
            'total_chunk_count' => $totalChunks,
        ];
        $state['upload'] = [
            'path' => $path,
            'owned' => $owned,
            'size' => $size,
            'chunk_size' => $chunkSize,
            'total_chunks' => $totalChunks,
            'mime' => $this->videoMime($video),
            'offset' => 0,
        ];

        return $this->commitInit($state);
    }

    // --- Phase: init (the create) ----------------------------------------------

    /**
     * The init, and the end of the video copy kept for a FILE_UPLOAD when the
     * init fails for good or restarts: no later run reads it (a restart
     * fetches the video again). A retried init still needs it.
     *
     * @param  array<string, mixed>  $state
     */
    protected function resumeInit(PreparedPost $post, array $state): PublishOutcome
    {
        try {
            return $this->sendInit($post, $state);
        } catch (SocialPosterException $e) {
            if ($e instanceof PermanentException || ($e->context['restart'] ?? false) === true) {
                $this->discardUploadFile($state);
            }

            throw $e;
        }
    }

    /** @param  array<string, mixed>  $state */
    protected function sendInit(PreparedPost $post, array $state): PublishOutcome
    {
        $transfer = (string) ($state['transfer'] ?? self::PULL_FROM_URL);

        // A FILE_UPLOAD init that may have gone through left an upload task with
        // no bytes, which never publishes: initialising again is safe. A pull
        // init may already be downloading and posting, and TikTok cannot be
        // asked about it.
        if (Pending::isRecovering($state) && $transfer !== self::FILE_UPLOAD) {
            throw new PermanentException(
                "TikTok may already have accepted this post: the previous init request was interrupted, and TikTok offers no way to look an init up. Check the creator's ".(($state['direct'] ?? false) ? 'profile' : 'inbox').' (or the post.publish webhooks) before posting it again.',
                Platform::TikTok,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'transfer' => $transfer, 'target' => ($state['direct'] ?? false) ? 'direct_post' : 'drafts'],
            );
        }

        $endpoint = (string) ($state['endpoint'] ?? '');
        $body = (array) ($state['body'] ?? []);

        if ($endpoint === '' || ! isset($body['source_info'])) {
            throw new PermanentException('The TikTok init state is incomplete.', Platform::TikTok, FailureReason::Unknown, ['phase' => self::PHASE_INIT]);
        }

        // An empty draft post_info must go out as {} rather than [].
        if (array_key_exists('post_info', $body) && $body['post_info'] === []) {
            $body['post_info'] = new \stdClass;
        }

        $response = $this->tt($post)->post($this->api.$endpoint, $body);

        if (! $this->ok($response)) {
            return $this->initRefused($state, $response, (array) ($state['body'] ?? []));
        }

        $publishId = $this->requireId($response, 'publish', 'data.publish_id', ambiguous: $transfer !== self::FILE_UPLOAD);
        $now = time();

        $next = $state;
        unset($next['body'], $next['endpoint'], $next['init_attempts'], $next['init_reresolved'], $next['auto']);
        $next['publish_id'] = $publishId;
        $next['init_at'] = $now;
        $next['deadline'] = $now + $this->maxWait();
        $next['polls'] = 0;

        if ($transfer === self::FILE_UPLOAD) {
            try {
                $next['upload_url'] = $this->requireId($response, 'upload URL', 'data.upload_url');
            } catch (TemporaryException $e) {
                // No bytes can ever reach this publish_id, so nothing will post:
                // start over with a new init.
                throw $this->restartOrFail($state, $e->getMessage(), FailureReason::ServerError, ['possibly_published' => false] + $e->context + ['publish_id' => $publishId]);
            }

            // TikTok starts publishing only once it holds every byte. Until the
            // last chunk is next, nothing can go live, so the upload runs are
            // not committed: a failure there is a clean one, and the post may
            // be dispatched again. A single chunk is the last one.
            $upload = (array) ($next['upload'] ?? []);
            $committed = (int) ($upload['total_chunks'] ?? 0) <= 1;
            $upload['committed'] = $committed;
            $next['upload'] = $upload;
            $next['phase'] = self::PHASE_UPLOAD;

            return $this->wait($next, 0, committed: $committed);
        }

        $next['phase'] = self::PHASE_AWAIT;

        return $this->wait($next, $this->pollDelay(0));
    }

    /**
     * TikTok refused the init. When it answered with an error code (or a 429 /
     * 503), it never created the publish job, so the create can run again
     * without duplicating the post: rather than throw (a retried commit step
     * looks like an interrupted create), wait as a fresh commit step, at most
     * social.api.tiktok.init_retries times.
     *
     * @param  mixed  $response
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $sent
     */
    protected function initRefused(array $state, $response, array $sent): PublishOutcome
    {
        $exception = $this->mapError($response, $sent);
        $code = $this->errorCode($response);

        // Auto transfer: an unverified pull host falls back to FILE_UPLOAD, once.
        if ($code === 'url_ownership_unverified'
            && ($state['auto'] ?? false) === true
            && ($state['transfer'] ?? null) === self::PULL_FROM_URL
            && ! ($state['photo'] ?? false)
            && ! isset($state['fallback'])) {
            $state['phase'] = self::PHASE_PREPARE_UPLOAD;
            $state['fallback'] = 'url_ownership_unverified';

            return $this->wait($state, 0, committed: false);
        }

        $definitive = $code !== '' || in_array((int) $response->status(), [429, 503], true);

        if (! $exception instanceof TemporaryException || ! $definitive) {
            throw $exception;
        }

        // A rejected token is retried once, with the credentials the job re-resolves.
        if (($exception->context['reresolve'] ?? false) === true) {
            if (($state['init_reresolved'] ?? false) === true) {
                $previous = $exception->getPrevious();

                throw $previous instanceof SocialPosterException ? $previous : $exception;
            }

            $state['init_reresolved'] = true;

            return $this->commitInit($state, 0);
        }

        $attempts = (int) ($state['init_attempts'] ?? 0);

        if ($attempts >= $this->initRetries()) {
            throw new PermanentException(
                rtrim($exception->getMessage(), '.')." (still refused after {$attempts} retries; giving up).",
                Platform::TikTok,
                $exception->reason,
                $exception->context + ['init_attempts' => $attempts + 1],
                $exception,
            );
        }

        $state['init_attempts'] = $attempts + 1;

        return $this->commitInit($state, $this->clampDelay($exception->retryAfter ?? 60));
    }

    /**
     * After an url_ownership_unverified refusal under TikTokTransfer::Auto: get
     * the file and initialise again as FILE_UPLOAD.
     *
     * @param  array<string, mixed>  $state
     */
    protected function resumePrepareUpload(PreparedPost $post, array $state): PublishOutcome
    {
        $video = $post->media()[0] ?? null;

        if ($video === null || $video->type !== MediaType::Video) {
            throw new PermanentException('The TikTok upload fallback needs the video.', Platform::TikTok, FailureReason::Unknown, ['phase' => self::PHASE_PREPARE_UPLOAD]);
        }

        $state['body'] = (array) ($state['body'] ?? []);
        unset($state['body']['source_info'], $state['init_attempts'], $state['auto']);

        return $this->prepareFileUpload($post, $video, Pending::withoutReservedKeys($state));
    }

    // --- Phase: upload (FILE_UPLOAD chunks) -------------------------------------

    /**
     * Send chunks within the run budget. The last chunk completes the upload,
     * and TikTok starts publishing then: only a committed run sends it (the
     * driver hands over first when its run is not committed), so a failure
     * from then on is reported as possibly published. A failure in a run that
     * has not reached the last chunk is not: nothing can be live yet.
     *
     * @param  array<string, mixed>  $state
     */
    protected function resumeUpload(PreparedPost $post, array $state): PublishOutcome
    {
        $upload = (array) ($state['upload'] ?? []);
        $uploadUrl = (string) ($state['upload_url'] ?? '');
        $size = (int) ($upload['size'] ?? 0);
        $chunkSize = (int) ($upload['chunk_size'] ?? 0);
        $total = (int) ($upload['total_chunks'] ?? 0);
        $offset = max(0, (int) ($upload['offset'] ?? 0));
        $mime = (string) ($upload['mime'] ?? 'video/mp4');
        $context = ['publish_id' => $state['publish_id'] ?? null];

        // Whether the job holds this run as committed (states without the
        // flag were all committed).
        $committedRun = (bool) ($upload['committed'] ?? true);

        if ($uploadUrl === '' || $size <= 0 || $chunkSize <= 0 || $total <= 0) {
            throw $this->uploadFailure($this->restartOrFail($state, 'The TikTok upload state is incomplete.', FailureReason::Unknown, $context), $committedRun);
        }

        $started = microtime(true);
        $sent = 0;
        $resyncs = 0;
        $path = null;
        $owned = (bool) ($upload['owned'] ?? false);

        try {
            [$path, $owned] = $this->uploadFileFor($post, $upload, $state);

            while ($offset < $size && ($sent === 0 || $this->budgetLeft($started))) {
                [$start, $length, $isLast] = $this->chunkAt($offset, $size, $chunkSize, $total);

                if ($isLast && ! $committedRun) {
                    break; // hand over: a committed run sends the last chunk
                }

                $chunk = $this->readChunk($path, $start, $length);

                if (strlen($chunk) !== $length) {
                    throw $this->restartOrFail($state, 'The video file changed during the TikTok upload.', FailureReason::MediaRejected, $context + ['offset' => $start, 'read' => strlen($chunk), 'expected' => $length]);
                }

                $response = $this->putChunk($uploadUrl, $chunk, $start, $size, $mime, $context, $started);
                $status = $response->status();
                $sent++;

                $accepted = $status >= 200 && $status < 300;

                // 201: every part received (possibly earlier than we thought).
                if ($status === 201) {
                    $offset = $size;
                    break;
                }

                // 206 (partial content) between chunks.
                if ($accepted && ! $isLast) {
                    $offset = $start + $length;

                    continue;
                }

                // 416, or the last chunk answered without 201: TikTok's count
                // differs from ours. Its Content-Range says what it holds;
                // continue from there.
                if ($status === 416 || $accepted) {
                    $received = $this->receivedBytes($response);

                    if ($received === null && $status !== 416 && $status !== 206) {
                        $offset = $size; // a plain 2xx that names no missing range
                        break;
                    }

                    if ($received === null || ++$resyncs > 3) {
                        throw $this->restartOrFail($state, 'TikTok and the upload disagree on the bytes received.', FailureReason::ServerError, $context + ['http_status' => $status, 'offset' => $start]);
                    }

                    $offset = $received >= $size ? $size : $this->chunkStart($received, $chunkSize, $total);

                    continue;
                }

                throw $this->chunkError($state, $response, $context + ['offset' => $start, 'chunk_length' => $length]);
            }
        } catch (Throwable $e) {
            // Retrying the phase reuses the file; a restart or a final failure does not.
            $retrying = $e instanceof TemporaryException && ! ($e->context['restart'] ?? false);

            if ($owned && $path !== null && ! $retrying) {
                @unlink($path);
            }

            throw $e instanceof SocialPosterException ? $this->uploadFailure($e, $committedRun) : $e;
        } finally {
            $this->gateway()?->cleanup();
        }

        if ($offset < $size) {
            // Run budget spent, or the last chunk is next: the next run
            // continues, as a committed one once the last chunk is next.
            $commit = $committedRun || $this->chunkAt($offset, $size, $chunkSize, $total)[2];

            $upload['path'] = $path;
            $upload['owned'] = $owned;
            $upload['offset'] = $offset;
            $upload['committed'] = $commit;
            $state['upload'] = $upload;

            return $this->wait($state, 0, committed: $commit);
        }

        if ($owned) {
            @unlink($path);
        }

        // TikTok processes the upload from now on.
        $now = time();
        unset($state['upload_url']);
        $state['upload'] = ['size' => $size, 'total_chunks' => $total];
        $state['uploaded_at'] = $now;
        $state['deadline'] = $now + $this->maxWait();
        $state['polls'] = 0;
        $state['phase'] = self::PHASE_AWAIT;

        return $this->wait($state, $this->pollDelay(0));
    }

    /**
     * An upload failure, flagged not published when no run has reached the
     * last chunk: TikTok cannot publish a FILE_UPLOAD it lacks bytes of. Once
     * the last chunk may have gone out, the job's committed flag decides.
     */
    protected function uploadFailure(SocialPosterException $e, bool $committedRun): SocialPosterException
    {
        return $committedRun ? $e : $e->withFlags(['possibly_published' => false]);
    }

    /**
     * PUT one chunk, retried on 5xx and connection errors (TikTok: "retry the
     * same chunk"), at most social.api.tiktok.chunk_attempts times, and only
     * while the run that started at $runStartedAt is within its upload budget
     * (see mayRetryChunk()). The upload_url is pre-signed: no bearer token is
     * sent to the upload host.
     *
     * @param  array<string, mixed>  $context
     * @return Response
     */
    protected function putChunk(string $uploadUrl, string $chunk, int $start, int $size, string $mime, array $context = [], ?float $runStartedAt = null)
    {
        $end = $start + strlen($chunk) - 1;
        $attempts = max(1, (int) $this->apiConfig('chunk_attempts', 3));

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = Http::withHeaders(['Content-Range' => "bytes {$start}-{$end}/{$size}"])
                    ->connectTimeout((int) $this->apiConfig('connect_timeout', 10))
                    ->timeout($this->uploadTimeout())
                    ->withBody($chunk, $mime)
                    ->put($uploadUrl);
            } catch (ConnectionException $e) {
                if (! $this->mayRetryChunk($attempt, $attempts, $runStartedAt)) {
                    throw TemporaryException::connection(Platform::TikTok, $e)->addContext($context + ['offset' => $start, 'chunk_attempts' => $attempt]);
                }

                $this->pauseBeforeRetry($attempt);

                continue;
            }

            if ($response->serverError() && $this->mayRetryChunk($attempt, $attempts, $runStartedAt)) {
                $this->pauseBeforeRetry($attempt);

                continue;
            }

            return $response;
        }
    }

    /**
     * Whether to try the same chunk again in this run: attempts remain and the
     * retry, after its pause, still starts within the run's upload budget. An
     * attempt may last upload_timeout, and the job's timeout leaves room for
     * one call past the budget (PublishToConnectionJob::configuredTimeout()),
     * not several: past the budget the phase goes back to the queue as a
     * retry, rather than have the worker killed mid-upload.
     */
    protected function mayRetryChunk(int $attempt, int $attempts, ?float $runStartedAt): bool
    {
        if ($attempt >= $attempts) {
            return false;
        }

        return $runStartedAt === null || $this->budgetLeft($runStartedAt - $this->chunkRetryPauseMs($attempt) / 1000);
    }

    /**
     * A failed chunk after its retries: 400 is a malformed request (a bug in
     * the chunk plan, permanent); 403 (upload_url expired) and 404 (no upload
     * task) need a new init; 5xx is retried by running the phase again.
     *
     * @param  mixed  $response
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $context
     */
    protected function chunkError(array $state, $response, array $context): TemporaryException|PermanentException
    {
        $status = (int) $response->status();
        $context += ['http_status' => $status, 'status' => $status];

        $exception = match (true) {
            $status === 403, $status === 404 => $this->restartOrFail(
                $state,
                $status === 403 ? 'The TikTok upload URL expired.' : 'TikTok no longer has this upload task.',
                FailureReason::NotFound,
                $context,
            ),
            $status === 400 => new PermanentException('TikTok rejected a video chunk as malformed (HTTP 400).', Platform::TikTok, FailureReason::Unknown, $context),
            $status >= 500 || $status === 408 || $status === 429 => new TemporaryException(
                "TikTok's upload host failed (HTTP {$status}).",
                Platform::TikTok,
                $status === 429 ? FailureReason::RateLimited : FailureReason::ServerError,
                RetryAfter::fromResponse($response) ?? 30,
                $context,
            ),
            default => new PermanentException("TikTok rejected a video chunk upload (HTTP {$status}).", Platform::TikTok, FailureReason::Unknown, $context),
        };

        $this->reportError($response, $exception);

        return $exception;
    }

    /** Wait between attempts at the same chunk: social.api.tiktok.chunk_retry_ms × attempt. */
    protected function pauseBeforeRetry(int $attempt): void
    {
        $ms = $this->chunkRetryPauseMs($attempt);

        if ($ms > 0) {
            Sleep::usleep($ms * 1000);
        }
    }

    protected function chunkRetryPauseMs(int $attempt): int
    {
        return max(0, (int) $this->apiConfig('chunk_retry_ms', 2000)) * $attempt;
    }

    /**
     * The local file the upload reads, re-fetched through the gateway when a
     * downloaded copy is gone (another worker host, a cleanup); its size must
     * not have changed.
     *
     * @param  array<string, mixed>  $upload
     * @param  array<string, mixed>  $state
     * @return array{0: string, 1: bool} [path, owned]
     */
    protected function uploadFileFor(PreparedPost $post, array $upload, array $state): array
    {
        $path = (string) ($upload['path'] ?? '');
        $size = (int) ($upload['size'] ?? 0);
        $owned = (bool) ($upload['owned'] ?? false);

        if ($path !== '') {
            clearstatcache(true, $path);

            if (is_file($path) && @filesize($path) === $size) {
                return [$path, $owned];
            }
        }

        $video = $post->media()[0] ?? null;

        if ($video === null) {
            throw new PermanentException('The TikTok upload needs the video.', Platform::TikTok, FailureReason::Unknown, ['phase' => self::PHASE_UPLOAD]);
        }

        [$path, $owned] = $this->localUploadFile($video);
        clearstatcache(true, $path);

        if (@filesize($path) !== $size) {
            if ($owned) {
                @unlink($path);
            }

            throw $this->restartOrFail($state, 'The video file changed size during the TikTok upload.', FailureReason::MediaRejected, ['publish_id' => $state['publish_id'] ?? null, 'expected_size' => $size]);
        }

        return [$path, $owned];
    }

    /**
     * The local file to upload. A temporary copy the gateway made (download,
     * disk stream) is moved under this driver's own name, because the gateway
     * is shared and its cleanup() would delete the copy before later runs send
     * the rest. Such a file is "owned" and deleted once the upload ends, or
     * once the init fails for good or restarts; social-poster:prune-temp is
     * the backstop for a chain the job ends (sp_tiktok_<16 hex> files).
     *
     * @return array{0: string, 1: bool} [path, owned]
     */
    protected function localUploadFile(Media $video): array
    {
        $path = $this->mediaPath($video);

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1 && ! str_starts_with(strtolower($path), 'file://')) {
            throw new PermanentException(
                'TikTok FILE_UPLOAD needs the video bytes, but the media is a remote URL and no MediaGateway downloaded it. Bind a MediaGateway (the default LocalMediaGateway downloads remote media).',
                Platform::TikTok,
                FailureReason::Misconfigured,
            );
        }

        if ($path === $video->source || ! str_starts_with(basename($path), 'sp_')) {
            return [$path, false];
        }

        $extension = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($path, PATHINFO_EXTENSION))) ?? '';
        $kept = dirname($path).DIRECTORY_SEPARATOR.'sp_tiktok_'.bin2hex(random_bytes(8)).($extension !== '' ? '.'.$extension : '');

        return @rename($path, $kept) ? [$kept, true] : [$path, true];
    }

    /**
     * Delete the video copy kept for a FILE_UPLOAD (never the caller's own
     * file) once no later run will read it.
     *
     * @param  array<string, mixed>  $state
     */
    protected function discardUploadFile(array $state): void
    {
        $upload = (array) ($state['upload'] ?? []);
        $path = (string) ($upload['path'] ?? '');

        if ((bool) ($upload['owned'] ?? false) && $path !== '') {
            @unlink($path);
        }
    }

    /**
     * Where the chunk holding $offset starts, how long it is and whether it is
     * the last one (which carries the remainder).
     *
     * @return array{0: int, 1: int, 2: bool} [start, length, isLast]
     */
    protected function chunkAt(int $offset, int $size, int $chunkSize, int $total): array
    {
        $start = $this->chunkStart($offset, $chunkSize, $total);
        $isLast = intdiv($start, $chunkSize) >= $total - 1;

        return [$start, $isLast ? $size - $start : $chunkSize, $isLast];
    }

    /** The start of the chunk that contains byte $offset. */
    protected function chunkStart(int $offset, int $chunkSize, int $total): int
    {
        return min(intdiv(max(0, $offset), $chunkSize), max(0, $total - 1)) * $chunkSize;
    }

    /**
     * Bytes TikTok holds, from the response's Content-Range ("bytes 0-N/size",
     * or "bytes *\/size" for none), or null when it does not say.
     *
     * @param  mixed  $response
     */
    protected function receivedBytes($response): ?int
    {
        $header = trim((string) ($response->header('Content-Range') ?: $response->header('Range')));

        if (preg_match('/bytes[\s=]+(\d+)-(\d+)/i', $header, $m) === 1) {
            return (int) $m[2] + 1;
        }

        return preg_match('/bytes[\s=]+\*/i', $header) === 1 ? 0 : null;
    }

    // --- Phase: await_publish (status poll) ------------------------------------

    /** @param  array<string, mixed>  $state */
    protected function resumeAwait(PreparedPost $post, array $state): PublishOutcome
    {
        $publishId = trim((string) ($state['publish_id'] ?? ''));

        if ($publishId === '') {
            throw new PermanentException(
                'TikTok accepted the post but no publish_id was kept, so its status cannot be checked; it may be live.',
                Platform::TikTok,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true],
            );
        }

        // Past the deadline, this run still takes one last look (the job
        // schedules its final run at the deadline): a post that completed in
        // the last interval is published, not timed out. Only a status that
        // would need another poll ends in ProcessingTimeout.
        $response = $this->fetchStatus($post, $publishId);

        if (! $this->ok($response)) {
            // TikTok allows 30 status reads a minute per token: wait, without
            // using a retry.
            if ($this->rateLimited($response)) {
                $this->assertWithinDeadline($state, $publishId);

                return $this->wait($state, $this->clampDelay(RetryAfter::fromResponse($response) ?? 60));
            }

            throw $this->mapError($response, ['publish_id' => $publishId])
                ->addContext(['publish_id' => $publishId, 'possibly_published' => true]);
        }

        $data = (array) $response->json('data', []);
        $status = (string) ($data['status'] ?? '');
        $state = $this->withProgress($state, $data);

        return match ($status) {
            'PUBLISH_COMPLETE' => $this->publishComplete($state, $data),
            'SEND_TO_USER_INBOX' => $this->publishedResult($state, null, $status, PostResult::STATE_DRAFT),
            'FAILED' => throw $this->publishFailed($state, $data, $response),
            default => $this->keepPolling($state),
        };
    }

    /**
     * PUBLISH_COMPLETE: the post is live. A public post shows its video id only
     * after moderation, so a direct PUBLIC_TO_EVERYONE post without one waits
     * for it (await_public_id); other privacy levels never get one.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $data
     */
    protected function publishComplete(array $state, array $data): PublishOutcome
    {
        $videoId = $this->publicPostId($data);

        if ($videoId !== null) {
            return $this->publishedResult($state, $videoId, 'PUBLISH_COMPLETE');
        }

        $wait = $this->publicIdWait();

        if (($state['direct'] ?? false) === true && ($state['privacy'] ?? null) === TikTokPrivacy::PublicToEveryone->value && $wait > 0) {
            $now = time();
            $state['phase'] = self::PHASE_AWAIT_PUBLIC_ID;
            $state['complete_at'] = $now;
            $state['deadline'] = $now + $wait + 60;
            $state['polls'] = 0;

            return $this->wait($state, $this->publicIdInterval());
        }

        $kind = ($state['privacy'] ?? null) === TikTokPrivacy::SelfOnly->value
            ? PostResult::STATE_PRIVATE
            : PostResult::STATE_PUBLISHED;

        return $this->publishedResult($state, null, 'PUBLISH_COMPLETE', $kind);
    }

    /**
     * FAILED: the fail_reason table. internal and *_pull_failed are worth a
     * fresh publish (a new init), at most social.api.tiktok.max_restarts times;
     * everything else is permanent. A post already seen live ($live: after
     * PUBLISH_COMPLETE) is never published again: its failure is permanent and
     * flagged possibly published.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $data
     * @param  mixed  $response
     */
    protected function publishFailed(array $state, array $data, $response, bool $live = false): TemporaryException|PermanentException
    {
        $reason = trim((string) ($data['fail_reason'] ?? ''));
        $reason = $reason !== '' ? $reason : 'unknown';
        $context = ['publish_id' => $state['publish_id'] ?? null, 'fail_reason' => $reason, 'status' => 'FAILED'];

        // Before PUBLISH_COMPLETE, FAILED is TikTok saying nothing was posted:
        // say so explicitly, or the job would take the committed chain (a
        // publish_id exists) as possibly published and refuse a re-dispatch.
        $exception = match (true) {
            $live => TikTokFailReason::exception($reason, $context + ['possibly_published' => true]),
            TikTokFailReason::restartable($reason) => $this->restartOrFail($state, TikTokFailReason::message($reason), TikTokFailReason::reason($reason), $context + ['possibly_published' => false], 60),
            default => TikTokFailReason::exception($reason, $context + ['possibly_published' => false]),
        };

        $this->reportError($response, $exception);

        return $exception;
    }

    /**
     * Still processing: poll again, unless the deadline passed (this was the
     * last look).
     *
     * @param  array<string, mixed>  $state
     */
    protected function keepPolling(array $state): Pending
    {
        $this->assertWithinDeadline($state, (string) ($state['publish_id'] ?? ''));

        $polls = (int) ($state['polls'] ?? 0) + 1;
        $state['polls'] = $polls;

        return $this->wait($state, $this->pollDelay($polls));
    }

    // --- Phase: await_public_id -----------------------------------------------

    /**
     * The post is live; only its public id is missing. Nothing here publishes
     * again: when the id does not come within the wait (or the poll fails for
     * good), the result is the publish_id with public_id_pending; a FAILED
     * status TikTok reports after PUBLISH_COMPLETE is a permanent failure
     * flagged possibly published, never a restart.
     *
     * @param  array<string, mixed>  $state
     */
    protected function resumeAwaitPublicId(PreparedPost $post, array $state): PublishOutcome
    {
        $publishId = (string) ($state['publish_id'] ?? '');
        $completeAt = (int) ($state['complete_at'] ?? time());

        if ($publishId === '' || time() - $completeAt >= $this->publicIdWait()) {
            return $this->publicIdPending($state);
        }

        try {
            $response = $this->fetchStatus($post, $publishId);
        } catch (ConnectionException) {
            return $this->wait($state, $this->publicIdInterval());
        }

        if (! $this->ok($response)) {
            if ($this->rateLimited($response)) {
                return $this->wait($state, $this->clampDelay(RetryAfter::fromResponse($response) ?? 60));
            }

            $exception = $this->mapError($response, ['publish_id' => $publishId]);

            return $exception instanceof TemporaryException
                ? $this->wait($state, $this->clampDelay($exception->retryAfter ?? $this->publicIdInterval()))
                : $this->publicIdPending($state + ['public_id_error' => $this->errorCode($response) ?: $exception->reason->value]);
        }

        $data = (array) $response->json('data', []);
        $videoId = $this->publicPostId($data);

        if ($videoId !== null) {
            return $this->publishedResult($state, $videoId, 'PUBLISH_COMPLETE');
        }

        if ((string) ($data['status'] ?? '') === 'FAILED') {
            throw $this->publishFailed($state, $data, $response, live: true);
        }

        $state['polls'] = (int) ($state['polls'] ?? 0) + 1;

        return $this->wait($state, $this->publicIdInterval());
    }

    /** @param  array<string, mixed>  $state */
    protected function publicIdPending(array $state): Published
    {
        return $this->publishedResult($state + ['public_id_pending' => true], null, 'PUBLISH_COMPLETE', PostResult::STATE_PROCESSING);
    }

    // --- Results ------------------------------------------------------------------

    /**
     * The terminal result. With a public video id it is the post id (idKind
     * video_id) and the permalink is built from the creator's username; without
     * one the publish_id is the only handle (idKind publish_id), as for drafts.
     * The payload keeps publish_id for webhook reconciliation.
     *
     * @param  array<string, mixed>  $state
     */
    protected function publishedResult(array $state, ?string $videoId, string $status, ?string $kind = null): Published
    {
        $publishId = (string) ($state['publish_id'] ?? '');

        $payload = [
            'status' => $status,
            'publish_id' => $publishId,
            'post_id' => $videoId,
        ];

        foreach (['privacy', 'privacy_requested', 'privacy_fallback_used', 'transfer', 'fallback', 'thumbnail_warning', 'caption_ignored', 'public_id_pending', 'public_id_error'] as $key) {
            if (isset($state[$key])) {
                $payload[$key] = $state[$key];
            }
        }

        if (isset($state['username'])) {
            $payload['creator_username'] = $state['username'];
        }

        $username = isset($state['username']) ? (string) $state['username'] : null;

        if ($videoId !== null) {
            return $this->published(PostResult::success(
                Platform::TikTok,
                $videoId,
                static::permalink($username, $videoId),
                $payload,
                PostResult::STATE_PUBLISHED,
                'video_id',
            ));
        }

        return $this->published(PostResult::success(
            Platform::TikTok,
            $publishId,
            null,
            $payload,
            $kind ?? PostResult::STATE_PUBLISHED,
            'publish_id',
        ));
    }

    /**
     * https://www.tiktok.com/@{username}/video/{id}, or null unless the id is
     * numeric and the username looks like a TikTok handle (the same guards the
     * production app applies).
     */
    public static function permalink(?string $username, ?string $videoId): ?string
    {
        if ($username === null || $videoId === null) {
            return null;
        }

        if (preg_match('/^\d+$/', $videoId) !== 1 || preg_match('/^[\w.]{2,24}$/', $username) !== 1) {
            return null;
        }

        return "https://www.tiktok.com/@{$username}/video/{$videoId}";
    }

    /**
     * The public video id from status/fetch (TikTok spells the field
     * publicaly_available_post_id), as a string of digits, or null.
     *
     * @param  array<string, mixed>  $data
     */
    protected function publicPostId(array $data): ?string
    {
        $ids = $data['publicaly_available_post_id'] ?? $data['publicly_available_post_id'] ?? null;
        $id = is_array($ids) ? (array_values($ids)[0] ?? null) : $ids;

        if (is_int($id)) {
            return (string) $id;
        }

        return is_string($id) && preg_match('/^\d+$/', trim($id)) === 1 ? trim($id) : null;
    }

    // --- post_info builders ----------------------------------------------------

    /**
     * post_info for DIRECT_POST. Layering, last wins: the branded-content
     * defaults (false, social.api.tiktok.send_brand_defaults), the extra() bag,
     * the typed disclosure options, then the driver's computed keys (text,
     * privacy, the honoured disables).
     *
     * The privacy level is resolved here from the same options and creator
     * info as publish() did (with the same fallback). The signature is v1.0's,
     * so subclasses that override this hook keep loading.
     *
     * @return array<string, mixed>
     */
    protected function directPostInfo(PreparedPost $post, TikTokOptions $options, ?array $creator, bool $video): array
    {
        $creator ??= [];
        $privacy = $this->resolvePrivacy($options, $creator)[0];
        $caption = (string) $post->caption();

        $computed = ['privacy_level' => $privacy->value];

        // A video's caption is its title (2200); a photo's goes in description
        // (4000) and ->title() is its title (90). Blank text is left out.
        if ($video) {
            if (trim($caption) !== '') {
                $computed['title'] = $caption;
            }
        } else {
            if (trim($caption) !== '') {
                $computed['description'] = $caption;
            }

            $title = (string) $post->title();

            if (trim($title) !== '') {
                $computed['title'] = $title;
            }
        }

        // The disable_* toggles are optional. Only send one when the creator
        // forces it off account-wide, or when the caller explicitly set it.
        // Sending disable_duet=false on a SELF_ONLY post contradicts TikTok
        // (private posts can't be dueted or stitched) and is rejected as
        // invalid_params, so by default we omit them and let TikTok apply the
        // right value for the chosen privacy level.
        $resolve = static fn (bool $forced, ?bool $chosen): ?bool => $forced ? true : $chosen;

        $comment = $resolve((bool) ($creator['comment_disabled'] ?? false), $options->disableComment);
        if ($comment !== null) {
            $computed['disable_comment'] = $comment;
        }

        if ($video) {
            $duet = $resolve((bool) ($creator['duet_disabled'] ?? false), $options->disableDuet);
            if ($duet !== null) {
                $computed['disable_duet'] = $duet;
            }

            $stitch = $resolve((bool) ($creator['stitch_disabled'] ?? false), $options->disableStitch);
            if ($stitch !== null) {
                $computed['disable_stitch'] = $stitch;
            }

            if ($options->coverTimestampMs !== null) {
                $computed['video_cover_timestamp_ms'] = $options->coverTimestampMs;
            }
        } elseif ($options->autoAddMusic !== null) {
            $computed['auto_add_music'] = $options->autoAddMusic;
        }

        // Disclosure. TikTok documents brand_content_toggle as required on
        // direct posts and the production app has always sent both toggles as
        // false. (1.0 left them out after an invalid_params during a debugging
        // session that had other likely causes.) The typed options override
        // the defaults and extra(). is_aigc belongs in a video's post_info; a
        // photo carries it at the top level of content/init (preparePhoto()).
        $defaults = (bool) $this->apiConfig('send_brand_defaults', true)
            ? ['brand_content_toggle' => false, 'brand_organic_toggle' => false]
            : [];

        $typed = array_filter([
            'brand_content_toggle' => $options->brandContent,
            'brand_organic_toggle' => $options->brandOrganic,
            'is_aigc' => $video ? $options->isAigc : null,
        ], static fn ($value) => $value !== null);

        return array_merge($defaults, $this->extraPayload($post), $typed, $computed);
    }

    /**
     * post_info for a MEDIA_UPLOAD (inbox) photo: description and title only.
     * The branded-content toggles only work for DIRECT_POST, so they are
     * stripped from extra() here; the creator discloses in-app.
     *
     * @return array<string, mixed>
     */
    protected function draftPhotoInfo(PreparedPost $post): array
    {
        $computed = [];
        $caption = (string) $post->caption();
        $title = (string) $post->title();

        if (trim($caption) !== '') {
            $computed['description'] = $caption;
        }

        if (trim($title) !== '') {
            $computed['title'] = $title;
        }

        $extra = array_diff_key($this->extraPayload($post), array_flip(['brand_content_toggle', 'brand_organic_toggle']));

        return array_merge($extra, $computed);
    }

    /**
     * The privacy level to post with: the chosen one when the creator allows it
     * (or TikTok did not say), else the first allowed TikTokOptions fallback
     * (never SELF_ONLY for branded content).
     *
     * @return array{0: TikTokPrivacy, 1: ?TikTokPrivacy} [level, the fallback used or null]
     */
    protected function resolvePrivacy(TikTokOptions $options, ?array $creator): array
    {
        $privacy = $options->privacyLevel;

        if ($privacy === null) {
            throw new PermanentException('TikTok direct posts require a privacy level.', Platform::TikTok, FailureReason::Validation);
        }

        if ($options->brandContent === true && $privacy === TikTokPrivacy::SelfOnly) {
            throw new PermanentException('TikTok branded content cannot be private (SELF_ONLY).', Platform::TikTok, FailureReason::InvalidContent);
        }

        $allowed = array_values(array_filter((array) ($creator['privacy_level_options'] ?? []), 'is_string'));

        if ($allowed === [] || in_array($privacy->value, $allowed, true)) {
            return [$privacy, null];
        }

        foreach ($options->privacyFallbacks as $fallback) {
            if (! $fallback instanceof TikTokPrivacy || ($options->brandContent === true && $fallback === TikTokPrivacy::SelfOnly)) {
                continue;
            }

            if (in_array($fallback->value, $allowed, true)) {
                return [$fallback, $fallback];
            }
        }

        throw new PermanentException(
            "Privacy level {$privacy->value} is not available for this creator. Allowed: ".implode(', ', $allowed).'.',
            Platform::TikTok,
            FailureReason::InsufficientPermissions,
            // The creator's settings decide; reconnecting changes nothing.
            ['privacy_level' => $privacy->value, 'privacy_level_options' => $allowed, 'reconnect' => false],
        );
    }

    /**
     * TikTok's guidelines require checking a direct video against the
     * creator's own maximum duration (creator_info max_video_post_duration_sec)
     * before posting.
     */
    protected function assertCreatorDuration(Media $video, ?array $creator): void
    {
        $max = (int) ($creator['max_video_post_duration_sec'] ?? 0);

        if ($max <= 0) {
            return;
        }

        $duration = $this->inspectMedia($video)?->durationSeconds;

        if ($duration !== null && $duration > $max) {
            throw new PermanentException(
                "This creator can post videos up to {$max}s on TikTok; this one is ".round($duration, 1).'s.',
                Platform::TikTok,
                FailureReason::MediaRejected,
                ['max_video_post_duration_sec' => $max, 'duration' => $duration],
            );
        }
    }

    // --- Creator info ----------------------------------------------------------

    /** @return array<string, mixed> */
    protected function queryCreatorInfo(PreparedPost $post): array
    {
        // TikTok rejects a JSON [] body as a type error. Passing null makes
        // Laravel send a null body, which it accepts as "no parameters". Errors
        // such as spam_risk_too_many_posts come back as HTTP 200 with a code,
        // which ok() catches.
        $response = $this->tt($post)->post($this->api.'/post/publish/creator_info/query/', null);

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        return (array) $response->json('data', []);
    }

    // --- FILE_UPLOAD chunking --------------------------------------------------

    /** @return array{0: int, 1: int} [chunk_size, total_chunk_count] */
    protected function chunkPlan(int $size): array
    {
        // A whole file at or under the max chunk goes in a single chunk.
        if ($size <= $this->maxSingleChunk) {
            return [$size, 1];
        }

        // Equal chunks; the final chunk carries the remainder (TikTok allows the
        // last chunk to exceed chunk_size), so total = floor(size / chunk_size).
        $chunkSize = min($this->maxSingleChunk, max($this->minChunkSize, (int) $this->apiConfig('chunk_size', $this->uploadChunkSize)));
        $total = intdiv($size, $chunkSize);

        // A single chunk above the maximum is not valid: split it in two.
        if ($total < 2) {
            return [intdiv($size, 2), 2];
        }

        return [$chunkSize, $total];
    }

    // --- Helpers ---------------------------------------------------------------

    /**
     * Continue after $recheckAfter seconds. Every Pending after the init is
     * committed (TikTok holds a publish job), and carries the state's deadline.
     *
     * @param  array<string, mixed>  $state
     */
    protected function wait(array $state, int $recheckAfter, bool $committed = true): Pending
    {
        $state = Pending::withoutReservedKeys($state);
        $deadline = isset($state['deadline']) ? (int) $state['deadline'] : null;

        return PublishOutcome::pending($state, $recheckAfter, $committed, $deadline);
    }

    /**
     * Hand the init to its own commit step.
     *
     * @param  array<string, mixed>  $state
     */
    protected function commitInit(array $state, int $recheckAfter = 0): Pending
    {
        $state = Pending::withoutReservedKeys($state);
        $state['phase'] = self::PHASE_INIT;
        $deadline = isset($state['deadline']) ? (int) $state['deadline'] : null;

        return $this->commit($state, $recheckAfter, $deadline);
    }

    /**
     * A fresh publish (new init) through the job's restart, or, once
     * social.api.tiktok.max_restarts fresh publishes were spent (the job counts
     * them in _restarts), a permanent failure.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $context
     */
    protected function restartOrFail(array $state, string $message, FailureReason $reason, array $context = [], int $retryAfter = 30): TemporaryException|PermanentException
    {
        $restarts = (int) ($state[Pending::RESTARTS] ?? 0);
        $max = max(0, (int) $this->apiConfig('max_restarts', 2));

        if ($restarts >= $max) {
            return new PermanentException(
                rtrim($message, '.').". Gave up after {$restarts} fresh attempt(s).",
                Platform::TikTok,
                $reason,
                $context + ['restarts' => $restarts] + (isset($context['fail_reason']) ? TikTokFailReason::context((string) $context['fail_reason']) : []),
            );
        }

        return new TemporaryException($message, Platform::TikTok, $reason, $retryAfter, $context + ['restart' => true, 'restarts' => $restarts]);
    }

    /**
     * The deadline the driver declared passed: the job normally stops first;
     * this keeps a job that does not honour Pending deadlines from polling
     * forever, and names the publish_id for reconciliation.
     *
     * @param  array<string, mixed>  $state
     */
    protected function assertWithinDeadline(array $state, string $publishId): void
    {
        $deadline = isset($state['deadline']) ? (int) $state['deadline'] : null;

        if ($deadline === null || time() <= $deadline) {
            return;
        }

        throw new PermanentException(
            'TikTok was still processing the post when the wait ran out; it may still go live. Check the creator\'s profile or the post.publish webhooks for this publish_id.',
            Platform::TikTok,
            FailureReason::ProcessingTimeout,
            ['publish_id' => $publishId, 'possibly_published' => true, 'progress' => $state['progress'] ?? null],
        );
    }

    /** @return Response */
    protected function fetchStatus(PreparedPost $post, string $publishId)
    {
        return $this->tt($post)->post($this->api.'/post/publish/status/fetch/', [
            'publish_id' => $publishId,
        ]);
    }

    /**
     * Keep status/fetch's byte counters in the state, for diagnostics.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withProgress(array $state, array $data): array
    {
        $progress = array_filter([
            'status' => is_string($data['status'] ?? null) ? $data['status'] : null,
            'uploaded_bytes' => is_numeric($data['uploaded_bytes'] ?? null) ? (int) $data['uploaded_bytes'] : null,
            'downloaded_bytes' => is_numeric($data['downloaded_bytes'] ?? null) ? (int) $data['downloaded_bytes'] : null,
        ], static fn ($value) => $value !== null);

        if ($progress !== []) {
            $state['progress'] = $progress;
        }

        return $state;
    }

    /** Status poll backoff: 15s, doubling every 4 polls, at most 120s (configurable). */
    protected function pollDelay(int $polls): int
    {
        $base = max(1, (int) $this->apiConfig('poll_interval', 15));
        $cap = max($base, (int) $this->apiConfig('poll_max_interval', 120));

        return (int) min($cap, $base * 2 ** min(10, intdiv(max(0, $polls), 4)));
    }

    protected function tt(PreparedPost $post)
    {
        return $this->http($post)
            ->withHeaders(['Content-Type' => 'application/json; charset=UTF-8']);
    }

    protected function options(PreparedPost $post): ?TikTokOptions
    {
        return $this->optionsOf($post, TikTokOptions::class);
    }

    protected function target(PreparedPost $post): TikTokTarget
    {
        return $this->options($post)->target ?? TikTokTarget::Drafts;
    }

    /** A photo post: several items, or a single image. */
    protected function isPhotoPost(PreparedPost $post): bool
    {
        $media = $post->media();

        return count($media) > 1 || (count($media) === 1 && $media[0]->type === MediaType::Image);
    }

    /** PULL_FROM_URL or FILE_UPLOAD for a video, before the URL is resolved. */
    protected function videoTransfer(Media $video, TikTokOptions $options): string
    {
        return match ($options->transfer) {
            TikTokTransfer::FileUpload => self::FILE_UPLOAD,
            TikTokTransfer::PullFromUrl => self::PULL_FROM_URL,
            // A local file is uploaded (no domain verification needed, no
            // re-hosted copy); a remote https URL is pulled.
            TikTokTransfer::Auto => $video->isRemote() && $this->isHttps($video->source) && $this->canPull($video)
                ? self::PULL_FROM_URL
                : self::FILE_UPLOAD,
        };
    }

    /** Whether TikTok can be given a URL to pull this media from. */
    protected function canPull(Media $media): bool
    {
        return $this->gateway()?->canProvideUrl($media) ?? $media->isRemote();
    }

    /** Whether the gateway can turn a local file (a converted PNG) into a public URL. */
    protected function canHostLocalFiles(): bool
    {
        $gateway = $this->gateway();

        if ($gateway === null || $gateway::class === LocalMediaGateway::class) {
            return false;
        }

        if ($gateway instanceof FilesystemMediaGateway) {
            try {
                return $gateway->policy() !== FilesystemMediaGateway::REHOST_NEVER;
            } catch (Throwable) {
                return true; // a bad policy is reported by url() at publish time
            }
        }

        return true; // a custom gateway is trusted to publish what it is given
    }

    protected function isHttps(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    /** TikTok pulls over HTTPS only. */
    protected function assertPullable(string $url, string $what): void
    {
        if ($this->isHttps($url)) {
            return;
        }

        throw new PermanentException(
            "TikTok only pulls media over HTTPS (without redirects, from a domain or URL prefix verified in your TikTok app), and the {$what} URL is not https.",
            Platform::TikTok,
            FailureReason::MediaRejected,
        );
    }

    // TikTok returns HTTP 200 with an error body, so success is HTTP-ok AND a
    // clean error.code. This overrides the base ok() used by every other driver.
    protected function ok($response): bool
    {
        return $response->successful() && in_array($response->json('error.code'), ['ok', null], true);
    }

    /** @param  mixed  $response */
    protected function errorCode($response): string
    {
        try {
            $code = $response->json('error.code');
        } catch (Throwable) {
            return '';
        }

        return is_string($code) && $code !== 'ok' ? trim($code) : '';
    }

    /** @param  mixed  $response */
    protected function rateLimited($response): bool
    {
        return (int) $response->status() === 429 || $this->errorCode($response) === 'rate_limit_exceeded';
    }

    /**
     * @deprecated since 1.1: use Utf16Counter (CaptionRules counts with it).
     */
    protected function utf16Length(string $text): int
    {
        return (new Utf16Counter)->count($text);
    }

    protected function videoMime(Media $video): string
    {
        return match ($video->extension()) {
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            default => 'video/mp4',
        };
    }

    protected function maxWait(): int
    {
        return max(60, (int) $this->apiConfig('max_wait', 3900));
    }

    protected function publicIdWait(): int
    {
        return max(0, (int) $this->apiConfig('public_id_wait', 600));
    }

    protected function publicIdInterval(): int
    {
        return max(5, (int) $this->apiConfig('public_id_poll_interval', 30));
    }

    protected function initRetries(): int
    {
        return max(0, (int) $this->apiConfig('init_retries', 4));
    }

    protected function uploadTimeout(): int
    {
        return (int) $this->apiConfig('upload_timeout', config('social.upload_timeout', 300));
    }

    protected function videoTitleLimit(): int
    {
        return (int) $this->apiConfig('video_title_limit', $this->captionLimit);
    }

    protected function photoTitleLimit(): int
    {
        return (int) $this->apiConfig('photo_title_limit', 90);
    }

    protected function photoDescriptionLimit(): int
    {
        return (int) $this->apiConfig('photo_description_limit', 4000);
    }

    // --- Errors -------------------------------------------------------------------

    /**
     * TikTok's error codes, matched exactly first (1.0 matched substrings, which
     * read token_not_authorized_for_specified_publish_id as a bad token and
     * retried a banned creator). Substring rules remain only as a fallback for
     * codes TikTok adds later, then the base HTTP-status table.
     *
     * @param  mixed  $response
     * @param  array<string, mixed>  $sent  The request body, kept (URLs redacted) in the context.
     */
    protected function classifyError($response, array $sent = []): TemporaryException|PermanentException
    {
        $status = (int) $response->status();
        $code = $this->errorCode($response);
        $tiktokMessage = trim((string) ($response->json('error.message') ?? ''));
        $message = $tiktokMessage !== '' ? $tiktokMessage : 'TikTok request failed.';
        $context = ['status' => $status, 'error' => $response->json('error')];

        if ($sent !== []) {
            $context['sent'] = $this->redactUrls($sent);
        }

        $retryAfter = RetryAfter::fromResponse($response);
        $permanent = static fn (string $text, FailureReason $reason, array $extra = []) => new PermanentException($text, Platform::TikTok, $reason, $context + $extra);
        $temporary = static fn (string $text, FailureReason $reason, ?int $wait, array $extra = []) => new TemporaryException($text, Platform::TikTok, $reason, $wait, $context + $extra);

        $exception = match ($code) {
            'access_token_invalid' => $permanent('The TikTok access token is invalid or expired; the account must be reconnected.', FailureReason::InvalidToken),
            'scope_not_authorized' => $permanent('The TikTok token lacks a required scope (video.publish for direct posts, video.upload for drafts); reconnect the account with it.', FailureReason::InsufficientPermissions),
            'spam_risk_user_banned_from_posting' => $permanent('This TikTok creator is banned from making new posts.', FailureReason::AccountRestricted),
            'spam_risk_too_many_posts' => $permanent('TikTok daily post limit reached for this creator; try again tomorrow.', FailureReason::RateLimited, TikTokFailReason::dailyCapContext()),
            'spam_risk_too_many_pending_share' => $temporary('Too many unfinished TikTok drafts (at most 5 pending shares per 24 hours): the creator must post or delete inbox drafts.', FailureReason::RateLimited, 3600),
            'app_version_check_failed' => $permanent('The creator must update the TikTok app (31.8 or later) to receive drafts.', FailureReason::AccountRestricted),
            'invalid_param', 'invalid_params' => $permanent($message, FailureReason::InvalidContent),
            'token_not_authorized_for_specified_publish_id', 'invalid_publish_id' => $permanent(
                'TikTok no longer recognises this publish_id ('.$code.'); check the creator profile or the webhooks before posting again.',
                FailureReason::NotFound,
                ['publish_id' => isset($sent['publish_id']) ? (string) $sent['publish_id'] : null],
            ),
            'url_ownership_unverified' => $permanent('The media URL host is not a verified domain or URL prefix of your TikTok app: verify it in the TikTok developer portal, use TikTokTransfer::FileUpload for videos, or bind a gateway that re-hosts media on a verified domain.', FailureReason::MediaRejected),
            // A daily quota of the whole client (owner decision: reschedule,
            // never wait inside the queue for the reset).
            'reached_active_user_cap' => $permanent('The TikTok app reached its daily quota of active publishing users; try again tomorrow.', FailureReason::RateLimited, TikTokFailReason::dailyCapContext()),
            'privacy_level_option_mismatch' => $permanent('The privacy level is not one this creator may use (TikTok privacy_level_option_mismatch).', FailureReason::InsufficientPermissions, ['reconnect' => false]),
            'unaudited_client_can_only_post_to_private_accounts' => $permanent('This TikTok app is not audited yet, so it can only post to private accounts (SELF_ONLY).', FailureReason::InsufficientPermissions, ['reconnect' => false]),
            'rate_limit_exceeded' => $temporary('TikTok rate limit reached.', FailureReason::RateLimited, $retryAfter ?? 60),
            'internal_error' => $temporary($message, FailureReason::ServerError, $retryAfter ?? 60),
            '' => match (true) {
                // No code at all: a 401 is a token problem; anything else is the
                // transient glitch the production app retries.
                $status === 401 => $permanent('TikTok rejected the access token (HTTP 401); the account must be reconnected.', FailureReason::InvalidToken),
                $status === 429 => $temporary('TikTok rate limit reached.', FailureReason::RateLimited, $retryAfter ?? 60),
                default => $temporary("TikTok returned an error without a code (HTTP {$status}).", FailureReason::ServerError, $retryAfter ?? 60),
            },
            default => null,
        };

        if ($exception !== null) {
            return $exception;
        }

        // Codes TikTok may add later.
        $exception = match (true) {
            str_contains($code, 'rate_limit') => $temporary('TikTok rate limit reached.', FailureReason::RateLimited, $retryAfter ?? 60),
            str_contains($code, 'internal') || $response->serverError() => $temporary($message, FailureReason::ServerError, $retryAfter ?? 60),
            str_starts_with($code, 'access_token') => $permanent($message, FailureReason::InvalidToken),
            str_contains($code, 'scope') || str_contains($code, 'permission') || str_contains($code, 'unaudited') => $permanent($message, FailureReason::InsufficientPermissions),
            str_contains($code, 'spam') || str_contains($code, 'frequency') => $temporary($message, FailureReason::RateLimited, 1800),
            default => null,
        };

        return $exception ?? parent::classifyError($response)->addContext($context);
    }

    /**
     * The request body with URL query strings redacted (pre-signed media URLs
     * carry signatures), for exception contexts.
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    protected function redactUrls(array $value): array
    {
        array_walk_recursive($value, static function (&$item): void {
            if (is_string($item) && preg_match('#^https?://[^?]*\?#i', $item) === 1) {
                $item = (string) preg_replace('/\?.*$/s', '?[redacted]', $item);
            }
        });

        return $value;
    }

    // --- Rule sets -------------------------------------------------------------

    protected function photoRules(): ImageRules
    {
        return new ImageRules(
            extensions: ['jpg', 'jpeg', 'webp'],
            maxSizeBytes: (int) $this->apiConfig('photo_max_bytes', 20971520),
            maxWidth: $this->intOrNull($this->apiConfig('photo_max_width')),
            maxHeight: $this->intOrNull($this->apiConfig('photo_max_height')),
            convert: ['png' => 'jpg'],
        );
    }

    protected function videoRules(): VideoRules
    {
        return new VideoRules(
            extensions: ['mp4', 'webm', 'mov'],
            maxSizeBytes: (int) $this->apiConfig('video_max_bytes', 4294967296),
            minDuration: (float) $this->apiConfig('video_min_duration', 3),
            maxDuration: (float) $this->apiConfig('video_max_duration', 600),
            minWidth: 360,
            minHeight: 360,
            maxWidth: 4096,
            maxHeight: 4096,
            codecs: array_values((array) $this->apiConfig('video_codecs', ['h264', 'hevc', 'vp8', 'vp9'])),
            minFrameRate: (float) $this->apiConfig('video_min_frame_rate', 23),
            maxFrameRate: (float) $this->apiConfig('video_max_frame_rate', 60),
        );
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
