# X (Twitter)

| | |
|---|---|
| Platform key | `'x'` / `Platform::X` |
| Driver | `SocialPoster\Platforms\X\XDriver` (implements `SupportsFirstComment`) |
| API | X API v2, `https://api.x.com/2` (`api.x.base_url`; `https://api.twitter.com/2` also works) |
| Auth | OAuth 2.0 user-context bearer token |
| Media transfer | Upload: the driver sends the bytes itself. Remote URLs are downloaded by the `MediaGateway` first |
| Options class | `SocialPoster\Platforms\X\XOptions` (with `XPoll`, `XReplySettings`), or `RawOptions(Platform::X, [...])` |
| First comment | Yes, as a self-reply |
| Async | Text and photos: no processing. GIF and video: chunked upload, then processing polls |

Settings are read from `config('social.api.x.*')`. The package's `config/social.php` is merged into
your app's config at boot, and a key that is set nowhere falls back to the driver default shown on
this page. A value in a config file always wins over the driver default. The code in
`src/Platforms/X/` is the reference.

---

## What you can publish

A post is one of the following. Photos, GIFs, videos and polls can't be mixed.

| Post | Media | Notes |
|---|---|---|
| Text | none | Up to 280 weighted characters by default |
| Photos | 1 to 4 images (`jpg`, `jpeg`, `png`, `webp`) | Uploaded one by one, alt text per image |
| GIF | exactly 1 `.gif` | Its own size limit. Chunked upload with processing |
| Video | exactly 1 `.mp4` | Chunked upload, processing, then the post |
| Poll | none | 2 to 4 options, 5 minutes to 7 days |
| Reply / thread | any of the above | `XOptions::$replyToTweetId` |
| Quote post | any of the above, except a poll | `XOptions::$quoteTweetId`. X accepts it on Enterprise plans only |

X has no stories, reels, shorts or carousels beyond the 4-photo post. The driver always publishes
immediately. There is no draft or scheduled mode, and `PostResult::$state` is `published`.

### Text

```php
use SocialPoster\Facades\SocialPoster;

SocialPoster::on('x')
    ->using(['access_token' => $token, 'user_id' => $xUserId, 'expires_at' => $expiresAt])
    ->caption('Version 1.1 is out.')
    ->post();
```

### Photos (1 to 4)

```php
use SocialPoster\ValueObjects\Media;

SocialPoster::on('x')
    ->using($credentials)
    ->media(
        Media::image('/var/app/storage/shots/dashboard.png', altText: 'Dashboard in light mode'),
        Media::image('https://cdn.example.com/shots/settings.jpg'),
    )
    ->caption('Two screens from the new release.')
    ->post();
```

### GIF

```php
SocialPoster::on('x')
    ->using($credentials)
    ->media(Media::image('https://cdn.example.com/loop.gif', altText: 'Looping demo of the editor'))
    ->caption('The editor in five seconds.')
    ->post();
```

The driver treats an image as a GIF only when its extension is `.gif` (`Media::image()` or
`Media::guess()`). A GIF must be the only attachment.

### Video

```php
SocialPoster::on('x')
    ->using($credentials)
    ->media(Media::video('/var/app/storage/clips/demo.mp4'))
    ->caption('A two-minute walkthrough.')
    ->post();
```

A video's `thumbnail` and `altText` are not sent. The driver sends alt text for images and GIFs
only, and it has no cover-image call.

### Poll

```php
use SocialPoster\Platforms\X\{XOptions, XPoll};

SocialPoster::on('x')
    ->using($credentials)
    ->caption('Which release cadence do you prefer?')
    ->withOptions(new XOptions(poll: new XPoll(['Monthly', 'Quarterly'], durationMinutes: 1440)))
    ->post();
```

### Reply or thread

```php
SocialPoster::on('x')
    ->using($credentials)
    ->caption('2/ The upload is now staged across job runs.')
    ->withOptions(new XOptions(replyToTweetId: '1880000000000000001'))
    ->post();
```

Since February 2026, X refuses programmatic replies to other people's posts unless their author
mentioned or engaged with the account. Replies to the account's own posts (threads) still work. A
refused reply fails as `InvalidContent`.

### Quote post

```php
SocialPoster::on('x')
    ->using($credentials)
    ->caption('Worth reading.')
    ->withOptions(new XOptions(quoteTweetId: '1880000000000000001'))
    ->post();
```

X accepts `quote_tweet_id` on Enterprise API plans only. On any other plan, X refuses the post at
creation time, after any media was uploaded. Set `api.x.allow_quote_posts` to `false` to refuse it at
validation instead (see [Typed options](#typed-options)).

---

## Credentials

| Key | Required | Meaning |
|---|---|---|
| `access_token` | yes | OAuth 2.0 user-context access token. Missing → `PermanentException(Misconfigured)` with `missing_credentials`, before any request |
| `user_id` | recommended | The account's numeric X id. It is the account's identity in idempotency keys (see below), and it saves a `GET /2/users/me` read during recovery lookups |
| `expires_at` | recommended | Token expiry (unix seconds or milliseconds, ISO-8601, or a `DateTimeInterface`). The driver uses it to refuse a token that is about to expire before it spends a call |
| `scopes` | optional | The granted scopes (a list, or a space- or comma-separated string). When present, validation and the comment step check them |
| `account` | optional | A stable account identifier, used as the identity when `user_id` is absent |

```php
use SocialPoster\ValueObjects\Credentials;

Credentials::make([
    'access_token' => $token,
    'user_id' => '1234567890',
    'expires_at' => $expiresAt,
    'scopes' => 'tweet.read tweet.write users.read media.write offline.access',
]);

// Or keep the token out of the queue payload. A CredentialsResolver
// (config social.credentials_resolver) returns the real values on every job run:
Credentials::ref('social_account:42', ['user_id' => '1234567890']);
```

### Auth model

- The bearer must be an **OAuth 2.0 user-context** token, from X's authorization-code flow with PKCE.
  The driver sends `Authorization: Bearer <access_token>`.
- **OAuth 1.0a and app-only (application) tokens are not supported.** An app-only token gets X's
  `unsupported-authentication` 403, which maps to `Misconfigured`.
- The developer App must be attached to a Project. Otherwise X answers `client-forbidden`, which also
  maps to `Misconfigured`.
- The driver does not check the App's plan. X refuses, when the request is made, whatever the plan
  does not allow: quote posts outside Enterprise, and every call once pay-per-use credits run out.
- If `config('social.authorizers.x')` names a class implementing `AuthorizesRequests`, requests are
  built from it instead of from the bearer token.

### Scopes

`Poster::requiredScopes()` (also `SocialPoster::requiredScopes()`) returns them as groups. Every
group is required:

| Feature | Scopes |
|---|---|
| `Feature::Publish` | `tweet.read`, `tweet.write`, `users.read`, `media.write` |
| `Feature::Comment` | `tweet.read`, `tweet.write`, `users.read` |
| `Feature::Video` | `media.write` |
| `Feature::Document` | none (X takes no documents) |

```php
use SocialPoster\Enums\{Feature, Platform};

SocialPoster::requiredScopes(Platform::X, Feature::Publish);
// [['tweet.read'], ['tweet.write'], ['users.read'], ['media.write']]
```

- `media.write` covers every upload: one-shot images, INITIALIZE/APPEND/FINALIZE/STATUS, and alt text.
  Validation asks for it only when the post has media. A text-only post passes with a token that
  lacks `media.write`.
- Add `offline.access` to your authorization request to get a refresh token. The package does not
  list it, because it never refreshes tokens itself.
- When the credentials include `scopes`, each missing group is one validation error under
  `credentials.scopes`, for example
  `The X token is missing a permission needed to upload media: grant media.write.` Without `scopes`,
  nothing is checked.

### Token lifetime (2 hours)

X user tokens live 2 hours. A queued X publish can span several job runs: upload slices, processing
polls, retries and the comment delay.

- With `expires_at` set, every `publish()`, `resume()` and reply first checks that the token stays
  valid for `api.x.token_margin` seconds (60). If no custom `CredentialsResolver` is bound, an
  expiring token fails as `PermanentException(InvalidToken)` with `token_expired = true`. With one
  bound, it is a `TemporaryException(InvalidToken, retryAfter 0, reresolve = true)`, and the job calls
  the driver again at once with re-resolved credentials.
- Bind a `CredentialsResolver` (`config('social.credentials_resolver')`) that returns a fresh token on
  every job run. With a resolver bound, a 401 is retried once with re-resolved credentials. A second
  401 is final.

### `user_id` and idempotency

The idempotency key of a publish includes the account identity. The driver takes the first of these
that is set: `user_id`, an `account` credential, or the `Credentials::ref()` reference. The token is
never used: X replaces it every 2 hours, so it could not identify the account.

Without any of them, the key is scoped by platform and `->idempotencyKey()` only, as in v1.0. Two X
accounts dispatched with the same `->idempotencyKey()` then share one record: the second is replayed
(or refused as a duplicate) instead of being posted. The builder's `validate()` reports this as a
warning under `idempotency` when you pass an explicit key. **Pass `user_id`.** It also keeps
`outcome()` and `reconcile()` working across token refreshes.

When the credentials carry no identity, `publish()` logs one notice-level line per driver instance
(in practice, once per queue worker):

```
[SocialPoster] x: the credentials carry no user_id, so idempotency keys are not scoped to the X account: ...
```

The line is logged only when `social.error_logging` is on and the bound `IdempotencyStore` is not
`NullIdempotencyStore`. It never contains the token.

### Plans and billing

- On pay-per-use X API plans, every post, reply and read is billed. A 402, or an error that mentions
  credits, fails as `QuotaExceeded`. That is an operator problem: top up the developer account.
- A monthly usage cap (429, problem type `usage-capped`) also fails as `QuotaExceeded`.
- `api.x.verify_reads` (off by default) makes billed reads during recovery. See
  [Duplicate safety](#duplicate-safety).

---

## Typed options

```php
use SocialPoster\Platforms\X\{XOptions, XPoll, XReplySettings};

new XOptions(
    replySettings: XReplySettings::Following, // or the string 'following'
    quoteTweetId: null,
    replyToTweetId: null,
    poll: null,
    extra: [],
);
```

| Option | Type | Default | Sent as | Validation |
|---|---|---|---|---|
| `replySettings` | `XReplySettings\|string\|null` | `null` (everyone can reply) | `reply_settings` | A string must be one of the enum values, else an `options.reply_settings` error |
| `quoteTweetId` | `?string` | `null` | `quote_tweet_id` | Numeric, 1 to 19 digits (`options.quote_tweet_id`). Refused when `api.x.allow_quote_posts` is `false` |
| `replyToTweetId` | `?string` | `null` | `reply.in_reply_to_tweet_id` | Numeric, 1 to 19 digits (`options.reply_to_tweet_id`) |
| `poll` | `?XPoll` | `null` | `poll.options`, `poll.duration_minutes` | See `XPoll`. No media and no quote with a poll (`options.poll`) |
| `extra` | `array` | `[]` | merged into the `POST /2/tweets` body | Not validated |

Blank strings count as unset and are left out of the body.

**`XReplySettings`** (`reply_settings`):

| Case | Value | Who may reply |
|---|---|---|
| `Following` | `following` | Accounts the author follows |
| `MentionedUsers` | `mentionedUsers` | Accounts mentioned in the post |
| `Subscribers` | `subscribers` | The author's subscribers |
| `Verified` | `verified` | Verified accounts |

**`XPoll`**: `new XPoll(array $options, int $durationMinutes = 1440)` or `XPoll::make(...)`.

| Constant | Value |
|---|---|
| `XPoll::MIN_OPTIONS` / `MAX_OPTIONS` | 2 / 4 |
| `XPoll::MAX_OPTION_LENGTH` | 25 characters per option (code points, counted after trimming) |
| `XPoll::MIN_DURATION_MINUTES` / `MAX_DURATION_MINUTES` | 5 / 10080 (7 days) |

Options are trimmed when sent. An empty option is an error.

**`api.x.allow_quote_posts`** (default `true`): when `false`, any post that would be sent with a
`quote_tweet_id` fails validation under `options.quote_tweet_id`. That includes a `quote_tweet_id`
from `XOptions::$quoteTweetId`, from `XOptions::$extra`, or from `RawOptions(Platform::X, ...)`. Use
it on self-serve and pay-per-use plans, where X refuses quote posts.

### The `extra` passthrough

Anything else that `POST /2/tweets` accepts goes in `extra`, for example `for_super_followers_only`,
`geo`, `community_id`, `nullcast`, `made_with_ai`, `media.tagged_user_ids` or
`reply.exclude_reply_user_ids`.

- The `media`, `reply` and `poll` objects are deep-merged with what the driver builds, so
  `media.tagged_user_ids` survives next to the uploaded `media.media_ids`.
- When the driver sets a key, its value wins: `text`, `media.media_ids`,
  `reply.in_reply_to_tweet_id` (from `replyToTweetId`), `reply_settings`, `quote_tweet_id` and the
  `poll` fields.
- `extra` is never sent with the first comment.

```php
new XOptions(
    replyToTweetId: '1880000000000000001',
    extra: [
        'reply' => ['exclude_reply_user_ids' => ['1234567890']],
        'media' => ['tagged_user_ids' => ['1234567890']],
    ],
);

// Without a typed class:
use SocialPoster\ValueObjects\RawOptions;
use SocialPoster\Enums\Platform;

new RawOptions(Platform::X, ['reply_settings' => 'verified']);
```

The X driver ignores options bound to another platform.

---

## Limits

### Text

- **Counting rule** (`XWeightedCounter`, twitter-text v3): the text is NFC-normalised (with
  `ext-intl`). Each link counts 23 whatever its length, bare domains included. Each emoji sequence
  counts 2. Every other code point counts 1 inside U+0000–10FF, U+2000–200D, U+2010–201F and
  U+2032–2037, and 2 elsewhere (CJK and others). Weights are per code point, so combining marks count.
- **Maximum**: `api.x.max_characters` = 280. Raise it for accounts that can post longer text (X
  Premium).
- **Text is required** by default (`api.x.require_text` = `true`). With `false`, a post with media
  may have an empty caption, and `text` is then left out of the body. A post without media always
  needs text.

### Media

| | Formats | Count | Size | Other |
|---|---|---|---|---|
| Photo | `jpg`, `jpeg`, `png`, `webp` | 1 to 4 | `api.x.max_image_bytes` = 5242880 (5 MB) | Alt text up to `api.x.max_alt_text` = 1000 characters |
| GIF | `gif` | exactly 1, alone | `api.x.max_gif_bytes` = 15728640 (15 MB) | Alt text as for photos |
| Video | `mp4` | exactly 1, alone | `api.x.max_video_bytes` = 8589934592 (8 GB) | See below |

Video rules:

| Rule | Driver default | Key |
|---|---|---|
| Minimum duration | 0.5 s | `api.x.min_video_duration` |
| Maximum duration | 1200 s (20 min) | `api.x.max_video_duration` |
| Maximum size | 8589934592 bytes (8 GB) | `api.x.max_video_bytes` |
| Minimum width and height | 32 px | `api.x.min_video_dimension` |
| Aspect ratio | 1:3 to 3:1 | fixed |
| Maximum width / height | none | — |

- 1200 s and 8 GB are X's documented post limits for accounts without Premium. Premium accounts can
  post longer and larger videos (X documents 125 minutes and 16 GB). Raise `max_video_duration` and
  `max_video_bytes` for them.
- v1.0 capped X video at 140 s and 512 MB, which are X's DM video limits. To keep that stricter
  product rule, set `api.x.max_video_duration` = 140 and `api.x.max_video_bytes` = 536870912.
  Check your config files for the reverse case: a `config/social.php` that still sets these two keys
  to 140 / 536870912 overrides the driver defaults above.
- X checks the posting account's own limit again when the post is created, and answers 403 above it.
  The driver maps that answer to `MediaRejected`.
- X's docs still quote 1280×1024 as a maximum. 1080p and vertical phone video are accepted in
  production, so the driver enforces no maximum. 4K has not been verified live.
- The limits are per app, not per account. An app that posts for both standard and Premium accounts
  uses one set.

How validation applies them:

- With 2 to 4 attachments, all must be photos. A GIF or a video in the set gets its own error under
  `media.N` (`X takes one animated GIF on its own; ...`, `X takes one video on its own; ...`).
- A single attachment uses the GIF rules when it is a `.gif`, else the photo or video rules.
  `capabilities()` advertises the union (photos and GIFs up to 15 MB, at most 4 items) for callers
  that check support up front. `validate()` applies the exact per-post rules.
- Duration, size and dimensions come from the bound `MediaInspector` (`ffprobe` by default),
  completed by `Media::$meta` (`size`, `width`, `height`, `duration`). A value that is unknown is not
  checked.
- Alt text longer than `max_alt_text` is a validation error. When validation is skipped, the driver
  cuts it to the limit.
- The file type comes from the extension, so a file without one fails validation. When validation is
  skipped, the driver sniffs the MIME type of such a file from its content.

### Chunked upload limits

GIFs and videos are sent in `APPEND` segments of `api.x.chunk_size` = 4194304 bytes (4 MB). X accepts
segment indexes 0 to 9999 (the driver's `MAX_SEGMENTS` = 10000).

- A segment grows past `chunk_size` only when the file would need more than 10000 segments. It never
  grows past the driver's `MAX_SEGMENT_BYTES` = 5242880 (5 MB): X recommends at most 5 MB per segment
  and refuses more than 8 MB. With the default chunk size, segments grow only for files above about
  42 GB.
- A file that would need bigger segments (above about 52 GB) is refused with
  `PermanentException(MediaRejected)` before INITIALIZE. The default size limits stop such files at
  validation long before that.
- A configured `chunk_size` above 5 MB is capped at 5 MB.

---

## First comment

`->withComment('...')` posts a reply to the new post (`POST /2/tweets` with
`reply.in_reply_to_tweet_id`) once the post is live.

```php
SocialPoster::on('x')
    ->using($credentials)
    ->caption('Release notes are up.')
    ->withComment('Full changelog: https://example.com/changelog')
    ->post();
```

| Condition | Behaviour |
|---|---|
| Length | 280 weighted characters (`api.x.max_comment_characters`, which defaults to `api.x.max_characters`). Checked at `validate()` under `comment` |
| Scopes | `tweet.read`, `tweet.write`, `users.read`. If the credentials include `scopes` and one is missing, the comment is skipped (`CommentSkipped`, reason `missing_scope`). Without `scopes`, it is attempted |
| Delay | `social.comment_delay` (60 s). X sets no minimum of its own. On the `sync` queue connection the reply is sent right away |
| Parent failed, scheduled or draft | No comment |
| Parent recovered without an id | Skipped (`CommentSkipped`, reason `no_post_id`). See [Duplicate safety](#duplicate-safety) |
| Body | Only `text` and `reply`. The post's options and `extra` are never copied to the reply |
| Billing | On pay-per-use plans the reply is billed like a post |

Errors are mapped as for the post. A 2xx answer without an id is a
`PermanentException(OutcomeUnknown)` and is never retried. The result's payload holds
`in_reply_to_tweet_id` and X's `data`. Outcomes arrive as `CommentPublished`, `CommentFailed` or
`CommentSkipped`. The comment job makes up to `social.comment_tries` (5) attempts on temporary errors.

If a reply's answer was lost after the request went out (connection dropped after sending, a 5xx, a
worker killed mid-call), the next attempt is a recovery. X refuses the re-sent reply as duplicate
content when the first one landed, and the comment job reads that refusal as the earlier reply:
`CommentPublished` with a null `commentId` and payload `recovered = true`, `duplicate_refused = true`.
On a first attempt, a duplicate refusal stays `CommentFailed` (`DuplicateContent`).

---

## Async behaviour

A publish is a small state machine. Each step is one job run, and the job carries the state from one
run to the next. `PostProcessing` fires for every wait; not for the immediate hop into the create.

| Phase (`state['phase']`) | What happens | Next |
|---|---|---|
| `publish()` | Text: nothing is sent yet. Photos: each one is uploaded (`POST /2/media/upload`, `media_category=tweet_image`), then its alt text is set (`POST /2/media/metadata`). GIF/video: INITIALIZE (`tweet_gif` / `tweet_video`), then APPEND segments while the run's budget lasts | `tweet` (commit), `append` or `await_media` |
| `append` | More APPEND segments. The state keeps `media_id`, `size`, `chunk_size`, `segment_index` and `offset` | `append` again, then FINALIZE in the same run |
| FINALIZE | `POST /2/media/upload/{id}/finalize`. No `processing_info`, or `succeeded`, means the media is ready | `await_media`, or `tweet` |
| `await_media` | `GET /2/media/upload?command=STATUS`. `pending`/`in_progress`: wait again. `failed`: `MediaRejected` with X's reason. Any other state is logged and polled again. A STATUS without `processing_info` counts as ready | `await_media`, or `tweet` |
| `tweet` | `POST /2/tweets`, the only non-idempotent call (the commit phase). A GIF's alt text is set just before this step | Done |

What each `Pending` means:

- **Text post**: `publish()` returns a commit `Pending` (recheck 0), and the next run creates the post.
  On the queue that is a second job run, dispatched without delay. `postNow()` does not sleep between
  the two.
- **Upload slice** (`append`): recheck 0. A run starts no new segment once `api.x.upload_run_seconds`
  (240) have passed, but always sends at least one, then hands over to the next run. Only one segment
  is held in memory.
- **Processing** (`await_media`): the recheck is X's `check_after_secs` (5 when X sends none), clamped
  to between 1 and `api.x.max_check_after` (30) seconds. On the queue a wait is at least 5 s.
- If the source file changes size between runs, the upload starts over, at most twice (also bounded
  by `social.max_restarts`). After that it fails as `MediaRejected`.
- Continuations queued by v1.0 (`await_media` with `media_id`, or `tweet` with a single `media_id`)
  still resume.

**Remote media is downloaded on every upload run.** With the default `LocalMediaGateway`, each run
that uploads (`publish()` and every `append` slice) downloads the source again, within
`social.download_timeout` (120 s), and deletes it at the end of the run. A `Media::fromDisk()` file
on a non-local disk (S3 and the like) is copied to a temp file on every run in the same way. For large
videos, pass a local path, or a `Media::fromDisk()` file on a local disk, that every worker can read.

How long it takes: photos and text need no waiting. A GIF or video needs one APPEND request per 4 MB
(2048 requests for an 8 GB file), spread over runs of about 240 s each, plus X's processing time. The
driver polls at the interval X asks for. It sets no deadline of its own, so the whole chain (upload
slices, processing polls, the create) must finish within `social.async.max_wait` (3600 s). For very
large videos on slow links, raise `social.async.max_wait_per_platform.x`. Past that limit the publish
fails with `Timeout`.

`postNow()` waits up to `social.sync.max_wait` (120 s), then hands the publish to the queue. The
result is then a success with state `processing`, and the final outcome arrives as `PostPublished` or
`PostFailed`. On the `sync` queue connection there is no queue to hand over to, so the publish times
out instead.

Timeouts: API calls (INITIALIZE, FINALIZE, STATUS, posts, metadata, recovery lookups) use
`api.x.timeout` (120 s). Byte uploads (one-shot images, APPEND) use `api.x.upload_timeout`, which
falls back to `social.upload_timeout` (300 s). The connect timeout is `api.x.connect_timeout` (10 s).

---

## Error handling

X answers either `{title, detail, type, status}` (problem+json) or `{errors: [{message, detail}], ...}`.
The exception message is the first of `errors[0].message`, `errors[0].detail`, `detail` and `title`
that is not a bare status name such as "Forbidden". The context holds `status`, the raw body under
`error`, and `problem_type` (X's problem `type`). The checks run in this order:

| X answer | Exception | `FailureReason` | Action |
|---|---|---|---|
| 402, or any error that mentions credits | Permanent | `QuotaExceeded` | Operator: top up API credits |
| 429 `usage-capped` / `UsageCapExceeded` | Permanent | `QuotaExceeded` | Operator: monthly cap reached. Context `usage_capped`, `period`, `scope` |
| 429, wait ≤ `max_rate_limit_wait` | Temporary | `RateLimited` | Retried after the wait. Context `retry_at`, `rate_limit_bucket` |
| 429, wait > `max_rate_limit_wait` (3600 s) | Permanent | `RateLimited` | Not retried. Context `retry_at` (ISO-8601 UTC), `retry_after_hint` (seconds), `rate_limit_bucket` |
| 5xx | Temporary | `ServerError` | Retried after `Retry-After`, else after `api.x.server_error_retry` (60 s) |
| "locked", "suspended" or "suspension" (whole words: "blocked" does not count) | Permanent | `AccountRestricted` | The user must unlock the account on X. Reconnecting does not help |
| `client-forbidden`, `unsupported-authentication` | Permanent | `Misconfigured` | Fix the developer App or the token type |
| 401 | Permanent | `InvalidToken` | Reconnect or refresh (one re-resolve retry when a resolver is bound) |
| 403 duplicate content | Permanent | `DuplicateContent` | During recovery, taken as proof that the earlier attempt published (see below) |
| 403 reply not allowed | Permanent | `InvalidContent` | The reply restriction (Feb 2026) or the conversation's reply settings |
| 403 that mentions duration or video | Permanent | `MediaRejected` | The video is above this account's limit |
| any other 403 | Permanent | `InsufficientPermissions` | Usually a missing scope: reconnect |
| 400 whose X message says the target does not exist or was deleted | Permanent | `NotFound` | For example, the replied-to post is gone |
| 400 whose X message is about media | Permanent | `MediaRejected` | For example, invalid media ids |
| 400 with any other X message | Permanent | `InvalidContent` | For example, text too long |
| anything else | base table | 404 `NotFound`; 408/425 `ServerError` (Temporary); a 400 without an X message and any other status `Unknown` | — |

The **429 wait** is the reset time of the bucket that is actually exhausted. The driver takes the
latest reset among the `x-user-limit-24hour-*` and `x-app-limit-24hour-*` buckets that report 0
remaining (bucket `user_24h` / `app_24h`). Failing that, it uses `x-rate-limit-reset` (`window`),
then `Retry-After` (`retry_after`), then `api.x.rate_limit_retry` = 120 s (`unknown`). A long wait is
never spent inside the queue: past `max_rate_limit_wait` the post fails with `retry_at`, and you
re-dispatch it after that time.

Other failures raised by the driver:

| Situation | Exception / reason |
|---|---|
| No `access_token` | Permanent `Misconfigured` (`missing_credentials`) |
| Token expires within `token_margin` | Permanent `InvalidToken` (`token_expired`), or Temporary with `reresolve` when a resolver is bound |
| A remote URL that no `MediaGateway` downloaded | Permanent `Misconfigured` |
| File unreadable, empty, or changed during the upload | Permanent `MediaRejected` (a size change first restarts the upload) |
| Processing `failed` | Permanent `MediaRejected`. The message includes X's reason, and the context has `processing_info` |
| 2xx without a media id (image upload, INITIALIZE) | Temporary `ServerError` (safe to retry) |
| 2xx without a post or reply id | Permanent `OutcomeUnknown` with `possibly_published = true` (never retried) |
| Unknown phase, or a state without its `media_id` | Permanent `Unknown` |
| Alt text refused or lost | Logged only. The post goes out without it |
| Connection lost (no response) | Handled by the job as `Network` (see below for the commit phase) |

`FailureReason::category()` groups the reasons for your UI. `retry` covers RateLimited, ServerError,
Timeout, ProcessingTimeout and Network. `reconnect` covers InvalidToken and InsufficientPermissions
(`requiresReconnect()` is true for both). `operator` covers QuotaExceeded, Misconfigured and Unknown.
`verify` covers OutcomeUnknown. The remaining groups are `user_fix` and `not_found`. An exception
flagged `possibly_published` reports `verify` through `errorSummary()`, whatever its reason.

Every failed response is logged (`[SocialPoster] x API error: ...`) with tokens redacted: temporary
failures at warning level, permanent ones at error level. Turn logging off with
`social.error_logging`, or route it with `social.error_log_channel`.

---

## Duplicate safety

`POST /2/tweets` is the only call that can create a post twice. The driver runs it in a commit phase,
and an atomic idempotency store records the commit before the call. When the outcome of a run is
ambiguous, the job runs the phase again with `_recovering` set. That covers a worker that died or
timed out, a queue redelivery, a store record still marked committing, and a lost connection or 5xx
after the request may have been sent. It does not cover explicit refusals (4xx, rate limits, token
rejections) or a request that provably never left.

X has no idempotency key, and it recognises a duplicate **by its text only**. The recovery policy
therefore depends on whether the post has text, and on `api.x.verify_reads`:

| | `verify_reads = false` (default) | `verify_reads = true` |
|---|---|---|
| **Post with text** | Sent again. A 403 duplicate answer means the earlier attempt published. The result is `Published` with a **null id and url**, and its payload has `recovered = true`, `possibly_duplicate = true`, `possibly_published = true`, `verified = false`, `duplicate_message` and `media_ids`. If X accepts the re-send, it is a normal publish | Looked up first. Found → `Published` with the real id, and the payload has `recovered = true` and `verified = true`. Not found, or the lookup failed → sent again, as in the left column |
| **Post without text** (media-only, `require_text = false`) | **Never sent again**: `PermanentException(OutcomeUnknown)` with `possibly_published = true` and `media_ids`. Nothing is requested | Looked up first. Found → `Published` with the real id. Sent again **only** when the lookup ran and did not find the post |

For a recovering post without text, a lookup that cannot rule the post out never leads to a re-send:

- A transient lookup failure (a 5xx, a 429 within `max_rate_limit_wait`, a `/2/users/me` answer
  without an id, a re-resolve request) is rethrown as that `TemporaryException`, flagged
  `possibly_published = true` and `lookup_failed = true`. The job retries it, and the retry is still
  a recovery. A lost connection during the lookup is retried in the same way.
- Any other lookup failure, or a result page with `meta.next_token` (more posts since the commit than
  one page holds), ends as `PermanentException(OutcomeUnknown)` with `possibly_published = true`,
  `lookup_failed = true`, `media_ids`, `lookup_reason` (the lookup's `FailureReason` value) and
  `lookup_status` (its HTTP status, if any).

The lookup is `GET /2/users/{user_id}/tweets` with `max_results=20`, `exclude=retweets` and
`tweet.fields=created_at,text,attachments,note_tweet`. Its `start_time` is the time the commit started
(else the chain start, else one hour ago), minus 120 s. A media post matches when every uploaded media
id appears in its `attachments.media_keys`. A text post matches on its text, compared after decoding
HTML entities, removing links (X rewrites them to t.co) and collapsing whitespace. `note_tweet.text`
is used for long posts. Without `user_id`, one extra `GET /2/users/me` read finds the id. The lookup
needs `tweet.read` and `users.read`, and every read is billed on pay-per-use plans.

What this means for your code:

- `PostPublished` can carry a `PostResult` with `platformPostId === null` and
  `payload['recovered'] === true`. The post is live, but its id is unknown without a read. Check for
  this case before you store the id. The first comment is skipped (`no_post_id`).
- `PostFailed` with `possiblyPublished === true` (reason `OutcomeUnknown`) means: check the account
  before posting again. Never re-dispatch blindly. The store keeps the record. `reconcile()`, given the
  original post, runs the `tweet` step again with `_recovering` set, so the same rules apply: for a
  media-only post, turn `verify_reads` on first.
- Keep an idempotency store bound (the default `CacheIdempotencyStore`, or
  `DatabaseIdempotencyStore`), give the publish your own `->idempotencyKey()`, and pass `user_id`. A
  repeat dispatch then replays the recorded result instead of posting again.

---

## Configuration (`social.api.x`)

Every key is optional. The default is the driver's own, used when no config file sets the key.

| Key | Default | Effect |
|---|---|---|
| `base_url` | `https://api.x.com/2` | API root |
| `timeout` | `120` | Read timeout (s) of API calls |
| `connect_timeout` | `10` | Connect timeout (s) |
| `upload_timeout` | `social.upload_timeout` (300) | Read timeout (s) of one-shot image uploads and APPEND segments |
| `chunk_size` | `4194304` | APPEND segment size (bytes) |
| `upload_run_seconds` | `240` | Upload time per job run before handing over |
| `max_characters` | `280` (env `SOCIAL_X_MAX_CHARACTERS` in `config/social.php`) | Weighted caption limit |
| `max_comment_characters` | `max_characters` | Weighted reply (first comment) limit |
| `require_text` | `true` | Require a caption on media posts |
| `allow_quote_posts` | `true` | `false` refuses `quote_tweet_id` at validation |
| `max_image_bytes` | `5242880` | Photo size limit |
| `max_gif_bytes` | `15728640` | GIF size limit |
| `max_video_bytes` | `8589934592` | Video size limit |
| `min_video_duration` | `0.5` | Seconds |
| `max_video_duration` | `1200` | Seconds |
| `min_video_dimension` | `32` | Minimum video width and height (px) |
| `max_alt_text` | `1000` | Alt text limit (characters) |
| `max_check_after` | `30` | Upper clamp (s) of X's `check_after_secs` |
| `max_rate_limit_wait` | `3600` | A longer 429 wait fails as `RateLimited` with `retry_at` |
| `rate_limit_retry` | `120` | 429 wait when no reset header is usable |
| `server_error_retry` | `60` | 5xx retry delay when X sends no `Retry-After` |
| `verify_reads` | `false` (env `SOCIAL_X_VERIFY_READS` in `config/social.php`) | Look a post up before re-sending an ambiguous create |
| `token_margin` | `60` | Seconds of token life required before a call |

Related global keys:

- Uploads and media: `social.upload_timeout` (300), `social.download_timeout` (120),
  `social.media.max_download_bytes`.
- Waits: `social.async.max_wait` (3600) and `social.async.max_wait_per_platform.x`,
  `social.sync.max_wait` (120).
- Comments: `social.comment_delay` (60), `social.comment_tries` (5).
- Retries: `social.tries` (5), `social.backoff`, `social.max_restarts` (3).
- Credentials: `social.credentials_resolver`, `social.authorizers.x`.
- Idempotency and logging: `social.idempotency`, `social.error_logging`, `social.error_log_channel`.

---

## Known limitations

- OAuth 1.0a and app-only tokens are not supported. The driver does not obtain or refresh tokens.
- Limits are per app, not per account: standard and Premium accounts share one setting.
- A recovered duplicate has no id and no url, and gets no first comment.
- A media-only post (`require_text = false`) cannot be recovered without `verify_reads`. It ends as
  `OutcomeUnknown` instead of risking a double post.
- `verify_reads` reads one page (20 posts) from the commit start minus 120 s. Text matching ignores
  links, so a post with the same words and a different link, published by the account in that window,
  is taken as the earlier attempt.
- A reply recovered from a duplicate refusal (see [First comment](#first-comment)) has no id:
  `CommentPublished` then carries a null `commentId`.
- Video alt text and video thumbnails are not sent.
- Only `.mp4` video. GIFs and videos can't share a post, and polls can't carry media or quote a post.
- Quote posts need an Enterprise plan. Since February 2026, X restricts replies to other people's
  posts.
- A remote GIF or video is downloaded again on every upload run (see
  [Async behaviour](#async-behaviour)).
- The upload counts toward `social.async.max_wait`. A very large video on a slow link needs a higher
  `social.async.max_wait_per_platform.x`.
- `extra` is sent as given and never validated.

---

## Open live checks

Fixtures cover these X behaviours, but they have not been confirmed against the live API yet. Run
`tests/Live/XLiveTest.php` with `SOCIAL_LIVE_X_TOKEN` and `SOCIAL_LIVE_X_USER_ID`. The checks that
post (and then delete) also need `SOCIAL_LIVE_PUBLISH=1`. See `tests/Live/README.md`.

1. Duplicate detection when the same text is re-sent with the same `media_ids` within their expiry,
   and whether re-using a media id is refused on its own. The plain text re-send is automated.
2. The exact bodies for locked and suspended accounts, 402 `CreditsDepleted`, 429 usage-capped, the
   February 2026 reply restriction, and the 403 for a video above the account's duration limit.
3. What `x-user-limit-24hour-*` and `x-app-limit-24hour-*` mean on a 429 for `POST /2/tweets`. The
   headers are recorded automatically.
4. Whether STATUS can omit `processing_info` (the driver treats that as ready).
5. Whether alt text works on a GIF after processing, and whether X refuses or ignores it for video.
6. Whether APPEND accepts a re-sent `segment_index` after a lost connection. Whether segments above
   5 MB are accepted only matters when `chunk_size` is configured above 5 MB.
7. The timeline format used by `verify_reads`: `media_keys` as `{type}_{media_id}`, `&amp;` entities
   in the text, `note_tweet.text` for long posts. This check is automated.
8. Whether `quote_tweet_id` is accepted together with media, and whether X counts poll options by
   code point.
9. 4K video and extreme aspect ratios within 1:3 to 3:1. Only 1080p is production-proven.
10. Whether `media.write` is required for each media endpoint (one-shot upload, chunked commands,
    metadata), as X documents.
