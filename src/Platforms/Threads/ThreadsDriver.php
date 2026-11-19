<?php

namespace SocialPoster\Platforms\Threads;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Sleep;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\CommentRules;
use SocialPoster\Capabilities\ImageRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Capabilities\VideoRules;
use SocialPoster\Concerns\TalksToMetaGraph;
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
use SocialPoster\Support\Text\ThreadsCounter;
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
 * Threads uses the Meta container model: create a container, wait until Threads
 * reports it FINISHED, then publish it with threads_publish. Everything rides
 * the publish()/resume() loop, so no worker ever sleeps on a container.
 *
 * Phases (kept in the continuation state):
 *
 * - publish(): create the container (text, image, video), or every carousel
 *   item at once (Http::pool) for a carousel. State keeps created_at and the
 *   text, so a post that went live unseen can be found again.
 * - 'await_children': poll every carousel item (images too) until FINISHED,
 *   then create the CAROUSEL parent. A failed item is re-created in place.
 * - 'await': read the container's status and error_message.
 *   FINISHED goes to 'publish'; IN_PROGRESS waits; PUBLISHED (an earlier
 *   threads_publish went through) recovers the post instead of publishing
 *   again, looking it up on the account's threads (or replies, for a post
 *   published as a reply) up to 3 times; EXPIRED restarts once; ERROR restarts
 *   on FAILED_DOWNLOADING_VIDEO / UNKNOWN (at most twice) and fails with a
 *   readable reason otherwise. A missing status for 12 polls publishes
 *   anyway, so the publish answer says what is wrong. An IN_PROGRESS
 *   container is never published.
 * - 'publish' (a commit phase): POST threads_publish. A connection dropped
 *   after sending goes back to 'await' rather than re-POSTing blind (one that
 *   never left the client bubbles for a clean retry); a run flagged
 *   `_recovering` reads the container status first.
 *
 * Containers are created through the v1.0 hooks startSingle() /
 * startCarousel(), on restarts too, so subclass overrides keep applying.
 *
 * Resume keeps accepting v1.0 continuations: 'await_publish' runs as 'await'
 * (status first), and the 'container' key is read as 'container_id'.
 *
 * Auth: the Bearer header (AbstractPlatform::http()), plus access_token as a
 * body field on every POST, which is the form Meta documents. GETs carry only
 * the header, so the token never lands in a URL. Requires the numeric Threads
 * user id as account_id: threads_publish is not reliable on 'me'.
 *
 * Quotas: 250 published posts and 1,000 replies per profile per rolling 24h
 * (a carousel counts as one post). publishingQuota() reads the usage; set
 * social.api.threads.check_quota to refuse a post up front once the quota is used.
 */
class ThreadsDriver extends AbstractPlatform implements SupportsFirstComment
{
    use TalksToMetaGraph;

    /** Container error_message values worth a fresh container: the media may download or process next time. */
    protected const RETRYABLE_CONTAINER_ERRORS = ['FAILED_DOWNLOADING_VIDEO', 'UNKNOWN'];

    /** Fresh containers after a retryable ERROR (FAILED_DOWNLOADING_VIDEO / UNKNOWN). */
    protected const MAX_ERROR_RESTARTS = 2;

    /** Fresh containers after an EXPIRED one (not published within 24h). */
    protected const MAX_EXPIRED_RESTARTS = 1;

    /** Clock skew allowed when matching a recovered post's timestamp against created_at. */
    protected const RECOVERY_SKEW_SECONDS = 300;

    /** threads_publish answers without an id this many times before the outcome is declared unknown. */
    protected const MAX_VERIFY_ROUNDS = 3;

    /** Longest wait (seconds) before looking a recovered post up again, so a throttle cannot outlast the job's wait limit. */
    protected const RECOVERY_LOOKUP_MAX_DELAY = 60;

    /** The permission a post with a location tag (location_id) needs. */
    protected const LOCATION_SCOPE = 'threads_location_tagging';

    public function platform(): Platform
    {
        return Platform::Threads;
    }

    protected function graphBase(): string
    {
        return 'https://graph.threads.net/'.$this->apiConfig('graph_version', 'v1.0');
    }

    protected function ingestion(): Ingestion
    {
        return Ingestion::PullUrl;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            caption: $this->captionRules(),
            media: new MediaRules(minCount: 0, maxCount: $this->maxCarouselItems(), image: $this->imageRules(), video: $this->videoRules()),
            supportsCarousel: true,
        );
    }

    protected function rulesFor(PreparedPost $post): Capabilities
    {
        if (count($post->media()) > 1) {
            return new Capabilities(
                caption: $this->captionRules(),
                media: new MediaRules(minCount: 2, maxCount: $this->maxCarouselItems(), image: $this->imageRules(), video: $this->videoRules()),
                supportsCarousel: true,
            );
        }

        return new Capabilities(
            caption: $this->captionRules(),
            media: new MediaRules(minCount: 0, maxCount: 1, image: $this->imageRules(), video: $this->videoRules()),
        );
    }

    /** @return array<string, string> */
    protected function requiredCredentials(): array
    {
        return [
            'account_id' => 'the numeric Threads user id',
            'access_token' => 'a Threads user access token',
        ];
    }

    /** @return list<string> */
    protected function identityKeys(): array
    {
        return ['account_id'];
    }

    /** @return array<int, string|array<int, string>> */
    protected function requiredScopes(Feature $feature, Credentials $credentials): array
    {
        return match ($feature) {
            Feature::Publish => [['threads_content_publish']],
            Feature::Comment => [['threads_manage_replies']],
            default => [],
        };
    }

    protected function validatePlatformRules(PreparedPost $post, MessageBag $errors): void
    {
        $caption = trim((string) $post->caption());
        $media = $post->media();
        $options = $this->optionsOf($post, ThreadsOptions::class);
        $extra = $this->extraPayload($post);

        if ($caption === '' && $media === []) {
            $errors->add('caption', 'Threads posts without media should at least contain text.');
        }

        $maxLinks = $this->maxLinks();
        $attachment = $this->linkAttachment($post);
        $links = $this->countLinks($caption, $attachment);

        if ($links > $maxLinks) {
            $errors->add('caption', "Threads allows at most {$maxLinks} links per post; this one has {$links} (every unique URL in the text, plus a different link attachment).");
        }

        $maxAlt = (int) $this->apiConfig('max_alt_text', 1000);

        foreach ($media as $index => $item) {
            if ($item->altText !== null && mb_strlen(trim($item->altText)) > $maxAlt) {
                $errors->add("media.{$index}", "Alt text exceeds Threads' {$maxAlt} character limit.");
            }
        }

        if ($media !== []) {
            if ($attachment !== null) {
                $errors->add('options.link_attachment', 'Threads only takes a link attachment on a text post (no media).');
            }

            if ($options?->pollAttachment !== null || isset($extra['poll_attachment'])) {
                $errors->add('options.poll_attachment', 'Threads only takes a poll on a text post (no media).');
            }
        } elseif ($options !== null && $options->spoilerMedia) {
            $errors->add('options.spoiler_media', 'spoilerMedia marks the media as a spoiler, but this post has no media.');
        }

        // A location tag needs its own App Review permission on top of publishing.
        $scopes = $post->credentials->scopes();

        if ($scopes !== null && $this->locationTag($post) !== null && ! in_array(static::LOCATION_SCOPE, $scopes, true)) {
            $errors->add('credentials.scopes', 'The Threads token is missing a permission needed to tag a location: grant '.static::LOCATION_SCOPE.'.');
        }

        // CommentRules carries the reply's length; the link limit applies to it too.
        $comment = trim((string) $post->comment());

        if ($comment !== '' && ($commentLinks = $this->countLinks($comment, null)) > $maxLinks) {
            $errors->add('comment', "Threads allows at most {$maxLinks} links in a reply; this one has {$commentLinks}.");
        }
    }

    /**
     * The raw passthrough without auto_publish_text: the driver always publishes
     * through threads_publish, and a container that published itself on creation
     * would make that call fail for a live post.
     *
     * @return array<string, mixed>
     */
    protected function extraPayload(PreparedPost $post): array
    {
        $extra = parent::extraPayload($post);
        unset($extra['auto_publish_text']);

        return $extra;
    }

    // --- Publish -----------------------------------------------------------------

    public function publish(PreparedPost $post): PublishOutcome
    {
        $this->assertCredentials($post);
        $this->assertPublishableMedia($post);
        $this->assertQuotaAvailable($post);

        return $this->start($post);
    }

    /** @param array<string, mixed> $state */
    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        $this->assertCredentials($post);
        $state = $this->upgradeState($state);

        return match ($state['phase'] ?? null) {
            'await_children' => $this->awaitChildren($post, $state),
            'await' => $this->awaitContainer($post, $state),
            'publish' => $this->publishPhase($post, $state),
            default => throw new PermanentException(
                'Unknown Threads publish phase.',
                Platform::Threads,
                FailureReason::Unknown,
                ['phase' => is_scalar($state['phase'] ?? null) ? $state['phase'] : null],
            ),
        };
    }

    /**
     * Map a v1.0 continuation onto the 1.1 phases: 'await_publish' (a blind
     * publish after 30s) re-reads the status first as 'await', 'container'
     * becomes 'container_id', and a v1.0 carousel that only tracked its video
     * items now polls every item.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function upgradeState(array $state): array
    {
        if (! isset($state['container_id']) && isset($state['container']) && is_scalar($state['container'])) {
            $state['container_id'] = (string) $state['container'];

            // v1.0 only polled video containers in 'await'.
            if (($state['phase'] ?? null) === 'await') {
                $state['kind'] ??= 'video';
            }
        }

        unset($state['container']);

        if (($state['phase'] ?? null) === 'await_publish') {
            $state['phase'] = 'await';
        }

        if (($state['phase'] ?? null) === 'await_children') {
            $state['kind'] ??= 'carousel';

            if (! isset($state['pending'])) {
                $state['pending'] = $state['children'] ?? [];
                $state['video_children'] = $state['pending_videos'] ?? [];
            }

            unset($state['pending_videos']);
        }

        return $state;
    }

    /**
     * Create the first container(s) of a post. $carry holds the restart
     * counters when this is a fresh attempt after an ERROR or EXPIRED container.
     *
     * Runs through the v1.0 hooks startSingle() / startCarousel(), so a
     * subclass that overrides one still applies, on restarts too. The counters
     * are laid over whatever state the hook returns, so a hook that builds its
     * own state cannot reset the restart budget.
     *
     * @param  array<string, mixed>  $carry
     */
    protected function start(PreparedPost $post, array $carry = []): PublishOutcome
    {
        $media = array_values($post->media());

        $outcome = count($media) > 1
            ? $this->startCarousel($post)
            : $this->startSingle($post, $media[0] ?? null);

        return $carry !== [] && $outcome instanceof Pending
            ? $outcome->withState($carry + $outcome->state)
            : $outcome;
    }

    // --- Single (text, image, video) ---------------------------------------------

    /** v1.0 hook (publish() and restarts call it): create a single container. */
    protected function startSingle(PreparedPost $post, ?Media $item): PublishOutcome
    {
        return $this->createSingle($post, $item);
    }

    /** @param array<string, mixed> $carry */
    protected function createSingle(PreparedPost $post, ?Media $item, array $carry = []): Pending
    {
        $caption = trim((string) $post->caption());
        $kind = match ($item?->type) {
            null => 'text',
            MediaType::Video => 'video',
            default => 'image',
        };

        $params = $item === null
            ? ['media_type' => 'TEXT', 'text' => $caption]
            : $this->mediaParams($item);

        if ($item !== null && $caption !== '') {
            $params['text'] = $caption;
        }

        $params = $this->mergeExtra($post, array_merge($this->postLevelParams($post, $kind), $params));

        $response = $this->graphPost($post, $this->edge($post, '/threads'), $params);

        if (! $this->ok($response)) {
            throw $this->forLocationTag($params, $this->mapError($response));
        }

        $container = $this->requireId($response, 'container');

        return $this->pendingFor([
            'phase' => 'await',
            'kind' => $kind,
            'container_id' => $container,
            'created_at' => $this->nowTimestamp(),
            'text' => $caption,
        ] + $carry, $this->initialCheckAfter());
    }

    /**
     * Container params for one media item (single post or carousel item):
     * media_type, the public URL and the alt text.
     *
     * @return array<string, mixed>
     *
     * @throws PermanentException For a document (MediaRejected), before any request.
     */
    protected function mediaParams(Media $item): array
    {
        $params = match ($item->type) {
            MediaType::Image => ['media_type' => 'IMAGE', 'image_url' => $this->mediaUrl($item)],
            MediaType::Video => ['media_type' => 'VIDEO', 'video_url' => $this->mediaUrl($item)],
            MediaType::Document => throw $this->documentRejected(),
        };

        if ($item->altText !== null && trim($item->altText) !== '') {
            $params['alt_text'] = trim($item->altText);
        }

        return $params;
    }

    /**
     * Post-level fields from ThreadsOptions, for the single container or the
     * carousel parent (never a carousel item). Text-only fields (link and poll
     * attachments) are sent on text posts only, and is_spoiler_media on media
     * posts only.
     *
     * @return array<string, mixed>
     */
    protected function postLevelParams(PreparedPost $post, string $kind): array
    {
        $options = $this->optionsOf($post, ThreadsOptions::class);

        if ($options === null) {
            return [];
        }

        $text = $kind === 'text';
        $params = [];

        if ($options->replyControl !== null) {
            $params['reply_control'] = $options->replyControl->value;
        }

        if (($tag = $options->normalizedTopicTag()) !== null) {
            $params['topic_tag'] = $tag;
        }

        if ($text && $options->linkAttachment !== null && trim($options->linkAttachment) !== '') {
            $params['link_attachment'] = trim($options->linkAttachment);
        }

        if ($text && $options->pollAttachment !== null) {
            $params['poll_attachment'] = $options->pollAttachment->toArray();
        }

        foreach (['quote_post_id' => $options->quotePostId, 'reply_to_id' => $options->replyToId, 'location_id' => $options->locationId] as $key => $value) {
            if ($value !== null && trim($value) !== '') {
                $params[$key] = trim($value);
            }
        }

        $countries = array_values(array_filter(array_map(
            static fn ($code) => is_string($code) ? strtoupper(trim($code)) : '',
            $options->allowlistedCountryCodes,
        ), static fn (string $code) => $code !== ''));

        if ($countries !== []) {
            $params['allowlisted_country_codes'] = implode(',', $countries);
        }

        if ($options->madeWithAi) {
            $params['is_made_with_ai'] = true;
        }

        if (! $text && $options->spoilerMedia) {
            $params['is_spoiler_media'] = true;
        }

        return $params;
    }

    // --- Carousel ----------------------------------------------------------------

    /** v1.0 hook (publish() and restarts call it): create the carousel items. */
    protected function startCarousel(PreparedPost $post): PublishOutcome
    {
        return $this->createCarousel($post);
    }

    /** @param array<string, mixed> $carry */
    protected function createCarousel(PreparedPost $post, array $carry = []): Pending
    {
        $items = array_values($post->media());

        // Built first, so a document is refused before any request.
        $params = array_map(fn (Media $item) => $this->childParams($item), $items);
        $children = $this->createChildren($post, $params);

        $videos = [];

        foreach ($items as $index => $item) {
            if ($item->type === MediaType::Video) {
                $videos[] = $children[$index];
            }
        }

        return $this->pendingFor([
            'phase' => 'await_children',
            'kind' => 'carousel',
            'children' => $children,
            'pending' => $children,
            'video_children' => $videos,
            'created_at' => $this->nowTimestamp(),
            'text' => trim((string) $post->caption()),
        ] + $carry, $videos === [] ? $this->pollInterval() : $this->videoPollInterval());
    }

    /**
     * A carousel item: is_carousel_item, media_type, the URL and the alt text.
     * Post-level options and extra never go on items.
     *
     * @return array<string, mixed>
     */
    protected function childParams(Media $item): array
    {
        return ['is_carousel_item' => true] + $this->mediaParams($item);
    }

    /**
     * Create the carousel items and return their container ids in source
     * order. Concurrent through Http::pool (with the same token, body field and
     * timeouts as http()); sequential when a request authorizer is configured
     * for Threads, when social.api.threads.pool_children is false, or for one
     * item.
     *
     * @param  list<array<string, mixed>>  $paramsList
     * @return list<string>
     */
    protected function createChildren(PreparedPost $post, array $paramsList): array
    {
        $url = $this->edge($post, '/threads');

        if (count($paramsList) < 2 || ! $this->poolsChildren()) {
            $ids = [];

            foreach ($paramsList as $params) {
                $response = $this->graphPost($post, $url, $params);

                if (! $this->ok($response)) {
                    throw $this->mapError($response);
                }

                $ids[] = $this->requireId($response, 'carousel item container');
            }

            return $ids;
        }

        $concurrency = max(1, min(count($paramsList), (int) $this->apiConfig('pool_concurrency', 10)));

        // Concurrency is passed positionally: Laravel 11 has no such parameter and ignores it.
        $responses = Http::pool(function (Pool $pool) use ($post, $paramsList, $url) {
            foreach ($paramsList as $index => $params) {
                $this->pooledRequest($pool->as((string) $index), $post)->post($url, $this->withTokenField($post, $params));
            }
        }, $concurrency);

        $ids = [];

        foreach (array_keys($paramsList) as $index) {
            $response = $responses[$index] ?? null;

            if ($response instanceof Throwable) {
                throw TemporaryException::connection(Platform::Threads, $response);
            }

            if (! $response instanceof Response) {
                throw new TemporaryException(
                    'Threads gave no response for carousel item '.($index + 1).'.',
                    Platform::Threads,
                    FailureReason::Network,
                    null,
                    ['connection' => true, 'item' => $index],
                );
            }

            if (! $this->ok($response)) {
                throw $this->mapError($response);
            }

            $ids[] = $this->requireId($response, 'carousel item container');
        }

        return $ids;
    }

    /**
     * A pooled request configured like http(): token, connect timeout and
     * timeout. (Pooled requests come from the pool, not from http().)
     */
    protected function pooledRequest(PendingRequest $request, PreparedPost $post): PendingRequest
    {
        $token = $this->token($post);

        if (trim($token) !== '') {
            $request = $request->withToken($token);
        }

        return $request
            ->connectTimeout((int) $this->apiConfig('connect_timeout', 10))
            ->timeout((int) $this->apiConfig('timeout', config('social.http_timeout', 60)));
    }

    /**
     * Whether carousel items may be created through Http::pool. A configured
     * request authorizer only applies through http(), so it forces the
     * sequential path.
     */
    protected function poolsChildren(): bool
    {
        $authorizer = config('social.authorizers.'.$this->platform()->value);

        return (bool) $this->apiConfig('pool_children', true)
            && ($authorizer === null || $authorizer === '' || $authorizer === false);
    }

    /**
     * Poll every pending carousel item. FINISHED items leave the list; when it is
     * empty (or only items with no status are left after the missing-status
     * budget) the CAROUSEL parent is created. An item in ERROR or EXPIRED is
     * re-created in place, within the restart budget.
     *
     * @param  array<string, mixed>  $state
     */
    protected function awaitChildren(PreparedPost $post, array $state): PublishOutcome
    {
        $children = $this->idList($state['children'] ?? []);

        if ($children === []) {
            throw new PermanentException('The Threads continuation state has no carousel items.', Platform::Threads, FailureReason::Unknown, ['phase' => 'await_children']);
        }

        $pending = $this->idList($state['pending'] ?? $children);
        $videos = $this->idList($state['video_children'] ?? []);
        $state['polls'] = (int) ($state['polls'] ?? 0) + 1;

        $stillPending = [];
        $inProgress = false;

        foreach ($pending as $childId) {
            $container = $this->containerState($post, $childId);
            $status = $this->normalizeStatus($container['status']);

            if ($status === 'FINISHED' || $status === 'PUBLISHED') {
                continue;
            }

            if ($status === 'ERROR' || $status === 'EXPIRED') {
                return $this->replaceChild($post, $state, $childId, $status, $container['error_message']);
            }

            $stillPending[] = $childId;
            $inProgress = $inProgress || $status === 'IN_PROGRESS';
        }

        if ($inProgress) {
            // The missing-status budget counts consecutive unknown reads only.
            unset($state['missing_polls']);
        } elseif ($stillPending !== []) {
            // Only items without a status are left: give up waiting after the budget.
            $state['missing_polls'] = (int) ($state['missing_polls'] ?? 0) + 1;

            if ($state['missing_polls'] >= $this->missingStatusPolls()) {
                $stillPending = [];
            }
        }

        if ($stillPending === []) {
            return $this->createParent($post, $state, $children);
        }

        $state['pending'] = $stillPending;

        return $this->pendingFor(
            $state,
            array_intersect($stillPending, $videos) !== [] ? $this->videoPollInterval() : $this->pollInterval(),
        );
    }

    /**
     * Re-create one failed carousel item in place (the parent needs every item),
     * or the whole carousel when the item cannot be matched to its media.
     *
     * @param  array<string, mixed>  $state
     */
    protected function replaceChild(PreparedPost $post, array $state, string $childId, string $status, ?string $errorMessage): PublishOutcome
    {
        $children = $this->idList($state['children'] ?? []);
        $index = array_search($childId, $children, true);
        $position = $index === false ? null : $index + 1;
        $what = $position !== null ? "carousel item {$position}" : 'a carousel item';

        $state = $this->countRestart($state, $status, $errorMessage, $childId, $what);

        $media = array_values($post->media());

        if ($index === false || ! isset($media[$index]) || count($media) !== count($children)) {
            return $this->restart($post, $state);
        }

        $response = $this->graphPost($post, $this->edge($post, '/threads'), $this->childParams($media[$index]));

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        $newId = $this->requireId($response, 'carousel item container');
        $swap = static fn (array $ids) => array_map(static fn (string $id) => $id === $childId ? $newId : $id, $ids);

        $state['children'] = $swap($children);
        $state['pending'] = $swap($this->idList($state['pending'] ?? $children));
        $state['video_children'] = $swap($this->idList($state['video_children'] ?? []));
        $state['missing_polls'] = 0;

        return $this->pendingFor($state, $this->initialCheckAfter());
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $children
     */
    protected function createParent(PreparedPost $post, array $state, array $children): Pending
    {
        $text = trim((string) ($state['text'] ?? $post->caption()));
        $params = ['media_type' => 'CAROUSEL', 'children' => implode(',', $children)];

        if ($text !== '') {
            $params['text'] = $text;
        }

        $params = $this->mergeExtra($post, array_merge($this->postLevelParams($post, 'carousel'), $params));

        $response = $this->graphPost($post, $this->edge($post, '/threads'), $params);

        if (! $this->ok($response)) {
            throw $this->forLocationTag($params, $this->mapError($response));
        }

        $parent = $this->requireId($response, 'carousel container');

        return $this->pendingFor([
            'phase' => 'await',
            'kind' => 'carousel',
            'container_id' => $parent,
            'children' => $children,
            'created_at' => (int) ($state['created_at'] ?? $this->nowTimestamp()),
            'text' => $text,
        ] + $this->counters($state), $this->pollInterval());
    }

    // --- Status ------------------------------------------------------------------

    /** @param array<string, mixed> $state */
    protected function awaitContainer(PreparedPost $post, array $state): PublishOutcome
    {
        $container = $this->containerState($post, $this->stateContainer($state));

        return $this->onContainerStatus($post, $state, $container, false);
    }

    /**
     * Act on a container status read. $committing is true inside the 'publish'
     * phase, where FINISHED (or a missing status past the budget) publishes at
     * once instead of scheduling the publish.
     *
     * @param  array<string, mixed>  $state
     * @param  array{status: ?string, error_message: ?string, raw: array<string, mixed>}  $container
     */
    protected function onContainerStatus(PreparedPost $post, array $state, array $container, bool $committing): PublishOutcome
    {
        $state['phase'] = 'await';
        $state['polls'] = (int) ($state['polls'] ?? 0) + 1;

        return match ($this->normalizeStatus($container['status'])) {
            'FINISHED' => $committing ? $this->publishNow($post, $state) : $this->toPublish($state),
            'PUBLISHED' => $this->recoverPublished($post, $state),
            // The missing-status budget counts consecutive unknown reads only.
            'IN_PROGRESS' => $this->pendingFor(array_diff_key($state, ['missing_polls' => true]), $this->pollAfter($state)),
            'EXPIRED' => $this->restart($post, $this->countRestart($state, 'EXPIRED', null, $this->stateContainer($state), 'the post')),
            'ERROR' => $this->restart($post, $this->countRestart($state, 'ERROR', $container['error_message'], $this->stateContainer($state), $this->describeKind($state))),
            default => $this->onUnknownStatus($post, $state, $committing),
        };
    }

    /**
     * No status (or one Threads does not document): poll again, and after
     * social.api.threads.missing_status_polls (12) publish anyway, as the
     * production app does. The publish answer then says what is wrong.
     *
     * @param  array<string, mixed>  $state
     */
    protected function onUnknownStatus(PreparedPost $post, array $state, bool $committing): PublishOutcome
    {
        $state['missing_polls'] = (int) ($state['missing_polls'] ?? 0) + 1;

        if ($state['missing_polls'] >= $this->missingStatusPolls()) {
            return $committing ? $this->publishNow($post, $state) : $this->toPublish($state);
        }

        return $this->pendingFor($state, $this->pollInterval());
    }

    /**
     * Count a restart for an ERROR or EXPIRED container, or throw when the
     * container cannot be retried (a permanent error_message, or the budget is
     * spent).
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     *
     * @throws PermanentException
     */
    protected function countRestart(array $state, string $status, ?string $errorMessage, string $containerId, string $what): array
    {
        if ($status === 'EXPIRED') {
            $expirations = (int) ($state['expirations'] ?? 0);

            if ($expirations >= static::MAX_EXPIRED_RESTARTS) {
                throw new PermanentException(
                    "The Threads container for {$what} expired (containers must be published within 24 hours).",
                    Platform::Threads,
                    FailureReason::Timeout,
                    ['container_id' => $containerId, 'container_status' => 'EXPIRED', 'expirations' => $expirations],
                );
            }

            $state['expirations'] = $expirations + 1;

            return $state;
        }

        $code = strtoupper(trim((string) $errorMessage));
        $restarts = (int) ($state['restarts'] ?? 0);
        $retryable = $code === '' || in_array($code, static::RETRYABLE_CONTAINER_ERRORS, true);

        if ($retryable && $restarts < static::MAX_ERROR_RESTARTS) {
            $state['restarts'] = $restarts + 1;

            return $state;
        }

        $description = $this->describeContainerError($code);
        $message = $retryable
            ? "Threads could not process {$what} after ".($restarts + 1)." attempts: {$description}"
            : "Threads rejected {$what}: {$description}";

        throw new PermanentException($message, Platform::Threads, FailureReason::MediaRejected, [
            'container_id' => $containerId,
            'container_status' => 'ERROR',
            'error_message' => $code !== '' ? $code : null,
            'restarts' => $restarts,
        ]);
    }

    /** A readable reason for a container error_message (Threads troubleshooting codes). */
    protected function describeContainerError(string $code): string
    {
        $reason = match ($code) {
            'FAILED_DOWNLOADING_VIDEO' => 'Threads could not download the video from its URL; check that it is public and stays reachable.',
            'FAILED_PROCESSING_AUDIO' => 'the audio track could not be processed; use AAC audio, mono or stereo.',
            'FAILED_PROCESSING_VIDEO' => 'the video could not be processed; use H.264 or HEVC in an MP4 or MOV file.',
            'INVALID_ASPEC_RATIO', 'INVALID_ASPECT_RATIO' => 'the aspect ratio must be between 0.01:1 and 10:1.',
            'INVALID_BIT_RATE' => 'the video bit rate is not supported.',
            'INVALID_DURATION' => 'the video must be at most 5 minutes (300 seconds) long.',
            'INVALID_FRAME_RATE' => 'the frame rate must be between 23 and 60 fps.',
            'INVALID_AUDIO_CHANNELS' => 'the audio must be mono or stereo.',
            'INVALID_AUDIO_CHANNEL_LAYOUT' => 'the audio channel layout is not supported.',
            'UNKNOWN', '' => 'Threads reported an unknown processing error.',
            default => 'the media was refused.',
        };

        return $code !== '' && $code !== 'UNKNOWN' ? "{$reason} ({$code})" : $reason;
    }

    /**
     * Start over with fresh containers, keeping the restart counters (and the
     * restart budget) across the new attempt.
     *
     * @param  array<string, mixed>  $state
     */
    protected function restart(PreparedPost $post, array $state): PublishOutcome
    {
        return $this->start($post, $this->counters($state));
    }

    // --- Publish (commit) ----------------------------------------------------------

    /**
     * The commit phase. A run flagged `_recovering` may repeat a threads_publish
     * that went through, so it reads the container first: PUBLISHED recovers
     * the post, FINISHED publishes, anything else goes back to waiting.
     *
     * @param  array<string, mixed>  $state
     */
    protected function publishPhase(PreparedPost $post, array $state): PublishOutcome
    {
        if (Pending::isRecovering($state)) {
            $container = $this->containerState($post, $this->stateContainer($state));

            return $this->onContainerStatus($post, $state, $container, true);
        }

        return $this->publishNow($post, $state);
    }

    /**
     * v1.0 entry point: publish a container now. Still callable, but the
     * driver no longer calls it: the commit phase runs publishNow(), which
     * needs the continuation state (recovery text, verify rounds).
     *
     * @deprecated since 1.1, no longer called by the driver; override publishNow() instead.
     */
    protected function publishContainer(PreparedPost $post, string $containerId): PublishOutcome
    {
        return $this->publishNow($post, ['container_id' => $containerId]);
    }

    /**
     * POST threads_publish. A dropped connection may hide a publish that went
     * through, so it returns to 'await' (a committed wait) and the status read
     * decides: PUBLISHED recovers the post, FINISHED publishes again. Threads
     * publishes a container at most once.
     *
     * A request that provably never left the client (DNS, refused, TLS,
     * connect timeout) cannot have published anything: it bubbles, so the job
     * retries this phase as a clean try and never marks the chain committed.
     *
     * @param  array<string, mixed>  $state
     */
    protected function publishNow(PreparedPost $post, array $state): PublishOutcome
    {
        $containerId = $this->stateContainer($state);

        try {
            $response = $this->graphPost($post, $this->edge($post, '/threads_publish'), ['creation_id' => $containerId], $this->publishTimeout());
        } catch (ConnectionException $e) {
            if (TemporaryException::requestNeverSent($e)) {
                throw $e;
            }

            return $this->pendingFor(['phase' => 'await', 'publish_sent' => true] + $state, $this->pollInterval());
        }

        if (! $this->ok($response)) {
            $error = $this->mapError($response);

            // A transient refusal (5xx, code 1/2, is_transient) may still have
            // published, whatever the HTTP status: read the container before
            // publishing again, as after a lost answer. The job alone would
            // retry an HTTP 400 as a clean refusal.
            if ($error instanceof TemporaryException && $this->mayHavePublished($error)) {
                return $this->pendingFor(['phase' => 'await', 'publish_sent' => true] + $state, $this->pollInterval());
            }

            // 4279009 (not ready yet) and throttles are temporary: the job retries this phase.
            throw $error;
        }

        try {
            $mediaId = $this->requireId($response, 'post', 'id', true);
        } catch (PermanentException $e) {
            // Accepted without an id: the container status says whether it went live.
            $rounds = (int) ($state['verify_rounds'] ?? 0) + 1;

            if ($rounds > static::MAX_VERIFY_ROUNDS) {
                throw $e;
            }

            return $this->pendingFor(['phase' => 'await', 'publish_sent' => true, 'verify_rounds' => $rounds] + $state, $this->pollInterval());
        }

        return $this->published(PostResult::success(
            Platform::Threads,
            $mediaId,
            $this->fetchPermalink($post, $mediaId),
            $this->resultPayload($post, $state, ['response' => $response->json()]),
            PostResult::STATE_PUBLISHED,
        ));
    }

    /**
     * The container is PUBLISHED: an earlier threads_publish went through (its
     * answer was lost). Find the post among the account's latest threads by
     * text and time instead of publishing again.
     *
     * A lookup that fails on a temporary error (throttle, 5xx, timeout) or
     * does not list the post yet is tried again on a later run, up to
     * social.api.threads.recovery_lookups (3) in all: the container stays
     * PUBLISHED, so the wait is committed and nothing is published again.
     * A post that cannot be told apart (no text), or is still not found after
     * the last lookup, is reported as published with a null id, so a live post
     * never fails.
     *
     * @param  array<string, mixed>  $state
     */
    protected function recoverPublished(PreparedPost $post, array $state): PublishOutcome
    {
        $found = $this->findPublishedThread($post, $state);

        if ($found['id'] === null && $found['retryable']) {
            $lookups = (int) ($state['lookup_attempts'] ?? 0) + 1;

            if ($lookups < $this->recoveryLookups()) {
                $wait = min((int) ($found['retry_after'] ?? 0), static::RECOVERY_LOOKUP_MAX_DELAY);

                return $this->pendingFor(
                    ['phase' => 'await', 'publish_sent' => true, 'lookup_attempts' => $lookups] + $state,
                    max($this->pollInterval(), $wait),
                );
            }
        }

        $permalink = $found['permalink'];

        if ($found['id'] !== null && $permalink === null) {
            $permalink = $this->fetchPermalink($post, $found['id']);
        }

        return $this->published(PostResult::success(
            Platform::Threads,
            $found['id'],
            $permalink,
            $this->resultPayload($post, $state, array_filter([
                'recovered' => true,
                'lookup_failed' => $found['lookup_failed'] ?: null,
            ])),
            PostResult::STATE_PUBLISHED,
        ));
    }

    /**
     * GET {account}/threads?fields=id,text,timestamp,permalink&limit=25 and match
     * trim(text) plus timestamp >= created_at - 300s. A post published as a
     * reply (reply_to_id) is listed among the account's replies, so it is
     * looked up on GET {account}/replies first, then on /threads.
     *
     * Best-effort: a failure gives a null id with lookup_failed. 'retryable'
     * says whether a later lookup may still find it (a temporary failure, or no
     * match yet), with the failure's retry_after; a refusal (missing
     * permission) or a post without text is final.
     *
     * @param  array<string, mixed>  $state
     * @return array{id: ?string, permalink: ?string, lookup_failed: bool, retryable: bool, retry_after: ?int}
     */
    protected function findPublishedThread(PreparedPost $post, array $state): array
    {
        $text = $this->normalizeText((string) ($state['text'] ?? ''));

        if ($text === '') {
            return ['id' => null, 'permalink' => null, 'lookup_failed' => false, 'retryable' => false, 'retry_after' => null];
        }

        $since = isset($state['created_at']) && is_numeric($state['created_at'])
            ? (int) $state['created_at'] - static::RECOVERY_SKEW_SECONDS
            : null;

        $failed = false;
        $refused = false;
        $retryAfter = null;

        foreach ($this->recoveryEdges($post) as $edge) {
            try {
                $response = $this->http($post)->get($this->edge($post, $edge), [
                    'fields' => 'id,text,timestamp,permalink',
                    'limit' => 25,
                ]);
            } catch (Throwable) {
                $failed = true;

                continue;
            }

            if (! $this->ok($response)) {
                $failed = true;
                $error = $this->lookupError($response);

                if ($error instanceof PermanentException) {
                    $refused = true;
                } elseif ($error instanceof TemporaryException) {
                    $retryAfter = max((int) $retryAfter, (int) $error->retryAfter);
                }

                continue;
            }

            $found = $this->matchPublishedThread($response->json('data'), $text, $since);

            if ($found !== null) {
                return $found + ['lookup_failed' => false, 'retryable' => false, 'retry_after' => null];
            }
        }

        return [
            'id' => null,
            'permalink' => null,
            'lookup_failed' => $failed,
            'retryable' => ! $refused,
            'retry_after' => $retryAfter,
        ];
    }

    /**
     * The edges a recovered post is looked up on: the account's replies first
     * for a post published as a reply, then its threads.
     *
     * @return list<string>
     */
    protected function recoveryEdges(PreparedPost $post): array
    {
        return $this->replyTarget($post) !== null ? ['/replies', '/threads'] : ['/threads'];
    }

    /** A failed lookup, classified (null when even that fails, which counts as temporary). */
    protected function lookupError(Response $response): TemporaryException|PermanentException|null
    {
        try {
            return $this->mapError($response);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The first listed post whose text matches and that is not older than
     * $since (unix time; null matches on text alone).
     *
     * @param  mixed  $items  The list's 'data'.
     * @return array{id: string, permalink: ?string}|null
     */
    protected function matchPublishedThread(mixed $items, string $text, ?int $since): ?array
    {
        foreach (is_array($items) ? $items : [] as $item) {
            if (! is_array($item) || $this->normalizeText((string) ($item['text'] ?? '')) !== $text) {
                continue;
            }

            if ($since !== null) {
                $timestamp = $this->parseTimestamp($item['timestamp'] ?? null);

                if ($timestamp === null || $timestamp < $since) {
                    continue;
                }
            }

            $id = is_scalar($item['id'] ?? null) ? trim((string) $item['id']) : '';

            if ($id === '') {
                continue;
            }

            $permalink = $item['permalink'] ?? null;

            return [
                'id' => $id,
                'permalink' => is_string($permalink) && trim($permalink) !== '' ? trim($permalink) : null,
            ];
        }

        return null;
    }

    // --- First comment (reply) -----------------------------------------------------

    /**
     * A Threads first comment is a reply: up to 500 characters counted like the
     * post (emoji as UTF-8 bytes), needing threads_manage_replies. When the
     * credentials do not list their scopes the reply is skipped (as the
     * production app does), unless social.api.threads.comment_unknown_scopes is
     * 'attempt'. Replies have their own quota of 1,000 per profile per 24h.
     */
    public function commentRules(PreparedPost $post, ?PostResult $parent = null): ?CommentRules
    {
        if ($parent !== null && ! $parent->isPublished()) {
            return null;
        }

        $policy = $this->apiConfig('comment_unknown_scopes', CommentRules::SKIP);

        return new CommentRules(
            max: (int) $this->apiConfig('max_comment_characters', 500),
            counter: new ThreadsCounter,
            requiredScopes: $this->requiredScopes(Feature::Comment, $post->credentials),
            unknownScopePolicy: in_array($policy, [CommentRules::ATTEMPT, CommentRules::SKIP], true) ? $policy : CommentRules::SKIP,
        );
    }

    /**
     * Reply to the published post: create a TEXT container with reply_to_id,
     * check its status (up to 5 reads, 2s apart), then threads_publish it.
     *
     * - ERROR on the reply container: PermanentException(MediaRejected);
     * - still IN_PROGRESS after the reads: TemporaryException(30), and the retry
     *   creates a new container (an unpublished one is discarded by Threads);
     * - no status at all: published anyway, as the app does (a not-ready
     *   container answers 4279009, which is retried);
     * - a threads_publish whose outcome is unclear (a connection lost after
     *   sending, a 5xx, a transient code 1/2 error) is verified on the
     *   container before anything is retried, because a retry publishes a new
     *   container and could post the reply twice: see verifyReplyPublish().
     *   A request that never left the client and explicit refusals (4279009,
     *   throttles, permissions) are retried or failed as they are.
     * - in a recovery (the comment job passes the parent with payload
     *   `_recovering`: an earlier attempt's answer was lost, or its worker died
     *   during threads_publish), the account's own replies are read first and
     *   a reply to this post with this text is returned instead of publishing
     *   a second one: see findOwnReply().
     */
    public function addFirstComment(PreparedPost $context, PostResult $parent, string $text): CommentResult
    {
        $this->assertCredentials($context);

        $parentId = trim((string) $parent->platformPostId);
        $text = trim($text);

        if ($parentId === '') {
            throw new PermanentException('Threads needs the published post id to reply to.', Platform::Threads, FailureReason::NotFound, ['no_post_id' => true]);
        }

        if ($text === '') {
            throw new PermanentException('The Threads reply is empty.', Platform::Threads, FailureReason::InvalidContent);
        }

        if (Pending::isRecovering($parent->payload)) {
            $found = $this->findOwnReply($context, $parentId, $text);

            if ($found !== null) {
                return $found;
            }
        }

        $response = $this->graphPost($context, $this->edge($context, '/threads'), [
            'media_type' => 'TEXT',
            'text' => $text,
            'reply_to_id' => $parentId,
        ]);

        if (! $this->ok($response)) {
            throw $this->forReply($this->mapError($response));
        }

        $container = $this->requireId($response, 'reply container');

        $this->awaitReplyContainer($context, $container);

        try {
            $published = $this->graphPost($context, $this->edge($context, '/threads_publish'), ['creation_id' => $container], $this->publishTimeout());
        } catch (ConnectionException $e) {
            $error = TemporaryException::connection(Platform::Threads, $e);

            // Never sent: the container stays unpublished, so a retry is safe.
            if (TemporaryException::requestNeverSent($e)) {
                throw $error;
            }

            return $this->verifyReplyPublish($context, $container, $error);
        }

        if (! $this->ok($published)) {
            $error = $this->forReply($this->mapError($published));

            if ($error instanceof TemporaryException && $this->mayHavePublished($error)) {
                return $this->verifyReplyPublish($context, $container, $error);
            }

            throw $error;
        }

        $id = $this->requireId($published, 'reply', 'id', true);

        return new CommentResult($id, $this->fetchPermalink($context, $id), [
            'container_id' => $container,
            'response' => $published->json(),
        ]);
    }

    /**
     * The account's own reply to $parentId with $text, from its newest
     * replies (GET {account}/replies, which lists only the account's own
     * replies, with replied_to), or null when there is none. The parent must
     * match too: a reused first comment ("Link in bio") also sits under older
     * posts. A temporary failure (or a connection error) propagates, so the
     * job retries the recovery; a refusal (no threads_read_replies, a rejected
     * token) becomes OutcomeUnknown with possibly_published = true, because
     * publishing a new container without having looked could post the reply
     * twice.
     */
    protected function findOwnReply(PreparedPost $context, string $parentId, string $text): ?CommentResult
    {
        $response = $this->http($context)->get($this->edge($context, '/replies'), [
            'fields' => 'id,text,timestamp,permalink,replied_to,root_post',
            'limit' => 50,
        ]);

        if (! $this->ok($response)) {
            $error = $this->forReply($this->mapError($response));

            if ($error instanceof TemporaryException) {
                throw $error;
            }

            throw new PermanentException(
                'An earlier attempt may already have posted this Threads reply, and the account\'s replies could not be read to check ('.$error->getMessage().'). Check the post before replying by hand.',
                Platform::Threads,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'recovering' => true, 'operation' => 'comment', 'lookup_reason' => $error->reason->value],
                $error,
            );
        }

        $wanted = $this->normalizeText($text);
        $items = $response->json('data');

        foreach (is_array($items) ? $items : [] as $item) {
            if (! is_array($item) || $this->normalizeText((string) ($item['text'] ?? '')) !== $wanted) {
                continue;
            }

            $repliedTo = data_get($item, 'replied_to.id') ?? data_get($item, 'root_post.id');

            if (! is_scalar($repliedTo) || trim((string) $repliedTo) !== $parentId) {
                continue;
            }

            $id = is_scalar($item['id'] ?? null) ? trim((string) $item['id']) : '';

            if ($id === '') {
                continue;
            }

            $permalink = $item['permalink'] ?? null;

            return new CommentResult(
                $id,
                is_string($permalink) && trim($permalink) !== '' ? trim($permalink) : null,
                ['recovered' => true, 'reply_to_id' => $parentId],
            );
        }

        return null;
    }

    /**
     * Wait (briefly, inside the comment job) for the reply container.
     *
     * @throws PermanentException On ERROR (MediaRejected).
     * @throws TemporaryException When it is still IN_PROGRESS, or EXPIRED.
     */
    protected function awaitReplyContainer(PreparedPost $post, string $container): void
    {
        $polls = max(1, (int) $this->apiConfig('reply_status_polls', 5));
        $interval = max(0, (int) $this->apiConfig('reply_status_interval', 2));
        $status = null;

        for ($poll = 0; $poll < $polls; $poll++) {
            if ($poll > 0 && $interval > 0) {
                Sleep::for($interval)->seconds();
            }

            try {
                $state = $this->containerState($post, $container);
            } catch (TemporaryException) {
                $status = null;

                continue;
            }

            $status = $this->normalizeStatus($state['status']);

            if ($status === 'FINISHED' || $status === 'PUBLISHED') {
                return;
            }

            if ($status === 'ERROR') {
                $code = strtoupper(trim((string) $state['error_message']));

                throw new PermanentException(
                    'Threads rejected the reply: '.$this->describeContainerError($code),
                    Platform::Threads,
                    FailureReason::MediaRejected,
                    ['container_id' => $container, 'container_status' => 'ERROR', 'error_message' => $code !== '' ? $code : null],
                );
            }

            if ($status === 'EXPIRED') {
                break;
            }
        }

        if ($status === 'IN_PROGRESS' || $status === 'EXPIRED') {
            throw new TemporaryException(
                'The Threads reply container is not ready; retrying with a new one.',
                Platform::Threads,
                FailureReason::ServerError,
                30,
                ['container_id' => $container, 'container_status' => $status],
            );
        }
    }

    /**
     * threads_publish on the reply failed without a clear answer, so it may
     * have gone through. A retry would create and publish a NEW container (the
     * comment job starts over), so read this one first, up to
     * social.api.threads.reply_status_polls (5) times, 2s apart:
     *
     * - PUBLISHED: the reply is live; its id is unknown (null);
     * - FINISHED, EXPIRED or ERROR (and never PUBLISHED): it was not
     *   published, so $error is thrown with possibly_published = false and
     *   the job retries it as a clean attempt;
     * - never readable: PermanentException(OutcomeUnknown) with
     *   possibly_published, so the reply is reported failed instead of being
     *   posted a second time.
     *
     * @throws TemporaryException|PermanentException
     */
    protected function verifyReplyPublish(PreparedPost $post, string $container, TemporaryException $error): CommentResult
    {
        $polls = max(1, (int) $this->apiConfig('reply_status_polls', 5));
        $interval = max(0, (int) $this->apiConfig('reply_status_interval', 2));
        $known = null;
        $readError = null;

        for ($poll = 0; $poll < $polls; $poll++) {
            if ($poll > 0 && $interval > 0) {
                Sleep::for($interval)->seconds();
            }

            try {
                $status = $this->normalizeStatus($this->containerState($post, $container)['status']);
            } catch (Throwable $e) {
                $readError = $e;

                continue;
            }

            if ($status === 'PUBLISHED') {
                return new CommentResult(null, null, ['container_id' => $container, 'recovered' => true]);
            }

            if (in_array($status, ['FINISHED', 'EXPIRED', 'ERROR'], true)) {
                $known = $status;
            }
        }

        if ($known !== null) {
            // Verified unpublished: the job retries it as a clean attempt, not a recovery.
            throw $error
                ->addContext(['container_id' => $container, 'container_status' => $known])
                ->withFlags(['possibly_published' => false]);
        }

        throw new PermanentException(
            'The Threads reply may already be live: threads_publish failed without a clear answer and the reply container could not be checked, so it was not posted again. Check the post before adding the reply by hand.',
            Platform::Threads,
            FailureReason::OutcomeUnknown,
            array_filter([
                'possibly_published' => true,
                'container_id' => $container,
                'publish_error' => $error->getMessage(),
                'status_error' => $readError instanceof SocialPosterException ? $readError->getMessage() : null,
            ], static fn ($value) => $value !== null),
            $error,
        );
    }

    /**
     * Whether a failed reply threads_publish may still have published it: a
     * 5xx or a transient Meta error (is_transient, codes 1/2) with no product
     * subcode. Throttles, token rejections and subcoded refusals (4279009...)
     * were refused outright.
     */
    protected function mayHavePublished(TemporaryException $error): bool
    {
        $context = $error->context;

        if (in_array($error->reason, [FailureReason::RateLimited, FailureReason::InvalidToken], true)
            || ($context['reresolve'] ?? false) === true
            || isset($context['subcode'])) {
            return false;
        }

        $status = is_numeric($context['http_status'] ?? null) ? (int) $context['http_status'] : 0;
        $code = is_numeric($context['code'] ?? null) ? (int) $context['code'] : 0;

        return $status >= 500 || ! empty($context['is_transient']) || in_array($code, [1, 2, -1, -2], true);
    }

    /** Point a refused reply at the scope it needs. */
    protected function forReply(TemporaryException|PermanentException $exception): TemporaryException|PermanentException
    {
        if ($exception instanceof PermanentException && $exception->reason === FailureReason::InsufficientPermissions) {
            $exception->addContext(['required_scope' => 'threads_manage_replies']);
        }

        return $exception;
    }

    /**
     * Point a refused post that carried a location tag at the permission the
     * tag needs (threads_location_tagging), rather than only at the
     * publishing scopes the account may well have.
     *
     * @param  array<string, mixed>  $params  The container params as sent.
     */
    protected function forLocationTag(array $params, TemporaryException|PermanentException $exception): TemporaryException|PermanentException
    {
        $location = $params['location_id'] ?? null;

        if (! $exception instanceof PermanentException
            || $exception->reason !== FailureReason::InsufficientPermissions
            || ! is_scalar($location) || trim((string) $location) === '') {
            return $exception;
        }

        return new PermanentException(
            $exception->getMessage().' This post has a location tag, which needs the '.static::LOCATION_SCOPE.' permission.',
            Platform::Threads,
            $exception->reason,
            ['required_scope' => static::LOCATION_SCOPE] + $exception->context,
            $exception->getPrevious(),
        );
    }

    // --- Quota ---------------------------------------------------------------------

    /**
     * The account's publishing usage over the rolling 24h window, from
     * GET {account}/threads_publishing_limit. Totals and durations come from
     * Threads' config objects (250 posts / 1,000 replies per 86,400s today).
     *
     * @return array{quota_usage: ?int, quota_total: ?int, quota_duration: ?int, reply_quota_usage: ?int, reply_quota_total: ?int, reply_quota_duration: ?int, raw: array<string, mixed>}
     */
    public function publishingQuota(PreparedPost $post): array
    {
        $this->assertCredentials($post);

        $response = $this->http($post)->get($this->edge($post, '/threads_publishing_limit'), [
            'fields' => 'quota_usage,config,reply_quota_usage,reply_config',
        ]);

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        $row = $response->json('data.0');
        $row = is_array($row) ? $row : [];
        $int = static fn ($value): ?int => is_numeric($value) ? (int) $value : null;

        return [
            'quota_usage' => $int($row['quota_usage'] ?? null),
            'quota_total' => $int(data_get($row, 'config.quota_total')),
            'quota_duration' => $int(data_get($row, 'config.quota_duration')),
            'reply_quota_usage' => $int($row['reply_quota_usage'] ?? null),
            'reply_quota_total' => $int(data_get($row, 'reply_config.quota_total')),
            'reply_quota_duration' => $int(data_get($row, 'reply_config.quota_duration')),
            'raw' => $row,
        ];
    }

    /**
     * Opt-in pre-flight (social.api.threads.check_quota): refuse the post when
     * the 24h publishing quota is used up, as a PermanentException(RateLimited)
     * with retry_at / retry_after_hint, rather than creating containers that
     * cannot be published. Best-effort: a failed quota read never blocks a post.
     */
    protected function assertQuotaAvailable(PreparedPost $post): void
    {
        if (! (bool) $this->apiConfig('check_quota', false)) {
            return;
        }

        try {
            $quota = $this->publishingQuota($post);
        } catch (Throwable) {
            return;
        }

        if ($quota['quota_usage'] === null || $quota['quota_total'] === null || $quota['quota_usage'] < $quota['quota_total']) {
            return;
        }

        // A rolling window: the reset time is unknown, so the hint is a guess.
        $hint = max(60, (int) $this->apiConfig('quota_retry_after', 3600));

        throw new PermanentException(
            "Threads publishing quota reached ({$quota['quota_usage']} of {$quota['quota_total']} posts in the last 24 hours); try again later.",
            Platform::Threads,
            FailureReason::RateLimited,
            [
                'quota_usage' => $quota['quota_usage'],
                'quota_total' => $quota['quota_total'],
                'quota_duration' => $quota['quota_duration'],
                'retry_after_hint' => $hint,
                'retry_at' => Carbon::now()->addSeconds($hint)->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
        );
    }

    // --- Errors --------------------------------------------------------------------

    /**
     * Threads' own subcodes first (they ride on generic Graph codes), then the
     * shared Meta table (tokens, throttling, transient, permissions), then the
     * HTTP-status fallback.
     */
    protected function classifyError($response): TemporaryException|PermanentException
    {
        ['subcode' => $subcode, 'raw' => $raw, 'message' => $message, 'context' => $context] = $this->metaErrorParts($response);

        $platform = Platform::Threads;

        // Media still downloading, or a carousel item not ready.
        if (in_array($subcode, [2207003, 4279004], true)) {
            return new TemporaryException("Threads is still processing the media; retrying shortly. ({$message})", $platform, FailureReason::ServerError, 60, $context);
        }

        // Code 24 "Media Not Found": a container published before it is ready.
        if ($subcode === 4279009) {
            return new TemporaryException(
                "Threads cannot find the container yet (Media Not Found); it is usually not ready, retrying shortly. A creation_id or reply_to_id that does not exist fails the same way. ({$message})",
                $platform,
                FailureReason::ServerError,
                30,
                $context,
            );
        }

        if (str_contains($this->rawBody($response), 'THREADS_API__LINK_LIMIT_EXCEEDED')) {
            return new PermanentException(
                "Threads allows at most {$this->maxLinks()} links per post (every unique URL in the text, plus a different link attachment). ({$message})",
                $platform,
                FailureReason::InvalidContent,
                $context + ['link_limit_exceeded' => true],
            );
        }

        // Kept ahead of the Meta transient row for its longer wait.
        if (str_contains(strtolower($raw), 'unexpected error has occurred')) {
            return new TemporaryException('Threads API is busy; retrying shortly.', $platform, FailureReason::ServerError, 90, $context);
        }

        $meta = $this->classifyMetaError($response);

        if ($meta instanceof PermanentException && $meta->reason === FailureReason::InsufficientPermissions) {
            return new PermanentException(
                $meta->getMessage().' (Threads publishing needs threads_basic and threads_content_publish; replies need threads_manage_replies.)',
                $platform,
                $meta->reason,
                $meta->context,
            );
        }

        if ($meta !== null) {
            return $meta;
        }

        return parent::classifyError($response);
    }

    // --- Rule sets -----------------------------------------------------------------

    protected function captionRules(): CaptionRules
    {
        return new CaptionRules(max: (int) $this->apiConfig('max_characters', 500), counter: new ThreadsCounter);
    }

    protected function imageRules(): ImageRules
    {
        return new ImageRules(
            extensions: ['jpg', 'jpeg', 'png'],
            maxSizeBytes: (int) $this->apiConfig('max_image_bytes', 8388608),
            aspectRatioRange: [0.1, 10.0],
        );
    }

    /**
     * Threads' documented video limits: MP4/MOV, H.264 or HEVC, 1 GB, up to 300s,
     * at most 1920 horizontal pixels (width, not height), aspect ratio
     * 0.01:1 to 10:1, 23 to 60 fps.
     */
    protected function videoRules(): VideoRules
    {
        return new VideoRules(
            extensions: ['mp4', 'mov'],
            maxSizeBytes: (int) $this->apiConfig('max_video_bytes', 1073741824),
            minDuration: (float) $this->apiConfig('min_video_duration', 1),
            maxDuration: (float) $this->apiConfig('max_video_duration', 300),
            maxWidth: (int) $this->apiConfig('max_video_width', 1920),
            aspectRatioRange: [0.01, 10.0],
            codecs: ['h264', 'hevc'],
            minFrameRate: (float) $this->apiConfig('min_video_frame_rate', 23),
            maxFrameRate: (float) $this->apiConfig('max_video_frame_rate', 60),
        );
    }

    // --- Helpers -------------------------------------------------------------------

    /**
     * POST to Graph through http(), with access_token also in the body (the
     * documented form) and an optional longer timeout.
     *
     * @param  array<string, mixed>  $params
     * @return Response
     */
    protected function graphPost(PreparedPost $post, string $url, array $params, ?int $timeout = null)
    {
        $request = $this->http($post);

        if ($timeout !== null) {
            $request = $request->timeout($timeout);
        }

        return $request->post($url, $this->withTokenField($post, $params));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function withTokenField(PreparedPost $post, array $params): array
    {
        $token = $this->token($post);

        if (trim($token) !== '') {
            $params['access_token'] = $token;
        }

        return $params;
    }

    /**
     * A continuation. A wait after a threads_publish that may have gone through
     * is committed: a later failure is reported as possibly published.
     *
     * @param  array<string, mixed>  $state
     */
    protected function pendingFor(array $state, int $recheckAfter): Pending
    {
        $state = Pending::withoutReservedKeys($state);

        return PublishOutcome::pending($state, max(1, $recheckAfter), committed: ! empty($state['publish_sent']));
    }

    /**
     * Schedule the commit phase. A FINISHED container was not published, so the
     * driver drops its own publish_sent doubt. (The job keeps a chain it saw
     * committed as committed, so a later failure is still reported as possibly
     * published; publishNow() avoids that for requests that were never sent.)
     *
     * @param  array<string, mixed>  $state
     */
    protected function toPublish(array $state): Pending
    {
        $state = Pending::withoutReservedKeys($state);
        $state['phase'] = 'publish';
        unset($state['publish_sent']);

        return $this->commit($state);
    }

    /**
     * The restart counters a fresh attempt carries over.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, int>
     */
    protected function counters(array $state): array
    {
        return array_filter([
            'restarts' => (int) ($state['restarts'] ?? 0),
            'expirations' => (int) ($state['expirations'] ?? 0),
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function resultPayload(PreparedPost $post, array $state, array $extra = []): array
    {
        $payload = ['container_id' => $this->stateContainer($state)];

        if (($state['children'] ?? []) !== []) {
            $payload['children'] = $this->idList($state['children']);
        }

        $payload += $extra;

        foreach ($post->media() as $item) {
            if ($item->type === MediaType::Video && $item->thumbnail !== null && trim($item->thumbnail) !== '') {
                $payload['thumbnail_warning'] = 'Threads does not support video covers; the thumbnail was ignored.';
                break;
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $state */
    protected function stateContainer(array $state): string
    {
        $id = $state['container_id'] ?? $state['container'] ?? null;

        if (! is_scalar($id) || trim((string) $id) === '') {
            throw new PermanentException('The Threads continuation state has no container id.', Platform::Threads, FailureReason::Unknown, ['phase' => $state['phase'] ?? null]);
        }

        return trim((string) $id);
    }

    /** @param array<string, mixed> $state */
    protected function pollAfter(array $state): int
    {
        return ($state['kind'] ?? null) === 'video' ? $this->videoPollInterval() : $this->pollInterval();
    }

    /** @param array<string, mixed> $state */
    protected function describeKind(array $state): string
    {
        return match ($state['kind'] ?? null) {
            'video' => 'the video',
            'image' => 'the image',
            'carousel' => 'the carousel',
            'text' => 'the post',
            default => 'the media',
        };
    }

    /** @throws PermanentException For any document (MediaRejected), before a request is sent. */
    protected function assertPublishableMedia(PreparedPost $post): void
    {
        foreach ($post->media() as $item) {
            if ($item->type === MediaType::Document) {
                throw $this->documentRejected();
            }
        }
    }

    protected function documentRejected(): PermanentException
    {
        return new PermanentException('Threads does not accept documents; post images or videos.', Platform::Threads, FailureReason::MediaRejected);
    }

    /** The location tag (location_id) from ThreadsOptions, else from the raw extra, or null. */
    protected function locationTag(PreparedPost $post): ?string
    {
        return $this->postParam($post, $this->optionsOf($post, ThreadsOptions::class)?->locationId, 'location_id');
    }

    /** The post this one replies to (reply_to_id) from ThreadsOptions, else from the raw extra, or null. */
    protected function replyTarget(PreparedPost $post): ?string
    {
        return $this->postParam($post, $this->optionsOf($post, ThreadsOptions::class)?->replyToId, 'reply_to_id');
    }

    /** A post-level param: the typed value when set, else the raw extra's $key; trimmed, null when blank. */
    protected function postParam(PreparedPost $post, ?string $typed, string $key): ?string
    {
        $value = $typed !== null && trim($typed) !== '' ? $typed : ($this->extraPayload($post)[$key] ?? null);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /** The link attachment from ThreadsOptions, else from the raw extra, or null. */
    protected function linkAttachment(PreparedPost $post): ?string
    {
        $attachment = $this->optionsOf($post, ThreadsOptions::class)->linkAttachment
            ?? ($this->extraPayload($post)['link_attachment'] ?? null);

        return is_string($attachment) && trim($attachment) !== '' ? trim($attachment) : null;
    }

    /**
     * Threads' link count: every unique URL in the text, plus the link
     * attachment when it is not one of them.
     */
    protected function countLinks(string $text, ?string $attachment): int
    {
        $key = static fn (string $url) => strtolower(rtrim(Urls::absolute($url), '/'));
        $links = array_map($key, Urls::unique($text));

        if ($attachment !== null && ! in_array($key($attachment), $links, true)) {
            $links[] = $key($attachment);
        }

        return count($links);
    }

    /**
     * @param  mixed  $ids
     * @return list<string>
     */
    protected function idList($ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_map(
            static fn ($id) => trim((string) $id),
            array_filter($ids, static fn ($id) => is_scalar($id) && trim((string) $id) !== ''),
        ));
    }

    protected function normalizeStatus(?string $status): ?string
    {
        return $status === null ? null : strtoupper(trim($status));
    }

    protected function normalizeText(string $text): string
    {
        return trim(str_replace("\r\n", "\n", $text));
    }

    protected function parseTimestamp(mixed $value): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    /** The raw response body ('' when it cannot be read), for code strings anywhere in it. */
    protected function rawBody(mixed $response): string
    {
        try {
            return (string) $response->body();
        } catch (Throwable) {
            return '';
        }
    }

    protected function nowTimestamp(): int
    {
        return Carbon::now()->getTimestamp();
    }

    protected function initialCheckAfter(): int
    {
        return max(1, (int) $this->apiConfig('initial_check_after', 30));
    }

    protected function pollInterval(): int
    {
        return max(1, (int) $this->apiConfig('poll_interval', 10));
    }

    protected function videoPollInterval(): int
    {
        return max(1, (int) $this->apiConfig('video_poll_interval', 30));
    }

    protected function missingStatusPolls(): int
    {
        return max(1, (int) $this->apiConfig('missing_status_polls', 12));
    }

    /** Lookups of a recovered (PUBLISHED) post before it is reported with a null id. */
    protected function recoveryLookups(): int
    {
        return max(1, (int) $this->apiConfig('recovery_lookups', 3));
    }

    protected function maxLinks(): int
    {
        return (int) $this->apiConfig('max_links', 5);
    }

    protected function maxCarouselItems(): int
    {
        return (int) $this->apiConfig('max_carousel_items', 20);
    }
}
