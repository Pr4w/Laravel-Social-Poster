# Changelog

All notable changes to `pr4w/laravel-social-poster` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Upgrade steps are in
[UPGRADE.md](UPGRADE.md).

## [1.1.0] - 2026-11-19

### Added

**New platforms**

- Bluesky (`BlueskyDriver`, `BlueskyOptions`, `DefaultBlueskyAuthorizer`): text with link, hashtag and
  mention facets, link cards, 1 to 4 images, video with captions, quotes, replies and threads,
  threadgates and postgates written atomically with the post, and a first comment as a reply. Auth by
  OAuth DPoP (`access_token` + `dpop_private_key`), a bearer session (`access_jwt`), or app passwords
  (`identifier` + `app_password`, opt-in with `api.bluesky.app_password_mode`).
- Pinterest (`PinterestDriver`, `PinterestOptions`, `PinterestImageTransfer`): image, carousel (2 to 5)
  and video Pins, sections, the Sandbox host for Trial access, and a board lookup before any create
  that may repeat an earlier one.

**Platform features**

- First comments on Threads (a reply), X (a self-reply), YouTube (a comment thread) and Bluesky (a
  reply), alongside Facebook, Instagram and LinkedIn.
- Facebook: Reels and video stories (with local file upload), photo stories, scheduled posts, videos
  and Reels (`FacebookOptions::$scheduledAt`), link cards (`$link`), `$asReel`, `extra['photo']` for the
  `/photos` uploads, and alt text.
- Instagram: Instagram Login accounts on graph.instagram.com (the `login` credential or the token
  prefix; `InstagramDriver::loginFor()`), `InstagramOptions::$shareToFeed`, `$thumbOffsetMs` and
  `$collaborators`, alt text, per-item carousel params from `Media::$meta['instagram']`, the
  `Media::video()` thumbnail as reel cover fallback (left out when known to exceed
  `cover_max_bytes`), and the `check_quota` pre-flight.
- Threads: `ThreadsOptions` (reply control, topic tag, link attachment, poll, quote, reply, location,
  country allow-list, AI label, spoiler), `ThreadsPoll`, `ThreadsReplyControl`,
  `ThreadsDriver::publishingQuota()` and the `check_quota` pre-flight.
- X: `XOptions`, `XPoll`, `XReplySettings`; GIFs, alt text, polls, replies and quotes; deep-merged
  `extra` (`media.tagged_user_ids`, `reply.exclude_reply_user_ids`); `api.x.verify_reads` recovery
  lookups; `api.x.allow_quote_posts`.
- LinkedIn: `LinkedInOptions::$visibility`, `$disableReshare`, `$feedDistribution`, `$targetEntities`,
  `$reshareOf`, `$poll`, `$article`, `$autoLinkCard`, `$autoMentionUrls`; `LinkedInPoll`,
  `LinkedInArticle` and `LinkedInMentionResolver`; alt text and video thumbnails; `api.linkedin.verify_reads`.
- TikTok: photo posts and carousels (direct or draft), chunked `FILE_UPLOAD` of local or downloaded
  video (`TikTokTransfer`), `TikTokOptions::$brandContent`, `$brandOrganic`, `$isAigc` and
  `$privacyFallbacks`, the creator's maximum video duration check, waiting for the public video id,
  `TikTokDriver::permalink()`, `TikTokFailReason`, and webhook helpers (`TikTokWebhook::verify()`,
  `sign()`, `parse()`, `TikTokWebhookEvent`).
- YouTube: resumable upload staged across job runs with session recovery, an optional finalize stage
  (processing, thumbnail, playlists), `YouTubeOptions::$publishAt`, `$notifySubscribers`,
  `$containsSyntheticMedia`, `$embeddable`, `$license`, `$defaultLanguage`, `$defaultAudioLanguage`,
  `$playlistIds` and `$waitForProcessing`, a Shorts guess (`likely_short`, `shorts_url`), and Range
  reads of remote sources.

**Builder and poster**

- Per-platform credentials and options: `using($credentials, $for)`, a map keyed by platform, and a
  variadic `withOptions(...$options)`.
- `bestEffort()`, `afterCommit()`, `notAfter()`, `inlineComment()` and `fromPost()`.
- `post()` returns a `QueuedPost` (correlation id, queued, deduplicated and failed platforms, store key
  per platform).
- `outcome()`, `status()` and `reconcile()` on the builder and on `SocialPoster`, with
  `Poster::RECONCILE_*` results; `SocialPoster::requiredScopes()`.
- `SocialPoster::fake()` with `Testing\SocialPosterFake` and `Testing\FakePostBuilder`: scripted outcomes
  and `assert*` methods.
- `ValidationResult::warnings()` / `hasWarnings()` (non-blocking, and in `toArray()` under an optional
  `warnings` key, absent when empty so the v1.0 shape is kept), with an `idempotency` warning when an
  explicit key cannot keep accounts apart; `ValidationResult::withError()`.
- With `->idempotencyKey()` and credentials that name no account (X without `user_id`, TikTok without
  `open_id`, YouTube without `channel_id`...), two accounts sharing the key share one record, and the
  second replays the first. The package logs a one-time warning per platform and process and adds the
  `idempotency` validation warning; add the identity credential or a generic `account` credential.

**Duplicate safety and recovery**

- `CacheIdempotencyStore`, a zero-migration store on the cache.
- `AtomicIdempotencyStore` contract (claim and release a lease, `committing`, `failed`, account
  binding, status), implemented by every first-party store, plus `record()` on the cache and database
  stores. `KeyCollisionException` for a key reused by another account.
- Migration `2026_11_20_000000_add_claim_columns_to_social_idempotency_table` (additive) for
  `DatabaseIdempotencyStore`.
- The commit protocol: `PublishOutcome::commit()`, `AbstractPlatform::commit()`, `Pending::$commits`,
  `$committed`, `$deadline` and `$possiblySent`, and the reserved `_` state keys (`Pending::RECOVERING`,
  `COMMITTING_SINCE`, `STARTED_AT`, `ATTEMPT`, `RESTARTS`). A step that may have created the post runs
  again with `_recovering` set, and every first-party driver verifies on the platform before creating.
- Commands `social-poster:reconcile`, `social-poster:prune-idempotency` and `social-poster:prune-temp`.

**Credentials**

- `CredentialsResolver` contract (`social.credentials_resolver`, default `IdentityCredentialsResolver`),
  called at the start of every job run and by `postNow()`, with one re-resolve retry of a rejected
  token when a custom resolver is bound.
- `Credentials::ref()` (keeps tokens out of queue payloads), `with()`, `filled()`, `expiresAt()`,
  `expiresWithin()`, `scopes()`, `missingScopes()`, `redacted()`, `isReference()`, `reference()`.
- `AuthorizesRequests` contract and `social.authorizers` for per-platform request signing.
- The `Feature` enum and scope checks: `requiredScopes()`, `scopesFor()`, `missingScopes()`.

**First comments**

- `SupportsFirstComment` contract with `CommentRules` (length, counter, minimum delay, scopes,
  unknown-scope policy) and `CommentResult` (id, url, payload).
- The comment job carries the full post and the parent result, records the comment under
  `{key}:comment`, retries with backoff, and passes `_recovering` in the parent payload after an
  attempt whose answer was lost (the contract is on `SupportsFirstComment::addFirstComment()`).
  Every first-party driver honours it: Facebook, Instagram, LinkedIn and YouTube look for the
  account's own comment with the same text, Threads for its own reply to the post, Bluesky reuses its
  record key, X reads a duplicate refusal as the earlier reply; when the comments cannot be read, the
  recovery fails as `OutcomeUnknown` with `possiblyPublished` instead of commenting twice.

**Events**

- `PostRetrying`, `PostProcessing`, `PostDeduplicated`, `CommentSkipped` and `CredentialsRejected`.
- New trailing properties: `PostPublished::$correlationId`, `$idempotencyKey`, `$attempt`, `$replayed`;
  `PostFailed::$correlationId`, `$idempotencyKey`, `$attempt`, `$willRetry`, `$possiblyPublished` and
  `errorSummary()`; `PostQueued::$correlationId`, `$idempotencyKey`; `CommentPublished::$url`,
  `$payload`; `CommentFailed::$attempt` and `errorSummary()`.
- Events serialise safely for queued listeners, and payloads from older releases unserialise.

**Results and errors**

- `PostResult::$state` (`published`, `draft`, `scheduled`, `private`, `processing`) and `$idKind`,
  `isPublished()`, `isScheduled()`, `isDraft()`, `isProcessing()`, `toArray()` and `fromArray()`.
- `FailureReason` cases `InvalidContent`, `Validation`, `OutcomeUnknown`, `ProcessingTimeout`,
  `Network`, `NotFound`, `QuotaExceeded`, `Expired` and `Misconfigured`, with `requiresReconnect()` and
  `category()` (`retry`, `verify`, `reconnect`, `user_fix`, `operator`, `not_found`).
- `SocialPosterException::addContext()`, `withFlags()`, `possiblyPublished()`, `isTerminal()`,
  `needsReconnect()` and `errorSummary()`; `TemporaryException::connection()` and `requestNeverSent()`;
  `UnsupportedPlatformException`.

**Media**

- `Media::fromDisk()`, a trailing `$meta` on every factory (known width, height, duration, size, frame
  rate...), `hasForeignScheme()`, `disk()`, `diskPath()`, `isOnDisk()`, `knownMetadata()`.
- `FilesystemMediaGateway`: re-hosts local, disk or (optionally) remote media on a Laravel disk for
  pull-only platforms.
- `MediaTransformer` contract (`social.transformer`), `NullMediaTransformer` and
  `ImageConvertingTransformer` (GD or Imagick).
- Caption counters in `Support\Text`: `CaptionCounter`, `MbStrlenCounter`, `Utf16Counter`,
  `GraphemeCounter`, `XWeightedCounter`, `ThreadsCounter`, and `Urls`. `CaptionRules` takes a counter
  and `maxBytes`.
- `MediaMetadata::$frameRate` and `$rotation`; `ImageRules` gains `maxPixels`, `maxWidth`, `maxHeight`,
  `minSizeBytes`, `convert`, `minWidth`, `minHeight`; `VideoRules` gains `minFrameRate`, `maxFrameRate`,
  `minSizeBytes`.
- `Support\PublicUrl`: the URL policy used by every download and page fetch.

**Driver authoring**

- `AbstractPlatform` helpers: `requireId()`, `stream()`, `clampDelay()`, `requiredCredentials()`,
  `assertCredentials()`, `assertTokenFresh()`, `identityKeys()` (read with the public
  `identityCredentialKeys()`), `accountKey()`, `requiredScopes()`, `transformMedia()`;
  `Concerns\UploadsInChunks` for staged uploads; `Support\RetryAfter`.

**Configuration and operations**

- Every per-platform setting is documented in `config/social.php` with its default; the `api`,
  `async`, `sync` and `media` blocks are deep-merged with the package defaults at boot.
- New keys: `connection`, `after_commit`, `encrypt_jobs`, `tries`, `backoff`, `job_timeout`,
  `max_restarts`, `lock_store`, `async.*`, `sync.*`, `max_recheck_after`, `comment_processing_delay`,
  `comment_tries`, `comment_backoff`, `comment_fail_jobs`, `comment_timeout` (`SOCIAL_COMMENT_TIMEOUT`),
  `http_timeout`, `publish_timeout`, `download_timeout`, `idempotency_connection`, `idempotency_ttl`,
  `idempotency_cache_store`, `credentials_resolver`, `authorizers`, `inspector_timeout`, `ffprobe_path`,
  `inspect_remote`, `inspector_range_bytes`, `inspector_video_range_bytes`, `temp_dir`, `transformer`
  and the `media` block.
- Boot warnings (`SocialPosterServiceProvider::configurationWarnings()`) for a queue `retry_after` not
  longer than the job timeout (the longest publish step or first-comment run), a
  `NullIdempotencyStore`, an `array` or `null` cache store holding `social.lock_store`,
  `social.idempotency_cache_store` or their `cache.default` fallback on an asynchronous queue (it
  disables cross-worker duplicate protection), a Graph version below v25.0, and driver names that are
  not `Platform` cases.
- A derived publish job timeout (`PublishToConnectionJob::configuredTimeout()`), and a derived comment
  job timeout (`AddFirstCommentJob::configuredTimeout()`).
- `tests/Live`: opt-in checks against real accounts, and a `Database` test suite for concurrency.
- CI on PHP 8.2 to 8.5 and Laravel 11 to 13, plus MySQL, MariaDB, PostgreSQL, redis and memcached.

### Changed

- The default idempotency store is `CacheIdempotencyStore` (v1.0: `NullIdempotencyStore`, no
  protection).
- `social.comment_delay` defaults to 60 s (v1.0: 10 s). A driver can require more (LinkedIn and
  YouTube: 60 s).
- Jobs are dispatched after the open database transaction commits (`social.after_commit`, true), and
  their payloads are encrypted when the app has a key (`social.encrypt_jobs`, true).
- The publish job has a timeout (660 s by default, 960 s for Pinterest), fails a run killed by it
  (`failOnTimeout`), and retries up to `social.tries` (5) per step with `social.backoff`. The comment job
  makes `social.comment_tries` (5) attempts with a derived timeout of about 390 s (v1.0: 3 attempts and
  no job timeout; a comment job queued by v1.0 runs with 120 s).
- First-party drivers are registered in code; `config('social.drivers')` holds overrides only, and
  `null` disables a driver.
- `postNow()` retries temporary errors in-process (`social.sync.retries`), hands a long publish to the
  queue after `social.sync.max_wait` (result state `processing`), uses the idempotency store, queues the
  first comment instead of adding it inline (`inlineComment()` restores that), and never stops a fan-out
  half way. It renews its idempotency lease before every step and records each step, so a same-key
  `post()` or `postNow()` during a long synchronous upload steps aside (`PostDeduplicated`), and a
  dispatch that takes over a crashed or stalled run resumes it.
- `post()` returns `QueuedPost` instead of `void`; `withOptions()` is variadic and `using()` takes a
  trailing `$for`.
- An unknown platform throws `UnsupportedPlatformException` (v1.0: `ValueError`).
- Missing credentials fail as `Misconfigured` (v1.0: `InvalidToken`), before any request.
- Connection failures become `TemporaryException` with reason `Network` and context `request_sent`
  (v1.0: `Timeout`).
- Daily quotas (YouTube, TikTok, Instagram 2207042, Bluesky, LinkedIn day throttles, X 24-hour limits)
  fail at once as `PermanentException(RateLimited)` with `retry_at` and `retry_after_hint` instead of
  waiting in the queue.
- `FailureReason::category()` has a `verify` bucket: `OutcomeUnknown` moved there from `retry`, and
  `errorSummary()` reports `verify` for any failure flagged `possibly_published`.
- A refusal that a reconnect cannot fix (TikTok privacy refusals, YouTube `forbiddenPrivacySetting` /
  `forbiddenLicenseSetting`) keeps `InsufficientPermissions` with `reconnect = false`: no
  `CredentialsRejected`, category `user_fix`.
- The first comment is validated with the post: an over-long comment fails validation instead of
  failing after the post is live.
- The account identity in idempotency keys comes from the driver's identity key (`account_id`,
  `user_id`, `author`, `open_id`, `channel_id`, `did`, `board_id`), a generic `account` credential, or a
  `Credentials::ref()`. The token is never used; without an identity, keys are scoped as in v1.0.
- `Credentials` implements `JsonSerializable` and serialises to its redacted copy.
- Remote media downloads go through the URL policy (private networks blocked outside the `local`
  environment and tests, redirects re-checked, size capped). A 4xx on a media URL is a permanent
  `MediaRejected` (v1.0: retried).
- Temporary files moved from the system temp directory to `storage/app/social-poster-tmp`
  (`social.temp_dir`).
- Facebook and Instagram default to Graph API v25.0 (v1.0: v23.0).
- Captions are counted with each platform's rule: X weighted characters, Threads emoji as UTF-8 bytes
  with at most 5 links, Bluesky graphemes and bytes, TikTok UTF-16 code units.
- YouTube: `publish()` returns a `Pending` after opening the upload session; the upload runs in job
  runs of about 45 s. Sources that are not read in ranges (cloud disks, hosts without Range,
  `range_reads = false`) are copied once per upload instead of once per run, so an expiring signed URL
  no longer fails an upload once it was read; `social-poster:prune-temp` sweeps the `sp_youtube_*`
  copies a chain the job ended leaves behind. A run that has already sent chunks leaves the last
  chunk to a fresh run when it may not finish inside the job timeout, so the queue does not kill the
  run while the video is being created. `YouTubeOptions::$privacyStatus` is nullable (null uploads as public unless
  scheduled) and `$categoryId` defaults to `null` (v1.0: `'22'`). `Media::video(thumbnail:)` is used as a
  fallback cover (v1.0 ignored it).
- LinkedIn: text and image posts create the post in a separate commit step (one more continuation);
  documents wait for `AVAILABLE`; video limits follow LinkedIn's 75 KB to 500 MB (v1.0: up to 200 MB); a
  malformed `author` fails as `Misconfigured`; an orphan `{{mention:N}}` marker fails validation.
- X: video defaults follow X's post limits, 1200 s and 8 GB (v1.0: 140 s and 512 MB, the DM limits);
  `media.write` is part of the publish scopes.
- TikTok: the photo caption goes to `description` (4,000) and `->title()` to `title` (90); direct posts
  send the brand toggles as `false` unless set (`api.tiktok.send_brand_defaults`); a draft video sends no
  caption; `reached_active_user_cap` is permanent with `retry_at`.
- Threads: the video rule caps the width at 1920 px (v1.0: the height).
- `composer.json` requires `laravel/framework` ^11|^12|^13, `guzzlehttp/guzzle` ^7.8,
  `symfony/process` ^7|^8 and the `fileinfo`, `json`, `mbstring` and `openssl` extensions.

### Deprecated

- `SupportsComments::comment()` and the adapters on `FacebookDriver`, `InstagramDriver` and
  `LinkedInDriver`: implement `SupportsFirstComment`. Removed in 2.0.
- `ThreadsDriver::publishContainer()`: no longer called; override `publishNow()`.
- `AbstractPlatform::bytes()` for large files: use `stream()`.
- `LinkedInDriver::assetStatus()`, `uploadVideoChunks()` and `urnId()`; `XDriver::appendChunks()` and
  `mediaStatus()`; `TikTokDriver::utf16Length()`.
- The permissive media defaults: `social.media.local_roots = null` (logs a deprecation notice) and
  `social.media.allow_http = true`. 2.0 requires `local_roots`, refuses plain http and blocks private
  networks by default.

### Removed

- The protected `TikTokDriver::initVideo()`, `initPhoto()`, `putChunks()` and `awaitPublish()`: the
  phased flow no longer calls them. Override `prepareVideo()`, `preparePhoto()`, `prepareFileUpload()`,
  `putChunk()` or `wait()`.

### Fixed

- A listener, store or queue error after a successful publish no longer re-publishes the post or
  reports it as failed.
- A timeout or lost connection on a create call (Facebook `/feed`, Instagram `media_publish`,
  `threads_publish`, X `POST /2/tweets`, LinkedIn `POST /rest/posts`, YouTube's final chunk, TikTok's
  init, Pinterest `POST /pins`) is no longer retried blind: the step is verified before anything is
  created again, or reported as possibly published.
- An idempotency key now includes the account, so a fan-out to two accounts of one platform no longer
  drops the second post or replays the first account's result.
- `DatabaseIdempotencyStore::forget()` no longer deletes published records, and `markPending()` never
  downgrades them.
- Unexpected exceptions and worker deaths are reported (`PostFailed`) instead of being lost silently.
- A post that is still processing when its poll budget ends is no longer reported as failed:
  continuations run against a wall-clock deadline.
- The 'sync' queue no longer drops temporary failures silently.
- First comments: a replay no longer posts the comment twice; LinkedIn's 404 before the post
  propagates is retried; comments are no longer attempted on stories or scheduled posts; a comment
  failure in `postNow()` no longer marks the live post as failed.
- Facebook: Reel and video-story ids are prefixed with the Page id (Graph refused the bare `post_id`
  for comments and permalinks); subcode 2207003 is retried; transient codes are `ServerError`, not
  `RateLimited`.
- Instagram: accounts connected with Instagram Login work (graph.instagram.com); `PUBLISHED`,
  `EXPIRED` and Meta-side `ERROR` containers are handled; token, throttle, permission and the
  documented 2207xxx codes are mapped.
- Threads: the carousel parent is polled before publishing (4279009 is retried), `PUBLISHED` and
  `EXPIRED` containers are handled, and token, throttle and link-limit errors are mapped.
- X: 1080p and vertical videos are accepted (v1.0 capped them at 1280x1280); a STATUS without
  `processing_info` counts as ready; duplicate, locked/suspended, credits and 429 answers are mapped.
- LinkedIn: video status is read from the Videos API (v1.0 used the legacy Assets API); `|` and a `#`
  that does not start a hashtag are escaped; 401, 403, 408, 409 and 429 are classified.
- TikTok: `spam_risk_*` codes are permanent; `token_not_authorized_for_specified_publish_id` is
  `NotFound`, not an invalid token; restartable `FAILED` reasons publish again.
- YouTube: the upload resumes from the session instead of starting a new one (which could create a
  second video), and the description limit is 5,000 bytes.
- `composer.json` no longer conflicts with Laravel 11 and 12 (`symfony/process` ^7|^8) and declares the
  HTTP client it uses.

### Security

- Credentials are redacted in `var_dump()`, `print_r()` and `json_encode()`, marked
  `#[\SensitiveParameter]`, and kept out of queue payloads with `Credentials::ref()`. Job payloads are
  encrypted by default.
- Error logs redact tokens (including Bearer and DPoP values in raw bodies) and drop the
  `Authorization`, `Cookie` and `DPoP` headers; the request body a TikTok init error carries has its
  URL query strings redacted. Exceptions given to queued listeners are portable copies without
  requests, responses, credentials or frame arguments.
- Media URLs and link-card pages are fetched under one policy: private and reserved addresses blocked
  (outside the `local` environment and tests), the connection pinned to the checked address, every
  redirect checked again, optional host allow-list, download size caps that stop the transfer.
- Sources on schemes other than `http(s)://` and `file://` are refused before PHP opens them; disk
  paths that leave the disk root are refused; `social.media.local_roots` limits local files.
- `FilesystemMediaGateway` keeps an extension only for media formats (anything else is stored as
  `.bin`), so a re-hosted copy can never be served as HTML, SVG or PHP.
- `ImageConvertingTransformer` refuses images above `social.media.max_convert_pixels` before decoding
  them.
- ffprobe never fetches a remote URL itself while private networks are blocked: the first bytes are
  downloaded and probed locally.
- The YouTube upload session URL must be on a Google host, and a Pinterest `api_base` on
  `*.pinterest.com`, so the token is never sent elsewhere.

## [1.0.0] - 2026-09-03

### Added

- First release: a fluent builder (`SocialPoster::on(...)->using(...)->media(...)->caption(...)`) with
  `validate()`, `postNow()` and queued `post()`.
- Drivers for Facebook, Instagram, Threads, LinkedIn (with `LinkedInMention`), X, TikTok (drafts and
  direct posts) and YouTube.
- Asynchronous publishing through `Pending` states and a self-re-dispatching `PublishToConnectionJob`.
- First comments on Facebook, Instagram and LinkedIn (`SupportsComments`, `AddFirstCommentJob`).
- Optional duplicate protection with `DatabaseIdempotencyStore`.
- `TemporaryException`, `PermanentException`, `ValidationException` and `FailureReason`; error logging
  of every failed platform response.
- `PostQueued`, `PostPublished`, `PostFailed`, `CommentPublished` and `CommentFailed` events with
  correlation metadata.
- `LocalMediaGateway`, `FFProbeInspector`, typed platform options and the `extra` passthrough.

[1.1.0]: https://github.com/Pr4w/Laravel-Social-Poster/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/Pr4w/Laravel-Social-Poster/releases/tag/v1.0.0
