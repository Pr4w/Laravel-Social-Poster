<?php

use SocialPoster\Gateways\LocalMediaGateway;
use SocialPoster\Idempotency\CacheIdempotencyStore;
use SocialPoster\Inspectors\FFProbeInspector;
use SocialPoster\Resolvers\IdentityCredentialsResolver;
use SocialPoster\Transformers\NullMediaTransformer;

/*
|--------------------------------------------------------------------------
| social-poster
|--------------------------------------------------------------------------
|
| Every key below is optional: the package reads each one with an inline
| default, so a key missing from a published (older) copy of this file keeps
| working. The 'api', 'async', 'sync' and 'media' blocks are deep-merged with
| the package defaults at boot, so a config published under v1.0 still picks
| up new per-platform defaults (Bluesky, Pinterest, Graph versions...). After
| upgrading, diff your published file against this one, or re-publish it:
|
|   php artisan vendor:publish --tag=social-poster-config --force
|
*/

return [

    // Optional default platform used when the builder is given none.
    'default' => null,

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | The publish job runs as long as one upload slice (see job_timeout). Every
    | queue connection the package uses must have a retry_after LONGER than
    | job_timeout, or the queue hands a still-running job to a second worker.
    | The provider logs a warning at boot when it is not.
    |
    */

    // Queue connection and queue name for the publish and comment jobs. Null
    // uses the application defaults. Continuations and comment jobs stay on the
    // connection/queue of the job that dispatched them.
    'connection' => env('SOCIAL_QUEUE_CONNECTION'),
    'queue' => env('SOCIAL_QUEUE'),

    // Dispatch jobs only after the surrounding database transaction commits, so
    // a rolled-back record never gets a post. Null leaves it to the connection.
    'after_commit' => env('SOCIAL_AFTER_COMMIT', true),

    // Encrypt job payloads (they carry credentials). Old unencrypted payloads
    // still run. Dispatch Credentials::ref() to keep tokens out entirely.
    'encrypt_jobs' => env('SOCIAL_ENCRYPT_JOBS', true),

    // Attempts per publish step and the waits between them (seconds; the last
    // value repeats). A platform's Retry-After wins over the schedule.
    'tries' => env('SOCIAL_TRIES', 5),
    'backoff' => [30, 120, 300, 900],

    // Seconds one run of the publish job may take. Null derives it per platform:
    // the longest upload slice (api.<platform>.upload_run_seconds, 240) plus the
    // longest single call (upload, publish or HTTP timeout) plus 120.
    'job_timeout' => env('SOCIAL_JOB_TIMEOUT'),

    // Fresh restarts of one publish allowed after a platform-side failure the
    // driver marks as restartable (expired container, garbage-collected blob).
    'max_restarts' => 3,

    // Cache store for the job locks (unique dispatch, overlap guard) and the
    // idempotency leases. Null uses the default cache store. Use a shared store
    // (redis, database, memcached) when workers run on several servers.
    'lock_store' => env('SOCIAL_LOCK_STORE'),

    // Wall-clock limits for asynchronous processing (video transcoding,
    // container status, publish jobs). A driver may declare its own deadline,
    // which wins; otherwise the per-platform value, then max_wait. When the
    // limit passes, a post the platform already accepted is reported as
    // PostFailed(ProcessingTimeout, possiblyPublished) and kept for reconcile.
    // YouTube declares a deadline on every step (api.youtube.upload_window and
    // processing_window), so its value here only covers a driver override
    // that declares none.
    'async' => [
        'max_wait' => env('SOCIAL_ASYNC_MAX_WAIT', 3600),
        'max_wait_per_platform' => [
            'tiktok' => 3900,
            'instagram' => 86400,
            'youtube' => 14400,
            'linkedin' => 3600,
        ],
    ],

    // postNow() and the 'sync' queue connection, where the request itself waits.
    'sync' => [
        // Seconds of processing a synchronous publish may wait per platform.
        // Past it, postNow() hands the publish to the queue (result state
        // 'processing'); on the 'sync' connection it times out instead.
        'max_wait' => env('SOCIAL_SYNC_MAX_WAIT', 120),
        // postNow(): in-process retries of a temporary error per platform.
        'retries' => 3,
        // Seconds between those retries when the platform sends no Retry-After
        // (the last value repeats). A wait past max_wait ends the retries.
        'backoff' => [2, 5, 10],
        // postNow(): add the first comment in-process instead of queueing it.
        // Per call: ->inlineComment().
        'inline_comments' => false,
    ],

    // Upper bound for a single re-check delay (seconds). SQS cannot delay a
    // message longer than 900; longer waits are chained.
    'max_recheck_after' => 900,

    /*
    |--------------------------------------------------------------------------
    | First comment
    |--------------------------------------------------------------------------
    */

    // Seconds to wait after a post is live before adding its first comment.
    // A driver can require more (CommentRules::$minDelaySeconds, e.g. LinkedIn).
    'comment_delay' => env('SOCIAL_COMMENT_DELAY', 60),

    // Seconds before the first comment on a post that was still processing
    // when the publish returned (e.g. a Facebook Reel past the status-poll
    // budget); it usually goes live within minutes.
    'comment_processing_delay' => env('SOCIAL_COMMENT_PROCESSING_DELAY', 300),

    // Attempts and waits for the comment job.
    'comment_tries' => 5,
    'comment_backoff' => [60, 120, 300, 600],

    // Mark the comment job as failed in the queue (failed_jobs) on a final
    // failure, in addition to the CommentFailed event. The post is unaffected.
    'comment_fail_jobs' => false,

    // Seconds one comment run may take. Null derives it per platform: the
    // publish timeout plus three HTTP calls (with connect timeouts), the
    // reply-status waits and 60 s (about 390 s by default).
    'comment_timeout' => env('SOCIAL_COMMENT_TIMEOUT'),

    /*
    |--------------------------------------------------------------------------
    | Timeouts (seconds)
    |--------------------------------------------------------------------------
    |
    | Per platform: api.<platform>.timeout, connect_timeout (10),
    | publish_timeout and upload_timeout override these.
    |
    */

    // Read timeout for every API call.
    'http_timeout' => env('SOCIAL_HTTP_TIMEOUT', 60),

    // Meta create/publish calls (POST /feed, media_publish, threads_publish),
    // which can take well over a minute without having failed.
    'publish_timeout' => env('SOCIAL_PUBLISH_TIMEOUT', 120),

    // Byte and video uploads. Pull-based uploads can be slow because the
    // platform downloads the file during the request.
    'upload_timeout' => env('SOCIAL_UPLOAD_TIMEOUT', 300),

    // Downloads of remote media by the gateway.
    'download_timeout' => env('SOCIAL_DOWNLOAD_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Duplicate protection
    |--------------------------------------------------------------------------
    |
    | - CacheIdempotencyStore (default): no migration; records live
    |   idempotency_ttl seconds in the cache. A cache flush or eviction forgets
    |   them, so use a non-evicting store when that matters.
    | - DatabaseIdempotencyStore: durable. Publish and run the migrations
    |   (vendor:publish --tag=social-poster-migrations), then schedule
    |   social-poster:prune-idempotency daily.
    | - NullIdempotencyStore: no protection; a retried publish posts again.
    |
    */

    'idempotency' => CacheIdempotencyStore::class,
    'idempotency_table' => 'social_idempotency',
    'idempotency_connection' => env('SOCIAL_IDEMPOTENCY_CONNECTION'),
    'idempotency_ttl' => env('SOCIAL_IDEMPOTENCY_TTL', 604800),
    'idempotency_cache_store' => env('SOCIAL_IDEMPOTENCY_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    */

    // Turns the dispatched credentials (or a Credentials::ref() reference) into
    // the ones to use, at the start of every job run. Bind your own class to
    // look tokens up (and refresh them); a custom resolver also gets one retry
    // with re-resolved credentials when a platform rejects a token.
    'credentials_resolver' => IdentityCredentialsResolver::class,

    // Per-platform request authorizers (classes implementing
    // SocialPoster\Contracts\AuthorizesRequests), e.g. for DPoP-bound or
    // app-signed requests. Bluesky uses DefaultBlueskyAuthorizer when unset.
    // 'bluesky' => SocialPoster\Platforms\Bluesky\DefaultBlueskyAuthorizer::class,
    'authorizers' => [],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    // Log every failed platform response (driver, status, code/subcode, body) so
    // unmapped errors are easy to find and add to a driver's classifyError().
    'error_logging' => env('SOCIAL_ERROR_LOGGING', true),
    'error_log_channel' => env('SOCIAL_ERROR_LOG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Per-platform API settings
    |--------------------------------------------------------------------------
    |
    | Every driver reads each key with a baked-in default, so this block is
    | optional and every value below IS that default: change one to override
    | it. Keys every platform accepts (left out below, so they fall back to the
    | global values above):
    |
    | - timeout: HTTP read timeout (social.http_timeout; X uses 120).
    | - connect_timeout: HTTP connect timeout (10).
    | - publish_timeout: Meta create/publish calls (social.publish_timeout).
    | - upload_timeout: one upload request (social.upload_timeout).
    | - upload_run_seconds: how long one job run keeps uploading chunks before
    |   handing over to a continuation (240; YouTube 45). Also sizes the
    |   derived job timeout, see job_timeout.
    |
    | Media limits default to each platform's documented values. Raise them
    | for accounts with higher limits (X Premium, verified YouTube channels).
    |
    */

    'api' => [

        'facebook' => [
            // Graph API version. 1.1 is tested against v25.0.
            'graph_version' => env('SOCIAL_FACEBOOK_GRAPH_VERSION', 'v25.0'),
            // Unpublished /photos uploads of a multi-photo post: timeout and concurrency.
            'photo_timeout' => 60,
            'photo_concurrency' => 5,
            // Photos per multi-photo post.
            'max_attached_media' => 10,
            // Size limits: feed photo 4MB, story photo 10MB, video thumbnail 10MB, video 1GB.
            'photo_max_bytes' => 4194304,
            'story_photo_max_bytes' => 10485760,
            'thumbnail_max_bytes' => 10485760,
            'video_max_bytes' => 1073741824,
            // Duration limits in seconds: video, Reel, video story.
            'video_max_duration' => 1200,
            'reel_max_duration' => 90,
            'story_max_duration' => 60,
            // Upload a local Reel or story file directly instead of requiring a public URL.
            'local_video_upload' => true,
            // Seconds between the upload and the Reel/story finish call.
            'finish_delay' => 60,
            // Times (30 s apart) the finish waits for an upload Facebook is still fetching.
            'upload_checks' => 10,
            // Status polls after the finish, and seconds between them, before
            // the post is reported as published with state 'processing'.
            'status_polls' => 15,
            'status_interval' => 20,
            // Duplicate lookup after an ambiguous create: how far before the
            // create a found post may be (seconds), and how many unanswered
            // creates or unreadable lookups before failing as OutcomeUnknown.
            'verify_window' => 300,
            'verify_retries' => 5,
            // Seconds a throttled post/story lookup (after an unanswered create)
            // may be waited out before the publish fails as OutcomeUnknown.
            'verify_max_wait' => 3600,
            // Seconds on top of 10 minutes + finish_delay that a scheduled Reel
            // must be ahead at post() time (15 minutes by default).
            'reel_schedule_margin' => 240,
            // First comment length limit.
            'comment_max' => 8000,
        ],

        'instagram' => [
            // Graph API version, for graph.facebook.com and graph.instagram.com.
            'graph_version' => env('SOCIAL_INSTAGRAM_GRAPH_VERSION', 'v25.0'),
            // Login used when neither the 'login' credential nor the token
            // prefix (IG... = instagram, EAA... = facebook) tells.
            'default_login' => 'facebook',
            // Container status polling: interval, first check of a reel/story
            // container or carousel parent, first check of carousel video children.
            'poll_interval' => 60,
            'first_poll_after' => 20,
            'first_video_poll_after' => 60,
            // Consecutive failed status reads, and ambiguous media_publish rounds
            // (timeout, 5xx, throttle), tolerated before the error is reported.
            'max_poll_errors' => 5,
            'max_publish_errors' => 5,
            // "Media not ready" (2207027): fast rounds at poll_interval, then
            // this many seconds between rounds.
            'not_ready_fast_rounds' => 5,
            'not_ready_backoff' => 1800,
            // Age (seconds) after which a container is re-created, and how many
            // re-creations Meta-side processing errors may trigger.
            'container_max_age' => 82800,
            'max_restarts' => 2,
            // Carousels: item limit, Http::pool concurrency, pooled or sequential
            // child creation, and the media_type of video children.
            'carousel_max' => 10,
            'carousel_concurrency' => 5,
            'pool_children' => true,
            'carousel_video_media_type' => 'REELS',
            // Check content_publishing_limit before creating containers.
            'check_quota' => false,
            // retry_after_hint / retry_at for the daily publishing limit.
            'daily_limit_retry_after' => 86400,
            // Size limits: image 8MB, Reel and carousel video 300MB, story video 100MB, Reel cover 8MB.
            'image_max_bytes' => 8388608,
            'reel_max_bytes' => 314572800,
            'story_video_max_bytes' => 104857600,
            'cover_max_bytes' => 8388608,
            // Reel, carousel and story video frame rate range (fps).
            'min_video_frame_rate' => 23,
            'max_video_frame_rate' => 60,
            // Accept JPEG only (PNG then needs a converting MediaTransformer).
            'jpeg_only' => false,
            // Caption limits.
            'max_hashtags' => 30,
            'max_mentions' => 20,
            // First comment: length limit and minimum delay after the post.
            'comment_max_length' => 2200,
            'comment_min_delay' => 0,
        ],

        'threads' => [
            // Threads Graph API version.
            'graph_version' => env('SOCIAL_THREADS_GRAPH_VERSION', 'v1.0'),
            // Container status checks: first check (Meta advises ~30 s), then
            // the interval for text/image/carousel and for video containers.
            'initial_check_after' => 30,
            'poll_interval' => 10,
            'video_poll_interval' => 30,
            // Polls without any status before publishing anyway.
            'missing_status_polls' => 12,
            // Lookups of a post whose publish answer was lost (container
            // PUBLISHED) before it is reported with a null id.
            'recovery_lookups' => 3,
            // Create carousel items concurrently (sequential when false or when
            // an authorizer is configured), and how many at once.
            'pool_children' => true,
            'pool_concurrency' => 10,
            // Caption limit (emoji count as their UTF-8 bytes) and unique links per post.
            'max_characters' => 500,
            'max_links' => 5,
            'max_alt_text' => 1000,
            'max_carousel_items' => 20,
            // Media limits: image 8MB, video 1GB, 1-300 s, at most 1920 px wide, 23-60 fps.
            'max_image_bytes' => 8388608,
            'max_video_bytes' => 1073741824,
            'min_video_duration' => 1,
            'max_video_duration' => 300,
            'max_video_width' => 1920,
            'min_video_frame_rate' => 23,
            'max_video_frame_rate' => 60,
            // Replies (first comments): length limit, and what to do when the
            // credentials do not list their scopes: 'skip' or 'attempt'.
            'max_comment_characters' => 500,
            'comment_unknown_scopes' => 'skip',
            // Status reads on a reply container (before publishing it, and to
            // verify a publish whose answer was unclear), and seconds between them.
            'reply_status_polls' => 5,
            'reply_status_interval' => 2,
            // Check threads_publishing_limit (250 posts / 24 h) before publishing,
            // and the retry hint (seconds) when it is used up.
            'check_quota' => false,
            'quota_retry_after' => 3600,
        ],

        'x' => [
            // API root (default https://api.x.com/2; api.twitter.com/2 also works).
            // 'base_url' => 'https://api.x.com/2',
            // Timeout of API calls (initialize, finalize, status, posts): 120.
            // 'timeout' => 120,
            // APPEND segment size (grown, up to 5MB, so a file fits in 10000
            // segments): 4MB. Values above 5MB are capped at 5MB.
            // 'chunk_size' => 4194304,
            // Weighted caption limit. Raise for Premium accounts.
            'max_characters' => env('SOCIAL_X_MAX_CHARACTERS', 280),
            // Reply (first comment) limit; defaults to max_characters.
            // 'max_comment_characters' => 280,
            // Require a caption on media posts (when false, empty text is omitted).
            'require_text' => true,
            // Size limits: photo 5MB, GIF 15MB, video 8GB (X post limit without
            // Premium; Premium 16GB). 140s / 512MB are X's DM limits: set them
            // only as a stricter product rule.
            'max_image_bytes' => 5242880,
            'max_gif_bytes' => 15728640,
            'max_video_bytes' => 8589934592,
            // Video duration in seconds: 0.5 to 1200 (20 min; Premium 7500),
            // and minimum width/height.
            'min_video_duration' => 0.5,
            'max_video_duration' => 1200,
            'min_video_dimension' => 32,
            'max_alt_text' => 1000,
            // Upper clamp for the media STATUS check_after_secs.
            'max_check_after' => 30,
            // A 429 wait longer than this (seconds) fails as RateLimited with
            // retry_at; the wait when no reset header is usable; the 5xx retry delay.
            'max_rate_limit_wait' => 3600,
            'rate_limit_retry' => 120,
            'server_error_retry' => 60,
            // Look a post up before re-sending an ambiguous create (billed reads;
            // needs tweet.read and users.read). A post without text is sent
            // again only when this lookup ran and did not find it.
            'verify_reads' => env('SOCIAL_X_VERIFY_READS', false),
            // Quote posts (quote_tweet_id) need an X Enterprise API plan; false
            // refuses them at validation.
            'allow_quote_posts' => true,
            // Seconds of token life required before a call.
            'token_margin' => 60,
        ],

        'linkedin' => [
            // LinkedIn-Version header. Bump it at least yearly (426 = retired).
            'version' => env('SOCIAL_LINKEDIN_VERSION', '202605'),
            // Caption limit: 3000.
            // 'max_characters' => 3000,
            // Images per post, and pixels per image (fewer than 36,152,320).
            'max_images' => 20,
            'max_image_pixels' => 36152319,
            // Video: 75KB-500MB, 3 s-30 min. Documents: 100MB.
            'max_video_bytes' => 524288000,
            'min_video_bytes' => 76800,
            'min_video_duration' => 3,
            'max_video_duration' => 1800,
            'max_document_bytes' => 104857600,
            'max_alt_text' => 4086,
            // First comment: length limit and minimum delay after the post
            // (LinkedIn needs time to propagate a new post).
            'comment_max_characters' => 1250,
            'comment_min_delay' => 60,
            // Video parts sent at once (1-8; 1 when an authorizer is configured).
            'upload_concurrency' => 1,
            // Upload Media::video() thumbnails.
            'upload_thumbnail' => true,
            // Seconds to wait for video/document processing, and for LinkedIn to
            // notice an upload (WAITING_UPLOAD).
            'processing_timeout' => 3600,
            'waiting_upload_timeout' => 300,
            // A daily-throttle reset further away than this (seconds) fails as
            // RateLimited with retry_at.
            'max_rate_limit_wait' => 3600,
            // Read posts back to verify an ambiguous create (needs r_member_social
            // or r_organization_social; also on when the credentials list them).
            'verify_reads' => env('SOCIAL_LINKEDIN_VERIFY_READS', false),
            // Links resolved into mentions per post (LinkedInOptions::autoMentionUrls).
            'max_auto_mentions' => 10,
            // Automatic link cards: page fetch timeout, title/description clip
            // lengths, and thumbnail size cap.
            'link_card_timeout' => 8,
            'link_card_title_max' => 200,
            'link_card_description_max' => 300,
            'link_card_image_max_bytes' => 5242880,
        ],

        'tiktok' => [
            // Send brand_content_toggle / brand_organic_toggle = false on direct
            // posts when the options do not set them.
            'send_brand_defaults' => true,
            // Seconds TikTok may process after the init or upload (the job's deadline).
            'max_wait' => 3900,
            // After PUBLISH_COMPLETE of a public post: how long to wait for the
            // public video id (0 = do not wait) and how often to check.
            'public_id_wait' => 600,
            'public_id_poll_interval' => 30,
            // Status polling: base interval (doubles every 4 polls) and maximum.
            'poll_interval' => 15,
            'poll_max_interval' => 120,
            // Retries of an init TikTok refused with an error code, and fresh
            // publishes after a restartable failure.
            'init_retries' => 4,
            'max_restarts' => 2,
            // FILE_UPLOAD chunk size for files above 64MB (5-64MB): 10MB.
            // 'chunk_size' => 10485760,
            // Attempts per chunk on 5xx/connection errors, and the wait between
            // them (milliseconds, times the attempt number).
            'chunk_attempts' => 3,
            'chunk_retry_ms' => 2000,
            // Caption limits in UTF-16 code units: video caption (2200), photo
            // title (->title()) and photo description.
            // 'video_title_limit' => 2200,
            'photo_title_limit' => 90,
            'photo_description_limit' => 4000,
            // Photos: size limit, optional max width/height (null = unchecked).
            'photo_max_bytes' => 20971520,
            'photo_max_width' => null,
            'photo_max_height' => null,
            // Videos: 4GB, 3-600 s (the creator's own maximum is checked at publish), codecs, 23-60 fps.
            'video_max_bytes' => 4294967296,
            'video_min_duration' => 3,
            'video_max_duration' => 600,
            'video_codecs' => ['h264', 'hevc', 'vp8', 'vp9'],
            'video_min_frame_rate' => 23,
            'video_max_frame_rate' => 60,
        ],

        'youtube' => [
            // Resumable upload chunk (a multiple of 256 KiB): 16MB.
            'chunk_bytes' => 16777216,
            // Per-run upload budget: 45 s, so a run ends well inside the job timeout.
            // 'upload_run_seconds' => 45,
            // Seconds the whole upload may take; null = max(1 h, size / 512 KiB/s + 15 min).
            'upload_window' => null,
            // New sessions after an expired one, and consecutive 5xx/network
            // failures waited out before the upload fails as temporary.
            'max_session_restarts' => 2,
            'max_upload_stalls' => 6,
            // Read remote videos chunk by chunk with HTTP Range requests. false:
            // download them once when the upload starts and keep the copy until
            // it ends (for signed URLs that expire, e.g. Notion or S3 presigned).
            'range_reads' => true,
            // Limits: thumbnail 50MB, video 256GB / 12 h, description 5000 bytes.
            'thumbnail_max_bytes' => 52428800,
            'max_video_bytes' => 274877906944,
            'max_duration' => 43200,
            'description_max_bytes' => 5000,
            // Vertical or square videos up to this long are reported as likely Shorts.
            'shorts_max_seconds' => 180,
            // Processing after the upload: polls, base interval (grows every 5
            // polls), and the wall-clock window before publishing with a warning.
            'max_processing_polls' => 20,
            'processing_poll_seconds' => 30,
            'processing_window' => 3600,
            // Minimum delay before the first comment (a fresh upload is not commentable at once).
            'comment_delay' => 60,
        ],

        'bluesky' => [
            // Entryway: app-password sessions and DID resolution for a 'pds'
            // credential equal to it.
            'entryway' => env('SOCIAL_BLUESKY_ENTRYWAY', 'https://bsky.social'),
            // PLC directory for did:plc documents, and how long a resolved PDS is cached.
            'plc_directory' => 'https://plc.directory',
            'pds_cache_ttl' => 3600,
            // Public AppView: handle lookups for mentions (timeout, cache TTL)
            // and getRecord for other people's posts.
            'appview' => 'https://public.api.bsky.app',
            'mention_timeout' => 5,
            'handle_cache_ttl' => 86400,
            // Video service, its service DID, and the getUploadLimits pre-flight.
            'video_service' => 'https://video.bsky.app',
            'video_service_did' => 'did:web:video.bsky.app',
            'check_upload_limits' => true,
            // Video limits (bsky.social allows 300000000 bytes / 600 s) and the
            // processing deadline after the upload.
            'video_max_bytes' => 100000000,
            'video_max_duration' => 180,
            'video_max_wait' => 1800,
            // Per-image blob limit and alt text limit (graphemes).
            'image_max_bytes' => 1000000,
            'alt_text_max' => 2000,
            // uploadBlob timeout, and restarts after a garbage-collected blob.
            'blob_timeout' => 60,
            'blob_restarts' => 1,
            // A 429 whose reset is further away than this (seconds), e.g. the
            // PDS daily write budget or the createSession daily limit, fails as
            // RateLimited with retry_at instead of waiting in the queue.
            'max_rate_limit_wait' => 3600,
            // Host of post and comment permalinks.
            'permalink_host' => 'https://bsky.app',
            // Link cards: page fetch timeout, User-Agent, HTML and image caps.
            'link_preview_timeout' => 10,
            'link_preview_user_agent' => null,
            'link_preview_max_bytes' => 1000000,
            'link_preview_image_max_bytes' => 5000000,
            // Allow identifier + app_password credentials (createSession), and
            // how long such a session is cached.
            'app_password_mode' => env('SOCIAL_BLUESKY_APP_PASSWORD_MODE', false),
            'session_ttl' => 3600,
        ],

        'pinterest' => [
            'base_url' => 'https://api.pinterest.com/v5',
            // Send every call to the sandbox (apps with Trial access). A
            // 'sandbox' credential overrides it per connection.
            'sandbox' => env('SOCIAL_PINTEREST_SANDBOX', false),
            'sandbox_url' => 'https://api-sandbox.pinterest.com/v5',
            // auto | url | base64: how image pins send their media.
            'image_transfer' => 'auto',
            // Total image size above which the base64 fallback is skipped.
            'base64_max_bytes' => 20971520,
            // After an ambiguous pin create, look the pin up before re-creating:
            // first lookup after (seconds), attempts, pins listed per lookup.
            'dedupe_on_timeout' => true,
            'verify_after' => 60,
            'verify_retries' => 5,
            'verify_page_size' => 50,
            // POST /pins timeout.
            'pin_timeout' => 60,
            // The S3 video upload is one request (capped at job_timeout - 60
            // when job_timeout is set).
            'upload_timeout' => env('SOCIAL_PINTEREST_UPLOAD_TIMEOUT', 600),
            // Seconds Pinterest may process a video after the upload.
            'video_max_wait' => 1800,
            // Limits: image 20MB, video 2GB, 4-900 s.
            'image_max_bytes' => 20971520,
            'video_max_bytes' => 2147483648,
            'video_min_duration' => 4,
            'video_max_duration' => 900,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    */

    // Reads media metadata for the deep validation checks.
    'inspector' => FFProbeInspector::class,

    // ffprobe Process timeout; remote HEAD/Range reads use min(10, this).
    'inspector_timeout' => 20,

    // The ffprobe binary. Queue workers often lack Homebrew or /usr/local/bin on
    // their PATH, so set an absolute path there.
    'ffprobe_path' => env('SOCIAL_FFPROBE_PATH', 'ffprobe'),

    // Read remote media URLs during validation (HEAD, capped Range GET, ffprobe).
    'inspect_remote' => true,

    // Byte cap for the Range GET used to measure remote images.
    'inspector_range_bytes' => 2097152,

    // Bytes of a remote video fetched (head, plus tail when the index is at
    // the end) to probe it locally while private networks are blocked.
    'inspector_video_range_bytes' => 4194304,

    // Resolves media to a URL or bytes. FilesystemMediaGateway also re-hosts
    // local or disk files on a Storage disk (see 'media' below) for platforms
    // that fetch media from a public URL (Instagram, Facebook, Threads...).
    'gateway' => LocalMediaGateway::class,

    // Where temporary sp_* files go (downloads, conversions, inspection
    // ranges). Null = storage/app/social-poster-tmp. Schedule
    // social-poster:prune-temp daily to sweep leftovers.
    'temp_dir' => null,

    // Converts media a platform does not accept (png -> jpg for TikTok...).
    // Set SocialPoster\Transformers\ImageConvertingTransformer to enable it.
    'transformer' => NullMediaTransformer::class,

    // Media fetching and re-hosting. The 1.x defaults are permissive; 2.0 will
    // require local_roots, refuse http and block private networks by default.
    'media' => [
        // Directories local media may come from (null = any readable path).
        'local_roots' => null,
        // Allow plain-http media URLs.
        'allow_http' => env('SOCIAL_MEDIA_ALLOW_HTTP', true),
        // Refuse URLs resolving to private/reserved addresses. Null = on,
        // except in the 'local' environment and while running unit tests.
        'block_private_networks' => env('SOCIAL_MEDIA_BLOCK_PRIVATE_NETWORKS'),
        // Optional host allow-list: ['cdn.example.com', '*.example.org'].
        'allowed_hosts' => null,
        // Size cap for remote downloads (null = no cap beyond free disk space).
        'max_download_bytes' => env('SOCIAL_MEDIA_MAX_DOWNLOAD_BYTES'),
        // ImageConvertingTransformer refuses to decode images above this many
        // pixels (0 = no cap).
        'max_convert_pixels' => 50000000,
        // FilesystemMediaGateway: always | local_only | never.
        'rehost' => 'local_only',
        'rehost_disk' => 'public',
        'rehost_prefix' => 'social-poster',
        'rehost_visibility' => null,
        // Use temporaryUrl() for disk media (some platforms reject signed URLs).
        'temporary_urls' => false,
        'url_ttl' => 86400,
    ],

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | The first-party drivers (Facebook, Instagram, Threads, LinkedIn, TikTok,
    | X, YouTube, Bluesky, Pinterest) are registered by the package itself.
    | Override a first-party driver by its name, or set it to null to disable
    | it. In 1.x the names must be SocialPoster\Enums\Platform cases: a reserved
    | case without a driver (mastodon) can take your own, but any other name
    | cannot be posted to yet (custom platform names arrive in 2.0). Drivers
    | are resolved from the container, so they receive the inspector and
    | gateway automatically, and must implement SocialPlatform.
    |
    */

    'drivers' => [
        // 'mastodon' => App\Social\MastodonDriver::class,
        // 'facebook' => App\Social\MyFacebookDriver::class,
    ],

    // Static, single-account credentials per platform. Omit and pass credentials
    // at call time via ->using(...) for the multi-account case.
    'platforms' => [
        // 'facebook' => [
        //     'credentials' => [
        //         'account_id' => env('FB_PAGE_ID'),
        //         'page_access_token' => env('FB_PAGE_TOKEN'),
        //     ],
        // ],
        // 'instagram' => [
        //     'credentials' => [
        //         'account_id' => env('IG_USER_ID'),
        //         'access_token' => env('IG_ACCESS_TOKEN'),
        //     ],
        // ],
    ],
];
