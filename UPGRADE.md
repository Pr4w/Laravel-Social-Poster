# Upgrade guide

## From 1.0 to 1.1

1.1 keeps the 1.0 builder, events, value objects and driver contract. Code that publishes through
`SocialPoster::on(...)` keeps working. What needs attention is operational (the idempotency store, the
queue's `retry_after`, two scheduled commands), a few changed defaults in a config file published under
1.0, and a short list of behaviour and signature changes. The full list of changes is in
[CHANGELOG.md](CHANGELOG.md).

### Checklist

1. [Update the package](#1-update-the-package).
2. [Review your published config](#2-review-your-published-config).
3. [Choose the idempotency store](#3-choose-the-idempotency-store) (and run the new migration if you use the database store).
4. [Check the queue connections](#4-check-the-queue-connections): `retry_after` above the job timeout, a shared cache store.
5. [Schedule the cleanup commands](#5-schedule-the-cleanup-commands).
6. [Deploy with jobs in flight](#6-deploy-with-jobs-in-flight).
7. Read [behaviour changes](#7-behaviour-changes) and, if you extend the package, [code changes](#8-code-changes-for-subclasses-and-custom-drivers).

### 1. Update the package

```bash
composer update pr4w/laravel-social-poster
```

`composer.json` now requires `laravel/framework` ^11|^12|^13, `guzzlehttp/guzzle` ^7.8, `symfony/process`
^7|^8 (1.0 required ^8 only) and the PHP extensions `fileinfo`, `json`, `mbstring` and `openssl`. PHP 8.2
is still the minimum. `ext-intl`, `ext-dom` and `ext-gd` or `ext-imagick` are suggested (Bluesky and
Threads counting, Bluesky link cards, image conversion).

### 2. Review your published config

A `config/social.php` published under 1.0 keeps working: every key has an inline default, and the
`api`, `async`, `sync` and `media` blocks are deep-merged with the package defaults at boot, so the new
per-platform settings (and the Bluesky and Pinterest drivers) reach you without re-publishing. Values
your file sets explicitly still win, and three of them carry 1.0 defaults you probably want to change:

| Key in a 1.0 file | 1.0 value | 1.1 default | Why change it |
|---|---|---|---|
| `idempotency` | `NullIdempotencyStore::class` | `CacheIdempotencyStore::class` | Null means no duplicate protection: a retried publish posts again. A boot warning says so on an asynchronous queue |
| `comment_delay` | `env('SOCIAL_COMMENT_DELAY', 10)` | 60 | Platforms need time before a new post is commentable (LinkedIn and YouTube enforce 60 s anyway) |
| `api.facebook.graph_version`, `api.instagram.graph_version` | `v23.0` | `v25.0` | The drivers are tested against v25.0; an older value logs a boot warning |

The 1.0 `drivers` list is harmless: the first-party drivers are now registered in code and that key
only holds overrides (`null` disables a driver).

Diff your file against the package's, or re-publish it:

```bash
php artisan vendor:publish --tag=social-poster-config --force
```

### 3. Choose the idempotency store

The store remembers what was published so a retried or redelivered job never posts twice.

- **`CacheIdempotencyStore` (new default, no migration).** Records live in the cache for
  `social.idempotency_ttl` (7 days) after their last write, and leases use the cache's locks. The
  cache must be shared by every worker (redis, database, memcached) and should not evict: a flush or an
  eviction forgets records. Point it at a dedicated store with `SOCIAL_IDEMPOTENCY_CACHE_STORE` and
  `SOCIAL_LOCK_STORE` if your default cache is `array`, `file` on several servers, or an LRU cache.
- **`DatabaseIdempotencyStore` (durable).** If you used it in 1.0, publish and run the new additive
  migration `2026_11_20_000000_add_claim_columns_to_social_idempotency_table` (it adds the lease,
  account, result-state and `key_hash` columns and backfills `key_hash`):

  ```bash
  php artisan vendor:publish --tag=social-poster-migrations
  php artisan migrate
  ```

  The create migration you published under 1.0 keeps its name and is skipped; it now also no-ops when
  the table exists. Until the new migration runs, the store works in a reduced mode (cache-lock
  claims, no account binding) and logs a warning. Schedule `social-poster:prune-idempotency` (below).
- **`NullIdempotencyStore`** keeps 1.0's behaviour (no protection). Only use it with the `sync` queue.

### 4. Check the queue connections

- **`retry_after`.** In 1.0 the publish job had no timeout. In 1.1 it declares one, which takes
  precedence over the worker's `--timeout`: `social.job_timeout`, or derived per platform (660 s by
  default, 960 s for Pinterest). Every queue connection the package uses (`social.connection`, else your
  default) needs a `retry_after` longer than that, or the queue redelivers a running step to a second
  worker. On SQS, raise the queue's visibility timeout. The provider logs a warning at boot when
  `retry_after` is too short.
- **The comment job** now derives its timeout (about 390 s; `social.comment_timeout` /
  `SOCIAL_COMMENT_TIMEOUT` overrides it) and makes `social.comment_tries` (5) attempts. The
  `retry_after` warning counts the comment run too, which can be the longer one when
  `social.job_timeout` is set low.
- **A shared cache store** for the job locks (`social.lock_store`, else the default store) and, with
  `CacheIdempotencyStore`, the records (`social.idempotency_cache_store`, else the default store). A
  store with the `array` or `null` driver on an asynchronous queue protects nothing across workers;
  the provider warns at boot.
- **Transactions.** Jobs are now dispatched after the surrounding database transaction commits
  (`social.after_commit`, true). Set it to `false`, or call `->afterCommit(false)`, to dispatch at once.
- **Payload encryption.** Job payloads are encrypted when the app has an `APP_KEY`
  (`social.encrypt_jobs`, true). Payloads queued unencrypted by 1.0 still run.

### 5. Schedule the cleanup commands

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('social-poster:prune-temp')->daily();
Schedule::command('social-poster:prune-idempotency --days=30')->daily(); // DatabaseIdempotencyStore only
```

Temporary media moved from the system temp directory to `storage/app/social-poster-tmp`
(`social.temp_dir`). Run `php artisan social-poster:prune-temp --legacy` once after the upgrade to sweep
`sp_*` files 1.0 left in the system temp directory. `prune-temp` also removes the upload copies TikTok
(`sp_tiktok_*`) and YouTube (`sp_youtube_*`, as large as the video) keep between runs when a chain ends
without cleaning up (a deadline, retries used up, a killed worker).

### 6. Deploy with jobs in flight

Publish and comment jobs queued by 1.0 still unserialize and run under 1.1: their continuations resume
(each driver maps the 1.0 phase names), and they keep the store key 1.0 recorded them under. Publishes
recorded by 1.0 under an `->idempotencyKey()` are replayed by 1.1 for the same key.

Still, the safest deploy is to let the queues drain (or pause dispatching) before switching code, then
restart the workers so they load 1.1:

```bash
php artisan queue:restart
```

Jobs queued by 1.1 are not meant to run on 1.0 code: restart every worker right after the deploy.

### 7. Behaviour changes

**Builder and results**

- `post()` returns a `QueuedPost` (it returned nothing). Ignoring the return value is fine.
- Fan-outs should pass credentials per platform: `->using(['instagram' => [...], 'linkedin' => [...]])`
  or `->using($bag, 'instagram')`. A single shared bag still works, but it sends every platform the same
  keys.
- `postNow()`:
  - retries temporary errors in-process (`social.sync.retries`, 3);
  - hands a publish that outlasts `social.sync.max_wait` (120 s) to the queue and returns a success
    with `state = processing` and a null id; the outcome arrives as events;
  - queues the first comment instead of adding it inline; call `->inlineComment()` (or set
    `social.sync.inline_comments`) to keep 1.0's behaviour;
  - uses the idempotency store, like `post()`: it holds and renews a lease on the key while it runs
    and records each step, so a same-key dispatch during a long synchronous upload is deduplicated
    (`PostDeduplicated`), and one that finds a crashed or stalled run resumes it.
- An unknown platform name throws `UnsupportedPlatformException` (1.0: `ValueError`).
- The first comment is validated with the post. An over-long comment now fails validation
  (`ValidationException`, or a skipped platform under `->bestEffort()`) instead of failing after the
  post is live.
- `PostPublished` can fire more than once for one post (a replay after a crash, a second dispatch of a
  published key, a `reconcile()`), with `$replayed = true`. Make listeners idempotent, keyed on
  `$event->idempotencyKey`. Check `$event->result->state` too: scheduled posts, TikTok drafts and
  posts still processing also arrive as `PostPublished`.

**Errors**

- Missing credentials fail as `Misconfigured` (1.0: `InvalidToken`), before any request. Code that
  disconnects an account on `InvalidToken` no longer does so for a configuration mistake.
- Connection failures are `TemporaryException` with reason `Network` and context `request_sent`
  (1.0: `Timeout`).
- Daily quotas are `PermanentException(RateLimited)` with `context['retry_at']`, never waited out in the
  queue: YouTube's quota, TikTok's daily caps, Instagram 2207042, Bluesky's daily budget, LinkedIn's
  daily throttles, X's 24-hour limits. Reschedule from `retry_at`.
- A remote media URL answering 4xx fails permanently (`MediaRejected`); 1.0 retried it.
- New `FailureReason` cases exist (`InvalidContent`, `Validation`, `OutcomeUnknown`,
  `ProcessingTimeout`, `Network`, `NotFound`, `QuotaExceeded`, `Expired`, `Misconfigured`). Give
  exhaustive `match` blocks a default arm. `category()` returns `verify` for `OutcomeUnknown`, and
  `errorSummary()['category']` is `verify` for any failure flagged `possibly_published`: never re-post
  those automatically.
- `PostFailed::$possiblyPublished` tells whether the post may be live. Such a key stays recorded as
  `failed`, and a re-dispatch with it is refused; use `reconcile()` or a new key (see the README).
- TikTok privacy refusals and YouTube `forbiddenPrivacySetting` keep `InsufficientPermissions` but no
  longer fire `CredentialsRejected` (`$e->needsReconnect()` is false).
- Queued listeners receive a portable copy of the exception: `getPrevious()->getRequest()` and the
  response are not available there. Use `errorSummary()`.

**Credentials**

- `Credentials` implements `JsonSerializable`: `json_encode($credentials)` returns the redacted copy.
  If you stored credentials by JSON-encoding the object, encode `$credentials->values` instead.
  `serialize()` and queue payloads are unchanged.
- Idempotency keys now include the account's identity key (`account_id`, `user_id`, `author`,
  `open_id`, `channel_id`, `did`, `board_id`) or a generic `account` credential. Pass it. Without one, a
  key is scoped by platform and `->idempotencyKey()` only, as in 1.0: two accounts that share a key
  share one record, and the second replays the first. 1.1 logs a one-time warning for this and adds an
  `idempotency` warning to `ValidationResult::warnings()` (non-blocking; `toArray()` gains a
  `warnings` key only when there are warnings).

**Media**

- Remote downloads follow the URL policy: private and reserved addresses are refused outside the
  `local` environment and tests (`SOCIAL_MEDIA_BLOCK_PRIVATE_NETWORKS`), redirects are re-checked,
  sizes can be capped (`SOCIAL_MEDIA_MAX_DOWNLOAD_BYTES`). Media served from a private network in
  production needs `block_private_networks = false` or an allow-list.
- `social.media.local_roots = null` (any readable path) logs a deprecation notice once per process; 2.0
  will require it. Set it if local paths can come from user input.

**Platforms**

- YouTube: `publish()` returns a `Pending` after opening the upload session, and the upload runs in
  job runs of about 45 s; the video is no longer published synchronously. `YouTubeOptions::$categoryId`
  defaults to `null` (1.0: `'22'`, People & Blogs) and `$privacyStatus` to `null` (still public unless
  scheduled). Pass `categoryId: '22'` to keep 1.0's category. `Media::video(thumbnail:)` is now used as
  a fallback cover.
- LinkedIn: text and image posts create the post in a separate commit step (one more job run);
  documents wait for `AVAILABLE`; videos must be 75 KB to 500 MB and 3 s to 30 min; a malformed
  `author` fails as `Misconfigured`; an orphan `{{mention:N}}` marker fails validation. Bump
  `SOCIAL_LINKEDIN_VERSION` at least yearly (426 maps to `Misconfigured`).
- X: captions are counted with X's weighted rule (links 23, emoji and CJK 2); video limits are 1200 s
  and 8 GB (1.0: 140 s and 512 MB; set `api.x.max_video_duration` / `max_video_bytes` to keep them).
  Pass `user_id` in the credentials.
- TikTok: the photo caption goes to `description` (4,000) and `->title()` to the photo title (90);
  direct posts send `brand_content_toggle` / `brand_organic_toggle` as `false` unless set
  (`api.tiktok.send_brand_defaults`); a draft video sends no caption. Only a public direct post whose
  public id arrives gets `idKind = video_id`; store `payload['publish_id']` for webhooks.
- Threads: emoji count as their UTF-8 bytes against 500, at most 5 links, videos at most 1920 px wide.
- Instagram and Facebook use Graph v25.0.

### 8. Code changes for subclasses and custom drivers

Only needed if you extend the package's classes.

- **`PostBuilder` subclasses.** `post()` returns `QueuedPost` (was `void`), `withOptions()` is variadic
  (`PlatformOptions ...$options`) and `using()` has a trailing `string|Platform|null $for = null`. A
  subclass that overrides them with the 1.0 signatures fails to load; update the signatures.
- **Custom drivers.** `SocialPlatform` is unchanged, and
  `AbstractPlatform::pending(array $state, int $recheckAfter = 30)` keeps its 1.0 signature. To get 1.1's protection, run your create call in a
  commit phase (`$this->commit($state)`) and check `Pending::isRecovering($state)` there. See
  [Writing a driver](README.md#writing-a-driver). `classifyError()` keeps its 1.0 signature on the
  first-party drivers; LinkedIn passes extra arguments that an override reads with `func_get_args()`.
- **First comments.** `SupportsComments::comment()` is deprecated (removed in 2.0). Implement
  `SupportsFirstComment` (`commentRules()`, `addFirstComment()`); the job still falls back to the legacy
  contract. When `Pending::isRecovering($parent->payload)` is true, an earlier attempt may have added
  the comment: look it up and return it (payload `recovered = true`), or throw
  `PermanentException(OutcomeUnknown)` with `possibly_published = true`.
- **TikTok.** The protected `initVideo()`, `initPhoto()`, `putChunks()` and `awaitPublish()` were
  removed: overrides of them are no longer called. Override `prepareVideo()`, `preparePhoto()`,
  `prepareFileUpload()`, `putChunk()` or `wait()`. `directPostInfo()` keeps its 1.0 signature.
- **Threads.** `ThreadsDriver::publishContainer()` is deprecated and no longer called: the commit phase
  runs `publishNow(PreparedPost $post, array $state)`. Move an override there. `startSingle()` and
  `startCarousel()` are still called, on `publish()` and on every restart.
- **YouTube.** `thumbnailMime()` returns `application/octet-stream` for an image without an extension
  (it returned `image/jpeg`).
- **Custom idempotency stores.** The `IdempotencyStore` contract is unchanged. `forget()` must never
  delete a published record and `markPending()` must never overwrite one. Implement
  `AtomicIdempotencyStore` for leases, account binding and recovery, and an optional
  `record(string $key): ?array` for `reconcile()` of failed records.
- **Deprecated helpers.** `AbstractPlatform::bytes()` for large files (use `stream()`),
  `LinkedInDriver::assetStatus()`, `uploadVideoChunks()`, `urnId()`, `XDriver::appendChunks()`,
  `mediaStatus()`, `TikTokDriver::utf16Length()`.

### Optional: adopt the new features

- Bind a `CredentialsResolver` and dispatch `Credentials::ref()`, so tokens stay out of the queue and
  are refreshed on every job run (needed for X, YouTube, TikTok and Bluesky OAuth tokens on queued
  posts).
- Give every publish your own `->idempotencyKey()`, and handle `PostFailed::$possiblyPublished` and
  `retry_at`.
- Use `FilesystemMediaGateway` to publish local files to pull-only platforms, and
  `ImageConvertingTransformer` for format conversion.
- Replace your own fakes with `SocialPoster::fake()`.
