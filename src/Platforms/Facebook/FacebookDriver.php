<?php

namespace SocialPoster\Platforms\Facebook;

use DateTimeInterface;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\MessageBag;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\CommentRules;
use SocialPoster\Capabilities\ImageRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Capabilities\VideoRules;
use SocialPoster\Concerns\TalksToMetaGraph;
use SocialPoster\Contracts\SupportsComments;
use SocialPoster\Contracts\SupportsFirstComment;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\Ingestion;
use SocialPoster\Enums\MediaType;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\Support\Text\MbStrlenCounter;
use SocialPoster\Support\Text\Urls;
use SocialPoster\ValueObjects\CommentResult;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\PublishOutcome;
use Throwable;

/**
 * Facebook Pages: text and link posts, photos and multi-photo posts, regular
 * videos, Reels, photo stories and video stories, plus the first comment.
 *
 * rulesFor() resolves the variant; the generic engine does the per-item checks.
 *
 * Every create that Facebook cannot undo runs as its own commit phase (see
 * PublishOutcome::commit()), so the job records it before it runs and a repeat
 * arrives with `_recovering` set:
 *
 * - feed_create (text, link, photos): POST /{page}/feed with the stored params.
 *   Photos are uploaded unpublished first and never re-uploaded. When
 *   recovering, the post is looked up on the Page (/published_posts, or
 *   /scheduled_posts) by message, or by its photo ids when there is no message.
 *   A timeout goes to feed_verify (look up, then create) a minute later.
 * - video_create (regular video): POST /{page}/videos, verified through
 *   /{page}/videos by description or title (video_verify after a timeout).
 * - reel_finish / story_finish (v1.0 phase names): the upload_phase=finish
 *   call. The video's status is read first, and a finish whose publishing the
 *   status already shows (publishing_phase in_progress/complete, or
 *   publish_status published/scheduled) is not sent again; a status read that
 *   still lags behind a finish lost in transit lets it go out a second time,
 *   for the same video_id. Then reel_status polls processing and publishing
 *   until the Reel or story is live.
 * - photo_story: POST /photo_stories. When recovering, the Page's stories
 *   (GET /{page}/stories since the commit) are searched for the uploaded
 *   photo (media_id). A match is the story; an empty window means none was
 *   created; any other story in the window, or a state without a window,
 *   fails as OutcomeUnknown (possibly published) rather than risk a second
 *   story (photo_story_verify after an unreadable listing).
 *
 * The first comment looks for the Page's own identical comment on the post
 * before posting, so a retried comment job (timeout after Facebook created
 * it, worker death, redelivery) never comments twice.
 *
 * Credentials: account_id (the Page id) and page_access_token (or
 * access_token, or the key a v1.0 subclass names in $tokenKey). Scopes:
 * pages_manage_posts to publish (pages_read_engagement, which Meta grants with
 * it, to look posts and stories up), pages_manage_engagement +
 * pages_read_user_content to comment. Media is pulled from public URLs; a local
 * file is uploaded directly only for Reels and video stories. Hosts must let
 * the facebookexternalhit user agent in (robots.txt), and fbcdn URLs are refused.
 */
class FacebookDriver extends AbstractPlatform implements SupportsComments, SupportsFirstComment
{
    use TalksToMetaGraph {
        fetchPermalink as protected graphPermalink;
    }

    /** Width/height of a vertical 9:16 video, the shape that goes to Reels by default. */
    protected const VERTICAL = 9 / 16;

    /** Encoder rounding allowed around 9:16 (1080x1918 still counts), as production checks it. */
    protected const RATIO_TOLERANCE = 0.01;

    /** Formats Facebook takes for a regular video's custom thumbnail. */
    protected const THUMBNAIL_EXTENSIONS = ['bmp', 'gif', 'jpeg', 'jpg', 'png', 'tiff'];

    /** MIME types accepted for a thumbnail URL without an extension. */
    protected const THUMBNAIL_MIMES = ['image/bmp', 'image/x-ms-bmp', 'image/gif', 'image/jpeg', 'image/png', 'image/tiff'];

    /** Readable reasons for the Reels / stories processing errors. */
    protected const VIDEO_ERRORS = [
        1363040 => 'Facebook rejected the video\'s aspect ratio: Reels must be between 16:9 and 9:16, and stories 9:16 (1080x1920 is best).',
        1363127 => 'Facebook rejected the video\'s resolution: Reels and stories need at least 540x960 (1080x1920 is recommended).',
        1363128 => 'Facebook rejected the video\'s length: Reels must last 3 to 90 seconds, and stories at most 60.',
        1363129 => 'Facebook rejected the video\'s frame rate: Reels and stories need 24 to 60 frames per second.',
    ];

    /** Seconds of clock skew and request time kept on top of Facebook's 10-minute scheduling floor. */
    protected const SCHEDULE_SKEW = 30;

    private const WEB = 'https://www.facebook.com';

    /**
     * The v1.0 extension point for the token's credential key. token() reads
     * it first, then page_access_token and access_token.
     */
    protected string $tokenKey = 'page_access_token';

    public function platform(): Platform
    {
        return Platform::Facebook;
    }

    protected function graphBase(): string
    {
        return 'https://graph.facebook.com/'.$this->apiConfig('graph_version', 'v25.0');
    }

    protected function permalinkField(): string
    {
        return 'permalink_url';
    }

    /** A Video node's state lives in status.video_status; the phases sit next to it. */
    protected function containerStatusField(): string
    {
        return 'status.video_status';
    }

    /** @return list<string> */
    protected function containerErrorFields(): array
    {
        return [
            'status.processing_phase.errors.0.message',
            'status.processing_phase.error.message',
            'status.publishing_phase.errors.0.message',
            'status.publishing_phase.error.message',
            'status.uploading_phase.errors.0.message',
            'status.uploading_phase.error.message',
        ];
    }

    protected function ingestion(): Ingestion
    {
        return Ingestion::PullUrl;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            caption: new CaptionRules(max: 63206),
            media: new MediaRules(minCount: 0, maxCount: $this->maxAttachedMedia(), image: $this->feedImageRules(), video: $this->videoRules()),
            supportsTitle: true,
            supportsCarousel: true,
        );
    }

    protected function rulesFor(PreparedPost $post): Capabilities
    {
        $caption = new CaptionRules(max: 63206);
        $media = $post->media();
        $first = $media[0] ?? null;
        $isVideo = $first?->type === MediaType::Video;

        if ($this->isStory($post)) {
            return new Capabilities(
                caption: $caption,
                media: new MediaRules(
                    minCount: 1,
                    maxCount: 1,
                    image: $isVideo ? null : $this->storyPhotoRules(),
                    video: $isVideo ? $this->storyVideoRules() : null,
                ),
            );
        }

        if (count($media) > 1) {
            return new Capabilities(
                caption: $caption,
                media: new MediaRules(minCount: 1, maxCount: $this->maxAttachedMedia(), image: $this->feedImageRules(), video: null),
                supportsCarousel: true,
            );
        }

        if ($first !== null && $isVideo) {
            return new Capabilities(
                caption: $caption,
                media: new MediaRules(
                    minCount: 0,
                    maxCount: 1,
                    video: $this->routesAsReel($post, $first) ? $this->reelRules() : $this->videoRules(),
                ),
                supportsTitle: true,
            );
        }

        return new Capabilities(
            caption: $caption,
            media: new MediaRules(minCount: 0, maxCount: 1, image: $this->feedImageRules()),
        );
    }

    protected function validatePlatformRules(PreparedPost $post, MessageBag $errors): void
    {
        $media = $post->media();
        $isStory = $this->isStory($post);

        if (! $isStory && $media === [] && trim((string) $post->caption()) === '') {
            $errors->add('caption', "Facebook posts can't have an empty caption.");
        }

        if ($isStory && $media === []) {
            $errors->add('media', "You don't have any media attached for this Facebook story.");
        }

        $this->validateThumbnail($post, $errors);
        $this->validateReelSchedule($post, $errors);
    }

    /**
     * Facebook pulls media from public URLs, except that a Reel or video story
     * can upload a local file directly (offset/file_size upload), so those skip
     * the "needs a public URL" check.
     */
    protected function validateMediaTransfer(PreparedPost $post, MessageBag $errors): void
    {
        if ($this->uploadsLocalVideo($post)) {
            return;
        }

        parent::validateMediaTransfer($post, $errors);
    }

    /**
     * A custom thumbnail applies to a regular video only, and only the
     * explicit FacebookOptions thumbnail is checked. On a Reel, story, photo
     * or text post it is ignored, as v1.0 did (publish() logs it). The
     * cross-platform Media::video() thumbnail is best-effort: createVideo()
     * sends it only when Facebook can take it, so it never fails a post.
     */
    protected function validateThumbnail(PreparedPost $post, MessageBag $errors): void
    {
        $thumbnail = $this->options($post)?->thumbnail;

        if ($thumbnail === null || ! $this->isRegularVideo($post)) {
            return;
        }

        if (($problem = $this->thumbnailProblem($thumbnail)) !== null) {
            $errors->add('thumbnail', $problem);
        }
    }

    /**
     * A scheduled Reel carries its time on the finish call, which goes out
     * finish_delay (plus any upload checks and queue time) after post(), and
     * Facebook wants it more than 10 minutes ahead then. So post() asks for
     * reelScheduleLead() instead. FacebookOptions::validate() reports a time
     * under the 10-minute floor itself; the v1.0 extra recipe is checked when
     * the finish is sent (assertStillSchedulable()).
     */
    protected function validateReelSchedule(PreparedPost $post, MessageBag $errors): void
    {
        $at = $this->options($post)?->scheduledAt;
        $media = $post->media();
        $first = $media[0] ?? null;

        if ($at === null || $first === null || $this->isStory($post) || count($media) !== 1
            || $first->type !== MediaType::Video || ! $this->routesAsReel($post, $first)) {
            return;
        }

        $ahead = $at->getTimestamp() - time();
        $lead = $this->reelScheduleLead();

        if ($ahead >= FacebookOptions::MIN_SCHEDULE_AHEAD && $ahead < $lead) {
            $errors->add('scheduledAt', 'Facebook needs a scheduled Reel to be at least '.(int) ceil($lead / 60).' minutes in the future, to leave time for the upload.');
        }
    }

    /** Whether the post is a single regular (non-Reel) feed video, the one variant that takes a thumbnail. */
    protected function isRegularVideo(PreparedPost $post): bool
    {
        $media = $post->media();
        $first = $media[0] ?? null;

        return $first !== null
            && count($media) === 1
            && $first->type === MediaType::Video
            && ! $this->isStory($post)
            && ! $this->routesAsReel($post, $first);
    }

    /**
     * Why Facebook would refuse $thumbnail as a video thumbnail (format, or
     * size when known), or null when it can take it. A signed CDN link often
     * has no extension; then the inspector's MIME type decides.
     */
    protected function thumbnailProblem(Media $thumbnail): ?string
    {
        $meta = $this->inspectMedia($thumbnail);
        $extension = $thumbnail->extension();
        $allowed = implode(', ', static::THUMBNAIL_EXTENSIONS);

        if ($extension === '') {
            if (! in_array(strtolower((string) $meta?->mimeType), static::THUMBNAIL_MIMES, true)) {
                return "Facebook video thumbnails must be one of: {$allowed}; this one has no file extension and its type could not be confirmed.";
            }
        } elseif (! in_array($extension, static::THUMBNAIL_EXTENSIONS, true)) {
            return "Facebook video thumbnails must be one of: {$allowed}.";
        }

        $max = (int) $this->apiConfig('thumbnail_max_bytes', 10485760);

        if ($meta?->sizeBytes !== null && $meta->sizeBytes > $max) {
            return 'Facebook video thumbnails must be at most '.round($max / 1048576, 1).'MB.';
        }

        return null;
    }

    /** The message, plus a link card for a text post (see linkFor()). */
    protected function prepare(PreparedPost $post): array
    {
        $params = ['message' => trim((string) $post->caption())];

        if ($post->media() === [] && ($link = $this->linkFor($post)) !== null) {
            $params['link'] = $link;
        }

        return $params;
    }

    // --- Credentials and scopes --------------------------------------------------

    /** The Page token: the first non-blank of tokenKeys(). */
    protected function token(PreparedPost $post): string
    {
        foreach ($this->tokenKeys() as $key) {
            $value = $post->credentials->get($key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * Where the Page token may be: $tokenKey (which a v1.0 subclass may
     * rename), then page_access_token, then access_token (the key every other
     * driver uses).
     *
     * @return list<string>
     */
    protected function tokenKeys(): array
    {
        return array_values(array_unique([$this->tokenKey, 'page_access_token', 'access_token']));
    }

    protected function requiredCredentials(): array
    {
        return [
            'account_id' => 'the Facebook Page id',
            implode('|', $this->tokenKeys()) => 'a Page access token',
        ];
    }

    protected function identityKeys(): array
    {
        return ['account_id'];
    }

    protected function requiredScopes(Feature $feature, Credentials $credentials): array
    {
        return match ($feature) {
            Feature::Publish => [['pages_manage_posts']],
            Feature::Comment => [['pages_manage_engagement'], ['pages_read_user_content']],
            default => [],
        };
    }

    // --- Publish -----------------------------------------------------------------

    public function publish(PreparedPost $post): PublishOutcome
    {
        $this->assertCredentials($post);

        $media = $post->media();
        $isStory = $this->isStory($post);

        if ($isStory && $this->options($post)?->scheduledAt !== null) {
            throw new PermanentException(
                "Facebook stories can't be scheduled; publish the story when it should go live.",
                Platform::Facebook,
                FailureReason::Validation,
            );
        }

        $thumbnail = $this->options($post)?->thumbnail;

        if ($thumbnail !== null && ! $this->isRegularVideo($post)) {
            // v1.0 accepted it here and simply did not send it; keep that, but say so.
            $this->logWarning('[SocialPoster] facebook: FacebookOptions::$thumbnail is used only for a regular video; it was not sent with this Reel, story, photo or text post.', [
                'media' => basename((string) parse_url($thumbnail->source, PHP_URL_PATH)),
            ]);
        }

        if ($media === []) {
            return $this->publishText($post);
        }

        $first = $media[0];

        if ($isStory) {
            return $first->type === MediaType::Video
                ? $this->startVideoStory($post, $first)
                : $this->publishPhotoStory($post, $first);
        }

        if (count($media) > 1) {
            return $this->publishCarousel($post);
        }

        if ($first->type === MediaType::Video) {
            if ($this->options($post)?->asReel === null && $this->inspectMedia($first)?->aspectRatio() === null) {
                $this->warnUnknownShape($first);
            }

            return $this->routesAsReel($post, $first)
                ? $this->startReel($post, $first)
                : $this->publishVideo($post, $first);
        }

        return $this->publishCarousel($post);
    }

    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        $this->assertCredentials($post);

        return match ($state['phase'] ?? null) {
            'feed_create', 'feed_verify' => $this->createFeedPost($post, $state),
            'video_create', 'video_verify' => $this->createVideo($post, $state),
            'photo_story', 'photo_story_verify' => $this->createPhotoStory($post, $state),
            'reel_finish' => $this->finishReel($post, $state),
            'story_finish' => $this->finishVideoStory($post, $state),
            'reel_status' => $this->checkVideoStatus($post, $state),
            default => throw new PermanentException(
                'Unknown Facebook publish phase.',
                Platform::Facebook,
                FailureReason::Unknown,
                ['phase' => is_scalar($state['phase'] ?? null) ? $state['phase'] : null],
            ),
        };
    }

    // --- Feed posts: text, link, photos --------------------------------------------

    /** A text or link post: the /feed create runs as its own commit phase. */
    protected function publishText(PreparedPost $post): PublishOutcome
    {
        return $this->commit([
            'phase' => 'feed_create',
            'params' => $this->feedParams($post),
            'since' => time() - $this->verifyWindow(),
        ]);
    }

    /**
     * One or more photos: upload them unpublished (in parallel, order kept),
     * then create the post with attached_media in the commit phase. A scheduled
     * post's photos are uploaded as temporary, as Facebook requires.
     */
    protected function publishCarousel(PreparedPost $post): PublishOutcome
    {
        $scheduled = $this->scheduledTime($post) !== null;
        $ids = $this->uploadPhotos($post, $post->media(), $scheduled);

        $params = $this->feedParams($post) + [
            'attached_media' => array_map(static fn (string $id) => ['media_fbid' => $id], $ids),
        ];

        if ($scheduled) {
            $params['unpublished_content_type'] = 'SCHEDULED';
        }

        return $this->commit([
            'phase' => 'feed_create',
            'params' => $params,
            'media_ids' => $ids,
            'since' => time() - $this->verifyWindow(),
        ]);
    }

    /** prepare() plus the scheduling fields. */
    protected function feedParams(PreparedPost $post): array
    {
        $params = $this->prepare($post);
        $scheduledAt = $this->scheduledTime($post);

        if ($scheduledAt !== null) {
            $params['published'] = false;
            $params['scheduled_publish_time'] = $scheduledAt;
        }

        return $params;
    }

    /**
     * The /feed create. Verifies first when the previous attempt may have
     * created the post; retries once without the link card when Facebook could
     * not fetch the link (1609005), since that error means nothing was created.
     * While verifying, a duplicate refusal (506) is the earlier attempt's post.
     *
     * @param  array<string, mixed>  $state
     */
    protected function createFeedPost(PreparedPost $post, array $state): PublishOutcome
    {
        $params = $this->stateArray($state, 'params');
        $verifying = ($state['phase'] ?? null) === 'feed_verify' || Pending::isRecovering($state);

        if ($verifying) {
            $found = $this->verifyOrRetry($state, 'feed_verify', fn () => $this->findFeedPost($post, $state));

            if ($found instanceof Pending) {
                return $found;
            }

            if ($found !== null) {
                return $this->feedPublished($post, $state, $found, ['recovered' => true]);
            }
        }

        $this->assertStillSchedulable($this->scheduledParam($params), $verifying);

        $payload = $this->mergePostExtra($post, $params);
        $linkRetried = false;

        while (true) {
            try {
                $response = $this->http($post)
                    ->timeout($this->publishTimeout())
                    ->post($this->edge($post, '/feed'), $payload);
            } catch (ConnectionException $e) {
                return $this->afterAmbiguousCreate($state, 'feed_verify', $e);
            }

            if ($this->ok($response)) {
                break;
            }

            if (! $linkRetried && isset($payload['link']) && $this->metaErrorParts($response)['code'] === 1609005) {
                $linkRetried = true;
                unset($payload['link']);

                continue;
            }

            $error = $this->mapError($response);

            if ($verifying && $error->reason === FailureReason::DuplicateContent) {
                $found = $this->earlierAttempt($error, fn () => $this->findFeedPost($post, $state));

                return $this->feedPublished($post, $state, $found, ['recovered' => true, 'duplicate_refused' => true]);
            }

            throw $error;
        }

        $id = $this->requireId($response, 'post', 'id', ambiguous: true);

        return $this->feedPublished($post, $state, $id, array_filter([
            'response' => $this->body($response),
            'link_dropped' => $linkRetried ?: null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $payload
     */
    protected function feedPublished(PreparedPost $post, array $state, string $id, array $payload = []): PublishOutcome
    {
        $scheduled = isset($state['params']['scheduled_publish_time']);
        $mediaIds = $state['media_ids'] ?? [];

        return $this->published(PostResult::success(
            Platform::Facebook,
            $id,
            $scheduled ? null : $this->fetchPermalink($post, $id),
            $payload + (is_array($mediaIds) && $mediaIds !== [] ? ['media_ids' => array_values($mediaIds)] : []),
            $scheduled ? PostResult::STATE_SCHEDULED : PostResult::STATE_PUBLISHED,
            'post_id',
        ));
    }

    /**
     * Upload photos unpublished and return their ids in the caller's order.
     * Several photos go up in parallel (Http::pool keyed by index, then sorted,
     * because the pool fills results in completion order); a single photo, or
     * a platform authorizer configured in social.authorizers, goes one by one
     * through http().
     *
     * @param  Media[]  $photos
     * @return list<string>
     */
    protected function uploadPhotos(PreparedPost $post, array $photos, bool $temporary = false): array
    {
        $photos = array_values($photos);
        $requests = array_map(fn (Media $photo) => $this->photoParams($post, $photo, $temporary), $photos);
        $url = $this->edge($post, '/photos');
        $timeout = (int) $this->apiConfig('photo_timeout', 60);

        if (count($requests) === 1 || config('social.authorizers.facebook')) {
            $ids = [];

            foreach ($requests as $params) {
                $response = $this->http($post)->timeout($timeout)->post($url, $params);

                if (! $this->ok($response)) {
                    throw $this->mapError($response);
                }

                $ids[] = $this->requireId($response, 'photo');
            }

            return $ids;
        }

        $token = $this->token($post);
        $connect = (int) $this->apiConfig('connect_timeout', 10);

        $results = Http::pool(function (Pool $pool) use ($requests, $url, $token, $timeout, $connect) {
            foreach ($requests as $index => $params) {
                $pool->as((string) $index)
                    ->withToken($token)
                    ->connectTimeout($connect)
                    ->timeout($timeout)
                    ->post($url, $params);
            }
        }, max(1, (int) $this->apiConfig('photo_concurrency', 5)));

        ksort($results);
        $ids = [];

        foreach ($results as $result) {
            if ($result instanceof Throwable) {
                throw TemporaryException::connection(Platform::Facebook, $result);
            }

            if (! $this->ok($result)) {
                throw $this->mapError($result);
            }

            $ids[] = $this->requireId($result, 'photo');
        }

        return $ids;
    }

    /** The /photos params for one unpublished photo: url, alt text, extra['photo']. */
    protected function photoParams(PreparedPost $post, Media $photo, bool $temporary = false): array
    {
        $params = [
            'url' => $this->mediaUrl($photo),
            'published' => false,
        ];

        if ($temporary) {
            $params['temporary'] = true;
        }

        if ($photo->altText !== null && trim($photo->altText) !== '') {
            $params['alt_text_custom'] = trim($photo->altText);
        }

        return array_merge($this->photoExtra($post), $params);
    }

    // --- Regular videos --------------------------------------------------------------

    /** A regular (non-Reel) video: the /videos create runs as its own commit phase. */
    protected function publishVideo(PreparedPost $post, Media $video): PublishOutcome
    {
        $params = ['file_url' => $this->mediaUrl($video)];
        $description = trim((string) $post->caption());
        $title = trim((string) $post->title());

        if ($description !== '') {
            $params['description'] = $description;
        }

        if ($title !== '') {
            $params['title'] = $title;
        }

        if (($scheduledAt = $this->scheduledTime($post)) !== null) {
            $params['published'] = false;
            $params['scheduled_publish_time'] = $scheduledAt;
        }

        return $this->commit([
            'phase' => 'video_create',
            'params' => $params,
            'since' => time() - $this->verifyWindow(),
        ]);
    }

    /**
     * The /videos create. Facebook wants the thumbnail as uploaded bytes, so
     * with one the request is multipart (the params ride along as fields).
     *
     * The explicit FacebookOptions thumbnail is required, as in v1.0: when it
     * cannot be read the create fails, flagged as never sent. The
     * Media::video() cover is best-effort: one Facebook cannot take, or cannot
     * be read, is left out and reported in the payload (thumbnail_warning).
     *
     * @param  array<string, mixed>  $state
     */
    protected function createVideo(PreparedPost $post, array $state): PublishOutcome
    {
        $params = $this->stateArray($state, 'params');
        $verifying = ($state['phase'] ?? null) === 'video_verify' || Pending::isRecovering($state);

        if ($verifying) {
            $found = $this->verifyOrRetry($state, 'video_verify', fn () => $this->findVideo($post, $state));

            if ($found instanceof Pending) {
                return $found;
            }

            if ($found !== null) {
                return $this->videoCreated($post, $params, $found, ['recovered' => true]);
            }
        }

        $this->assertStillSchedulable($this->scheduledParam($params), $verifying);

        // Form and multipart bodies carry booleans as words, not 1/0.
        $payload = array_map(
            static fn ($value) => is_bool($value) ? ($value ? 'true' : 'false') : $value,
            $this->mergePostExtra($post, $params),
        );

        $video = $post->media()[0] ?? null;
        $explicit = $this->options($post)?->thumbnail;
        $cover = $explicit === null && $video !== null ? $this->mediaCover($video) : null;
        $thumbnail = $explicit;
        $warning = null;

        if ($cover !== null) {
            $problem = $this->thumbnailProblem($cover);
            $thumbnail = $problem === null ? $cover : null;
            $warning = $problem !== null ? "The video was posted without its thumbnail: {$problem}" : null;
        }

        $request = $this->http($post)->timeout($this->uploadTimeout());

        try {
            $bytes = null;

            if ($thumbnail !== null) {
                try {
                    $bytes = $this->bytes($thumbnail);
                } catch (Exception $e) {
                    if ($thumbnail === $explicit) {
                        throw $this->unsent($e);
                    }

                    $warning = 'The video thumbnail could not be read, so the video was posted without it.';
                }
            }

            if ($thumbnail !== null && $bytes !== null) {
                $extension = $thumbnail->extension() !== '' ? $thumbnail->extension() : 'jpg';
                $request = $request->attach('thumb', $bytes, "thumbnail.{$extension}");
            } else {
                $request = $request->asForm();
            }

            try {
                $response = $request->post($this->edge($post, '/videos'), $payload);
            } catch (ConnectionException $e) {
                return $this->afterAmbiguousCreate($state, 'video_verify', $e);
            }
        } finally {
            $this->gateway()?->cleanup();
        }

        if (! $this->ok($response)) {
            $error = $this->mapError($response);

            if ($verifying && $error->reason === FailureReason::DuplicateContent) {
                $found = $this->earlierAttempt($error, fn () => $this->findVideo($post, $state));

                return $this->videoCreated($post, $params, $found, ['recovered' => true, 'duplicate_refused' => true]);
            }

            throw $error;
        }

        $id = $this->requireId($response, 'video', 'id', ambiguous: true);

        return $this->videoCreated($post, $params, $id, array_filter([
            'response' => $this->body($response),
            'thumbnail_warning' => $warning,
        ], static fn ($value) => $value !== null));
    }

    /**
     * A failure reading the thumbnail, which happens before the /videos
     * request: flag it as never sent, so the job does not take it for an
     * ambiguous create and look the video up (or fail it as unknown) next run.
     */
    protected function unsent(Exception $e): Exception
    {
        if ($e instanceof ConnectionException) {
            return TemporaryException::connection(Platform::Facebook, $e)->withFlags(['request_sent' => false]);
        }

        if ($e instanceof SocialPosterException) {
            return $e->withFlags(['request_sent' => false]);
        }

        return $e;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $payload
     */
    protected function videoCreated(PreparedPost $post, array $params, string $id, array $payload = []): PublishOutcome
    {
        $scheduled = isset($params['scheduled_publish_time']);

        return $this->published(PostResult::success(
            Platform::Facebook,
            $id,
            $scheduled ? null : $this->fetchPermalink($post, $id),
            $payload,
            $scheduled ? PostResult::STATE_SCHEDULED : PostResult::STATE_PUBLISHED,
            'video_id',
        ));
    }

    // --- Photo stories -----------------------------------------------------------------

    /** Upload the photo unpublished now; publishing the story is the commit phase. */
    protected function publishPhotoStory(PreparedPost $post, Media $photo): PublishOutcome
    {
        [$photoId] = $this->uploadPhotos($post, [$photo]);

        return $this->commit([
            'phase' => 'photo_story',
            'photo_id' => $photoId,
            'since' => time() - $this->verifyWindow(),
        ]);
    }

    /**
     * POST /photo_stories. When an earlier attempt may have published it, the
     * Page's stories are checked first (findPhotoStory()); without a window to
     * check (a state from before the lookup existed), the outcome is unknown.
     *
     * @param  array<string, mixed>  $state
     */
    protected function createPhotoStory(PreparedPost $post, array $state): PublishOutcome
    {
        $photoId = $this->stateId($state, 'photo_id');

        if (($state['phase'] ?? null) === 'photo_story_verify' || Pending::isRecovering($state)) {
            $since = $this->storyWindowStart($state);

            if ($since === null) {
                throw new PermanentException(
                    'An earlier attempt to publish this Facebook photo story may have gone through, and it cannot be looked up; check the Page before posting it again.',
                    Platform::Facebook,
                    FailureReason::OutcomeUnknown,
                    ['possibly_published' => true, 'photo_id' => $photoId],
                );
            }

            $found = $this->verifyOrRetry(
                ['since' => $since] + $state,
                'photo_story_verify',
                fn () => $this->findPhotoStory($post, $photoId, $since),
            );

            if ($found instanceof Pending) {
                return $found;
            }

            if ($found !== null) {
                return $this->published(PostResult::success(
                    Platform::Facebook,
                    (string) $this->pagePostId($post, $found),
                    null,
                    ['photo_id' => $photoId, 'recovered' => true],
                    PostResult::STATE_PUBLISHED,
                    'post_id',
                ));
            }
        }

        $response = $this->http($post)
            ->timeout($this->publishTimeout())
            ->post($this->edge($post, '/photo_stories'), $this->storyPayload($post, ['photo_id' => $photoId]));

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        $this->assertConfirmed($response, 'photo story', ['photo_id' => $photoId]);

        $id = (string) $this->pagePostId($post, $this->requireId($response, 'story', 'post_id', ambiguous: true));

        return $this->published(PostResult::success(
            Platform::Facebook,
            $id,
            null,
            ['photo_id' => $photoId, 'response' => $this->body($response)],
            PostResult::STATE_PUBLISHED,
            'post_id',
        ));
    }

    // --- Reels and video stories: upload now, finish in a commit phase -------------

    protected function startReel(PreparedPost $post, Media $video): PublishOutcome
    {
        $videoId = $this->startVideoUpload($post, '/video_reels', $video);

        return $this->commit([
            'phase' => 'reel_finish',
            'video_id' => $videoId,
            'caption' => trim((string) $post->caption()),
        ], $this->finishDelay());
    }

    /** @param array<string, mixed> $state */
    protected function finishReel(PreparedPost $post, array $state): PublishOutcome
    {
        $videoId = $this->stateId($state, 'video_id');

        if (($ready = $this->beforeFinish($post, $state, 'reel')) !== null) {
            return $ready;
        }

        // The schedule rides on the finish, sent finish_delay (or more) after post().
        $this->assertStillSchedulable($this->scheduledTime($post));

        $params = [
            'upload_phase' => 'finish',
            'video_id' => $videoId,
            'video_state' => 'PUBLISHED',
            'description' => is_string($state['caption'] ?? null) ? $state['caption'] : trim((string) $post->caption()),
        ];

        if (($title = trim((string) $post->title())) !== '') {
            $params['title'] = $title;
        }

        $payload = $this->mergePostExtra($post, $params);

        if (($scheduledAt = $this->scheduledTime($post)) !== null) {
            // A Reel is scheduled through video_state, not published=false.
            unset($payload['published']);
            $payload['video_state'] = 'SCHEDULED';
            $payload['scheduled_publish_time'] = $scheduledAt;
        }

        $finish = $this->http($post)
            ->timeout($this->publishTimeout())
            ->post($this->edge($post, '/video_reels'), $payload);

        if (! $this->ok($finish)) {
            throw $this->mapError($finish);
        }

        return $this->afterFinish($post, $state, $finish, 'reel');
    }

    protected function startVideoStory(PreparedPost $post, Media $video): PublishOutcome
    {
        $videoId = $this->startVideoUpload($post, '/video_stories', $video);

        return $this->commit([
            'phase' => 'story_finish',
            'video_id' => $videoId,
        ], $this->finishDelay());
    }

    /** @param array<string, mixed> $state */
    protected function finishVideoStory(PreparedPost $post, array $state): PublishOutcome
    {
        $videoId = $this->stateId($state, 'video_id');

        if (($ready = $this->beforeFinish($post, $state, 'story')) !== null) {
            return $ready;
        }

        $finish = $this->http($post)
            ->timeout($this->publishTimeout())
            ->post($this->edge($post, '/video_stories'), $this->storyPayload($post, [
                'upload_phase' => 'finish',
                'video_id' => $videoId,
            ]));

        if (! $this->ok($finish)) {
            throw $this->mapError($finish);
        }

        return $this->afterFinish($post, $state, $finish, 'story');
    }

    /**
     * Open an upload session on $endpoint (/video_reels or /video_stories) and
     * transfer the video into it. Returns the video id.
     */
    protected function startVideoUpload(PreparedPost $post, string $endpoint, Media $video): string
    {
        $start = $this->http($post)->post($this->edge($post, $endpoint), ['upload_phase' => 'start']);

        if (! $this->ok($start)) {
            throw $this->mapError($start);
        }

        $videoId = $this->requireId($start, 'video upload session', 'video_id');
        $uploadUrl = $this->requireId($start, 'video upload session', 'upload_url');

        $this->canPullUrl($video)
            ? $this->uploadByUrl($post, $uploadUrl, $video)
            : $this->uploadFile($post, $uploadUrl, $video);

        return $videoId;
    }

    /** Hosted-file upload: Facebook fetches file_url itself. */
    protected function uploadByUrl(PreparedPost $post, string $uploadUrl, Media $video): void
    {
        $upload = Http::connectTimeout((int) $this->apiConfig('connect_timeout', 10))
            ->timeout($this->uploadTimeout())
            ->withHeaders([
                'Authorization' => 'OAuth '.$this->token($post),
                'file_url' => $this->mediaUrl($video),
            ])
            ->post($uploadUrl);

        $this->assertUploaded($upload);
    }

    /** Local-file upload: the bytes go to the session in one request (offset 0). */
    protected function uploadFile(PreparedPost $post, string $uploadUrl, Media $video): void
    {
        try {
            $path = $this->mediaPath($video);
            $size = @filesize($path);

            if ($size === false) {
                throw new PermanentException(
                    'The video file could not be read.',
                    Platform::Facebook,
                    FailureReason::MediaRejected,
                    ['path' => basename($path)],
                );
            }

            $upload = Http::connectTimeout((int) $this->apiConfig('connect_timeout', 10))
                ->timeout($this->uploadTimeout())
                ->withHeaders([
                    'Authorization' => 'OAuth '.$this->token($post),
                    'offset' => '0',
                    'file_size' => (string) $size,
                ])
                ->withBody($this->stream($video), 'application/octet-stream')
                ->post($uploadUrl);
        } finally {
            $this->gateway()?->cleanup();
        }

        $this->assertUploaded($upload);
    }

    /**
     * The upload step answers {"success": true}. Anything else is retried with
     * a fresh session (the job re-runs publish()).
     *
     * @param  mixed  $upload
     */
    protected function assertUploaded($upload): void
    {
        if (! $this->ok($upload)) {
            throw $this->mapError($upload);
        }

        if (! $this->isTrue($this->jsonValue($upload, 'success'))) {
            throw new TemporaryException(
                'Facebook did not confirm the video upload; retrying with a new upload session.',
                Platform::Facebook,
                FailureReason::ServerError,
                60,
                ['status' => $upload->status(), 'body' => $this->body($upload)],
            );
        }
    }

    /**
     * Before a finish: read the video's status. A failed upload or processing
     * is MediaRejected; a finish the status shows went through (publishing
     * started, or published/scheduled) is not sent again, the run goes
     * straight to the status poll (a lagging status read cannot tell, and the
     * finish then repeats for the same video_id); an upload
     * Facebook is still fetching waits (a few times) before finishing. Null
     * means "finish now".
     *
     * @param  array<string, mixed>  $state
     */
    protected function beforeFinish(PreparedPost $post, array $state, string $kind): ?PublishOutcome
    {
        $videoId = $this->stateId($state, 'video_id');
        $status = $this->videoStatus($post, $videoId);

        if (($failure = $this->videoFailure($status, $kind)) !== null) {
            throw $failure;
        }

        if ($this->publishingStarted($status)) {
            return $this->awaitVideo($videoId, null, $kind, false, ['recovered' => true]);
        }

        $checks = (int) ($state['upload_checks'] ?? 0);

        if ($this->uploadInProgress($status) && $checks < (int) $this->apiConfig('upload_checks', 10)) {
            return $this->commit(['upload_checks' => $checks + 1] + Pending::withoutReservedKeys($state), 30);
        }

        return null;
    }

    /**
     * After an accepted finish: poll the status until the video is live. A
     * finish without success:true but no refusal either is not trusted: the
     * poll decides, and fails as OutcomeUnknown if publishing never starts.
     *
     * @param  array<string, mixed>  $state
     * @param  mixed  $finish
     */
    protected function afterFinish(PreparedPost $post, array $state, $finish, string $kind): PublishOutcome
    {
        $videoId = $this->stateId($state, 'video_id');
        $success = $this->jsonValue($finish, 'success');
        $label = $kind === 'story' ? 'video story' : 'Reel';

        if ($this->isFalse($success)) {
            throw new PermanentException(
                "Facebook did not confirm the {$label} publish.",
                Platform::Facebook,
                FailureReason::Unknown,
                ['video_id' => $videoId, 'body' => $this->body($finish)],
            );
        }

        $postId = $this->jsonValue($finish, 'post_id');
        $postId = is_string($postId) || is_int($postId) ? $this->pagePostId($post, (string) $postId) : null;

        return $this->awaitVideo($videoId, $postId, $kind, ! $this->isTrue($success));
    }

    /**
     * Enter the status poll. The post exists (or is being published), so the
     * wait is committed: a later failure is reported as possibly published.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function awaitVideo(string $videoId, ?string $postId, string $kind, bool $unconfirmed = false, array $extra = []): Pending
    {
        $deadline = time() + $this->statusPolls() * $this->statusInterval() + 600;

        return PublishOutcome::pending([
            'phase' => 'reel_status',
            'kind' => $kind,
            'video_id' => $videoId,
            'post_id' => $postId,
            'polls' => 0,
            'unconfirmed' => $unconfirmed,
            'deadline' => $deadline,
        ] + $extra, $this->statusInterval(), committed: true, deadline: $deadline);
    }

    /**
     * Poll GET /{video-id}?fields=status until processing and publishing are
     * done. A Reel accepted by the finish call that is still processing when
     * the poll budget runs out (status_polls, or the next poll would land
     * after the poll's own deadline) is reported as Published with state
     * processing (it usually goes live), never as a failure that would invite
     * a re-post: the job fails a chain still pending at its deadline.
     *
     * A throttled or failed status read is waited out here, inside that
     * deadline, rather than handed to the job, whose retry wait could outlast it.
     *
     * @param  array<string, mixed>  $state
     */
    protected function checkVideoStatus(PreparedPost $post, array $state): PublishOutcome
    {
        $videoId = $this->stateId($state, 'video_id');
        $kind = ($state['kind'] ?? 'reel') === 'story' ? 'story' : 'reel';
        $polls = (int) ($state['polls'] ?? 0) + 1;
        $deadline = isset($state['deadline']) && is_numeric($state['deadline']) ? (int) $state['deadline'] : null;

        try {
            $status = $this->videoStatus($post, $videoId);
        } catch (PermanentException $e) {
            // The finish was accepted: an unreadable status must not report a live video as failed.
            return $this->videoPublished($post, $state, PostResult::STATE_PROCESSING, [
                'processing' => true,
                'status_unavailable' => true,
                'status_error' => $e->getMessage(),
            ]);
        } catch (ConnectionException|TemporaryException $e) {
            $error = $e instanceof TemporaryException ? $e : TemporaryException::connection(Platform::Facebook, $e);
            $wait = max($this->statusInterval(), (int) ($error->retryAfter ?? 0));

            if ($this->pollBudgetSpent($polls, $wait, $deadline)) {
                return $this->videoStillProcessing($post, $state, $kind, null, [
                    'status_unavailable' => true,
                    'status_error' => $error->getMessage(),
                ]);
            }

            return $this->nextPoll($state, $polls, $wait, $deadline);
        }

        if (($failure = $this->videoFailure($status, $kind)) !== null) {
            throw $failure;
        }

        if ($this->videoDone($status, $kind)) {
            $scheduled = $this->lower(data_get($status, 'publishing_phase.publish_status')) === 'scheduled'
                || $this->scheduledTime($post) !== null;

            return $this->videoPublished($post, $state, $scheduled ? PostResult::STATE_SCHEDULED : PostResult::STATE_PUBLISHED);
        }

        if ($this->pollBudgetSpent($polls, $this->statusInterval(), $deadline)) {
            return $this->videoStillProcessing($post, $state, $kind, $status);
        }

        return $this->nextPoll($state, $polls, $this->statusInterval(), $deadline);
    }

    /** Whether the poll must end now: out of polls, or the next one would come too late for the deadline. */
    protected function pollBudgetSpent(int $polls, int $wait, ?int $deadline): bool
    {
        return $polls >= $this->statusPolls() || ($deadline !== null && time() + $wait >= $deadline);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function nextPoll(array $state, int $polls, int $wait, ?int $deadline): Pending
    {
        return PublishOutcome::pending(
            ['polls' => $polls] + Pending::withoutReservedKeys($state),
            $wait,
            committed: true,
            deadline: $deadline,
        );
    }

    /**
     * The poll budget is spent and the video is not live yet: Published with
     * state processing, unless the finish was never confirmed and publishing
     * was never seen to start ($status null = unread), which is unknown.
     *
     * @param  array<string, mixed>  $state
     * @param  ?array<string, mixed>  $status
     * @param  array<string, mixed>  $payload
     */
    protected function videoStillProcessing(PreparedPost $post, array $state, string $kind, ?array $status, array $payload = []): PublishOutcome
    {
        if (($state['unconfirmed'] ?? false) === true && ($status === null || ! $this->publishingStarted($status))) {
            throw new PermanentException(
                'Facebook did not confirm the '.($kind === 'story' ? 'video story' : 'Reel').' publish, and it has not been seen to start publishing; check the Page before posting it again.',
                Platform::Facebook,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'video_id' => $this->stateId($state, 'video_id'), 'status_body' => $status],
            );
        }

        return $this->videoPublished($post, $state, PostResult::STATE_PROCESSING, ['processing' => true] + $payload);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $payload
     */
    protected function videoPublished(PreparedPost $post, array $state, string $resultState, array $payload = []): PublishOutcome
    {
        $videoId = $this->stateId($state, 'video_id');
        $postId = is_string($state['post_id'] ?? null) && $state['post_id'] !== '' ? $state['post_id'] : null;
        $id = $postId ?? $videoId;
        $story = ($state['kind'] ?? 'reel') === 'story';
        $linkable = ! $story && $resultState !== PostResult::STATE_SCHEDULED;

        return $this->published(PostResult::success(
            Platform::Facebook,
            $id,
            $linkable ? $this->fetchPermalink($post, $id) : null,
            ['video_id' => $videoId, 'post_id' => $postId] + $payload + (($state['recovered'] ?? false) === true ? ['recovered' => true] : []),
            $resultState,
            $postId !== null ? 'post_id' : 'video_id',
        ));
    }

    /**
     * The Video node's status object: video_status plus uploading_phase,
     * processing_phase and publishing_phase. Empty when Graph sent none.
     *
     * @return array<string, mixed>
     */
    protected function videoStatus(PreparedPost $post, string $videoId): array
    {
        $status = $this->containerState($post, $videoId, ['status'])['raw']['status'] ?? null;

        return is_array($status) ? $status : [];
    }

    /**
     * MediaRejected when the upload, processing or publishing failed, with a
     * readable message for the documented Reels error codes; null otherwise.
     *
     * @param  array<string, mixed>  $status
     */
    protected function videoFailure(array $status, string $kind): ?PermanentException
    {
        $failedPhase = null;

        foreach (['uploading_phase', 'processing_phase', 'publishing_phase'] as $phase) {
            if ($this->lower(data_get($status, "{$phase}.status")) === 'error') {
                $failedPhase = $phase;
                break;
            }
        }

        $videoStatus = $this->lower($status['video_status'] ?? null);

        if ($failedPhase === null && ! in_array($videoStatus, ['error', 'upload_failed', 'expired'], true)) {
            return null;
        }

        [$code, $detail] = $this->phaseError($status, $failedPhase);
        $label = $kind === 'story' ? 'video story' : 'Reel';

        $message = static::VIDEO_ERRORS[$code] ?? ($videoStatus === 'expired'
            ? "Facebook could not publish the {$label}: the upload session expired."
            : "Facebook could not publish the {$label}.");

        if ($detail !== null) {
            $message .= " ({$detail})";
        }

        return new PermanentException($message, Platform::Facebook, FailureReason::MediaRejected, array_filter([
            'video_status' => $videoStatus !== '' ? $videoStatus : null,
            'failed_phase' => $failedPhase,
            'code' => $code !== 0 ? $code : null,
            'status_body' => $status,
        ], static fn ($value) => $value !== null));
    }

    /**
     * The error code and message reported by the failed phase (or any phase).
     *
     * @param  array<string, mixed>  $status
     * @return array{0: int, 1: ?string}
     */
    protected function phaseError(array $status, ?string $failedPhase): array
    {
        $phases = array_unique(array_filter([$failedPhase, 'processing_phase', 'publishing_phase', 'uploading_phase']));

        foreach ($phases as $phase) {
            foreach (["{$phase}.errors.0", "{$phase}.error"] as $path) {
                $error = data_get($status, $path);

                if (! is_array($error)) {
                    continue;
                }

                $code = is_numeric($error['code'] ?? null) ? (int) $error['code'] : 0;
                $message = is_string($error['message'] ?? null) && trim($error['message']) !== '' ? trim($error['message']) : null;

                if ($code !== 0 || $message !== null) {
                    return [$code, $message];
                }
            }
        }

        return [0, null];
    }

    /** @param array<string, mixed> $status */
    protected function publishingStarted(array $status): bool
    {
        return in_array($this->lower(data_get($status, 'publishing_phase.status')), ['in_progress', 'complete', 'completed'], true)
            || in_array($this->lower(data_get($status, 'publishing_phase.publish_status')), ['published', 'scheduled'], true);
    }

    /** @param array<string, mixed> $status */
    protected function videoDone(array $status, string $kind): bool
    {
        if (in_array($this->lower(data_get($status, 'publishing_phase.status')), ['complete', 'completed'], true)
            || in_array($this->lower(data_get($status, 'publishing_phase.publish_status')), ['published', 'scheduled'], true)) {
            return true;
        }

        // A story has no publishing schedule: it is live once processed.
        return $kind === 'story'
            && ($this->lower($status['video_status'] ?? null) === 'ready'
                || in_array($this->lower(data_get($status, 'processing_phase.status')), ['complete', 'completed'], true));
    }

    /** @param array<string, mixed> $status */
    protected function uploadInProgress(array $status): bool
    {
        return in_array($this->lower(data_get($status, 'uploading_phase.status')), ['not_started', 'in_progress'], true)
            || $this->lower($status['video_status'] ?? null) === 'uploading';
    }

    // --- Ambiguous creates: verify before creating again -------------------------------

    /**
     * Run a post-create lookup. Returns the id when the post exists, null when
     * it provably does not (safe to create), or a Pending that runs the verify
     * phase again later when Facebook could not be read. A network failure or
     * an outage counts against apiConfig('verify_retries', 5) and is read
     * again a minute later. A throttle (RateLimited) is waited out for as long
     * as Facebook asks (X-Business-Use-Case-Usage), without counting, within
     * apiConfig('verify_max_wait', 3600) seconds of the first unreadable
     * lookup; that wait is declared as the chain's deadline so the job does
     * not time it out first. Past either limit, or when the lookup is refused
     * outright, the outcome is reported as unknown (possibly published)
     * instead of risking a duplicate.
     *
     * @param  array<string, mixed>  $state
     * @param  callable(): ?string  $lookup
     */
    protected function verifyOrRetry(array $state, string $verifyPhase, callable $lookup): string|Pending|null
    {
        try {
            return $lookup();
        } catch (ConnectionException|TemporaryException $e) {
            $throttled = $e instanceof TemporaryException && $e->reason === FailureReason::RateLimited;
            $failures = (int) ($state['verify_failures'] ?? 0) + ($throttled ? 0 : 1);
            $wait = max(60, $e instanceof TemporaryException ? (int) ($e->retryAfter ?? 0) : 0);
            $started = is_numeric($state['verify_started'] ?? null) ? (int) $state['verify_started'] : time();
            $limit = $started + $this->verifyMaxWait();

            if ($failures > $this->verifyRetries() || time() + $wait > $limit) {
                throw new PermanentException(
                    'Facebook could not be read to check whether an earlier attempt already created this post; check the Page before posting it again.',
                    Platform::Facebook,
                    FailureReason::OutcomeUnknown,
                    ['possibly_published' => true, 'phase' => $verifyPhase],
                    $e,
                );
            }

            return $this->commit(
                ['phase' => $verifyPhase, 'verify_failures' => $failures, 'verify_started' => $started] + Pending::withoutReservedKeys($state),
                $wait,
                $throttled ? $limit + 600 : null,
                possiblySent: true,
            );
        } catch (PermanentException $e) {
            throw $e->withFlags(['possibly_published' => true]);
        }
    }

    /**
     * A create sent while verifying an earlier, unanswered attempt was refused
     * as a duplicate (506: identical to the last post): that is the earlier
     * attempt's post. Look it up once more and return its id; when it still
     * cannot be found, report the refusal as possibly published, never as a
     * post that was not created.
     *
     * @param  callable(): ?string  $lookup
     */
    protected function earlierAttempt(SocialPosterException $refusal, callable $lookup): string
    {
        try {
            $found = $lookup();
        } catch (ConnectionException|SocialPosterException) {
            $found = null;
        }

        if ($found !== null) {
            return $found;
        }

        throw $refusal->withFlags(['possibly_published' => true]);
    }

    /**
     * Facebook refuses a scheduled_publish_time that is not more than 10
     * minutes ahead when the create arrives, with an opaque #100. Time in the
     * queue, or a Reel's finish delay, can bring a time that passed validation
     * under that floor: fail clearly before sending instead. This create is
     * not sent; when $verifying, an earlier unanswered one may still exist.
     */
    protected function assertStillSchedulable(?int $scheduledAt, bool $verifying = false): void
    {
        if ($scheduledAt === null) {
            return;
        }

        $ahead = $scheduledAt - time();

        if ($ahead > FacebookOptions::MIN_SCHEDULE_AHEAD + static::SCHEDULE_SKEW) {
            return;
        }

        throw new PermanentException(
            'The scheduled time is now less than 10 minutes away, and Facebook refuses to schedule a post that close; schedule it later, or publish it now.',
            Platform::Facebook,
            FailureReason::Validation,
            ['scheduled_publish_time' => $scheduledAt, 'seconds_ahead' => $ahead] + ($verifying ? ['possibly_published' => true] : []),
        );
    }

    /**
     * The scheduled_publish_time stored in a create's params, or null.
     *
     * @param  array<string, mixed>  $params
     */
    protected function scheduledParam(array $params): ?int
    {
        $time = $params['scheduled_publish_time'] ?? null;

        return is_numeric($time) ? (int) $time : null;
    }

    /**
     * A create that got no answer. When the request provably never left
     * (DNS, refused connection, TLS), rethrow: the job retries it as is.
     * Otherwise Facebook may have created the post, so look it up a minute
     * later (verify phase) before creating again; after
     * apiConfig('verify_retries', 5) such rounds, report OutcomeUnknown.
     *
     * @param  array<string, mixed>  $state
     */
    protected function afterAmbiguousCreate(array $state, string $verifyPhase, ConnectionException $e): Pending
    {
        if (TemporaryException::requestNeverSent($e)) {
            throw $e;
        }

        $rounds = (int) ($state['ambiguous_rounds'] ?? 0) + 1;

        if ($rounds > $this->verifyRetries()) {
            throw new PermanentException(
                "Facebook did not answer the create request {$rounds} times; the post may be live, check the Page before posting it again.",
                Platform::Facebook,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'phase' => $verifyPhase],
                $e,
            );
        }

        return $this->commit(
            ['phase' => $verifyPhase, 'ambiguous_rounds' => $rounds] + Pending::withoutReservedKeys($state),
            60,
            possiblySent: true,
        );
    }

    /**
     * Look for the /feed post an earlier attempt may have created: on
     * /published_posts (or /scheduled_posts for a scheduled post), created
     * since the commit started, with the same message, or, without a message,
     * carrying one of the uploaded photos. Null when none matches.
     *
     * @param  array<string, mixed>  $state
     */
    protected function findFeedPost(PreparedPost $post, array $state): ?string
    {
        $params = $this->stateArray($state, 'params');
        $since = $this->since($state);
        $scheduled = isset($params['scheduled_publish_time']);

        $response = $this->http($post)->get($this->edge($post, $scheduled ? '/scheduled_posts' : '/published_posts'), [
            'fields' => 'id,message,created_time,attachments{target{id},subattachments{target{id}}}',
            'since' => $since,
            'limit' => 25,
        ]);

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        $items = $this->recentItems($response, $since);
        $message = $this->normaliseText($params['message'] ?? '');
        $mediaIds = array_values(array_map('strval', array_filter((array) ($state['media_ids'] ?? []), 'is_scalar')));
        $anyAttachments = false;

        foreach ($items as $item) {
            $attached = $this->attachmentIds($item);
            $anyAttachments = $anyAttachments || $attached !== [];
            $id = $this->itemId($item);

            if ($id === null) {
                continue;
            }

            if ($message !== '') {
                if ($this->normaliseText($item['message'] ?? '') !== $message) {
                    continue;
                }

                if ($mediaIds !== [] && $attached !== [] && array_intersect($mediaIds, $attached) === []) {
                    continue;
                }

                return $id;
            }

            if ($mediaIds !== [] && array_intersect($mediaIds, $attached) !== []) {
                return $id;
            }
        }

        if ($message === '' && $items !== [] && ($mediaIds === [] || ! $anyAttachments)) {
            throw new PermanentException(
                'An earlier attempt may have created this Facebook post, and a post without a caption cannot be told apart from the others published since; check the Page before posting it again.',
                Platform::Facebook,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'candidates' => count($items)],
            );
        }

        return null;
    }

    /**
     * Look for the video an earlier /videos attempt may have created, by
     * description and title. A video with neither cannot be matched: when
     * other videos appeared since, the outcome is unknown; when none did, it
     * is safe to upload.
     *
     * @param  array<string, mixed>  $state
     */
    protected function findVideo(PreparedPost $post, array $state): ?string
    {
        $params = $this->stateArray($state, 'params');
        $since = $this->since($state);

        $response = $this->http($post)->get($this->edge($post, '/videos'), [
            'fields' => 'id,title,description,created_time',
            'since' => $since,
            'limit' => 25,
        ]);

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        $items = $this->recentItems($response, $since);
        $description = $this->normaliseText($params['description'] ?? '');
        $title = $this->normaliseText($params['title'] ?? '');

        if ($description === '' && $title === '') {
            if ($items === []) {
                return null;
            }

            throw new PermanentException(
                'An earlier attempt may have uploaded this Facebook video, and a video without a description or title cannot be told apart from the others uploaded since; check the Page before posting it again.',
                Platform::Facebook,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'candidates' => count($items)],
            );
        }

        foreach ($items as $item) {
            if ($description !== '' && $this->normaliseText($item['description'] ?? '') !== $description) {
                continue;
            }

            if ($title !== '' && $this->normaliseText($item['title'] ?? '') !== $title) {
                continue;
            }

            if (($id = $this->itemId($item)) !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Look for the photo story an earlier attempt may have published, on
     * GET /{page}/stories since the commit (pages_read_engagement). Returns
     * the story's post_id when one carries the uploaded photo (media_id), and
     * null when no story at all appeared in the window (safe to publish). Any
     * other story in the window is OutcomeUnknown: Meta documents media_id as
     * the story's photo, but its equality with an unpublished /photos upload
     * id is not confirmed live, so an unmatched story may still be this one.
     */
    protected function findPhotoStory(PreparedPost $post, string $photoId, int $since): ?string
    {
        $response = $this->http($post)->get($this->edge($post, '/stories'), [
            'since' => $since,
            'limit' => 25,
        ]);

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        $data = $this->jsonValue($response, 'data');
        $others = 0;

        foreach (is_array($data) ? $data : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $created = $item['creation_time'] ?? null;
            $created = is_numeric($created) ? (int) $created : (is_string($created) ? strtotime($created) : false);

            if ($created !== false && $created < $since - 60) {
                continue;
            }

            $mediaId = $item['media_id'] ?? null;
            $storyId = $item['post_id'] ?? null;

            if (is_scalar($mediaId) && (string) $mediaId === $photoId
                && (is_string($storyId) || is_int($storyId)) && trim((string) $storyId) !== '') {
                return trim((string) $storyId);
            }

            $others++;
        }

        if ($others > 0) {
            throw new PermanentException(
                'An earlier attempt may have published this Facebook photo story, and it cannot be told apart from the other stories published since; check the Page before posting it again.',
                Platform::Facebook,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'photo_id' => $photoId, 'candidates' => $others],
            );
        }

        return null;
    }

    /**
     * Where a photo story lookup starts: the state's window, else the job's
     * commit time less the verify window. Null when neither is known.
     *
     * @param  array<string, mixed>  $state
     */
    protected function storyWindowStart(array $state): ?int
    {
        if (is_numeric($state['since'] ?? null)) {
            return (int) $state['since'];
        }

        $committing = $state[Pending::COMMITTING_SINCE] ?? null;

        return is_numeric($committing) ? (int) $committing - $this->verifyWindow() : null;
    }

    /**
     * The listing's items created at or after $since (a minute of clock skew
     * allowed; an item without a readable created_time is kept).
     *
     * @param  mixed  $response
     * @return list<array<string, mixed>>
     */
    protected function recentItems($response, int $since): array
    {
        $data = $this->jsonValue($response, 'data');
        $items = [];

        foreach (is_array($data) ? $data : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $created = is_string($item['created_time'] ?? null) ? strtotime($item['created_time']) : false;

            if ($created === false || $created >= $since - 60) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * The photo ids attached to a listed post (attachments target, and the
     * subattachments of a multi-photo post).
     *
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    protected function attachmentIds(array $item): array
    {
        $ids = [];
        $attachments = data_get($item, 'attachments.data');

        foreach (is_array($attachments) ? $attachments : [] as $attachment) {
            $candidates = [data_get($attachment, 'target.id')];
            $subattachments = data_get($attachment, 'subattachments.data');

            foreach (is_array($subattachments) ? $subattachments : [] as $sub) {
                $candidates[] = data_get($sub, 'target.id');
            }

            foreach ($candidates as $candidate) {
                if (is_scalar($candidate) && (string) $candidate !== '') {
                    $ids[] = (string) $candidate;
                }
            }
        }

        return $ids;
    }

    // --- Permalinks -------------------------------------------------------------------

    /**
     * Best-effort public link. Stories expire and get none. Graph may answer a
     * site-relative path (videos), which is made absolute; when the fetch
     * fails, a feed post or Reel still gets https://www.facebook.com/{id}.
     */
    protected function fetchPermalink(PreparedPost $post, string $id): ?string
    {
        $id = trim($id);

        if ($id === '' || $this->isStory($post)) {
            return null;
        }

        return $this->absoluteUrl($this->graphPermalink($post, $id)) ?? self::WEB.'/'.$id;
    }

    protected function absoluteUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        return str_starts_with($url, '/') ? self::WEB.$url : $url;
    }

    // --- First comment ---------------------------------------------------------------

    /**
     * Stories and scheduled (or draft) posts take no first comment. Otherwise
     * 8000 characters, with pages_manage_engagement and pages_read_user_content.
     */
    public function commentRules(PreparedPost $post, ?PostResult $parent = null): ?CommentRules
    {
        if ($this->isStory($post) || $this->scheduledTime($post) !== null) {
            return null;
        }

        if ($parent !== null && ($parent->isScheduled() || $parent->isDraft())) {
            return null;
        }

        return new CommentRules(
            max: (int) $this->apiConfig('comment_max', 8000),
            counter: new MbStrlenCounter,
            requiredScopes: $this->requiredScopes(Feature::Comment, $post->credentials),
        );
    }

    /**
     * POST /{post-id}/comments with the Page token. The parent id is already
     * the canonical {page_id}_{post_id} (or a video id, also a valid target).
     *
     * The comment create cannot be undone and the job retries it (after a
     * timeout once Facebook created it, a 5xx, a worker that died mid-request),
     * so the Page's own comment with the same text is looked for first and
     * returned when it is there (payload recovered), never posted twice.
     */
    public function addFirstComment(PreparedPost $context, PostResult $parent, string $text): CommentResult
    {
        $this->assertCredentials($context);

        $postId = trim((string) $parent->platformPostId);

        if ($postId === '') {
            throw new PermanentException(
                'There is no Facebook post id to comment on.',
                Platform::Facebook,
                FailureReason::NotFound,
            );
        }

        if (($existing = $this->findComment($context, $postId, $text)) !== null) {
            return new CommentResult(
                $existing,
                $this->absoluteUrl($this->graphPermalink($context, $existing)),
                ['id' => $existing, 'recovered' => true],
            );
        }

        $response = $this->http($context)->post($this->node($context, $postId).'/comments', [
            'message' => trim($text),
        ]);

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        $id = $this->requireId($response, 'comment', 'id', ambiguous: true);

        return new CommentResult($id, $this->absoluteUrl($this->graphPermalink($context, $id)), $this->body($response));
    }

    /**
     * The id of the Page's own top-level comment with this text on the post,
     * among the 25 newest (GET /{post}/comments, reverse chronological), or
     * null. A comment whose author is not shown is matched on its text alone.
     * A throttle or an outage is thrown, so the job retries instead of
     * commenting blind; a refused read (permissions) proves nothing either way
     * and lets the comment go out, as it did before the lookup existed.
     */
    protected function findComment(PreparedPost $context, string $postId, string $text): ?string
    {
        $response = $this->http($context)->get($this->node($context, $postId).'/comments', [
            'fields' => 'id,message,from,created_time',
            'filter' => 'toplevel',
            'order' => 'reverse_chronological',
            'limit' => 25,
        ]);

        if (! $this->ok($response)) {
            $error = $this->mapError($response);

            if ($error instanceof TemporaryException) {
                throw $error;
            }

            return null;
        }

        $data = $this->jsonValue($response, 'data');
        $message = $this->normaliseText($text);
        $pageId = trim((string) $context->credentials->get('account_id'));

        foreach (is_array($data) ? $data : [] as $item) {
            if (! is_array($item) || $this->normaliseText($item['message'] ?? '') !== $message) {
                continue;
            }

            $author = data_get($item, 'from.id');

            if (is_scalar($author) && (string) $author !== $pageId) {
                continue;
            }

            if (($id = $this->itemId($item)) !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * v1.0 contract, kept as an adapter over addFirstComment().
     *
     * @deprecated since 1.1, use addFirstComment().
     */
    public function comment(PreparedPost $context, string $postId, string $comment): ?string
    {
        return $this->addFirstComment($context, PostResult::success(Platform::Facebook, $postId), $comment)->id;
    }

    // --- Errors ------------------------------------------------------------------------

    /**
     * Facebook's own codes first (product subcodes ride on generic codes), then
     * the shared Meta table, then the HTTP-status defaults.
     *
     * - hosted-file upload errors ({debug_info: {type, message}}):
     *   ProcessingFailedError or retriable => Temporary 60, OAuth => InvalidToken,
     *   anything else => MediaRejected (the file URL could not be fetched);
     * - subcode 2207003 (took too long to download the file) => Temporary 30;
     * - 1363040 / 1363127 / 1363128 / 1363129 (in code or subcode) and 324 =>
     *   MediaRejected with a readable reason;
     * - 1609005 (the link could not be fetched) => MediaRejected;
     * - Meta: tokens, throttling (4/17/32/341/613/800xx with the
     *   X-Business-Use-Case-Usage wait), outages, permissions 3/10/200-299,
     *   368, 506;
     * - 100 (invalid parameter) => Unknown, with Facebook's explanation.
     */
    protected function classifyError($response): TemporaryException|PermanentException
    {
        $parts = $this->metaErrorParts($response);
        $code = $parts['code'];
        $subcode = $parts['subcode'];
        $message = $parts['message'];
        $context = $parts['context'];

        if ($parts['error'] === [] && ($upload = $this->classifyUploadError($response)) !== null) {
            return $upload;
        }

        if ($subcode === 2207003) {
            return new TemporaryException(
                "Facebook took too long to download the file; retrying shortly. ({$message})",
                Platform::Facebook,
                FailureReason::Timeout,
                30,
                $context,
            );
        }

        foreach ([$code, $subcode] as $candidate) {
            if (isset(static::VIDEO_ERRORS[$candidate])) {
                return new PermanentException(
                    static::VIDEO_ERRORS[$candidate]." ({$message})",
                    Platform::Facebook,
                    FailureReason::MediaRejected,
                    $context,
                );
            }
        }

        if ($code === 324) {
            return new PermanentException(
                "Facebook could not use the image file: it is missing, unreadable or not a supported image. ({$message})",
                Platform::Facebook,
                FailureReason::MediaRejected,
                $context,
            );
        }

        if ($code === 1609005 || $subcode === 1609005) {
            return new PermanentException(
                "Facebook could not fetch the link for the preview card. ({$message})",
                Platform::Facebook,
                FailureReason::MediaRejected,
                $context,
            );
        }

        if (($meta = $this->classifyMetaError($response)) !== null) {
            return $meta;
        }

        if ($code === 100) {
            $raw = $parts['raw'];

            return new PermanentException(
                $raw !== '' && $raw !== $message ? "{$message} ({$raw})" : $message,
                Platform::Facebook,
                FailureReason::Unknown,
                $context,
            );
        }

        return parent::classifyError($response);
    }

    /**
     * The rupload (hosted-file and local-file upload) error shape, which is not
     * Graph's {error: {...}}. Null when the body is not one.
     *
     * @param  mixed  $response
     */
    protected function classifyUploadError($response): TemporaryException|PermanentException|null
    {
        $debug = $this->jsonValue($response, 'debug_info');

        if (! is_array($debug)) {
            return null;
        }

        $type = is_string($debug['type'] ?? null) ? $debug['type'] : '';
        $detail = is_string($debug['message'] ?? null) ? trim($debug['message']) : '';
        $status = (int) $response->status();
        $context = ['status' => $status, 'http_status' => $status, 'upload_error' => $type !== '' ? $type : null, 'debug_info' => $debug];
        $suffix = $detail !== '' ? " ({$type}: {$detail})" : ($type !== '' ? " ({$type})" : '');

        if ($type === 'ProcessingFailedError' || ($debug['retriable'] ?? false) === true || $status >= 500) {
            return new TemporaryException(
                "Facebook could not process the video upload; retrying with a new upload session.{$suffix}",
                Platform::Facebook,
                FailureReason::ServerError,
                60,
                $context,
            );
        }

        if (str_contains(strtolower($type), 'oauth') || $status === 401) {
            return new PermanentException(
                "Facebook rejected the access token for the video upload.{$suffix}",
                Platform::Facebook,
                FailureReason::InvalidToken,
                $context,
            );
        }

        return new PermanentException(
            "Facebook could not fetch the video.{$suffix} The file URL must be public, let the facebookexternalhit user agent in (robots.txt), and not be an fbcdn URL.",
            Platform::Facebook,
            FailureReason::MediaRejected,
            $context,
        );
    }

    // --- Routing and options -------------------------------------------------------------

    protected function options(PreparedPost $post): ?FacebookOptions
    {
        return $this->optionsOf($post, FacebookOptions::class);
    }

    protected function isStory(PreparedPost $post): bool
    {
        return $this->options($post)->isStory ?? false;
    }

    /** Whether the video's shape is vertical 9:16 (within encoder rounding), as far as is known. */
    protected function isReel(Media $video): bool
    {
        $ratio = $this->inspectMedia($video)?->aspectRatio();

        return $ratio !== null && abs($ratio - static::VERTICAL) <= static::RATIO_TOLERANCE;
    }

    /**
     * Reel or regular video: FacebookOptions::$asReel when set, else the
     * video's shape (inspector, then Media::$meta). With neither, the video
     * goes out as a regular video, and publish() logs a warning.
     */
    protected function routesAsReel(PreparedPost $post, Media $video): bool
    {
        return $this->options($post)->asReel ?? $this->isReel($video);
    }

    /**
     * Unix time the post is scheduled for: FacebookOptions::$scheduledAt, else
     * the v1.0 README recipe (extra published=false + scheduled_publish_time).
     * Null publishes now. Stories are never scheduled.
     */
    protected function scheduledTime(PreparedPost $post): ?int
    {
        if ($this->isStory($post)) {
            return null;
        }

        $at = $this->options($post)?->scheduledAt;

        if ($at !== null) {
            return $at->getTimestamp();
        }

        $extra = $this->extraPayload($post);
        $time = $extra['scheduled_publish_time'] ?? null;

        if ($time === null || ! in_array($extra['published'] ?? null, [false, 0, '0', 'false'], true)) {
            return null;
        }

        if ($time instanceof DateTimeInterface) {
            return $time->getTimestamp();
        }

        if (is_numeric($time)) {
            return (int) $time;
        }

        $parsed = is_string($time) ? strtotime($time) : false;

        return $parsed !== false ? $parsed : null;
    }

    /**
     * The link card for a text post: FacebookOptions::$link when set (false =
     * none), a link the caller put in extra, else the first link in the caption
     * (trailing punctuation dropped; https:// added to a bare domain).
     */
    protected function linkFor(PreparedPost $post): ?string
    {
        $choice = $this->options($post)?->link;

        if ($choice === false) {
            return null;
        }

        if (is_string($choice) && trim($choice) !== '') {
            return Urls::absolute(trim($choice));
        }

        if (array_key_exists('link', $this->postExtra($post))) {
            return null; // the caller's own link, merged from extra
        }

        $first = Urls::first((string) $post->caption());

        return $first !== null ? Urls::absolute($first) : null;
    }

    /**
     * The extra() passthrough for the post-level create, without the reserved
     * 'photo' bag (and without 'link' when the link card is turned off).
     *
     * @return array<string, mixed>
     */
    protected function postExtra(PreparedPost $post): array
    {
        $extra = $this->extraPayload($post);
        unset($extra['photo']);

        if ($this->options($post)?->link === false) {
            unset($extra['link']);
        }

        return $extra;
    }

    /**
     * Merge postExtra() into a create request; driver keys win.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function mergePostExtra(PreparedPost $post, array $params): array
    {
        return array_merge($this->postExtra($post), $params);
    }

    /**
     * A story create: extra merged, scheduling keys dropped (stories go live now).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function storyPayload(PreparedPost $post, array $params): array
    {
        $payload = $this->mergePostExtra($post, $params);
        unset($payload['published'], $payload['scheduled_publish_time'], $payload['unpublished_content_type']);

        return $payload;
    }

    /**
     * extra['photo']: raw params for each /photos upload.
     *
     * @return array<string, mixed>
     */
    protected function photoExtra(PreparedPost $post): array
    {
        $photo = $this->extraPayload($post)['photo'] ?? [];

        return is_array($photo) ? $photo : [];
    }

    /** The cross-platform Media::video() thumbnail, as an image, or null. */
    protected function mediaCover(Media $video): ?Media
    {
        return $video->thumbnail !== null && trim($video->thumbnail) !== ''
            ? Media::image(trim($video->thumbnail))
            : null;
    }

    /** A single Reel or video story whose file Facebook cannot pull: its bytes are uploaded. */
    protected function uploadsLocalVideo(PreparedPost $post): bool
    {
        $media = $post->media();
        $first = $media[0] ?? null;

        if ($first === null || $first->type !== MediaType::Video || (! $this->isStory($post) && count($media) !== 1)) {
            return false;
        }

        return (bool) $this->apiConfig('local_video_upload', true)
            && ($this->isStory($post) || $this->routesAsReel($post, $first));
    }

    /** Whether Facebook can fetch the video from a URL (else its bytes are uploaded). */
    protected function canPullUrl(Media $video): bool
    {
        $gateway = $this->gateway();

        if ($gateway !== null) {
            return $gateway->canProvideUrl($video) || ! (bool) $this->apiConfig('local_video_upload', true);
        }

        return $video->isRemote() || ! (bool) $this->apiConfig('local_video_upload', true);
    }

    /**
     * Facebook returns bare post ids from the Reel / story finish and from
     * /photo_stories, which Graph refuses on their own ("#12 singular statuses
     * API is deprecated"). A Page post is addressed as {page_id}_{post_id}.
     */
    protected function pagePostId(PreparedPost $post, ?string $id): ?string
    {
        $id = trim((string) $id);

        if ($id === '') {
            return null;
        }

        return str_contains($id, '_') ? $id : trim((string) $post->credentials->get('account_id')).'_'.$id;
    }

    // --- Rule sets -----------------------------------------------------------------

    protected function videoRules(): VideoRules
    {
        return new VideoRules(
            extensions: ['mp4', 'mov'],
            maxSizeBytes: (int) $this->apiConfig('video_max_bytes', 1073741824),
            minDuration: 3,
            maxDuration: (float) $this->apiConfig('video_max_duration', 1200),
        );
    }

    /** Reels: 3-90 s, at least 540x960 (no maximum: 4K vertical is fine), 24-60 fps. */
    protected function reelRules(): VideoRules
    {
        return new VideoRules(
            extensions: ['mp4', 'mov'],
            minDuration: 3,
            maxDuration: (float) $this->apiConfig('reel_max_duration', 90),
            minWidth: 540,
            minHeight: 960,
            minFrameRate: 24,
            maxFrameRate: 60,
        );
    }

    protected function feedImageRules(): ImageRules
    {
        return new ImageRules(
            extensions: ['jpeg', 'jpg', 'png', 'gif', 'tiff', 'bmp'],
            maxSizeBytes: (int) $this->apiConfig('photo_max_bytes', 4194304),
        );
    }

    /** Page Stories photos: up to 10MB. */
    protected function storyPhotoRules(): ImageRules
    {
        return new ImageRules(
            extensions: ['jpeg', 'jpg', 'png', 'gif', 'tiff', 'bmp'],
            maxSizeBytes: (int) $this->apiConfig('story_photo_max_bytes', 10485760),
        );
    }

    /** Page Stories videos: 9:16, 3-60 s, at least 540x960, 24-60 fps. */
    protected function storyVideoRules(): VideoRules
    {
        return new VideoRules(
            extensions: ['mp4', 'mov'],
            minDuration: 3,
            maxDuration: (float) $this->apiConfig('story_max_duration', 60),
            minWidth: 540,
            minHeight: 960,
            aspectRatioRange: [static::VERTICAL - static::RATIO_TOLERANCE, static::VERTICAL + static::RATIO_TOLERANCE],
            minFrameRate: 24,
            maxFrameRate: 60,
        );
    }

    // --- Settings --------------------------------------------------------------------

    protected function maxAttachedMedia(): int
    {
        return max(1, (int) $this->apiConfig('max_attached_media', 10));
    }

    /** Seconds allowed for a video transfer (/videos, the upload step). */
    protected function uploadTimeout(): int
    {
        return (int) $this->apiConfig('upload_timeout', config('social.upload_timeout', 300));
    }

    /** Wait between the upload and the finish call (Facebook fetches the file meanwhile). */
    protected function finishDelay(): int
    {
        return max(0, (int) $this->apiConfig('finish_delay', 60));
    }

    /**
     * How far ahead post() wants a scheduled Reel: Facebook's 10 minutes, plus
     * the finish delay, plus apiConfig('reel_schedule_margin', 240) seconds for
     * the upload checks and queue time (15 minutes by default).
     */
    protected function reelScheduleLead(): int
    {
        return FacebookOptions::MIN_SCHEDULE_AHEAD + $this->finishDelay() + max(0, (int) $this->apiConfig('reel_schedule_margin', 240));
    }

    protected function statusPolls(): int
    {
        return max(1, (int) $this->apiConfig('status_polls', 15));
    }

    protected function statusInterval(): int
    {
        return max(5, (int) $this->apiConfig('status_interval', 20));
    }

    /** How far before the commit a post found by a lookup may have been created. */
    protected function verifyWindow(): int
    {
        return max(60, (int) $this->apiConfig('verify_window', 300));
    }

    protected function verifyRetries(): int
    {
        return max(0, (int) $this->apiConfig('verify_retries', 5));
    }

    /** How long a throttled lookup may be waited out, from the first unreadable one. */
    protected function verifyMaxWait(): int
    {
        return max(60, (int) $this->apiConfig('verify_max_wait', 3600));
    }

    // --- Small helpers -----------------------------------------------------------------

    protected function node(PreparedPost $post, string $id): string
    {
        return rtrim($this->graphBaseFor($post), '/').'/'.$id;
    }

    /**
     * Log once at publish time when a video goes out as a regular video only
     * because nothing told its shape (no inspector data, no Media::$meta, no
     * asReel). Logging never breaks the publish.
     */
    protected function warnUnknownShape(Media $video): void
    {
        $this->logWarning('[SocialPoster] facebook: the video\'s dimensions are unknown, so it is posted as a regular video rather than a Reel. Set FacebookOptions(asReel: ...) or pass width/height in Media::$meta.', [
            'media' => basename((string) parse_url($video->source, PHP_URL_PATH)),
        ]);
    }

    /**
     * A publish-time warning, gated by social.error_logging. Logging never
     * breaks the publish.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logWarning(string $message, array $context = []): void
    {
        if (! (bool) config('social.error_logging', true)) {
            return;
        }

        try {
            Log::warning($message, ['platform' => Platform::Facebook->value] + $context);
        } catch (Throwable) {
            // ignore
        }
    }

    /** @param array<string, mixed> $state */
    protected function since(array $state): int
    {
        return is_numeric($state['since'] ?? null) ? (int) $state['since'] : time() - $this->verifyWindow();
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function stateArray(array $state, string $key): array
    {
        $value = $state[$key] ?? null;

        if (! is_array($value)) {
            throw new PermanentException(
                "The Facebook continuation state has no {$key}.",
                Platform::Facebook,
                FailureReason::Unknown,
                ['phase' => is_scalar($state['phase'] ?? null) ? $state['phase'] : null, 'missing_field' => $key],
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $state */
    protected function stateId(array $state, string $key): string
    {
        $value = $state[$key] ?? null;

        if ((is_string($value) || is_int($value)) && trim((string) $value) !== '') {
            return trim((string) $value);
        }

        throw new PermanentException(
            "The Facebook continuation state has no {$key}.",
            Platform::Facebook,
            FailureReason::Unknown,
            ['phase' => is_scalar($state['phase'] ?? null) ? $state['phase'] : null, 'missing_field' => $key],
        );
    }

    /** @param array<string, mixed> $item */
    protected function itemId(array $item): ?string
    {
        $id = $item['id'] ?? null;

        return (is_string($id) || is_int($id)) && trim((string) $id) !== '' ? trim((string) $id) : null;
    }

    /**
     * A 200 that is not an explicit success: success false means Facebook
     * refused it; a missing success leaves the outcome unknown.
     *
     * @param  mixed  $response
     * @param  array<string, mixed>  $context
     */
    protected function assertConfirmed($response, string $what, array $context = []): void
    {
        $success = $this->jsonValue($response, 'success');

        if ($this->isTrue($success)) {
            return;
        }

        $context += ['body' => $this->body($response)];

        if ($this->isFalse($success)) {
            throw new PermanentException(
                "Facebook did not publish the {$what} (success: false).",
                Platform::Facebook,
                FailureReason::Unknown,
                $context,
            );
        }

        throw new PermanentException(
            "Facebook did not confirm the {$what} publish; it may be live, check the Page before posting it again.",
            Platform::Facebook,
            FailureReason::OutcomeUnknown,
            $context + ['possibly_published' => true],
        );
    }

    protected function isTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || (is_string($value) && strtolower($value) === 'true');
    }

    protected function isFalse(mixed $value): bool
    {
        return $value === false || $value === 0 || $value === '0' || (is_string($value) && strtolower($value) === 'false');
    }

    protected function lower(mixed $value): string
    {
        return is_string($value) ? strtolower(trim($value)) : '';
    }

    protected function normaliseText(mixed $text): string
    {
        return is_string($text) ? trim(str_replace("\r\n", "\n", $text)) : '';
    }

    /**
     * A JSON field of a response, or null for a non-JSON body.
     *
     * @param  mixed  $response
     */
    protected function jsonValue($response, string $key): mixed
    {
        try {
            return $response->json($key);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The decoded response body ([] when it is not a JSON object).
     *
     * @param  mixed  $response
     * @return array<string, mixed>
     */
    protected function body($response): array
    {
        try {
            $body = $response->json();
        } catch (Throwable) {
            return [];
        }

        return is_array($body) ? $body : [];
    }
}
