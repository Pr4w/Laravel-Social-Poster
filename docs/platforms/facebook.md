# Facebook

| | |
|---|---|
| Platform key | `'facebook'` / `Platform::Facebook` |
| Driver | `SocialPoster\Platforms\Facebook\FacebookDriver` (implements `SupportsFirstComment` and the deprecated `SupportsComments`) |
| API | Graph API, `https://graph.facebook.com/v25.0` (`api.facebook.graph_version`). Reel and video story bytes go to the `upload_url` Facebook returns for the upload session |
| Target | Facebook Pages, with a Page access token |
| Auth | `Authorization: Bearer <Page token>` on Graph calls, `Authorization: OAuth <Page token>` on the Reel/story upload step |
| Media transfer | Facebook pulls media from public URLs. Reels and video stories can also upload a local file directly |
| Options class | `SocialPoster\Platforms\Facebook\FacebookOptions`, or `RawOptions(Platform::Facebook, [...])` for `extra` only |
| First comment | Yes, on feed posts, regular videos and Reels |
| Async | Text, photos, regular videos and photo stories: one create step, no polling. Reels and video stories: upload, finish 60 s later, then status polls |

Every limit and setting on this page is read from `config('social.api.facebook.*')` with the default
shown, so nothing depends on the published config file. The code in `src/Platforms/Facebook/` and
`src/Concerns/TalksToMetaGraph.php` is the reference.

---

## What you can publish

| Post | Media | Graph calls | Notes |
|---|---|---|---|
| Text / link | none | `POST /{page}/feed` | A caption is required. Link card from the caption's first URL, `FacebookOptions::$link`, or `extra['link']` |
| Photo post | 1 to 10 images | `POST /{page}/photos` per image (`published=false`), then `POST /{page}/feed` with `attached_media` | Order kept. Alt text per image. A single image goes the same way |
| Regular video | exactly 1 video | `POST /{page}/videos` with `file_url` | Needs a public URL. Optional thumbnail and title |
| Reel | exactly 1 video, 9:16 (or `asReel: true`) | `POST /{page}/video_reels` start, upload, finish | Local files allowed. Title supported |
| Photo story | exactly 1 image, `isStory: true` | `POST /{page}/photos` (`published=false`), then `POST /{page}/photo_stories` | The caption is not sent |
| Video story | exactly 1 video, 9:16, `isStory: true` | `POST /{page}/video_stories` start, upload, finish | Local files allowed |
| Scheduled post | any of the above except stories | the same calls, with `scheduled_publish_time` | `FacebookOptions::$scheduledAt`, 10 minutes to 29 days ahead |

Photos and videos can't share a post, and a post carries at most one video. Facebook's multi-photo
post is the only "carousel". There are no Shorts. The driver publishes to Pages only.

Which variant a single video becomes:

1. `FacebookOptions::$isStory = true` → video story.
2. `FacebookOptions::$asReel = true` → Reel; `false` → regular video.
3. Otherwise the shape decides: width/height within 0.01 of 9:16 → Reel, anything else → regular
   video. The shape comes from the `MediaInspector` (ffprobe by default), else from
   `Media::$meta['width']` / `['height']`.
4. When nothing reports the shape, the video goes out as a regular video and a warning is logged
   (`[SocialPoster] facebook: the video's dimensions are unknown...`, when `social.error_logging` is on).

Facebook itself accepts Reels between 16:9 and 9:16. The 9:16 routing is the package's choice: pass
`asReel: true` for a Reel of another shape.

### Text or link

```php
use SocialPoster\Facades\SocialPoster;

SocialPoster::on('facebook')
    ->using(['account_id' => $pageId, 'page_access_token' => $pageToken])
    ->caption('Our spring schedule is out: https://example.com/schedule')
    ->post();
```

The first link in the caption becomes the link card (`link`): trailing punctuation is dropped and
`https://` is added to a bare domain (`example.com/page`). See [`link`](#typed-options) to pick
another URL or turn the card off.

### Photos (1 to 10)

```php
use SocialPoster\ValueObjects\Media;

SocialPoster::on('facebook')
    ->using($credentials)
    ->media(
        Media::image('https://cdn.example.com/photos/stage.jpg', altText: 'The main stage at night'),
        Media::image('https://cdn.example.com/photos/crowd.png'),
    )
    ->caption('Photos from Saturday.')
    ->post();
```

The photos are uploaded unpublished, several at a time (`api.facebook.photo_concurrency`), and the
post attaches them in the order given. Alt text is sent as `alt_text_custom`.

### Regular video

```php
use SocialPoster\Platforms\Facebook\FacebookOptions;

SocialPoster::on('facebook')
    ->using($credentials)
    ->media(Media::video('https://cdn.example.com/videos/talk.mp4', thumbnail: 'https://cdn.example.com/videos/talk.jpg'))
    ->title('Keynote, full length')
    ->caption('The full keynote.')
    ->withOptions(new FacebookOptions(asReel: false))
    ->post();
```

The caption is sent as `description`, the title as `title`. A thumbnail is uploaded as bytes with the
create request (see [Thumbnails](#thumbnails)).

### Reel

```php
SocialPoster::on('facebook')
    ->using($credentials)
    ->media(Media::video('/var/app/storage/clips/teaser.mp4'))   // 9:16, 3 to 90 s
    ->caption('Ten seconds of the new venue.')
    ->post();
```

A local file is uploaded directly (`api.facebook.local_video_upload`). A remote URL is fetched by
Facebook (hosted-file upload). The title, when set, is sent with the finish call.

### Photo story

```php
SocialPoster::on('facebook')
    ->using($credentials)
    ->media(Media::image('https://cdn.example.com/stories/today.jpg'))
    ->withOptions(new FacebookOptions(isStory: true))
    ->post();
```

### Video story

```php
SocialPoster::on('facebook')
    ->using($credentials)
    ->media(Media::video('https://cdn.example.com/stories/today.mp4'))   // 9:16, 3 to 60 s
    ->withOptions(new FacebookOptions(isStory: true))
    ->post();
```

Stories can't be scheduled, take no first comment and get no permalink (`PostResult::$url` is null).

### Scheduled post, video or Reel

```php
SocialPoster::on('facebook')
    ->using($credentials)
    ->caption('Doors open at 9.')
    ->withOptions(new FacebookOptions(scheduledAt: now()->addDay()))
    ->post();
```

- `/feed` and `/videos` get `published=false` and `scheduled_publish_time`. A scheduled photo post's
  photos are uploaded with `temporary=true`, and its `/feed` call also carries
  `unpublished_content_type=SCHEDULED`.
- A Reel gets `video_state=SCHEDULED` and `scheduled_publish_time` on its finish call.
- The result's `state` is `scheduled` and its `url` is null. The first comment is skipped
  (`CommentSkipped`, reason `scheduled`).
- The v1.0 recipe (`extra` with `published => false` and `scheduled_publish_time`) still works for
  feed posts, regular videos and Reels. `scheduledAt` wins when both are set.

---

## Credentials

| Key | Required | Meaning |
|---|---|---|
| `account_id` | yes | The Page id. The edges are `/{account_id}/feed`, `/photos`, `/videos` and so on. It is also the account's identity in idempotency keys |
| `page_access_token` | yes, or `access_token` | A Page access token for that Page |
| `access_token` | alternative | Read when `page_access_token` is blank, so credentials shaped like the other drivers' work |
| `scopes` | optional | The granted permissions (a list, or a space- or comma-separated string). When present, validation and the comment step check them |

A missing `account_id` or token fails with `PermanentException(Misconfigured)` before any request.
Its context holds `missing_credentials`, for example `['page_access_token|access_token']`. `expires_at`
is not read by this driver.

```php
use SocialPoster\ValueObjects\Credentials;

Credentials::make([
    'account_id' => $pageId,
    'page_access_token' => $pageToken,
    'scopes' => ['pages_manage_posts', 'pages_read_engagement', 'pages_manage_engagement', 'pages_read_user_content'],
]);

// Or keep the token out of the queue payload and resolve it per job run:
Credentials::ref('social_account:42', ['account_id' => $pageId]);
```

A `FacebookDriver` subclass that overrides the v1.0 `protected string $tokenKey` is honoured: the token
is read from `$tokenKey`, then `page_access_token`, then `access_token`.

### Page tokens

- Use a **Page** access token. A user token gets Graph error #200 on Page actions, which fails as
  `InsufficientPermissions`.
- The package does not obtain or exchange tokens. Get the Page token in your connect flow (for example
  `GET /me/accounts` with a user token that has `pages_show_list`). Meta's access-token guide says a
  Page token obtained from a long-lived user token has no expiry date.
- If `config('social.authorizers.facebook')` names a class implementing `AuthorizesRequests`, Graph
  requests are built from it, and multi-photo uploads go one by one instead of in parallel. The
  Reel/story upload step still sends `Authorization: OAuth` with the token from the credentials.

### Permissions (scopes)

`SocialPoster::requiredScopes(Platform::Facebook, $feature)` returns them as groups that are all
required:

| Feature | Checked by the driver | Also needed |
|---|---|---|
| `Feature::Publish` | `pages_manage_posts` | `pages_read_engagement`, for the duplicate lookups (`/published_posts`, `/scheduled_posts`, `/videos`, `/stories`) |
| `Feature::Comment` | `pages_manage_engagement` and `pages_read_user_content` | — (the comment lookup reads `/{post}/comments` with these) |
| `Feature::Video` | — | — |

```php
use SocialPoster\Enums\{Feature, Platform};

SocialPoster::requiredScopes(Platform::Facebook, Feature::Comment);
// [['pages_manage_engagement'], ['pages_read_user_content']]
```

- With `scopes` in the credentials and `pages_manage_posts` missing, validation fails under
  `credentials.scopes`: `The Facebook token is missing a permission needed to publish: grant pages_manage_posts.`
  Without `scopes`, nothing is checked.
- Meta's Page Stories documentation lists `pages_manage_posts`, `pages_read_engagement` and
  `pages_show_list` for stories.
- App review: on Pages managed by people with no role on your Meta app, these permissions need
  Advanced Access, which Meta grants through App Review.

### Media hosting

Facebook downloads every photo, every regular video, and every Reel or story given by URL. The host
must:

- be reachable from the internet and let the `facebookexternalhit` user agent in (robots.txt);
- not be an `fbcdn` URL, which Facebook refuses for hosted files (per Meta's Page Stories
  documentation; the package does not check it).

A local photo or regular video fails validation unless the bound `MediaGateway` can publish it at a
URL (for example `FilesystemMediaGateway` with `social.media.rehost`). A local Reel or video story is
uploaded from disk unless `api.facebook.local_video_upload` is `false`.

---

## Typed options

```php
use SocialPoster\Platforms\Facebook\FacebookOptions;

new FacebookOptions(
    isStory: false,
    thumbnail: null,
    extra: [],
    asReel: null,
    link: null,
    scheduledAt: null,
);
```

The positions are those of v1.0 (`isStory`, `thumbnail`, `extra`), with the new fields appended.
Options serialized by v1.0 into queued jobs still unserialize, with defaults for the new fields.

| Option | Type | Default | Effect | Validation |
|---|---|---|---|---|
| `isStory` | `bool` | `false` | Publish a photo story or video story instead of a feed post | A story needs exactly one media item |
| `thumbnail` | `?Media` | `null` | Custom thumbnail of a **regular video**, uploaded as the `thumb` file. Ignored on Reels, stories, photos and text (a warning is logged when `social.error_logging` is on) | Regular video only: bmp, gif, jpeg, jpg, png or tiff (an extensionless URL needs an inspector-confirmed image MIME), at most `thumbnail_max_bytes` (`thumbnail`) |
| `extra` | `array` | `[]` | Raw params for the create calls, see [below](#the-extra-passthrough) | Not validated |
| `asReel` | `?bool` | `null` | `true`: Reel. `false`: regular video. `null`: decide from the 9:16 shape | — |
| `link` | `string\|false\|null` | `null` | Link card of a post without media. A URL (bare domains get `https://`), `false` for no card, `null` for the caption's first link | A string must be a URL (`link`) |
| `scheduledAt` | `?DateTimeInterface` | `null` | Publish later as a scheduled post, video or Reel | 10 minutes to 29 days ahead; never on a story (`scheduledAt`). A Reel needs 15 minutes, see [Limits](#scheduling) |

Examples:

```php
// A Reel from a 4:5 video.
new FacebookOptions(asReel: true);

// A text post without a link card.
new FacebookOptions(link: false);

// A link card for a URL that is not in the caption.
new FacebookOptions(link: 'example.org/landing');

// A regular video with its own thumbnail.
new FacebookOptions(asReel: false, thumbnail: Media::image('https://cdn.example.com/cover.png'));
```

### The `extra` passthrough

| Where `extra` goes | Calls | Rules |
|---|---|---|
| Top-level keys | `/feed`, `/videos`, the Reel finish, the video story finish, `/photo_stories` | Driver keys win (`message`, `attached_media`, `file_url`, `description`, `title`, `video_state`, `upload_phase`, `video_id`, `photo_id`, the scheduling fields) |
| `extra['photo']` (an array) | each `/photos` upload, photo stories included | Driver keys win (`url`, `published`, `temporary`, `alt_text_custom`). Never sent at post level |
| `extra['link']` | `/feed` | Used as the link card instead of the caption's first link. Dropped when `link: false` |
| Story calls | `/video_stories` finish, `/photo_stories` | `published`, `scheduled_publish_time` and `unpublished_content_type` are removed |

```php
new FacebookOptions(extra: [
    'place' => $placePageId,                   // /feed, /videos or the Reel finish
    'is_ai_generated' => true,                 // Reel finish
    'photo' => ['no_story' => true],           // each /photos upload
]);
```

`extra` cannot change a Reel's `video_state`. On `/videos`, booleans are sent as `'true'`/`'false'`.

### Thumbnails

| Source | Applies to | Behaviour |
|---|---|---|
| `FacebookOptions::$thumbnail` | regular video only | Validated (format, size). Must be readable when the video is created: a read failure fails the post, flagged as never sent |
| `Media::video($src, thumbnail: ...)` | regular video only, when no `FacebookOptions::$thumbnail` | Best-effort, never a validation error. Sent only when Facebook can take it (format above, at most `thumbnail_max_bytes`). Otherwise, or when it cannot be read, the video posts without it and the result's `payload['thumbnail_warning']` says why |

Reels, stories and photo posts take no thumbnail from this driver.

---

## Limits

### Text

| Text | Limit | Counting |
|---|---|---|
| Caption | 63,206 characters (fixed) | Unicode code points (`mb_strlen`) of the trimmed text |
| First comment | 8,000 characters (`comment_max`) | Code points, trimmed |

A post without media needs a non-blank caption. A story sends no caption.

### Media

| Variant | Formats | Size | Duration | Other |
|---|---|---|---|---|
| Feed photo | jpeg, jpg, png, gif, tiff, bmp | 4 MB (`photo_max_bytes`, 4194304) | — | 1 to `max_attached_media` (10) per post, images only |
| Story photo | jpeg, jpg, png, gif, tiff, bmp | 10 MB (`story_photo_max_bytes`, 10485760) | — | exactly 1 |
| Regular video | mp4, mov | 1 GB (`video_max_bytes`, 1073741824) | 3 s to 1200 s (`video_max_duration`) | exactly 1, public URL |
| Reel | mp4, mov | no package limit | 3 s to 90 s (`reel_max_duration`) | at least 540×960, 24 to 60 fps, no maximum resolution |
| Video story | mp4, mov | no package limit | 3 s to 60 s (`story_max_duration`) | 9:16 (±0.01), at least 540×960, 24 to 60 fps |
| Video thumbnail | bmp, gif, jpeg, jpg, png, tiff | 10 MB (`thumbnail_max_bytes`, 10485760) | — | regular videos only |

- The extension is always checked. Size, duration, dimensions, frame rate and aspect ratio are checked
  only when the inspector (ffprobe) or `Media::$meta` reports them. An unknown value passes.
- Frame rates get half a frame of tolerance (23.976 counts as 24, 59.94 as 60). Dimensions are
  rotation-corrected by the inspector.
- A video's reported MIME type must be MP4/QuickTime-family; a `.mp4` that is really WebM fails.
- The minimum durations (3 s), the minimum resolution, the frame-rate range and the formats are fixed.
  The keys in parentheses override the rest.
- Facebook reports its own Reel/story checks as errors 1363040 (aspect ratio), 1363127 (resolution),
  1363128 (length) and 1363129 (frame rate). The package turns them into readable `MediaRejected`
  messages.

### Scheduling

| Rule | Value | Where |
|---|---|---|
| Earliest | 10 minutes ahead (`FacebookOptions::MIN_SCHEDULE_AHEAD`, 600) | `FacebookOptions::validate()` |
| Latest | 29 days ahead (`FacebookOptions::MAX_SCHEDULE_AHEAD`), for every variant | `FacebookOptions::validate()` |
| Scheduled Reel | at least 600 + `finish_delay` (60) + `reel_schedule_margin` (240) seconds ahead: 15 minutes by default | validation, because the time rides on the finish call sent later |
| When the create is sent | more than 10.5 minutes ahead | otherwise `PermanentException(Validation)`: "The scheduled time is now less than 10 minutes away..." and nothing is sent |
| Stories | never | validation, and again at publish |

The send-time check covers queue delay and the Reel finish delay. When it fires in a verify phase
(an earlier create got no answer), the exception is flagged `possibly_published`. The v1.0 `extra`
recipe is not range-checked at validation, only at send time.

---

## First comment

`->withComment('...')` adds a top-level comment as the Page once the post is live:
`POST /{post-id}/comments` with `message`.

```php
SocialPoster::on('facebook')
    ->using($credentials)
    ->caption('Tickets are on sale.')
    ->withComment('Box office hours: https://example.com/box-office')
    ->post();
```

| Condition | Behaviour |
|---|---|
| Variants | Feed posts (text, link, photos), regular videos and Reels. The target is `PostResult::$platformPostId` (`{page}_{post}` or a video id) |
| Stories | No comment: `CommentSkipped`, reason `story` |
| Scheduled posts | No comment: `CommentSkipped`, reason `scheduled` |
| Length | 8,000 characters (`comment_max`), checked at `validate()` under `comment` |
| Scopes | `pages_manage_engagement` and `pages_read_user_content`. With `scopes` in the credentials and one missing, skipped (`CommentSkipped`, reason `missing_scope`). Without `scopes`, attempted |
| Delay | `social.comment_delay` (60 s). A Reel reported as `processing` waits at least `social.comment_processing_delay` (300 s) |
| Body | `message` only. The post's options and `extra` are not copied |

Before posting, the driver reads the post's comments (`GET /{post}/comments`, `filter=toplevel`,
`order=reverse_chronological`, `limit=25`). If the Page already has a comment with the same text (after
trimming and normalising line endings), that comment is returned with `payload['recovered'] = true`
instead of posting again. A listed comment whose `from.id` is another account is ignored; one without
`from` is matched on its text alone.

- A throttled or failed lookup (any `TemporaryException`) is thrown, and the comment job retries it
  (`social.comment_tries`, `social.comment_backoff`) instead of commenting blind.
- A refused lookup (permissions) proves nothing and the comment is posted, as before the lookup existed.
- A 2xx answer without a comment id is `PermanentException(OutcomeUnknown)` with `possibly_published`.
- Other errors go through the same table as posts. Outcomes arrive as `CommentPublished`,
  `CommentFailed` or `CommentSkipped`. The comment's permalink is fetched best-effort.

The deprecated `comment(PreparedPost, string $postId, string $comment): ?string` still works and calls
`addFirstComment()`.

---

## Async behaviour

A publish is a small state machine. Each step is one job run; the job carries the state between runs.
Every create Facebook cannot undo runs in its own **commit phase**: the job records it before the call,
so a repeat after an unclear outcome runs with `_recovering` set (see
[Duplicate safety](#duplicate-safety)).

| Phase (`state['phase']`) | What happens | Next |
|---|---|---|
| `publish()` | Text, regular video: nothing is sent. Photos: the unpublished `/photos` uploads. Reel / video story: the upload session start (`upload_phase=start`) and the transfer. Photo story: the unpublished `/photos` upload | `feed_create`, `video_create`, `reel_finish`, `story_finish` or `photo_story` (all commit phases) |
| `feed_create` (`feed_verify`) | Lookup when recovering, then `POST /{page}/feed` | Done |
| `video_create` (`video_verify`) | Lookup when recovering, then `POST /{page}/videos` (form, or multipart with a thumbnail) | Done |
| `photo_story` (`photo_story_verify`) | Lookup when recovering, then `POST /{page}/photo_stories` | Done |
| `reel_finish` / `story_finish` | `GET /{video-id}?fields=status`, then the finish call | `reel_status` |
| `reel_status` | `GET /{video-id}?fields=status` | `reel_status` again, or done |

What each `Pending` means:

- **Create step** (`feed_create`, `video_create`, `photo_story`): a commit `Pending` with recheck 0.
  The next run creates the post without delay. `postNow()` runs it at once.
- **Finish step** (`reel_finish`, `story_finish`): a commit `Pending` with recheck `finish_delay`
  (60 s), while Facebook fetches the file. The finish first reads the video's status:
  - an upload, processing or publishing error, or `video_status` `error`/`upload_failed`/`expired`
    → `PermanentException(MediaRejected)` with a readable reason;
  - publishing already started (an earlier finish went through) → no second finish, straight to the poll;
  - the upload still in progress → wait 30 s and read again, up to `upload_checks` (10) times, then
    finish anyway.
- **Status poll** (`reel_status`): recheck `status_interval` (20 s). The post exists, so the wait is
  committed: a later failure is reported as possibly published. `PostProcessing` fires on each poll.
  The poll declares its own deadline: the finish time + `status_polls` × `status_interval` + 600 s
  (900 s by default), which the job honours instead of `social.async.max_wait`.
- **Verify step** (`*_verify`): a commit `Pending` with recheck 60 s, or the throttle wait.

How the poll ends:

| Status | Result |
|---|---|
| `publishing_phase.status` complete, or `publish_status` `published` | `Published`, state `published` |
| `publish_status` `scheduled`, or a scheduled post | `Published`, state `scheduled`, no url |
| A story with `video_status` `ready` or processing complete | `Published`, state `published`, no url |
| An error state | `PermanentException(MediaRejected)` |
| Still processing after `status_polls` (15), or the next poll would pass the deadline | `Published`, state `processing`, `payload['processing'] = true`. Never a failure: Facebook accepted the finish |
| Status refused (a `PermanentException`) | `Published`, state `processing`, `payload['status_unavailable'] = true` |
| Status throttled or unreachable | Waited out inside the deadline (the throttle's wait, at least `status_interval`). When time runs out: `processing` with `status_unavailable` |
| Finish answered without `success: true` and publishing never seen to start | `PermanentException(OutcomeUnknown)`, `possibly_published = true` |

Timings with the default settings:

| Variant | Runs before the result |
|---|---|
| Text, link, photos | 2: `publish()` (with the photo uploads), then the create immediately after |
| Regular video | 2. Facebook may still process the video after `/videos` answers; the driver does not poll it |
| Photo story | 2 |
| Reel, video story | `publish()` with the transfer (up to `upload_timeout`), the finish at least 60 s later, then polls every 20 s, at most 15 or 900 s after the finish |

How long Facebook takes to process a Reel is not measured by the package (live check 4). A Reel that is
still `processing` when the poll ends usually goes live afterwards. The package does not follow it
further: if Facebook fails it later, no event says so.

`postNow()` waits up to `social.sync.max_wait` (120 s). A Reel or video story can exceed it with the
default finish delay; the publish is then handed to the queue and the returned state is `processing`.

Timeouts: `/feed`, `/photo_stories` and the finish calls use `publish_timeout`
(`social.publish_timeout`, 120 s). `/videos` and the Reel/story transfer use `upload_timeout`
(`social.upload_timeout`, 300 s). `/photos` uploads use `photo_timeout` (60 s). Everything else uses
`timeout` (`social.http_timeout`, 60 s). The connect timeout is `connect_timeout` (10 s).

### Results

| Variant | `platformPostId` | `idKind` | `state` | `url` | Payload keys |
|---|---|---|---|---|---|
| Text / photos | `{page}_{post}` from `/feed` | `post_id` | `published` or `scheduled` | `permalink_url`, else `https://www.facebook.com/{id}`; null when scheduled | `response`, `media_ids`, `link_dropped`, `recovered`, `duplicate_refused` |
| Regular video | video id | `video_id` | `published` or `scheduled` | as above (a relative permalink is made absolute) | `response`, `thumbnail_warning`, `recovered`, `duplicate_refused` |
| Reel | `{page}_{post}` from the finish, else the video id | `post_id` or `video_id` | `published`, `scheduled` or `processing` | as above; null when scheduled | `video_id`, `post_id`, `processing`, `status_unavailable`, `status_error`, `recovered` |
| Video story | as Reel | as Reel | `published` or `processing` | null | as Reel |
| Photo story | `{page}_{post}` | `post_id` | `published` | null | `photo_id`, `response`, `recovered` |

The finish and `/photo_stories` return a bare `post_id`, which Graph refuses on its own ("#12 singular
statuses API is deprecated"), so the driver prefixes it with the Page id. A finish without `post_id`
leaves the result on the video id, never prefixed. A Reel recovered after its finish answer was lost
also carries the video id.

---

## Error handling

Graph errors are `{error: {code, error_subcode, message, error_user_msg, type, is_transient, fbtrace_id}}`.
The exception message is `error_user_msg`, else `message`. The context holds `code`, `subcode`, `type`,
`is_transient`, `error_user_title`, `error_user_msg`, `fbtrace_id`, `raw_message`, `error`, `status`
and `http_status`. Checks run in this order:

| Facebook answer | Exception | `FailureReason` | Action |
|---|---|---|---|
| Upload step `{debug_info}`: `ProcessingFailedError`, `retriable: true`, or HTTP 5xx | Temporary (60 s) | `ServerError` | `publish()` runs again with a new upload session |
| Upload step: an OAuth type, or HTTP 401 | Permanent | `InvalidToken` | Reconnect |
| Upload step: anything else | Permanent | `MediaRejected` | The file URL could not be fetched (public, robots.txt, not fbcdn) |
| Subcode 2207003 (took too long to download the file) | Temporary (30 s) | `Timeout` | Retried |
| 1363040, 1363127, 1363128, 1363129 (as code or subcode) | Permanent | `MediaRejected` | Readable reason: aspect ratio, resolution, length, frame rate |
| Code 324 (image missing or unreadable) | Permanent | `MediaRejected` | Fix the image |
| 1609005 (link could not be fetched) | Permanent | `MediaRejected` | On `/feed` with a link, first retried once without the link (`payload['link_dropped'] = true`) |
| Codes 102, 190; subcodes 458, 463, 467; "Error validating access token" or "has not authorized application" in the message | Permanent | `InvalidToken` | Reconnect. 458/460/463/467/492 are `terminal`; others are `reresolvable` (one retry with re-resolved credentials when a custom `CredentialsResolver` is bound) |
| Subcode 460 (password changed) | Permanent | `InvalidToken` | Reconnect |
| Subcodes 459, 464 (checkpoint) | Permanent | `AccountRestricted` | The owner must log in on facebook.com |
| Subcode 492 (lost the Page role) | Permanent | `InsufficientPermissions` | Restore the role or reconnect with a Page admin |
| Codes 4, 17, 32, 341, 613, 80000 to 80014, HTTP 429, or "rate limit" in the message | Temporary | `RateLimited` | Waits `estimated_time_to_regain_access` (minutes, largest in `X-Business-Use-Case-Usage` / `X-App-Usage`), else `Retry-After`, else 900 s (4, 17, 613, 800xx) or 300 s |
| `is_transient`, codes 1, 2, -1, -2, any 5xx (JSON or not) | Temporary (60 s) | `ServerError` | Retried |
| Codes 3, 10, 200 to 299, or 100 with subcode 33 | Permanent | `InsufficientPermissions` | Missing permission or a user token: reconnect |
| Code 368 (temporarily blocked) | Permanent | `AccountRestricted` | Context `retry_later = true` |
| Code 506 (duplicate post) | Permanent | `DuplicateContent` | During recovery, taken as the earlier attempt's post (see below) |
| Code 100 (invalid parameter) | Permanent | `Unknown` | Message: Facebook's explanation plus the technical message |
| anything else | base table | 401 `InvalidToken`, 403 `InsufficientPermissions`, 404 `NotFound`, 408/425 `ServerError` (Temporary), other `Unknown` | — |

The driver maps no Facebook error to a daily quota, so no Facebook failure carries `retry_at`. A
throttle is a `TemporaryException`: the job waits it out, up to 3600 s per retry and `social.tries` (5)
attempts, then fails with `RateLimited`.

Other failures raised by the driver:

| Situation | Exception / reason |
|---|---|
| No `account_id` or no token | Permanent `Misconfigured` (`missing_credentials`), before any request |
| `isStory` with `scheduledAt` at publish | Permanent `Validation` |
| Scheduled time under 10.5 minutes away when the create is about to be sent | Permanent `Validation`, nothing sent |
| A `/photos` or upload-session answer without an id | Temporary `ServerError` (safe to retry) |
| A dropped connection during the parallel photo uploads | Temporary `Network` |
| The transfer not answered with `success: true` | Temporary `ServerError` (60 s), new upload session |
| A local Reel/story file that cannot be read | Permanent `MediaRejected` |
| `FacebookOptions::$thumbnail` unreadable | Its read error, flagged `request_sent = false` |
| `/feed` or `/videos` 2xx without an id | Permanent `OutcomeUnknown`, `possibly_published = true` |
| A finish answering `success: false` | Permanent `Unknown` |
| `/photo_stories` answering `success: false` | Permanent `Unknown` |
| `/photo_stories` without `success`, or without `post_id` | Permanent `OutcomeUnknown`, `possibly_published = true` |
| A failed upload, processing or publishing status | Permanent `MediaRejected` (context `video_status`, `failed_phase`, `code`, `status_body`) |
| An unknown phase, or a state missing its id | Permanent `Unknown` (`missing_field`) |
| Connection lost (no response) | Handled by the job as `Network`; on a create, see below |

`FailureReason::requiresReconnect()` is true for `InvalidToken` and `InsufficientPermissions`.
`category()` groups the reasons into `retry`, `verify` (`OutcomeUnknown`), `reconnect`, `user_fix`,
`operator` and `not_found`. An exception flagged `possibly_published` reports `verify` through
`errorSummary()` whatever its reason.

Every failed response is logged (`[SocialPoster] facebook API error: ...`) with tokens redacted. Turn it
off with `social.error_logging`, or route it with `social.error_log_channel`.

---

## Duplicate safety

Four calls can create something twice: `/feed`, `/videos`, `/photo_stories` and the Reel/story finish.
Each runs in a commit phase. The job re-runs a commit phase with `_recovering` set after a worker death
or timeout, a queue redelivery, a store record still marked committing, or a lost connection or 5xx
after the request may have been sent. It does not set it for explicit refusals (4xx, throttles, token
rejections) or a request that provably never left (DNS, refused connection, TLS).

**A create that gets no answer.** For `/feed` and `/videos` the driver catches the lost connection
itself. When the request provably never left, the error is rethrown and the job retries it as is.
Otherwise the run goes to the verify phase (`feed_verify` / `video_verify`) 60 s later. After
`verify_retries` (5) unanswered creates the post fails with `OutcomeUnknown`, `possibly_published = true`.
For `/photo_stories` and the finish calls, the lost connection goes to the job, which re-runs the phase
with `_recovering` set.

What is verified before creating again:

| Create | Lookup | Match | No match |
|---|---|---|---|
| `/feed` | `GET /{page}/published_posts` (or `/scheduled_posts` when scheduled), `fields=id,message,created_time,attachments{target{id},subattachments{target{id}}}`, `since`, `limit=25` | Same message (trimmed, line endings normalised). When both are known, at least one uploaded photo id among the post's attachments. A caption-less photo post matches on a photo id alone | Created again with the stored params. Photos are never re-uploaded |
| `/videos` | `GET /{page}/videos`, `fields=id,title,description,created_time`, `since`, `limit=25` | Same description and same title (each when set) | Uploaded again |
| `/photo_stories` | `GET /{page}/stories`, `since`, `limit=25` | `media_id` equal to the uploaded photo's id | Created, but only when no story at all appeared in the window |
| Reel / story finish | `GET /{video-id}?fields=status` (always, not only when recovering) | Publishing started or done | The finish is sent again for the same uploaded video |

`since` is the commit start minus `verify_window` (300 s), and items older than `since` minus 60 s are
ignored. A found post is returned as `Published` with `payload['recovered'] = true` and no `response`.

When the lookup cannot rule the earlier attempt out, nothing is sent:

- A caption-less feed post, when posts appeared since and none of them shows attachments to compare,
  or the post has no photos: `OutcomeUnknown`, `possibly_published`, context `candidates`.
- A video with neither description nor title, when other videos appeared since: `OutcomeUnknown`.
- A photo story, when any other story appeared in the window: `OutcomeUnknown`. A story state queued
  without a window (no `since` and no commit time) is `OutcomeUnknown` without a lookup.
- A lookup refused outright (a `PermanentException`, for example a missing `pages_read_engagement`):
  rethrown with `possibly_published = true`.
- A lookup that fails temporarily (5xx, network): read again 60 s later, up to `verify_retries` (5)
  failures, then `OutcomeUnknown`.
- A throttled lookup (`RateLimited`): waited out for as long as Facebook asks (at least 60 s), without
  counting toward `verify_retries`, for up to `verify_max_wait` (3600 s) from the first unreadable
  lookup. The wait is declared as the chain's deadline so the job does not time it out first. Past
  that: `OutcomeUnknown`.

**A duplicate refusal while verifying.** If the re-create of a verifying `/feed` or `/videos` is refused
with 506, the earlier attempt went through. The driver looks it up once more: found, it is returned
as `Published` with `recovered` and `duplicate_refused`; not found, the post fails as
`PostFailed(DuplicateContent)` with `possiblyPublished = true`.

Steps before the commit are safe to repeat but not free. If `publish()` fails after some photos were
uploaded, its retry uploads them all again, and the earlier ones stay on the Page unpublished. A failed
Reel transfer leaves its upload session unused.

What this means for your code:

- `PostPublished` can carry `payload['recovered'] === true`: the post was found, not created by this run.
- `PostFailed` with `possiblyPublished === true` (`OutcomeUnknown`, `DuplicateContent`, or a scheduled
  time that ran out while verifying) means: check the Page before posting again. Never re-dispatch blindly.
- Keep an idempotency store bound (the default `CacheIdempotencyStore`, or `DatabaseIdempotencyStore`),
  give the publish your own `->idempotencyKey()`, and always pass `account_id`, so a repeat dispatch
  replays the recorded result instead of posting again.

---

## Configuration (`social.api.facebook`)

| Key | Default | Effect |
|---|---|---|
| `graph_version` | `v25.0` (env `SOCIAL_FACEBOOK_GRAPH_VERSION`) | Graph API version |
| `timeout` | `social.http_timeout` (60) | Read timeout (s) of lookups, status reads, permalinks, the upload-session start and comments |
| `connect_timeout` | `10` | Connect timeout (s) |
| `publish_timeout` | `social.publish_timeout` (120) | Read timeout (s) of `/feed`, `/photo_stories` and the finish calls |
| `upload_timeout` | `social.upload_timeout` (300) | Read timeout (s) of `/videos` and the Reel/story transfer |
| `photo_timeout` | `60` | Read timeout (s) of each `/photos` upload |
| `photo_concurrency` | `5` | Parallel `/photos` uploads (minimum 1) |
| `max_attached_media` | `10` | Photos per post (minimum 1) |
| `photo_max_bytes` | `4194304` | Feed photo size limit |
| `story_photo_max_bytes` | `10485760` | Story photo size limit |
| `thumbnail_max_bytes` | `10485760` | Regular-video thumbnail size limit |
| `video_max_bytes` | `1073741824` | Regular video size limit |
| `video_max_duration` | `1200` | Regular video, seconds |
| `reel_max_duration` | `90` | Reel, seconds |
| `story_max_duration` | `60` | Video story, seconds |
| `local_video_upload` | `true` | Upload a local Reel or video story file directly. `false` requires a public URL |
| `finish_delay` | `60` | Seconds between the transfer and the finish call |
| `upload_checks` | `10` | Times (30 s apart) the finish waits for an upload Facebook is still fetching |
| `status_polls` | `15` | Status polls after the finish (minimum 1) |
| `status_interval` | `20` | Seconds between polls (minimum 5) |
| `reel_schedule_margin` | `240` | Extra lead (s) a scheduled Reel needs on top of 10 minutes + `finish_delay` |
| `verify_window` | `300` | How far before the commit a found post may have been created (minimum 60) |
| `verify_retries` | `5` | Unanswered creates, or unreadable lookups, before `OutcomeUnknown` |
| `verify_max_wait` | `3600` | Seconds a throttled lookup may be waited out, from the first one (minimum 60) |
| `comment_max` | `8000` | First comment limit |

Related global keys: `social.http_timeout` (60), `social.publish_timeout` (120),
`social.upload_timeout` (300), `social.async.max_wait` (3600) and
`social.async.max_wait_per_platform.facebook`, `social.sync.max_wait` (120), `social.tries` (5) and
`social.backoff`, `social.comment_delay` (60), `social.comment_processing_delay` (300),
`social.comment_tries` and `social.comment_backoff`, `social.credentials_resolver`,
`social.authorizers.facebook`, `social.idempotency`, `social.gateway`, `social.inspector`,
`social.error_logging` and `social.error_log_channel`.

---

## Known limitations

- Pages only, with a Page token. The driver does not obtain, exchange or refresh tokens, and does not
  read `expires_at`.
- Photos and regular videos must be at a public URL (or a gateway that re-hosts them). Only Reels and
  video stories upload local files, in one streamed request with no resume: a dropped transfer starts
  a new upload session.
- One video per post; photos and videos can't be mixed. Stories take one item and can't be scheduled.
- Reel routing needs the video's shape. Without ffprobe or `Media::$meta`, a vertical video goes out as
  a regular video (with a logged warning). Pass `asReel`.
- Thumbnails apply to regular videos only. Video alt text is not sent.
- Scheduling is limited to 29 days for every variant.
- Recovery reads 25 items per listing within `verify_window`. A caption-less post, a video without
  description or title, or a photo story with other stories in the window ends as `OutcomeUnknown`
  rather than risking a duplicate. A Page post with the same message created in that window is taken
  as the earlier attempt.
- The first-comment check reads the 25 newest top-level comments. A Page comment with the same text
  already on the post is taken as the earlier attempt and not posted again. A listed comment without
  `from` is matched on its text alone: if Graph leaves `from` out on other users' comments, a user's
  identical comment would be taken as the Page's (live check 14 confirms which).
- A Reel or story reported as `processing` is not followed after the poll ends.
- A regular video is reported as published when `/videos` answers; its processing is not polled.
- When a request authorizer is configured, the Reel/story transfer still uses the credentials' token.
- `extra` is sent as given and never validated.

---

## Open live checks

These behaviours are covered by fixtures but not yet confirmed against the live API. Run
`tests/Live/FacebookLiveTest.php` with `SOCIAL_LIVE_FACEBOOK_PAGE_ID` and
`SOCIAL_LIVE_FACEBOOK_PAGE_TOKEN` (plus `SOCIAL_LIVE_PUBLISH=1` for the checks that create scheduled
posts and delete them). See `tests/Live/README.md`.

1. `GET /{page}/published_posts` returns posts made through `/feed`, honours `since`, and lists
   subattachment target ids equal to the unpublished `/photos` ids. Automated.
2. `GET /{page}/scheduled_posts` lists a just-scheduled post with its message. Automated.
3. `GET /{page}/videos` with `title`, `description` and `since`. Automated; whether a scheduled regular
   video is listed is manual.
4. The status shapes of Reels and video stories: `uploading_phase`, `processing_phase`,
   `publishing_phase`, `errors` vs `error`, `publish_status` values, and which signal marks a video
   story live. Manual.
5. The upload step: the `{"success":true}` body, the `debug_info` error shape, and a local upload with
   `offset: 0` and `file_size`. Manual.
6. The Reel finish: whether `post_id` is returned (bare) or absent; `title`, `video_state=SCHEDULED`
   and extra keys such as `is_ai_generated`; a finish sent before the upload completes. Manual.
7. `/videos` form-encoded `published='false'` with `scheduled_publish_time`, and multipart with the
   thumbnail. Manual.
8. `/feed` scheduled with `temporary=true` photos and `unpublished_content_type=SCHEDULED`. Automated.
9. `/photo_stories`: `success` as `true` or `"true"`, and a bare `post_id`. Manual.
10. `permalink_url` on a Video node and a Comment node, and the `https://www.facebook.com/{id}`
    fallback. Partly automated.
11. Whether 2207003, the 1363xxx codes and 1609005 arrive as `code` or `error_subcode`. Manual.
12. The `X-Business-Use-Case-Usage` header format, and `estimated_time_to_regain_access` in minutes on
    an 80001 throttle. Partly automated.
13. `GET /{page}/stories` with `post_id`, `media_id` and `creation_time` since a time. Automated. That
    `media_id` equals the unpublished `/photos` id a photo story was made from is manual.
14. `GET /{post}/comments` (`filter=toplevel`, newest first) lists the Page's own comments with
    `from.id` equal to the Page id. The listing is automated; the `from.id` value is manual.
