<?php

namespace SocialPoster\Platforms\Instagram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\MessageBag;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\CommentRules;
use SocialPoster\Capabilities\ImageRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Capabilities\VideoRules;
use SocialPoster\Concerns\TalksToMetaGraph;
use SocialPoster\Contracts\MediaGateway;
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
use SocialPoster\Transformers\ImageConvertingTransformer;
use SocialPoster\Transformers\NullMediaTransformer;
use SocialPoster\ValueObjects\CommentResult;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\Published;
use SocialPoster\ValueObjects\PublishOutcome;
use Throwable;

/**
 * Reference driver for the container model. Publishing is: create container(s),
 * wait for Meta to process them (a Pending the job re-polls via resume()), then
 * publish with media_publish.
 *
 * Hosts. Accounts connected with Instagram Login live on graph.instagram.com,
 * accounts linked through a Facebook Page on graph.facebook.com; the paths are
 * the same. The credential 'login' ('instagram' | 'facebook') picks the host.
 * Without it the token decides (Instagram Login tokens start with "IG", Facebook
 * ones with "EAA"), then api.instagram.default_login ('facebook', as in 1.0).
 *
 * media_publish is the only call that creates a post, and it is not
 * idempotent: it runs as its own commit phase ('publish'). That phase reads the
 * container first whenever the run may repeat an earlier attempt (`_recovering`,
 * a previous ambiguous publish, a not-ready round): a container whose
 * status_code is PUBLISHED is never published again, the media id is looked up
 * by caption and timestamp instead. A timeout, a 5xx or a throttle on
 * media_publish therefore never re-POSTs blind. While an earlier publish may
 * have gone through, the phase never publishes or re-creates without a status
 * read: when the container cannot be read it looks the post up, then fails with
 * possibly_published = true.
 *
 * Container states: IN_PROGRESS keeps polling (every api.instagram.poll_interval
 * seconds), FINISHED goes to the publish phase, PUBLISHED is recovered, ERROR is
 * parsed for its error code (server-side codes re-create the container, at most
 * twice; for a carousel only the failed child), EXPIRED (or a container older
 * than 23 hours) is re-created once. Re-creating from the publish phase runs on
 * a hop of its own ('restart'), outside the commit. "Media not ready" (2207027)
 * on publish polls every minute for five rounds, then backs off
 * (api.instagram.not_ready_backoff) until the container's 24-hour lifetime runs
 * out; 2207008 retries the same container twice before re-creating it.
 */
class InstagramDriver extends AbstractPlatform implements SupportsComments, SupportsFirstComment
{
    use TalksToMetaGraph;

    /** Default Graph API version, the one production runs on. */
    public const GRAPH_VERSION = 'v25.0';

    public const LOGIN_FACEBOOK = 'facebook';

    public const LOGIN_INSTAGRAM = 'instagram';

    /** Containers expire 24h after creation; stop waiting on one an hour before that. */
    protected const CONTAINER_MAX_AGE = 82800;

    /** Container ERROR codes worth a fresh container: Meta-side processing failures. */
    protected const RESTART_STATUS_CODES = [2207001, 2207003, 2207008, 2207020, 2207032, 2207053];

    /** API error subcodes that mean "this container is gone or broken, create a new one". */
    protected const RESTART_ERROR_SUBCODES = [2207006, 2207008, 2207020, 2207053];

    /**
     * 2207008 on media_publish is temporary: Meta asks to retry the same
     * container 1-2 times over 30 seconds to 2 minutes before creating a new one.
     */
    protected const BUILDER_RETRIES = 2;

    protected const BUILDER_RETRY_AFTER = 60;

    /** extra() keys a STORIES container refuses; stripped instead of failing the story. */
    protected const STORY_INVALID_KEYS = [
        'caption', 'collaborators', 'location_id', 'share_to_feed', 'cover_url', 'thumb_offset',
        'audio_name', 'trial_params', 'alt_text', 'product_tags', 'is_carousel_item', 'children',
    ];

    /** Images Instagram refuses but a bound MediaTransformer may convert to JPEG. */
    protected const CONVERTIBLE_IMAGES = [
        'png' => 'jpg', 'webp' => 'jpg', 'heic' => 'jpg', 'heif' => 'jpg', 'avif' => 'jpg',
        'gif' => 'jpg', 'bmp' => 'jpg', 'tif' => 'jpg', 'tiff' => 'jpg',
    ];

    /** Readable descriptions of the documented Instagram error subcodes. */
    protected const SUBCODE_MESSAGES = [
        2207001 => 'Instagram had a server error while processing the media.',
        2207003 => 'Instagram took too long to download the media.',
        2207004 => "The image is larger than Instagram's 8 MB limit.",
        2207005 => 'Instagram does not support this image format; use a JPEG.',
        2207006 => 'Instagram could not find the media container (a missing permission or an expired token).',
        2207008 => 'The Instagram media container expired or does not exist.',
        2207009 => 'Instagram does not support this aspect ratio; feed images must be between 4:5 and 1.91:1.',
        2207010 => 'The caption is too long or has too many hashtags or @mentions (2200 characters, 30 hashtags, 20 @mentions).',
        2207020 => 'The Instagram media container expired; a new one is needed.',
        2207023 => 'Instagram does not recognise the media type.',
        2207026 => 'Instagram does not support this video format; use an MP4 or MOV (H.264 or HEVC video, AAC audio) within the reel limits.',
        2207027 => 'The Instagram media is not ready to publish yet.',
        2207028 => 'An Instagram carousel needs between 2 and 10 items.',
        2207032 => 'Instagram failed to create the media container.',
        2207035 => 'Instagram refused the product or user tags.',
        2207036 => 'Instagram refused the product or user tags.',
        2207037 => 'Instagram refused the product or user tags.',
        2207040 => 'The caption tags more than 20 accounts.',
        2207042 => "The account reached Instagram's publishing limit for the last 24 hours (see content_publishing_limit).",
        2207050 => 'Meta has temporarily restricted this Instagram account.',
        2207051 => 'Instagram blocked this action as possible spam; check the account in the Instagram app.',
        2207052 => 'Instagram could not download the media; the URL must be public and long-lived.',
        2207053 => 'Instagram hit an unknown error while uploading the media.',
        2207057 => 'The thumb_offset is invalid; it must fall within the video.',
        2207069 => 'Instagram is throttling this account.',
    ];

    public function platform(): Platform
    {
        return Platform::Instagram;
    }

    /** The Facebook Login host (1.0's only host); Instagram Login accounts use graphBaseFor(). */
    protected function graphBase(): string
    {
        return 'https://graph.facebook.com/'.$this->apiConfig('graph_version', self::GRAPH_VERSION);
    }

    /** graph.instagram.com for Instagram Login accounts, graph.facebook.com otherwise. */
    protected function graphBaseFor(PreparedPost $post): string
    {
        return $this->hostLogin($post->credentials) === self::LOGIN_INSTAGRAM
            ? 'https://graph.instagram.com/'.$this->apiConfig('graph_version', self::GRAPH_VERSION)
            : $this->graphBase();
    }

    protected function containerStatusField(): string
    {
        return 'status_code';
    }

    protected function ingestion(): Ingestion
    {
        return Ingestion::PullUrl;
    }

    // --- Credentials, identity, scopes -------------------------------------------

    protected function requiredCredentials(): array
    {
        return [
            'account_id' => 'Instagram professional account (IG user) id',
            'access_token' => 'Instagram user access token (Facebook Login or Instagram Login)',
        ];
    }

    protected function identityKeys(): array
    {
        return ['account_id'];
    }

    /**
     * Instagram Login and Facebook Login grant differently named scopes. When the
     * login is known (credential 'login' or the token prefix) only its names are
     * required; when it is not, either name satisfies each group.
     */
    protected function requiredScopes(Feature $feature, Credentials $credentials): array
    {
        $login = $this->loginFor($credentials);

        $pick = static fn (string $facebook, string $instagram): array => match ($login) {
            self::LOGIN_FACEBOOK => [$facebook],
            self::LOGIN_INSTAGRAM => [$instagram],
            default => [$facebook, $instagram],
        };

        return match ($feature) {
            Feature::Publish => [
                $pick('instagram_basic', 'instagram_business_basic'),
                $pick('instagram_content_publish', 'instagram_business_content_publish'),
            ],
            Feature::Comment => [$pick('instagram_manage_comments', 'instagram_business_manage_comments')],
            default => [],
        };
    }

    /**
     * The login the credentials say they belong to: the explicit 'login' key
     * first (it always wins), else the token prefix (IG… Instagram Login, EAA…
     * Facebook Login), else null.
     */
    public function loginFor(Credentials $credentials): ?string
    {
        $explicit = $credentials->get('login');

        if (is_string($explicit)) {
            $explicit = strtolower(trim($explicit));

            if (str_starts_with($explicit, 'instagram') || $explicit === 'ig') {
                return self::LOGIN_INSTAGRAM;
            }

            if (str_starts_with($explicit, 'facebook') || $explicit === 'fb') {
                return self::LOGIN_FACEBOOK;
            }
        }

        return $this->loginFromToken($credentials->get($this->tokenKey));
    }

    /** The login a token was issued by, from its prefix, or null when it does not say. */
    protected function loginFromToken(mixed $token): ?string
    {
        if (! is_string($token)) {
            return null;
        }

        $token = trim($token);

        return match (true) {
            str_starts_with($token, 'IG') => self::LOGIN_INSTAGRAM,
            str_starts_with($token, 'EAA') => self::LOGIN_FACEBOOK,
            default => null,
        };
    }

    /** The login whose host requests go to: loginFor(), else api.instagram.default_login. */
    protected function hostLogin(Credentials $credentials): string
    {
        return $this->loginFor($credentials)
            ?? ($this->apiConfig('default_login', self::LOGIN_FACEBOOK) === self::LOGIN_INSTAGRAM ? self::LOGIN_INSTAGRAM : self::LOGIN_FACEBOOK);
    }

    // --- Capabilities and validation ---------------------------------------------

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            caption: $this->captionRules(),
            media: new MediaRules(minCount: 1, maxCount: $this->carouselMax(), image: $this->imageRules(), video: $this->reelRules()),
            supportsCarousel: true,
        );
    }

    protected function rulesFor(PreparedPost $post): Capabilities
    {
        $caption = $this->captionRules();
        $media = array_values($post->media());
        $first = $media[0] ?? null;
        $isVideo = $first?->type === MediaType::Video;

        if ($this->options($post)?->isStory) {
            return new Capabilities(
                // Stories are published without the caption, so its length is
                // no reason to refuse one (a fan-out shares one caption).
                caption: new CaptionRules(0, PHP_INT_MAX),
                media: new MediaRules(
                    minCount: 1,
                    maxCount: 1,
                    image: $isVideo ? null : $this->storyPhotoRules(),
                    video: $isVideo ? $this->storyVideoRules() : null,
                ),
            );
        }

        // Carousel: images and videos both allowed.
        if (count($media) > 1) {
            return new Capabilities(
                caption: $caption,
                media: new MediaRules(minCount: 2, maxCount: $this->carouselMax(), image: $this->imageRules(), video: $this->reelRules()),
                supportsCarousel: true,
            );
        }

        // Single item (image or reel). minCount 1 enforces "at least one media".
        return new Capabilities(
            caption: $caption,
            media: new MediaRules(
                minCount: 1,
                maxCount: 1,
                image: $isVideo ? null : $this->imageRules(),
                video: $isVideo ? $this->reelRules() : null,
            ),
        );
    }

    /**
     * Instagram-only rules the generic engine cannot express: hashtag and
     *
     * @mention caps (2207010 / 2207040), the alt text limit, and which variant
     * a cover, thumb_offset or share_to_feed applies to. The cover must be
     * reachable by URL (Instagram pulls it) and at most 8 MB.
     */
    protected function validatePlatformRules(PreparedPost $post, MessageBag $errors): void
    {
        $options = $this->options($post);
        $isStory = $options->isStory ?? false;
        $media = array_values($post->media());
        $first = $media[0] ?? null;
        $isReel = ! $isStory && count($media) === 1 && $first?->type === MediaType::Video;

        if (! $isStory) {
            $this->validateCaptionTags((string) $post->caption(), $errors);

            foreach ($media as $index => $item) {
                if ($item->type === MediaType::Image && $item->altText !== null && mb_strlen(trim($item->altText)) > 1000) {
                    $errors->add("media.{$index}", 'Instagram alt text is limited to 1000 characters.');
                }
            }
        }

        $this->validateConvertedImages($media, $errors);

        if ($options === null) {
            return;
        }

        if ($options->thumbnail !== null && ! $isStory && ! $isReel) {
            $errors->add('thumbnail', 'Instagram uses a cover image only on single-video reels.');
        }

        if ($options->thumbOffsetMs !== null && ! $isStory && ! $isReel) {
            $errors->add('thumb_offset', 'Instagram applies thumbOffsetMs only to single-video reels.');
        }

        if ($options->shareToFeed !== null && ! $isStory && ! $isReel) {
            $errors->add('share_to_feed', 'Instagram applies shareToFeed only to single-video reels.');
        }

        if ($isReel && $options->thumbnail !== null) {
            $cover = $options->thumbnail;
            $gateway = $this->gateway();

            if ($gateway !== null && ! $gateway->canProvideUrl($cover)) {
                $errors->add('thumbnail', 'Instagram fetches the cover from a public URL, but it is a local file. Host it publicly or bind a MediaGateway that can publish local files.');
            }

            $size = $this->inspectMedia($cover)?->sizeBytes;
            $max = (int) $this->apiConfig('cover_max_bytes', 8388608);

            if ($size !== null && $size > $max) {
                $errors->add('thumbnail', 'The Instagram cover image exceeds the '.round($max / 1048576).' MB limit.');
            }
        }
    }

    /**
     * An image Instagram only accepts once converted (WebP, HEIC..., or PNG with
     * api.instagram.jpeg_only) passes the generic rules when
     * ImageConvertingTransformer can convert it. That transformer writes a
     * local JPEG, and Instagram pulls media by URL: with a gateway that cannot
     * publish local files the conversion would be dropped and the original
     * sent, which Meta refuses (2207005). Say so before publishing.
     *
     * @param  array<int, Media>  $media
     */
    protected function validateConvertedImages(array $media, MessageBag $errors): void
    {
        $gateway = $this->gateway();

        if ($gateway === null || ! $this->mediaTransformer() instanceof ImageConvertingTransformer) {
            return;
        }

        $accepted = $this->imageExtensions();
        $servesLocal = null;

        foreach ($media as $index => $item) {
            $target = self::CONVERTIBLE_IMAGES[$item->extension()] ?? null;

            // A local original is already reported by validateMediaTransfer().
            if ($item->type !== MediaType::Image || ! $item->isRemote() || in_array($item->extension(), $accepted, true)
                || $target === null || ! $this->canTransform($item, $target)) {
                continue;
            }

            $servesLocal ??= $this->gatewayServesLocalFiles($gateway);

            if (! $servesLocal) {
                $errors->add("media.{$index}", "Instagram only takes JPEG images by URL. This .{$item->extension()} image would be converted to a local JPEG that the media gateway cannot publish, so the original would be sent and refused. Bind a gateway that re-hosts local files (e.g. FilesystemMediaGateway), or upload a JPEG.");
            }
        }
    }

    /** Whether the gateway can hand a local file (a converted image) over by URL. */
    protected function gatewayServesLocalFiles(MediaGateway $gateway): bool
    {
        $probe = @tempnam(sys_get_temp_dir(), 'sp_ig_');

        try {
            return $gateway->canProvideUrl(Media::image(is_string($probe) ? $probe : sys_get_temp_dir().'/sp_ig_probe.jpg'));
        } catch (Throwable) {
            return false;
        } finally {
            if (is_string($probe)) {
                @unlink($probe);
            }
        }
    }

    /** Instagram refuses captions with more than 30 hashtags or 20 @mentions. */
    protected function validateCaptionTags(string $caption, MessageBag $errors): void
    {
        $maxHashtags = (int) $this->apiConfig('max_hashtags', 30);
        $maxMentions = (int) $this->apiConfig('max_mentions', 20);

        $hashtags = (int) preg_match_all('/(?<![\w&])#[\p{L}\p{N}_]+/u', $caption);
        $mentions = (int) preg_match_all('/(?<![\w.])@[A-Za-z0-9._]+/u', $caption);

        if ($hashtags > $maxHashtags) {
            $errors->add('caption', "Instagram captions can have at most {$maxHashtags} hashtags (currently {$hashtags}).");
        }

        if ($mentions > $maxMentions) {
            $errors->add('caption', "Instagram captions can tag at most {$maxMentions} accounts (currently {$mentions}).");
        }
    }

    // --- Publish -----------------------------------------------------------------

    public function publish(PreparedPost $post): PublishOutcome
    {
        $this->assertCredentials($post);
        $this->assertTokenFresh($post);

        if ((bool) $this->apiConfig('check_quota', false)) {
            $this->assertPublishingQuota($post);
        }

        $media = array_values($post->media());

        if ($media === []) {
            throw new PermanentException('Instagram posts need at least one image or video.', Platform::Instagram, FailureReason::Validation);
        }

        if (count($media) > 1) {
            return $this->startCarousel($post);
        }

        return $media[0]->type === MediaType::Image
            ? $this->publishPhoto($post, $media[0])
            : $this->startVideo($post, $media[0]);
    }

    /**
     * Continue a publish. Accepts the 1.0 phases and keys for the whole 1.x line:
     * 'publish_retry' (1.0's 30-minute 2207027 retry) maps to 'publish', and
     * states without caption/since/media_type are completed from the post.
     */
    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        $this->assertCredentials($post);

        $state = $this->normaliseState($post, $state);

        // A wait longer than the queue can delay (over 900s) runs as several hops.
        $notBefore = (int) ($state['not_before'] ?? 0);

        if ($notBefore > time() + 5) {
            return $this->again($state, $notBefore - time(), ($state['phase'] ?? null) === 'publish');
        }

        unset($state['not_before']);

        return match ($state['phase'] ?? null) {
            'await_container' => $this->awaitContainer($post, $state),
            'await_children' => $this->awaitChildren($post, $state),
            'await_carousel' => $this->awaitCarousel($post, $state),
            'publish' => $this->publishPhase($post, $state),
            'restart' => $this->restart($post, $state),
            default => throw new PermanentException(
                'Unknown Instagram publish phase.',
                Platform::Instagram,
                FailureReason::Unknown,
                ['phase' => is_scalar($state['phase'] ?? null) ? $state['phase'] : null],
            ),
        };
    }

    // --- Single photo: container, then the publish commit ------------------------

    /**
     * Instagram downloads an image while the create call is open, so the
     * container is normally ready at once: hand straight over to the publish
     * commit (a not-ready answer there goes back to polling).
     */
    protected function publishPhoto(PreparedPost $post, Media $photo): PublishOutcome
    {
        $isStory = $this->options($post)->isStory ?? false;
        $source = $photo;
        $photo = $this->transformMedia($post, $photo);

        $params = ['image_url' => $this->mediaUrl($photo)];

        if ($isStory) {
            $params['media_type'] = 'STORIES';
        } else {
            $params += $this->captionParam($post);
            $params += $this->altTextParam($source);
            $params += $this->collaboratorsParam($post);
        }

        $id = $this->createContainer($post, $this->containerParams($post, $params, $isStory), 'media container');

        return $this->again($this->baseState($post, $isStory ? 'STORIES' : 'IMAGE') + [
            'phase' => 'publish',
            'container_id' => $id,
            'ready' => true,
        ], 0, true);
    }

    // --- Single video / reel / video story: container, poll, publish -------------

    protected function startVideo(PreparedPost $post, Media $video): PublishOutcome
    {
        $options = $this->options($post);
        $isStory = $options->isStory ?? false;

        $params = ['video_url' => $this->mediaUrl($this->transformMedia($post, $video))];

        if ($isStory) {
            $params['media_type'] = 'STORIES';
        } else {
            $params['media_type'] = 'REELS';
            $params += $this->captionParam($post);

            if (($cover = $this->coverFor($post, $video)) !== null) {
                $params['cover_url'] = $this->mediaUrl($cover);
            } elseif ($options?->thumbOffsetMs !== null) {
                $params['thumb_offset'] = $options->thumbOffsetMs;
            }

            if ($options?->shareToFeed !== null) {
                $params['share_to_feed'] = $options->shareToFeed;
            }

            $params += $this->collaboratorsParam($post);
        }

        $id = $this->createContainer($post, $this->containerParams($post, $params, $isStory), 'media container');

        return $this->again($this->baseState($post, $isStory ? 'STORIES' : 'REELS') + [
            'phase' => 'await_container',
            'container_id' => $id,
        ], $this->firstPollAfter());
    }

    protected function awaitContainer(PreparedPost $post, array $state): PublishOutcome
    {
        return $this->awaitPublishable($post, $state);
    }

    // --- Carousel: pooled children, poll videos, parent, poll, publish -----------

    protected function startCarousel(PreparedPost $post): PublishOutcome
    {
        $media = array_values($post->media());
        $state = $this->baseState($post, 'CAROUSEL');
        $ids = $this->createChildren($post, $media);

        $children = [];
        $pendingVideos = [];

        foreach ($media as $index => $item) {
            $children[] = $ids[$index];

            if ($item->type === MediaType::Video) {
                $pendingVideos[] = $ids[$index];
            }
        }

        return $this->again($state + [
            'phase' => 'await_children',
            'children' => $children,
            'pending_videos' => $pendingVideos,
        ], $pendingVideos === [] ? 5 : (int) $this->apiConfig('first_video_poll_after', 60));
    }

    /**
     * Poll the carousel's video children. A child that fails with a Meta-side
     * error code is re-created on its own (at most twice; an expired one once),
     * keeping its position. Once every child is ready, create the parent.
     *
     * Every pending child is read before any is re-created, and each new
     * container id is written to the state before a later create can fail: a
     * container created and then dropped with the state would be an orphan
     * (against Meta's 400 containers a day) and a restart the cap never counts.
     */
    protected function awaitChildren(PreparedPost $post, array $state): PublishOutcome
    {
        $children = array_values(array_map('strval', (array) ($state['children'] ?? [])));
        $pending = array_values(array_map('strval', (array) ($state['pending_videos'] ?? [])));
        $childRestarts = (array) ($state['child_restarts'] ?? []);
        $media = array_values($post->media());
        $stillPending = [];
        $failed = [];

        foreach ($pending as $videoId) {
            try {
                $container = $this->containerState($post, $videoId);
            } catch (TemporaryException|ConnectionException $e) {
                return $this->pollFailed($state, $e);
            }

            $status = $container['status'];

            if ($status === 'FINISHED' || $status === 'PUBLISHED') {
                continue;
            }

            if ($status !== 'ERROR' && $status !== 'EXPIRED') {
                $stillPending[] = $videoId;

                continue;
            }

            $found = array_search($videoId, $children, true);
            $index = $found === false ? null : (int) $found;
            $item = $index === null ? null : ($media[$index] ?? null);
            $restarts = $index === null ? 0 : (int) ($childRestarts[$index] ?? 0);
            $retryable = $status === 'EXPIRED'
                ? $restarts < 1
                : in_array($this->containerErrorCode($container['error_message']), self::RESTART_STATUS_CODES, true) && $restarts < $this->maxRestarts();

            if (! $retryable || $item === null || $index === null) {
                throw $this->containerFailure($state, $container, $videoId, $index);
            }

            $failed[] = ['id' => $videoId, 'index' => $index, 'item' => $item, 'restarts' => $restarts];
        }

        foreach ($failed as $position => $child) {
            try {
                $newId = $this->createChild($post, $child['item']);
            } catch (TemporaryException|ConnectionException $e) {
                // Keep the children re-created so far; the ones still to do stay
                // pending under their old id and are re-created on the next poll.
                $left = array_column(array_slice($failed, $position), 'id');

                return $this->pollFailed($this->childrenState($state, $children, array_merge($stillPending, $left), $childRestarts), $e);
            }

            $children[$child['index']] = $newId;
            $childRestarts[$child['index']] = $child['restarts'] + 1;
            $stillPending[] = $newId;
        }

        $state = $this->childrenState($state, $children, $stillPending, $childRestarts);
        $state['poll_errors'] = 0;

        if ($stillPending !== []) {
            return $this->again($state, $this->pollInterval());
        }

        $params = ['media_type' => 'CAROUSEL', 'children' => implode(',', $children)];
        $params += $this->captionParam($post, $state);
        $params += $this->collaboratorsParam($post);

        $id = $this->createContainer($post, $this->containerParams($post, $params, false), 'carousel container');

        return $this->again(['phase' => 'await_carousel', 'container_id' => $id] + $state, $this->firstPollAfter());
    }

    /**
     * The carousel state with its children, the videos still pending (in
     * carousel order) and the per-child restart counts.
     *
     * @param  array<string, mixed>  $state
     * @param  array<int, string>  $children
     * @param  array<int, string>  $pending
     * @param  array<int|string, mixed>  $childRestarts
     * @return array<string, mixed>
     */
    protected function childrenState(array $state, array $children, array $pending, array $childRestarts): array
    {
        $ordered = array_values(array_filter($children, static fn (string $id) => in_array($id, $pending, true)));

        $state['children'] = $children;
        $state['pending_videos'] = array_values(array_unique(array_merge($ordered, $pending)));

        if ($childRestarts !== []) {
            $state['child_restarts'] = $childRestarts;
        }

        return $state;
    }

    protected function awaitCarousel(PreparedPost $post, array $state): PublishOutcome
    {
        return $this->awaitPublishable($post, $state);
    }

    // --- Shared polling of the container to publish ------------------------------

    /**
     * Poll the single or carousel container: FINISHED hands over to the publish
     * commit, PUBLISHED (a publish that timed out earlier went through) is
     * recovered, ERROR/EXPIRED re-create or fail, anything else (IN_PROGRESS, or
     * no status at all) keeps polling. A failed status read is retried a few
     * times before it is allowed to fail the post.
     */
    protected function awaitPublishable(PreparedPost $post, array $state): PublishOutcome
    {
        $containerId = $this->containerIdFrom($state);

        try {
            $container = $this->containerState($post, $containerId);
        } catch (TemporaryException|ConnectionException $e) {
            return $this->pollFailed($state, $e);
        }

        $state['poll_errors'] = 0;

        return match ($container['status']) {
            'FINISHED' => $this->again(['phase' => 'publish', 'ready' => true] + $state, 0, true),
            'PUBLISHED' => $this->recoverPublished($post, $state),
            'ERROR' => $this->containerFailed($post, $state, $container),
            'EXPIRED' => $this->containerExpired($post, $state),
            default => $this->tooOld($state)
                ? $this->containerExpired($post, $state)
                : $this->again($state, $this->pollInterval()),
        };
    }

    // --- The publish commit ------------------------------------------------------

    /**
     * The non-idempotent step. Verifies the container first whenever this run
     * may repeat an earlier attempt, then calls media_publish with
     * publishTimeout(). Ambiguous answers (timeout, dropped connection, 5xx)
     * come back here after a minute, never as a blind re-POST; a throttle is a
     * refusal and is retried after verifying too.
     *
     * While an earlier publish may have gone through ('ambiguous', or the job's
     * `_recovering`), only a status read decides: a container that cannot be
     * read, or reports no status, is never published or re-created (see
     * unverified()). Without that doubt, a failed read is a readiness check
     * that sent no publish, and the job is told so.
     */
    protected function publishPhase(PreparedPost $post, array $state): PublishOutcome
    {
        $containerId = $this->containerIdFrom($state);
        $state['phase'] = 'publish';

        if (Pending::isRecovering($state) || ! empty($state['ambiguous']) || empty($state['ready'])) {
            // Keep the need to verify in the driver's own state: '_recovering'
            // is the job's and is not carried to the next hop.
            $state['ready'] = false;

            if (Pending::isRecovering($state)) {
                $state['ambiguous'] = true;
            }

            $ambiguous = ! empty($state['ambiguous']);

            try {
                $container = $this->containerState($post, $containerId);
            } catch (TemporaryException|ConnectionException $e) {
                return $ambiguous
                    ? $this->unverified($post, $state, $e)
                    : $this->pollFailed($state, $this->noPublishSent($e), true);
            } catch (PermanentException $e) {
                if ($ambiguous) {
                    return $this->unverified($post, $state, $e);
                }

                throw $e;
            }

            $status = $container['status'];

            // No (or an unknown) status is not "unpublished". Publishing on it is
            // safe only when no earlier attempt may have gone through: a
            // container that is not ready then answers 2207027.
            if ($ambiguous && ! in_array($status, ['PUBLISHED', 'ERROR', 'EXPIRED', 'IN_PROGRESS', 'FINISHED'], true)) {
                return $this->unverified($post, $state, null);
            }

            $state['poll_errors'] = 0;

            switch ($status) {
                case 'PUBLISHED':
                    return $this->recoverPublished($post, $state);
                case 'ERROR':
                    return $this->containerFailed($post, $state, $container);
                case 'EXPIRED':
                    return $this->containerExpired($post, $state);
                case 'IN_PROGRESS':
                    $state['phase'] = ($state['media_type'] ?? null) === 'CAROUSEL' ? 'await_carousel' : 'await_container';

                    return $this->again($state, $this->pollInterval());
            }
        }

        if ($this->tooOld($state)) {
            return $this->containerExpired($post, $state);
        }

        $this->assertTokenFresh($post);

        try {
            $response = $this->http($post)
                ->timeout($this->publishTimeout())
                ->post($this->edge($post, '/media_publish'), ['creation_id' => $containerId]);
        } catch (ConnectionException $e) {
            return $this->publishAmbiguous($state, $e, ! TemporaryException::requestNeverSent($e));
        }

        if ($this->ok($response)) {
            return $this->publishSucceeded($post, $state, $response);
        }

        $subcode = $this->subcode($response);

        if ($subcode === 2207027) {
            return $this->notReady($post, $state);
        }

        // A definitive refusal (nothing was published): retry the same
        // container as Meta documents before re-creating it.
        if ($subcode === 2207008 && (int) ($state['builder_retries'] ?? 0) < self::BUILDER_RETRIES) {
            $state['builder_retries'] = (int) ($state['builder_retries'] ?? 0) + 1;
            $state['ready'] = true;

            return $this->again($state, self::BUILDER_RETRY_AFTER, true);
        }

        if (in_array($subcode, self::RESTART_ERROR_SUBCODES, true)) {
            // A container an earlier attempt consumed may answer like a missing
            // one: while that attempt is in doubt, look the post up before
            // creating new containers.
            $doubt = ! empty($state['ambiguous']);

            if ($doubt && ($match = $this->findPublishedMedia($post, $state)) !== null) {
                return $this->publishedResult($post, $state, $match['id'], $match['permalink'] ?? $this->fetchPermalink($post, $match['id']), ['recovered' => true]);
            }

            if ($this->canRestart($state, $this->maxRestarts())) {
                return $this->restart($post, $state);
            }

            $exception = $this->mapError($response, $post);

            // The container is gone and new ones failed too: stop here rather than
            // let the job start over again.
            throw new PermanentException(
                $exception->getMessage(),
                Platform::Instagram,
                FailureReason::ServerError,
                ['restarts' => (int) ($state['restarts'] ?? 0), 'restart' => false]
                    + ($doubt ? ['possibly_published' => true] : [])
                    + $exception->context,
                $exception,
            );
        }

        $exception = $this->mapError($response, $post);

        if ($exception instanceof TemporaryException
            && empty($exception->context['reresolve'])
            && in_array($exception->reason, [FailureReason::ServerError, FailureReason::RateLimited, FailureReason::Network, FailureReason::Timeout], true)) {
            return $this->publishAmbiguous($state, $exception, $this->publishMayHaveGoneThrough($exception));
        }

        // An earlier attempt may have gone through before this refusal.
        if (! empty($state['ambiguous']) || Pending::isRecovering($state)) {
            $match = $this->findPublishedMedia($post, $state);

            if ($match !== null) {
                return $this->publishedResult($post, $state, $match['id'], $match['permalink'] ?? $this->fetchPermalink($post, $match['id']), ['recovered' => true]);
            }

            $this->markPossiblyPublished($exception);
        }

        throw $exception;
    }

    /**
     * Whether a temporary media_publish failure may have published anyway. A
     * throttle (code 4/17/32/613, 2207069, a 429) is Meta refusing the call
     * before acting on it: verify before retrying, but it creates no doubt.
     * Server-side failures stay doubtful even with HTTP 400, which Meta also
     * uses for "Instagram server error" (2207001) and "an unexpected error".
     */
    protected function publishMayHaveGoneThrough(TemporaryException $e): bool
    {
        return $e->reason !== FailureReason::RateLimited;
    }

    /**
     * The container of a publish that may already have gone through could not
     * be read ($e), or reported no status ($e null). Publishing now could post
     * twice, and so could the fresh containers a restart subcode (2207006...)
     * asks for, so neither happens: the read is retried as a commit, up to
     * api.instagram.max_poll_errors (a refusal does not wait). Then the post is
     * looked up among the account's media; without a match the failure says
     * possibly_published = true, so the job keeps the key for reconcile and the
     * app checks the account before posting again.
     *
     * @param  array<string, mixed>  $state
     */
    protected function unverified(PreparedPost $post, array $state, ?Throwable $e): PublishOutcome
    {
        if ($e instanceof TemporaryException && ! empty($e->context['reresolve'])) {
            // The job retries at once with fresh credentials; a second rejection is final.
            throw $this->markPossiblyPublished($e);
        }

        if (! $e instanceof PermanentException) {
            $failures = (int) ($state['poll_errors'] ?? 0) + 1;

            if ($failures <= (int) $this->apiConfig('max_poll_errors', 5)) {
                $state['poll_errors'] = $failures;

                $delay = $e instanceof TemporaryException && $e->retryAfter !== null
                    ? max($this->pollInterval(), $e->retryAfter)
                    : $this->pollInterval();

                return $this->again($state, $delay, true);
            }
        }

        $match = $this->findPublishedMedia($post, $state);

        if ($match !== null) {
            return $this->publishedResult($post, $state, $match['id'], $match['permalink'] ?? $this->fetchPermalink($post, $match['id']), ['recovered' => true]);
        }

        if ($e instanceof PermanentException) {
            throw $this->markPossiblyPublished($e);
        }

        // A connection message is redacted on the way (tokens in a URL).
        $cause = $e instanceof ConnectionException ? TemporaryException::connection(Platform::Instagram, $e) : $e;
        $context = $cause instanceof SocialPosterException ? $cause->context : [];

        throw new PermanentException(
            'Instagram did not confirm whether the post was published: '
            .($cause !== null ? 'its container could not be read ('.$cause->getMessage().')' : 'its container reported no status')
            .'. It may be live; check the account before posting it again.',
            Platform::Instagram,
            FailureReason::OutcomeUnknown,
            ['possibly_published' => true, 'container_id' => $state['container_id'] ?? null, 'poll_errors' => (int) ($state['poll_errors'] ?? 0)]
                + array_diff_key($context, array_flip(['restart', 'reresolve', 'request_sent', 'possibly_published'])),
            $cause,
        );
    }

    /**
     * Flag a failure as possibly published. For a re-resolve request the
     * wrapped rejection is flagged too: the job reports that one when the
     * fresh credentials are refused again.
     */
    protected function markPossiblyPublished(SocialPosterException $e): SocialPosterException
    {
        $e->withFlags(['possibly_published' => true]);
        $previous = $e->getPrevious();

        if ($e instanceof TemporaryException && ! empty($e->context['reresolve']) && $previous instanceof SocialPosterException) {
            $previous->withFlags(['possibly_published' => true]);
        }

        return $e;
    }

    /**
     * A container read inside the publish commit failed while no earlier
     * publish can have gone through. Say so: the job would otherwise count a
     * 5xx or a dropped connection in a commit run as a possibly sent publish,
     * and report a post that was never published as possibly live.
     */
    protected function noPublishSent(TemporaryException|ConnectionException $e): TemporaryException
    {
        $e = $e instanceof ConnectionException ? TemporaryException::connection(Platform::Instagram, $e) : $e;

        return $e->withFlags(['possibly_published' => false, 'request_sent' => false]);
    }

    /**
     * 1.0's publish step, kept for subclasses: publish $containerId now (as a
     * fresh, ready container).
     */
    protected function publishContainer(PreparedPost $post, string $containerId): PublishOutcome
    {
        return $this->publishPhase($post, $this->normaliseState($post, [
            'phase' => 'publish',
            'container_id' => $containerId,
            'ready' => true,
        ]));
    }

    /** media_publish answered 2xx: the post is live. */
    protected function publishSucceeded(PreparedPost $post, array $state, $response): Published
    {
        $body = $response->json();
        $mediaId = $response->json('id');
        $mediaId = is_int($mediaId) || is_string($mediaId) ? trim((string) $mediaId) : '';

        // 2xx without an id: the post is live, so look it up rather than fail it.
        if ($mediaId === '') {
            return $this->recoverPublished($post, $state + ['publish_response' => is_array($body) ? $body : null]);
        }

        return $this->publishedResult($post, $state, $mediaId, $this->fetchPermalink($post, $mediaId), [
            'response' => is_array($body) ? $body : null,
        ]);
    }

    /**
     * The publish failed without a definitive answer: come back in a minute
     * (or when the platform asked) and verify the container before anything
     * else. $possiblySent marks the state 'ambiguous' (the post may be live).
     * After api.instagram.max_publish_errors rounds the failure is handed to
     * the job, flagged possibly_published when this publish may have gone
     * through (a ConnectionException already tells the job through cURL).
     */
    protected function publishAmbiguous(array $state, Throwable $e, bool $possiblySent): Pending
    {
        $rounds = (int) ($state['publish_errors'] ?? 0) + 1;

        if ($rounds > (int) $this->apiConfig('max_publish_errors', 5)) {
            if ($possiblySent && $e instanceof SocialPosterException) {
                $this->markPossiblyPublished($e);
            }

            throw $e;
        }

        $state['publish_errors'] = $rounds;
        $state['ready'] = false;

        if ($possiblySent) {
            $state['ambiguous'] = true;
        }

        $delay = $e instanceof TemporaryException && $e->retryAfter !== null
            ? max($this->pollInterval(), $e->retryAfter)
            : $this->pollInterval();

        return $this->again($state, $delay, true);
    }

    /**
     * 2207027: the container is not ready to publish. Poll every minute for
     * api.instagram.not_ready_fast_rounds rounds, then every not_ready_backoff
     * seconds, never past the container's lifetime.
     */
    protected function notReady(PreparedPost $post, array $state): PublishOutcome
    {
        $rounds = (int) ($state['not_ready'] ?? 0) + 1;
        $state['not_ready'] = $rounds;
        $state['ready'] = false;

        if ($this->tooOld($state)) {
            return $this->containerExpired($post, $state);
        }

        $delay = $rounds <= (int) $this->apiConfig('not_ready_fast_rounds', 5)
            ? $this->pollInterval()
            : max($this->pollInterval(), (int) $this->apiConfig('not_ready_backoff', 1800));

        $createdAt = (int) ($state['created_at'] ?? 0);

        if ($createdAt > 0) {
            $delay = min($delay, max($this->pollInterval(), $createdAt + $this->containerMaxAge() - time()));
        }

        return $this->again($state, $delay, true);
    }

    // --- Container failures and restarts -----------------------------------------

    /** A container reported ERROR: re-create it for Meta-side codes, otherwise fail with the reason. */
    protected function containerFailed(PreparedPost $post, array $state, array $container): PublishOutcome
    {
        $code = $this->containerErrorCode($container['error_message'] ?? null);

        if (in_array($code, self::RESTART_STATUS_CODES, true) && $this->canRestart($state, $this->maxRestarts())) {
            return $this->restart($post, $state);
        }

        throw $this->containerFailure($state, $container, $this->containerIdFrom($state));
    }

    /**
     * A container expired (or is too old to publish): re-create it once, then
     * fail. Reached only once the container is known to be unpublished (an
     * EXPIRED or FINISHED status, a not-ready answer, or no earlier attempt),
     * so the failure says so.
     */
    protected function containerExpired(PreparedPost $post, array $state): PublishOutcome
    {
        if ($this->canRestart($state, 1)) {
            return $this->restart($post, $state);
        }

        throw new PermanentException(
            'The Instagram media container expired before it could be published.',
            Platform::Instagram,
            FailureReason::ProcessingTimeout,
            [
                'container_id' => $state['container_id'] ?? null,
                'created_at' => $state['created_at'] ?? null,
                'not_ready' => $state['not_ready'] ?? null,
                'restarts' => (int) ($state['restarts'] ?? 0),
                'possibly_published' => false,
            ],
        );
    }

    protected function canRestart(array $state, int $max): bool
    {
        return (int) ($state['restarts'] ?? 0) < $max;
    }

    /**
     * Start over with new containers (the old ones expire unpublished on their
     * own), carrying the restart count and the earliest 'since' so a recovery
     * lookup still covers the first attempt.
     *
     * From the publish commit this only schedules a 'restart' hop: creating
     * containers is not a publish, and a failure there (a 5xx, a dropped
     * connection) inside a commit run would be taken by the job for a
     * media_publish that may have gone through.
     */
    protected function restart(PreparedPost $post, array $state): PublishOutcome
    {
        if (($state['phase'] ?? null) === 'publish') {
            return $this->again(['phase' => 'restart'] + $state, 0);
        }

        $outcome = $this->publish($post);

        if (! $outcome instanceof Pending) {
            return $outcome;
        }

        $carry = ['restarts' => (int) ($state['restarts'] ?? 0) + 1];

        if (isset($state['since'], $outcome->state['since'])) {
            $carry['since'] = min((int) $state['since'], (int) $outcome->state['since']);
        }

        return $outcome->withState(array_replace($outcome->state, $carry));
    }

    /**
     * The permanent failure for a container (or carousel child, by index) that
     * reported ERROR or EXPIRED. Such a container was never published, which
     * the job is told (possibly_published = false) even on a recovering run.
     */
    protected function containerFailure(array $state, array $container, string $containerId, ?int $index = null): PermanentException
    {
        $text = $container['error_message'] ?? null;
        $status = $container['status'] ?? null;
        $code = $this->containerErrorCode($text);
        $restartable = in_array($code, self::RESTART_STATUS_CODES, true);

        $what = $index !== null ? 'carousel item '.($index + 1) : (($state['media_type'] ?? null) === 'CAROUSEL' ? 'the carousel' : 'the media');

        $message = match (true) {
            $status === 'EXPIRED' => "Instagram's container for {$what} expired before it could be published.",
            $code !== null && isset(self::SUBCODE_MESSAGES[$code]) => "Instagram failed to process {$what}: ".self::SUBCODE_MESSAGES[$code]." (error code {$code})",
            $code !== null => "Instagram failed to process {$what} (error code {$code}).",
            default => "Instagram failed to process {$what}.",
        };

        return new PermanentException(
            $message,
            Platform::Instagram,
            match (true) {
                $status === 'EXPIRED' => FailureReason::ProcessingTimeout,
                $restartable => FailureReason::ServerError,
                default => FailureReason::MediaRejected,
            },
            array_filter([
                'container_id' => $containerId,
                'container_status' => $status,
                'status' => $text,
                'subcode' => $code,
                'carousel_index' => $index,
                'restarts' => (int) ($state['restarts'] ?? 0),
            ], static fn ($value) => $value !== null) + ['possibly_published' => false],
        );
    }

    /** The error code in a container's status text ("... failed with error code 2207026"). */
    protected function containerErrorCode(?string $text): ?int
    {
        if ($text === null || $text === '') {
            return null;
        }

        if (preg_match('/error code:?\s*(\d+)/i', $text, $match) || preg_match('/\b(2207\d{3})\b/', $text, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    /**
     * A container status read failed with a temporary or network error. The
     * container is most likely fine: poll again in a minute, and only let the
     * error through after api.instagram.max_poll_errors in a row. A request to
     * re-resolve credentials always goes through.
     */
    protected function pollFailed(array $state, Throwable $e, bool $commit = false): Pending
    {
        if ($e instanceof TemporaryException && ! empty($e->context['reresolve'])) {
            throw $e;
        }

        $failures = (int) ($state['poll_errors'] ?? 0) + 1;

        if ($failures > (int) $this->apiConfig('max_poll_errors', 5)) {
            throw $e;
        }

        $state['poll_errors'] = $failures;

        $delay = $e instanceof TemporaryException && $e->retryAfter !== null
            ? max($this->pollInterval(), $e->retryAfter)
            : $this->pollInterval();

        return $this->again($state, $delay, $commit);
    }

    // --- Recovery of a published container ---------------------------------------

    /**
     * The container is PUBLISHED: an earlier media_publish went through (its
     * answer was lost). Never publish again; find the media id by caption and
     * time. The post is live either way, so this never fails: without a match the
     * result carries a null id and payload recovered = true.
     */
    protected function recoverPublished(PreparedPost $post, array $state): Published
    {
        $match = $this->findPublishedMedia($post, $state);

        if ($match === null) {
            return $this->publishedResult($post, $state, null, null, ['recovered' => true]);
        }

        return $this->publishedResult(
            $post,
            $state,
            $match['id'],
            $match['permalink'] ?? $this->fetchPermalink($post, $match['id']),
            ['recovered' => true],
        );
    }

    /**
     * Look the post up among the account's latest media (or stories): created
     * since the publish started, of the same type, with the same caption. A post
     * without a caption (and a story) is only trusted when it is the single
     * candidate. Best effort: null on any failure.
     *
     * @return array{id: string, permalink: ?string}|null
     */
    protected function findPublishedMedia(PreparedPost $post, array $state): ?array
    {
        $isStory = ($state['media_type'] ?? null) === 'STORIES';
        $since = (int) ($state['since'] ?? 0);
        $caption = $this->normaliseCaption((string) ($state['caption'] ?? ''));
        $expectedType = match ($state['media_type'] ?? null) {
            'IMAGE' => 'IMAGE',
            'REELS' => 'VIDEO',
            'CAROUSEL' => 'CAROUSEL_ALBUM',
            default => null,
        };

        try {
            $response = $this->http($post)->get($this->edge($post, $isStory ? '/stories' : '/media'), [
                'fields' => 'id,caption,timestamp,permalink,media_type',
                'limit' => 25,
            ]);

            if (! $this->ok($response)) {
                return null;
            }

            $items = $response->json('data');
        } catch (Throwable) {
            return null;
        }

        if (! is_array($items)) {
            return null;
        }

        $candidates = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! (is_string($item['id'] ?? null) || is_int($item['id'] ?? null))) {
                continue;
            }

            $timestamp = is_string($item['timestamp'] ?? null) ? strtotime($item['timestamp']) : false;

            if ($since > 0 && ($timestamp === false || $timestamp < $since)) {
                continue;
            }

            if ($expectedType !== null && is_string($item['media_type'] ?? null) && $item['media_type'] !== $expectedType) {
                continue;
            }

            if (! $isStory && $this->normaliseCaption(is_string($item['caption'] ?? null) ? $item['caption'] : '') !== $caption) {
                continue;
            }

            $candidates[] = $item;
        }

        if ($candidates === [] || (($caption === '' || $isStory) && count($candidates) > 1)) {
            return null;
        }

        // Newest first, as Graph lists them.
        $match = $candidates[0];
        $permalink = is_string($match['permalink'] ?? null) && $match['permalink'] !== '' ? $match['permalink'] : null;

        return ['id' => (string) $match['id'], 'permalink' => $permalink];
    }

    protected function normaliseCaption(string $caption): string
    {
        return trim(str_replace("\r\n", "\n", $caption));
    }

    // --- Requests ----------------------------------------------------------------

    /**
     * Read a container's status_code (IN_PROGRESS, FINISHED, PUBLISHED, ERROR,
     * EXPIRED) and status (the failure text with its error code). Overrides the
     * trait so errors are classified with the post (host/token mismatch).
     *
     * @param  list<string>|null  $fields
     * @return array{status: ?string, error_message: ?string, raw: array<string, mixed>}
     */
    protected function containerState(PreparedPost $post, string $id, ?array $fields = null): array
    {
        $fields ??= ['status_code', 'status'];

        $response = $this->http($post)->get($this->graphUrl($post, $id), ['fields' => implode(',', $fields)]);

        if (! $this->ok($response)) {
            throw $this->mapError($response, $post);
        }

        $raw = $response->json();
        $raw = is_array($raw) ? $raw : [];
        $status = $raw['status_code'] ?? null;
        $text = $raw['status'] ?? null;

        return [
            'status' => is_string($status) && trim($status) !== '' ? strtoupper(trim($status)) : null,
            'error_message' => is_string($text) && trim($text) !== '' ? trim($text) : null,
            'raw' => $raw,
        ];
    }

    /** POST {account}/media and return the container id. */
    protected function createContainer(PreparedPost $post, array $params, string $what): string
    {
        $response = $this->http($post)->post($this->edge($post, '/media'), $params);

        if (! $this->ok($response)) {
            throw $this->mapError($response, $post);
        }

        return $this->requireId($response, $what);
    }

    /**
     * Create the carousel children, keyed by their position. Pooled (at most
     * api.instagram.carousel_concurrency at once) because Instagram downloads
     * each image during its create call; results are read by key, never in
     * completion order. A pool entry that is not a Response (a dropped
     * connection) is a TemporaryException. Sequential when a request authorizer
     * is configured for Instagram (pooled requests cannot use it).
     *
     * @param  array<int, Media>  $items
     * @return array<int, string>
     */
    protected function createChildren(PreparedPost $post, array $items): array
    {
        $requests = [];

        foreach ($items as $index => $item) {
            $requests[$index] = $this->childParams($post, $item);
        }

        $url = $this->edge($post, '/media');

        if (count($requests) < 2 || ! $this->canPool()) {
            $ids = [];

            foreach ($requests as $index => $params) {
                $ids[$index] = $this->createContainer($post, $params, 'carousel item container');
            }

            return $ids;
        }

        $token = $this->token($post);
        $connectTimeout = (int) $this->apiConfig('connect_timeout', 10);
        $timeout = (int) $this->apiConfig('timeout', config('social.http_timeout', 60));

        $responses = Http::pool(function (Pool $pool) use ($requests, $url, $token, $connectTimeout, $timeout) {
            $pending = [];

            foreach ($requests as $index => $params) {
                $pending[] = $pool->as((string) $index)
                    ->withToken($token)
                    ->connectTimeout($connectTimeout)
                    ->timeout($timeout)
                    ->post($url, $params);
            }

            return $pending;
        }, max(1, (int) $this->apiConfig('carousel_concurrency', 5)));

        $ids = [];

        foreach (array_keys($requests) as $index) {
            $response = $responses[$index] ?? null;

            if (! $response instanceof Response) {
                throw $response instanceof Throwable
                    ? TemporaryException::connection(Platform::Instagram, $response)->addContext(['carousel_index' => $index])
                    : new TemporaryException("Instagram returned no response for carousel item {$index}.", Platform::Instagram, FailureReason::Network, null, ['carousel_index' => $index]);
            }

            if (! $this->ok($response)) {
                throw $this->mapError($response, $post)->addContext(['carousel_index' => $index]);
            }

            $ids[$index] = $this->requireId($response, 'carousel item container');
        }

        return $ids;
    }

    /** Re-create one carousel child (after a Meta-side processing failure). */
    protected function createChild(PreparedPost $post, Media $item): string
    {
        return $this->createContainer($post, $this->childParams($post, $item), 'carousel item container');
    }

    /**
     * A carousel child's params: Media::$meta['instagram'] (e.g. user_tags) for
     * per-item fields, then the driver's keys, which win. Alt text goes on
     * image children only.
     *
     * @return array<string, mixed>
     */
    protected function childParams(PreparedPost $post, Media $item): array
    {
        $extra = $item->meta['instagram'] ?? [];
        $extra = is_array($extra) ? $extra : [];
        $transformed = $this->transformMedia($post, $item);

        $params = ['is_carousel_item' => true];

        if ($item->type === MediaType::Image) {
            $params['image_url'] = $this->mediaUrl($transformed);
            $params += $this->altTextParam($item);
        } else {
            $params['video_url'] = $this->mediaUrl($transformed);
            $params['media_type'] = (string) $this->apiConfig('carousel_video_media_type', 'REELS');
        }

        return array_merge($extra, $params);
    }

    /** Pooling is skipped when the app configured its own request authorizer for Instagram. */
    protected function canPool(): bool
    {
        $authorizer = config('social.authorizers.'.$this->platform()->value);

        return ($authorizer === null || $authorizer === '' || $authorizer === false)
            && (bool) $this->apiConfig('pool_children', true);
    }

    /**
     * Pre-flight against the 100-posts-per-24h publishing limit
     * (api.instagram.check_quota). Best effort: any failure to read the quota
     * lets the publish go ahead.
     */
    protected function assertPublishingQuota(PreparedPost $post): void
    {
        try {
            $response = $this->http($post)->get($this->edge($post, '/content_publishing_limit'), ['fields' => 'quota_usage,config']);

            if (! $this->ok($response)) {
                return;
            }

            $usage = $response->json('data.0.quota_usage');
            $total = $response->json('data.0.config.quota_total');
        } catch (Throwable) {
            return;
        }

        if (is_numeric($usage) && is_numeric($total) && (int) $total > 0 && (int) $usage >= (int) $total) {
            throw $this->dailyLimitException(self::SUBCODE_MESSAGES[2207042], [
                'quota_usage' => (int) $usage,
                'quota_total' => (int) $total,
                'preflight' => true,
            ]);
        }
    }

    /**
     * A daily cap is never waited out inside the queue (owner decision): it is
     * permanent, with a retry_at / retry_after_hint for the app to reschedule.
     */
    protected function dailyLimitException(string $message, array $context = []): PermanentException
    {
        $hint = max(60, (int) $this->apiConfig('daily_limit_retry_after', 86400));

        return new PermanentException($message, Platform::Instagram, FailureReason::RateLimited, $context + [
            'daily_limit' => true,
            'retry_after_hint' => $hint,
            'retry_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $hint),
        ]);
    }

    // --- Params ------------------------------------------------------------------

    /**
     * The raw extra() passthrough under the driver's params (driver keys win).
     * On a story the keys a STORIES container refuses are dropped.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function containerParams(PreparedPost $post, array $params, bool $isStory): array
    {
        $extra = $this->extraPayload($post);

        if ($isStory) {
            $extra = array_diff_key($extra, array_flip(self::STORY_INVALID_KEYS));
        }

        return array_merge($extra, $params);
    }

    /**
     * The caption, omitted when blank (never sent on stories by the callers).
     *
     * @param  array<string, mixed>|null  $state
     * @return array<string, string>
     */
    protected function captionParam(PreparedPost $post, ?array $state = null): array
    {
        $caption = trim((string) ($state['caption'] ?? $post->caption()));

        return $caption !== '' ? ['caption' => $caption] : [];
    }

    /** @return array<string, string> */
    protected function altTextParam(Media $media): array
    {
        $alt = trim((string) $media->altText);

        return $alt !== '' ? ['alt_text' => $alt] : [];
    }

    /** @return array<string, list<string>> */
    protected function collaboratorsParam(PreparedPost $post): array
    {
        $collaborators = array_values(array_filter(
            array_map(static fn ($name) => is_string($name) ? ltrim(trim($name), '@') : '', $this->options($post)->collaborators ?? []),
            static fn ($name) => $name !== '',
        ));

        return $collaborators !== [] ? ['collaborators' => $collaborators] : [];
    }

    /**
     * The reel cover: InstagramOptions::$thumbnail, else the video's own
     * thumbnail when it is a JPEG the gateway can hand over by URL and not
     * known to exceed api.instagram.cover_max_bytes (otherwise Instagram picks
     * a frame rather than failing the reel). None when a thumb_offset was
     * chosen instead.
     */
    protected function coverFor(PreparedPost $post, Media $video): ?Media
    {
        $options = $this->options($post);

        if ($options?->thumbnail !== null) {
            return $options->thumbnail;
        }

        if ($options?->thumbOffsetMs !== null || $video->thumbnail === null || trim($video->thumbnail) === '') {
            return null;
        }

        $cover = Media::image(trim($video->thumbnail));

        $gateway = $this->gateway();

        if (! in_array($cover->extension(), ['jpg', 'jpeg'], true) || ($gateway !== null && ! $gateway->canProvideUrl($cover))) {
            return null;
        }

        $size = $this->inspectMedia($cover)?->sizeBytes;

        if ($size !== null && $size > (int) $this->apiConfig('cover_max_bytes', 8388608)) {
            return null;
        }

        return $cover;
    }

    /**
     * Run the bound MediaTransformer (e.g. ImageConvertingTransformer turning a
     * WebP, or a PNG with api.instagram.jpeg_only, into a JPEG). A converted
     * local file the gateway cannot publish by URL is not used: the original
     * is sent instead and Instagram decides.
     */
    protected function transformMedia(PreparedPost $post, Media $media): Media
    {
        $transformer = $this->mediaTransformer();

        if ($transformer === null || $transformer instanceof NullMediaTransformer) {
            return $media;
        }

        $transformed = $transformer->transform($media, $this->platform(), $this->rulesFor($post));

        $gateway = $this->gateway();

        if ($transformed === $media || ($gateway !== null && ! $gateway->canProvideUrl($transformed))) {
            return $media;
        }

        return $transformed;
    }

    // --- State -------------------------------------------------------------------

    /**
     * What every flow keeps: the media type, the caption as sent and 'since'
     * (both for a recovery lookup), and when the first container was created
     * (containers expire after 24 hours).
     *
     * @return array<string, mixed>
     */
    protected function baseState(PreparedPost $post, string $mediaType): array
    {
        $now = time();

        return [
            'media_type' => $mediaType,
            'caption' => $mediaType === 'STORIES' ? '' : trim((string) $post->caption()),
            'since' => $now - 300,
            'created_at' => $now,
            'restarts' => 0,
        ];
    }

    /**
     * Map 1.0 continuation states onto the current phases and complete missing
     * keys from the post, so a job queued before the upgrade still finishes.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function normaliseState(PreparedPost $post, array $state): array
    {
        if (($state['phase'] ?? null) === 'publish_retry') {
            // 1.0 scheduled this 30 minutes after a 2207027; check the container first.
            $state['phase'] = 'publish';
            $state['ready'] = false;
            $state['not_ready'] = max(1, (int) ($state['not_ready'] ?? 0));
        }

        $isStory = $this->options($post)->isStory ?? false;
        $media = array_values($post->media());

        $state['media_type'] ??= match (true) {
            $isStory => 'STORIES',
            count($media) > 1 || isset($state['children']) => 'CAROUSEL',
            ($media[0] ?? null)?->type === MediaType::Video => 'REELS',
            default => 'IMAGE',
        };

        $state['caption'] = $state['media_type'] === 'STORIES'
            ? ''
            : trim((string) ($state['caption'] ?? $post->caption()));

        // Only a 1.0 state lacks 'since'. Its start time is unknown: the job
        // stamps _started_at (and _committing_since) with the time of the run
        // that resumes it, which may be long after the publish. A recovery
        // lookup therefore covers the container's whole 24-hour lifetime, and
        // 'created_at' stays unknown (an EXPIRED status ends the wait instead).
        if (! isset($state['since'])) {
            $state['since'] = time() - 86400 - 300;
        }

        return $state;
    }

    /** The container to read or publish, from the state. */
    protected function containerIdFrom(array $state): string
    {
        $id = $state['container_id'] ?? null;

        if ((! is_string($id) && ! is_int($id)) || trim((string) $id) === '') {
            throw new PermanentException(
                'The Instagram continuation state has no container id.',
                Platform::Instagram,
                FailureReason::Unknown,
                ['phase' => $state['phase'] ?? null],
            );
        }

        return trim((string) $id);
    }

    /** True when the container is too close to its 24-hour expiry to keep waiting on it. */
    protected function tooOld(array $state): bool
    {
        $createdAt = (int) ($state['created_at'] ?? 0);

        return $createdAt > 0 && time() - $createdAt >= $this->containerMaxAge();
    }

    /**
     * The next hop. Waits longer than the queue can delay (over the clamp) are
     * stored as 'not_before' and run as several hops. A wait on a not-ready
     * container, and the verification of a publish that may have gone through,
     * carry a deadline past the job's default max_wait, bounded by the
     * container's lifetime: the driver's own budgets end them first, with a
     * failure that says whether the post may be live (the job's timeout cannot
     * know). Job-owned '_' keys are not carried forward.
     *
     * @param  array<string, mixed>  $state
     */
    protected function again(array $state, int $delay, bool $commit = false): Pending
    {
        $state = Pending::withoutReservedKeys($state);
        unset($state['not_before']);

        $max = $this->clampDelay(PHP_INT_MAX);

        if ($delay > $max) {
            $state['not_before'] = time() + $delay;
            $delay = $max;
        } elseif ($delay > 0) {
            $delay = $this->clampDelay($delay);
        } else {
            $delay = 0;
        }

        $createdAt = (int) ($state['created_at'] ?? 0);
        $deadline = $createdAt > 0 && ((int) ($state['not_ready'] ?? 0) > 0 || ! empty($state['ambiguous']))
            ? $createdAt + $this->containerMaxAge() + 1800
            : null;

        // A publish whose earlier answer was lost: the post may exist.
        return $commit
            ? $this->commit($state, $delay, $deadline, ! empty($state['ambiguous']))
            : PublishOutcome::pending($state, $delay, false, $deadline);
    }

    /**
     * The success result: payload carries the container id and media type (for
     * reconciliation), the carousel children and the raw publish response.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $payload
     */
    protected function publishedResult(PreparedPost $post, array $state, ?string $mediaId, ?string $url, array $payload = []): Published
    {
        $base = array_filter([
            'container_id' => $state['container_id'] ?? null,
            'media_type' => $state['media_type'] ?? null,
            'children' => $state['children'] ?? null,
            'restarts' => ! empty($state['restarts']) ? (int) $state['restarts'] : null,
            // A media_publish 2xx that had no id (recoverPublished()).
            'response' => is_array($state['publish_response'] ?? null) ? $state['publish_response'] : null,
        ], static fn ($value) => $value !== null);

        return $this->published(PostResult::success(
            Platform::Instagram,
            $mediaId,
            $url,
            $base + array_filter($payload, static fn ($value) => $value !== null),
            PostResult::STATE_PUBLISHED,
        ));
    }

    protected function graphUrl(PreparedPost $post, string $path): string
    {
        return rtrim($this->graphBaseFor($post), '/').'/'.ltrim($path, '/');
    }

    protected function pollInterval(): int
    {
        return max(5, (int) $this->apiConfig('poll_interval', 60));
    }

    protected function firstPollAfter(): int
    {
        return max(5, (int) $this->apiConfig('first_poll_after', 20));
    }

    protected function maxRestarts(): int
    {
        return max(0, (int) $this->apiConfig('max_restarts', 2));
    }

    protected function containerMaxAge(): int
    {
        return max(60, (int) $this->apiConfig('container_max_age', self::CONTAINER_MAX_AGE));
    }

    protected function carouselMax(): int
    {
        return max(2, (int) $this->apiConfig('carousel_max', 10));
    }

    // --- Helpers -----------------------------------------------------------------

    protected function options(PreparedPost $post): ?InstagramOptions
    {
        return $this->optionsOf($post, InstagramOptions::class);
    }

    protected function subcode($response): ?int
    {
        try {
            $subcode = $response->json('error.error_subcode');
        } catch (Throwable) {
            return null;
        }

        return is_numeric($subcode) ? (int) $subcode : null;
    }

    // --- First comment -----------------------------------------------------------

    /**
     * Stories cannot take comments, and a post that is not live (scheduled,
     * draft) has nothing to comment on yet. Otherwise 2200 characters, with the
     * comment scope of the account's login.
     */
    public function commentRules(PreparedPost $post, ?PostResult $parent = null): ?CommentRules
    {
        if ($this->options($post)?->isStory) {
            return null;
        }

        if ($parent !== null && (! $parent->isPublished() || ($parent->payload['media_type'] ?? null) === 'STORIES')) {
            return null;
        }

        return new CommentRules(
            max: (int) $this->apiConfig('comment_max_length', 2200),
            counter: new MbStrlenCounter,
            minDelaySeconds: max(0, (int) $this->apiConfig('comment_min_delay', 0)),
            requiredScopes: $this->requiredScopes(Feature::Comment, $post->credentials),
            unknownScopePolicy: CommentRules::ATTEMPT,
        );
    }

    /**
     * POST {host}/{ig-media-id}/comments {message} with the account's user
     * token, on the account's login host. Throttling is temporary through the
     * shared Meta table. Graph answers a comment with its id; a 2xx without one
     * is still a posted comment, so the id is then null rather than a failure.
     *
     * The comment create cannot be undone and the job retries it (a timeout
     * after Instagram stored it, a 5xx, a worker that died mid-request), and
     * Instagram does not refuse a duplicate comment. So the account's own
     * comment with the same text is looked for first and returned when it is
     * there (payload recovered), never posted twice.
     */
    public function addFirstComment(PreparedPost $context, PostResult $parent, string $text): CommentResult
    {
        $this->assertCredentials($context);

        $mediaId = trim((string) $parent->platformPostId);

        if ($mediaId === '') {
            throw new PermanentException(
                'The Instagram post has no media id to comment on.',
                Platform::Instagram,
                FailureReason::NotFound,
                ['no_post_id' => true],
            );
        }

        if (($existing = $this->findComment($context, $mediaId, $text)) !== null) {
            return new CommentResult($existing, null, ['media_id' => $mediaId, 'recovered' => true]);
        }

        $response = $this->http($context)->post($this->graphUrl($context, rawurlencode($mediaId).'/comments'), [
            'message' => $text,
        ]);

        if (! $this->ok($response)) {
            throw $this->mapError($response, $context);
        }

        $id = $response->json('id');
        $body = $response->json();

        return new CommentResult(
            is_string($id) || is_int($id) ? (string) $id : null,
            null,
            ['media_id' => $mediaId, 'response' => is_array($body) ? $body : null],
        );
    }

    /**
     * The id of the account's own top-level comment with this text on the
     * media, among the 25 newest (GET {host}/{ig-media-id}/comments, reverse
     * chronological), or null. Reading needs the scope the comment needs.
     *
     * The comment is the account's when Graph returns its `user` field (only
     * returned for the app user's own comments; its id may be another id kind
     * than account_id), or when from.id is the account_id (from.id is an
     * IGSID, so it may not be). A comment whose author is not shown at all is
     * matched on its text alone. A throttle or an outage is thrown, so the job
     * retries instead of commenting blind; a refused read proves nothing
     * either way and lets the comment go out, as it did before the lookup.
     */
    protected function findComment(PreparedPost $context, string $mediaId, string $text): ?string
    {
        $response = $this->http($context)->get($this->graphUrl($context, rawurlencode($mediaId).'/comments'), [
            'fields' => 'id,text,timestamp,from,user',
            'limit' => 25,
        ]);

        if (! $this->ok($response)) {
            $error = $this->mapError($response, $context);

            if ($error instanceof TemporaryException) {
                throw $error;
            }

            return null;
        }

        try {
            $items = $response->json('data');
        } catch (Throwable) {
            return null;
        }

        $wanted = $this->normaliseCaption($text);
        $accountId = trim((string) $context->credentials->get('account_id'));

        foreach (is_array($items) ? $items : [] as $item) {
            if (! is_array($item) || ! is_string($item['text'] ?? null) || $this->normaliseCaption($item['text']) !== $wanted) {
                continue;
            }

            $id = $item['id'] ?? null;

            if ((! is_string($id) && ! is_int($id)) || trim((string) $id) === '') {
                continue;
            }

            if ($this->isOwnComment($item, $accountId)) {
                return trim((string) $id);
            }
        }

        return null;
    }

    /**
     * Whether a listed comment was written by the account (see findComment()).
     *
     * @param  array<mixed>  $item
     */
    protected function isOwnComment(array $item, string $accountId): bool
    {
        $shown = static fn (mixed $value): bool => (is_string($value) || is_int($value)) && trim((string) $value) !== '';
        $fromId = data_get($item, 'from.id');

        if ($accountId !== '' && $shown($fromId) && trim((string) $fromId) === $accountId) {
            return true;
        }

        if (! in_array($item['user'] ?? null, [null, '', []], true)) {
            return true;
        }

        return ! $shown($fromId) && ! $shown(data_get($item, 'from.username')) && ! $shown($item['username'] ?? null);
    }

    /**
     * @deprecated since 1.1: use addFirstComment(). Kept as a thin adapter for
     *             SupportsComments callers.
     */
    public function comment(PreparedPost $context, string $postId, string $comment): ?string
    {
        return $this->addFirstComment($context, PostResult::success(Platform::Instagram, $postId), $comment)->id;
    }

    // --- Errors ------------------------------------------------------------------

    /**
     * Instagram's error table, in order:
     *
     * 1. a 190 when the token belongs to the other login's host than the one it
     *    was sent to (explicit 'login' contradicting the token prefix) =>
     *    Misconfigured: the account is healthy, the credentials are not;
     * 2. Instagram subcodes, before the generic codes they ride on (2207051 comes
     *    with code 4 but is a spam block, not throttling);
     * 3. the 1.0 message rows ('not an Instagram Business', 'unexpected error');
     * 4. the shared Meta table (tokens, throttling, transient, permissions);
     * 5. parent::classifyError() by HTTP status.
     *
     * 2207027 is handled by the publish phase; here it is a plain temporary.
     *
     * v1.0's signature, so a subclass override written for 1.0 still loads:
     * mapError() forwards the PreparedPost as an extra argument, read here
     * with func_get_args().
     *
     * @param  mixed  $response
     */
    protected function classifyError($response): TemporaryException|PermanentException
    {
        $extra = func_get_args()[1] ?? null;
        $post = $extra instanceof PreparedPost ? $extra : null;

        [
            'code' => $code,
            'subcode' => $subcode,
            'raw' => $raw,
            'message' => $message,
            'context' => $context,
        ] = $this->metaErrorParts($response);

        if ($code === 190 && $post !== null && ($mismatch = $this->loginMismatch($post)) !== null) {
            return new PermanentException(
                "The Instagram token was issued by {$mismatch['token']} Login, but the credentials say login = '{$mismatch['login']}', so it was sent to the wrong Graph host. Fix the 'login' credential (or remove it to detect it from the token). ({$message})",
                Platform::Instagram,
                FailureReason::Misconfigured,
                $context + ['login' => $mismatch['login'], 'token_login' => $mismatch['token'], 'host_mismatch' => true],
            );
        }

        if ($subcode !== 0 && ($exception = $this->classifySubcode($subcode, $message, $context)) !== null) {
            return $exception;
        }

        $lower = strtolower($raw);

        if (str_contains($lower, 'not an instagram business')) {
            return new PermanentException('The account is not an Instagram Business or Creator account.', Platform::Instagram, FailureReason::AccountRestricted, $context);
        }

        if (str_contains($lower, 'unexpected error has occurred')
            || str_contains($lower, 'unknown error has occurred')
            || str_contains($lower, 'please reduce the amount of data')) {
            return new TemporaryException("Meta's API is having issues; retrying shortly. ({$message})", Platform::Instagram, FailureReason::ServerError, 60, $context);
        }

        return $this->classifyMetaError($response) ?? parent::classifyError($response);
    }

    /**
     * The documented Instagram subcodes, or null for one this table does not know.
     *
     * @param  array<string, mixed>  $context
     */
    protected function classifySubcode(int $subcode, string $message, array $context): TemporaryException|PermanentException|null
    {
        $known = self::SUBCODE_MESSAGES[$subcode] ?? null;
        $text = $known !== null && $message !== '' && $message !== $known ? "{$known} ({$message})" : ($known ?? $message);
        $platform = Platform::Instagram;

        return match (true) {
            $subcode === 2207042 => $this->dailyLimitException($text, $context),
            $subcode === 2207052 => new PermanentException($text, $platform, FailureReason::MediaRejected, $context),
            in_array($subcode, self::RESTART_ERROR_SUBCODES, true) => new TemporaryException($text, $platform, FailureReason::ServerError, 60, $context + ['restart' => true]),
            in_array($subcode, [2207001, 2207003, 2207032], true) => new TemporaryException($text, $platform, FailureReason::ServerError, 60, $context),
            $subcode === 2207027 => new TemporaryException($text, $platform, FailureReason::ServerError, 60, $context + ['not_ready' => true]),
            in_array($subcode, [2207004, 2207005, 2207009, 2207023, 2207026, 2207028], true) => new PermanentException($text, $platform, FailureReason::MediaRejected, $context),
            in_array($subcode, [2207010, 2207035, 2207036, 2207037, 2207040, 2207057], true) => new PermanentException($text, $platform, FailureReason::InvalidContent, $context),
            $subcode === 2207050 => new PermanentException('Meta has temporarily restricted this Instagram account.', $platform, FailureReason::AccountRestricted, $context),
            $subcode === 2207051 => new PermanentException($text, $platform, FailureReason::AccountRestricted, $context + ['spam_block' => true]),
            $subcode === 2207069 => new TemporaryException($text, $platform, FailureReason::RateLimited, 90, $context),
            default => null,
        };
    }

    /**
     * The login the credentials claim and the one the token was issued by, when
     * they disagree (so requests went to the wrong host); null otherwise.
     *
     * @return array{login: string, token: string}|null
     */
    protected function loginMismatch(PreparedPost $post): ?array
    {
        $token = $this->loginFromToken($post->credentials->get($this->tokenKey));
        $host = $this->hostLogin($post->credentials);

        return $token !== null && $token !== $host ? ['login' => $host, 'token' => $token === self::LOGIN_INSTAGRAM ? 'Instagram' : 'Facebook'] : null;
    }

    // --- Rule sets ---------------------------------------------------------------

    protected function captionRules(): CaptionRules
    {
        return new CaptionRules(max: 2200);
    }

    /**
     * Feed and carousel images. Instagram documents JPEG only, but PNG has
     * always worked in production, so it stays accepted unless
     * api.instagram.jpeg_only is set; other formats pass validation only when a
     * bound MediaTransformer can convert them to JPEG.
     */
    protected function imageRules(): ImageRules
    {
        return new ImageRules(
            extensions: $this->imageExtensions(),
            maxSizeBytes: (int) $this->apiConfig('image_max_bytes', 8388608),
            aspectRatioRange: [0.8, 1.91],
            convert: self::CONVERTIBLE_IMAGES,
        );
    }

    /** Reels and carousel videos: 23-60 fps, as the IG User Media reference documents. */
    protected function reelRules(): VideoRules
    {
        return new VideoRules(
            extensions: ['mp4', 'mov'],
            maxSizeBytes: (int) $this->apiConfig('reel_max_bytes', 314572800),
            minDuration: 3,
            maxDuration: 900,
            maxWidth: 1920,
            aspectRatioRange: [0.01, 10.0],
            minFrameRate: (float) $this->apiConfig('min_video_frame_rate', 23),
            maxFrameRate: (float) $this->apiConfig('max_video_frame_rate', 60),
        );
    }

    protected function storyPhotoRules(): ImageRules
    {
        return new ImageRules(
            extensions: $this->imageExtensions(),
            maxSizeBytes: (int) $this->apiConfig('image_max_bytes', 8388608),
            convert: self::CONVERTIBLE_IMAGES,
        );
    }

    /** Story videos share the reel's container, codec and frame-rate specs. */
    protected function storyVideoRules(): VideoRules
    {
        return new VideoRules(
            extensions: ['mp4', 'mov'],
            maxSizeBytes: (int) $this->apiConfig('story_video_max_bytes', 104857600),
            minDuration: 3,
            maxDuration: 60,
            maxWidth: 1920,
            aspectRatioRange: [0.1, 10.0],
            minFrameRate: (float) $this->apiConfig('min_video_frame_rate', 23),
            maxFrameRate: (float) $this->apiConfig('max_video_frame_rate', 60),
        );
    }

    /** @return list<string> */
    protected function imageExtensions(): array
    {
        return (bool) $this->apiConfig('jpeg_only', false) ? ['jpg', 'jpeg'] : ['jpg', 'jpeg', 'png'];
    }
}
