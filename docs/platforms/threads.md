# Threads

| | |
|---|---|
| Platform key | `'threads'` / `Platform::Threads` |
| Driver | `SocialPoster\Platforms\Threads\ThreadsDriver` (implements `SupportsFirstComment`) |
| API | Threads Graph API, `https://graph.threads.net/v1.0` (version from `api.threads.graph_version`) |
| Auth | Threads user access token, plus the numeric Threads user id |
| Media transfer | Pull. Threads downloads every image and video from a public URL |
| Options class | `SocialPoster\Platforms\Threads\ThreadsOptions` (+ `ThreadsPoll`, `ThreadsReplyControl`), or `RawOptions(Platform::Threads, [...])` |
| First comment | Yes, as a reply to the new post |
| Async | Always: create a container, poll its status, then `threads_publish` |

Every limit and setting on this page is read from `config('social.api.threads.*')` with the default
shown, so nothing depends on the published config file. The code in `src/Platforms/Threads/` is the
reference.

---

## What you can publish

| Post | Media | Notes |
|---|---|---|
| Text | none | Up to 500 characters, emoji counted as their UTF-8 bytes |
| Image | 1 image (`jpg`, `jpeg`, `png`) | Alt text sent as `alt_text` |
| Video | 1 video (`mp4`, `mov`) | Alt text sent; the thumbnail is ignored |
| Carousel | 2 to 20 images and/or videos, mixed | Alt text per item |
| Poll | none (text post) | `ThreadsOptions::$pollAttachment`, 2 to 4 options |
| Link preview | none (text post) | `ThreadsOptions::$linkAttachment` |
| Reply / thread | any of the above | `ThreadsOptions::$replyToId` |
| Quote post | any of the above | `ThreadsOptions::$quotePostId` |

A post needs text or at least one media item. Threads has no stories, reels or drafts here, and the
driver never schedules: every post is published as soon as its container is ready, and
`PostResult::$state` is `published`. `->title()` is ignored. Documents are refused (a validation
error, and `PermanentException(MediaRejected)` at publish time, before any request).

### Text

```php
use SocialPoster\Facades\SocialPoster;

SocialPoster::on('threads')
    ->using(['account_id' => $threadsUserId, 'access_token' => $token])
    ->caption('Version 1.1 is out.')
    ->post();
```

### Image

```php
use SocialPoster\ValueObjects\Media;

SocialPoster::on('threads')
    ->using($credentials)
    ->media(Media::image('https://cdn.example.com/shots/dashboard.png', altText: 'Dashboard in light mode'))
    ->caption('The new dashboard.')
    ->post();
```

The caption is optional on media posts. A blank caption sends no `text` field.

### Video

```php
SocialPoster::on('threads')
    ->using($credentials)
    ->media(Media::video('https://cdn.example.com/clips/demo.mp4', altText: 'Screen recording of the editor'))
    ->caption('A two-minute walkthrough.')
    ->post();
```

Threads has no cover parameter. A `thumbnail` passed to `Media::video()` is not sent, and the result
payload then carries `thumbnail_warning`.

### Carousel (2 to 20 items)

```php
SocialPoster::on('threads')
    ->using($credentials)
    ->media(
        Media::image('https://cdn.example.com/a.jpg', altText: 'Before'),
        Media::image('https://cdn.example.com/b.png', altText: 'After'),
        Media::video('https://cdn.example.com/c.mp4'),
    )
    ->caption('Before and after.')
    ->post();
```

Each item becomes its own container (`is_carousel_item=true`, `media_type`, URL, `alt_text`). Post-level
options and `extra` go on the `CAROUSEL` parent only, never on an item.

### Poll

```php
use SocialPoster\Platforms\Threads\{ThreadsOptions, ThreadsPoll};

SocialPoster::on('threads')
    ->using($credentials)
    ->caption('Which release cadence do you prefer?')
    ->withOptions(new ThreadsOptions(pollAttachment: new ThreadsPoll('Monthly', 'Quarterly', 'Yearly')))
    ->post();
```

### Link preview

```php
SocialPoster::on('threads')
    ->using($credentials)
    ->caption('Release notes are up.')
    ->withOptions(new ThreadsOptions(linkAttachment: 'https://example.com/changelog'))
    ->post();
```

### Reply or thread

```php
$first = SocialPoster::on('threads')->using($credentials)->caption('1/ What changed in 1.1')->postNow()['threads'];

SocialPoster::on('threads')
    ->using($credentials)
    ->caption('2/ Uploads are now staged across job runs.')
    ->withOptions(new ThreadsOptions(replyToId: $first->platformPostId))
    ->post();
```

`postNow()` returns a `processing` result with a null id when the publish outlasts
`social.sync.max_wait` (see [Async behaviour](#async-behaviour)), so check `platformPostId` before
using it. With `->post()`, take the id from the `PostPublished` event.

### Quote post

```php
SocialPoster::on('threads')
    ->using($credentials)
    ->caption('Worth reading.')
    ->withOptions(new ThreadsOptions(quotePostId: '1234567890'))
    ->post();
```

### Media URLs

Threads fetches each file itself. A remote URL is passed as is. A local file fails validation
(`media.N`) with the default `LocalMediaGateway`. Set `social.gateway` to `FilesystemMediaGateway`
(or bind your own `MediaGateway`) to re-host local and `Media::fromDisk()` files on a public disk
first. A video URL must stay reachable while Threads processes it. If Threads cannot download it,
the container ends in `ERROR` with `FAILED_DOWNLOADING_VIDEO` (see
[Error handling](#error-handling)).

---

## Credentials

| Key | Required | Meaning |
|---|---|---|
| `account_id` | yes | The **numeric Threads user id**, not the Instagram user id. Every call goes to `{base}/{account_id}/...`. It is also the account identity in idempotency keys |
| `access_token` | yes | A Threads user access token |
| `scopes` | optional | The granted permissions (a list, or a space- or comma-separated string). When present, validation and the first-comment step check them |

A missing `account_id` or `access_token` fails with `PermanentException(Misconfigured)` and context
`missing_credentials`. The check runs at the start of `publish()`, `resume()`, `addFirstComment()`
and `publishingQuota()`, before any request, and never in `validate()`.

```php
use SocialPoster\ValueObjects\Credentials;

Credentials::make([
    'account_id' => $threadsUserId,
    'access_token' => $token,
    'scopes' => 'threads_basic,threads_content_publish,threads_manage_replies',
]);

// Or keep the token out of the queue payload and resolve it per job run:
Credentials::ref('social_account:42', ['account_id' => $threadsUserId]);
```

To find the numeric id, call `GET https://graph.threads.net/v1.0/me?fields=id,username` with the
token. Pass that id, not `'me'`: the production app always publishes on the numeric id, and
`threads_publish` is not reliable on `'me'`.

### Auth model

- Host `graph.threads.net`, version `v1.0` by default (`api.threads.graph_version`, env
  `SOCIAL_THREADS_GRAPH_VERSION`).
- Every request carries `Authorization: Bearer <access_token>`. POST requests (container creates,
  `threads_publish`, replies) also carry `access_token` as a field of the JSON body, the form Meta
  documents. GET requests (status reads, permalinks, lookups, quota) carry only the header, so the
  token never appears in a URL.
- If `config('social.authorizers.threads')` names an `AuthorizesRequests` class, requests are built
  from it instead of the bearer header. The `access_token` body field is still sent when the
  credentials have one. Carousel items are then created one by one (see `pool_children`).
- The driver does not read `expires_at` and never refreshes tokens. Threads long-lived tokens expire
  unless refreshed ([Meta: long-lived tokens](https://developers.facebook.com/docs/threads/get-started/long-lived-tokens)).
  Refresh them in your app, or bind a `CredentialsResolver` (`social.credentials_resolver`) that
  returns a current token on every job run. With a custom resolver bound, a non-terminal token
  rejection (code 190 without a terminal subcode) is retried once with re-resolved credentials.

### Permissions

`SocialPoster::requiredScopes()` returns the groups the driver checks:

| Feature | Permissions |
|---|---|
| `Feature::Publish` | `threads_content_publish` |
| `Feature::Comment` | `threads_manage_replies` |
| Location tag (`locationId`) | `threads_location_tagging`, checked by validation only |

```php
use SocialPoster\Enums\{Feature, Platform};

SocialPoster::requiredScopes(Platform::Threads, Feature::Comment);
// [['threads_manage_replies']]
```

- `threads_basic` is required by Meta for every Threads call. Request it at connect time. The driver
  does not list it, so an app that stores only part of the granted list gets no false errors.
- With `scopes` in the credentials, a missing permission is a validation error under
  `credentials.scopes` (`threads_content_publish` always; `threads_location_tagging` when the post
  has a location tag). Without `scopes`, nothing is checked at validation.
- A post refused with a permission error (codes 3, 10, 200-299) says which permissions publishing
  and replies need. When the post had a location tag, the message names `threads_location_tagging`
  and the context has `required_scope = threads_location_tagging`.
- App Review: Threads accounts that are not testers of your Meta app can only be used once Meta has
  approved each permission for the app. `threads_location_tagging` is a permission of its own, on
  top of publishing ([Meta: Threads API](https://developers.facebook.com/docs/threads)).
- The live test suite deletes its test post when the token also has `threads_delete`. The driver
  itself never deletes.

---

## Typed options

```php
use SocialPoster\Platforms\Threads\{ThreadsOptions, ThreadsPoll, ThreadsReplyControl};

new ThreadsOptions(
    replyControl: ThreadsReplyControl::AccountsYouFollow,
    topicTag: 'Release notes',
    linkAttachment: 'https://example.com/changelog',
    allowlistedCountryCodes: ['US', 'CA'],
    madeWithAi: false,
);
```

Every option is post-level: it is sent on the single container or on the carousel parent, never on a
carousel item. `ThreadsOptions` passed to another platform in a fan-out is ignored there, and options
for another platform never reach Threads.

| Property | Type | Default | Sent as | Effect and rules |
|---|---|---|---|---|
| `replyControl` | `?ThreadsReplyControl` | `null` | `reply_control` | Who may reply. Not sent when `null` (Threads then allows everyone) |
| `topicTag` | `?string` | `null` | `topic_tag` | Trimmed, a leading `#` dropped. Must be 1 to 50 characters without `.` or `&` (validation key `options.topic_tag`) |
| `linkAttachment` | `?string` | `null` | `link_attachment` | Link preview. Text posts only. Must be an http(s) URL (`options.link_attachment`). Counts toward the link limit unless the text already contains it |
| `quotePostId` | `?string` | `null` | `quote_post_id` | Quote another Threads post |
| `replyToId` | `?string` | `null` | `reply_to_id` | Publish as a reply, e.g. to build a thread. Recovery then looks the post up among the account's replies first |
| `locationId` | `?string` | `null` | `location_id` | Tag a place. Needs `threads_location_tagging` |
| `allowlistedCountryCodes` | `string[]` | `[]` | `allowlisted_country_codes` | Show the post only in these countries. ISO 3166-1 alpha-2, uppercase (validation rejects anything else, `options.allowlisted_country_codes`). Sent as one comma-separated string, e.g. `US,CA` |
| `madeWithAi` | `bool` | `false` | `is_made_with_ai=true` | AI-generated label. Sent only when `true` |
| `spoilerMedia` | `bool` | `false` | `is_spoiler_media=true` | Blur the media as a spoiler. Media posts only: on a text post it is a validation error (`options.spoiler_media`) |
| `pollAttachment` | `?ThreadsPoll` | `null` | `poll_attachment` | A poll. Text posts only (`options.poll_attachment`) |
| `extra` | `array` | `[]` | merged as given | Raw params, see below |

`ThreadsReplyControl` cases: `Everyone` (`everyone`), `AccountsYouFollow` (`accounts_you_follow`),
`MentionedOnly` (`mentioned_only`), `ParentPostAuthorOnly` (`parent_post_author_only`),
`FollowersOnly` (`followers_only`).

`ThreadsPoll(string $optionA, string $optionB, ?string $optionC = null, ?string $optionD = null)`:

- sent as `{"option_a": ..., "option_b": ..., ...}`, trimmed, blank options left out;
- A and B are required, D needs C, and options must differ (compared case-insensitively);
- each option is at most `ThreadsPoll::MAX_OPTION_LENGTH` (25) characters after trimming, counted
  with `mb_strlen`.

On a post with media, `linkAttachment` and `pollAttachment` are validation errors. If validation is
skipped (`->withoutValidation()`), the typed values are left out of the request.

### The `extra` passthrough

`ThreadsOptions::$extra`, or `RawOptions(Platform::Threads, [...])`, adds fields the class does not
model (`text_entities`, `gif_attachment`, `enable_reply_approvals`...). They are merged into the single
container or the carousel parent:

- keys the driver computes (`media_type`, `text`, URLs, `access_token`, `children`, and the typed
  options above) win over `extra`;
- `auto_publish_text` is always removed: the driver publishes through `threads_publish`, and a
  container that published itself on creation would make that call fail;
- `extra` never reaches carousel items or the first-comment reply;
- `extra` is not validated, except that `location_id`, `reply_to_id`, `link_attachment` and
  `poll_attachment` given there are taken into account by validation and recovery like the typed
  options.

```php
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\RawOptions;

new RawOptions(Platform::Threads, [
    'text_entities' => [['entity_type' => 'SPOILER', 'offset' => 0, 'length' => 5]],
]);
```

---

## Limits

### Text

- Caption: at most `api.threads.max_characters` (500), counted on the trimmed text with
  `ThreadsCounter`. A grapheme that contains an emoji component (a pictograph, a regional indicator,
  U+FE0F, U+200D or U+20E3) counts as its UTF-8 byte length: `👍` is 4, a flag 8, a thumbs-up with a
  skin tone 8. Every other grapheme counts as its code points. 125 `👍` fit, 126 do not.
- Links: at most `api.threads.max_links` (5) per post. The count is every unique URL in the text
  (compared case-insensitively, `https://` added to bare domains, a trailing `/` ignored; bare
  domains such as `example.com` count), plus `linkAttachment` when it is not one of them. The same
  limit applies to the first comment.
- Alt text: at most `api.threads.max_alt_text` (1000) characters per item.
- Text or media is required.

Validation errors land under `caption`, `comment`, `media.N` and `options.*`.

### Media

| | Images | Videos |
|---|---|---|
| Formats | `jpg`, `jpeg`, `png` | `mp4`, `mov`; codec `h264` or `hevc` |
| Size | `max_image_bytes` = 8,388,608 (8 MB) | `max_video_bytes` = 1,073,741,824 (1 GB) |
| Duration | n/a | `min_video_duration` = 1 s to `max_video_duration` = 300 s |
| Dimensions | none | width at most `max_video_width` = 1920 px (width only; height is not capped) |
| Aspect ratio | 0.1 to 10 (width / height) | 0.01 to 10 |
| Frame rate | n/a | `min_video_frame_rate` = 23 to `max_video_frame_rate` = 60 fps, with 0.5 fps of tolerance (23.976 and 59.94 pass) |

- Extensions are always checked. Size, duration, dimensions, aspect ratio, codec and frame rate are
  checked only when metadata is known: from the bound `MediaInspector` (ffprobe by default) or from
  the `meta` array given to `Media::image()` / `Media::video()`. Width and height are the display
  values, with rotation applied.
- Aspect ratios, formats and codecs are fixed in the driver. The other limits are the config keys
  named in the table.
- Audio (Threads expects AAC, mono or stereo) and bit rate are not checked before publishing. Threads
  reports them as container errors, mapped below.

### Carousel

- 2 to `api.threads.max_carousel_items` (20) items; images and videos can be mixed.
- A single post takes at most one media item.
- Each item follows the image or video rules above.

---

## First comment

`->withComment('...')` posts a reply to the new post once it is live: a `TEXT` container with
`reply_to_id` set to the post's id, a few status reads, then `threads_publish`.

```php
SocialPoster::on('threads')
    ->using(['account_id' => $threadsUserId, 'access_token' => $token, 'scopes' => $grantedScopes])
    ->caption('Release notes are up.')
    ->withComment('Full changelog: https://example.com/changelog')
    ->post();
```

| Condition | Behaviour |
|---|---|
| Length | `api.threads.max_comment_characters` (500), counted like the caption (`ThreadsCounter`). Checked at `validate()` under `comment`, and again by the comment job (`InvalidContent`) |
| Links | At most `max_links` (5), checked at `validate()` |
| Permission | `threads_manage_replies`. With `scopes` in the credentials and the permission missing: skipped (`CommentSkipped`, reason `missing_scope`) |
| `scopes` absent | **Skipped** by default (`CommentSkipped`, reason `missing_scope`), as the production app does. Set `api.threads.comment_unknown_scopes` to `'attempt'` to try anyway. Any other value counts as `'skip'` |
| Delay | `social.comment_delay` (60 s). Threads sets no minimum of its own |
| Parent recovered without an id | Skipped (`CommentSkipped`, reason `no_post_id`); see [Duplicate safety](#duplicate-safety) |
| Body | Only `media_type=TEXT`, `text` and `reply_to_id`. The post's options and `extra` are never copied |
| Quota | Replies count against the 1,000 replies per 24 hours per profile, not the 250-post quota |

The reply container is read up to `api.threads.reply_status_polls` (5) times,
`api.threads.reply_status_interval` (2) seconds apart, inside the comment job:

- `FINISHED` or `PUBLISHED`: publish it;
- `ERROR`: `PermanentException(MediaRejected)` with a readable reason;
- still `IN_PROGRESS` after the reads, or `EXPIRED`: `TemporaryException(ServerError, 30 s)`; the
  retry creates a new reply container (the unpublished one is left to expire);
- no status at all (or only temporary read failures): published anyway; a container that is not
  ready answers 4279009, which is retried;
- a permanent read failure (e.g. a rejected token) stops the reply.

The comment result's payload holds `container_id` and the `threads_publish` answer under `response`.
A refused reply with a permission error has context `required_scope = threads_manage_replies`.
Retries follow `social.comment_tries` (5) and `social.comment_backoff`. Outcomes arrive as
`CommentPublished`, `CommentFailed` or `CommentSkipped`. How a lost reply publish is handled is
described under [Duplicate safety](#duplicate-safety).

---

## Async behaviour

Every Threads post goes through a container. Each step below is one job run; the job carries the
state between runs. `PostProcessing` fires for every wait that is not a commit.

| Phase (`state['phase']`) | What happens | Next |
|---|---|---|
| `publish()` | Optional quota pre-flight (`check_quota`). Single post: `POST {account}/threads` creates the container (`TEXT`, `IMAGE` or `VIDEO`). Carousel: every item is created (concurrently, see `pool_children`) | `await` after `initial_check_after` (30 s); carousel: `await_children` after 10 s, or 30 s when an item is a video |
| `await_children` | Reads every pending item (`fields=status,error_message`). `FINISHED` items leave the list. An item in `ERROR` or `EXPIRED` is re-created in place. When none is left, the `CAROUSEL` parent is created (`children` = the item ids, in source order) | `await_children` again (10 s, or 30 s while a video item is pending), or `await` after 10 s |
| `await` | Reads the container status (see the table below) | `publish`, `await`, a restart, or recovery |
| `publish` | `POST {account}/threads_publish` with `creation_id`, the only call that makes the post live (the commit phase). Then the permalink is read (`fields=permalink`), best-effort | Done |

The published `PostResult` has the post id, the permalink as `url` (null when the read failed) and a
payload with `container_id`, `children` (the carousel item ids), the `threads_publish` answer under
`response`, and `thumbnail_warning` when a video thumbnail was ignored.

Container status in `await`:

| Status | Action |
|---|---|
| `FINISHED` | Go to `publish` with no delay |
| `IN_PROGRESS` | Read again after `video_poll_interval` (30 s) for a video, `poll_interval` (10 s) otherwise. The driver never publishes an `IN_PROGRESS` container and sets no limit of its own |
| none, or one Threads does not document | Read again after 10 s. After `missing_status_polls` (12) such reads in a row, publish anyway, so Threads' answer says what is wrong. An `IN_PROGRESS` read resets the count |
| `PUBLISHED` | An earlier `threads_publish` went through: recover the post instead of publishing again (see [Duplicate safety](#duplicate-safety)) |
| `EXPIRED` | The container was not published within 24 hours. One fresh container, then `PermanentException(Timeout)` |
| `ERROR` | See [Container errors](#container-errors) |

What a `Pending` means for your code:

- With `->post()`, nothing to do: the job dispatches a delayed continuation for each wait and ends with
  `PostPublished` or `PostFailed`. No worker sleeps on a container.
- With `->postNow()`, the waits run in-process while they fit `social.sync.max_wait` (120 s). A text
  or image post usually fits: create, a status read about 30 s later, then the publish. Past that limit the
  publish is handed to the queue and `postNow()` returns a success with state `processing`, a null
  id and payload `handed_off = true`; the final outcome arrives as `PostPublished` / `PostFailed`.
  On the `sync` queue connection there is no queue to hand over to, so it fails with a timeout
  instead.

How long it takes: a text or image post needs about 30 s (the first status read) plus queue latency.
Videos and carousels with videos take as long as Threads' processing; Meta advises waiting about
30 s before publishing and reading the status about once a minute for up to 5 minutes
([Meta: troubleshooting](https://developers.facebook.com/docs/threads/troubleshooting)). The driver
declares no deadline, so the whole chain must finish within `social.async.max_wait` (3600 s), or
`social.async.max_wait_per_platform.threads` when set. Past the limit:

- before any `threads_publish` was sent: `PostFailed` with `Timeout`;
- after a `threads_publish` whose answer was lost (a committed wait): `PostFailed` with
  `ProcessingTimeout` and `possiblyPublished = true`.

On the queue, every wait is at least 5 s, whatever the configured interval.

Timeouts: container creates, status reads and lookups use `api.threads.timeout`
(`social.http_timeout`, 60 s). `threads_publish`, for posts and replies, uses
`api.threads.publish_timeout` (`social.publish_timeout`, 120 s). The connect timeout is
`api.threads.connect_timeout` (10 s), pooled carousel requests included.

---

## Error handling

Threads errors use Meta's shape, `{error: {message, type, code, error_subcode, error_user_msg,
is_transient, fbtrace_id}}`. The exception message is `error_user_msg` when present, else `message`;
string matches always run on the raw `message` or the raw body, never on the localised user message.
The context holds `code`, `subcode`, `type`, `is_transient`, `error_user_title`, `error_user_msg`,
`fbtrace_id` (also as `trace_id`), `raw_message`, `error` and `http_status`. Checks run in this order:

| Threads answer | Exception | `FailureReason` | Retry / action |
|---|---|---|---|
| Subcode 2207003 or 4279004 (media still downloading, carousel item not ready) | Temporary | `ServerError` | After 60 s |
| Subcode 4279009, code 24 "Media Not Found" (container published before it is ready) | Temporary | `ServerError` | After 30 s. An unknown `creation_id` or `reply_to_id` fails the same way; `social.tries` bounds it |
| `THREADS_API__LINK_LIMIT_EXCEEDED` anywhere in the body | Permanent | `InvalidContent` | Context `link_limit_exceeded = true`. Remove links |
| Raw message contains "unexpected error has occurred" | Temporary | `ServerError` | After 90 s |
| Codes 102/190, subcodes 458/459/460/463/464/467/492, "Error validating access token", "has not authorized application" | Permanent | `InvalidToken` (459/464: `AccountRestricted`; 492: `InsufficientPermissions`) | Reconnect. Context `terminal` is true for 458/460/463/467/492. A non-terminal one (`reresolvable`) is retried once with re-resolved credentials when a custom `CredentialsResolver` is bound |
| Codes 4/17/32/341/613, 80000-80014, HTTP 429, or "rate limit" | Temporary | `RateLimited` | After `estimated_time_to_regain_access` from `X-Business-Use-Case-Usage` / `X-App-Usage`, else `Retry-After`, else 900 s (4/17/613/800xx) or 300 s |
| `is_transient`, codes 1/2/-1/-2, any 5xx (JSON or not) | Temporary | `ServerError` | After 60 s |
| Codes 3/10/200-299, or 100 with subcode 33 | Permanent | `InsufficientPermissions` | Reconnect with the missing permission. The message names `threads_basic` + `threads_content_publish` (posts) and `threads_manage_replies` (replies); a location-tagged post names `threads_location_tagging` |
| Code 368 | Permanent | `AccountRestricted` | Context `retry_later = true` |
| Code 506 | Permanent | `DuplicateContent` | — |
| Anything else | Base table | 401 `InvalidToken`, 403 `InsufficientPermissions`, 404 `NotFound` (Permanent); 408/425 `ServerError` (Temporary); other `Unknown` (Permanent), e.g. code 100 for an invalid parameter | — |

A Temporary failure is retried by the job up to `social.tries` (5) per step, waiting the delay above
(at most 3600 s) or `social.backoff`. A Permanent failure ends the publish with `PostFailed`.
`requiresReconnect()` is true for `InvalidToken` and `InsufficientPermissions`, and the job then also
fires `CredentialsRejected`.

### Container errors

A container that fails to process reports `status=ERROR` and an `error_message`:

| `error_message` | Action |
|---|---|
| `FAILED_DOWNLOADING_VIDEO`, `UNKNOWN`, or empty | A fresh container: the single container, the failed carousel item only, or the whole carousel when the parent failed (or the item cannot be matched to its media). At most 2 such restarts per publish, then `PermanentException(MediaRejected)` "could not process ... after N attempts" |
| `FAILED_PROCESSING_AUDIO`, `FAILED_PROCESSING_VIDEO`, `INVALID_ASPEC_RATIO` / `INVALID_ASPECT_RATIO`, `INVALID_BIT_RATE`, `INVALID_DURATION`, `INVALID_FRAME_RATE`, `INVALID_AUDIO_CHANNELS`, `INVALID_AUDIO_CHANNEL_LAYOUT`, any other | `PermanentException(MediaRejected)` with a readable reason and the code, e.g. "the video could not be processed; use H.264 or HEVC in an MP4 or MOV file. (FAILED_PROCESSING_VIDEO)" |

The context holds `container_id`, `container_status`, `error_message` and `restarts`. An `EXPIRED`
container (item or post) is re-created once, then fails as `PermanentException(Timeout)` with
`expirations`. Restarting is always safe: an unpublished container is never shown.

### Other failures raised by the driver

| Situation | Exception / reason |
|---|---|
| No `account_id` or `access_token` | Permanent `Misconfigured` (`missing_credentials`) |
| A document in the post | Permanent `MediaRejected`, before any request |
| A local file with no gateway that can publish it | Permanent `MediaRejected` (also a validation error) |
| 2xx without a container id (post, item, parent, reply) | Temporary `ServerError` (safe to retry) |
| 2xx `threads_publish` without an id | The container status is read again (up to 3 rounds); then Permanent `OutcomeUnknown` with `possibly_published = true` |
| Quota pre-flight finds the quota used up | Permanent `RateLimited` with `retry_at` and `retry_after_hint` (see below) |
| A pooled carousel item create lost its connection | Temporary `Network` (`TemporaryException::connection()`) |
| A continuation without a container id, carousel items or a known phase | Permanent `Unknown` |
| Connection lost on any other call | Handled by the job as `Network` (see below for `threads_publish`) |

Every failed response is logged (`[SocialPoster] threads API error: ...`) with tokens redacted. Turn
it off with `social.error_logging`, or route it with `social.error_log_channel`.

### Publishing quota

Threads allows 250 published posts and 1,000 replies per profile in a rolling 24 hours; a carousel
counts as one post ([Meta: overview](https://developers.facebook.com/docs/threads/overview)).

`ThreadsDriver::publishingQuota(PreparedPost $post)` reads
`GET {account}/threads_publishing_limit?fields=quota_usage,config,reply_quota_usage,reply_config` and
returns `quota_usage`, `quota_total`, `quota_duration`, `reply_quota_usage`, `reply_quota_total`,
`reply_quota_duration` (each `?int`) and `raw`. A refused read throws the mapped exception.

```php
use SocialPoster\Enums\Platform;
use SocialPoster\SocialManager;
use SocialPoster\ValueObjects\{Credentials, PreparedPost, SocialPost};

$quota = app(SocialManager::class)->platform('threads')->publishingQuota(new PreparedPost(
    Platform::Threads,
    new SocialPost(),
    Credentials::make(['account_id' => $threadsUserId, 'access_token' => $token]),
));
```

With `api.threads.check_quota = true`, `publish()` reads the quota first. When `quota_usage` has
reached `quota_total`, the post fails before any container is created, as
`PermanentException(RateLimited)` with context `quota_usage`, `quota_total`, `quota_duration`,
`retry_after_hint` (`api.threads.quota_retry_after`, 3600 s, at least 60) and `retry_at` (ISO-8601
UTC, now plus the hint). The window is rolling, so the hint is an estimate. Re-dispatch after
`retry_at`; the job never waits for the reset. A quota read that fails never blocks the post. Without
the pre-flight, the error `threads_publish` returns once the quota is used up has no mapping of its
own and goes through the table above (its body is an open live check).

---

## Duplicate safety

Creating containers is harmless: a container that is never published is never shown, and expires.
The only call that can publish twice is `threads_publish`, run in the `publish` commit phase. Threads
reports a published container as `PUBLISHED`, so the driver reads the container's status before it
would publish again.

| Situation | What the driver does |
|---|---|
| Connection lost after `threads_publish` may have been sent (read timeout, reset) | Back to `await` as a **committed** wait (`publish_sent`), after 10 s. The status read decides: `PUBLISHED` → recover; `FINISHED` → publish |
| `threads_publish` never left the client (DNS, refused, TLS, connect timeout) | The `ConnectionException` bubbles; the job retries the phase as a clean try and the chain is not marked committed |
| `threads_publish` answered 2xx without an id | Committed `await`, up to 3 rounds (`verify_rounds`); then `OutcomeUnknown` with `possibly_published = true` |
| An unclear refusal of `threads_publish`: a 5xx, `is_transient`, or code 1/2/-1/-2 without a subcode, at any HTTP status | Back to `await` as a committed wait (`publish_sent`), as after a lost answer. The status read decides before anything is published again |
| A worker that died or timed out, a queue redelivery, a store record still marked committing | The job re-runs the phase with `_recovering` set. The driver reads the status first: `PUBLISHED` → recover; `FINISHED` → publish; `IN_PROGRESS` or unknown → wait |
| A clear refusal: 4279009 and other subcodes, throttles, token or permission errors, other 4xx answers | Retried or failed as they are. A retry runs `threads_publish` again without reading the status first |

**Recovery of a `PUBLISHED` container.** The driver looks the post up instead of publishing again:
`GET {account}/threads?fields=id,text,timestamp,permalink&limit=25`, and first
`GET {account}/replies` with the same fields when the post was a reply (`replyToId` or
`reply_to_id`). A post matches when its text equals the submitted text (trimmed, CRLF read as LF) and
its `timestamp` is no older than the container's creation minus 300 s. The first match wins.

- Found: `Published` with the real id and permalink, payload `recovered = true`.
- A lookup that failed temporarily (throttle, 5xx, timeout) or found nothing yet is tried again on a
  later run, as a committed wait, up to `api.threads.recovery_lookups` (3) lookups in all. The wait
  is `poll_interval` (10 s), or the failure's retry delay when longer, capped at 60 s.
- A refused lookup (a permanent error, such as a missing permission) is not tried again.
- A post without text (an image, video or carousel without caption) cannot be told apart, so it is
  reported at once.
- Not found in the end: `Published` with a **null id and url**, payload `recovered = true`, plus
  `lookup_failed = true` when a lookup failed. A live post is never reported as failed.

**First comment.** A failed reply `threads_publish` would make the comment job create and publish a
new container. When the outcome is unclear (connection lost after sending, 5xx, `is_transient` or
codes 1/2 without a subcode), the driver first re-reads the reply container (`reply_status_polls`
times, `reply_status_interval` s apart):

- `PUBLISHED`: success with a null comment id, payload `container_id`, `recovered = true`; the job
  records it and fires `CommentPublished`;
- `FINISHED`, `EXPIRED` or `ERROR` (never `PUBLISHED`): not published, the original error is
  rethrown with `possibly_published = false` and the job retries it as a clean attempt (not a
  recovery);
- never readable: `PermanentException(OutcomeUnknown)` with `possibly_published = true` and
  `container_id`, reported as `CommentFailed`; the reply is not posted a second time.

That check works within one run. When the run itself was lost (a worker killed during
`threads_publish`, or an answer lost in a way the driver could not check), the comment job's next
attempt is a recovery (`_recovering` in the parent payload). Before it creates a new container, the
driver reads the account's newest replies (`GET {account}/replies?fields=id,text,timestamp,permalink,replied_to,root_post&limit=50`)
and returns its own reply to this post with the same text (`replied_to` must be the post: a reused
comment text under another post does not count), payload `recovered = true`. Nothing found: the reply
is posted. The replies cannot be read: a temporary error is retried; a refusal (no
`threads_read_replies`, a rejected token) fails as `PermanentException(OutcomeUnknown)` with
`possibly_published = true`, `recovering = true` and `lookup_reason`, so the reply is never posted
twice. When the credentials list their `scopes` without `threads_read_replies`, the same failure comes
without the request (and without its error log line), with `lookup_skipped = true`.

A reply request that never left the client is retried directly. A reply `threads_publish` answered
2xx without an id fails as `PermanentException(OutcomeUnknown)` with `possibly_published = true` and
is never retried. The comment job also records the reply under `{key}:comment` and skips a comment
the store already holds.

What this means for your code:

- `PostPublished` can carry a `PostResult` with `platformPostId === null` and
  `payload['recovered'] === true`: the post is live, its id is unknown. Check before storing the id.
  It gets no first comment (`no_post_id`).
- `PostFailed` with `possiblyPublished === true` (`OutcomeUnknown`, or `ProcessingTimeout` after a
  lost publish answer) means: check the account before posting again.
- Keep an idempotency store bound (the default `CacheIdempotencyStore`, or
  `DatabaseIdempotencyStore`) and give the publish your own `->idempotencyKey()`, so a repeated
  dispatch replays the recorded result. The account identity in the key is `account_id`.

---

## Configuration (`social.api.threads`)

| Key | Default | Effect |
|---|---|---|
| `graph_version` | `v1.0` (env `SOCIAL_THREADS_GRAPH_VERSION`) | Graph API version in the base URL |
| `timeout` | `social.http_timeout` (60) | Read timeout (s) of creates, status reads, lookups, quota reads |
| `connect_timeout` | `10` | Connect timeout (s) |
| `publish_timeout` | `social.publish_timeout` (120) | Read timeout (s) of `threads_publish` (posts and replies) |
| `initial_check_after` | `30` | Seconds before the first status read of a single container (also after an item is re-created) |
| `poll_interval` | `10` | Status reads of text, image and carousel containers; recovery lookups |
| `video_poll_interval` | `30` | Status reads of video containers, and of carousel items while a video item is pending |
| `missing_status_polls` | `12` | Consecutive reads without a status before publishing (or creating the parent) anyway |
| `recovery_lookups` | `3` | Lookups of a `PUBLISHED` post before it is reported with a null id |
| `pool_children` | `true` | Create carousel items concurrently through `Http::pool`. Sequential when `false`, when an authorizer is configured, or for a single item |
| `pool_concurrency` | `10` | Concurrent item creates |
| `max_characters` | `500` | Caption limit (`ThreadsCounter`) |
| `max_links` | `5` | Unique links per post and per reply |
| `max_alt_text` | `1000` | Alt text limit (characters) |
| `max_carousel_items` | `20` | Carousel size limit |
| `max_image_bytes` | `8388608` | Image size limit |
| `max_video_bytes` | `1073741824` | Video size limit |
| `min_video_duration` | `1` | Seconds |
| `max_video_duration` | `300` | Seconds |
| `max_video_width` | `1920` | Pixels (display width) |
| `min_video_frame_rate` | `23` | fps |
| `max_video_frame_rate` | `60` | fps |
| `max_comment_characters` | `500` | Reply (first comment) limit |
| `comment_unknown_scopes` | `'skip'` | `'skip'` or `'attempt'` a reply when the credentials have no `scopes` |
| `reply_status_polls` | `5` | Status reads of a reply container (before and after its publish) |
| `reply_status_interval` | `2` | Seconds between those reads |
| `check_quota` | `false` | Read `threads_publishing_limit` before each publish and refuse when the quota is used up |
| `quota_retry_after` | `3600` | `retry_after_hint` (s) of that refusal, at least 60 |

Related global keys: `social.http_timeout` (60), `social.publish_timeout` (120),
`social.async.max_wait` (3600) and `social.async.max_wait_per_platform.threads`,
`social.sync.max_wait` (120), `social.comment_delay` (60), `social.comment_tries` (5),
`social.tries` (5) and `social.backoff`, `social.gateway`, `social.credentials_resolver`,
`social.authorizers.threads`, `social.idempotency`, `social.error_logging` and
`social.error_log_channel`.

---

## Extending the driver and v1.0 continuations

- `startSingle(PreparedPost $post, ?Media $item): PublishOutcome` and
  `startCarousel(PreparedPost $post): PublishOutcome` are still the hooks that create containers.
  `publish()` and every restart call them, so a subclass override applies to retries too. The
  restart counters are laid over the state an override returns.
- `publishNow(PreparedPost $post, array $state)` is the commit. `publishContainer()` is deprecated
  and no longer called; override `publishNow()` instead.
- `classifyError($response)` keeps its v1.0 signature.
- Continuations queued by v1.0 still resume: `await_publish` runs as `await` (the status is read before
  publishing), the `container` key is read as `container_id`, and an `await_children` state that
  tracked only its videos now polls every item.

---

## Known limitations

- Recovery matches on text and time. A post without text cannot be identified, and two posts with the
  same text published by the account within the window can be confused. Only the latest 25 posts
  (and replies) are read.
- A first-comment recovery reads the account's 50 newest replies and needs `threads_read_replies`;
  without it, a recovery fails as `OutcomeUnknown` (`possiblyPublished`) rather than reply blind.
- A recovered post or reply has no id: no URL, and no first comment on a recovered post.
- The driver relies on Threads publishing a container at most once and marking it `PUBLISHED`. The
  error a second `threads_publish` of a live container returns is not mapped specifically (open live
  check 8). After a lost answer, an unclear refusal (5xx, transient code) or a redelivery the status
  is read first, and such a failure is reported with `possiblyPublished`. After a clear refusal (a
  subcode, a throttle, a token or permission error) the retry publishes without a status read, and a
  failure is then reported without it.
- No scheduling and no drafts; `auto_publish_text` is always removed.
- Video covers are not supported (`thumbnail_warning`).
- Audio and bit rate are not validated; dimension, duration, codec and frame-rate checks need
  metadata from the inspector or `Media` meta.
- The 250-post and 1,000-reply quota errors from Threads have no dedicated mapping. Use
  `check_quota`, whose `retry_at` is an estimate.
- `expires_at` is not checked and tokens are never refreshed.
- `extra` is sent as given. A raw `link_attachment` or `poll_attachment` on a media post is caught
  only by validation.
- Request bodies are JSON with `access_token` as a body field; the poll object and the
  comma-separated country list follow Meta's examples but are not live-verified (see below).

---

## Open live checks

These behaviours are covered by fixtures but not yet confirmed against the live API. Run
`tests/Live/ThreadsLiveTest.php` with `SOCIAL_LIVE_THREADS_ACCOUNT_ID` (the numeric Threads user id)
and `SOCIAL_LIVE_THREADS_TOKEN`, plus `SOCIAL_LIVE_PUBLISH=1` for the check that posts and then
deletes. See `tests/Live/README.md`.

1. `Authorization: Bearer` on GETs: profile, `GET {account}/threads`, `threads_publishing_limit`,
   container status and permalink. Automated. If Bearer were refused, GETs would need `access_token`
   as a query parameter.
2. POSTs with both the Bearer header and an `access_token` JSON body field, for creates,
   `threads_publish` and pooled carousel items. The text publish covers creates and `threads_publish`.
3. `poll_attachment` as a nested JSON object, and Threads' own per-option limit (the driver enforces
   25 characters).
4. `allowlisted_country_codes` as `US,CA` or as an array.
5. Where `is_spoiler_media` belongs on a carousel (parent or items), and `topic_tag` without `#`.
6. The new post appears at once in `GET {account}/threads`, with its text equal to the submitted text
   after trim (emoji and newlines included) and an ISO-8601 `+0000` timestamp. Automated.
7. The id `threads_publish` returns is the one permalink lookups accept (automated) and `reply_to_id`
   accepts (manual, with a first comment).
8. The error `threads_publish` returns for a container that is already `PUBLISHED`.
9. The body shape of `THREADS_API__LINK_LIMIT_EXCEEDED` (post 6 distinct links).
10. Whether the 1920 px limit applies to the display (rotated) width or the coded width.
11. Whether TEXT reply containers ever report `IN_PROGRESS` or no status, and whether 4279009 on a
    reply publish clears on retry.
12. The error code and body for the 250-post and 1,000-reply quotas.
13. Whether 10 concurrent carousel-item creates through `Http::pool` are throttled.
14. The first-comment recovery read: that `GET {account}/replies` lists a reply right after its
    `threads_publish`, with `replied_to.id` set to the post, and which permission it needs
    (`threads_read_replies`).
