<?php

namespace SocialPoster\Platforms\X;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\MessageBag;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\CommentRules;
use SocialPoster\Capabilities\ImageRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Capabilities\VideoRules;
use SocialPoster\Concerns\UploadsInChunks;
use SocialPoster\Contracts\IdempotencyStore;
use SocialPoster\Contracts\SupportsFirstComment;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\Ingestion;
use SocialPoster\Enums\MediaType;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Idempotency\NullIdempotencyStore;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\Support\RetryAfter;
use SocialPoster\Support\Text\Text;
use SocialPoster\Support\Text\Urls;
use SocialPoster\Support\Text\XWeightedCounter;
use SocialPoster\ValueObjects\CommentResult;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\Published;
use SocialPoster\ValueObjects\PublishOutcome;

/**
 * X (Twitter) on the v2 API. Media is uploaded as bytes (Ingestion::Upload):
 * static images use the one-shot POST /2/media/upload, while GIFs and video use
 * the chunked INITIALIZE / APPEND / FINALIZE flow and then process
 * asynchronously (STATUS polls). A post takes up to 4 images, or one GIF, or
 * one video, or a poll.
 *
 * The publish runs as a small state machine across job runs:
 *
 *   publish()      images: upload (+ alt text), then commit 'tweet'
 *                  GIF/video: INITIALIZE, then APPEND while the run budget lasts
 *   'append'       more APPEND segments (segment_index/offset kept in state),
 *                  Pending(…, 0) between slices, then FINALIZE
 *   'await_media'  STATUS polls until processing succeeds (v1.0 phase name)
 *   'tweet'        POST /2/tweets: the commit phase (the only non-idempotent call)
 *
 * Recovery of the commit phase (`_recovering`): X bills every API read and the
 * lookup needs extra scopes, so by default the post is simply sent again and a
 * 403 "duplicate content" answer is taken as proof that the earlier, ambiguous
 * attempt published it (Published with a null id, payload recovered = true,
 * possibly_duplicate = true). Set api.x.verify_reads to true to look the post
 * up first (GET /2/users/{user_id}/tweets, matched by media ids or text).
 * X detects duplicates by their text only, so a post without text (media-only,
 * api.x.require_text = false) is sent again only after a lookup that ran and
 * did not find it; otherwise it ends as OutcomeUnknown, possibly published.
 *
 * Credentials: an OAuth 2.0 user-context access token (scopes tweet.read,
 * tweet.write, users.read, plus media.write for any image, GIF or video and
 * offline.access to refresh it). X user tokens live 2 hours: pass expires_at
 * so an expired token fails fast, and bind a CredentialsResolver to refresh it
 * between job runs. Pass user_id (the account's numeric id) too: it is the
 * account's identity in idempotency keys. Without it (or an 'account'
 * credential, or a Credentials::ref() reference) the identity is a hash of the
 * token, which X replaces every 2 hours, so a later dispatch with the same
 * ->idempotencyKey() gets a different key and neither replays nor refuses a
 * repeat, and outcome() / reconcile() with refreshed credentials cannot find
 * the earlier publish. user_id also saves a /2/users/me read when verifying.
 * OAuth 1.0a and app-only tokens are not supported. On pay-per-use X API plans
 * every post and reply is billed; a 402 maps to FailureReason::QuotaExceeded.
 */
class XDriver extends AbstractPlatform implements SupportsFirstComment
{
    use UploadsInChunks;

    /** Publish phases (Pending::$state['phase']). 'await_media' is the v1.0 name, kept. */
    protected const PHASE_APPEND = 'append';

    protected const PHASE_AWAIT_MEDIA = 'await_media';

    protected const PHASE_TWEET = 'tweet';

    /** APPEND accepts segment_index 0..9999. */
    protected const MAX_SEGMENTS = 10000;

    /**
     * The largest segment segmentSize() grows to so a file fits in
     * MAX_SEGMENTS: X recommends at most 5 MB per segment (its maximum is 8 MB).
     */
    protected const MAX_SEGMENT_BYTES = 5242880;

    /** Static image formats X takes in a multi-image post. */
    protected const PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** Bare HTTP status names X puts in title/detail; they tell the user nothing. */
    private const BARE_MESSAGES = [
        'bad request', 'unauthorized', 'payment required', 'forbidden', 'not found',
        'too many requests', 'internal server error', 'bad gateway', 'service unavailable',
        'gateway timeout', 'invalid request', 'unprocessable entity', 'conflict',
    ];

    /** Default API host; api.x.base_url overrides it. */
    protected string $api = 'https://api.x.com/2';

    /** Default APPEND segment size; api.x.chunk_size overrides it. */
    protected int $chunkSize = 4194304; // 4 MB

    /** Whether this instance already logged that the account is identified by its token. */
    private bool $tokenIdentityNoticed = false;

    public function platform(): Platform
    {
        return Platform::X;
    }

    protected function ingestion(): Ingestion
    {
        return Ingestion::Upload;
    }

    /**
     * Everything X can take, for callers checking support up front: photos and
     * GIFs (GIFs alone, with their own 15MB limit), or one video. rulesFor()
     * applies the exact limits of a given post.
     */
    public function capabilities(): Capabilities
    {
        $photo = $this->photoRules();
        $gif = $this->gifRules();

        return new Capabilities(
            caption: $this->captionRules(),
            media: new MediaRules(
                minCount: 0,
                maxCount: 4,
                image: new ImageRules(
                    extensions: array_values(array_unique([...($photo->extensions ?? []), ...($gif->extensions ?? [])])),
                    maxSizeBytes: max((int) $photo->maxSizeBytes, (int) $gif->maxSizeBytes),
                ),
                video: $this->videoRules(),
            ),
        );
    }

    protected function rulesFor(PreparedPost $post): Capabilities
    {
        $caption = $this->captionRules();
        $media = $post->media();

        // 2 to 4 attachments must all be static photos. A GIF or a video in the
        // set is reported by validatePlatformRules() with its own message rather
        // than as an "unsupported file type".
        if (count($media) > 1) {
            $photo = $this->photoRules();

            return new Capabilities(
                caption: $caption,
                media: new MediaRules(
                    minCount: 2,
                    maxCount: 4,
                    image: new ImageRules(
                        extensions: $photo->extensions === null ? null : array_values(array_unique([...$photo->extensions, 'gif'])),
                        types: $photo->types,
                        maxSizeBytes: $photo->maxSizeBytes,
                        aspectRatioRange: $photo->aspectRatioRange,
                        maxPixels: $photo->maxPixels,
                        maxWidth: $photo->maxWidth,
                        maxHeight: $photo->maxHeight,
                        minSizeBytes: $photo->minSizeBytes,
                    ),
                    video: $this->videoRules(),
                ),
            );
        }

        // Single attachment: a photo, a GIF (its own size limit), or a video.
        $first = $media[0] ?? null;

        return new Capabilities(
            caption: $caption,
            media: new MediaRules(
                minCount: 0,
                maxCount: 1,
                image: $this->isGif($first) ? $this->gifRules() : $this->photoRules(),
                video: $this->videoRules(),
            ),
        );
    }

    protected function validatePlatformRules(PreparedPost $post, MessageBag $errors): void
    {
        $media = $post->media();
        $options = $this->optionsOf($post, XOptions::class);

        // X takes media-only posts; requiring text is the default because the
        // original product did (api.x.require_text = false relaxes it).
        if (trim((string) $post->caption()) === '' && ($media === [] || $this->requiresText())) {
            $errors->add('caption', "X posts can't have an empty caption.");
        }

        if (count($media) > 1) {
            foreach ($media as $index => $item) {
                if ($this->isGif($item)) {
                    $errors->add("media.{$index}", "X takes one animated GIF on its own; it can't be combined with other images.");
                } elseif ($item->type === MediaType::Video) {
                    $errors->add("media.{$index}", "X takes one video on its own; it can't be combined with other media.");
                }
            }
        }

        $maxAlt = $this->maxAltTextLength();

        foreach ($media as $index => $item) {
            $alt = trim((string) $item->altText);

            if ($item->type === MediaType::Image && mb_strlen($alt) > $maxAlt) {
                $errors->add("media.{$index}", "Alt text exceeds X's {$maxAlt} character limit (currently ".mb_strlen($alt).').');
            }
        }

        if ($options?->poll !== null && $media !== []) {
            $errors->add('options.poll', "X polls can't be combined with media.");
        }

        // X takes quote_tweet_id on Enterprise plans only; an app on a
        // self-serve plan sets allow_quote_posts = false to refuse it here
        // instead of after the media upload.
        if (! (bool) $this->apiConfig('allow_quote_posts', true) && $this->quotesPost($post)) {
            $errors->add('options.quote_tweet_id', 'Quote posts (quote_tweet_id) need an X Enterprise API plan, and api.x.allow_quote_posts is false.');
        }

        $comment = trim((string) $post->comment());

        if ($comment !== '') {
            foreach ($this->commentRules($post)?->errors($comment) ?? [] as $message) {
                $errors->add('comment', $message);
            }
        }
    }

    /**
     * The Feature::Publish scopes this post needs: all of them for a post with
     * media, and without the media upload scopes (Feature::Video's, i.e.
     * media.write) for a text-only post. Each missing group is reported once.
     */
    protected function validateScopes(PreparedPost $post, MessageBag $errors): void
    {
        $credentials = $post->credentials;

        if ($credentials->scopes() === null) {
            return;
        }

        $upload = $this->scopesFor(Feature::Video, $credentials);
        $hasMedia = $post->media() !== [];

        foreach ($credentials->missingScopes($this->scopesFor(Feature::Publish, $credentials)) ?? [] as $group) {
            $forUpload = in_array($group, $upload, true);

            if ($forUpload && ! $hasMedia) {
                continue;
            }

            $errors->add(
                'credentials.scopes',
                'The X token is missing a permission needed to '.($forUpload ? 'upload media' : 'publish').': grant '.implode(' or ', $group).'.',
            );
        }
    }

    // --- Publish -----------------------------------------------------------------

    public function publish(PreparedPost $post): PublishOutcome
    {
        $this->assertCredentials($post);
        $this->assertTokenFresh($post, $this->tokenMargin());
        $this->noticeTokenIdentity($post);

        $media = $post->media();

        if ($media === []) {
            return $this->commit($this->tweetState([]));
        }

        $first = $media[0];

        if (count($media) === 1 && ($first->type === MediaType::Video || $this->isGif($first))) {
            return $this->startChunked($post, $first);
        }

        // One or more static images: upload each in one shot, then post.
        $ids = [];

        try {
            foreach ($media as $image) {
                $id = $this->uploadImage($post, $image);
                $this->applyAltText($post, $id, $image->altText);
                $ids[] = $id;
            }
        } finally {
            $this->gateway()?->cleanup();
        }

        return $this->commit($this->tweetState($ids));
    }

    /**
     * Continue a publish. Accepts the v1.0 state (phase 'await_media' with a
     * media_id), so continuations queued before the upgrade still finish.
     */
    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        $this->assertCredentials($post);
        $this->assertTokenFresh($post, $this->tokenMargin());

        $phase = $state['phase'] ?? null;

        return match ($phase) {
            self::PHASE_APPEND => $this->resumeUpload($post, $state),
            self::PHASE_AWAIT_MEDIA => $this->awaitMedia($post, $state),
            self::PHASE_TWEET => $this->tweetPhase($post, $state),
            default => throw new PermanentException(
                'Unknown X publish phase.',
                Platform::X,
                FailureReason::Unknown,
                ['phase' => is_scalar($phase) ? $phase : null],
            ),
        };
    }

    // --- Image (one-shot) ----------------------------------------------------------

    protected function uploadImage(PreparedPost $post, Media $image): string
    {
        // Resolve the path once: a gateway downloads remote media on every call.
        $path = $this->localPath($image);
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw new PermanentException(
                'The media file could not be read.',
                Platform::X,
                FailureReason::MediaRejected,
                ['path' => basename($path)],
            );
        }

        $response = $this->uploadClient($post)
            ->attach('media', $bytes, 'image.'.($image->extension() ?: 'jpg'))
            ->post($this->baseUrl().'/media/upload', [
                'media_category' => 'tweet_image',
                'media_type' => $this->mimeFor($image, $path),
            ]);

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        return $this->requireId($response, 'image upload', 'data.id');
    }

    /**
     * Attach alt text (POST /2/media/metadata, images and GIFs). Never fatal: a
     * refusal or a network error is logged and the post goes out without it.
     */
    protected function applyAltText(PreparedPost $post, string $mediaId, ?string $altText): void
    {
        $text = trim((string) $altText);

        if ($text === '') {
            return;
        }

        try {
            $response = $this->client($post)->post($this->baseUrl().'/media/metadata', [
                'id' => $mediaId,
                'metadata' => ['alt_text' => ['text' => mb_substr($text, 0, $this->maxAltTextLength())]],
            ]);

            if (! $this->ok($response)) {
                $this->mapError($response); // classified and logged, not thrown
            }
        } catch (ConnectionException $e) {
            $this->notice('could not set the alt text (network error); posting without it.', [
                'media_id' => $mediaId,
                'exception' => $e::class,
            ]);
        }
    }

    // --- GIF / video (chunked, staged across runs) --------------------------------

    /** INITIALIZE, then APPEND while this run's budget lasts (see UploadsInChunks). */
    protected function startChunked(PreparedPost $post, Media $item): PublishOutcome
    {
        $startedAt = microtime(true);

        try {
            $path = $this->localPath($item);
            $size = $this->uploadSize($path);
            $chunkSize = $this->segmentSize($size); // refuses an oversized file before INITIALIZE

            $init = $this->client($post)->post($this->baseUrl().'/media/upload/initialize', [
                'media_category' => $this->category($item),
                'media_type' => $this->mimeFor($item, $path),
                'total_bytes' => $size,
            ]);

            if (! $this->ok($init)) {
                throw $this->mapError($init);
            }

            $upload = [
                'phase' => self::PHASE_APPEND,
                'media_id' => $this->requireId($init, 'media upload', 'data.id'),
                'size' => $size,
                'chunk_size' => $chunkSize,
                'segment_index' => 0,
                'offset' => 0,
            ];

            $alt = $this->chunkedAltText($item);

            if ($alt !== null) {
                $upload['alt_text'] = $alt;
            }

            return $this->continueUpload($post, $upload, $path, $startedAt);
        } finally {
            $this->gateway()?->cleanup();
        }
    }

    /**
     * The 'append' phase: the next run of a staged upload. The file is resolved
     * again (a remote source is downloaded again on this worker) and must still
     * have the size announced to INITIALIZE.
     *
     * @param  array<string, mixed>  $state
     */
    protected function resumeUpload(PreparedPost $post, array $state): PublishOutcome
    {
        $startedAt = microtime(true);
        $item = $post->media()[0] ?? null;

        if ($item === null) {
            throw new PermanentException('The X upload has no media to continue with.', Platform::X, FailureReason::Unknown);
        }

        $upload = Pending::withoutReservedKeys($state);

        foreach (['size', 'chunk_size', 'segment_index', 'offset'] as $key) {
            $upload[$key] = (int) ($upload[$key] ?? 0);
        }

        $upload['media_id'] = $this->stateString($state, 'media_id');

        try {
            $path = $this->localPath($item);
            $size = $this->uploadSize($path);

            if ($size !== $upload['size']) {
                throw $this->changedDuringUpload($state, $size, $upload['size']);
            }

            if ($upload['chunk_size'] <= 0) {
                $upload['chunk_size'] = $this->segmentSize($size);
            }

            return $this->continueUpload($post, $upload, $path, $startedAt);
        } finally {
            $this->gateway()?->cleanup();
        }
    }

    /**
     * Send segments, then hand over to the next run (Pending(…, 0)) or FINALIZE.
     *
     * @param  array<string, mixed>  $upload
     */
    protected function continueUpload(PreparedPost $post, array $upload, string $path, float $startedAt): PublishOutcome
    {
        $upload = $this->appendSegments($post, $upload, $path, $startedAt);

        if ($upload['offset'] < $upload['size']) {
            return $this->pending($upload, 0);
        }

        return $this->finalizeUpload($post, $upload);
    }

    /**
     * APPEND full segments from $upload['offset'] while budgetLeft() (always at
     * least one, so every run makes progress). Only one segment is in memory.
     *
     * @param  array<string, mixed>  $upload  media_id, size, chunk_size, segment_index, offset
     * @return array<string, mixed> $upload advanced past the segments sent
     */
    protected function appendSegments(PreparedPost $post, array $upload, string $path, float $startedAt): array
    {
        $mediaId = (string) $upload['media_id'];
        $size = (int) $upload['size'];
        $chunkSize = max(1, (int) $upload['chunk_size']);
        $offset = (int) $upload['offset'];
        $segment = (int) $upload['segment_index'];
        $sent = 0;

        while ($offset < $size && ($sent === 0 || $this->budgetLeft($startedAt))) {
            $chunk = $this->readChunk($path, $offset, min($chunkSize, $size - $offset));

            if ($chunk === '') {
                throw new PermanentException(
                    'The media file ended before the size announced to X; it changed during the upload.',
                    Platform::X,
                    FailureReason::MediaRejected,
                    ['offset' => $offset, 'size' => $size],
                );
            }

            $response = $this->uploadClient($post)
                ->attach('media', $chunk, 'chunk-'.$segment)
                ->post($this->baseUrl().'/media/upload/'.$mediaId.'/append', [
                    'segment_index' => $segment,
                ]);

            if (! $this->ok($response)) {
                throw $this->mapError($response);
            }

            $offset += strlen($chunk);
            $segment++;
            $sent++;
        }

        return array_merge($upload, ['offset' => $offset, 'segment_index' => $segment]);
    }

    /**
     * Upload the whole file in one run.
     *
     * @deprecated since 1.1: publish() stages the upload across runs (appendSegments()).
     */
    protected function appendChunks(PreparedPost $post, string $mediaId, string $path): void
    {
        $size = $this->uploadSize($path);
        $upload = ['media_id' => $mediaId, 'size' => $size, 'chunk_size' => $this->segmentSize($size), 'segment_index' => 0, 'offset' => 0];

        while ($upload['offset'] < $size) {
            $upload = $this->appendSegments($post, $upload, $path, microtime(true));
        }
    }

    /** @param  array<string, mixed>  $upload */
    protected function finalizeUpload(PreparedPost $post, array $upload): PublishOutcome
    {
        $mediaId = (string) $upload['media_id'];
        $response = $this->client($post)->post($this->baseUrl().'/media/upload/'.$mediaId.'/finalize');

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        $info = $response->json('data.processing_info');
        $alt = $upload['alt_text'] ?? null;

        return $this->afterProcessing($post, $mediaId, is_array($info) ? $info : null, is_string($alt) ? $alt : null);
    }

    /**
     * The 'await_media' phase (v1.0 state shape: phase + media_id).
     *
     * @param  array<string, mixed>  $state
     */
    protected function awaitMedia(PreparedPost $post, array $state): PublishOutcome
    {
        $mediaId = $this->stateString($state, 'media_id');
        $alt = $state['alt_text'] ?? null;

        if (! is_string($alt)) {
            // v1.0 states carry no alt text: take it from the post's GIF.
            $first = $post->media()[0] ?? null;
            $alt = $first !== null ? $this->chunkedAltText($first) : null;
        }

        return $this->afterProcessing($post, $mediaId, $this->processingInfo($post, $mediaId), $alt);
    }

    /**
     * Route on processing_info:
     * - none, or 'succeeded': the media is ready → alt text, then commit the post;
     * - 'failed': MediaRejected with X's reason;
     * - 'pending' / 'in_progress': poll again after check_after_secs (1..max_check_after);
     * - anything else: logged, polled again.
     *
     * @param  array<string, mixed>|null  $info
     */
    protected function afterProcessing(PreparedPost $post, string $mediaId, ?array $info, ?string $altText): PublishOutcome
    {
        $state = $info['state'] ?? null;

        if ($state === null || $state === 'succeeded') {
            return $this->mediaReady($post, $mediaId, $altText);
        }

        if ($state === 'failed') {
            throw $this->processingFailed($info ?? []);
        }

        if (! in_array($state, ['pending', 'in_progress'], true)) {
            $this->notice('unknown media processing state; polling again.', ['media_id' => $mediaId, 'processing_info' => $info]);
        }

        $await = ['phase' => self::PHASE_AWAIT_MEDIA, 'media_id' => $mediaId];

        if ($altText !== null && trim($altText) !== '') {
            $await['alt_text'] = $altText;
        }

        return $this->pending($await, $this->checkAfter($info));
    }

    /** The media is processed: set the GIF's alt text, then commit the post. */
    protected function mediaReady(PreparedPost $post, string $mediaId, ?string $altText): Pending
    {
        $this->applyAltText($post, $mediaId, $altText);

        return $this->commit($this->tweetState([$mediaId]));
    }

    /**
     * The STATUS processing_info, or null when X returns none (treated as ready,
     * like FINALIZE without one; the post itself would surface "not ready").
     *
     * @return array<string, mixed>|null
     */
    protected function processingInfo(PreparedPost $post, string $mediaId): ?array
    {
        $response = $this->client($post)->get($this->baseUrl().'/media/upload', [
            'command' => 'STATUS',
            'media_id' => $mediaId,
        ]);

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        $info = $response->json('data.processing_info');

        return is_array($info) ? $info : null;
    }

    /**
     * @deprecated since 1.1, use processingInfo(). Kept for subclasses.
     *
     * @return array{0: string, 1: int} [state ('succeeded' when X sends no processing_info), check_after_secs]
     */
    protected function mediaStatus(PreparedPost $post, string $mediaId): array
    {
        $info = $this->processingInfo($post, $mediaId);
        $state = $info['state'] ?? null;

        return [is_string($state) ? $state : 'succeeded', $this->checkAfter($info)];
    }

    /** @param  array<string, mixed>|null  $info */
    protected function checkAfter(?array $info): int
    {
        $max = max(1, (int) $this->apiConfig('max_check_after', 30));
        $requested = $info['check_after_secs'] ?? null;

        return max(1, min($max, is_numeric($requested) ? (int) $requested : 5));
    }

    /** @param  array<string, mixed>  $info */
    protected function processingFailed(array $info): PermanentException
    {
        $reason = data_get($info, 'error.message') ?? data_get($info, 'error.name');
        $reason = is_string($reason) && trim($reason) !== '' ? ' ('.trim($reason).')' : '';

        return new PermanentException(
            "X couldn't process the media{$reason}. Check its format, length and size.",
            Platform::X,
            FailureReason::MediaRejected,
            ['processing_info' => $info],
        );
    }

    /**
     * The file no longer has the size announced to INITIALIZE (the source was
     * replaced between runs): start the upload over, at most twice.
     *
     * @param  array<string, mixed>  $state
     */
    protected function changedDuringUpload(array $state, int $size, int $expected): TemporaryException|PermanentException
    {
        $context = ['size' => $size, 'expected_size' => $expected];

        if ((int) ($state[Pending::RESTARTS] ?? 0) >= 2) {
            return new PermanentException(
                'The media file keeps changing while it is uploaded to X.',
                Platform::X,
                FailureReason::MediaRejected,
                $context,
            );
        }

        return new TemporaryException(
            'The media file changed while it was being uploaded to X; starting the upload again.',
            Platform::X,
            FailureReason::MediaRejected,
            0,
            $context + ['restart' => true],
        );
    }

    /**
     * Segment size for a file: the configured size (capped at
     * MAX_SEGMENT_BYTES, so a chunk_size above X's 8 MB segment maximum cannot
     * make every APPEND fail), grown so the file fits in MAX_SEGMENTS
     * segments, but never past MAX_SEGMENT_BYTES. A file that would need
     * larger segments is refused before INITIALIZE; it is far beyond any size
     * X accepts for a post.
     */
    protected function segmentSize(int $size): int
    {
        $configured = min(static::MAX_SEGMENT_BYTES, max(1, (int) $this->apiConfig('chunk_size', $this->chunkSize)));
        $needed = (int) ceil($size / static::MAX_SEGMENTS);

        if ($needed > static::MAX_SEGMENT_BYTES) {
            throw new PermanentException(
                'The media file is too large to upload to X.',
                Platform::X,
                FailureReason::MediaRejected,
                ['size' => $size, 'max_segments' => static::MAX_SEGMENTS, 'max_segment_bytes' => static::MAX_SEGMENT_BYTES],
            );
        }

        return max($configured, $needed);
    }

    // --- Tweet (the commit phase) --------------------------------------------------

    /**
     * POST /2/tweets. When the job flags the run as `_recovering` (an earlier
     * attempt may have published), look the post up first if verify_reads is
     * on, then send it again; a 403 duplicate then means it is already live.
     *
     * X recognises a duplicate by its text only, so a post without text (a
     * media-only post) is sent again only after a lookup that ran and did not
     * find it. Without verify_reads, or when the lookup cannot run (no user id,
     * a refused or failed read, more posts than one page), it is never re-sent:
     * a transient lookup failure is retried (the job keeps the run recovering),
     * anything else ends as OutcomeUnknown; both carry possibly_published.
     *
     * @param  array<string, mixed>  $state
     */
    protected function tweetPhase(PreparedPost $post, array $state): PublishOutcome
    {
        $mediaIds = $this->stateMediaIds($state);

        if (! Pending::isRecovering($state)) {
            return $this->createTweet($post, $mediaIds);
        }

        $hasText = $this->hasText($this->tweetPayload($post, $mediaIds));

        if ((bool) $this->apiConfig('verify_reads', false)) {
            try {
                $found = $this->findPublishedTweet($post, $mediaIds, $state);
            } catch (TemporaryException|PermanentException $e) {
                if (! $hasText) {
                    throw $this->lookupFailed($e, $mediaIds);
                }

                $found = null; // could not look: the 403 duplicate check covers a post with text
            }

            if ($found !== null) {
                return $found;
            }
        } elseif (! $hasText) {
            throw new PermanentException(
                'An earlier attempt to publish this X post may have succeeded, and a post without text cannot be sent again safely (X detects duplicates by their text). Check the account, or set api.x.verify_reads to look the post up automatically.',
                Platform::X,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'media_ids' => $mediaIds],
            );
        }

        return $this->publishTweet($post, $mediaIds, true);
    }

    /**
     * The lookup for a recovering post without text could not rule out that
     * the earlier attempt published it. A transient failure (5xx, a short rate
     * limit, a reresolve request) is kept, flagged possibly_published, so the
     * job retries the lookup; anything else becomes OutcomeUnknown.
     *
     * @param  string[]  $mediaIds
     */
    protected function lookupFailed(TemporaryException|PermanentException $e, array $mediaIds): TemporaryException|PermanentException
    {
        $flags = ['possibly_published' => true, 'media_ids' => $mediaIds, 'lookup_failed' => true];

        if ($e instanceof TemporaryException) {
            return $e->withFlags($flags);
        }

        return new PermanentException(
            'An earlier attempt to publish this X post may have succeeded, and X could not be checked for it ('.rtrim($e->getMessage(), '.').'). A post without text cannot be sent again safely (X detects duplicates by their text); check the account.',
            Platform::X,
            FailureReason::OutcomeUnknown,
            $flags + ['lookup_reason' => $e->reason->value, 'lookup_status' => $e->context['status'] ?? null],
            $e,
        );
    }

    /** @param  string[]  $mediaIds */
    protected function createTweet(PreparedPost $post, array $mediaIds): PublishOutcome
    {
        return $this->publishTweet($post, $mediaIds, false);
    }

    /**
     * Send the post. A 2xx without an id is ambiguous (the post may be live), so
     * it is a PermanentException(OutcomeUnknown), never retried blindly.
     *
     * @param  string[]  $mediaIds
     */
    protected function publishTweet(PreparedPost $post, array $mediaIds, bool $recovering): Published
    {
        $response = $this->sendTweet($post, $this->tweetPayload($post, $mediaIds));

        if (! $this->ok($response)) {
            if ($recovering) {
                $refusal = $this->classifyError($response);

                if ($refusal->reason === FailureReason::DuplicateContent) {
                    return $this->recoveredDuplicate($mediaIds, $refusal);
                }
            }

            throw $this->mapError($response);
        }

        $id = $this->requireId($response, 'post', 'data.id', true);
        $data = $response->json('data');

        return $this->published(PostResult::success(
            Platform::X,
            $id,
            $this->permalink($id),
            array_filter(['data' => is_array($data) ? $data : null, 'media_ids' => $mediaIds], static fn ($value) => $value !== null && $value !== []),
            PostResult::STATE_PUBLISHED,
        ));
    }

    /**
     * The POST /2/tweets body for this post: text, media, the XOptions fields,
     * then the extra passthrough (its 'media', 'reply' and 'poll' objects are
     * deep-merged; the driver's keys win).
     *
     * @param  string[]  $mediaIds
     * @return array<string, mixed>
     */
    protected function tweetPayload(PreparedPost $post, array $mediaIds): array
    {
        $extra = $this->extraPayload($post);
        $options = $this->optionsOf($post, XOptions::class);
        $caption = (string) $post->caption();
        $payload = [];

        if (trim($caption) !== '' || $mediaIds === [] || $this->requiresText()) {
            $payload['text'] = $caption;
        }

        if ($mediaIds !== []) {
            $payload['media'] = ['media_ids' => array_values($mediaIds)];
        }

        foreach ($options?->toPayload() ?? [] as $key => $value) {
            $payload[$key] = $value;
        }

        foreach (['media', 'reply', 'poll'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key]) && is_array($extra[$key] ?? null)) {
                $payload[$key] = array_merge($extra[$key], $payload[$key]);
            }
        }

        return $this->mergeExtra($post, $payload);
    }

    /** @param  array<string, mixed>  $payload */
    protected function hasText(array $payload): bool
    {
        $text = $payload['text'] ?? null;

        return is_string($text) && trim($text) !== '';
    }

    /**
     * POST /2/tweets with exactly $payload: no extra passthrough, so a reply
     * (first comment) never inherits the post's poll or reply settings.
     *
     * @param  array<string, mixed>  $payload
     * @return Response
     */
    protected function sendTweet(PreparedPost $post, array $payload)
    {
        return $this->client($post)->post($this->baseUrl().'/tweets', $payload);
    }

    /**
     * A recovered create answered "duplicate content": the earlier, ambiguous
     * attempt published the post. Its id is unknown without a (billed) read.
     *
     * @param  string[]  $mediaIds
     */
    protected function recoveredDuplicate(array $mediaIds, TemporaryException|PermanentException $refusal): Published
    {
        $this->notice('a re-sent post was refused as duplicate content after an ambiguous attempt; the earlier attempt published it.', [
            'media_ids' => $mediaIds,
        ]);

        return $this->published(PostResult::success(
            Platform::X,
            null,
            null,
            [
                'recovered' => true,
                'possibly_duplicate' => true,
                'possibly_published' => true,
                'verified' => false,
                'duplicate_message' => $refusal->getMessage(),
                'media_ids' => $mediaIds,
            ],
            PostResult::STATE_PUBLISHED,
        ));
    }

    /**
     * Look for the post an ambiguous attempt may have created
     * (GET /2/users/{id}/tweets since the commit started). Null only when the
     * lookup ran and the post is not there. When it cannot tell, it throws: the
     * mapped error of a refused or failed read (logged), or OutcomeUnknown when
     * more posts than one page were published since (the post may be on a
     * later page). A ConnectionException bubbles up to the job.
     *
     * @param  string[]  $mediaIds
     * @param  array<string, mixed>  $state
     *
     * @throws TemporaryException|PermanentException
     */
    protected function findPublishedTweet(PreparedPost $post, array $mediaIds, array $state): ?Published
    {
        $userId = $this->userId($post);
        $since = $state[Pending::COMMITTING_SINCE] ?? $state[Pending::STARTED_AT] ?? null;
        $since = min(time(), is_numeric($since) ? (int) $since : time() - 3600) - 120;

        $response = $this->client($post)->get($this->baseUrl().'/users/'.rawurlencode($userId).'/tweets', [
            'start_time' => gmdate('Y-m-d\TH:i:s\Z', $since),
            'max_results' => 20,
            'exclude' => 'retweets',
            'tweet.fields' => 'created_at,text,attachments,note_tweet',
        ]);

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        $text = $this->tweetPayload($post, $mediaIds)['text'] ?? '';

        foreach ((array) $response->json('data') as $tweet) {
            if (! is_array($tweet) || ! $this->matchesTweet($tweet, is_string($text) ? $text : '', $mediaIds)) {
                continue;
            }

            $id = is_scalar($tweet['id'] ?? null) ? trim((string) $tweet['id']) : '';

            if ($id === '') {
                continue;
            }

            return $this->published(PostResult::success(
                Platform::X,
                $id,
                $this->permalink($id),
                ['data' => $tweet, 'media_ids' => $mediaIds, 'recovered' => true, 'verified' => true],
                PostResult::STATE_PUBLISHED,
            ));
        }

        $next = $response->json('meta.next_token');

        if (is_string($next) && $next !== '') {
            throw new PermanentException(
                'X lists more posts since this publish started than one page holds, so the post could not be ruled out.',
                Platform::X,
                FailureReason::OutcomeUnknown,
                ['lookup_incomplete' => true],
            );
        }

        return null;
    }

    /**
     * Whether a listed post is ours: every uploaded media id appears in its
     * media_keys ('{type}_{media_id}', unique to this upload), or, for a post
     * without media, the text matches once links (t.co-wrapped by X) and HTML
     * entities are normalised.
     *
     * @param  array<string, mixed>  $tweet
     * @param  string[]  $mediaIds
     */
    protected function matchesTweet(array $tweet, string $text, array $mediaIds): bool
    {
        if ($mediaIds !== []) {
            $keys = array_filter((array) data_get($tweet, 'attachments.media_keys', []), 'is_string');

            foreach ($mediaIds as $mediaId) {
                $match = array_filter($keys, static fn (string $key) => $key === (string) $mediaId || str_ends_with($key, '_'.$mediaId));

                if ($match === []) {
                    return false;
                }
            }

            return true;
        }

        $wanted = $this->comparableText($text);
        $note = data_get($tweet, 'note_tweet.text');
        $actual = is_string($note) && $note !== '' ? $note : (string) ($tweet['text'] ?? '');

        return $wanted !== '' && $wanted === $this->comparableText($actual);
    }

    /** Text with links removed, entities decoded, whitespace collapsed (for matching). */
    protected function comparableText(string $text): string
    {
        $text = Text::nfc(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = '';
        $cursor = 0;

        foreach (Urls::entities($text) as $entity) {
            $plain .= substr($text, $cursor, $entity['start'] - $cursor).' ';
            $cursor = $entity['end'];
        }

        $plain .= substr($text, $cursor);

        return trim((string) preg_replace('/\s+/u', ' ', $plain));
    }

    /**
     * The account's numeric id: the user_id credential, else one GET
     * /2/users/me. Throws the mapped error when that read is refused, and a
     * TemporaryException when it answers without an id.
     *
     * @throws TemporaryException|PermanentException
     */
    protected function userId(PreparedPost $post): string
    {
        $configured = $post->credentials->get('user_id');

        if (is_scalar($configured) && trim((string) $configured) !== '') {
            return trim((string) $configured);
        }

        $response = $this->client($post)->get($this->baseUrl().'/users/me');

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        return $this->requireId($response, 'account', 'data.id');
    }

    // --- First comment (a self-reply) ---------------------------------------------

    /**
     * A reply to the post: 280 weighted characters (as the caption), scopes
     * tweet.read + tweet.write + users.read, attempted when the granted scopes
     * are unknown. No comment on a scheduled or draft parent. On pay-per-use X
     * plans the reply is billed like a post.
     */
    public function commentRules(PreparedPost $post, ?PostResult $parent = null): ?CommentRules
    {
        if ($parent !== null && (! $parent->success || $parent->isScheduled() || $parent->isDraft())) {
            return null;
        }

        return new CommentRules(
            max: (int) $this->apiConfig('max_comment_characters', $this->apiConfig('max_characters', 280)),
            counter: new XWeightedCounter,
            requiredScopes: $this->requiredScopes(Feature::Comment, $post->credentials),
            unknownScopePolicy: CommentRules::ATTEMPT,
        );
    }

    /** POST /2/tweets {text, reply: {in_reply_to_tweet_id}}, mapped like a post. */
    public function addFirstComment(PreparedPost $context, PostResult $parent, string $text): CommentResult
    {
        $this->assertCredentials($context);
        $this->assertTokenFresh($context, $this->tokenMargin());

        $parentId = trim((string) $parent->platformPostId);

        if ($parentId === '') {
            throw new PermanentException(
                'X needs the id of the published post to reply to it.',
                Platform::X,
                FailureReason::NotFound,
                ['missing_field' => 'platform_post_id'],
            );
        }

        $response = $this->sendTweet($context, [
            'text' => trim($text),
            'reply' => ['in_reply_to_tweet_id' => $parentId],
        ]);

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        $id = $this->requireId($response, 'reply', 'data.id', true);
        $data = $response->json('data');

        return new CommentResult($id, $this->permalink($id), [
            'in_reply_to_tweet_id' => $parentId,
            'data' => is_array($data) ? $data : [],
        ]);
    }

    // --- Credentials and scopes ---------------------------------------------------

    protected function requiredCredentials(): array
    {
        return [$this->tokenKey => 'OAuth 2.0 user-context access token'];
    }

    protected function identityKeys(): array
    {
        return ['user_id'];
    }

    /**
     * Publish covers a post with any media, so it includes media.write, which
     * every image, GIF and video upload needs (a connect URL built from it then
     * works for every post; validateScopes() does not ask a text-only post for
     * it). Comment: a text reply. Video: the upload scope alone.
     */
    protected function requiredScopes(Feature $feature, Credentials $credentials): array
    {
        return match ($feature) {
            Feature::Publish => [['tweet.read'], ['tweet.write'], ['users.read'], ['media.write']],
            Feature::Comment => [['tweet.read'], ['tweet.write'], ['users.read']],
            Feature::Video => [['media.write']],
            default => [],
        };
    }

    // --- Errors -------------------------------------------------------------------

    /**
     * X answers {title, detail, type, status} (problem+json) or
     * {errors: [{message, detail}], title, detail, type}. The message prefers
     * errors[0].message, errors[0].detail, detail, then title, skipping bare
     * status names. In order:
     *
     * - 402, or credits mentioned → QuotaExceeded (operator billing, permanent);
     * - 429 → RateLimited: usage-capped is QuotaExceeded; else wait for the
     *   exhausted bucket's reset (24-hour user/app buckets first, then the
     *   15-minute window, then 120s); a wait above max_rate_limit_wait (3600)
     *   is permanent with retry_at / retry_after_hint;
     * - 5xx → ServerError, retried after 60s;
     * - locked / suspended (whole words: 'blocked' is not 'locked') → AccountRestricted;
     * - client-forbidden / unsupported-authentication → Misconfigured (the developer app);
     * - 401 → InvalidToken;
     * - 403: duplicate → DuplicateContent; reply restriction → InvalidContent;
     *   duration/video → MediaRejected; else InsufficientPermissions;
     * - 400 with X's reason: deleted target → NotFound, media → MediaRejected,
     *   else InvalidContent;
     * - anything else → the base table.
     */
    protected function classifyError($response): TemporaryException|PermanentException
    {
        $status = (int) $response->status();
        $json = $this->errorBody($response);
        $messages = $this->errorMessages($json);
        $message = $messages[0] ?? null;
        $type = is_string($json['type'] ?? null) ? $json['type'] : '';
        $problem = str_contains($type, '/problems/') ? substr($type, strrpos($type, '/') + 1) : '';
        $haystack = $this->errorHaystack($json);
        $context = ['status' => $status, 'error' => $json];

        if ($problem !== '') {
            $context['problem_type'] = $type;
        }

        if ($status === 402 || preg_match('/\bcredits?\b/', $haystack) === 1) {
            return new PermanentException(
                $message ?? 'X refused the request for lack of API credits (HTTP 402); top up the X developer account.',
                Platform::X,
                FailureReason::QuotaExceeded,
                $context,
            );
        }

        if ($status === 429) {
            return $this->rateLimited($response, $json, $message, $context, $problem);
        }

        if ($status >= 500) {
            return new TemporaryException(
                $message ?? "X is temporarily unavailable (HTTP {$status}).",
                Platform::X,
                FailureReason::ServerError,
                RetryAfter::fromResponse($response) ?? max(1, (int) $this->apiConfig('server_error_retry', 60)),
                $context,
            );
        }

        if (preg_match('/\b(locked|suspended|suspension)\b/', $haystack) === 1) {
            return new PermanentException(
                $message ?? 'The X account is locked or suspended; it must be unlocked on X.',
                Platform::X,
                FailureReason::AccountRestricted,
                $context,
            );
        }

        if (in_array($problem, ['client-forbidden', 'unsupported-authentication'], true)) {
            return new PermanentException(
                $message ?? 'X refused the developer app or the kind of token (use an OAuth 2.0 user-context token from an app attached to a Project).',
                Platform::X,
                FailureReason::Misconfigured,
                $context,
            );
        }

        if ($status === 401) {
            return new PermanentException(
                $message ?? 'X rejected the access token (HTTP 401); refresh it, or reconnect the account.',
                Platform::X,
                FailureReason::InvalidToken,
                $context,
            );
        }

        if ($status === 403) {
            return $this->forbidden($message, $haystack, $context);
        }

        if ($status === 400 && $message !== null) {
            $reason = match (true) {
                preg_match('/\b(does not exist|not found|deleted|no longer available)\b/', $haystack) === 1 => FailureReason::NotFound,
                preg_match('/\bmedia/', $haystack) === 1 => FailureReason::MediaRejected,
                default => FailureReason::InvalidContent,
            };

            return new PermanentException($message, Platform::X, $reason, $context);
        }

        // The base table, still carrying X's body under 'error' as in v1.0.
        return parent::classifyError($response)->addContext($context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function forbidden(?string $message, string $haystack, array $context): PermanentException
    {
        [$reason, $fallback] = match (true) {
            preg_match('/\bduplicate\b/', $haystack) === 1 => [
                FailureReason::DuplicateContent,
                'X refused the post as a duplicate of one already published.',
            ],
            preg_match('/\breply\b.*\bnot (allowed|permitted)\b|\bnot (allowed|permitted) to reply\b/', $haystack) === 1 => [
                FailureReason::InvalidContent,
                'X does not allow replying to this post.',
            ],
            preg_match('/\b(duration|video)/', $haystack) === 1 => [
                FailureReason::MediaRejected,
                "X refused the video for this account (duration or size above the account's limit).",
            ],
            default => [
                FailureReason::InsufficientPermissions,
                'X refused the request (HTTP 403); the token may lack a required permission.',
            ],
        };

        return new PermanentException($message ?? $fallback, Platform::X, $reason, $context);
    }

    /**
     * A 429. Monthly usage caps are an operator quota (permanent). Otherwise the
     * wait is the reset of the exhausted bucket; one longer than
     * max_rate_limit_wait is reported as permanent instead of burning retries.
     *
     * @param  mixed  $response
     * @param  array<string, mixed>  $json
     * @param  array<string, mixed>  $context
     */
    protected function rateLimited($response, array $json, ?string $message, array $context, string $problem): TemporaryException|PermanentException
    {
        if ($problem === 'usage-capped' || ($json['title'] ?? null) === 'UsageCapExceeded') {
            return new PermanentException(
                $message ?? 'The X API usage cap of the developer app is reached.',
                Platform::X,
                FailureReason::QuotaExceeded,
                $context + array_filter([
                    'usage_capped' => true,
                    'period' => is_scalar($json['period'] ?? null) ? $json['period'] : null,
                    'scope' => is_scalar($json['scope'] ?? null) ? $json['scope'] : null,
                ], static fn ($value) => $value !== null),
            );
        }

        $now = time();
        [$wait, $bucket] = $this->rateLimitWait($response, $now);
        $retryAt = gmdate('Y-m-d\TH:i:s\Z', $now + $wait);
        $context += ['retry_at' => $retryAt, 'rate_limit_bucket' => $bucket];

        if ($wait > (int) $this->apiConfig('max_rate_limit_wait', 3600)) {
            return new PermanentException(
                ($message ?? 'X rate limit reached').' (the limit resets at '.$retryAt.').',
                Platform::X,
                FailureReason::RateLimited,
                $context + ['retry_after_hint' => $wait],
            );
        }

        return new TemporaryException($message ?? 'X rate limit reached.', Platform::X, FailureReason::RateLimited, $wait, $context);
    }

    /**
     * Seconds until the exhausted bucket resets, and which bucket: the latest of
     * the 24-hour user/app buckets reporting 0 remaining, else the 15-minute
     * window (x-rate-limit-reset), else Retry-After, else rate_limit_retry (120).
     *
     * @param  mixed  $response
     * @return array{0: int, 1: string}
     */
    protected function rateLimitWait($response, int $now): array
    {
        $daily = [];

        foreach (['x-user-limit-24hour' => 'user_24h', 'x-app-limit-24hour' => 'app_24h'] as $prefix => $bucket) {
            $reset = $this->headerValue($response, "{$prefix}-reset");

            if ($this->headerValue($response, "{$prefix}-remaining") === '0' && $reset !== null) {
                $seconds = RetryAfter::parseReset($reset, $now);

                if ($seconds !== null) {
                    $daily[$bucket] = $seconds;
                }
            }
        }

        if ($daily !== []) {
            arsort($daily);

            return [max(1, (int) reset($daily)), (string) array_key_first($daily)];
        }

        $remaining = $this->headerValue($response, 'x-rate-limit-remaining');
        $reset = $this->headerValue($response, 'x-rate-limit-reset');

        if ($reset !== null && ($remaining === null || $remaining === '0')) {
            $seconds = RetryAfter::parseReset($reset, $now);

            if ($seconds !== null) {
                return [max(1, $seconds), 'window'];
            }
        }

        $retryAfter = $this->headerValue($response, 'retry-after');
        $seconds = $retryAfter !== null ? RetryAfter::parseRetryAfter($retryAfter, $now) : null;

        if ($seconds !== null) {
            return [max(1, $seconds), 'retry_after'];
        }

        return [max(1, (int) $this->apiConfig('rate_limit_retry', 120)), 'unknown'];
    }

    // --- Rule sets --------------------------------------------------------------------

    protected function photoRules(): ImageRules
    {
        return new ImageRules(
            extensions: self::PHOTO_EXTENSIONS,
            maxSizeBytes: (int) $this->apiConfig('max_image_bytes', 5242880),
        );
    }

    protected function gifRules(): ImageRules
    {
        return new ImageRules(extensions: ['gif'], maxSizeBytes: (int) $this->apiConfig('max_gif_bytes', 15728640));
    }

    /**
     * Post video: MP4, 0.5s to 20 minutes and 8GB, X's documented post limits
     * for accounts without Premium (Premium / verified accounts: 125 minutes
     * and 16GB; raise max_video_duration / max_video_bytes). 140s / 512MB are
     * X's DM video limits, not the post limits; set them to keep a stricter
     * product rule. X checks the posting account's limit again when the post
     * is created and answers 403 above it (mapped to MediaRejected). At least
     * 32x32 and an aspect ratio between 1:3 and 3:1. There is no maximum width
     * or height: X takes 1080p and vertical phone video in production,
     * although its docs still quote 1280x1024.
     */
    protected function videoRules(): VideoRules
    {
        $minSide = max(1, (int) $this->apiConfig('min_video_dimension', 32));

        return new VideoRules(
            extensions: ['mp4'],
            maxSizeBytes: (int) $this->apiConfig('max_video_bytes', 8589934592),
            minDuration: (float) $this->apiConfig('min_video_duration', 0.5),
            maxDuration: (float) $this->apiConfig('max_video_duration', 1200),
            minWidth: $minSide,
            minHeight: $minSide,
            aspectRatioRange: [1 / 3, 3.0],
        );
    }

    // --- Helpers --------------------------------------------------------------------

    /** Whether the post would be sent with a quote_tweet_id (XOptions::$quoteTweetId or the extra passthrough). */
    protected function quotesPost(PreparedPost $post): bool
    {
        $quote = $this->tweetPayload($post, [])['quote_tweet_id'] ?? null;

        return is_scalar($quote) && trim((string) $quote) !== '';
    }

    /**
     * Log once per driver instance (a queue worker keeps one) that nothing
     * identifies the account: the idempotency key is then scoped by platform
     * and ->idempotencyKey() only (as in v1.0), so two X accounts dispatched
     * with the same key share one record. Only when a persistent idempotency
     * store is bound.
     */
    protected function noticeTokenIdentity(PreparedPost $post): void
    {
        if ($this->tokenIdentityNoticed || $this->accountKey($post->credentials) !== '') {
            return;
        }

        $this->tokenIdentityNoticed = true;

        try {
            if (app(IdempotencyStore::class) instanceof NullIdempotencyStore) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $this->notice(
            'the credentials carry no user_id, so idempotency keys are not scoped to the X account: two accounts dispatched with the same ->idempotencyKey() share one record. '
            .'Pass user_id (the numeric X account id), or an account credential, to keep accounts apart.',
            [],
            true,
        );
    }

    protected function isGif(?Media $media): bool
    {
        return $media !== null && $media->type === MediaType::Image && $media->extension() === 'gif';
    }

    protected function category(Media $media): string
    {
        return $media->type === MediaType::Video ? 'tweet_video' : 'tweet_gif';
    }

    protected function mime(Media $media): string
    {
        return match ($media->extension()) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            default => 'application/octet-stream',
        };
    }

    /**
     * mime(), falling back to the content of the already-resolved local file
     * when the extension says nothing (signed or CDN URLs without one).
     */
    protected function mimeFor(Media $media, string $path): string
    {
        $mime = $this->mime($media);

        if ($mime !== 'application/octet-stream' || ! function_exists('mime_content_type')) {
            return $mime;
        }

        $detected = @mime_content_type($path);

        return is_string($detected) && $detected !== '' ? $detected : $mime;
    }

    /**
     * The local file to upload. A remote URL here means no MediaGateway
     * downloaded it, which is a setup problem, not a media one.
     */
    protected function localPath(Media $media): string
    {
        $path = $this->mediaPath($media);

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1 && ! str_starts_with(strtolower($path), 'file://')) {
            throw new PermanentException(
                'X uploads the file itself, but the media is a remote URL and no MediaGateway downloaded it. Bind a MediaGateway (the default LocalMediaGateway downloads remote media).',
                Platform::X,
                FailureReason::Misconfigured,
            );
        }

        return $path;
    }

    /** Alt text to set on a chunked upload: GIFs only (X takes no alt text on video). */
    protected function chunkedAltText(Media $media): ?string
    {
        $alt = trim((string) $media->altText);

        return $this->isGif($media) && $alt !== '' ? $alt : null;
    }

    /** Public URL of a post; x.com resolves it without the username. */
    protected function permalink(string $id): string
    {
        return 'https://x.com/i/status/'.$id;
    }

    /** API root, from api.x.base_url (e.g. https://api.twitter.com/2), else $api. */
    protected function baseUrl(): string
    {
        $configured = $this->apiConfig('base_url');

        return rtrim(is_string($configured) && trim($configured) !== '' ? trim($configured) : $this->api, '/');
    }

    /**
     * The client for API calls (initialize, finalize, STATUS, tweets, metadata):
     * api.x.timeout, 120s by default, since a slow FINALIZE or create is
     * common and a timeout there forces a retry.
     */
    protected function client(PreparedPost $post): PendingRequest
    {
        return $this->http($post)->timeout((int) $this->apiConfig('timeout', 120));
    }

    /** The client for byte uploads (one-shot images and APPEND segments). */
    protected function uploadClient(PreparedPost $post): PendingRequest
    {
        return $this->http($post)->timeout((int) $this->apiConfig('upload_timeout', config('social.upload_timeout', 300)));
    }

    protected function captionRules(): CaptionRules
    {
        return new CaptionRules(max: (int) $this->apiConfig('max_characters', 280), counter: new XWeightedCounter);
    }

    protected function requiresText(): bool
    {
        return (bool) $this->apiConfig('require_text', true);
    }

    protected function maxAltTextLength(): int
    {
        return max(1, (int) $this->apiConfig('max_alt_text', 1000));
    }

    /** Seconds a token must still be valid for before a call is made (X tokens live 2h). */
    protected function tokenMargin(): int
    {
        return max(0, (int) $this->apiConfig('token_margin', 60));
    }

    /**
     * @param  string[]  $mediaIds
     * @return array<string, mixed>
     */
    protected function tweetState(array $mediaIds): array
    {
        return ['phase' => self::PHASE_TWEET, 'media_ids' => array_values($mediaIds)];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return string[]
     */
    protected function stateMediaIds(array $state): array
    {
        $ids = $state['media_ids'] ?? (isset($state['media_id']) ? [$state['media_id']] : []);
        $ids = array_filter((array) $ids, static fn ($id) => is_scalar($id) && trim((string) $id) !== '');

        return array_values(array_map(static fn ($id) => trim((string) $id), $ids));
    }

    /** @param  array<string, mixed>  $state */
    protected function stateString(array $state, string $key): string
    {
        $value = $state[$key] ?? null;

        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new PermanentException(
                "The X publish state has no {$key}.",
                Platform::X,
                FailureReason::Unknown,
                ['phase' => $state['phase'] ?? null, 'missing_field' => $key],
            );
        }

        return trim((string) $value);
    }

    /** @param  mixed  $response */
    protected function headerValue($response, string $name): ?string
    {
        try {
            $value = trim((string) $response->header($name));
        } catch (\Throwable) {
            return null;
        }

        return $value !== '' ? $value : null;
    }

    /**
     * @param  mixed  $response
     * @return array<string, mixed>
     */
    private function errorBody($response): array
    {
        try {
            $json = $response->json();
        } catch (\Throwable) {
            return [];
        }

        return is_array($json) ? $json : [];
    }

    /**
     * X's own messages, most specific first, without bare status names.
     *
     * @param  array<string, mixed>  $json
     * @return list<string>
     */
    private function errorMessages(array $json): array
    {
        $messages = [];

        foreach (['errors.0.message', 'errors.0.detail', 'detail', 'title'] as $path) {
            $value = data_get($json, $path);

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $value = trim($value);

            if (in_array(strtolower(rtrim($value, '.')), self::BARE_MESSAGES, true) || in_array($value, $messages, true)) {
                continue;
            }

            $messages[] = $value;
        }

        return $messages;
    }

    /**
     * Every text field of the error (all errors[*] messages, detail, title,
     * type), lower-cased, for keyword rules.
     *
     * @param  array<string, mixed>  $json
     */
    private function errorHaystack(array $json): string
    {
        $parts = [];

        foreach ((array) ($json['errors'] ?? []) as $error) {
            foreach (['message', 'detail', 'title'] as $key) {
                if (is_array($error) && is_string($error[$key] ?? null)) {
                    $parts[] = $error[$key];
                }
            }
        }

        foreach (['detail', 'title', 'type', 'reason'] as $key) {
            if (is_string($json[$key] ?? null)) {
                $parts[] = $json[$key];
            }
        }

        return strtolower(implode(' ', $parts));
    }

    /**
     * A warning, or with $advisory a notice (a setup hint, not a problem with
     * this post).
     *
     * @param  array<string, mixed>  $context
     */
    private function notice(string $message, array $context = [], bool $advisory = false): void
    {
        if (! (bool) config('social.error_logging', true)) {
            return;
        }

        try {
            $channel = config('social.error_log_channel');
            $line = '[SocialPoster] x: '.$message;

            if (is_string($channel) && $channel !== '') {
                $advisory ? Log::channel($channel)->notice($line, $context) : Log::channel($channel)->warning($line, $context);
            } else {
                $advisory ? Log::notice($line, $context) : Log::warning($line, $context);
            }
        } catch (\Throwable) {
            // logging must never interfere with publishing
        }
    }
}
