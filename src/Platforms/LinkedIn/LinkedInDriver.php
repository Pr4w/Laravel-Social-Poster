<?php

namespace SocialPoster\Platforms\LinkedIn;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\MessageBag;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\CommentRules;
use SocialPoster\Capabilities\DocumentRules;
use SocialPoster\Capabilities\ImageRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Capabilities\VideoRules;
use SocialPoster\Concerns\UploadsInChunks;
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
use SocialPoster\Support\PublicUrl;
use SocialPoster\Support\RetryAfter;
use SocialPoster\Support\Text\Urls;
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
 * LinkedIn through the versioned REST APIs (Posts, Images, Videos, Documents,
 * Comments), sent with the LinkedIn-Version header from
 * config('social.api.linkedin.version').
 *
 * Flow. Media is uploaded first, then the post is created in its own commit
 * step (Pending::$commits), so a run that may repeat the create arrives with
 * `_recovering` set:
 *
 * - text / images: upload images (or build a link card) → create_post;
 * - video: initializeUpload → upload_video (parts staged across runs within
 *   upload_run_seconds, ETags kept in state) → finalize → await_video
 *   (GET /rest/videos/{urn}) → create_post once AVAILABLE;
 * - document: initializeUpload + PUT → await_document → create_post once AVAILABLE.
 *
 * LinkedIn posts cannot be looked up with the posting scopes (w_*_social), so a
 * create that may already have happened (a lost response, a worker that died
 * mid-call) fails as OutcomeUnknown with possibly_published = true instead of
 * risking a duplicate. A connection failure that provably never sent the
 * request is retried. With read scopes (r_member_social / r_organization_social
 * in the credentials' `scopes`, or api.linkedin.verify_reads = true) the driver
 * looks the post up before deciding.
 *
 * Text. prepare() escapes LinkedIn's reserved little-text characters. Mentions
 * are first-class through LinkedInOptions::$mentions and {{mention:N}} markers
 * (caller supplies the URN and the exact display name), or resolved from
 * linkedin.com profile/company links with autoMentionUrls. Hand-built little
 * text is possible with escapeText: false. Link cards come from
 * LinkedInOptions::$article or autoLinkCard (LinkedIn does not scrape URLs).
 *
 * Credentials: access_token and author (urn:li:person:… or
 * urn:li:organization:…); optional scopes, expires_at, and organization (an
 * organization URN the member administers, for resolving person mentions).
 */
class LinkedInDriver extends AbstractPlatform implements SupportsComments, SupportsFirstComment
{
    use UploadsInChunks;

    protected string $rest = 'https://api.linkedin.com/rest';

    protected int $captionLimit = 3000;

    /** Person or organization URN with a plain id. */
    protected const AUTHOR_PATTERN = '/^urn:li:(person|organization):[A-Za-z0-9_-]+\z/';

    /** Access-token error codes LinkedIn puts in the top-level `code`. */
    protected const TOKEN_CODES = ['REVOKED_ACCESS_TOKEN', 'EXPIRED_ACCESS_TOKEN', 'INVALID_ACCESS_TOKEN', 'EMPTY_ACCESS_TOKEN'];

    /** Roles a member needs on a page to post as it (Posts API). */
    protected const PAGE_POST_ROLES = 'ADMINISTRATOR, DIRECT_SPONSORED_CONTENT_POSTER or CONTENT_ADMIN';

    /** Roles a member needs on a page to comment as it (Comments API). */
    protected const PAGE_COMMENT_ROLES = 'ADMINISTRATOR, DIRECT_SPONSORED_CONTENT_POSTER or RECRUITING_POSTER';

    /** The window a create or verify step gets to be scheduled in, past any processing deadline. */
    protected const CREATE_STEP_SECONDS = 300;

    /**
     * Clock skew allowed when a recovery read matches a listed post's createdAt
     * against the start of the attempt (LinkedIn's clock against ours).
     */
    protected const RECOVERY_SKEW_SECONDS = 60;

    /** Bytes of a page read for its Open Graph tags (the <head> is in the first megabyte). */
    protected const CARD_PAGE_READ_BYTES = 1_048_576;

    /** A link-card page download is aborted past this size (a file, not a web page). */
    protected const CARD_PAGE_MAX_DOWNLOAD = 5_242_880;

    public function platform(): Platform
    {
        return Platform::LinkedIn;
    }

    protected function ingestion(): Ingestion
    {
        return Ingestion::Upload;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            caption: new CaptionRules(max: $this->captionLimit()),
            media: new MediaRules(
                minCount: 0,
                maxCount: $this->maxImages(),
                image: $this->imageRules(),
                video: $this->videoRules(),
                document: $this->documentRules(),
            ),
            supportsTitle: true,
            supportsCarousel: true,
        );
    }

    protected function rulesFor(PreparedPost $post): Capabilities
    {
        $caption = new CaptionRules(max: $this->captionMax($post));

        // Multi-image carousel: images only, 2 to 20.
        if (count($post->media()) > 1) {
            return new Capabilities(
                caption: $caption,
                media: new MediaRules(minCount: 2, maxCount: $this->maxImages(), image: $this->imageRules()),
                supportsCarousel: true,
            );
        }

        // Text, or a single image / video / document.
        return new Capabilities(
            caption: $caption,
            media: new MediaRules(
                minCount: 0,
                maxCount: 1,
                image: $this->imageRules(),
                video: $this->videoRules(),
                document: $this->documentRules(),
            ),
            supportsTitle: true,
        );
    }

    protected function validatePlatformRules(PreparedPost $post, MessageBag $errors): void
    {
        $caption = (string) $post->caption();
        $options = $this->options($post);

        // A reshare's commentary may be empty; every other post needs text.
        if (trim($caption) === '' && $options?->reshareOf === null) {
            $errors->add('caption', "LinkedIn posts can't have an empty caption.");
        }

        // With mentions the raw caption carries {{mention:N}} markers, so the
        // generic engine can't measure the real length (rulesFor lifts its cap for
        // this case). LinkedIn counts the visible text — names, not URNs — so that
        // is what we check here, matching what the user actually sees.
        $mentions = $options->mentions ?? [];

        if ($mentions !== []) {
            $visible = mb_strlen($this->visibleText($caption, $mentions));
            $limit = $this->captionLimit();

            if ($visible > $limit) {
                $errors->add('caption', "Caption exceeds the {$limit} character limit (currently {$visible} visible characters).");
            }
        }

        $this->validateMentionMarkers($caption, $mentions, $errors);
        $this->validateAuthorShape($post, $errors);
        $this->validateAltText($post, $errors);
        $this->validateContentCombinations($post, $errors);
        $this->validateComment($post, $errors);
    }

    /**
     * A {{mention:N}} marker with no entry N in $mentions would publish as
     * literal text, so it is a caption error. (The literal fallback in
     * renderMentions() stays only for callers that skip validation.)
     *
     * @param  list<LinkedInMention>  $mentions
     */
    protected function validateMentionMarkers(string $caption, array $mentions, MessageBag $errors): void
    {
        if (preg_match_all('/\{\{mention:(\d+)\}\}/', $caption, $matches) < 1) {
            return;
        }

        foreach (array_unique(array_map('intval', $matches[1])) as $index) {
            if (! isset($mentions[$index])) {
                $errors->add('caption', $mentions === []
                    ? "The caption contains {{mention:{$index}}} but no mentions were given (LinkedInOptions::\$mentions)."
                    : "The caption contains {{mention:{$index}}} but LinkedInOptions::\$mentions has no entry {$index}.");
            }
        }
    }

    /**
     * Checked only when an author is present: validate() also runs without
     * credentials (content-only dry runs), and a missing author is reported by
     * assertCredentials() when publishing.
     */
    protected function validateAuthorShape(PreparedPost $post, MessageBag $errors): void
    {
        $author = $post->credentials->get('author');

        if ($author === null || (is_string($author) && trim($author) === '')) {
            return;
        }

        if (! is_string($author) || ! $this->isValidAuthor($author)) {
            $errors->add('credentials.author', 'The LinkedIn author must be a member or organization URN (urn:li:person:… or urn:li:organization:…).');
        }
    }

    protected function validateAltText(PreparedPost $post, MessageBag $errors): void
    {
        $max = (int) $this->apiConfig('max_alt_text', LinkedInArticle::MAX_ALT_TEXT);

        foreach ($post->media() as $index => $media) {
            if ($media->altText !== null && mb_strlen($media->altText) > $max) {
                $errors->add("media.{$index}", "LinkedIn alt text is limited to {$max} characters.");
            }
        }
    }

    protected function validateContentCombinations(PreparedPost $post, MessageBag $errors): void
    {
        $options = $this->options($post);

        if ($options === null) {
            return;
        }

        if ($post->media() !== []) {
            if ($options->article !== null) {
                $errors->add('article', "A LinkedIn link card can't be combined with media.");
            }

            if ($options->poll !== null) {
                $errors->add('poll', "A LinkedIn poll can't be combined with media.");
            }

            if ($options->reshareOf !== null) {
                $errors->add('reshareOf', "A LinkedIn reshare can't carry media.");
            }
        }

        // Dark posts and targeting exist for organization authors only. The
        // author is unknown during a content-only validate(), so only a known
        // person author is refused here.
        if ($this->authorType($post->credentials->get('author')) === 'person') {
            if ($options->feedDistribution === LinkedInOptions::FEED_NONE) {
                $errors->add('feedDistribution', 'feedDistribution NONE (a post kept out of the feed) is for organization authors only.');
            }

            if ($options->targetEntities !== []) {
                $errors->add('targetEntities', 'Targeted posts (targetEntities) are for organization authors only.');
            }
        }
    }

    /** The first comment is checked before publishing, so an over-long one never fails after the post is live. */
    protected function validateComment(PreparedPost $post, MessageBag $errors): void
    {
        $comment = $post->comment();

        if ($comment === null || trim($comment) === '') {
            return;
        }

        $rules = $this->commentRules($post);

        if ($rules !== null) {
            $this->validateText('comment', 'Comment', $comment, $rules->max, $rules->counter, $errors, 0, $rules->maxBytes);
        }
    }

    protected function prepare(PreparedPost $post): array
    {
        $caption = (string) $post->caption();
        $options = $this->options($post);
        $mentions = $options->mentions ?? [];

        // No mentions: unchanged behaviour, honouring escapeText.
        if ($mentions === []) {
            $escape = $options->escapeText ?? true;

            return ['commentary' => $escape ? $this->escape($caption) : $caption];
        }

        return ['commentary' => $this->renderMentions($caption, $mentions)];
    }

    /**
     * Turn a caption with {{mention:N}} markers into LinkedIn "little text": each
     * text segment is escaped, each marker becomes @[escaped name](raw urn).
     * Splitting on the markers first means the {{ }} we consume and the @[]() we
     * emit never pass through escape(), so there is no collision. escapeText is
     * intentionally ignored here — with mentions the driver owns escaping end to
     * end, and honouring it would let raw text sit next to managed mentions and
     * produce invalid commentary.
     *
     * @param  list<LinkedInMention>  $mentions
     */
    protected function renderMentions(string $caption, array $mentions): string
    {
        $parts = preg_split('/\{\{mention:(\d+)\}\}/', $caption, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $this->escape($caption);
        }

        $out = '';

        foreach ($parts as $i => $part) {
            // Even indices are literal text; odd indices are a captured marker index.
            if ($i % 2 === 0) {
                $out .= $this->escape($part);

                continue;
            }

            $mention = $mentions[(int) $part] ?? null;

            // An orphan marker (no matching entry) fails validation; when
            // validation was skipped it is left as literal, escaped text rather
            // than dropped, so a wrong index is visible and debuggable.
            $out .= $mention !== null
                ? '@['.$this->escape($mention->name).']('.$mention->urn.')'
                : $this->escape('{{mention:'.$part.'}}');
        }

        return $out;
    }

    /**
     * The caption as the reader sees it: {{mention:N}} markers replaced by their
     * display name, no URNs, no escaping. This is what LinkedIn's length limit
     * applies to. An orphan marker is left in place (it renders literally).
     *
     * @param  list<LinkedInMention>  $mentions
     */
    protected function visibleText(string $caption, array $mentions): string
    {
        return preg_replace_callback(
            '/\{\{mention:(\d+)\}\}/',
            static fn (array $m): string => ($mentions[(int) $m[1]] ?? null)->name ?? $m[0],
            $caption,
        ) ?? $caption;
    }

    /**
     * The caption length cap handed to the generic validator. With mentions the
     * raw caption contains markers whose length differs from the rendered text, so
     * the real check moves to validatePlatformRules() and the generic engine is
     * told not to measure the raw form.
     */
    protected function captionMax(PreparedPost $post): int
    {
        return ($this->options($post)->mentions ?? []) === [] ? $this->captionLimit() : PHP_INT_MAX;
    }

    // --- Publish ----------------------------------------------------------------

    public function publish(PreparedPost $post): PublishOutcome
    {
        $this->assertCredentials($post);
        $this->author($post);
        $this->assertTokenFresh($post);

        $media = $post->media();

        if ($media === []) {
            return $this->publishText($post);
        }

        if (count($media) > 1) {
            return $this->publishImages($post);
        }

        return match ($media[0]->type) {
            MediaType::Image => $this->publishImages($post),
            MediaType::Video => $this->startVideo($post, $media[0]),
            MediaType::Document => $this->startDocument($post, $media[0]),
        };
    }

    /**
     * Phases: create_post (commit), upload_video, finalize_video, await_video,
     * await_document. v1.0 continuations (await_video with `video`,
     * await_document with `document` + `title`) resume unchanged.
     */
    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        $this->assertCredentials($post);
        $this->author($post);

        $phase = $state['phase'] ?? null;

        return match ($phase) {
            'create_post' => $this->createPost($post, $state),
            'upload_video' => $this->continueVideoUpload($post, $state),
            'finalize_video' => $this->resumeFinalize($post, $state),
            'await_video' => $this->finishVideo($post, $state),
            'await_document' => $this->finishDocument($post, $state),
            default => throw new PermanentException(
                'Unknown LinkedIn publish phase'.(is_scalar($phase) ? " '{$phase}'." : '.'),
                Platform::LinkedIn,
                FailureReason::Unknown,
                ['phase' => is_scalar($phase) ? $phase : null],
            ),
        };
    }

    // --- Text -----------------------------------------------------------------

    /** Text, poll, reshare or link-card post: everything is prepared here, the create runs as a commit step. */
    protected function publishText(PreparedPost $post): PublishOutcome
    {
        $commentary = $this->commentaryFor($post);

        return $this->commitCreate($post, $commentary, $this->textContent($post));
    }

    /**
     * The content block of a text post: a poll, a link card, or none.
     *
     * @return array<string, mixed>|null
     */
    protected function textContent(PreparedPost $post): ?array
    {
        $options = $this->options($post);

        if ($options === null || $options->reshareOf !== null) {
            return null;
        }

        if ($options->poll !== null) {
            return $options->poll->toContent();
        }

        return $this->linkCard($post);
    }

    // --- Images (uploaded here, created in the commit step) --------------------

    protected function publishImages(PreparedPost $post): PublishOutcome
    {
        $commentary = $this->commentaryFor($post);
        $images = [];

        try {
            foreach ($post->media() as $image) {
                $images[] = ['id' => $this->uploadImage($post, $image)] + $this->altTextOf($image);
            }
        } finally {
            $this->gateway()?->cleanup();
        }

        $content = count($images) > 1
            ? ['multiImage' => ['images' => $images]]
            : ['media' => $images[0]];

        return $this->commitCreate($post, $commentary, $content);
    }

    protected function uploadImage(PreparedPost $post, Media $image): string
    {
        return $this->uploadImageBytes($post, $this->bytes($image));
    }

    /** Images API: initializeUpload, then PUT the bytes. Returns the image URN. */
    protected function uploadImageBytes(PreparedPost $post, string $bytes): string
    {
        $upload = $this->initializeUpload($post, 'images', 'image');

        $this->putUpload($post, $upload['uploadUrl'], $bytes, 'an image');

        return $upload['urn'];
    }

    /**
     * POST {kind}?action=initializeUpload for images and documents, with the
     * upload URL and the asset URN checked (a missing one is a temporary
     * LinkedIn fault, never a TypeError).
     *
     * @return array{uploadUrl: string, urn: string}
     */
    protected function initializeUpload(PreparedPost $post, string $kind, string $field): array
    {
        $author = $this->author($post);
        $init = $this->rest($post)->post("{$kind}?action=initializeUpload", [
            'initializeUploadRequest' => ['owner' => $author],
        ]);

        if (! $this->ok($init)) {
            throw $this->mapError($init, 'upload', $author);
        }

        return [
            'uploadUrl' => $this->requireId($init, "{$field} upload URL", 'value.uploadUrl'),
            'urn' => $this->requireId($init, "{$field} upload", "value.{$field}"),
        ];
    }

    /**
     * PUT bytes to a LinkedIn upload URL. Kept off mapError(): these URLs answer
     * 401 when they expire, which is not a token problem. A 401/403 (expired or
     * refused URL), 408, 429 or 5xx is retried; any other 4xx (a malformed or
     * oversized upload) would fail the same way every time, so it is final.
     *
     * @param  string|StreamInterface  $body
     */
    protected function putUpload(PreparedPost $post, string $url, $body, string $what): void
    {
        $response = $this->http($post)
            ->timeout($this->uploadTimeout())
            ->withBody($body, 'application/octet-stream')
            ->put($url);

        $status = $response->status();

        if (! $this->ok($response) && $status >= 400 && $status < 500 && ! in_array($status, [401, 403, 408, 429], true)) {
            throw new PermanentException(
                "LinkedIn refused the upload of {$what} (HTTP {$status}).",
                Platform::LinkedIn,
                FailureReason::MediaRejected,
                ['http_status' => $status, 'upload' => $what],
            );
        }

        if (! $this->ok($response)) {
            throw new TemporaryException(
                "Failed to upload {$what} to LinkedIn (HTTP {$response->status()}).",
                Platform::LinkedIn,
                FailureReason::ServerError,
                null,
                ['http_status' => $response->status(), 'upload' => $what],
            );
        }
    }

    /** @return array{altText?: string} */
    protected function altTextOf(Media $media): array
    {
        $alt = $media->altText !== null ? trim($media->altText) : '';

        return $alt !== '' ? ['altText' => $alt] : [];
    }

    // --- Video (staged multi-part upload, finalize, processing wait) -----------

    protected function startVideo(PreparedPost $post, Media $video): PublishOutcome
    {
        $started = microtime(true);

        try {
            $commentary = $this->commentaryFor($post);
            $path = $this->mediaPath($video);
            $size = $this->uploadSize($path);
            $this->assertVideoSize($size);

            $author = $this->author($post);
            $thumbnail = $this->wantsThumbnail($video);

            $init = $this->rest($post)->post('videos?action=initializeUpload', [
                'initializeUploadRequest' => [
                    'owner' => $author,
                    'fileSizeBytes' => $size,
                    'uploadCaptions' => false,
                    'uploadThumbnail' => $thumbnail,
                ],
            ]);

            if (! $this->ok($init)) {
                throw $this->mapError($init, 'upload', $author);
            }

            $thumbnailUrl = $init->json('value.thumbnailUploadUrl');
            $expiresAt = $init->json('value.uploadUrlsExpireAt');

            $state = [
                'phase' => 'upload_video',
                'video' => $this->requireId($init, 'video upload', 'value.video'),
                'size' => $size,
                'upload_token' => is_scalar($init->json('value.uploadToken')) ? (string) $init->json('value.uploadToken') : '',
                'instructions' => $this->uploadInstructions($init, $size),
                'etags' => [],
                'next' => 0,
                'thumbnail_url' => $thumbnail && is_string($thumbnailUrl) && $thumbnailUrl !== '' ? $thumbnailUrl : null,
                'upload_expires_at' => is_numeric($expiresAt) ? (int) $expiresAt : null,
                'commentary' => $commentary,
            ];

            return $this->uploadVideoParts($post, $video, $state, $path, $started);
        } finally {
            $this->gateway()?->cleanup();
        }
    }

    /**
     * A later run of a staged upload: the bytes are resolved again (temporary
     * downloads never outlive a run) and must still be the same size.
     *
     * @param  array<string, mixed>  $state
     */
    protected function continueVideoUpload(PreparedPost $post, array $state): PublishOutcome
    {
        $started = microtime(true);
        $video = $this->videoMedia($post);

        try {
            $path = $this->mediaPath($video);
            $size = $this->uploadSize($path);

            if ($size !== (int) ($state['size'] ?? 0)) {
                throw new PermanentException(
                    'The video file changed during the LinkedIn upload (now '.$size.' bytes, expected '.(int) ($state['size'] ?? 0).').',
                    Platform::LinkedIn,
                    FailureReason::MediaRejected,
                    ['size' => $size, 'expected_size' => $state['size'] ?? null],
                );
            }

            return $this->uploadVideoParts($post, $video, $state, $path, $started);
        } finally {
            $this->gateway()?->cleanup();
        }
    }

    /**
     * Upload the parts LinkedIn planned, from `next`, while this run has budget
     * (upload_run_seconds). The collected ETags and the next index live in the
     * state, so a slow transfer continues in the next run instead of being
     * killed by the job timeout. A failure after some progress hands the
     * progress over (Pending) rather than throwing it away.
     *
     * Every run sends at least one batch: the budget clock starts before the
     * media is resolved (so a run stays within the job timeout), and a slow
     * resolve (a large remote download) that spent the whole budget would
     * otherwise leave every run with nothing sent until the deadline.
     *
     * @param  array<string, mixed>  $state
     */
    protected function uploadVideoParts(PreparedPost $post, ?Media $video, array $state, string $path, float $started): PublishOutcome
    {
        /** @var list<array{uploadUrl: string, firstByte: int, lastByte: int}> $instructions */
        $instructions = array_values((array) ($state['instructions'] ?? []));
        $count = count($instructions);
        $next = (int) ($state['next'] ?? 0);
        $etags = array_values((array) ($state['etags'] ?? []));
        $batchSize = $this->uploadConcurrency();
        $progressed = false;

        while ($next < $count && ($this->budgetLeft($started) || ! $progressed)) {
            $batch = array_slice($instructions, $next, $batchSize, true);

            try {
                $tags = $this->putVideoParts($post, $path, $batch, $state);
            } catch (TemporaryException $e) {
                if (! $progressed || ($e->context['restart'] ?? false) === true) {
                    throw $e;
                }

                $this->logWarning('A LinkedIn video part failed; keeping the parts already uploaded and retrying from part '.$next.'.', [
                    'video' => $state['video'] ?? null,
                    'error' => $e->getMessage(),
                ]);

                return $this->pending(array_replace($state, ['next' => $next, 'etags' => $etags]), 15);
            }

            array_push($etags, ...$tags);
            $next += count($batch);
            $progressed = true;
        }

        $state = array_replace($state, ['next' => $next, 'etags' => $etags]);

        if ($next < $count) {
            return $this->pending($state, 0);
        }

        return $this->finalizeVideo($post, $video, $state);
    }

    /**
     * PUT one batch of parts (one at a time, or concurrently with
     * upload_concurrency > 1) and return their ETags in order.
     *
     * @param  array<int, array{uploadUrl: string, firstByte: int, lastByte: int}>  $batch
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    protected function putVideoParts(PreparedPost $post, string $path, array $batch, array $state): array
    {
        if (count($batch) === 1) {
            $index = (int) array_key_first($batch);
            $instruction = $batch[$index];
            $chunk = $this->partBytes($path, $instruction);

            try {
                $response = $this->http($post)
                    ->timeout($this->uploadTimeout())
                    ->withBody($chunk, 'application/octet-stream')
                    ->put($instruction['uploadUrl']);
            } catch (ConnectionException $e) {
                throw TemporaryException::connection(Platform::LinkedIn, $e)->addContext(['part' => $index]);
            }

            return [$this->partEtag($response, $index, $state)];
        }

        $chunks = [];

        foreach ($batch as $index => $instruction) {
            $chunks[$index] = $this->partBytes($path, $instruction);
        }

        $token = $this->token($post);
        $timeout = $this->uploadTimeout();

        try {
            // Concurrency is passed positionally: Laravel 11's pool() has no such parameter.
            $connectTimeout = (int) $this->apiConfig('connect_timeout', 10);
            $responses = Http::pool(function (Pool $pool) use ($batch, $chunks, $token, $timeout, $connectTimeout) {
                foreach ($batch as $index => $instruction) {
                    $pool->as((string) $index)
                        ->withToken($token)
                        ->connectTimeout($connectTimeout)
                        ->timeout($timeout)
                        ->withBody($chunks[$index], 'application/octet-stream')
                        ->put($instruction['uploadUrl']);
                }
            }, count($batch));
        } catch (ConnectionException $e) {
            throw TemporaryException::connection(Platform::LinkedIn, $e);
        }

        $tags = [];

        foreach (array_keys($batch) as $index) {
            $response = $responses[$index] ?? $responses[(string) $index] ?? null;

            if (! $response instanceof Response) {
                throw new TemporaryException(
                    "Network error uploading video part {$index} to LinkedIn.",
                    Platform::LinkedIn,
                    FailureReason::Network,
                    null,
                    ['part' => $index, 'connection' => true],
                );
            }

            $tags[] = $this->partEtag($response, $index, $state);
        }

        return $tags;
    }

    /**
     * The exact byte range of one part. A short read means the file is not the
     * one LinkedIn planned the parts for.
     *
     * @param  array{uploadUrl: string, firstByte: int, lastByte: int}  $instruction
     */
    protected function partBytes(string $path, array $instruction): string
    {
        $length = $instruction['lastByte'] - $instruction['firstByte'] + 1;
        $chunk = $this->readChunk($path, $instruction['firstByte'], $length);

        if (strlen($chunk) !== $length) {
            throw new PermanentException(
                'The video file is shorter than the upload LinkedIn planned for it.',
                Platform::LinkedIn,
                FailureReason::MediaRejected,
                ['first_byte' => $instruction['firstByte'], 'expected' => $length, 'read' => strlen($chunk)],
            );
        }

        return $chunk;
    }

    /**
     * The ETag of an uploaded part; finalizeUpload needs every one of them.
     *
     * @param  array<string, mixed>  $state
     */
    protected function partEtag(Response $response, int $index, array $state): string
    {
        if (! $this->ok($response)) {
            $context = ['http_status' => $response->status(), 'part' => $index];

            // The part URLs expire (uploadUrlsExpireAt) and then answer 401:
            // start a fresh upload rather than retry dead URLs.
            if ($response->status() === 401 && $this->uploadUrlsExpired($state)) {
                throw new TemporaryException(
                    'The LinkedIn upload URLs expired before the video was sent; restarting the upload.',
                    Platform::LinkedIn,
                    FailureReason::ServerError,
                    0,
                    $context + ['restart' => true],
                );
            }

            throw new TemporaryException(
                "Failed to upload a video chunk to LinkedIn (part {$index}, HTTP {$response->status()}).",
                Platform::LinkedIn,
                FailureReason::ServerError,
                null,
                $context,
            );
        }

        $etag = trim((string) $response->header('ETag'));

        if ($etag === '') {
            throw new TemporaryException(
                "LinkedIn accepted video part {$index} but returned no ETag.",
                Platform::LinkedIn,
                FailureReason::ServerError,
                null,
                ['part' => $index],
            );
        }

        return $etag;
    }

    /**
     * Upload the thumbnail (before finalizing, as documented), then finalize
     * with the init's uploadToken and every part's ETag. A lost finalize
     * response keeps the uploaded parts (finalize_video phase); a refused
     * finalize is checked against the video status, since a repeated finalize
     * of an already finalized video is refused too.
     *
     * @param  array<string, mixed>  $state
     */
    protected function finalizeVideo(PreparedPost $post, ?Media $video, array $state): PublishOutcome
    {
        if (is_string($state['thumbnail_url'] ?? null) && $video !== null) {
            $this->uploadVideoThumbnail($post, $video, $state['thumbnail_url']);
        }

        $state['thumbnail_url'] = null;
        $author = $this->author($post);

        try {
            $finalize = $this->rest($post)->post('videos?action=finalizeUpload', [
                'finalizeUploadRequest' => [
                    'video' => $state['video'],
                    'uploadToken' => (string) ($state['upload_token'] ?? ''),
                    'uploadedPartIds' => array_values((array) ($state['etags'] ?? [])),
                ],
            ]);
        } catch (ConnectionException $e) {
            if (TemporaryException::requestNeverSent($e)) {
                throw TemporaryException::connection(Platform::LinkedIn, $e);
            }

            return $this->pending(array_replace($state, ['phase' => 'finalize_video']), 15);
        }

        if (! $this->ok($finalize)) {
            $status = $this->videoStatusOrNull($post, (string) $state['video']);

            if (! in_array($status, ['PROCESSING', 'AVAILABLE'], true)) {
                throw $this->mapError($finalize, 'upload', $author);
            }
        }

        return $this->awaitVideo($state);
    }

    /**
     * finalize_video: the parts are up but the finalize answer was lost. Read
     * the status first: a finalized video is processing (or done) already.
     *
     * @param  array<string, mixed>  $state
     */
    protected function resumeFinalize(PreparedPost $post, array $state): PublishOutcome
    {
        $info = $this->videoStatus($post, $this->stateUrn($state, 'video'));

        return match ($info['status']) {
            'PROCESSING', 'AVAILABLE' => $this->awaitVideo($state),
            'PROCESSING_FAILED' => throw $this->processingFailed('video', $info),
            default => $this->finalizeVideo($post, null, $state),
        };
    }

    /**
     * Move to await_video, keeping only what the wait and the create need.
     *
     * @param  array<string, mixed>  $state
     */
    protected function awaitVideo(array $state): Pending
    {
        $deadline = time() + $this->processingTimeout();

        $next = array_filter([
            'phase' => 'await_video',
            'video' => $state['video'],
            'commentary' => $state['commentary'] ?? null,
            'deadline' => $deadline,
        ], static fn ($value) => $value !== null);

        return PublishOutcome::pending($next, 10, deadline: $deadline);
    }

    /**
     * Non-fatal: LinkedIn serves the video with its default frame when the
     * thumbnail upload fails.
     */
    protected function uploadVideoThumbnail(PreparedPost $post, Media $video, string $url): void
    {
        try {
            $bytes = $this->bytes(Media::image((string) $video->thumbnail));

            $response = $this->http($post)
                ->timeout($this->uploadTimeout())
                ->withHeaders(['media-type-family' => 'STILLIMAGE'])
                ->withBody($bytes, 'application/octet-stream')
                ->put($url);

            if (! $this->ok($response)) {
                $this->logWarning('LinkedIn refused the video thumbnail; the video keeps its default frame.', ['http_status' => $response->status()]);
            }
        } catch (Throwable $e) {
            $this->logWarning('The LinkedIn video thumbnail could not be uploaded; the video keeps its default frame.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * await_video: poll GET /rest/videos/{urn} until AVAILABLE, then create.
     * v1.0 continuations carry only `video`.
     *
     * @param  array<string, mixed>  $state
     */
    protected function finishVideo(PreparedPost $post, array $state): PublishOutcome
    {
        $urn = $this->stateUrn($state, 'video');
        $deadline = $this->stateDeadline($state);
        $state['deadline'] = $deadline;
        $info = $this->videoStatus($post, $urn);

        return match ($info['status']) {
            'AVAILABLE' => $this->commitCreate($post, $this->stateCommentary($state), $this->videoContent($post, $urn), $this->createDeadline($deadline)),
            'PROCESSING_FAILED' => throw $this->processingFailed('video', $info),
            'WAITING_UPLOAD' => $this->waitForUpload($state, 'video', 15),
            null => throw $this->missingStatus('video', $info['raw']),
            default => $this->stillProcessing(array_diff_key($state, ['waiting_since' => true]), 'video', 15),
        };
    }

    /**
     * The post's media block for a processed video, with the post title and
     * the video's alt text when given.
     *
     * @return array{media: array<string, string>}
     */
    protected function videoContent(PreparedPost $post, string $urn): array
    {
        $media = ['id' => $urn];
        $title = trim((string) $post->title());

        if ($title !== '') {
            $media['title'] = $title;
        }

        foreach ($post->media() as $item) {
            if ($item->type === MediaType::Video) {
                $media += $this->altTextOf($item);
                break;
            }
        }

        return ['media' => $media];
    }

    /**
     * Videos API status: PROCESSING, WAITING_UPLOAD, AVAILABLE or
     * PROCESSING_FAILED (with processingFailureReason). A missing status is
     * reported as null, never assumed to be "processing".
     *
     * @return array{status: ?string, reason: ?string, raw: array<mixed>}
     */
    protected function videoStatus(PreparedPost $post, string $urn): array
    {
        $response = $this->rest($post)->get('videos/'.rawurlencode($urn));

        if (! $this->ok($response)) {
            throw $this->mapError($response, 'upload', $this->author($post));
        }

        return $this->assetStatusOf($response);
    }

    /** The video status, or null when it cannot be read (used to settle a refused finalize). */
    protected function videoStatusOrNull(PreparedPost $post, string $urn): ?string
    {
        try {
            return $this->videoStatus($post, $urn)['status'];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @deprecated since 1.1: read the Videos API through videoStatus(). Kept for
     *             subclasses; it no longer reads the legacy /v2/assets recipes.
     */
    protected function assetStatus(PreparedPost $post, string $videoUrn): string
    {
        return $this->videoStatus($post, $videoUrn)['status'] ?? 'PROCESSING';
    }

    /**
     * @deprecated since 1.1: the parts are uploaded in stages by
     *             uploadVideoParts(). Kept for subclasses: uploads every part in
     *             one go and returns the ETags.
     *
     * @param  array<int, array<string, mixed>>  $instructions
     * @return string[]
     */
    protected function uploadVideoChunks(PreparedPost $post, string $path, array $instructions): array
    {
        $etags = [];

        foreach ($this->normaliseInstructions($instructions, PHP_INT_MAX) as $index => $instruction) {
            array_push($etags, ...$this->putVideoParts($post, $path, [$index => $instruction], []));
        }

        return $etags;
    }

    /**
     * The init's upload plan, checked: a missing or malformed plan is a
     * temporary LinkedIn fault (a fresh init will bring a new one).
     *
     * @return list<array{uploadUrl: string, firstByte: int, lastByte: int}>
     */
    protected function uploadInstructions(Response $init, int $size): array
    {
        $instructions = $init->json('value.uploadInstructions');
        $normalised = is_array($instructions) ? $this->normaliseInstructions($instructions, $size) : [];

        if ($normalised === []) {
            throw new TemporaryException(
                'LinkedIn returned no usable upload instructions for the video.',
                Platform::LinkedIn,
                FailureReason::ServerError,
                null,
                ['http_status' => $init->status(), 'missing_field' => 'value.uploadInstructions'],
            );
        }

        return $normalised;
    }

    /**
     * @param  array<int, mixed>  $instructions
     * @return list<array{uploadUrl: string, firstByte: int, lastByte: int}>
     */
    protected function normaliseInstructions(array $instructions, int $size): array
    {
        $normalised = [];

        foreach (array_values($instructions) as $instruction) {
            $url = is_array($instruction) ? ($instruction['uploadUrl'] ?? null) : null;
            $first = is_array($instruction) ? ($instruction['firstByte'] ?? null) : null;
            $last = is_array($instruction) ? ($instruction['lastByte'] ?? null) : null;

            if (! is_string($url) || trim($url) === '' || ! is_numeric($first) || ! is_numeric($last)
                || (int) $first < 0 || (int) $last < (int) $first || (int) $last >= $size) {
                return [];
            }

            $normalised[] = ['uploadUrl' => $url, 'firstByte' => (int) $first, 'lastByte' => (int) $last];
        }

        return $normalised;
    }

    /** LinkedIn's feed video limits: 75 KB to 500 MB (checked before init, so no upload is wasted). */
    protected function assertVideoSize(int $size): void
    {
        $min = (int) $this->apiConfig('min_video_bytes', 76800);
        $max = (int) $this->apiConfig('max_video_bytes', 524288000);

        if ($size < $min || $size > $max) {
            throw new PermanentException(
                "LinkedIn accepts videos between {$min} and {$max} bytes; this one is {$size} bytes.",
                Platform::LinkedIn,
                FailureReason::MediaRejected,
                ['size' => $size, 'min_bytes' => $min, 'max_bytes' => $max],
            );
        }
    }

    protected function wantsThumbnail(Media $video): bool
    {
        return $video->thumbnail !== null
            && trim($video->thumbnail) !== ''
            && (bool) $this->apiConfig('upload_thumbnail', true);
    }

    /** The post's video, needed again by every upload run. */
    protected function videoMedia(PreparedPost $post): Media
    {
        foreach ($post->media() as $media) {
            if ($media->type === MediaType::Video) {
                return $media;
            }
        }

        throw new PermanentException(
            'The LinkedIn video upload was resumed for a post that carries no video.',
            Platform::LinkedIn,
            FailureReason::Misconfigured,
        );
    }

    /** @param array<string, mixed> $state */
    protected function uploadUrlsExpired(array $state): bool
    {
        $expiresAt = $state['upload_expires_at'] ?? null;

        if (! is_numeric($expiresAt)) {
            return false;
        }

        $seconds = (int) $expiresAt >= 1_000_000_000_000 ? intdiv((int) $expiresAt, 1000) : (int) $expiresAt;

        return $seconds <= time();
    }

    // --- Document (upload, then processing wait) -------------------------------

    protected function startDocument(PreparedPost $post, Media $document): PublishOutcome
    {
        try {
            $commentary = $this->commentaryFor($post);
            $upload = $this->initializeUpload($post, 'documents', 'document');

            $this->putUpload($post, $upload['uploadUrl'], $this->stream($document), 'a document');

            $deadline = time() + $this->processingTimeout();

            return PublishOutcome::pending([
                'phase' => 'await_document',
                'document' => $upload['urn'],
                'title' => $this->documentTitle($post, $document),
                'commentary' => $commentary,
                'deadline' => $deadline,
            ], 20, deadline: $deadline);
        } finally {
            $this->gateway()?->cleanup();
        }
    }

    /**
     * await_document: poll GET /rest/documents/{urn} until AVAILABLE, then
     * create. v1.0 continuations carry `document` and `title`.
     *
     * @param  array<string, mixed>  $state
     */
    protected function finishDocument(PreparedPost $post, array $state): PublishOutcome
    {
        $urn = $this->stateUrn($state, 'document');
        $deadline = $this->stateDeadline($state);
        $state['deadline'] = $deadline;

        $response = $this->rest($post)->get('documents/'.rawurlencode($urn));

        if (! $this->ok($response)) {
            throw $this->mapError($response, 'upload', $this->author($post));
        }

        $info = $this->assetStatusOf($response);
        $title = is_string($state['title'] ?? null) && trim($state['title']) !== '' ? $state['title'] : 'Document';

        return match ($info['status']) {
            'AVAILABLE' => $this->commitCreate($post, $this->stateCommentary($state), ['media' => ['title' => $title, 'id' => $urn]], $this->createDeadline($deadline)),
            'PROCESSING_FAILED' => throw $this->processingFailed('document', $info),
            'WAITING_UPLOAD' => $this->waitForUpload($state, 'document', 20),
            null => throw $this->missingStatus('document', $info['raw']),
            default => $this->stillProcessing(array_diff_key($state, ['waiting_since' => true]), 'document', 20),
        };
    }

    /** The post title, else the file's name without extension, else 'Document'. */
    protected function documentTitle(PreparedPost $post, Media $document): string
    {
        $title = trim((string) $post->title());

        if ($title !== '') {
            return $title;
        }

        $source = $document->diskPath() ?? $document->source;
        $path = parse_url($source, PHP_URL_PATH);
        $name = trim(rawurldecode(pathinfo(is_string($path) && $path !== '' ? $path : $source, PATHINFO_FILENAME)));

        return $name !== '' ? $name : 'Document';
    }

    // --- Processing waits (video and document) ----------------------------------

    /**
     * @return array{status: ?string, reason: ?string, raw: array<mixed>}
     */
    protected function assetStatusOf(Response $response): array
    {
        try {
            $raw = $response->json();
        } catch (Throwable) {
            $raw = null;
        }

        $raw = is_array($raw) ? $raw : [];
        $status = $raw['status'] ?? null;
        $reason = $raw['processingFailureReason'] ?? null;

        return [
            'status' => is_string($status) && trim($status) !== '' ? strtoupper(trim($status)) : null,
            'reason' => is_string($reason) && trim($reason) !== '' ? trim($reason) : null,
            'raw' => $raw,
        ];
    }

    /**
     * WAITING_UPLOAD can show briefly after an upload; lasting longer than
     * waiting_upload_timeout (5 minutes) means LinkedIn never got the file.
     * There is no automatic re-upload: it fails clearly instead.
     *
     * @param  array<string, mixed>  $state
     */
    protected function waitForUpload(array $state, string $kind, int $recheckAfter): Pending
    {
        $state['waiting_since'] = (int) ($state['waiting_since'] ?? time());
        $window = (int) $this->apiConfig('waiting_upload_timeout', 300);

        if (time() - $state['waiting_since'] >= $window) {
            throw new PermanentException(
                "LinkedIn never received the uploaded {$kind} (still WAITING_UPLOAD after {$window} seconds); please try again.",
                Platform::LinkedIn,
                FailureReason::MediaRejected,
                ['status' => 'WAITING_UPLOAD', 'waiting_seconds' => time() - $state['waiting_since']],
            );
        }

        return $this->stillProcessing($state, $kind, $recheckAfter);
    }

    /**
     * Keep waiting until the deadline (processing_timeout, an hour by default).
     * Nothing was posted yet, so running out is a clean failure.
     *
     * @param  array<string, mixed>  $state
     */
    protected function stillProcessing(array $state, string $kind, int $recheckAfter): Pending
    {
        $deadline = (int) $state['deadline'];

        if (time() > $deadline) {
            throw new PermanentException(
                "LinkedIn was still processing the {$kind} when the wait ran out.",
                Platform::LinkedIn,
                FailureReason::ProcessingTimeout,
                ['deadline' => $deadline],
            );
        }

        return PublishOutcome::pending($state, $recheckAfter, deadline: $deadline);
    }

    /** @param array{status: ?string, reason: ?string, raw: array<mixed>} $info */
    protected function processingFailed(string $kind, array $info): PermanentException
    {
        return new PermanentException(
            "LinkedIn could not process the {$kind}".($info['reason'] !== null ? " ({$info['reason']})" : '').'.',
            Platform::LinkedIn,
            FailureReason::MediaRejected,
            ['status' => 'PROCESSING_FAILED', 'processingFailureReason' => $info['reason']],
        );
    }

    /** @param array<mixed> $raw */
    protected function missingStatus(string $kind, array $raw): TemporaryException
    {
        return new TemporaryException(
            "LinkedIn returned no processing status for the {$kind}.",
            Platform::LinkedIn,
            FailureReason::ServerError,
            null,
            ['missing_field' => 'status', 'body' => array_slice($raw, 0, 20, true)],
        );
    }

    /** @param array<string, mixed> $state */
    protected function stateUrn(array $state, string $key): string
    {
        $urn = $state[$key] ?? null;

        if (! is_string($urn) || trim($urn) === '') {
            throw new PermanentException(
                "The LinkedIn continuation lost its {$key} URN.",
                Platform::LinkedIn,
                FailureReason::Unknown,
                ['missing_field' => $key],
            );
        }

        return trim($urn);
    }

    /**
     * The commentary fixed when the publish started; null for v1.0
     * continuations, which render it again from the post.
     *
     * @param  array<string, mixed>  $state
     */
    protected function stateCommentary(array $state): ?string
    {
        return is_string($state['commentary'] ?? null) ? $state['commentary'] : null;
    }

    /**
     * The processing deadline carried in the state; v1.0 continuations had
     * none, so theirs starts now.
     *
     * @param  array<string, mixed>  $state
     */
    protected function stateDeadline(array $state): int
    {
        return is_numeric($state['deadline'] ?? null)
            ? (int) $state['deadline']
            : time() + $this->processingTimeout();
    }

    // --- Create (the commit step) -----------------------------------------------

    /**
     * Hand the create over to its own commit step. The commentary is fixed here
     * (resolved mentions included), so every run of the step sends the same text.
     * $deadline (see createDeadline()) lets a create reached at the end of a
     * processing wait still run.
     *
     * @param  array<string, mixed>|null  $content
     */
    protected function commitCreate(PreparedPost $post, ?string $commentary, ?array $content, ?int $deadline = null): Pending
    {
        return $this->commit([
            'phase' => 'create_post',
            'commentary' => $commentary ?? $this->prepare($post)['commentary'],
            'content' => $content,
            'since' => time(),
        ], 0, $deadline);
    }

    /**
     * The deadline declared for a create (or verify) step: never earlier than
     * $after (the processing deadline it follows), and at least
     * CREATE_STEP_SECONDS from now. Without it the job applies the processing
     * deadline, and a video that turned AVAILABLE on the last look before it
     * would be failed as a timeout instead of posted.
     */
    protected function createDeadline(?int $after = null): int
    {
        return max($after ?? 0, time() + static::CREATE_STEP_SECONDS);
    }

    /**
     * create_post: POST /rest/posts. Not idempotent, and LinkedIn posts cannot be
     * read back with the posting scopes:
     *
     * - `_recovering` (an earlier run may have created it): look the post up when
     *   read scopes allow, else OutcomeUnknown with possibly_published;
     * - a connection failure that never sent the request: Temporary (safe retry);
     * - any other connection failure: OutcomeUnknown with possibly_published, or
     *   a verify step when read scopes allow.
     *
     * In a run after an ambiguous create (`_recovering`, or the verify step)
     * every failure is flagged possibly_published, since the earlier attempt may
     * have created the post whatever this run hits. The job dispatches the
     * verify step as an ordinary commit step, so without the flag a failure
     * there would be reported as a clean failure and the key forgotten.
     *
     * @param  array<string, mixed>  $state
     */
    protected function createPost(PreparedPost $post, array $state): PublishOutcome
    {
        $ambiguous = Pending::isRecovering($state) || ($state['verify'] ?? false) === true;

        try {
            return $this->runCreate($post, $state, $ambiguous);
        } catch (SocialPosterException $e) {
            throw $ambiguous ? $e->withFlags(['possibly_published' => true]) : $e;
        }
    }

    /**
     * The create step itself (see createPost()). After an ambiguous create, a
     * re-create LinkedIn refuses as a duplicate of a post names the post the
     * earlier attempt created: that post is the result (the read can miss it,
     * e.g. while it is still processing).
     *
     * @param  array<string, mixed>  $state
     */
    protected function runCreate(PreparedPost $post, array $state, bool $ambiguous): PublishOutcome
    {
        $this->assertTokenFresh($post);

        $content = is_array($state['content'] ?? null) ? $state['content'] : null;
        $payload = $this->postPayload($post, $content, $this->stateCommentary($state));
        $since = $this->attemptStartedAt($state);

        if ($ambiguous) {
            $existing = $this->findCreatedPost($post, $payload, $since);

            if ($existing !== null) {
                return $this->publishedPost($existing, ['recovered' => true]);
            }
        }

        try {
            $response = $this->rest($post)->post('posts', $payload);
        } catch (ConnectionException $e) {
            if (TemporaryException::requestNeverSent($e)) {
                throw TemporaryException::connection(Platform::LinkedIn, $e);
            }

            if ($this->canVerifyPosts($post)) {
                return $this->commit(
                    array_replace(Pending::withoutReservedKeys($state), ['verify' => true, 'since' => $since]),
                    30,
                    $this->createDeadline(),
                    possiblySent: true,
                );
            }

            throw new PermanentException(
                'The connection to LinkedIn was lost while creating the post, so it may have been published. LinkedIn posts cannot be read back with the posting permissions: check the account before posting again.',
                Platform::LinkedIn,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'connection' => true, 'request_sent' => true, 'phase' => 'create_post'],
                $e,
            );
        }

        if (! $this->ok($response)) {
            $error = $this->mapError($response, 'post', $payload['author']);
            $duplicateOf = $error->reason === FailureReason::DuplicateContent ? ($error->context['existing_urn'] ?? null) : null;

            if ($ambiguous && is_string($duplicateOf) && $duplicateOf !== '') {
                return $this->publishedPost($duplicateOf, ['recovered' => true, 'duplicate_refused' => true]);
            }

            throw $error;
        }

        $urn = $this->foreignId($response) ?? $this->requireId($response, 'post', 'id', ambiguous: true);

        return $this->publishedPost($urn);
    }

    /**
     * When the create was first attempted: the earliest of the step's own
     * `since` (set when the create was prepared, and carried unchanged into a
     * verify step) and the job's `_committing_since` (stamped again when a
     * verify step or a redelivered run starts a new commit run). The earliest
     * is a safe lower bound for the createdAt of the post being looked for.
     *
     * @param  array<string, mixed>  $state
     */
    protected function attemptStartedAt(array $state): int
    {
        $times = [];

        foreach (['since', Pending::COMMITTING_SINCE] as $key) {
            if (is_numeric($state[$key] ?? null)) {
                $times[] = (int) $state[$key];
            }
        }

        return $times === [] ? time() : min($times);
    }

    /**
     * After an ambiguous create: the post's URN when it exists, null when it
     * provably does not. OutcomeUnknown when it cannot be checked.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function findCreatedPost(PreparedPost $post, array $payload, int $since): ?string
    {
        if (! $this->canVerifyPosts($post)) {
            throw new PermanentException(
                'An earlier attempt to create this LinkedIn post was interrupted, so it may already be live. LinkedIn posts cannot be read back with the posting permissions: check the account before posting again (granting r_member_social / r_organization_social lets the package check).',
                Platform::LinkedIn,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'recovering' => true, 'phase' => 'create_post'],
            );
        }

        try {
            $response = $this->rest($post)
                ->withHeaders(['X-RestLi-Method' => 'FINDER'])
                ->get('posts', [
                    'q' => 'author',
                    'author' => $payload['author'],
                    'count' => 20,
                    'sortBy' => 'LAST_MODIFIED',
                    // AUTHOR also lists the latest version of a post not yet
                    // published to readers (PROCESSING, PUBLISH_REQUESTED).
                    'viewContext' => 'AUTHOR',
                ]);
        } catch (ConnectionException $e) {
            throw TemporaryException::connection(Platform::LinkedIn, $e)->addContext(['verify' => true]);
        }

        if (! $this->ok($response)) {
            throw new PermanentException(
                "An earlier attempt to create this LinkedIn post was interrupted, and LinkedIn refused to list the author's posts (HTTP {$response->status()}) to check: it may already be live.",
                Platform::LinkedIn,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'recovering' => true, 'verify_status' => $response->status()],
            );
        }

        $elements = $response->json('elements');

        foreach (is_array($elements) ? $elements : [] as $element) {
            if (is_array($element) && is_string($element['id'] ?? null) && $this->matchesPost($element, $payload, $since)) {
                return $element['id'];
            }
        }

        return null;
    }

    /**
     * Whether a listed post is the one we tried to create: created after the
     * attempt began (RECOVERY_SKEW_SECONDS of clock slack), the same commentary
     * (hashtag templates and escapes ignored), the same reshare parent (an
     * empty-commentary reshare is told apart by it alone) and, when both sides
     * have one, the same content.
     *
     * @param  array<string, mixed>  $element
     * @param  array<string, mixed>  $payload
     */
    protected function matchesPost(array $element, array $payload, int $since): bool
    {
        $created = $element['createdAt'] ?? $element['publishedAt'] ?? null;

        if (is_numeric($created) && (int) $created < ($since - static::RECOVERY_SKEW_SECONDS) * 1000) {
            return false;
        }

        if ($this->normaliseCommentary((string) ($element['commentary'] ?? '')) !== $this->normaliseCommentary((string) $payload['commentary'])) {
            return false;
        }

        if ($this->reshareParent($element) !== $this->reshareParent($payload)) {
            return false;
        }

        $wanted = $this->contentKey($payload['content'] ?? null);
        $found = $this->contentKey($element['content'] ?? null);

        return $wanted === null || $found === null || $wanted === $found;
    }

    /**
     * Commentary in a comparable form. LinkedIn returns the hashtags of a post
     * created with a plain "#coding" as little-text templates,
     * "{hashtag|\#|coding}" (Posts API, "Hashtag a keyword"; the full-width
     * "＃" form too), so templates become plain hashtags before escapes are
     * removed and whitespace is collapsed.
     */
    protected function normaliseCommentary(string $commentary): string
    {
        $plain = preg_replace('/\{hashtag\|\\\\?([#＃])\|((?:[^{}\\\\]|\\\\.)*)\}/u', '$1$2', $commentary) ?? $commentary;
        $plain = preg_replace('/\\\\(.)/su', '$1', $plain) ?? $plain;

        return trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
    }

    /**
     * The reshared post's URN, or null for a post that is not a reshare.
     *
     * @param  array<string, mixed>  $post  a listed post or a create payload
     */
    protected function reshareParent(array $post): ?string
    {
        $parent = data_get($post, 'reshareContext.parent');

        return is_string($parent) && trim($parent) !== '' ? trim($parent) : null;
    }

    /** A comparable identity for a content block (media URN, first image, article source, poll question). */
    protected function contentKey(mixed $content): ?string
    {
        if (! is_array($content)) {
            return null;
        }

        foreach (['media.id', 'multiImage.images.0.id', 'article.source', 'poll.question'] as $path) {
            $value = data_get($content, $path);

            if (is_string($value) && $value !== '') {
                return $path.'='.$value;
            }
        }

        return null;
    }

    /**
     * Verify reads need r_member_social / r_organization_social, which apps
     * often lack: on when the credentials list the matching scope, or when
     * api.linkedin.verify_reads is set.
     */
    protected function canVerifyPosts(PreparedPost $post): bool
    {
        if ((bool) $this->apiConfig('verify_reads', false)) {
            return true;
        }

        $scopes = $post->credentials->scopes();

        if ($scopes === null) {
            return false;
        }

        $scope = $this->authorType($post->credentials->get('author')) === 'organization'
            ? 'r_organization_social'
            : 'r_member_social';

        return in_array($scope, $scopes, true);
    }

    /** @param array<string, mixed> $payload */
    protected function publishedPost(string $urn, array $payload = []): Published
    {
        return $this->published(PostResult::success(
            Platform::LinkedIn,
            $urn,
            $this->postUrl($urn),
            ['urn' => $urn] + $payload,
            PostResult::STATE_PUBLISHED,
            'urn',
        ));
    }

    // --- Payload ----------------------------------------------------------------

    /**
     * The /rest/posts body. The driver owns author, commentary, visibility,
     * distribution, lifecycleState and isReshareDisabledByAuthor (so `extra`
     * cannot override them; use the typed options); `content` and
     * `reshareContext` are set only when the post has them, so `extra` can
     * still supply them for text posts.
     *
     * @param  array<string, mixed>|null  $content
     * @return array<string, mixed>
     */
    protected function postPayload(PreparedPost $post, ?array $content, ?string $commentary = null): array
    {
        $options = $this->options($post);

        $payload = [
            'author' => $this->author($post),
            'commentary' => $commentary ?? $this->prepare($post)['commentary'],
            'visibility' => $options->visibility ?? LinkedInOptions::VISIBILITY_PUBLIC,
            'distribution' => [
                'feedDistribution' => $options->feedDistribution ?? LinkedInOptions::FEED_MAIN,
                'targetEntities' => $options->targetEntities ?? [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => $options->disableReshare ?? false,
        ];

        if ($content !== null && $content !== []) {
            $payload['content'] = $content;
        }

        if ($options?->reshareOf !== null) {
            $payload['reshareContext'] = ['parent' => $options->reshareOf];
        }

        return $this->mergeExtra($post, $payload);
    }

    /**
     * @param  array<string, mixed>|null  $content
     * @return array<string, mixed>
     */
    protected function buildPost(PreparedPost $post, ?array $content): array
    {
        return $this->postPayload($post, $content);
    }

    /**
     * The commentary to send: prepare()'s little text, with linkedin.com profile
     * and company links turned into mentions first when autoMentionUrls is on.
     */
    protected function commentaryFor(PreparedPost $post): string
    {
        $resolved = $this->resolveMentionUrls($post);

        return $resolved === null
            ? $this->prepare($post)['commentary']
            : $this->renderMentions($resolved['caption'], $resolved['mentions']);
    }

    /**
     * autoMentionUrls: replace each resolvable linkedin.com/in|company|school link
     * with a {{mention:N}} marker and append the mention. A link that cannot be
     * resolved (unknown, no organization for a person, API error) stays a link.
     * Null when nothing was resolved, so the caption renders exactly as before.
     *
     * @return array{caption: string, mentions: list<LinkedInMention>}|null
     */
    protected function resolveMentionUrls(PreparedPost $post): ?array
    {
        $options = $this->options($post);

        if ($options === null || ! $options->autoMentionUrls) {
            return null;
        }

        // Hand-built little text: a rendered mention would get its text escaped.
        if ($options->mentions === [] && ! $options->escapeText) {
            return null;
        }

        $caption = (string) $post->caption();

        if (! mb_check_encoding($caption, 'UTF-8')) {
            return null;
        }

        $resolver = $this->mentionResolver();
        $token = $this->token($post);
        $organization = $this->mentionOrganization($post);
        $limit = max(0, (int) $this->apiConfig('max_auto_mentions', 10));
        $found = [];
        $cache = [];

        foreach (Urls::entities($caption) as $entity) {
            if (count($found) >= $limit) {
                break;
            }

            $url = $entity['url'];

            if (LinkedInMentionResolver::parseUrl($url) === null) {
                continue;
            }

            // Lookups that fail cost API calls too: at most twice the limit
            // distinct links are looked up during publish().
            if (! array_key_exists($url, $cache) && count($cache) >= 2 * $limit) {
                break;
            }

            if (! array_key_exists($url, $cache)) {
                try {
                    $cache[$url] = $resolver->fromUrl($token, Urls::absolute($url), $organization);
                } catch (Throwable $e) {
                    $cache[$url] = null;
                    $this->logWarning('A LinkedIn mention could not be resolved; the link is kept as text.', ['url' => $url, 'error' => $e->getMessage()]);
                }
            }

            $mention = $cache[$url];

            if ($mention instanceof LinkedInMention && $mention->hasValidUrn() && trim($mention->name) !== '') {
                $found[] = ['start' => $entity['start'], 'end' => $entity['end'], 'mention' => $mention];
            }
        }

        if ($found === []) {
            return null;
        }

        $mentions = $options->mentions;
        $markers = [];

        foreach ($found as $item) {
            $markers[] = $item + ['index' => count($mentions)];
            $mentions[] = $item['mention'];
        }

        // Replace from the end so earlier byte offsets stay valid.
        foreach (array_reverse($markers) as $marker) {
            $caption = substr_replace($caption, '{{mention:'.$marker['index'].'}}', $marker['start'], $marker['end'] - $marker['start']);
        }

        return ['caption' => $caption, 'mentions' => $mentions];
    }

    protected function mentionResolver(): LinkedInMentionResolver
    {
        if (app()->bound(LinkedInMentionResolver::class)) {
            return app(LinkedInMentionResolver::class);
        }

        return new LinkedInMentionResolver(
            (string) $this->apiConfig('version', '202605'),
            (int) $this->apiConfig('timeout', 15),
        );
    }

    /** The organization person lookups go through: an organization author, else credentials `organization`. */
    protected function mentionOrganization(PreparedPost $post): ?string
    {
        $author = $post->credentials->get('author');

        if ($this->authorType($author) === 'organization') {
            return trim((string) $author);
        }

        $organization = $post->credentials->get('organization');

        return is_string($organization) && $this->authorType($organization) === 'organization' ? trim($organization) : null;
    }

    // --- Link cards -------------------------------------------------------------

    /**
     * The article content for a text post: LinkedInOptions::$article as given,
     * or (autoLinkCard) a card built from the Open Graph tags of the first
     * non-LinkedIn link. Never fatal: a card that cannot be built is skipped,
     * a thumbnail that cannot be uploaded is dropped.
     *
     * @return array{article: array<string, string>}|null
     */
    protected function linkCard(PreparedPost $post): ?array
    {
        $options = $this->options($post);

        if ($options === null) {
            return null;
        }

        $article = $options->article;

        if ($article === null && $options->autoLinkCard) {
            $article = $this->articleFromCaption((string) $post->caption());
        }

        if ($article === null || $article->errors() !== []) {
            return null;
        }

        $thumbnail = $article->thumbnail !== null && trim($article->thumbnail) !== ''
            ? $this->uploadCardThumbnail($post, trim($article->thumbnail))
            : null;

        return $article->toContent($thumbnail);
    }

    protected function articleFromCaption(string $caption): ?LinkedInArticle
    {
        $url = $this->firstCardLink($caption);

        if ($url === null) {
            return null;
        }

        try {
            $graph = $this->fetchOpenGraph($url);
        } catch (Throwable $e) {
            $this->logWarning('The LinkedIn link card could not be built; posting without it.', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if ($graph === null || ($graph['title'] ?? null) === null) {
            return null;
        }

        return new LinkedInArticle($url, $graph['title'], $graph['description'] ?? null, $graph['image'] ?? null);
    }

    /** The first link in the caption that is not a LinkedIn page (those become mentions, not cards). */
    protected function firstCardLink(string $caption): ?string
    {
        foreach (Urls::extract($caption) as $url) {
            $absolute = Urls::absolute($url);
            $host = strtolower((string) parse_url($absolute, PHP_URL_HOST));

            if ($host === '' || $host === 'lnkd.in' || $host === 'linkedin.com' || str_ends_with($host, '.linkedin.com')) {
                continue;
            }

            return $absolute;
        }

        return null;
    }

    /**
     * Title, description and image of a page from its Open Graph (then Twitter,
     * then plain HTML) tags. The URL passes the SSRF guard first, redirects are
     * re-checked, and the fetch is short (link_card_timeout). Only an HTML page
     * is read, and only its first megabyte: a caption linking to a large file
     * must never load it into memory (cardFetchOptions() also aborts such a
     * download on the wire).
     *
     * @return array{title?: string, description?: string, image?: string}|null
     */
    protected function fetchOpenGraph(string $url): ?array
    {
        $types = ['text/html', 'application/xhtml+xml'];
        $response = $this->fetchCardResource($url, $types, static::CARD_PAGE_MAX_DOWNLOAD, ['Accept' => 'text/html,application/xhtml+xml']);

        if (! $response->successful() || ! $this->isCardType($response->header('Content-Type'), $types)) {
            return null;
        }

        $html = $this->readCapped($response, static::CARD_PAGE_READ_BYTES);

        return $html !== null ? $this->parseOpenGraph($html['bytes'], $url) : null;
    }

    /**
     * GET a link-card page or image through the SSRF guard (redirects re-checked,
     * the checked address pinned), with the transfer bounded by cardFetchOptions().
     *
     * @param  list<string>  $types
     * @param  array<string, string>  $headers
     */
    protected function fetchCardResource(string $url, array $types, int $maxBytes, array $headers = []): Response
    {
        PublicUrl::check($url);

        $options = $this->cardFetchOptions($types, $maxBytes);

        if (PublicUrl::blocksPrivateNetworks()) {
            $options += PublicUrl::curlOptions(PublicUrl::resolvePinned($url));
        }

        return Http::connectTimeout(5)
            ->timeout(max(1, (int) $this->apiConfig('link_card_timeout', 8)))
            ->withHeaders($headers + ['User-Agent' => 'Mozilla/5.0 (compatible; SocialPoster link preview)'])
            ->withOptions($options)
            ->get($url);
    }

    /**
     * Guzzle options that stop a card download early: at the headers when the
     * Content-Type is not one of $types or the announced size is over
     * $maxBytes, and while receiving once more than $maxBytes arrived. An abort
     * surfaces as an exception, which the callers turn into "no card". (No
     * 'stream' option: it would switch Guzzle to its stream handler, which
     * ignores the cURL address pin.)
     *
     * @param  list<string>  $types  accepted Content-Type prefixes (a missing type is accepted)
     * @return array<string, mixed>
     */
    protected function cardFetchOptions(array $types, int $maxBytes): array
    {
        return [
            'allow_redirects' => PublicUrl::redirectOptions(3),
            'on_headers' => function (ResponseInterface $response) use ($types, $maxBytes): void {
                $length = $response->getHeaderLine('Content-Length');

                if (! $this->isCardType($response->getHeaderLine('Content-Type'), $types)) {
                    throw new RuntimeException('The link card resource is not '.implode(' or ', $types).'.');
                }

                if (is_numeric($length) && (int) $length > $maxBytes) {
                    throw new RuntimeException("The link card resource is larger than {$maxBytes} bytes.");
                }
            },
            'progress' => static function ($total, $downloaded) use ($maxBytes): void {
                if ((int) $total > $maxBytes || (int) $downloaded > $maxBytes) {
                    throw new RuntimeException("The link card resource is larger than {$maxBytes} bytes.");
                }
            },
        ];
    }

    /**
     * Whether a Content-Type header is one of the accepted prefixes. A missing
     * type is accepted (the body decides).
     *
     * @param  list<string>  $types
     */
    protected function isCardType(?string $header, array $types): bool
    {
        $type = strtolower(trim(explode(';', (string) $header)[0]));

        if ($type === '') {
            return true;
        }

        foreach ($types as $prefix) {
            if (str_starts_with($type, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * At most $maxBytes of a response body, read from its stream so a large body
     * is never turned into one PHP string; `truncated` says more was available.
     * Null when the body cannot be read.
     *
     * @return array{bytes: string, truncated: bool}|null
     */
    protected function readCapped(Response $response, int $maxBytes): ?array
    {
        try {
            $stream = $response->toPsrResponse()->getBody();

            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            $bytes = '';

            while (strlen($bytes) <= $maxBytes && ! $stream->eof()) {
                $chunk = $stream->read(min(65536, $maxBytes + 1 - strlen($bytes)));

                if ($chunk === '') {
                    break;
                }

                $bytes .= $chunk;
            }

            return ['bytes' => substr($bytes, 0, $maxBytes), 'truncated' => strlen($bytes) > $maxBytes];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Parsed with regular expressions on purpose: no ext-dom dependency, and only
     * a handful of <meta> tags and <title> are needed.
     *
     * @return array{title?: string, description?: string, image?: string}
     */
    protected function parseOpenGraph(string $html, string $pageUrl): array
    {
        $meta = [];

        if (preg_match_all('/<meta\b[^>]*>/i', $html, $tags) > 0) {
            foreach ($tags[0] as $tag) {
                $attributes = [];

                if (preg_match_all('/([a-zA-Z:_-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/', $tag, $pairs, PREG_SET_ORDER) > 0) {
                    foreach ($pairs as $pair) {
                        $attributes[strtolower($pair[1])] = $pair[2] !== '' ? $pair[2] : (($pair[3] ?? '') !== '' ? $pair[3] : ($pair[4] ?? ''));
                    }
                }

                $key = strtolower($attributes['property'] ?? $attributes['name'] ?? '');
                $content = trim(html_entity_decode($attributes['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if ($key !== '' && $content !== '' && ! isset($meta[$key])) {
                    $meta[$key] = $content;
                }
            }
        }

        $title = $meta['og:title'] ?? $meta['twitter:title'] ?? null;

        if ($title === null && preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $match) === 1) {
            $title = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $description = $meta['og:description'] ?? $meta['twitter:description'] ?? $meta['description'] ?? null;
        $image = $meta['og:image'] ?? $meta['og:image:url'] ?? $meta['og:image:secure_url'] ?? $meta['twitter:image'] ?? null;

        return array_filter([
            'title' => $title !== null && $title !== '' ? $this->clip($title, (int) $this->apiConfig('link_card_title_max', 200)) : null,
            'description' => $description !== null ? $this->clip($description, (int) $this->apiConfig('link_card_description_max', 300)) : null,
            'image' => $image !== null ? $this->absoluteUrl($image, $pageUrl) : null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Upload a card thumbnail and return its image URN; an existing image URN
     * is used as is. Null (card without thumbnail) on any failure.
     */
    protected function uploadCardThumbnail(PreparedPost $post, string $thumbnail): ?string
    {
        if (preg_match('/^urn:li:(image|digitalmediaAsset):/', $thumbnail) === 1) {
            return $thumbnail;
        }

        try {
            $bytes = preg_match('#^https?://#i', $thumbnail) === 1
                ? $this->fetchRemoteImage($thumbnail)
                : $this->bytes(Media::image($thumbnail));

            return $bytes !== null ? $this->uploadImageBytes($post, $bytes) : null;
        } catch (Throwable $e) {
            $this->logWarning('The LinkedIn link card thumbnail could not be uploaded; posting the card without it.', ['error' => $e->getMessage()]);

            return null;
        } finally {
            $this->gateway()?->cleanup();
        }
    }

    /**
     * A remote image's bytes through the SSRF guard, capped in size
     * (link_card_image_max_bytes): an image announced or found larger is
     * skipped without being read whole.
     */
    protected function fetchRemoteImage(string $url): ?string
    {
        $max = max(1, (int) $this->apiConfig('link_card_image_max_bytes', 5_242_880));
        $response = $this->fetchCardResource($url, ['image/'], $max);
        $length = $response->header('Content-Length');

        if (! $response->successful() || ! $this->isCardType($response->header('Content-Type'), ['image/'])
            || (is_numeric($length) && (int) $length > $max)) {
            return null;
        }

        $body = $this->readCapped($response, $max);

        return $body !== null && ! $body['truncated'] && $body['bytes'] !== '' ? $body['bytes'] : null;
    }

    protected function absoluteUrl(string $url, string $base): string
    {
        $url = trim($url);

        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($url, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$url;
        }

        if (str_starts_with($url, '/')) {
            return $origin.$url;
        }

        $directory = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $origin.($directory === '' || $directory === '.' ? '' : $directory).'/'.$url;
    }

    protected function clip(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return $max > 0 && mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }

    // --- Comments -----------------------------------------------------------------

    /**
     * 1,250 characters, sent as plain text (no little-text escaping), at least a
     * minute after the post: a brand-new post answers 404 until its activity
     * reaches the comments API.
     */
    public function commentRules(PreparedPost $post, ?PostResult $parent = null): ?CommentRules
    {
        if ($parent !== null && (! $parent->success || ! $parent->isPublished())) {
            return null;
        }

        return new CommentRules(
            max: (int) $this->apiConfig('comment_max_characters', 1250),
            minDelaySeconds: max(0, (int) $this->apiConfig('comment_min_delay', 60)),
            requiredScopes: $this->requiredScopes(Feature::Comment, $post->credentials),
        );
    }

    /**
     * POST /rest/socialActions/{post urn}/comments as the author. A 404 (the
     * activity has not propagated yet) and throttles are temporary. A lost
     * response is not retried blindly: the comment may exist, so it fails with
     * OutcomeUnknown (possibly_published) unless the request provably never left.
     *
     * In a recovery (the comment job passes the parent with payload
     * `_recovering`: an earlier attempt got a 5xx, or its worker died
     * mid-POST), the post's comments are read first and the author's comment
     * with this text is returned (payload recovered = true) instead of posting
     * it again: see findOwnComment().
     */
    public function addFirstComment(PreparedPost $context, PostResult $parent, string $text): CommentResult
    {
        $this->assertCredentials($context);
        $author = $this->author($context);
        $urn = trim((string) $parent->platformPostId);

        if ($urn === '') {
            throw new PermanentException(
                'LinkedIn needs the post URN to add a comment, and the published result carries none.',
                Platform::LinkedIn,
                FailureReason::NotFound,
                ['missing_field' => 'platformPostId'],
            );
        }

        if (Pending::isRecovering($parent->payload)) {
            $found = $this->findOwnComment($context, $urn, $author, $text);

            if ($found !== null) {
                return $found;
            }
        }

        try {
            $response = $this->rest($context)->post('socialActions/'.rawurlencode($urn).'/comments', [
                'actor' => $author,
                'object' => $urn,
                // Plain text: the Comments API does not use little-text escaping.
                'message' => ['text' => $text],
            ]);
        } catch (ConnectionException $e) {
            if (TemporaryException::requestNeverSent($e)) {
                throw TemporaryException::connection(Platform::LinkedIn, $e);
            }

            throw new PermanentException(
                'The connection to LinkedIn was lost while adding the comment, so it may have been posted; check the post before adding it again.',
                Platform::LinkedIn,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'connection' => true, 'operation' => 'comment'],
                $e,
            );
        }

        if (! $this->ok($response)) {
            throw $this->mapError($response, 'comment', $author);
        }

        $body = $this->jsonBody($response);
        $bodyId = $body['id'] ?? null;
        $commentId = $this->foreignId($response) ?? (is_scalar($bodyId) && trim((string) $bodyId) !== '' ? (string) $bodyId : null);
        $commentUrn = $body['commentUrn'] ?? $body['$URN'] ?? null;
        $commentUrn = is_string($commentUrn) && trim($commentUrn) !== '' ? trim($commentUrn) : null;

        return new CommentResult(
            $commentId ?? $commentUrn,
            $commentUrn !== null ? $this->postUrl($urn).'?commentUrn='.rawurlencode($commentUrn) : null,
            array_filter([
                'comment_id' => $commentId,
                'comment_urn' => $commentUrn,
                'response' => $body,
            ], static fn ($value) => $value !== null && $value !== []),
        );
    }

    /**
     * The author's comment with $text on $urn (GET
     * /rest/socialActions/{urn}/comments), or null when there is none. Reading
     * comments needs r_organization_social_feed (pages) or the restricted
     * r_member_social_feed (members): without it, or on any other refusal,
     * this throws OutcomeUnknown with possibly_published = true, because
     * posting again without having looked could comment twice. A temporary
     * failure (or a connection error) propagates, so the job retries the
     * recovery.
     */
    protected function findOwnComment(PreparedPost $context, string $urn, string $author, string $text): ?CommentResult
    {
        $response = $this->rest($context)->get('socialActions/'.rawurlencode($urn).'/comments');

        if (! $this->ok($response)) {
            $error = $this->mapError($response, 'comment', $author);

            if ($error instanceof TemporaryException) {
                throw $error;
            }

            throw new PermanentException(
                'An earlier attempt may already have posted this LinkedIn comment, and the post\'s comments could not be read to check ('.$error->getMessage().'). Check the post before commenting again.',
                Platform::LinkedIn,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'recovering' => true, 'operation' => 'comment', 'lookup_reason' => $error->reason->value],
                $error,
            );
        }

        $wanted = trim(str_replace("\r\n", "\n", $text));
        $elements = $this->jsonBody($response)['elements'] ?? null;

        foreach (is_array($elements) ? $elements : [] as $element) {
            if (! is_array($element)
                || ($element['actor'] ?? null) !== $author
                || ! is_string($element['message']['text'] ?? null)
                || trim(str_replace("\r\n", "\n", $element['message']['text'])) !== $wanted) {
                continue;
            }

            $id = is_scalar($element['id'] ?? null) && trim((string) $element['id']) !== '' ? trim((string) $element['id']) : null;
            $commentUrn = $element['commentUrn'] ?? $element['$URN'] ?? null;
            $commentUrn = is_string($commentUrn) && trim($commentUrn) !== '' ? trim($commentUrn) : null;

            return new CommentResult(
                $id ?? $commentUrn,
                $commentUrn !== null ? $this->postUrl($urn).'?commentUrn='.rawurlencode($commentUrn) : null,
                array_filter([
                    'comment_id' => $id,
                    'comment_urn' => $commentUrn,
                    'recovered' => true,
                ], static fn ($value) => $value !== null),
            );
        }

        return null;
    }

    /**
     * Legacy SupportsComments contract (deprecated): returns the bare comment id
     * (x-restli-id) like v1.0, else the comment URN.
     */
    public function comment(PreparedPost $context, string $postId, string $comment): ?string
    {
        return $this->addFirstComment($context, PostResult::success(Platform::LinkedIn, $postId), $comment)->id;
    }

    // --- Credentials and scopes ----------------------------------------------------

    protected function requiredCredentials(): array
    {
        return [
            'access_token' => 'OAuth access token',
            'author' => 'author URN: urn:li:person:… or urn:li:organization:…',
        ];
    }

    protected function identityKeys(): array
    {
        return ['author'];
    }

    /**
     * Posting: w_member_social (member) or w_organization_social (page, plus a
     * page role). Commenting: the *_social_feed scope, or *_social, which also
     * covers comments. Unknown author: any of them.
     */
    protected function requiredScopes(Feature $feature, Credentials $credentials): array
    {
        $type = $this->authorType($credentials->get('author'));

        return match ($feature) {
            Feature::Publish => match ($type) {
                'organization' => [['w_organization_social']],
                'person' => [['w_member_social']],
                default => [['w_member_social', 'w_organization_social']],
            },
            Feature::Comment => match ($type) {
                'organization' => [['w_organization_social_feed', 'w_organization_social']],
                'person' => [['w_member_social_feed', 'w_member_social']],
                default => [['w_member_social_feed', 'w_member_social', 'w_organization_social_feed', 'w_organization_social']],
            },
            default => [],
        };
    }

    /**
     * The validated author URN.
     *
     * @throws PermanentException Misconfigured when it is missing or malformed.
     */
    protected function author(PreparedPost $post): string
    {
        $author = $post->credentials->get('author');
        $author = is_string($author) ? trim($author) : '';

        if (! $this->isValidAuthor($author)) {
            throw new PermanentException(
                $author === ''
                    ? 'The LinkedIn credentials need an `author` URN (urn:li:person:… or urn:li:organization:…).'
                    : 'The LinkedIn `author` credential must be urn:li:person:… or urn:li:organization:…; got "'.mb_substr($author, 0, 80).'".',
                Platform::LinkedIn,
                FailureReason::Misconfigured,
                ['credential' => 'author'],
            );
        }

        return $author;
    }

    protected function isValidAuthor(string $author): bool
    {
        return preg_match(self::AUTHOR_PATTERN, trim($author)) === 1;
    }

    /** 'person', 'organization', or null when unknown or malformed. */
    protected function authorType(mixed $author): ?string
    {
        if (! is_string($author) || ! $this->isValidAuthor($author)) {
            return null;
        }

        return str_starts_with(trim($author), 'urn:li:organization:') ? 'organization' : 'person';
    }

    // --- Helpers ---------------------------------------------------------------

    protected function options(PreparedPost $post): ?LinkedInOptions
    {
        return $this->optionsOf($post, LinkedInOptions::class);
    }

    /**
     * Escape LinkedIn's little-text reserved characters: \ | ( ) [ ] { } @ * < > _ ~
     * always, and # when it does not start a hashtag (C#, "# 1", a trailing #).
     * Invalid UTF-8 is escaped byte-wise rather than left unescaped.
     */
    protected function escape(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            $escaped = preg_replace('/[\\\\|()\[\]{}@*<>_~]|#(?![\p{L}\p{N}_])/u', '\\\\$0', $text);

            if ($escaped !== null) {
                return $escaped;
            }
        }

        return (string) preg_replace('/[\\\\|()\[\]{}@*<>_~]|#(?![A-Za-z0-9_\x80-\xFF])/', '\\\\$0', $text);
    }

    /** @return PendingRequest */
    protected function rest(PreparedPost $post)
    {
        return $this->http($post)
            ->withHeaders([
                'X-Restli-Protocol-Version' => '2.0.0',
                'LinkedIn-Version' => $this->version(),
            ])
            ->baseUrl($this->rest);
    }

    protected function version(): string
    {
        return (string) $this->apiConfig('version', '202605');
    }

    /**
     * Build the public post URL from the share/ugcPost urn. Deterministic, so no
     * extra API call is needed.
     */
    protected function postUrl(?string $urn): ?string
    {
        return $urn ? 'https://www.linkedin.com/feed/update/'.$urn.'/' : null;
    }

    protected function foreignId($response): ?string
    {
        $id = trim((string) $response->header('x-restli-id'));

        if ($id === '') {
            $id = trim((string) $response->header('x-linkedin-id'));
        }

        return $id !== '' ? $id : null;
    }

    /** @deprecated since 1.1, unused: URNs are sent whole (url-encoded). */
    protected function urnId(string $urn): string
    {
        $parts = explode(':', $urn);

        return (string) end($parts);
    }

    protected function captionLimit(): int
    {
        return (int) $this->apiConfig('max_characters', $this->captionLimit);
    }

    protected function maxImages(): int
    {
        return (int) $this->apiConfig('max_images', 20);
    }

    protected function uploadTimeout(): int
    {
        return (int) $this->apiConfig('upload_timeout', config('social.upload_timeout', 300));
    }

    protected function processingTimeout(): int
    {
        return max(60, (int) $this->apiConfig('processing_timeout', 3600));
    }

    /** Parts sent at once (1 = one after another). Sequential when an authorizer is configured. */
    protected function uploadConcurrency(): int
    {
        $authorizer = config('social.authorizers.linkedin');

        if ($authorizer !== null && $authorizer !== '' && $authorizer !== false) {
            return 1;
        }

        return max(1, min(8, (int) $this->apiConfig('upload_concurrency', 1)));
    }

    /**
     * @param  mixed  $response
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

    /** @param array<string, mixed> $context */
    protected function logWarning(string $message, array $context = []): void
    {
        if (! (bool) config('social.error_logging', true)) {
            return;
        }

        try {
            $channel = config('social.error_log_channel');
            $message = '[SocialPoster] linkedin: '.$message;
            $context = ['platform' => Platform::LinkedIn->value] + $context;

            $channel ? Log::channel($channel)->warning($message, $context) : Log::warning($message, $context);
        } catch (Throwable) {
            // Logging must never break a publish.
        }
    }

    // --- Errors -------------------------------------------------------------------

    /**
     * LinkedIn's error table. Bodies look like {status, serviceErrorCode, code,
     * message} or carry errorDetails.inputErrors[] for validation failures.
     * $operation is 'post', 'upload' or 'comment' (404 and throttles differ for
     * comments); $author shapes the permission hint.
     *
     * v1.0's signature, so a subclass override written for 1.0 still loads:
     * mapError() forwards $operation and $author as extra arguments, read here
     * with func_get_args().
     *
     * @param  mixed  $response
     */
    protected function classifyError($response): TemporaryException|PermanentException
    {
        $args = func_get_args();
        $operation = is_string($args[1] ?? null) ? $args[1] : 'post';
        $author = is_string($args[2] ?? null) ? $args[2] : '';
        $status = (int) $response->status();
        $json = $this->jsonBody($response);
        $code = is_string($json['code'] ?? null) ? trim($json['code']) : '';
        $message = is_string($json['message'] ?? null) ? trim($json['message']) : '';
        $inputErrors = $this->inputErrors($json);
        $codes = array_values(array_filter(array_merge([$code], array_column($inputErrors, 'code'))));
        $context = array_filter([
            'status' => $status,
            'http_status' => $status,
            'code' => $code !== '' ? $code : null,
            'service_error_code' => $json['serviceErrorCode'] ?? null,
            'operation' => $operation,
            'error' => $json !== [] ? $json : null,
        ], static fn ($value) => $value !== null);

        if ($status === 426 || in_array('NONEXISTENT_VERSION', $codes, true) || in_array('VERSION_MISSING', $codes, true)) {
            return new PermanentException(
                "LinkedIn API version {$this->version()} is not active; set SOCIAL_LINKEDIN_VERSION (config social.api.linkedin.version) to a current YYYYMM version.".($message !== '' ? " LinkedIn said: {$message}" : ''),
                Platform::LinkedIn,
                FailureReason::Misconfigured,
                $context + ['linkedin_version' => $this->version()],
            );
        }

        if ($status === 401 || in_array($code, self::TOKEN_CODES, true)) {
            return new PermanentException(
                $message !== '' ? "LinkedIn rejected the access token: {$message}" : 'LinkedIn rejected the access token (HTTP 401); the account must be reconnected.',
                Platform::LinkedIn,
                FailureReason::InvalidToken,
                $context + array_filter([
                    'terminal' => $code === 'REVOKED_ACCESS_TOKEN' ? true : null,
                    'token_expired' => $code === 'EXPIRED_ACCESS_TOKEN' ? true : null,
                ]),
            );
        }

        if ($code === 'RESTRICTED_MEMBER' || str_contains($message, 'RESTRICTED_MEMBER')) {
            return new PermanentException(
                'LinkedIn has restricted this member'.($message !== '' ? ": {$message}" : '.'),
                Platform::LinkedIn,
                FailureReason::AccountRestricted,
                $context,
            );
        }

        if ($status === 429 || stripos($message, 'throttle') !== false) {
            return $this->throttled($response, $message, $operation, $context);
        }

        if (in_array('MEDIA_ASSET_WAITING_UPLOAD', $codes, true) || str_contains($message, 'MEDIA_ASSET_WAITING_UPLOAD')) {
            return new TemporaryException(
                'LinkedIn has not finished receiving the media yet.',
                Platform::LinkedIn,
                FailureReason::ServerError,
                20,
                $context,
            );
        }

        if (in_array('MEDIA_ASSET_PROCESSING_FAILED', $codes, true) || str_contains($message, 'MEDIA_ASSET_PROCESSING_FAILED')) {
            return new PermanentException(
                'LinkedIn could not process the media attached to the post.',
                Platform::LinkedIn,
                FailureReason::MediaRejected,
                $context,
            );
        }

        if (preg_match('/duplicate of (urn:li:[A-Za-z]+:\d+)/i', $message, $duplicate) === 1) {
            return new PermanentException(
                "LinkedIn refused the post as a duplicate of {$duplicate[1]}.",
                Platform::LinkedIn,
                FailureReason::DuplicateContent,
                $context + ['existing_urn' => $duplicate[1]],
            );
        }

        if ($status === 403 || $code === 'ACCESS_DENIED') {
            return new PermanentException(
                'LinkedIn refused the request'.($message !== '' ? ": {$message}" : ' (HTTP 403)').'. '.$this->permissionHint($operation, $author),
                Platform::LinkedIn,
                FailureReason::InsufficientPermissions,
                $context,
            );
        }

        if ($status === 404 && $operation === 'comment') {
            return new TemporaryException(
                'LinkedIn cannot find the new post for comments yet (its activity has not propagated); retrying.',
                Platform::LinkedIn,
                FailureReason::NotFound,
                60,
                $context + ['activity_not_ready' => true],
            );
        }

        if ($status === 408 || $status === 409) {
            return new TemporaryException(
                $message !== '' ? $message : ($status === 409 ? 'LinkedIn reported a write conflict; retrying.' : 'LinkedIn timed out; retrying.'),
                Platform::LinkedIn,
                FailureReason::ServerError,
                RetryAfter::fromResponse($response) ?? 30,
                $context,
            );
        }

        if ($inputErrors !== []) {
            $descriptions = array_values(array_filter(array_column($inputErrors, 'description')));
            $fields = array_values(array_filter(array_column($inputErrors, 'field')));
            $media = array_filter($fields, static fn ($field) => is_string($field) && str_starts_with($field, '/content')) !== [];

            return new PermanentException(
                $descriptions !== [] ? implode('; ', $descriptions) : ($message !== '' ? $message : 'LinkedIn rejected the post.'),
                Platform::LinkedIn,
                $media ? FailureReason::MediaRejected : FailureReason::InvalidContent,
                $context + [
                    'input_errors' => array_values(array_filter(array_column($inputErrors, 'code'))),
                    'field_paths' => $fields,
                ],
            );
        }

        return parent::classifyError($response)->addContext($context);
    }

    /**
     * Throttles. LinkedIn's daily limits ("Resource level throttle … DAY limit")
     * reset at midnight UTC: a reset further away than max_rate_limit_wait is a
     * PermanentException(RateLimited) with retry_at / retry_after_hint, so the
     * queue never sits on it. Short throttles (infrastructure protection,
     * "Comment create throttled", a one-minute limit) retry after Retry-After
     * or 60-300 seconds; comment waits are capped at 300.
     *
     * @param  mixed  $response
     * @param  array<string, mixed>  $context
     */
    protected function throttled($response, string $message, string $operation, array $context): TemporaryException|PermanentException
    {
        $retryAfter = RetryAfter::fromResponse($response);
        $daily = preg_match('/\bDAY\b/', $message) === 1 || stripos($message, 'Resource level throttle') !== false;
        $cap = max(0, (int) $this->apiConfig('max_rate_limit_wait', 3600));

        // A daily limit is never waited out in the queue past the cap (owner
        // decision 2), whether LinkedIn sends Retry-After or not.
        if ($daily && ($retryAfter === null || $retryAfter > $cap)) {
            $now = now('UTC')->getTimestamp();
            $reset = $retryAfter !== null ? $now + $retryAfter : (intdiv($now, 86400) + 1) * 86400;
            $wait = $retryAfter ?? $reset - $now + random_int(30, 300);

            if ($wait > $cap) {
                return new PermanentException(
                    "LinkedIn's daily limit for this action is reached; it resets at midnight UTC (".gmdate('Y-m-d H:i', $reset).' UTC).'.($message !== '' ? " LinkedIn said: {$message}" : ''),
                    Platform::LinkedIn,
                    FailureReason::RateLimited,
                    $context + [
                        'daily_limit' => true,
                        'retry_at' => gmdate('Y-m-d\TH:i:s\Z', $reset),
                        'retry_after_hint' => $reset - $now,
                    ],
                );
            }

            $retryAfter = $wait;
        }

        $retryAfter ??= $operation === 'comment' ? 60 : random_int(60, 300);

        if ($operation === 'comment') {
            $retryAfter = min($retryAfter, 300);
        }

        return new TemporaryException(
            $message !== '' ? $message : 'LinkedIn rate limit reached (HTTP 429).',
            Platform::LinkedIn,
            FailureReason::RateLimited,
            $retryAfter,
            $context + ['daily_limit' => $daily],
        );
    }

    /**
     * errorDetails.inputErrors[] as code / field path / description.
     *
     * @param  array<mixed>  $json
     * @return list<array{code: ?string, field: ?string, description: ?string}>
     */
    protected function inputErrors(array $json): array
    {
        $list = $json['errorDetails']['inputErrors'] ?? null;

        if (! is_array($list)) {
            return [];
        }

        $errors = [];

        foreach ($list as $error) {
            if (! is_array($error)) {
                continue;
            }

            $field = data_get($error, 'input.inputPath.fieldPath');

            $errors[] = [
                'code' => is_scalar($error['code'] ?? null) ? (string) $error['code'] : null,
                'field' => is_string($field) ? $field : null,
                'description' => is_string($error['description'] ?? null) && trim($error['description']) !== '' ? trim($error['description']) : null,
            ];
        }

        return $errors;
    }

    /** What to grant when LinkedIn says 403, by author type and operation. */
    protected function permissionHint(string $operation, string $author): string
    {
        $type = $this->authorType($author);

        if ($operation === 'comment') {
            return match ($type) {
                'organization' => 'Commenting as a page needs e.g. w_organization_social_feed (or w_organization_social) and an '.self::PAGE_COMMENT_ROLES.' role on it.',
                'person' => 'Commenting as a member needs e.g. w_member_social_feed (or w_member_social).',
                default => 'Commenting needs e.g. w_member_social_feed for a member, or w_organization_social_feed and a page role for an organization.',
            };
        }

        return match ($type) {
            'organization' => 'Posting as a page needs the w_organization_social scope and an '.self::PAGE_POST_ROLES.' role on it.',
            'person' => 'Posting as a member needs the w_member_social scope.',
            default => 'Posting needs w_member_social for a member, or w_organization_social and a page role for an organization.',
        };
    }

    // --- Rule sets -------------------------------------------------------------

    protected function imageRules(): ImageRules
    {
        return new ImageRules(
            extensions: ['jpg', 'jpeg', 'gif', 'png'],
            maxPixels: (int) $this->apiConfig('max_image_pixels', 36152319),
        );
    }

    protected function videoRules(): VideoRules
    {
        return new VideoRules(
            extensions: ['mp4'],
            maxSizeBytes: (int) $this->apiConfig('max_video_bytes', 524288000),
            minDuration: (float) $this->apiConfig('min_video_duration', 3),
            maxDuration: (float) $this->apiConfig('max_video_duration', 1800),
            minSizeBytes: (int) $this->apiConfig('min_video_bytes', 76800),
        );
    }

    protected function documentRules(): DocumentRules
    {
        return new DocumentRules(
            extensions: ['pdf', 'doc', 'docx', 'ppt', 'pptx'],
            maxSizeBytes: (int) $this->apiConfig('max_document_bytes', 104857600),
        );
    }
}
