# Instagram

| | |
|---|---|
| Platform key | `'instagram'` / `Platform::Instagram` |
| Driver | `SocialPoster\Platforms\Instagram\InstagramDriver` (implements `SupportsFirstComment`, and the deprecated `SupportsComments`) |
| API | Instagram Graph API `v25.0` (`api.instagram.graph_version`), on `https://graph.facebook.com` (Facebook Login) or `https://graph.instagram.com` (Instagram Login). The paths are the same on both hosts |
| Auth | The account's user access token, sent as `Authorization: Bearer <access_token>` |
| Accounts | Instagram professional accounts (Business or Creator) |
| Media transfer | Pull by URL. Instagram downloads every file from a public URL; the driver uploads nothing |
| Options class | `SocialPoster\Platforms\Instagram\InstagramOptions`, or `RawOptions(Platform::Instagram, [...])` for raw params only |
| First comment | Yes, on feed photos, reels and carousels. Never on stories |
| Async | Always: create the container(s), wait until Meta has processed them, then `media_publish` |

Every limit and setting on this page is read from `config('social.api.instagram.*')` with the default shown, so
nothing depends on the published config file. The code in `src/Platforms/Instagram/` is the reference.

---

## What you can publish

Every post needs at least one image or video. There are no text-only posts.

| Post | Media | Container sent to `POST /{account_id}/media` | Notes |
|---|---|---|---|
| Photo | 1 image | `image_url` (no `media_type`) | Caption, alt text, collaborators |
| Reel | 1 video | `video_url`, `media_type=REELS` | Caption, cover or `thumb_offset`, `share_to_feed`, collaborators. A single video is always published as a reel |
| Carousel | 2 to 10 images and/or videos (`api.instagram.carousel_max`) | One child per item (`is_carousel_item=true`), then a parent with `media_type=CAROUSEL` and `children` | Caption and collaborators on the parent, alt text per image child |
| Photo story | 1 image with `isStory: true` | `image_url`, `media_type=STORIES` | No caption, alt text, collaborators or cover |
| Video story | 1 video with `isStory: true` | `video_url`, `media_type=STORIES` | Same |

The driver always publishes immediately. There is no draft or scheduled mode, and `PostResult::$state` is
`published`. The result carries:

| Field | Value |
|---|---|
| `platformPostId` | The Instagram media id returned by `media_publish` (`null` only for a recovered post that could not be found; see [Duplicate safety](#duplicate-safety)) |
| `url` | The media's `permalink`, read after the publish. `null` when that read fails: the post is live either way |
| `payload` | `container_id`, `media_type` (`IMAGE`, `REELS`, `STORIES` or `CAROUSEL`), `children` (carousel child container ids), `restarts` (when containers were re-created), `response` (the raw `media_publish` answer), `recovered = true` after a recovery |

### Photo

```php
use SocialPoster\Facades\SocialPoster;
use SocialPoster\ValueObjects\Media;

SocialPoster::on('instagram')
    ->using(['account_id' => $igUserId, 'access_token' => $token])
    ->media(Media::image('https://cdn.example.com/posts/launch.jpg', altText: 'The new dashboard on a laptop'))
    ->caption('Version 1.1 is out. #release')
    ->post();
```

### Reel

```php
use SocialPoster\Platforms\Instagram\InstagramOptions;

SocialPoster::on('instagram')
    ->using($credentials)
    ->media(Media::video('https://cdn.example.com/clips/demo.mp4'))
    ->caption('Two minutes with the new editor.')
    ->withOptions(new InstagramOptions(
        thumbnail: Media::image('https://cdn.example.com/clips/demo-cover.jpg'),
        shareToFeed: true,
    ))
    ->post();
```

### Carousel

```php
SocialPoster::on('instagram')
    ->using($credentials)
    ->media(
        Media::image('https://cdn.example.com/steps/1.jpg', altText: 'Step one: connect the account'),
        Media::video('https://cdn.example.com/steps/2.mp4'),
        Media::image('https://cdn.example.com/steps/3.jpg', meta: [
            'instagram' => ['user_tags' => [['username' => 'example.partner', 'x' => 0.5, 'y' => 0.5]]],
        ]),
    )
    ->caption('Three steps to your first post.')
    ->post();
```

### Story

```php
SocialPoster::on('instagram')
    ->using($credentials)
    ->media(Media::video('https://cdn.example.com/stories/today.mp4'))
    ->withOptions(new InstagramOptions(isStory: true))
    ->post();
```

---

## Credentials

| Key | Required | Meaning |
|---|---|---|
| `account_id` | yes | The professional account's IG user id. Every call is built on it (`/{account_id}/media`, `/{account_id}/media_publish`...), and it is the account's identity in idempotency keys |
| `access_token` | yes | The user access token from Facebook Login (starts with `EAA`) or Instagram Login (starts with `IG`) |
| `login` | optional | `'facebook'` or `'instagram'`: which Graph host to call. See [Login and host](#login-and-host) |
| `expires_at` | recommended | Token expiry (unix seconds or milliseconds, ISO-8601, or a `DateTimeInterface`). Lets the driver refuse a token that is about to expire before it creates anything |
| `scopes` | optional | The granted permissions (a list, or a space- or comma-separated string). When present, validation and the comment step check them |

A missing `account_id` or `access_token` fails as `PermanentException(Misconfigured)` with
`missing_credentials`, before any request. That applies to the first comment too.

```php
use SocialPoster\ValueObjects\Credentials;

Credentials::make([
    'account_id' => $igUserId,
    'access_token' => $token,
    'login' => 'instagram',
    'expires_at' => $expiresAt,
    'scopes' => 'instagram_business_basic,instagram_business_content_publish,instagram_business_manage_comments',
]);

// Or keep the token out of the queue payload and resolve it on every job run:
Credentials::ref('social_account:42', ['account_id' => $igUserId, 'login' => 'instagram']);
```

### Login and host

Meta serves Instagram accounts connected with Instagram Login on `graph.instagram.com`, and accounts linked
through a Facebook Page (Facebook Login) on `graph.facebook.com`. A token only works on its own host. The
driver picks the host per post, for every call (containers, status reads, `media_publish`, permalink,
lookups, comments):

1. The `login` credential, when it says something: `'ig'` or any value starting with `instagram`
   (`'instagram_login'`...) means graph.instagram.com; `'fb'` or any value starting with `facebook` means
   graph.facebook.com. Case and surrounding spaces are ignored. Any other value is ignored.
2. Else the token prefix: `IG...` is Instagram Login, `EAA...` is Facebook Login.
3. Else `api.instagram.default_login` (`'facebook'`, the 1.0 behaviour).

`InstagramDriver::loginFor(Credentials)` returns what steps 1 and 2 decide (`'instagram'`, `'facebook'` or
`null`).

When the `login` credential contradicts the token prefix, Meta answers with a 190 because the token went to
the wrong host. The driver reports that as `PermanentException(Misconfigured)` with `host_mismatch = true`,
`login` and `token_login`, not as a dead token, and asks for no re-resolve. Fix the `login` credential, or
remove it so the token decides.

If `config('social.authorizers.instagram')` names a class implementing `AuthorizesRequests`, requests are
built from it instead of the bearer token (carousel children are then created one by one).

### Scopes

`SocialPoster::requiredScopes()` returns them as groups that are all required. A group lists alternatives:

| Feature | Facebook Login | Instagram Login |
|---|---|---|
| `Feature::Publish` | `instagram_basic`, `instagram_content_publish` | `instagram_business_basic`, `instagram_business_content_publish` |
| `Feature::Comment` | `instagram_manage_comments` | `instagram_business_manage_comments` |
| `Feature::Video` | none | none |

When neither `login` nor the token prefix tells the login, each group accepts both names:

```php
use SocialPoster\Enums\{Feature, Platform};

SocialPoster::requiredScopes(Platform::Instagram, Feature::Publish);
// [['instagram_basic', 'instagram_business_basic'], ['instagram_content_publish', 'instagram_business_content_publish']]

SocialPoster::requiredScopes(Platform::Instagram, Feature::Publish, ['login' => 'instagram']);
// [['instagram_business_basic'], ['instagram_business_content_publish']]
```

- With `scopes` in the credentials, a missing publish group is a validation error under
  `credentials.scopes`, and a missing comment scope skips the first comment (`CommentSkipped`, reason
  `missing_scope`). Without `scopes`, nothing is checked and the comment is attempted.
- The package does not run the OAuth flow, and never calls Page endpoints: finding the IG user id for
  `account_id` is up to your connect flow.
- App review: Meta grants these permissions to accounts that have a role on your Meta app. Publishing for
  other accounts needs Advanced Access, which goes through Meta's App Review.

### Token expiry and re-resolve

- With `expires_at` set, `publish()` and every `media_publish` call check that the token is valid for at
  least 60 more seconds. Without a custom `CredentialsResolver` an expiring token fails as
  `PermanentException(InvalidToken)` with `token_expired = true`. With one bound, it is a
  `TemporaryException(InvalidToken, retryAfter 0, reresolve = true)`, and the job runs again with
  re-resolved credentials.
- With a custom resolver bound (`config('social.credentials_resolver')`), a rejected token that is not
  terminal (a 401, or a code 102/190 without subcode 458, 460, 463, 467 or 492) is retried once, at once,
  with re-resolved credentials. A second rejection is final.
- The driver never refreshes tokens.

### Identity

The idempotency identity is `account_id`, so refreshing the token does not change the store key of a
publish.

---

## Typed options

```php
use SocialPoster\Platforms\Instagram\InstagramOptions;

new InstagramOptions(
    isStory: false,
    thumbnail: null,      // ?Media, the reel cover
    extra: [],
    shareToFeed: null,
    thumbOffsetMs: null,
    collaborators: [],
);
```

The constructor keeps the 1.0 positions (`isStory`, `thumbnail`, `extra`); the 1.1 fields come after them.
Options serialised by 1.0 in queued jobs unserialize with the new fields at their defaults.

| Option | Type | Default | Sent as | Applies to | Validation (error key) |
|---|---|---|---|---|---|
| `isStory` | `bool` | `false` | `media_type=STORIES` | Exactly 1 image or video | A second media item fails under `media` |
| `thumbnail` | `?Media` | `null` | `cover_url` (the gateway's URL for it) | Single reels | Must be an image with a `.jpg`, `.jpeg` or no extension; not with `thumbOffsetMs`; not on stories or other posts; a local file needs a gateway that publishes local files; at most `cover_max_bytes` (8 MB) when its size is known (`thumbnail`) |
| `shareToFeed` | `?bool` | `null` (Meta's default) | `share_to_feed` | Single reels | Refused on stories and other posts (`share_to_feed`) |
| `thumbOffsetMs` | `?int` | `null` | `thumb_offset` (milliseconds) | Single reels without `thumbnail` | Not negative; refused on stories and other posts (`thumb_offset`). An offset outside the video is refused by Meta (2207057) |
| `collaborators` | `list<string>` | `[]` | `collaborators` (array of usernames; a leading `@` and blanks are removed) | Photos, reels, carousels | At most 3, all strings; refused on stories (`collaborators`) |
| `extra` | `array` | `[]` | Merged into the container create | See below | Not validated |

**Reel cover fallback.** Without `thumbnail` and `thumbOffsetMs`, the video's own thumbnail
(`Media::video($url, thumbnail: $coverUrl)`) is sent as `cover_url` when it is a `.jpg`/`.jpeg` that the
gateway can hand over by URL, and its size (when the inspector or `Media` meta knows it) is at most
`cover_max_bytes`. Otherwise no cover is sent and Instagram picks a frame. Carousel videos and stories
never get a cover.

### The `extra` passthrough

The long tail of container params (`location_id`, `user_tags`, `audio_name`, `trial_params`,
`product_tags`...) goes in `extra`:

- It is merged into the container create of a photo, reel or story, or into the carousel parent. The
  driver's keys win on collision (`image_url`, `video_url`, `media_type`, `caption`, `alt_text`,
  `cover_url`, `thumb_offset`, `share_to_feed`, `collaborators`, `children`).
- On a story these keys are dropped instead of failing it: `caption`, `collaborators`, `location_id`,
  `share_to_feed`, `cover_url`, `thumb_offset`, `audio_name`, `trial_params`, `alt_text`, `product_tags`,
  `is_carousel_item`, `children`.
- It is never sent to carousel children, `media_publish` or the first comment.
- `RawOptions(Platform::Instagram, [...])` carries `extra` only. A story needs `InstagramOptions`.

```php
new InstagramOptions(extra: [
    'location_id' => '123456789',
    'trial_params' => ['graduation_strategy' => 'MANUAL'],
]);
```

### Per-item carousel params

A carousel child takes its own params from `Media::$meta['instagram']` (for example `user_tags`). The driver's
keys win (`is_carousel_item`, `image_url`, `alt_text`, `video_url`, `media_type`). This key is read only for
carousel children; on a single photo or reel, use `extra`.

---

## Limits

### Caption and alt text

- **2,200 characters**, counted as Unicode code points (`mb_strlen`) after trimming. Fixed, not configurable.
  Checked at `validate()` under `caption`.
- At most **30 hashtags** (`api.instagram.max_hashtags`) and **20 @mentions** (`api.instagram.max_mentions`)
  on photos, reels and carousels. A hashtag is `#` followed by letters, digits or `_`, not preceded by a
  word character or `&`. A mention is `@` followed by `A-Z a-z 0-9 . _`, not preceded by a word character
  or `.` (so an e-mail address does not count). Repeats count each time.
- The caption is trimmed when sent, and a blank caption is left out.
- Stories: the caption is not sent, so neither the length nor the tag limits are checked.
- Alt text: at most **1,000 characters** (code points, after trimming) per image of a photo post or
  carousel. Fixed. It is not sent on stories or videos.

### Media

| | Photo, carousel image | Story photo | Reel, carousel video | Story video | Config keys |
|---|---|---|---|---|---|
| Formats | `jpg`, `jpeg`, `png` | `jpg`, `jpeg`, `png` | `mp4`, `mov` | `mp4`, `mov` | `jpeg_only` drops `png` |
| Max size | 8 MB | 8 MB | 300 MB | 100 MB | `image_max_bytes`, `reel_max_bytes`, `story_video_max_bytes` |
| Aspect ratio | 0.8 (4:5) to 1.91 | not checked | 0.01 to 10 | 0.1 to 10 | fixed |
| Duration | | | 3 to 900 s | 3 to 60 s | fixed |
| Max width | | | 1920 px | 1920 px | fixed |
| Frame rate | | | 23 to 60 fps | 23 to 60 fps | `min_video_frame_rate`, `max_video_frame_rate` |
| Reel cover | | | JPEG, 8 MB | | `cover_max_bytes` |

- Counts: 1 item for a photo, reel or story; 2 to 10 for a carousel (`api.instagram.carousel_max`, at least 2).
  Meta's media reference allows up to 20 carousel items while its error 2207028 still says 2 to 10; raise
  `carousel_max` once your accounts take more.
- Frame rates get half a frame of tolerance: with the defaults, 22.5 to 60.5 fps passes.
- The format is read from the file extension in the URL path. A URL without one (`.../image?id=1`) fails
  validation (`media.N`).
- The size, ratio, duration, width and frame-rate checks need metadata: from the bound `MediaInspector`
  (ffprobe by default; remote URLs are read when `social.inspect_remote` is on) or from `Media::$meta`
  (`width`, `height`, `duration`, `size`, `frame_rate`...). When nothing is known, only the extension and
  count checks run.
- Instagram fetches media by URL. A local file needs a gateway that can publish it
  (`FilesystemMediaGateway`); with the default `LocalMediaGateway` it fails validation under `media.N`.

### Image formats and conversion

Meta documents JPEG as the only image format. PNG has kept working in production, so it stays accepted
unless `api.instagram.jpeg_only` is `true`.

WebP, HEIC/HEIF, AVIF, GIF, BMP and TIFF (and PNG under `jpeg_only`) pass validation only when a bound
`MediaTransformer` can convert them to JPEG (`social.transformer` = `ImageConvertingTransformer`, which
needs GD or Imagick support for the source format; a custom transformer is trusted). That transformer
writes a local JPEG, which Instagram can only fetch if the gateway publishes local files:

- With `ImageConvertingTransformer` and a gateway that cannot publish local files (the default
  `LocalMediaGateway`), a remote image that only conversion makes acceptable fails validation under
  `media.N`, with a message recommending `FilesystemMediaGateway`.
- At publish time, a converted file the gateway cannot hand over by URL is not used: the original is sent,
  and Meta decides (it refuses unsupported formats with 2207005).
- An animated GIF becomes a still JPEG (its first frame).

### Daily publishing limit

Meta caps API-published posts per account per rolling 24 hours (100 in its content publishing guide; the
carousel section of the same guide says 50). Hitting it (2207042) fails the post as
`PermanentException(RateLimited)` with `daily_limit = true`, `retry_after_hint` (seconds,
`api.instagram.daily_limit_retry_after`, default 86400) and `retry_at` (ISO-8601 UTC). It is never waited out
in the queue: reschedule the post at `retry_at`.

With `api.instagram.check_quota = true`, `publish()` first reads `GET /{account_id}/content_publishing_limit`
and fails the same way (plus `quota_usage`, `quota_total`, `preflight = true`) when `quota_usage` has
reached `quota_total`, before any container is created. If the quota cannot be read, the publish goes
ahead.

---

## First comment

`->withComment('...')` posts a comment on the new media (`POST /{media-id}/comments` with `message`) on the
account's host, after the post is live.

```php
SocialPoster::on('instagram')
    ->using($credentials)
    ->media(Media::image('https://cdn.example.com/posts/launch.jpg'))
    ->caption('Version 1.1 is out.')
    ->withComment('Release notes: https://example.com/changelog')
    ->post();
```

| Condition | Behaviour |
|---|---|
| Length | 2,200 characters, counted as code points (`api.instagram.comment_max_length`). Checked at `validate()` under `comment`, and again before the call |
| Stories | No comment: `CommentSkipped`, reason `story`. Also when the parent result's payload has `media_type = STORIES` |
| Parent scheduled, draft or failed | No comment |
| Parent recovered without a media id | Skipped (`CommentSkipped`, reason `no_post_id`); see [Duplicate safety](#duplicate-safety) |
| Scopes | `instagram_manage_comments` (Facebook Login) or `instagram_business_manage_comments` (Instagram Login). With `scopes` in the credentials and the scope missing: skipped (`missing_scope`). Without `scopes`: attempted |
| Delay | The larger of `social.comment_delay` (60 s) and `api.instagram.comment_min_delay` (0 s) |
| Credentials | `account_id` and `access_token`. `account_id` also identifies the account's own comments in the lookup below |

Before posting, the driver reads the media's comments:
`GET /{media-id}/comments?fields=id,text,timestamp,from,user&limit=25`. If the account's own comment with
the same text (trimmed, line endings normalised) is already there, its id is returned with payload
`{media_id, recovered: true}` and nothing is posted. A retry after a lost answer therefore no longer adds
the comment twice. A listed comment counts as the account's when Graph returns its `user` field, when
`from.id` equals `account_id`, or when it shows no author at all.

- The lookup costs one extra read per comment and needs the scope the comment needs.
- A throttled or failing read (throttle, 5xx: a `TemporaryException`) delays the comment through the
  comment job's normal retry instead of posting blind.
- A refused read (permissions, invalid parameter) proves nothing, and the comment is posted.
- A comment found this way arrives as `CommentPublished` with payload `recovered = true`.

Errors go through the same table as the post (for example code 200 is `InsufficientPermissions`, a throttle
is temporary). A 2xx without an id is still a posted comment: the `CommentResult` id is then `null`. Its
payload holds `media_id` and Meta's `response`. Outcomes arrive as `CommentPublished`, `CommentFailed` or
`CommentSkipped`. The 1.0 `comment($context, $postId, $text)` method still works, calls
`addFirstComment()`, and so does the same lookup.

---

## Async behaviour

A publish is a small state machine. Each step is one job run, and the job carries the state between runs.

| Phase (`state['phase']`) | What happens | Next |
|---|---|---|
| `publish()`, photo or photo story | `POST /{account_id}/media` with `image_url`. Instagram downloads the image while this call is open, so the container is normally ready when it answers | `publish` (commit), recheck 0 |
| `publish()`, reel or video story | `POST /{account_id}/media` with `video_url` | `await_container` after `first_poll_after` (20 s) |
| `publish()`, carousel | One child container per item, created concurrently (`Http::pool`, `carousel_concurrency` 5) and read back in carousel order | `await_children` after 5 s (images only) or `first_video_poll_after` (60 s) |
| `await_children` | Reads each pending video child. `FINISHED` or `PUBLISHED` is ready; `ERROR`/`EXPIRED` re-creates that child only. Once every child is ready, creates the parent (`media_type=CAROUSEL`, `children`, caption, collaborators) | `await_carousel` after `first_poll_after` |
| `await_container`, `await_carousel` | `GET /{container}?fields=status_code,status` every `poll_interval` (60 s) | `publish` (commit) on `FINISHED` |
| `publish` | Reads the container first when needed (see [Duplicate safety](#duplicate-safety)), then `POST /{account_id}/media_publish` with `creation_id`, then reads the permalink | Done |
| `restart` | Creates new containers, as `publish()` does | As `publish()` |

Container statuses while polling:

| `status_code` | Single container or carousel parent | Carousel child |
|---|---|---|
| `IN_PROGRESS`, or no status | Poll again. A container older than `container_max_age` (82800 s, 23 h) is re-created as if expired | Poll again |
| `FINISHED` | Hand over to the `publish` commit | Ready |
| `PUBLISHED` | An earlier `media_publish` went through: recovered, never published again | Ready |
| `ERROR` with code 2207001, 2207003, 2207008, 2207020, 2207032 or 2207053 (Meta-side) | Re-created, at most `max_restarts` (2) times, then `PermanentException(ServerError)` | That child alone is re-created, at most `max_restarts` times |
| `ERROR` with another code, or none | `PermanentException(MediaRejected)` naming the code | Same, naming the carousel item |
| `EXPIRED` | Re-created once (only if nothing was re-created before), then `PermanentException(ProcessingTimeout)` | Re-created once |

The error code is read from the container's `status` text ("... failed with error code 2207026").
Container failures carry `possibly_published = false`: such a container was never published.

What each `Pending` means:

- **Processing waits** (`await_*`) and the **`restart` hop** are plain `Pending`s. The job fires
  `PostProcessing` for each of them.
- **The `publish` phase** is a commit `Pending` (`commits = true`): the next run calls `media_publish`, the
  only call that creates a post. The rounds that stay in that phase (not ready, a 2207008 retry, a throttle
  or an ambiguous answer being verified, a long wait split into hops) are commit `Pending`s too, without
  `PostProcessing`.
- Re-creating containers from the `publish` phase runs on a hop of its own (`restart`, recheck 0), outside
  the commit. That is one extra queue hop and one `PostProcessing` event, and a failure while re-creating
  is reported with `possiblyPublished = false`. From the polling phases the new containers are created in
  the same run.
- **"Media not ready" (2207027) on `media_publish`**: the driver polls every `poll_interval` for
  `not_ready_fast_rounds` (5) rounds, then every `not_ready_backoff` (1800 s), never past the container's
  age limit (`container_max_age`). At that age the container is re-created once, then the post fails as
  `ProcessingTimeout`.
- Waits longer than `social.max_recheck_after` (900 s) are split into several hops (`not_before` in the
  state); those hops call nothing.

How long it takes:

- **Photo**: two job runs back to back. Normally live within seconds, plus queue latency.
- **Image carousel**: children created concurrently, parent after 5 s, first parent check 20 s later.
- **Reel, video story, carousel with videos**: first check after 20 s (60 s for carousel video children),
  then every 60 s until Meta reports `FINISHED`. Processing time depends on the file. Meta recommends
  checking once a minute for no more than five minutes; the driver keeps polling as long as the container
  reports `IN_PROGRESS`, within the job's wait limit.
- **Wait limit**: `social.async.max_wait_per_platform.instagram` (86400 s). Passing it while Meta still
  processes fails the post with `Timeout` (nothing was published). While a not-ready container is waited
  on, or a possibly published post is verified, the `Pending` declares its own deadline, 1800 s past the
  container's age limit (creation time plus `container_max_age`), which the job honours instead; the
  driver's own budgets end those waits first.
- `postNow()` waits up to `social.sync.max_wait` (120 s). A reel still processing then is handed to the
  queue, and the result's state is `processing`.

Timeouts: `media_publish` uses `api.instagram.publish_timeout`, which falls back to `social.publish_timeout`
(120 s), because a publish (carousels most of all) can take well over a minute without having failed. Every
other call (container creates, status reads, lookups, permalink, comments) uses `api.instagram.timeout`,
falling back to `social.http_timeout` (60 s). The connect timeout is `api.instagram.connect_timeout` (10 s).

Continuations queued by 1.0 still resume: `publish_retry` (1.0's 30-minute retry after a 2207027) maps to
`publish` and reads the container first, and `await_container`, `await_children` and `await_carousel`
states are completed from the post. A 1.0 state has no `since`, so its recovery lookup covers the
container's whole 24-hour lifetime, and its waits use the job's default limit.

---

## Error handling

Meta answers `{error: {message, type, code, error_subcode, is_transient, error_user_title, error_user_msg,
fbtrace_id}}`. The exception message is the driver's description of the subcode when it has one, followed
by Meta's `error_user_msg` (else `message`). The context holds `http_status`, `code`, `subcode`, `type`,
`is_transient`, `error_user_title`, `error_user_msg`, `fbtrace_id` (also as `trace_id`), `raw_message` and
the `error` object. Checks run in this order:

**1. Login and host mismatch.** A 190 when the `login` credential contradicts the token prefix:
`Permanent`, `Misconfigured`, `host_mismatch = true`.

**2. Instagram subcodes** (before the generic codes they ride on):

| Subcode | Meaning | Exception | `FailureReason` |
|---|---|---|---|
| 2207001, 2207003, 2207032 | Server error while processing, download took too long, container create failed | Temporary, 60 s | `ServerError` |
| 2207006, 2207008, 2207020, 2207053 | Container not found, expired or broken | Temporary, 60 s, `restart = true` | `ServerError` |
| 2207027 | Media not ready | Temporary, 60 s, `not_ready = true` | `ServerError` |
| 2207042 | Daily publishing limit | Permanent, `daily_limit`, `retry_after_hint`, `retry_at` | `RateLimited` |
| 2207052 | Media could not be fetched from its URL | Permanent | `MediaRejected` |
| 2207004, 2207005, 2207009, 2207023, 2207026, 2207028 | Image over 8 MB, image format, aspect ratio, media type, video format, carousel size | Permanent | `MediaRejected` |
| 2207010, 2207035, 2207036, 2207037, 2207040, 2207057 | Caption length or tag counts, product or user tags, more than 20 tagged accounts, `thumb_offset` | Permanent | `InvalidContent` |
| 2207050 | Account temporarily restricted | Permanent | `AccountRestricted` |
| 2207051 | Blocked as possible spam (comes with code 4) | Permanent, `spam_block = true` | `AccountRestricted` |
| 2207069 | Throttled | Temporary, 90 s | `RateLimited` |

`restart = true` outside the `publish` phase (a container create, a status read that keeps failing) makes the
job drop the state and publish again from scratch, at most `social.max_restarts` (3) times. On
`media_publish` the driver handles these subcodes itself (next table).

**3. Message rows.** "not an Instagram Business": `Permanent`, `AccountRestricted`. "An unexpected error has
occurred", "unknown error has occurred", "Please reduce the amount of data": `Temporary` (60 s),
`ServerError`.

**4. The shared Meta table** (Facebook, Instagram, Threads):

| Meta answer | Exception | `FailureReason` | Action |
|---|---|---|---|
| Code 102 or 190, subcodes 458/463/467, "Error validating access token", "has not authorized application" | Permanent, `terminal` for 458/463/467, else `reresolvable` | `InvalidToken` | Reconnect. One re-resolve retry when a custom resolver is bound and the rejection is not terminal |
| Subcode 460 | Permanent | `InvalidToken` | Password changed: reconnect |
| Subcodes 459, 464 | Permanent | `AccountRestricted` | The owner must clear a checkpoint on facebook.com |
| Subcode 492 | Permanent | `InsufficientPermissions` | The token's user lost its role on the Page |
| Codes 4, 17, 32, 341, 613, 80000 to 80014, HTTP 429, "rate limit" | Temporary | `RateLimited` | Waits `estimated_time_to_regain_access` from `X-Business-Use-Case-Usage` / `X-App-Usage`, else `Retry-After`, else 900 s (4, 17, 613, 800xx) or 300 s |
| `is_transient`, codes 1, 2, -1, -2, any 5xx (JSON or not) | Temporary, 60 s | `ServerError` | Retried |
| Codes 3, 10, 200 to 299, code 100 with subcode 33 | Permanent | `InsufficientPermissions` | Grant the permission or restore access, then reconnect |
| Code 368 | Permanent, `retry_later` | `AccountRestricted` | Temporarily blocked |
| Code 506 | Permanent | `DuplicateContent` | |

**5. By HTTP status**: 401 `InvalidToken`, 403 `InsufficientPermissions`, 404 `NotFound` (Permanent); 429
`RateLimited`, 408, 425 and 5xx `ServerError` (Temporary); anything else `Unknown` (Permanent), with the HTTP
status in the message.

A `TemporaryException` is retried by the job (`social.tries`, 5, with `social.backoff`, or the wait the
exception carries).

### What `media_publish` answers lead to

| Answer | What the driver does |
|---|---|
| 2xx with an id | `Published`. The permalink is read afterwards (best effort: `url` is `null` if that read fails) |
| 2xx without an id | The post is live: it is looked up (payload `recovered = true`, with Meta's answer kept in `payload['response']`) instead of failing |
| 2207027 (not ready) | Not-ready rounds (see [Async behaviour](#async-behaviour)) |
| 2207008 | `media_publish` is retried on the same container up to twice, 60 s apart (state key `builder_retries`), as Meta advises; then handled as the next row |
| 2207006, 2207008, 2207020, 2207053 | If an earlier attempt may have gone through, the post is looked up first. Then new containers on a `restart` hop, at most `max_restarts` (2) times; after that `PermanentException(ServerError)` with `restart = false` |
| Throttle (`RateLimited`: codes 4/17/32/341/613/800xx, 2207069, 429) | A refusal: nothing was published. The driver comes back after `poll_interval` or the throttle's wait (whichever is longer), reads the container, and publishes again. A later refusal (such as 2207042) is not flagged as possibly published |
| 5xx, transient codes, 2207001/2207003/2207032, "unexpected error", or a lost connection after the request was sent | Ambiguous: the state is marked `ambiguous`, and the driver comes back after `poll_interval` (or `Retry-After`) to verify before anything else |
| A connection failure where the request provably never left (DNS, refused, TLS, connect timeout) | Retried the same way, without the `ambiguous` mark |
| After `max_publish_errors` (5) throttled or ambiguous rounds | The error goes to the job, which treats it as possibly published when the publish may have gone through (the driver flags it; a lost connection is judged by the job from cURL) |
| A token rejection with a custom resolver bound | Goes to the job, which retries at once with re-resolved credentials |
| Any other refusal | Thrown. If an earlier attempt may have gone through, the post is looked up first, and the failure is flagged `possibly_published` when it is not found |

### Failed status reads

- While polling (`await_*`): a temporary failure or a dropped connection is retried every `poll_interval`
  (or the throttle's wait), and only fails the post after `max_poll_errors` (5) in a row. A permanent error
  (a rejected token) fails at once. A re-resolve request always reaches the job.
- In the `publish` phase with nothing in doubt, a failing readiness read is retried the same way, as a
  commit, and is finally thrown as a `TemporaryException` with `possibly_published = false` and
  `request_sent = false`: no publish was sent.
- In the `publish` phase with an earlier attempt in doubt, see [Duplicate safety](#duplicate-safety).

### Other failures

| Situation | Exception / reason |
|---|---|
| No media | Permanent `Validation` |
| No `account_id` or `access_token` | Permanent `Misconfigured` (`missing_credentials`) |
| Token expires within 60 s | Permanent `InvalidToken` (`token_expired`), or Temporary with `reresolve` when a resolver is bound |
| Container create answers 2xx without an id | Temporary `ServerError` (creating a container publishes nothing, so retrying is safe) |
| A pooled carousel child create lost its connection | Temporary `Network`, with `carousel_index` |
| A refused carousel child create | Mapped as above, with `carousel_index` |
| A continuation with an unknown phase or no container id | Permanent `Unknown` |

`FailureReason::category()` groups these: `retry` (RateLimited, ServerError, Timeout, Network,
ProcessingTimeout), `reconnect` (InvalidToken, InsufficientPermissions; `requiresReconnect()` is true),
`user_fix` (AccountRestricted, MediaRejected, InvalidContent, DuplicateContent...), `operator`
(Misconfigured, Unknown...), `not_found`, and `verify` (OutcomeUnknown). An exception flagged
`possibly_published` reports `verify` through `errorSummary()` whatever its reason.

Every failed response is logged (`[SocialPoster] instagram API error: ...`) with tokens redacted. Turn it off
with `social.error_logging`, or route it with `social.error_log_channel`.

---

## Duplicate safety

`media_publish` is the only call that creates a post, and it is not idempotent. Creating containers
publishes nothing: an unused container expires on its own after 24 hours. The driver runs `media_publish`
in a commit phase, and the job records the commit before the call.

**When the container is read first.** A clean run (a photo right after its container was created, or a
container that just reported `FINISHED`) publishes without reading the container again. Every other run of
the `publish` phase reads `status_code` first: a run the job marks `_recovering` (worker died or timed out,
queue redelivery, a store record still marked committing, a lost connection or 5xx after the request may
have been sent), a state marked `ambiguous` by an earlier round, and every not-ready or throttled round.
`_recovering` is copied into the driver's own `ambiguous` flag, so the doubt survives the next hop. The
2207008 retries publish the same container again without a read: Meta refused it, so nothing was
published, and an `ambiguous` state still forces the read.

**What the read decides:**

| `status_code` | Action |
|---|---|
| `PUBLISHED` | Never published again. The post is looked up (below) and returned as `Published`, payload `recovered = true` |
| `FINISHED` | Not published yet: `media_publish` is called |
| `IN_PROGRESS` | Back to polling |
| `ERROR`, `EXPIRED` | Re-created or failed as in [Async behaviour](#async-behaviour), with `possibly_published = false` |
| No (or an unknown) status, or the read fails, while an earlier attempt is in doubt | Never published and never re-created (the restart subcodes included). The read is retried as a commit up to `max_poll_errors` (5) times; then the post is looked up. Found: `Published`, `recovered = true`. Not found: `PermanentException(OutcomeUnknown)` with `possibly_published = true`, `container_id` and `poll_errors`. A permanent error on the read (a token rejection, code 100/33, code 10) skips the retries, is looked up the same way, and keeps its own reason with `possibly_published = true`, including through the credentials re-resolve path. The driver never asks the job to restart here |

**The lookup.** `GET /{account_id}/media?fields=id,caption,timestamp,permalink,media_type&limit=25`
(`/stories` for a story). A candidate was created at or after `since` (5 minutes before the first
container; for a 1.0 continuation, the last 24 hours and 5 minutes), has the same type (`IMAGE`, `VIDEO` for a reel,
`CAROUSEL_ALBUM`; not checked for stories) and, except for stories, the same caption after trimming and
normalising line endings. With a caption, the newest candidate wins. A post without a caption, and a story,
are matched only when there is exactly one candidate. Any failure of the lookup counts as "not found".

A container that reports `PUBLISHED` but cannot be matched is still a live post: the result is `Published`
with a **null id and url** and payload `recovered = true`.

What this means for your code:

- `PostPublished` can carry a `PostResult` with `platformPostId === null` and `payload['recovered'] === true`.
  The post is live, but its id is unknown. Check for it before you store the id. The first comment is skipped
  (`no_post_id`).
- `PostFailed` with `possiblyPublished === true` (reason `OutcomeUnknown`, or the original reason flagged
  `possibly_published`) means: check the account before posting again. The job keeps the key as `failed`, and
  `SocialPoster::reconcile()` can resume it with the original post: the driver then reads the container again
  before doing anything.
- A failure without that flag (a throttle followed by 2207042, a container in `ERROR`, a failed re-create)
  published nothing. The key is forgotten, so a reschedule at `retry_at` with the same `->idempotencyKey()`
  publishes normally.
- Keep an idempotency store bound (the default `CacheIdempotencyStore`, or `DatabaseIdempotencyStore`) and give
  the publish your own `->idempotencyKey()`, so a repeat dispatch replays the recorded result instead of
  posting again.

---

## Configuration (`social.api.instagram`)

| Key | Default | Effect |
|---|---|---|
| `graph_version` | `v25.0` (env `SOCIAL_INSTAGRAM_GRAPH_VERSION`) | Graph API version, on both hosts |
| `default_login` | `facebook` | Host when neither `login` nor the token prefix tells (`instagram` for graph.instagram.com) |
| `timeout` | `social.http_timeout` (60) | Read timeout (s) of every call except `media_publish` |
| `connect_timeout` | `10` | Connect timeout (s) |
| `publish_timeout` | `social.publish_timeout` (120) | Read timeout (s) of `media_publish` |
| `poll_interval` | `60` | Seconds between container status reads (at least 5) |
| `first_poll_after` | `20` | First read of a reel or story container and of a carousel parent (at least 5) |
| `first_video_poll_after` | `60` | First read of carousel video children |
| `max_poll_errors` | `5` | Consecutive failed status reads tolerated |
| `max_publish_errors` | `5` | Throttled or ambiguous `media_publish` rounds before the error goes to the job |
| `not_ready_fast_rounds` | `5` | 2207027 rounds at `poll_interval` |
| `not_ready_backoff` | `1800` | Seconds between later 2207027 rounds |
| `container_max_age` | `82800` | Age (s) after which a container is re-created instead of waited on (at least 60) |
| `max_restarts` | `2` | Re-creations after Meta-side container errors or a "container gone" answer on `media_publish` (per post; per child for carousel children) |
| `carousel_max` | `10` | Carousel items (at least 2) |
| `carousel_concurrency` | `5` | Concurrent child creates |
| `pool_children` | `true` | `false` creates children one by one (also the case when `social.authorizers.instagram` is set) |
| `carousel_video_media_type` | `REELS` | `media_type` of carousel video children |
| `check_quota` | `false` | Read `content_publishing_limit` before creating containers |
| `daily_limit_retry_after` | `86400` | `retry_after_hint` / `retry_at` of the daily limit (at least 60) |
| `image_max_bytes` | `8388608` | Feed, carousel and story images |
| `reel_max_bytes` | `314572800` | Reels and carousel videos |
| `story_video_max_bytes` | `104857600` | Story videos |
| `cover_max_bytes` | `8388608` | `InstagramOptions::$thumbnail` |
| `jpeg_only` | `false` | `true` refuses PNG unless a transformer converts it |
| `min_video_frame_rate` | `23` | Reels, carousel videos, story videos |
| `max_video_frame_rate` | `60` | Same |
| `max_hashtags` | `30` | Hashtags per caption |
| `max_mentions` | `20` | @mentions per caption |
| `comment_max_length` | `2200` | First comment limit (code points) |
| `comment_min_delay` | `0` | Minimum seconds between the post and its first comment |

Related global keys: `social.http_timeout` (60), `social.publish_timeout` (120),
`social.async.max_wait_per_platform.instagram` (86400), `social.max_recheck_after` (900),
`social.sync.max_wait` (120), `social.comment_delay` (60), `social.tries` (5) and `social.backoff`,
`social.max_restarts` (3), `social.credentials_resolver`, `social.authorizers.instagram`, `social.gateway`,
`social.transformer`, `social.media.*` (re-hosting), `social.idempotency`, `social.error_logging` and
`social.error_log_channel`.

---

## Known limitations

- Media must be reachable by public URL until Meta has processed it. Instagram downloads videos
  asynchronously, so a short-lived signed URL can expire mid-processing (container `ERROR`, or 2207052,
  `MediaRejected`). Use long-lived public URLs, or `FilesystemMediaGateway` with
  `social.media.rehost` set to `'always'` to copy remote media to your own disk first.
- No upload ingestion: Meta's resumable upload (rupload.facebook.com, Facebook Login only) is not
  implemented. Local files need `FilesystemMediaGateway`.
- Media URLs need a file extension the rules know (`jpg`, `jpeg`, `png`, `mp4`, `mov`).
- No drafts, no scheduled publishing, and no delete.
- A recovered post may have a null id and url, and then gets no first comment.
- The recovery lookup reads the latest 25 media (or stories) only. A post without a caption, and a story, are
  matched only when they are the only candidate since `since`. Two posts with the same caption in that window
  match the newest.
- The first-comment lookup reads the 25 newest comments only. An identical comment by the account that is
  already among them is taken as the earlier attempt and not posted again.
- The comment step requires `account_id`. A 1.0 caller of `comment()` without `account_id` now gets
  `Misconfigured`.
- Fixed limits: the 2,200-character caption, 1,000-character alt text, aspect ratios, video durations and
  the 1920 px width are not configurable.
- The reel cover taken from the video's thumbnail is size-checked only when its size is known (the
  inspector, or `Media` meta); an unknown size is sent as is.
- Carousel children created in a pool use the plain bearer token; with `social.authorizers.instagram` set they
  are created one by one.
- A child create that fails while a carousel is first created makes the job retry the whole carousel create.
  The containers already created are left to expire. (A child re-created later, after a Meta-side error,
  keeps the others.)
- `extra` and `Media::$meta['instagram']` are sent as given and never validated.

---

## Open live checks

These Instagram behaviours are covered by fixtures but not yet confirmed against the live API. Run
`tests/Live/InstagramLiveTest.php` with `SOCIAL_LIVE_INSTAGRAM_ACCOUNT_ID` and `SOCIAL_LIVE_INSTAGRAM_TOKEN`
(optional: `SOCIAL_LIVE_INSTAGRAM_LOGIN`, `..._IMAGE_URL`, `..._PNG_URL`). The checks that publish also need
`SOCIAL_LIVE_PUBLISH=1`, and they create real posts that cannot be deleted through the API: use a test
account. See `tests/Live/README.md`.

1. `Authorization: Bearer` on POSTs (media, `media_publish`, comments) on both hosts. GETs are automated, and
   the publishing test covers the account's own host. Production sends `access_token` as a parameter.
2. A container's `status_code` turns `PUBLISHED` after `media_publish`. Recovery relies on this. Automated;
   run it once per login type.
3. `GET /{ig-user}/media?fields=id,caption,timestamp,permalink,media_type&limit=25` and `GET /{ig-user}/stories`
   on the account's host. Automated.
4. The exact 190 body when an IG token is sent to graph.facebook.com, or an EAA token to graph.instagram.com.
   Automated (recorded).
5. The container `ERROR` status text: `Media upload has failed with error code N`?
6. Whether `media_type=REELS` and `VIDEO` are accepted for carousel video children (`carousel_video_media_type`).
7. In a JSON body: `collaborators` as an array, `share_to_feed` as a JSON boolean, `thumb_offset` in ms.
8. `alt_text` on carousel image children. A single feed image is automated.
9. Whether 2207051 is a spam block (now Permanent `AccountRestricted`; 1.0 retried it as Temporary
   `RateLimited` after 120 s).
10. The `content_publishing_limit` shape (`data[0].quota_usage`, `data[0].config.quota_total`). Automated; run
    it once per login type.
11. `Http::pool` with concurrency 5 for a 10-item carousel does not trigger throttling.
12. Meta still accepts PNG images (kept accepted unless `jpeg_only`). Automated.
