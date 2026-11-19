<?php

namespace SocialPoster\Platforms\YouTube;

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\MessageBag;
use Psr\Http\Message\StreamInterface;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\CommentRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Capabilities\VideoRules;
use SocialPoster\Concerns\UploadsInChunks;
use SocialPoster\Contracts\SupportsFirstComment;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\Ingestion;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Gateways\LocalMediaGateway;
use SocialPoster\Jobs\PublishToConnectionJob;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\Support\PublicUrl;
use SocialPoster\Support\RetryAfter;
use SocialPoster\ValueObjects\CommentResult;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\PublishOutcome;
use Throwable;

/**
 * YouTube (Data API v3). Uploads one video with the resumable protocol, in
 * stages that survive worker timeouts, redeliveries and expired tokens:
 *
 * 1. publish() opens an upload session (videos.insert with the metadata) and
 *    returns Pending(stage 'upload') right away, so the session URI is stored
 *    before a single byte is sent. Opening a session spends one call of the
 *    videos.insert quota bucket (100 a day per project by default).
 * 2. resume('upload') sends the file in chunks (multiples of 256 KiB) for at
 *    most apiConfig('upload_run_seconds', 45) per run, then hands over to the
 *    next run with the confirmed offset. The bytes come from a Range GET on
 *    the source URL when the host supports it, else from a local file: the
 *    caller's own, or the MediaGateway's copy (a download, a copy from a
 *    cloud disk), made once and kept between runs as sp_youtube_<16 hex>
 *    until the upload ends. A run that may repeat
 *    earlier work (a retry, a redelivery) first asks the session where it is
 *    (an empty PUT whose Content-Range gives only the total size): 308
 *    resumes, 200/201 is the finished video (no duplicate), 404/410 opens a
 *    new session (at most 2 restarts) unless the last chunk may already have
 *    arrived (then OutcomeUnknown: the video may exist). Network errors and
 *    5xx keep the session and retry with backoff. A source URL read in ranges
 *    must stay valid for the whole upload (signed URLs such as S3 presigned
 *    or Notion file links expire): one that stops answering mid-upload fails
 *    with context source_url_expired. apiConfig('range_reads', true) = false
 *    downloads remote sources once in publish() instead.
 * 3. Optional stage 'finalize', only when needed: a likely Short with a
 *    thumbnail (YouTube applies a Short's thumbnail only after processing),
 *    waitForProcessing, or a thumbnail/playlist call that deserves one retry.
 *    It polls videos.list (1 quota unit) a bounded number of times; problems
 *    there are warnings, never a failed post, except a definitive rejection
 *    (duplicate, account suspended, too long...), which is reported with the
 *    video id in the exception context and possibly_published = false.
 *
 * The builder's ->title() becomes the video title (required, max 100
 * characters, no < or >) and ->caption() the description (max 5000 bytes, no
 * < or >). The thumbnail is YouTubeOptions::$thumbnail (validated: JPG or
 * PNG), else the video's own Media::video(thumbnail: ...), which is never
 * validated (1.0 ignored it) and is skipped with a thumbnail_warning when it
 * is not a JPG or PNG. A thumbnail never fails the post.
 *
 * Content-owner uploads (extra['query']['onBehalfOfContentOwner']) send that
 * parameter with the follow-up calls too (videos.list, thumbnails.set,
 * playlistItems).
 *
 * Credentials: access_token (Google tokens last about 1 hour: refresh with a
 * margin before dispatching, or bind a CredentialsResolver; long uploads need
 * one), optional channel_id (account identity, and saves a channels.list call
 * for comments), optional expires_at and scopes. Scopes: youtube.upload (or
 * youtube / youtube.force-ssl / youtubepartner) to upload; youtube.force-ssl for
 * first comments; youtube or youtube.force-ssl for playlists.
 *
 * Daily quota errors are permanent (FailureReason::RateLimited) with context
 * retry_at (next midnight Pacific Time, ISO-8601 UTC) and retry_after_hint, so
 * the app can reschedule: the queue never sleeps until the reset.
 *
 * Community posts are intentionally not supported: the Data API exposes no
 * endpoint to create them.
 */
class YouTubeDriver extends AbstractPlatform implements SupportsFirstComment
{
    use UploadsInChunks;

    /**
     * The videos.insert upload endpoint. Only the part before '?' is used since
     * 1.1: the query (uploadType, part, notifySubscribers...) is built per
     * request by uploadUrl().
     */
    protected string $resumable = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status';

    protected string $thumbnailEndpoint = 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set';

    /** Base of the non-upload Data API endpoints (videos.list, playlistItems, commentThreads, channels). */
    protected string $apiBase = 'https://www.googleapis.com/youtube/v3';

    protected int $titleLimit = 100;

    /** Top-level video resource parts videos.insert can write; the 'part' parameter is built from these. */
    protected const WRITABLE_PARTS = ['snippet', 'status', 'recordingDetails', 'localizations', 'paidProductPlacementDetails', 'brandPartner'];

    /** Resumable chunks must be multiples of this (256 KiB), except the last. */
    protected const CHUNK_GRANULARITY = 262144;

    protected const VIDEO_ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    protected const SCOPE_PREFIX = 'https://www.googleapis.com/auth/';

    /** Seconds added to a stage's own window to form the deadline the job enforces. */
    protected const DEADLINE_MARGIN = 600;

    /** Daily quotas: they reset at midnight Pacific Time. */
    protected const DAILY_QUOTA_REASONS = ['quotaExceeded', 'dailyLimitExceeded', 'variableTermExpiredDailyExceeded'];

    /** Short-term limits (per-minute / per-user / concurrency), including ErrorInfo and status spellings. */
    protected const SHORT_TERM_REASONS = [
        'rateLimitExceeded', 'userRateLimitExceeded', 'concurrentLimitExceeded', 'limitExceeded',
        'servingLimitExceeded', 'variableTermLimitExceeded', 'uploadRateLimitExceeded',
        'RATE_LIMIT_EXCEEDED', 'RESOURCE_EXHAUSTED',
    ];

    /** Field-level rejections (videos.insert and commentThreads.insert): reason => field. */
    protected const FIELD_REASONS = [
        'invalidTitle' => 'title',
        'invalidDescription' => 'description',
        'invalidTags' => 'tags',
        'invalidCategoryId' => 'categoryId',
        'invalidPublishAt' => 'publishAt',
        'defaultLanguageNotSet' => 'defaultLanguage',
        'invalidRecordingDetails' => 'recordingDetails',
        'invalidVideoGameRating' => 'videoGameRating',
        'invalidVideoMetadata' => 'metadata',
        'commentTextTooLong' => 'comment',
        'commentTextRequired' => 'comment',
        'invalidCommentThreadMetadata' => 'comment',
        'processingFailure' => 'comment',
    ];

    protected const MEDIA_REASONS = ['mediaBodyRequired', 'invalidFilename', 'invalidImage'];

    /** The project's API access is off: an operator fix, reconnecting will not help. */
    protected const DEVELOPER_CONFIG_REASONS = ['accessNotConfigured', 'SERVICE_DISABLED', 'API_KEY_SERVICE_BLOCKED', 'CONSUMER_INVALID'];

    /** Token rejections whatever the status ('expired', 'required', 'unauthorized' only count on a 401). */
    protected const TOKEN_REASONS = ['authError', 'UNAUTHENTICATED', 'ACCESS_TOKEN_EXPIRED', 'ACCESS_TOKEN_TYPE_UNSUPPORTED', 'CREDENTIALS_MISSING'];

    protected const SCOPE_REASONS = ['insufficientPermissions', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT'];

    /**
     * Thumbnail extensions YouTube takes. A thumbnail without an extension (a
     * CDN URL) is accepted too: the bytes decide (and the inspector's MIME,
     * when it reads one), and it is sent as application/octet-stream.
     */
    protected const THUMBNAIL_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    /** Finalize runs that wait for a re-resolved token before publishing with a follow_up_warning. */
    protected const FINALIZE_TOKEN_WAITS = 2;

    /** A source answering these mid-upload stopped accepting the URL (a signed URL expired). */
    protected const SOURCE_EXPIRED_STATUSES = [401, 403, 410];

    /** Copies of the source kept between upload runs (social-poster:prune-temp removes stale ones). */
    protected const STAGED_COPY_PATTERN = '/^sp_youtube_[0-9a-f]{16}(\.[a-z0-9]+)?$/';

    public function platform(): Platform
    {
        return Platform::YouTube;
    }

    protected function ingestion(): Ingestion
    {
        return Ingestion::Upload;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            // The description: 5000 BYTES (accents and emoji take 2-4 each).
            caption: new CaptionRules(max: 5000, maxBytes: (int) $this->apiConfig('description_max_bytes', 5000)),
            media: new MediaRules(minCount: 1, maxCount: 1, video: $this->videoRules()),
            supportsTitle: true,
        );
    }

    // --- Credentials and scopes ----------------------------------------------------

    protected function requiredCredentials(): array
    {
        return [$this->tokenKey => 'Google OAuth 2.0 access token with the youtube.upload scope (lasts about 1 hour)'];
    }

    protected function identityKeys(): array
    {
        return ['channel_id'];
    }

    protected function requiredScopes(Feature $feature, Credentials $credentials): array
    {
        return match ($feature) {
            // Video is covered by Publish (the only thing YouTube publishes).
            Feature::Publish => [$this->scopeGroup(['youtube.upload', 'youtube', 'youtube.force-ssl', 'youtubepartner'])],
            Feature::Comment => [$this->scopeGroup(['youtube.force-ssl'])],
            default => [],
        };
    }

    /**
     * An any-of scope group accepting Google's full scope URLs (what the token
     * endpoint returns) and the short names.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    protected function scopeGroup(array $names): array
    {
        return [...array_map(static fn (string $name) => self::SCOPE_PREFIX.$name, $names), ...$names];
    }

    // --- Validation ----------------------------------------------------------------

    protected function validatePlatformRules(PreparedPost $post, MessageBag $errors): void
    {
        $title = trim((string) $post->title());

        if ($title === '') {
            $errors->add('title', 'YouTube uploads need a title. Set it with ->title().');
        } elseif (mb_strlen($title) > $this->titleLimit) {
            $errors->add('title', "YouTube titles can't be longer than {$this->titleLimit} characters.");
        }

        if (preg_match('/[<>]/', $title) === 1) {
            $errors->add('title', "YouTube titles can't contain < or >.");
        }

        if (preg_match('/[<>]/', (string) $post->caption()) === 1) {
            $errors->add('caption', "YouTube descriptions can't contain < or >.");
        }

        $this->validateThumbnail($post, $errors);
        $this->validateExtra($post, $errors);
    }

    /**
     * Only YouTubeOptions::$thumbnail is validated. The shared
     * Media::video(thumbnail: ...) is not: 1.0 ignored it on YouTube, and other
     * platforms of the same fan-out accept covers YouTube cannot take (webp,
     * extension-less CDN URLs), so it must never refuse the post. An unusable
     * one is skipped with a thumbnail_warning when the upload finishes.
     */
    protected function validateThumbnail(PreparedPost $post, MessageBag $errors): void
    {
        $thumbnail = $this->options($post)?->thumbnail;

        if ($thumbnail === null) {
            return;
        }

        $extension = $thumbnail->extension();

        if ($extension !== '' && ! in_array($extension, self::THUMBNAIL_EXTENSIONS, true)) {
            $errors->add('thumbnail', 'A YouTube thumbnail must be a JPG or PNG image.');
        }

        $meta = $this->inspectMedia($thumbnail);
        $max = (int) $this->apiConfig('thumbnail_max_bytes', 52428800);

        if ($meta?->sizeBytes !== null && $meta->sizeBytes > $max) {
            $errors->add('thumbnail', "A YouTube thumbnail can't be larger than ".$this->humanBytes($max).'.');
        }

        if ($meta?->mimeType !== null && ! in_array(strtolower($meta->mimeType), ['image/jpeg', 'image/png', 'application/octet-stream'], true)) {
            $errors->add('thumbnail', "A YouTube thumbnail must be a JPG or PNG image (got {$meta->mimeType}).");
        }
    }

    protected function validateExtra(PreparedPost $post, MessageBag $errors): void
    {
        $extra = $this->extraPayload($post);
        $query = $extra['query'] ?? null;

        if ($query !== null && ! is_array($query)) {
            $errors->add('extra.query', "extra['query'] must be an array of videos.insert query parameters.");
        }

        if (is_array($query) && $this->filledString($query['onBehalfOfContentOwner'] ?? null) && ! $this->filledString($query['onBehalfOfContentOwnerChannel'] ?? null)) {
            $errors->add('extra.query', 'onBehalfOfContentOwner needs onBehalfOfContentOwnerChannel: the channel the video is uploaded to.');
        }

        if (isset($extra['localizations'])) {
            $language = $this->options($post)->defaultLanguage ?? ($extra['snippet']['defaultLanguage'] ?? null);

            if (! $this->filledString($language)) {
                $errors->add('extra.localizations', 'YouTube localizations need a default language: set YouTubeOptions::$defaultLanguage.');
            }
        }
    }

    // --- Publish: open the session ------------------------------------------------------

    public function publish(PreparedPost $post): PublishOutcome
    {
        $this->assertCredentials($post);
        $this->assertTokenFresh($post);

        $video = $this->videoOf($post);
        $staged = null;

        try {
            $source = $this->measureSource($video);
            $staged = $source['staged'];
            $mime = $this->videoMime($video);
            $body = $this->buildBody($post);
            $session = $this->openSession($post, $body, $source['size'], $mime);
            $now = time();

            $state = array_filter([
                'stage' => 'upload',
                'session' => $session,
                'size' => $source['size'],
                'mime' => $mime,
                'offset' => 0,
                'etag' => $source['etag'],
                'range_reads' => $source['range'],
                'staged' => $staged,
                'restarts' => 0,
                'stalls' => 0,
                'requested_privacy' => $this->requestedPrivacy($post),
                'likely_short' => $this->likelyShort($video),
                'ignored_parts' => $this->ignoredExtraParts($post),
                'upload_started_at' => $now,
                'deadline' => $now + $this->uploadWindowSeconds($source['size']),
            ], static fn ($value) => $value !== null && $value !== []);

            return PublishOutcome::pending($state, 0, false, $state['deadline']);
        } catch (Throwable $e) {
            // A retried publish() starts from nothing: it makes its own copy.
            $this->discardStaged(['staged' => $staged]);

            throw $e;
        } finally {
            $this->gateway()?->cleanup();
        }
    }

    /**
     * Open a resumable session for $body and return its URI.
     *
     * @param  array<string, mixed>  $body
     */
    protected function openSession(PreparedPost $post, array $body, int $size, string $mime): string
    {
        $response = $this->http($post)
            ->withHeaders([
                'X-Upload-Content-Length' => (string) $size,
                'X-Upload-Content-Type' => $mime,
            ])
            ->post($this->uploadUrl($post, $body), $body);

        if (! $this->ok($response)) {
            throw $this->mapError($response, 'upload');
        }

        $location = trim((string) $response->header('Location'));

        if ($location === '') {
            // No video exists yet, so asking for a new session is safe.
            throw new TemporaryException(
                'YouTube did not return a resumable upload URL.',
                Platform::YouTube,
                FailureReason::ServerError,
                null,
                ['http_status' => $response->status(), 'stage' => 'session'],
            );
        }

        if (! $this->isSessionUrl($location)) {
            // The bearer token is sent to this URL on every chunk: never to an unexpected host.
            throw new PermanentException(
                'YouTube returned an upload URL on an unexpected host; refusing to send the token there.',
                Platform::YouTube,
                FailureReason::Misconfigured,
                ['http_status' => $response->status(), 'session_host' => parse_url($location, PHP_URL_HOST)],
            );
        }

        return $location;
    }

    /** True for an https URL on Google's API hosts (or the configured upload endpoint's host). */
    protected function isSessionUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        $expected = strtolower((string) parse_url($this->uploadEndpoint(), PHP_URL_HOST));

        return $host === $expected || $host === 'googleapis.com' || str_ends_with($host, '.googleapis.com');
    }

    // --- Resume -------------------------------------------------------------------------

    /**
     * Stage 'upload' sends chunks; stage 'finalize' waits for processing and
     * runs the follow-ups (thumbnail, playlists). 'phase' is accepted as an
     * alias of 'stage'. Finalize never fails a live video for missing or
     * expired credentials: it publishes with a warning instead.
     */
    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        $stage = $state['stage'] ?? $state['phase'] ?? null;

        if ($stage === 'finalize') {
            return $this->resumeFinalize($post, $state);
        }

        $this->assertCredentials($post);

        if ($stage === 'upload') {
            return $this->resumeUpload($post, $state);
        }

        throw new PermanentException(
            'Unknown YouTube publish stage '.var_export($stage, true).'.',
            Platform::YouTube,
            FailureReason::Unknown,
            ['stage' => is_scalar($stage) ? $stage : null],
        );
    }

    /**
     * An upload run the queue killed (it may have been sending the last
     * chunk) runs again: a repeated run asks the session first, which returns
     * the video if the last chunk arrived, or the offset to continue from.
     *
     * @param  array<string, mixed>  $state
     */
    public function retriesKilledRun(array $state): bool
    {
        return ($state['stage'] ?? $state['phase'] ?? null) === 'upload';
    }

    /**
     * One upload run, and the end of the source copy kept between runs (see
     * uploadPath()) once no later run reads it: the upload finished (the
     * finalize stage never reads the video), failed for good, or starts over
     * from publish(). A retry, a stall or a new session keeps it.
     *
     * @param  array<string, mixed>  $state
     */
    protected function resumeUpload(PreparedPost $post, array $state): PublishOutcome
    {
        try {
            $outcome = $this->uploadRun($post, $state);
        } catch (Throwable $e) {
            if ($e instanceof PermanentException || ($e instanceof TemporaryException && ($e->context['restart'] ?? false) === true)) {
                $this->discardStaged($state);
            }

            throw $e;
        }

        if (! $outcome instanceof Pending || ($outcome->state['stage'] ?? null) !== 'upload') {
            $this->discardStaged($state);
        }

        return $outcome;
    }

    /**
     * Send chunks for one run. $state is updated in place when the run keeps
     * a new copy of the source ('staged'), so resumeUpload() can delete it.
     *
     * @param  array<string, mixed>  $state
     */
    protected function uploadRun(PreparedPost $post, array &$state): PublishOutcome
    {
        // A retried, redelivered or recovering run may have sent bytes the
        // state does not know about: ask the session before sending.
        $repeat = Pending::isRecovering($state) || (int) ($state[Pending::ATTEMPT] ?? 1) > 1;
        $state = Pending::withoutReservedKeys($state);
        $session = $state['session'] ?? null;
        $size = (int) ($state['size'] ?? 0);

        if (! is_string($session) || $session === '' || $size <= 0) {
            // Nothing exists on YouTube without a session: starting over is safe.
            throw new TemporaryException(
                'The YouTube upload state is incomplete; starting the upload over.',
                Platform::YouTube,
                FailureReason::Unknown,
                0,
                ['restart' => true, 'stage' => 'upload', 'possibly_published' => false],
            );
        }

        $this->assertTokenFresh($post);

        $video = $this->videoOf($post);
        $offset = ! $repeat && is_int($state['offset'] ?? null) ? $state['offset'] : null;
        $source = ['mode' => ($state['range_reads'] ?? false) && $this->usesRangeReads($video) ? 'range' : 'path', 'path' => null];
        $runStarted = microtime(true);
        $started = $runStarted;
        $verified = false;
        $finalInFlight = false;

        try {
            if ($offset === null) {
                $verified = true;
                $response = $this->querySession($post, $state);
                $kind = $this->sessionResponseKind($response);

                if ($kind !== 'incomplete') {
                    return $this->settleSessionResponse($post, $state, $response, $kind, false, $repeat);
                }

                $offset = $this->committedOffset($response);
            }

            $chunk = $this->chunkBytes();
            $sent = 0;

            while ($offset < $size) {
                if ($sent > 0 && ! $this->budgetLeft($started)) {
                    break;
                }

                $length = min($chunk, $size - $offset);

                $staged = $source['path'] !== null;
                $body = $this->chunkBody($video, $state, $source, $offset, $length);

                if (! $staged && $source['path'] !== null) {
                    // Staging the whole file (a disk copy, a full download) is
                    // not upload time: counting it would leave a run that had
                    // to make the copy (again) only one chunk.
                    $started = microtime(true);
                }

                if ($offset + $length >= $size && ! $this->lastChunkFits($runStarted, $sent)) {
                    // A fresh run sends it, with the whole job timeout ahead.
                    break;
                }

                $finalInFlight = $offset + $length >= $size;
                $response = $this->sendChunk($post, $state, $offset, $length, $body);
                $finalInFlight = false;
                $sent++;

                $kind = $this->sessionResponseKind($response);

                if ($kind === 'error' && ! $verified && $this->mayBeStaleOffset($response)) {
                    // This run started from an offset it could not confirm (a
                    // retried run): ask the session where it really is.
                    $verified = true;
                    $response = $this->querySession($post, $state);
                    $kind = $this->sessionResponseKind($response);
                }

                if ($kind !== 'incomplete') {
                    return $this->settleSessionResponse($post, $state, $response, $kind, $offset + $length >= $size, $repeat);
                }

                $offset = $this->committedOffset($response);
                $state['stalls'] = 0;
                unset($state['final_sent']);
            }

            if ($offset >= $size) {
                // Every byte is acknowledged but no video came back yet: the
                // session holds the final response (unless this run just asked).
                if ($sent > 0 || ! $verified) {
                    $response = $this->querySession($post, $state);
                    $kind = $this->sessionResponseKind($response);

                    if ($kind !== 'incomplete') {
                        return $this->settleSessionResponse($post, $state, $response, $kind, true, $repeat);
                    }

                    $offset = $this->committedOffset($response);
                }

                if ($offset >= $size) {
                    return $this->stalled($state, null, true);
                }
            }

            return PublishOutcome::pending(
                [...$state, 'offset' => $offset, 'stalls' => 0],
                0,
                false,
                $this->deadlineOf($state),
            );
        } catch (ConnectionException $e) {
            // The session survives a dropped connection: keep it, and let the
            // next run ask it how many bytes arrived.
            return $this->stalled($state, null, $finalInFlight, $e);
        } finally {
            $this->gateway()?->cleanup();
        }
    }

    /**
     * Whether this run may still send the last chunk: its timeout plus one
     * HTTP call fit in what is left of the job timeout
     * (PublishToConnectionJob::configuredTimeout()), counted from the start
     * of the run (a slow staging copy included, which the per-run budget does
     * not count). A run the queue kills runs again (retriesKilledRun()) and
     * asks the session first, but only once the queue's retry_after has
     * passed, and only up to the job's tries; so a run that has already sent
     * chunks hands the last one to a fresh run instead. A run that has sent
     * nothing always sends it: the next run would start from the same point,
     * and handing over again could repeat forever.
     */
    protected function lastChunkFits(float $runStartedAt, int $sent): bool
    {
        if ($sent === 0) {
            return true;
        }

        $elapsed = microtime(true) - $runStartedAt;
        $reserve = max(1, (int) $this->apiConfig('timeout', config('social.http_timeout', 60)));

        return $elapsed + $this->chunkTimeout() + $reserve <= PublishToConnectionJob::configuredTimeout(Platform::YouTube);
    }

    /** Seconds one chunk PUT may take: api.youtube.upload_timeout, else social.upload_timeout (300). */
    protected function chunkTimeout(): int
    {
        return max(1, (int) $this->apiConfig('upload_timeout', config('social.upload_timeout', 300)));
    }

    /**
     * Empty status query on the session: a Content-Range of an unknown range
     * over the total size, no body, and no redirect following (a 308 is an
     * answer, not a redirect).
     *
     * @param  array<string, mixed>  $state
     * @return Response
     */
    protected function querySession(PreparedPost $post, array $state)
    {
        return $this->http($post)
            ->withoutRedirecting()
            ->withHeaders([
                'Content-Range' => 'bytes */'.(int) $state['size'],
                'Content-Length' => '0',
            ])
            ->withBody('', 'application/octet-stream')
            ->put((string) $state['session']);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return Response
     */
    protected function sendChunk(PreparedPost $post, array $state, int $offset, int $length, StreamInterface $body)
    {
        $end = $offset + $length - 1;

        return $this->http($post)
            ->timeout($this->chunkTimeout())
            ->withoutRedirecting()
            ->withHeaders([
                'Content-Range' => "bytes {$offset}-{$end}/".(int) $state['size'],
                'Content-Length' => (string) $length,
            ])
            ->withBody($body, (string) ($state['mime'] ?? 'application/octet-stream'))
            ->put((string) $state['session']);
    }

    /**
     * What a session response means:
     * done (200/201, the video resource), incomplete (308), expired (404/410),
     * retry (408/5xx: keep the session) or error (anything else, classified).
     *
     * @param  mixed  $response
     */
    protected function sessionResponseKind($response): string
    {
        $status = (int) $response->status();

        return match (true) {
            $status === 200 || $status === 201 => 'done',
            $status === 308 => 'incomplete',
            $status === 404 || $status === 410 => 'expired',
            $status === 408 || $status >= 500 => 'retry',
            default => 'error',
        };
    }

    /**
     * Turn a non-308 session response into the outcome: the uploaded video, a
     * new session, a backoff that keeps the session, or the classified error
     * (a 401 keeps the session too: the exception carries no new state).
     * $repeat: this run may repeat an earlier one that already ran the
     * follow-ups of a finished upload.
     *
     * @param  array<string, mixed>  $state
     * @param  mixed  $response
     */
    protected function settleSessionResponse(PreparedPost $post, array $state, $response, string $kind, bool $finalSent, bool $repeat = false): PublishOutcome
    {
        return match ($kind) {
            'done' => $this->uploaded($post, $state, $response, $repeat),
            'expired' => $this->restartSession($post, $state),
            'retry' => $this->stalled($state, RetryAfter::fromResponse($response), $finalSent, null, (int) $response->status()),
            default => throw $this->sessionError($response),
        };
    }

    /**
     * The classified error of a session response. When it is videos.insert's
     * own final verdict (a field or media rejection, the channel's upload
     * limit, the daily quota), no video was created, even after a final chunk
     * whose response was lost: possibly_published = false, so the job neither
     * reports the post as maybe live nor blocks its idempotency key. Token,
     * scope and unknown errors say nothing about the video and keep the job's
     * default.
     *
     * @param  mixed  $response
     */
    protected function sessionError($response): TemporaryException|PermanentException
    {
        $exception = $this->mapError($response, 'upload');
        $reason = $exception->context['reason'] ?? null;

        if ($exception instanceof PermanentException && is_string($reason) && $this->isInsertVerdict($reason)) {
            $exception->addContext(['possibly_published' => false]);
        }

        return $exception;
    }

    /** Whether a Google error reason is a definitive refusal of the videos.insert itself. */
    protected function isInsertVerdict(string $reason): bool
    {
        return isset(self::FIELD_REASONS[$reason])
            || in_array($reason, self::MEDIA_REASONS, true)
            || in_array($reason, self::DAILY_QUOTA_REASONS, true)
            || $reason === 'uploadLimitExceeded';
    }

    /**
     * A chunk refused with a client error by a run that could not confirm its
     * offset is first checked against the session (the bytes may already be
     * there). Auth, quota, permission and expiry answers are taken as they are.
     *
     * @param  mixed  $response
     */
    protected function mayBeStaleOffset($response): bool
    {
        $status = (int) $response->status();

        return $status >= 400 && $status < 500 && ! in_array($status, [401, 403, 404, 410, 429], true);
    }

    /**
     * The next byte to send after a 308: the end of its 'Range: bytes=0-N'
     * header plus one; no Range header means nothing was stored (0).
     *
     * @param  mixed  $response
     */
    protected function committedOffset($response): int
    {
        $range = (string) $response->header('Range');

        if (preg_match('/bytes\s*=\s*(\d+)\s*-\s*(\d+)/i', $range, $m) === 1) {
            return (int) $m[2] + 1;
        }

        return 0;
    }

    /**
     * A network error or 5xx on the session: keep the session, forget the
     * offset (the next run asks the session) and retry with exponential
     * backoff, or the server's Retry-After. After apiConfig('max_upload_stalls', 6)
     * in a row it becomes a TemporaryException, so the job's own retry budget
     * applies. When the last chunk may have arrived, the Pending is committed
     * (the video may exist).
     *
     * @param  array<string, mixed>  $state
     */
    protected function stalled(array $state, ?int $retryAfter, bool $finalSent, ?Throwable $exception = null, ?int $status = null): Pending
    {
        $stalls = (int) ($state['stalls'] ?? 0) + 1;
        $finalSent = $finalSent || (bool) ($state['final_sent'] ?? false);

        if ($stalls > max(1, (int) $this->apiConfig('max_upload_stalls', 6))) {
            $context = ['stage' => 'upload', 'stalls' => $stalls - 1, 'session_kept' => true] + ($finalSent ? ['possibly_published' => true] : []);

            if ($exception !== null) {
                throw TemporaryException::connection(Platform::YouTube, $exception)->addContext($context);
            }

            throw new TemporaryException(
                'The YouTube upload session kept failing'.($status !== null ? " (HTTP {$status})" : '').'; retrying later with the same session.',
                Platform::YouTube,
                FailureReason::ServerError,
                $retryAfter,
                $context + ['http_status' => $status],
            );
        }

        $delay = $retryAfter ?? min(300, 5 * (2 ** ($stalls - 1)));
        $next = [...$state, 'offset' => null, 'stalls' => $stalls];

        if ($finalSent) {
            $next['final_sent'] = true;
        }

        return PublishOutcome::pending($next, $this->clampDelay($delay), $finalSent, $this->deadlineOf($state));
    }

    /**
     * The session expired (404/410): open a new one and start again at byte 0.
     * Each new session spends a videos.insert call, so this happens at most
     * apiConfig('max_session_restarts', 2) times.
     *
     * Never after the last chunk may have arrived (final_sent): a finished
     * session replays its video only while it lives, so once it expired a
     * 404 says nothing about whether the video was created, and uploading
     * again could put a second copy on the channel. That is OutcomeUnknown,
     * possibly published.
     *
     * @param  array<string, mixed>  $state
     */
    protected function restartSession(PreparedPost $post, array $state): Pending
    {
        if ((bool) ($state['final_sent'] ?? false)) {
            throw new PermanentException(
                'The YouTube upload session expired after the last chunk may have arrived, so the video may exist; check the channel before uploading it again.',
                Platform::YouTube,
                FailureReason::OutcomeUnknown,
                array_filter([
                    'possibly_published' => true,
                    'stage' => 'upload',
                    'session_expired' => true,
                    'final_sent' => true,
                    'upload_started_at' => is_numeric($state['upload_started_at'] ?? null) ? (int) $state['upload_started_at'] : null,
                ], static fn ($value) => $value !== null),
            );
        }

        $restarts = (int) ($state['restarts'] ?? 0) + 1;
        $max = max(0, (int) $this->apiConfig('max_session_restarts', 2));

        if ($restarts > $max) {
            throw new PermanentException(
                "The YouTube upload session expired after {$max} restart(s); giving up so new sessions do not use up the daily upload quota.",
                Platform::YouTube,
                FailureReason::NotFound,
                ['stage' => 'upload', 'session_expired' => true, 'restarts' => $restarts - 1],
            );
        }

        $size = (int) $state['size'];
        $session = $this->openSession($post, $this->buildBody($post), $size, (string) ($state['mime'] ?? $this->videoMime($this->videoOf($post))));
        $deadline = max((int) ($this->deadlineOf($state) ?? 0), time() + $this->uploadWindowSeconds($size));

        $next = [...$state, 'session' => $session, 'offset' => 0, 'restarts' => $restarts, 'stalls' => 0, 'deadline' => $deadline];
        unset($next['final_sent']);

        return PublishOutcome::pending($next, 0, false, $deadline);
    }

    // --- Reading the source ----------------------------------------------------------------

    /**
     * Size (and ETag) of the video: a 1-byte Range probe on a remote URL when
     * the host supports ranges, else the gateway's local copy, which is kept
     * for the upload runs when the gateway made one (see uploadPath()).
     *
     * @return array{size: int, etag: ?string, range: bool, staged: array{path: string, size: int, mtime: int}|null}
     */
    protected function measureSource(Media $video): array
    {
        if ($this->usesRangeReads($video)) {
            $probe = $this->probeRange($video->source);

            if ($probe !== null) {
                return $probe + ['range' => true, 'staged' => null];
            }
        }

        $path = $this->mediaPath($video);
        $size = $this->uploadSize($path);
        $kept = $this->keepCopy($video, $path);

        return ['size' => $size, 'etag' => null, 'range' => false, 'staged' => $kept !== null ? $this->stagedEntry($kept) : null];
    }

    /**
     * Whether chunks may be read with Range GETs on the source URL: remote
     * media, not on a filesystem disk, behind the default gateway (a custom
     * gateway may need its own way of fetching), and apiConfig('range_reads', true).
     */
    protected function usesRangeReads(Media $video): bool
    {
        $gateway = $this->gateway();

        return (bool) $this->apiConfig('range_reads', true)
            && $video->isRemote()
            && ! $video->isOnDisk()
            && ($gateway === null || $gateway instanceof LocalMediaGateway);
    }

    /**
     * GET bytes 0-0: a 206 with 'Content-Range: bytes 0-0/N' gives the size
     * and proves the host serves ranges. Null otherwise (the caller falls back
     * to the gateway download). The body is streamed and closed unread.
     *
     * @return array{size: int, etag: ?string}|null
     */
    protected function probeRange(string $url): ?array
    {
        $response = $this->sourceRequest($url)
            ->withOptions(['stream' => true])
            ->withHeaders(['Range' => 'bytes=0-0'])
            ->get($url);

        try {
            if ($response->status() !== 206) {
                return null;
            }

            $range = $this->parseContentRange((string) $response->header('Content-Range'));

            if ($range === null || $range['total'] === null || $range['total'] <= 0) {
                return null;
            }

            return ['size' => $range['total'], 'etag' => $this->etagOf($response)];
        } finally {
            try {
                $response->toPsrResponse()->getBody()->close();
            } catch (Throwable) {
                // nothing to release
            }
        }
    }

    /**
     * The request body for bytes [$offset, $offset + $length) of the video.
     *
     * @param  array<string, mixed>  $state
     * @param  array{mode: string, path: ?string}  $source  Per-run reader state (switches to 'path' when ranges stop working).
     */
    protected function chunkBody(Media $video, array &$state, array &$source, int $offset, int $length): StreamInterface
    {
        if ($source['mode'] === 'range') {
            $stream = $this->rangeChunk($video->source, $offset, $length, $state);

            if ($stream !== null) {
                return $stream;
            }

            // The host stopped honouring ranges: stage the file for the rest of the upload.
            $state['range_reads'] = false;
            $source['mode'] = 'path';
        }

        if ($source['path'] === null) {
            $source['path'] = $this->uploadPath($video, $state);
        }

        $handle = @fopen($source['path'], 'rb');

        if ($handle === false) {
            throw new PermanentException(
                'The video file could not be opened for upload.',
                Platform::YouTube,
                FailureReason::MediaRejected,
                ['path' => basename($source['path'])],
            );
        }

        return new LimitStream(Utils::streamFor($handle), $length, $offset);
    }

    /**
     * The local file the chunks are read from when the source is not read in
     * ranges. A copy the gateway made (a download, a copy from a cloud disk)
     * is kept between runs as sp_youtube_<16 hex> next to the gateway's temp
     * files, so a large video is copied once per upload, not once per run,
     * and a signed URL that expires mid-upload no longer matters once it was
     * read. A later run reuses the kept copy while its size and mtime match
     * the state; a missing one (another worker host, a pruned file) is made
     * again. The caller's own file (a local path, a local disk) is read
     * directly. resumeUpload() deletes the copy when the upload ends;
     * social-poster:prune-temp is the backstop for a chain the job ends.
     *
     * @param  array<string, mixed>  $state  Gains or loses 'staged' as the kept copy changes.
     */
    protected function uploadPath(Media $video, array &$state): string
    {
        $kept = $this->keptCopy($state);

        if ($kept !== null) {
            return $kept;
        }

        // A stale copy (changed size or mtime) is not read again.
        $this->discardStaged($state);
        unset($state['staged']);

        try {
            $path = $this->mediaPath($video);
        } catch (PermanentException $e) {
            $status = (int) ($e->context['http_status'] ?? 0);

            throw $video->isRemote() && in_array($status, self::SOURCE_EXPIRED_STATUSES, true)
                ? $this->sourceExpired($status, $video->source, $e)
                : $e;
        }

        $actual = $this->uploadSize($path);

        if ($actual !== (int) $state['size']) {
            throw $this->sourceChanged("its size is now {$actual} bytes, not {$state['size']}");
        }

        $kept = $this->keepCopy($video, $path);

        if ($kept === null) {
            return $path;
        }

        $state['staged'] = $this->stagedEntry($kept);

        return $kept;
    }

    /**
     * Take over a temp copy the gateway made, under this driver's own name:
     * the gateway is shared, and its cleanup() at the end of the run would
     * delete the copy before later runs read it. Null for anything else (the
     * caller's own file, a custom gateway's naming), which stays per run.
     */
    protected function keepCopy(Media $video, string $path): ?string
    {
        if ($path === $video->source || preg_match('/^sp_[0-9a-f]{16}(\.[a-z0-9]+)?$/', basename($path)) !== 1 || ! is_file($path)) {
            return null;
        }

        $extension = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($path, PATHINFO_EXTENSION))) ?? '';
        $kept = dirname($path).DIRECTORY_SEPARATOR.'sp_youtube_'.bin2hex(random_bytes(8)).($extension !== '' ? '.'.$extension : '');

        return @rename($path, $kept) ? $kept : null;
    }

    /** @return array{path: string, size: int, mtime: int} */
    protected function stagedEntry(string $path): array
    {
        clearstatcache(true, $path);

        return ['path' => $path, 'size' => (int) @filesize($path), 'mtime' => (int) @filemtime($path)];
    }

    /**
     * The kept copy named by the state, when it is still the file this upload
     * made: present, of the upload's size, and not modified since.
     *
     * @param  array<string, mixed>  $state
     */
    protected function keptCopy(array $state): ?string
    {
        $path = $this->stagedPath($state);

        if ($path === null) {
            return null;
        }

        clearstatcache(true, $path);
        $size = @filesize($path);

        return is_file($path)
            && $size === (int) ($state['staged']['size'] ?? -1)
            && $size === (int) ($state['size'] ?? -1)
            && @filemtime($path) === (int) ($state['staged']['mtime'] ?? -1)
            ? $path
            : null;
    }

    /**
     * The kept copy's path from the state, only when it carries the name
     * this driver gives its copies: a state never makes it read or delete
     * another file.
     *
     * @param  array<string, mixed>  $state
     */
    protected function stagedPath(array $state): ?string
    {
        $path = is_array($state['staged'] ?? null) ? ($state['staged']['path'] ?? null) : null;

        return is_string($path) && preg_match(self::STAGED_COPY_PATTERN, basename($path)) === 1 ? $path : null;
    }

    /** @param  array<string, mixed>  $state */
    protected function discardStaged(array $state): void
    {
        $path = $this->stagedPath($state);

        if ($path !== null) {
            @unlink($path);
        }
    }

    /**
     * Fetch one chunk of a remote source with a Range GET (never with the
     * YouTube token). Null when the host answers without a usable range (the
     * caller stages the file instead).
     *
     * @param  array<string, mixed>  $state
     *
     * @throws PermanentException When the source changed or is gone (MediaRejected).
     * @throws TemporaryException When the source host fails transiently (Network).
     */
    protected function rangeChunk(string $url, int $offset, int $length, array $state): ?StreamInterface
    {
        $end = $offset + $length - 1;
        $response = $this->sourceRequest($url)
            ->withHeaders(['Range' => "bytes={$offset}-{$end}"])
            ->get($url);

        $status = (int) $response->status();
        $context = ['http_status' => $status, 'media_download' => true, 'url_host' => parse_url($url, PHP_URL_HOST)];

        if ($status === 206) {
            $range = $this->parseContentRange((string) $response->header('Content-Range'));

            if ($range === null || $range['start'] !== $offset || $range['end'] !== $end) {
                return null;
            }

            if ($range['total'] !== null && $range['total'] !== (int) $state['size']) {
                throw $this->sourceChanged("its size is now {$range['total']} bytes, not {$state['size']}");
            }

            $etag = $this->etagOf($response);
            $expected = $state['etag'] ?? null;

            if (is_string($expected) && $expected !== '' && $etag !== null && $etag !== $expected) {
                throw $this->sourceChanged('its ETag changed');
            }

            $body = $response->toPsrResponse()->getBody();

            if ($body->getSize() !== null && $body->getSize() !== $length) {
                return null;
            }

            if ($body->isSeekable()) {
                $body->rewind();
            }

            return $body;
        }

        if ($status === 200) {
            return null;
        }

        if ($status === 416) {
            throw $this->sourceChanged('it is shorter than when the upload started');
        }

        if ($status >= 500 || in_array($status, [408, 425, 429], true)) {
            throw new TemporaryException(
                "The video source returned HTTP {$status}.",
                Platform::YouTube,
                FailureReason::Network,
                RetryAfter::fromResponse($response),
                $context,
            );
        }

        if (in_array($status, self::SOURCE_EXPIRED_STATUSES, true)) {
            throw $this->sourceExpired($status, $url);
        }

        throw new PermanentException(
            "The video source returned HTTP {$status} during the upload.",
            Platform::YouTube,
            FailureReason::MediaRejected,
            $context,
        );
    }

    /**
     * A client for the media host: no YouTube token, the PublicUrl policy, the
     * checked address pinned, and every redirect hop re-checked.
     */
    protected function sourceRequest(string $url): PendingRequest
    {
        $options = ['allow_redirects' => PublicUrl::redirectOptions(3)];

        if (PublicUrl::blocksPrivateNetworks()) {
            $options += PublicUrl::curlOptions(PublicUrl::resolvePinned($url, true));
        } else {
            PublicUrl::check($url, false);
        }

        return Http::connectTimeout(10)
            ->timeout((int) config('social.download_timeout', 120))
            ->withOptions($options);
    }

    /** @return array{start: int, end: int, total: ?int}|null */
    protected function parseContentRange(string $header): ?array
    {
        if (preg_match('#^\s*bytes\s+(\d+)-(\d+)/(\d+|\*)\s*$#i', $header, $m) !== 1) {
            return null;
        }

        return ['start' => (int) $m[1], 'end' => (int) $m[2], 'total' => $m[3] === '*' ? null : (int) $m[3]];
    }

    /** @param  mixed  $response */
    protected function etagOf($response): ?string
    {
        $etag = trim((string) $response->header('ETag'));

        return $etag !== '' ? $etag : null;
    }

    /**
     * The source URL answered 401/403/410 after publish() could read it: a
     * signed URL (S3 presigned, Notion files: one hour) expired before the
     * upload finished, while it was read in ranges or with no kept copy left
     * (another worker host). Distinct from a content rejection
     * (source_url_expired), and never naming the URL (its query string
     * carries the signature).
     */
    protected function sourceExpired(int $status, string $url, ?Throwable $previous = null): PermanentException
    {
        return new PermanentException(
            "The video URL stopped being valid during the upload (HTTP {$status}): a signed URL read during the upload must stay valid until it ends. Set social.api.youtube.range_reads to false (the video is then downloaded once, when the upload starts), use Media::fromDisk() or a longer-lived URL, then publish again.",
            Platform::YouTube,
            FailureReason::MediaRejected,
            [
                'http_status' => $status,
                'media_download' => true,
                'url_host' => parse_url($url, PHP_URL_HOST),
                'source_url_expired' => true,
            ],
            $previous,
        );
    }

    protected function sourceChanged(string $why): PermanentException
    {
        return new PermanentException(
            "The video changed while it was being uploaded ({$why}); publish it again.",
            Platform::YouTube,
            FailureReason::MediaRejected,
            ['source_changed' => true],
        );
    }

    /** Chunk size: apiConfig('chunk_bytes', 16 MiB) rounded down to a multiple of 256 KiB. */
    protected function chunkBytes(): int
    {
        $configured = (int) $this->apiConfig('chunk_bytes', 16777216);

        return max(self::CHUNK_GRANULARITY, intdiv($configured, self::CHUNK_GRANULARITY) * self::CHUNK_GRANULARITY);
    }

    /** YouTube runs stay below the default 60s worker timeout. */
    protected function runBudgetSeconds(): int
    {
        return max(1, (int) $this->apiConfig('upload_run_seconds', 45));
    }

    /**
     * How long the whole upload may take before the job gives up:
     * apiConfig('upload_window'), else the time to move the file at 512 KiB/s
     * plus 15 minutes, and never less than an hour.
     */
    protected function uploadWindowSeconds(int $size): int
    {
        $configured = $this->apiConfig('upload_window', null);

        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        return max(3600, (int) ceil($size / 524288) + 900);
    }

    /** @param  array<string, mixed>  $state */
    protected function deadlineOf(array $state): ?int
    {
        return isset($state['deadline']) && is_numeric($state['deadline']) ? (int) $state['deadline'] : null;
    }

    // --- Upload finished -------------------------------------------------------------------

    /**
     * The session returned the video resource. Check the id (11 characters, or
     * the post may be live under an id we cannot read: OutcomeUnknown) and the
     * immediate outcome, then run or schedule the follow-ups. On a $repeat
     * run the session may be replaying a video whose follow-ups already ran,
     * so playlists are checked before any insert.
     *
     * @param  array<string, mixed>  $state
     * @param  mixed  $response
     */
    protected function uploaded(PreparedPost $post, array $state, $response, bool $repeat = false): PublishOutcome
    {
        $videoId = $this->requireId($response, 'video', 'id', true);

        if (preg_match(self::VIDEO_ID_PATTERN, $videoId) !== 1) {
            throw new PermanentException(
                'YouTube accepted the upload but returned an unexpected video id; the video may be live, check the channel before retrying.',
                Platform::YouTube,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'video_id' => $videoId, 'http_status' => $response->status()],
            );
        }

        $resource = $response->json();
        $resource = is_array($resource) ? $resource : [];

        $this->assertVideoOutcome($resource, $videoId);

        $options = $this->options($post);
        $thumbnail = $this->thumbnailFor($post);
        $skipped = $thumbnail !== null ? $this->unusableThumbnail($post, $thumbnail) : null;
        $likelyShort = $state['likely_short'] ?? null;
        $playlists = [];

        if ($skipped !== null) {
            // Nothing to set, so nothing to wait for either.
            $thumbnail = null;
        }

        foreach (array_unique(array_filter(array_map(
            static fn ($id) => is_scalar($id) ? trim((string) $id) : '',
            $options->playlistIds ?? [],
        ))) as $playlistId) {
            $playlists[] = ['id' => $playlistId, 'tries' => 0];
        }

        $finalize = [
            'stage' => 'finalize',
            'video_id' => $videoId,
            'payload' => $this->uploadPayload($post, $resource, $videoId, $state),
            'result_state' => $this->resultState($post, $resource),
            // A Short's thumbnail only sticks once processing finished; when
            // the shape is unknown, assume it may be a Short.
            'await' => (bool) ($options->waitForProcessing ?? false) || ($thumbnail !== null && $likelyShort !== false),
            'thumbnail' => $thumbnail !== null,
            'thumbnail_tries' => 0,
            'playlists' => $playlists,
            'polls' => 0,
            'finalize_started_at' => time(),
        ];

        if ($skipped !== null) {
            $finalize['payload']['thumbnail_warning'] = $skipped;
        }

        $finalize['deadline'] = $finalize['finalize_started_at'] + $this->processingWindowSeconds() + self::DEADLINE_MARGIN;

        return $this->continueFinalize($post, $this->runFollowUps($post, $finalize, false, $repeat));
    }

    /**
     * What the upload response says, for the result payload.
     *
     * @param  array<string, mixed>  $resource
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function uploadPayload(PreparedPost $post, array $resource, string $videoId, array $state): array
    {
        $requested = $state['requested_privacy'] ?? $this->requestedPrivacy($post);
        $actual = data_get($resource, 'status.privacyStatus');

        $payload = array_filter([
            'upload_status' => data_get($resource, 'status.uploadStatus'),
            'privacy_status' => is_string($actual) && $actual !== '' ? $actual : $requested,
            'failure_reason' => data_get($resource, 'status.failureReason'),
            'rejection_reason' => data_get($resource, 'status.rejectionReason'),
            'channel_id' => data_get($resource, 'snippet.channelId'),
            'scheduled_for' => $this->options($post)?->scheduledAt() ?? data_get($resource, 'status.publishAt'),
        ], static fn ($value) => $value !== null && $value !== '');

        if (is_string($actual) && $actual !== '' && is_string($requested) && $actual !== $requested) {
            $payload['privacy_warning'] = "Requested {$requested} but YouTube applied {$actual} (channel settings or an unaudited API project can force private uploads).";
        }

        if (array_key_exists('likely_short', $state) && is_bool($state['likely_short'])) {
            // A heuristic (vertical or square, 3 minutes or less): the API has no Shorts flag.
            $payload['likely_short'] = $state['likely_short'];

            if ($state['likely_short']) {
                $payload['shorts_url'] = 'https://www.youtube.com/shorts/'.$videoId;
            }
        }

        if (! empty($state['ignored_parts'])) {
            $payload['ignored_extra_parts'] = array_values((array) $state['ignored_parts']);
        }

        return $payload;
    }

    /**
     * published, private or scheduled, from the privacy YouTube actually applied.
     *
     * @param  array<string, mixed>  $resource
     */
    protected function resultState(PreparedPost $post, array $resource): string
    {
        if ($this->options($post)?->isScheduled() || $this->filledString(data_get($resource, 'status.publishAt'))) {
            return PostResult::STATE_SCHEDULED;
        }

        $privacy = data_get($resource, 'status.privacyStatus') ?? $this->requestedPrivacy($post);

        return $privacy === YouTubePrivacy::Private->value ? PostResult::STATE_PRIVATE : PostResult::STATE_PUBLISHED;
    }

    /**
     * Fail on a definitive outcome: rejected, failed, deleted, or processing
     * failed/terminated. The video id is in the context (the resource exists
     * on YouTube), with possibly_published = false: such a video never goes
     * live, so the job must not report it as maybe published nor keep its
     * idempotency key blocked, whichever stage saw the verdict.
     *
     * @param  array<string, mixed>  $resource  A video resource (status, processingDetails).
     */
    protected function assertVideoOutcome(array $resource, string $videoId): void
    {
        $upload = data_get($resource, 'status.uploadStatus');
        $processing = data_get($resource, 'processingDetails.processingStatus');
        $rejection = data_get($resource, 'status.rejectionReason');
        $failure = data_get($resource, 'status.failureReason');

        $context = array_filter([
            'video_id' => $videoId,
            'upload_status' => $upload,
            'processing_status' => $processing,
            'rejection_reason' => $rejection,
            'failure_reason' => $failure,
        ], static fn ($value) => $value !== null && $value !== '') + ['possibly_published' => false];

        if ($upload === 'rejected') {
            throw match ($rejection) {
                'duplicate' => new PermanentException('YouTube rejected the video as a duplicate of one already on the channel.', Platform::YouTube, FailureReason::DuplicateContent, $context),
                'uploaderAccountSuspended', 'uploaderAccountClosed' => new PermanentException("YouTube rejected the video because the channel is suspended or closed ({$rejection}).", Platform::YouTube, FailureReason::AccountRestricted, $context),
                'length' => new PermanentException('YouTube rejected the video as too long: channels that are not verified can upload up to 15 minutes (verify the channel at youtube.com/verify for up to 12 hours).', Platform::YouTube, FailureReason::MediaRejected, $context + ['field' => 'duration']),
                default => new PermanentException('YouTube rejected the video ('.(is_string($rejection) ? $rejection : 'no reason given').').', Platform::YouTube, FailureReason::MediaRejected, $context),
            };
        }

        if ($upload === 'failed') {
            throw new PermanentException('YouTube could not process the video ('.(is_string($failure) ? $failure : 'no reason given').').', Platform::YouTube, FailureReason::MediaRejected, $context);
        }

        if ($upload === 'deleted') {
            throw new PermanentException('The video was deleted on YouTube before processing finished.', Platform::YouTube, FailureReason::MediaRejected, $context);
        }

        if ($processing === 'failed' || $processing === 'terminated') {
            throw new PermanentException("YouTube video processing {$processing}.", Platform::YouTube, FailureReason::MediaRejected, $context);
        }
    }

    // --- Finalize --------------------------------------------------------------------------

    /**
     * The video is live: nothing here may fail it over credentials. An
     * expiring token with a CredentialsResolver bound waits (committed
     * Pending, at most FINALIZE_TOKEN_WAITS runs) for the job to re-resolve a
     * fresh one at the start of the next run; throwing instead would let the
     * job's second reresolve in the same run turn into a failed post. Without
     * a resolver, or once the waits are spent, it publishes with a
     * follow_up_warning.
     *
     * @param  array<string, mixed>  $state
     */
    protected function resumeFinalize(PreparedPost $post, array $state): PublishOutcome
    {
        $repeat = Pending::isRecovering($state) || (int) ($state[Pending::ATTEMPT] ?? 1) > 1;
        $state = Pending::withoutReservedKeys($state);
        $videoId = (string) ($state['video_id'] ?? '');

        if (preg_match(self::VIDEO_ID_PATTERN, $videoId) !== 1) {
            throw new PermanentException(
                'The YouTube finalize state has no video id; the video may be live, check the channel.',
                Platform::YouTube,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'stage' => 'finalize'],
            );
        }

        if (! $post->credentials->filled($this->tokenKey) || $post->credentials->expiresWithin(60)) {
            $waits = (int) ($state['token_waits'] ?? 0);

            if ($post->credentials->filled($this->tokenKey) && $this->hasCredentialsResolver() && $waits < self::FINALIZE_TOKEN_WAITS) {
                $state['token_waits'] = $waits + 1;

                return PublishOutcome::pending($state, $this->clampDelay(max(5, (int) $this->apiConfig('processing_poll_seconds', 30))), true, $this->deadlineOf($state));
            }

            // The video is live: never fail it over a follow-up.
            $state['payload']['follow_up_warning'] = 'The access token was missing or expired before the follow-up steps (processing check, thumbnail, playlists) finished.';

            return $this->finishFinalize($state);
        }

        unset($state['token_waits']);

        try {
            $polls = (int) ($state['polls'] ?? 0) + 1;
            $state['polls'] = $polls;
            $outOfTime = $polls >= max(1, (int) $this->apiConfig('max_processing_polls', 20))
                || time() >= (int) ($state['finalize_started_at'] ?? time()) + $this->processingWindowSeconds();

            if ($state['await'] ?? false) {
                $resource = $this->fetchVideoStatus($post, $videoId);

                if (is_array($resource) && $resource !== []) {
                    $this->assertVideoOutcome($resource, $videoId);
                    $state['payload'] = array_merge($state['payload'] ?? [], array_filter([
                        'upload_status' => data_get($resource, 'status.uploadStatus'),
                        'privacy_status' => data_get($resource, 'status.privacyStatus'),
                        'processing_status' => data_get($resource, 'processingDetails.processingStatus'),
                    ], static fn ($value) => is_string($value) && $value !== ''));

                    if ($this->processingFinished($resource)) {
                        $state['await'] = false;
                    }
                }

                if (($state['await'] ?? false) && $outOfTime) {
                    $state['await'] = false;
                    $state['processing_incomplete'] = true;
                    $state['payload']['processing_warning'] = "YouTube was still processing the video after {$polls} checks; it goes live once processing finishes.";
                }
            }

            $state = $this->runFollowUps($post, $state, $outOfTime, $repeat);
        } finally {
            $this->gateway()?->cleanup();
        }

        return $this->continueFinalize($post, $state);
    }

    /**
     * Published when nothing is left, else another finalize run: committed (the
     * video exists) with the stage deadline.
     *
     * @param  array<string, mixed>  $state
     */
    protected function continueFinalize(PreparedPost $post, array $state): PublishOutcome
    {
        if (! ($state['await'] ?? false) && ! ($state['thumbnail'] ?? false) && ($state['playlists'] ?? []) === []) {
            return $this->finishFinalize($state);
        }

        $polls = (int) ($state['polls'] ?? 0);
        $base = max(5, (int) $this->apiConfig('processing_poll_seconds', 30));
        $delay = ($state['await'] ?? false) ? $base * (1 + intdiv($polls, 5)) : $base;

        return PublishOutcome::pending($state, $this->clampDelay($delay), true, $this->deadlineOf($state));
    }

    /** @param  array<string, mixed>  $state */
    protected function finishFinalize(array $state): PublishOutcome
    {
        $videoId = (string) $state['video_id'];

        return $this->published(PostResult::success(
            Platform::YouTube,
            $videoId,
            'https://www.youtube.com/watch?v='.$videoId,
            payload: (array) ($state['payload'] ?? []),
            state: is_string($state['result_state'] ?? null) ? $state['result_state'] : PostResult::STATE_PUBLISHED,
            idKind: 'video_id',
        ));
    }

    /**
     * Run the follow-ups that can run now. Playlists never wait for
     * processing; the thumbnail waits while 'await' is set. Each gets one
     * retry on a failure that certainly did nothing (404 videoNotFound, 429;
     * a thumbnail also on 5xx, since setting it twice is harmless), unless
     * $lastChance; every other failure becomes a payload warning. $verify:
     * the run may repeat an earlier one, so a playlist is checked before
     * the video is added to it.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function runFollowUps(PreparedPost $post, array $state, bool $lastChance, bool $verify = false): array
    {
        $videoId = (string) $state['video_id'];
        $remaining = [];

        foreach ((array) ($state['playlists'] ?? []) as $item) {
            $playlistId = (string) ($item['id'] ?? '');
            $tries = (int) ($item['tries'] ?? 0);

            if ($playlistId === '') {
                continue;
            }

            $result = $this->addToPlaylist($post, $videoId, $playlistId, $verify);

            if ($result['ok']) {
                $state['payload']['playlist_items'][$playlistId] = $result['item_id'] ?? null;

                continue;
            }

            if ($result['retryable'] && $tries < 1 && ! $lastChance) {
                $remaining[] = ['id' => $playlistId, 'tries' => $tries + 1];

                continue;
            }

            $state['payload']['playlist_warnings'][$playlistId] = $result['message'];
        }

        $state['playlists'] = $remaining;

        if (($state['thumbnail'] ?? false) && ! ($state['await'] ?? false)) {
            $thumbnail = $this->thumbnailFor($post);
            $failure = $thumbnail !== null ? $this->uploadThumbnail($post, $videoId, $thumbnail) : null;

            if ($failure === null) {
                $state['thumbnail'] = false;

                if ($thumbnail !== null && ($state['processing_incomplete'] ?? false)) {
                    $state['payload']['thumbnail_warning'] = 'The thumbnail was set before YouTube finished processing; YouTube may not apply it to a Short.';
                }
            } elseif ($failure['retryable'] && (int) ($state['thumbnail_tries'] ?? 0) < 1 && ! $lastChance) {
                $state['thumbnail_tries'] = (int) ($state['thumbnail_tries'] ?? 0) + 1;
            } else {
                $state['thumbnail'] = false;
                $state['payload']['thumbnail_warning'] = $failure['message'];

                if ($failure['reason'] !== null) {
                    $state['payload']['thumbnail_error_reason'] = $failure['reason'];
                }
            }
        }

        return $state;
    }

    /**
     * videos.list?part=status,processingDetails for one video: the resource,
     * [] when YouTube does not list it yet, null when the call failed.
     *
     * @return array<string, mixed>|null
     */
    protected function fetchVideoStatus(PreparedPost $post, string $videoId): ?array
    {
        try {
            $response = $this->http($post)->get($this->apiBase.'/videos', [
                'part' => 'status,processingDetails',
                'id' => $videoId,
            ] + $this->contentOwnerQuery($post));
        } catch (ConnectionException) {
            return null;
        }

        if (! $this->ok($response)) {
            return null;
        }

        $item = $response->json('items.0');

        return is_array($item) ? $item : [];
    }

    /** @param  array<string, mixed>  $resource */
    protected function processingFinished(array $resource): bool
    {
        return data_get($resource, 'processingDetails.processingStatus') === 'succeeded'
            || data_get($resource, 'status.uploadStatus') === 'processed';
    }

    protected function processingWindowSeconds(): int
    {
        return max(60, (int) $this->apiConfig('processing_window', 3600));
    }

    // --- Thumbnail and playlists ----------------------------------------------------------

    /**
     * The thumbnail to set: YouTubeOptions::$thumbnail, else the video's own
     * Media::video(thumbnail: ...).
     */
    protected function thumbnailFor(PreparedPost $post): ?Media
    {
        $thumbnail = $this->options($post)?->thumbnail;

        if ($thumbnail !== null) {
            return $thumbnail;
        }

        $fallback = ($post->media()[0] ?? null)?->thumbnail;

        return is_string($fallback) && trim($fallback) !== '' ? Media::image(trim($fallback)) : null;
    }

    /**
     * Why the thumbnail cannot be set, or null when it can be tried. Only the
     * shared Media::video(thumbnail: ...) fallback is checked here (the
     * explicit option was validated): a known extension other than JPG/PNG
     * is skipped; no extension is tried, and YouTube decides from the bytes.
     */
    protected function unusableThumbnail(PreparedPost $post, Media $thumbnail): ?string
    {
        if ($this->options($post)?->thumbnail !== null) {
            return null;
        }

        $extension = $thumbnail->extension();

        if ($extension === '' || in_array($extension, self::THUMBNAIL_EXTENSIONS, true)) {
            return null;
        }

        return "The video's thumbnail (Media::video(thumbnail: ...)) was not set: YouTube only takes a JPG or PNG image (got .{$extension}). Set YouTubeOptions::\$thumbnail to give YouTube its own cover.";
    }

    /**
     * Set the thumbnail; returns the warning message, or null when it was set.
     * Kept for subclasses (1.0 API); the driver itself uses uploadThumbnail().
     */
    protected function setThumbnail(PreparedPost $post, string $videoId, Media $thumbnail): ?string
    {
        return $this->uploadThumbnail($post, $videoId, $thumbnail)['message'] ?? null;
    }

    /**
     * thumbnails.set with the image bytes. Null when it was set, else the
     * failure: message, Google reason, and whether one retry is worthwhile
     * (videoNotFound right after the upload, uploadRateLimitExceeded, 5xx).
     *
     * @return array{message: string, reason: ?string, retryable: bool}|null
     */
    protected function uploadThumbnail(PreparedPost $post, string $videoId, Media $thumbnail): ?array
    {
        try {
            $response = $this->http($post)
                ->withBody($this->bytes($thumbnail), $this->thumbnailMime($thumbnail))
                ->post($this->thumbnailEndpoint.'?'.http_build_query(['videoId' => $videoId] + $this->contentOwnerQuery($post)));
        } catch (ConnectionException $e) {
            return ['message' => 'The thumbnail could not be sent (network error).', 'reason' => null, 'retryable' => true];
        } catch (Throwable $e) {
            return ['message' => $e->getMessage(), 'reason' => null, 'retryable' => false];
        }

        if ($this->ok($response)) {
            return null;
        }

        return $this->followUpFailure($response, 'Thumbnail could not be set.');
    }

    /**
     * playlistItems.insert. Needs the youtube, youtube.force-ssl or
     * youtubepartner scope (youtube.upload is not enough): when the token's
     * scopes are known and lack it, no call is made. Playlists accept the same
     * video twice, so an insert whose outcome is unknown (network error, 408,
     * 5xx) is never retried, and with $verify (a run that may repeat an
     * earlier insert) playlistItems.list (1 quota unit) is asked first: an
     * existing item is reused, and when the check fails nothing is inserted.
     *
     * @return array{ok: bool, item_id?: ?string, message: string, reason: ?string, retryable: bool}
     */
    protected function addToPlaylist(PreparedPost $post, string $videoId, string $playlistId, bool $verify = false): array
    {
        $missing = $post->credentials->missingScopes([$this->scopeGroup(['youtube', 'youtube.force-ssl', 'youtubepartner'])]);

        if ($missing !== null && $missing !== []) {
            return ['ok' => false, 'message' => 'Adding the video to a playlist needs the youtube or youtube.force-ssl scope.', 'reason' => 'insufficientPermissions', 'retryable' => false];
        }

        if ($verify) {
            $existing = $this->playlistItemOf($post, $videoId, $playlistId);

            if ($existing === false) {
                return ['ok' => false, 'message' => 'YouTube could not check whether an earlier attempt already added the video to the playlist; it was not added again, to avoid a duplicate.', 'reason' => null, 'retryable' => false];
            }

            if ($existing !== null) {
                return ['ok' => true, 'item_id' => $existing !== '' ? $existing : null, 'message' => '', 'reason' => null, 'retryable' => false];
            }
        }

        try {
            $response = $this->http($post)->post($this->apiBase.'/playlistItems?'.http_build_query(['part' => 'snippet'] + $this->contentOwnerQuery($post)), [
                'snippet' => [
                    'playlistId' => $playlistId,
                    'resourceId' => ['kind' => 'youtube#video', 'videoId' => $videoId],
                ],
            ]);
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => 'Network error adding the video to the playlist; it may or may not have been added.', 'reason' => null, 'retryable' => false];
        }

        if ($this->ok($response)) {
            $itemId = $response->json('id');

            return ['ok' => true, 'item_id' => is_string($itemId) && $itemId !== '' ? $itemId : null, 'message' => '', 'reason' => null, 'retryable' => false];
        }

        $failure = $this->followUpFailure($response, 'The video could not be added to the playlist.');
        $status = (int) $response->status();

        if ($status === 408 || $status >= 500 || in_array($failure['reason'], ['backendError', 'internalError'], true)) {
            return [
                'ok' => false,
                'message' => "YouTube failed while adding the video to the playlist (HTTP {$status}); it may or may not have been added.",
                'reason' => $failure['reason'],
                'retryable' => false,
            ];
        }

        return ['ok' => false] + $failure;
    }

    /**
     * playlistItems.list?part=id&playlistId=...&videoId=...: the id of the
     * item holding the video ('' when YouTube lists one without an id), null
     * when the playlist does not hold it, false when the check failed.
     */
    protected function playlistItemOf(PreparedPost $post, string $videoId, string $playlistId): string|false|null
    {
        try {
            $response = $this->http($post)->get($this->apiBase.'/playlistItems', [
                'part' => 'id',
                'playlistId' => $playlistId,
                'videoId' => $videoId,
                'maxResults' => 1,
            ] + $this->contentOwnerQuery($post));
        } catch (ConnectionException) {
            return false;
        }

        $items = $this->ok($response) ? $response->json('items') : null;

        if (! is_array($items)) {
            return false;
        }

        if ($items === []) {
            return null;
        }

        $id = data_get($items, '0.id');

        return is_string($id) ? trim($id) : '';
    }

    /**
     * @param  mixed  $response
     * @return array{message: string, reason: ?string, retryable: bool}
     */
    protected function followUpFailure($response, string $fallback): array
    {
        $error = $this->googleError($response);
        $reason = $this->errorReason($error);
        $status = (int) $response->status();
        $message = $this->googleMessage($error) ?? $fallback;

        $retryable = $status === 429 || $status >= 500
            || in_array($reason, ['videoNotFound', 'uploadRateLimitExceeded', 'backendError', 'internalError'], true)
            || ($status === 401 && $this->hasCredentialsResolver());

        return ['message' => $message, 'reason' => $reason !== '' ? $reason : null, 'retryable' => $retryable];
    }

    // --- Comments ---------------------------------------------------------------------------

    /**
     * No comment on a video that is private, scheduled or made for kids
     * (comments are disabled there). Up to 10000 characters, the youtube.force-ssl
     * scope, attempted when the scopes are unknown. YouTube needs a moment
     * before a fresh upload accepts comments: apiConfig('comment_delay', 60), and
     * a 404 videoNotFound is retried.
     */
    public function commentRules(PreparedPost $post, ?PostResult $parent = null): ?CommentRules
    {
        $options = $this->options($post);

        if ($options !== null && ($options->madeForKids || $options->isScheduled() || $options->resolvedPrivacy() === YouTubePrivacy::Private)) {
            return null;
        }

        if ($parent !== null && (
            $parent->isScheduled()
            || $parent->isDraft()
            || $parent->state === PostResult::STATE_PRIVATE
            || ($parent->payload['privacy_status'] ?? null) === YouTubePrivacy::Private->value
        )) {
            return null;
        }

        return new CommentRules(
            max: 10000,
            minDelaySeconds: max(0, (int) $this->apiConfig('comment_delay', 60)),
            requiredScopes: $this->requiredScopes(Feature::Comment, $post->credentials),
            unknownScopePolicy: CommentRules::ATTEMPT,
        );
    }

    /**
     * commentThreads.insert (50 quota units). The channel comes from the
     * 'channel_id' credential, the upload response, or channels.list?mine=true.
     *
     * In a recovery (the comment job passes the parent with payload
     * `_recovering`: an earlier attempt's answer was lost, a 5xx, or its worker
     * died mid-call), the video's newest comments are read first
     * (findOwnComment(), 1 quota unit) and the channel's own comment with this
     * text is returned (payload recovered = true) instead of adding a second.
     */
    public function addFirstComment(PreparedPost $context, PostResult $parent, string $text): CommentResult
    {
        $this->assertCredentials($context);
        $this->assertTokenFresh($context);

        $videoId = trim((string) $parent->platformPostId);
        $text = trim($text);

        if ($videoId === '') {
            throw new PermanentException('There is no YouTube video id to comment on.', Platform::YouTube, FailureReason::NotFound, ['field' => 'video_id']);
        }

        if ($text === '') {
            throw new PermanentException('The YouTube comment is empty.', Platform::YouTube, FailureReason::InvalidContent, ['field' => 'comment']);
        }

        $channelId = $this->channelIdFor($context, $parent);

        if (Pending::isRecovering($parent->payload)) {
            $found = $this->findOwnComment($context, $videoId, $channelId, $text);

            if ($found !== null) {
                return $found;
            }
        }

        $response = $this->http($context)->post($this->apiBase.'/commentThreads?part=snippet', [
            'snippet' => [
                'channelId' => $channelId,
                'videoId' => $videoId,
                'topLevelComment' => ['snippet' => ['textOriginal' => $text]],
            ],
        ]);

        if (! $this->ok($response)) {
            throw $this->mapError($response, 'comment');
        }

        // A 2xx without an id may still have created the comment: never retried.
        $threadId = $this->requireId($response, 'comment', 'id', true);
        $commentId = $response->json('snippet.topLevelComment.id');
        $commentId = is_string($commentId) && trim($commentId) !== '' ? trim($commentId) : $threadId;

        return new CommentResult(
            $commentId,
            'https://www.youtube.com/watch?'.http_build_query(['v' => $videoId, 'lc' => $commentId]),
            ['thread_id' => $threadId, 'comment_id' => $commentId, 'channel_id' => $channelId, 'video_id' => $videoId],
        );
    }

    /**
     * The channel's own top-level comment with $text among the video's newest
     * comments (commentThreads.list, order=time, 100 per page, 1 quota unit),
     * or null when there is none. The comment being recovered was added
     * minutes ago, so the newest page holds it unless more than 100 others
     * arrived since. A TemporaryException from the read (or a connection
     * error) propagates, so the job retries the recovery; any other refusal
     * becomes OutcomeUnknown with possibly_published = true, because adding
     * the comment without having looked could post it twice.
     */
    protected function findOwnComment(PreparedPost $context, string $videoId, string $channelId, string $text): ?CommentResult
    {
        $response = $this->http($context)->get($this->apiBase.'/commentThreads', [
            'part' => 'snippet',
            'videoId' => $videoId,
            'order' => 'time',
            'maxResults' => 100,
            'textFormat' => 'plainText',
        ]);

        if (! $this->ok($response)) {
            $error = $this->mapError($response, 'comment');

            if ($error instanceof TemporaryException) {
                throw $error;
            }

            throw new PermanentException(
                'An earlier attempt may already have added this YouTube comment, and the video\'s comments could not be read to check ('.$error->getMessage().'). Check the video before commenting again.',
                Platform::YouTube,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'recovering' => true, 'operation' => 'comment', 'video_id' => $videoId, 'lookup_reason' => $error->reason->value],
                $error,
            );
        }

        $wanted = $this->normalizeCommentText($text);
        $items = $response->json('items');

        foreach (is_array($items) ? $items : [] as $item) {
            $snippet = data_get($item, 'snippet.topLevelComment.snippet');
            $author = data_get($snippet, 'authorChannelId.value');
            $body = data_get($snippet, 'textOriginal') ?? data_get($snippet, 'textDisplay');

            if (! is_string($author) || $author !== $channelId || ! is_string($body) || $this->normalizeCommentText($body) !== $wanted) {
                continue;
            }

            $threadId = data_get($item, 'id');
            $commentId = data_get($item, 'snippet.topLevelComment.id');
            $threadId = is_string($threadId) && trim($threadId) !== '' ? trim($threadId) : null;
            $commentId = is_string($commentId) && trim($commentId) !== '' ? trim($commentId) : $threadId;

            if ($commentId === null) {
                continue;
            }

            return new CommentResult(
                $commentId,
                'https://www.youtube.com/watch?'.http_build_query(['v' => $videoId, 'lc' => $commentId]),
                ['thread_id' => $threadId ?? $commentId, 'comment_id' => $commentId, 'channel_id' => $channelId, 'video_id' => $videoId, 'recovered' => true],
            );
        }

        return null;
    }

    /** Comment text as compared in a recovery: trimmed, with \r\n line breaks as \n. */
    protected function normalizeCommentText(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    protected function channelIdFor(PreparedPost $context, PostResult $parent): string
    {
        foreach ([$context->credentials->get('channel_id'), $parent->payload['channel_id'] ?? null] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        $response = $this->http($context)->get($this->apiBase.'/channels', ['part' => 'id', 'mine' => 'true']);

        if (! $this->ok($response)) {
            throw $this->mapError($response, 'comment');
        }

        if ($response->json('items') === []) {
            throw new PermanentException(
                'The Google account behind this token has no YouTube channel to comment from.',
                Platform::YouTube,
                FailureReason::AccountRestricted,
                ['no_channel' => true],
            );
        }

        return $this->requireId($response, 'channel', 'items.0.id');
    }

    // --- Metadata ---------------------------------------------------------------------------

    /**
     * The videos.insert body. Typed options fill snippet and status; extra()
     * adds to each sub-object (driver keys win) and may add the other writable
     * parts (recordingDetails, localizations...). extra['query'] goes to the
     * query string instead, and other top-level keys are dropped (YouTube
     * ignores any part not named in 'part'; see ignoredExtraParts()).
     *
     * @return array<string, mixed>
     */
    protected function buildBody(PreparedPost $post): array
    {
        $options = $this->options($post);

        $snippet = array_filter([
            'title' => trim((string) $post->title()),
            'description' => (string) $post->caption(),
            'tags' => $options?->tags ? array_values($options->tags) : null,
            'categoryId' => $options?->categoryId,
            'defaultLanguage' => $options?->defaultLanguage,
            'defaultAudioLanguage' => $options?->defaultAudioLanguage,
        ], static fn ($value) => $value !== null && $value !== '');

        $status = array_filter([
            'privacyStatus' => $this->requestedPrivacy($post),
            'selfDeclaredMadeForKids' => $options->madeForKids ?? false,
            'publishAt' => $options?->publishAt !== null ? $options->scheduledAt() : null,
            'containsSyntheticMedia' => $options?->containsSyntheticMedia,
            'embeddable' => $options?->embeddable,
            'license' => $options?->license,
        ], static fn ($value) => $value !== null);

        $extra = $this->extraPayload($post);

        $body = [
            'snippet' => array_merge((array) ($extra['snippet'] ?? []), $snippet),
            'status' => array_merge((array) ($extra['status'] ?? []), $status),
        ];

        foreach ($extra as $key => $value) {
            if (is_string($key) && $key !== 'snippet' && $key !== 'status' && in_array($key, self::WRITABLE_PARTS, true)) {
                $body[$key] = $value;
            }
        }

        return $body;
    }

    /**
     * Top-level extra() keys that are neither a writable part nor 'query':
     * not sent, and listed in the result payload as ignored_extra_parts.
     *
     * @return list<string>
     */
    protected function ignoredExtraParts(PreparedPost $post): array
    {
        $ignored = [];

        foreach (array_keys($this->extraPayload($post)) as $key) {
            if ($key !== 'query' && ! in_array($key, self::WRITABLE_PARTS, true)) {
                $ignored[] = (string) $key;
            }
        }

        return $ignored;
    }

    /**
     * videos.insert URL: part derived from the body's writable parts,
     * notifySubscribers=false when asked, and extra['query'] (e.g.
     * onBehalfOfContentOwner). uploadType and part cannot be overridden.
     *
     * @param  array<string, mixed>  $body
     */
    protected function uploadUrl(PreparedPost $post, array $body): string
    {
        $parts = array_values(array_filter(self::WRITABLE_PARTS, static fn ($part) => array_key_exists($part, $body)));

        $query = ['uploadType' => 'resumable', 'part' => implode(',', $parts)] + $this->uploadQuery($post);

        return $this->uploadEndpoint().'?'.http_build_query($query);
    }

    /** @return array<string, string> */
    protected function uploadQuery(PreparedPost $post): array
    {
        $query = [];
        $extra = $this->extraPayload($post)['query'] ?? [];

        foreach (is_array($extra) ? $extra : [] as $key => $value) {
            if (is_string($key) && $key !== '' && is_scalar($value)) {
                $query[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }

        if ($this->options($post)?->notifySubscribers === false) {
            $query['notifySubscribers'] = 'false';
        }

        return $query;
    }

    /**
     * onBehalfOfContentOwner from extra['query'], for the calls after
     * videos.insert (videos.list, thumbnails.set, playlistItems): without it a
     * CMS user's token acts as its own identity, which does not own the video.
     *
     * @return array<string, string>
     */
    protected function contentOwnerQuery(PreparedPost $post): array
    {
        $query = $this->extraPayload($post)['query'] ?? null;
        $owner = is_array($query) ? ($query['onBehalfOfContentOwner'] ?? null) : null;

        return $this->filledString($owner) ? ['onBehalfOfContentOwner' => (string) $owner] : [];
    }

    protected function uploadEndpoint(): string
    {
        return explode('?', $this->resumable, 2)[0];
    }

    protected function requestedPrivacy(PreparedPost $post): string
    {
        return ($this->options($post)?->resolvedPrivacy() ?? YouTubePrivacy::Public)->value;
    }

    /**
     * Whether the video looks like a Short (vertical or square, at most
     * apiConfig('shorts_max_seconds', 180)), from the inspector or Media::$meta;
     * null when the shape or length is unknown.
     */
    protected function likelyShort(Media $video): ?bool
    {
        $meta = $this->inspectMedia($video);

        if ($meta === null || ! $meta->width || ! $meta->height || $meta->durationSeconds === null) {
            return null;
        }

        return $meta->height >= $meta->width && $meta->durationSeconds <= (float) $this->apiConfig('shorts_max_seconds', 180);
    }

    // --- Helpers ----------------------------------------------------------------------------

    protected function options(PreparedPost $post): ?YouTubeOptions
    {
        return $this->optionsOf($post, YouTubeOptions::class);
    }

    protected function videoOf(PreparedPost $post): Media
    {
        $video = $post->media()[0] ?? null;

        if (! $video instanceof Media) {
            throw new PermanentException('A YouTube upload needs exactly one video.', Platform::YouTube, FailureReason::MediaRejected, ['field' => 'media']);
        }

        return $video;
    }

    protected function videoMime(Media $video): string
    {
        return match ($video->extension()) {
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'wmv' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
            'webm' => 'video/webm',
            'mpg', 'mpeg' => 'video/mpeg',
            '3gp' => 'video/3gpp',
            default => 'video/mp4',
        };
    }

    /** image/png, image/jpeg, or application/octet-stream (accepted by thumbnails.set) when the extension says nothing. */
    protected function thumbnailMime(Media $image): string
    {
        return match ($image->extension()) {
            'png' => 'image/png',
            '' => 'application/octet-stream',
            default => 'image/jpeg',
        };
    }

    private function filledString(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes % 1048576 === 0 ? ($bytes / 1048576).'MB' : $bytes.' bytes';
    }

    // --- Errors -----------------------------------------------------------------------------

    /**
     * Google's error table. The reason is read from error.errors[0].reason,
     * then the google.rpc.ErrorInfo details[].reason (UPPER_SNAKE), then
     * error.status. $operation is 'upload' (videos.insert and its session),
     * 'comment' (commentThreads / channels) or anything else.
     *
     * Daily quotas are permanent with retry_at = next midnight Pacific Time:
     * the queue never waits for a reset.
     *
     * v1.0's signature, so a subclass override written for 1.0 still loads:
     * mapError() forwards $operation as an extra argument, read here with
     * func_get_args().
     *
     * @param  mixed  $response
     */
    protected function classifyError($response): TemporaryException|PermanentException
    {
        $operation = func_get_args()[1] ?? 'upload';
        $operation = is_string($operation) ? $operation : 'upload';
        $status = (int) $response->status();
        $error = $this->googleError($response);
        $reason = $this->errorReason($error);
        $details = $this->detailReasons($error);
        $message = $this->googleMessage($error) ?? "YouTube request failed (HTTP {$status}).";

        $context = ['status' => $status, 'error' => $error, 'operation' => $operation]
            + array_filter([
                'reason' => $reason !== '' ? $reason : null,
                'google_status' => is_string($error['status'] ?? null) ? $error['status'] : null,
                'error_info' => $details !== [] ? $details : null,
                'location' => data_get($error, 'errors.0.location'),
            ], static fn ($value) => $value !== null && $value !== '');

        if (in_array($reason, self::DAILY_QUOTA_REASONS, true)) {
            return $this->dailyQuotaException($reason, $context);
        }

        if (in_array($reason, self::SHORT_TERM_REASONS, true)) {
            return new TemporaryException($message, Platform::YouTube, FailureReason::RateLimited, RetryAfter::fromResponse($response) ?? 60, $context);
        }

        if ($reason === 'uploadLimitExceeded') {
            return new PermanentException(
                "The channel reached YouTube's upload limit (uploadLimitExceeded); it can upload again later. Reschedule the video.",
                Platform::YouTube,
                FailureReason::RateLimited,
                $context + ['upload_limit' => true],
            );
        }

        if (isset(self::FIELD_REASONS[$reason])) {
            return new PermanentException($message, Platform::YouTube, FailureReason::InvalidContent, $context + ['field' => self::FIELD_REASONS[$reason]]);
        }

        if (in_array($reason, self::MEDIA_REASONS, true)) {
            return new PermanentException($message, Platform::YouTube, FailureReason::MediaRejected, $context);
        }

        if ($reason === 'forbiddenPrivacySetting' || $reason === 'forbiddenLicenseSetting') {
            // The channel's standing decides which privacy or licence it may use; reconnecting changes nothing.
            return new PermanentException($message, Platform::YouTube, FailureReason::InsufficientPermissions, $context + ['field' => $reason === 'forbiddenPrivacySetting' ? 'privacyStatus' : 'license', 'reconnect' => false]);
        }

        if (in_array($reason, self::DEVELOPER_CONFIG_REASONS, true) || array_intersect($details, self::DEVELOPER_CONFIG_REASONS) !== []) {
            return new PermanentException(
                "The YouTube Data API is not enabled or not allowed for this Google Cloud project: {$message}",
                Platform::YouTube,
                FailureReason::Misconfigured,
                $context + ['developer_config' => true],
            );
        }

        if ($reason === 'youtubeSignupRequired' || $reason === 'ineligibleAccount' || str_starts_with($reason, 'account')) {
            return new PermanentException($message, Platform::YouTube, FailureReason::AccountRestricted, $context);
        }

        if ($operation === 'comment') {
            if ($reason === 'videoNotFound') {
                // A fresh upload is not commentable until YouTube lists it.
                return new TemporaryException($message, Platform::YouTube, FailureReason::NotFound, 60, $context + ['video_processing' => true]);
            }

            if ($reason === 'commentsDisabled') {
                return new PermanentException($message, Platform::YouTube, FailureReason::InvalidContent, $context + ['comments_disabled' => true]);
            }

            if ($reason === 'forbidden') {
                return new PermanentException($message, Platform::YouTube, FailureReason::AccountRestricted, $context + ['comments_forbidden' => true]);
            }

            if ($reason === 'channelOrVideoIdMissing') {
                return new PermanentException($message, Platform::YouTube, FailureReason::Misconfigured, $context);
            }
        }

        $expired = in_array('ACCESS_TOKEN_EXPIRED', $details, true) || $reason === 'ACCESS_TOKEN_EXPIRED' || $reason === 'expired';

        if ($status === 401 || in_array($reason, self::TOKEN_REASONS, true)) {
            return new PermanentException($message, Platform::YouTube, FailureReason::InvalidToken, $context + ($expired ? ['token_expired' => true] : []));
        }

        if (in_array($reason, self::SCOPE_REASONS, true) || array_intersect($details, self::SCOPE_REASONS) !== []) {
            return new PermanentException($message, Platform::YouTube, FailureReason::InsufficientPermissions, $context + ['missing_scope' => true]);
        }

        return parent::classifyError($response)->addContext($context);
    }

    /**
     * PermanentException(RateLimited) with retry_at (ISO-8601 UTC) at the
     * next midnight in America/Los_Angeles, when Google resets daily quotas,
     * and retry_after_hint in seconds.
     *
     * @param  array<string, mixed>  $context
     */
    protected function dailyQuotaException(string $reason, array $context): PermanentException
    {
        $now = CarbonImmutable::now();
        $reset = $now->setTimezone('America/Los_Angeles')->addDay()->startOfDay();
        $retryAt = $reset->utc()->format('Y-m-d\TH:i:s\Z');

        return new PermanentException(
            "YouTube's daily API quota is used up ({$reason}); it resets at midnight Pacific Time ({$retryAt}). Reschedule the upload after that.",
            Platform::YouTube,
            FailureReason::RateLimited,
            $context + [
                'quota' => 'daily',
                'retry_at' => $retryAt,
                'retry_after_hint' => max(0, $reset->getTimestamp() - $now->getTimestamp()),
            ],
        );
    }

    /**
     * The 'error' object of a Google error body ([] when absent or not JSON).
     *
     * @param  mixed  $response
     * @return array<string, mixed>
     */
    protected function googleError($response): array
    {
        try {
            $error = $response->json('error');
        } catch (Throwable) {
            return [];
        }

        return is_array($error) ? $error : [];
    }

    /** @param  array<string, mixed>  $error */
    protected function googleMessage(array $error): ?string
    {
        foreach ([$error['message'] ?? null, data_get($error, 'errors.0.message')] as $message) {
            if (is_string($message) && trim($message) !== '') {
                return trim($message);
            }
        }

        return null;
    }

    /**
     * The error reason: errors[0].reason, else the first ErrorInfo reason,
     * else error.status, else ''.
     *
     * @param  array<string, mixed>  $error
     */
    protected function errorReason(array $error): string
    {
        $reason = data_get($error, 'errors.0.reason');

        if (is_string($reason) && $reason !== '') {
            return $reason;
        }

        $details = $this->detailReasons($error);

        if ($details !== []) {
            return $details[0];
        }

        $status = $error['status'] ?? null;

        return is_string($status) ? $status : '';
    }

    /**
     * Every google.rpc.ErrorInfo reason in error.details[].
     *
     * @param  array<string, mixed>  $error
     * @return list<string>
     */
    protected function detailReasons(array $error): array
    {
        $reasons = [];

        foreach ((array) ($error['details'] ?? []) as $detail) {
            $reason = is_array($detail) ? ($detail['reason'] ?? null) : null;

            if (is_string($reason) && $reason !== '') {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    // --- Rule sets --------------------------------------------------------------------------

    /**
     * Every format YouTube lists, up to apiConfig('max_video_bytes', 256 GB) and
     * apiConfig('max_duration', 12 h). Channels that are not verified are capped
     * at 15 minutes; that cannot be known here, so it surfaces as a rejection
     * ('length') after the upload.
     */
    protected function videoRules(): VideoRules
    {
        return new VideoRules(
            extensions: ['mov', 'mp4', 'm4v', 'avi', 'wmv', 'flv', 'webm', 'mpg', 'mpeg', '3gp'],
            maxSizeBytes: (int) $this->apiConfig('max_video_bytes', 274877906944),
            maxDuration: (float) $this->apiConfig('max_duration', 43200),
        );
    }
}
