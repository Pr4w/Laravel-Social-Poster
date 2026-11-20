# Pinterest

| | |
|---|---|
| Platform key | `'pinterest'` / `Platform::Pinterest` |
| Driver | `SocialPoster\Platforms\Pinterest\PinterestDriver` (no comment interface) |
| API | Pinterest API v5: `https://api.pinterest.com/v5` (`api.pinterest.base_url`), or the Sandbox `https://api-sandbox.pinterest.com/v5` (`api.pinterest.sandbox_url`). Video bytes go to the S3 `upload_url` that `POST /media` returns |
| Target | One board (optionally one board section) per connection |
| Auth | OAuth 2.0 user access token, sent as `Authorization: Bearer <token>`, or your own request authorizer |
| Media transfer | Images: Pinterest fetches a URL, or the driver sends the bytes as base64. Video: always uploaded by the driver |
| Options class | `SocialPoster\Platforms\Pinterest\PinterestOptions` (with the `PinterestImageTransfer` enum), or `RawOptions(Platform::Pinterest, [...])` for `extra` only |
| First comment | No. Pinterest v5 has no endpoint to create a comment |
| Async | Images and carousels: one create step, no polling. Video: register, S3 upload, processing polls, then the create |

Settings are read from `config('social.api.pinterest.*')`. The package's `config/social.php` is merged
into your app's config at boot, so the shipped values apply unless you override them. A key that is
set nowhere falls back to the driver default shown on this page. The code in
`src/Platforms/Pinterest/` is the reference.

---

## What you can publish

A Pin is exactly one of the following. Images and a video can't share a Pin.

| Pin | Media | Request | Notes |
|---|---|---|---|
| Image | 1 image (`jpg`, `jpeg`, `png`, `webp`) | `POST /pins`, `media_source.source_type` `image_url` or `image_base64` | WebP only by URL |
| Carousel | 2 to 5 images | `POST /pins`, `multiple_image_urls` or `multiple_image_base64` | Never mixed: one image without a URL sends them all as base64 |
| Video | 1 video (`mp4`, `mov`, `m4v`) | `POST /media`, S3 upload, `GET /media/{id}` polls, then `POST /pins` with `video_id` | Always uploaded. One cover field is always sent |

The title (`->title()`) and the description (`->caption()`) are both optional. Pinterest has no
stories, reels, shorts, drafts or scheduled Pins in this driver: every Pin is published at once, and
`PostResult::$state` is `published`. Each publish targets one board. To save the same Pin to several
boards, dispatch it once per board.

### Image

```php
use SocialPoster\Facades\SocialPoster;
use SocialPoster\ValueObjects\Media;

SocialPoster::on('pinterest')
    ->using(['access_token' => $token, 'board_id' => $boardId])
    ->title('Spring collection')
    ->caption('New spring collection is live: https://example.com/spring.')
    ->media(Media::image('https://cdn.example.com/spring.jpg', altText: 'Three jackets on a rail'))
    ->post();
```

The Pin link becomes `https://example.com/spring`: without `PinterestOptions::$link`, the driver
takes the caption's first `http(s)` URL, with trailing `.,;:!?'"` removed (and one closing `)` when
the URL has more `)` than `(`). The caption, trimmed, is sent as the description, URL included.

A local file goes as base64 (JPEG or PNG only):

```php
SocialPoster::on('pinterest')
    ->using($credentials)
    ->caption('Printable spring poster.')
    ->media(Media::image('/var/app/storage/pins/poster.png'))
    ->post();
```

### Carousel (2 to 5 images)

```php
use SocialPoster\Platforms\Pinterest\PinterestOptions;

SocialPoster::on('pinterest')
    ->using($credentials)
    ->title('Spring lookbook')
    ->caption('Three outfits for April.')
    ->media(
        Media::image('https://cdn.example.com/look-1.jpg', altText: 'Linen shirt and chinos'),
        Media::image('https://cdn.example.com/look-2.jpg'),
        Media::image('https://cdn.example.com/look-3.png'),
    )
    ->withOptions(new PinterestOptions(
        link: 'https://example.com/lookbook',
        carouselItems: [1 => ['title' => 'Look 2', 'link' => 'https://example.com/lookbook#2']],
    ))
    ->post();
```

Every item repeats the Pin link unless `carouselItems` overrides it. The Pin's `alt_text` is the
first image's alt text (or `PinterestOptions::$altText`); the driver sends no per-item alt text.

All images must share one width/height ratio: Pinterest refuses the carousel otherwise ("Images must
have the same width/height ratios."). When their dimensions are known (the inspector, or
`Media::$meta`), `validate()` refuses images whose ratio differs from the first known one by more than
1% (`PinterestDriver::CAROUSEL_RATIO_TOLERANCE`), under `media.{index}`. Unknown dimensions are left to
Pinterest, whose refusal is a `MediaRejected` failure.

### Video

```php
SocialPoster::on('pinterest')
    ->using($credentials)
    ->title('How we pack an order')
    ->caption('Behind the scenes at the warehouse.')
    ->media(Media::video(
        '/var/app/storage/clips/packing.mp4',
        thumbnail: 'https://cdn.example.com/packing-cover.jpg',
        altText: 'Hands folding a jacket into a box',
    ))
    ->post();
```

The cover is, in order: `PinterestOptions::$thumbnail`; else the video's own `Media::video(thumbnail:)`;
sent as `cover_image_url` when the `MediaGateway` can give a URL, else as `cover_image_content_type` +
`cover_image_data` (JPEG or PNG). When neither can be sent, the driver sends
`cover_image_key_frame_time` (`PinterestOptions::$coverKeyFrameTime`, default `0`). Give a cover image
when you can: see [Error handling](#error-handling) for the 401 that a missing cover is reported to
cause.

---

## Credentials

| Key | Required | Meaning |
|---|---|---|
| `access_token` | yes, unless `social.authorizers.pinterest` is set | OAuth 2.0 user access token. Missing → `PermanentException(InvalidToken)` with `missing_credentials = ['access_token']`, before any request |
| `board_id` | yes | The board's numeric id (`^\d+$`). `account_id` is accepted as an alias when `board_id` is absent. Missing → `PermanentException(Misconfigured)` (`missing_credentials = ['board_id\|account_id']`); not numeric → `Misconfigured` (`credential = board_id`) |
| `board_section_id` | optional | Numeric board section id. `PinterestOptions::$boardSectionId` overrides it per Pin. Not numeric → `Misconfigured` |
| `sandbox` | optional | A boolean (or `"true"`/`"false"`/`"1"`/`"0"`/`"yes"`/`"no"`) that overrides `api.pinterest.sandbox` for this connection |
| `api_base` | optional | Overrides the API root. Accepted only as an `https://` URL on `pinterest.com` or `*.pinterest.com`, else `Misconfigured`, so the token is never sent elsewhere. Wins over `sandbox` |
| `expires_at` | recommended | Token expiry (unix seconds or milliseconds, ISO-8601, or a `DateTimeInterface`). A token that expires within 60 s is refused before any request |
| `scopes` | optional | The granted scopes (a list, or a space- or comma-separated string). When present, `validate()` reports missing ones under `credentials.scopes` |

```php
use SocialPoster\ValueObjects\Credentials;

Credentials::make([
    'access_token' => $token,
    'board_id' => '1234567890123456789',
    'board_section_id' => '9876543210987654321', // optional
    'expires_at' => $expiresAt,
    'scopes' => 'boards:read,boards:write,pins:read,pins:write',
]);

// Or keep the token out of the queue payload. A CredentialsResolver
// (config social.credentials_resolver) returns the real values on every job run:
Credentials::ref('pinterest_board:42', ['board_id' => '1234567890123456789']);
```

`validate()` never requires credentials. When `board_id` (or a numeric-looking `account_id`) or
`board_section_id` is given, it reports a non-numeric value under `credentials.board_id` /
`credentials.board_section_id`.

### Auth model

- **Bearer token** (default): every API call carries `Authorization: Bearer <access_token>`. The S3
  upload never carries it: the driver sends that request with a bare client, authorized by the S3
  policy fields Pinterest returned.
- **Your own authorizer**: set `config('social.authorizers.pinterest')` to a class implementing
  `SocialPoster\Contracts\AuthorizesRequests`. Its `PendingRequest` is used for every Pinterest API
  call, and `access_token` is no longer required.
- **Resolver**: dispatch `Credentials::ref(...)` and bind a `CredentialsResolver`. A missing value
  after resolution is reported as `Misconfigured`, with a hint to bind the resolver.
- The driver never obtains or refreshes tokens. In the production reference app, Pinterest tokens
  last 30 days.

### Host: production or Sandbox

A Pinterest token works only on the host that issued it, and Sandbox boards are separate from
production boards. The host is chosen in this order:

1. credential `api_base` (a `*.pinterest.com` https URL);
2. credential `sandbox`, when it is a boolean;
3. `api.pinterest.sandbox` (env `SOCIAL_PINTEREST_SANDBOX`, default `false`) → `sandbox_url`, else
   `base_url`.

An app with **Trial access** can only publish to the Sandbox. On production it gets `403` with code
`29`, which the driver reports as `InsufficientPermissions` with `trial_access = true` and a message
that points to the Sandbox setting. Publishing to production needs Standard access from Pinterest.
When the Sandbox host was used (and no `api_base`), the result payload has `sandbox = true`.

### Scopes

| Feature | Scopes (all required) |
|---|---|
| `Feature::Publish` (`POST /pins`, and the board lookup) | `boards:read`, `boards:write`, `pins:read`, `pins:write` |
| `Feature::Video` (`POST /media`, `GET /media/{id}`) | `pins:read`, `pins:write` |
| `Feature::Comment` | none (no comment support) |

Secret boards also need `boards:read_secret`, `boards:write_secret`, `pins:read_secret` and
`pins:write_secret`. The driver cannot tell from a board id whether a board is secret, so it never
checks these. `SocialPoster::requiredScopes('pinterest', Feature::Publish)` returns the table above
for your connect flow.

### Idempotency identity

The account key is `board_id`, plus `:board_section_id` when the credential is set (from the
credentials only, not from `PinterestOptions`). A numeric `account_id` counts as `board_id`. Two
boards that share one token therefore never share a key.

---

## Typed options

```php
use SocialPoster\Platforms\Pinterest\{PinterestOptions, PinterestImageTransfer};

new PinterestOptions(
    link: null,
    linkFromCaption: true,
    altText: null,
    boardSectionId: null,
    thumbnail: null,
    coverKeyFrameTime: null,
    imageTransfer: null,
    carouselItems: [],
    adAccountId: null,
    extra: [],
);
```

| Option | Type | Default | Sent as | Validation (error key) |
|---|---|---|---|---|
| `link` | `?string` | `null` | `link`, and each carousel item's `link` | `http://` or `https://` with a host, at most 2048 bytes (`link`) |
| `linkFromCaption` | `bool` | `true` | — | When `link` is blank: `true` uses the caption's first URL as the link. A caption URL over 2048 bytes is a validation error (`link`); at publish time it is dropped |
| `altText` | `?string` | `null` (the first media item's `altText`) | `alt_text` | At most 500 characters, trimmed (`alt_text`). The fallback media alt text is checked under `media.0` |
| `boardSectionId` | `?string` | `null` (credential `board_section_id`) | `board_section_id` | Numeric (`board_section_id`). Also selects the section listing for the duplicate check |
| `thumbnail` | `?Media` | `null` (the video's `Media::video(thumbnail:)`) | `media_source.cover_image_url`, or `cover_image_content_type` + `cover_image_data` | An image with extension `jpg`, `jpeg`, `png` or none (`thumbnail`). Video Pins only (`thumbnail`). Not with `coverKeyFrameTime` |
| `coverKeyFrameTime` | `?int` | `null` (sends `0`) | `media_source.cover_image_key_frame_time` (seconds) | Not negative; video Pins only; not with `thumbnail` (`cover_key_frame_time`) |
| `imageTransfer` | `?PinterestImageTransfer` | `null` (`api.pinterest.image_transfer`, `auto`) | chooses `image_url`/`multiple_image_urls` or `image_base64`/`multiple_image_base64` | See below |
| `carouselItems` | `array<int, array{title?: string, description?: string, link?: string}>` | `[]` | `media_source.items[i].title` / `description` / `link` | Keys 0 to 4, below the image count; only `title` (≤ 100), `description` (≤ 800), `link` (as `link` above); carousels only (`carousel_items`, `carousel_items.{i}`, `carousel_items.{i}.{key}`) |
| `adAccountId` | `?string` | `null` | `?ad_account_id=` on `POST /pins` and on the board lookup | Numeric (`ad_account_id`). Business Access: the Pin is created as the owner of that ad account |
| `extra` | `array` | `[]` | merged into the `POST /pins` body | Not validated |

Blank strings count as unset. Options bound to another platform are ignored.

**`PinterestImageTransfer`** (images and carousels; a video is always uploaded):

| Case | Value | Behaviour |
|---|---|---|
| `Auto` | `auto` | A URL when the `MediaGateway` can provide one for every image (remote media, or a gateway that re-hosts local files), else every image as base64. When Pinterest cannot fetch the URL, the create is retried once as base64 (see [Error handling](#error-handling)) |
| `Url` | `url` | Always a URL. A local image needs a gateway that re-hosts it (`FilesystemMediaGateway`); otherwise validation fails under `media.{i}`, and `publish()` fails with `MediaRejected` |
| `Base64` | `base64` | Always the bytes. Pinterest accepts only `image/jpeg` and `image/png` this way, so a WebP is refused (`media.{i}`) |

An unknown `image_transfer` config value falls back to `auto`. The base64 content type is sniffed
from the file's first bytes: a `.png` that is really a WebP or GIF fails with `MediaRejected` instead
of reaching Pinterest. Remote images sent as base64 are downloaded by the gateway first (the default
`LocalMediaGateway` does that); without a gateway that is `Misconfigured`.

### The `extra` passthrough

`extra` (or `RawOptions(Platform::Pinterest, [...])`) adds raw `PinCreate` fields, for example
`dominant_color`, `parent_pin_id`, `note` or `sponsor_id`.

- Fields the driver sets win: `board_id`, and `board_section_id`, `title`, `description`, `link` and
  `alt_text` when the post provides them. A field the post leaves blank can come from `extra`
  (for example `extra['title']` on a post without a title).
- `extra['media_source']` is deep-merged into the computed media source, and the driver's keys win.
  Use it to add fields such as the carousel cover position: `['media_source' => ['index' => 2]]`.

```php
new PinterestOptions(extra: [
    'dominant_color' => '#6E7874',
    'media_source' => ['index' => 1],
]);

use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\RawOptions;

new RawOptions(Platform::Pinterest, ['dominant_color' => '#6E7874']);
```

---

## Limits

### Text

| Field | Limit | Counting rule |
|---|---|---|
| Description (`->caption()`) | 800 | Unicode code points (`mb_strlen`) of the trimmed caption. URLs count at full length, hashtags are plain text. Not required |
| Title (`->title()`) | 100 | Code points of the trimmed title. Not required |
| Alt text | 500 | Code points of the trimmed text |
| Link | 2048 | Bytes (`strlen`) |
| Carousel item title / description | 100 / 800 | Code points of the trimmed text |

Text limits are constants (`PinterestDriver::MAX_TITLE`, `MAX_DESCRIPTION`, `MAX_ALT_TEXT`,
`MAX_LINK`) and cannot be changed in config. The description is the trimmed caption, sent verbatim.
Pinterest has no mentions, facets or link cards: the clickable destination is the `link` field.

### Media

| | Limit | Config key |
|---|---|---|
| Media per Pin | 1 image, 2 to 5 images, or 1 video | — |
| Image types | `jpg`, `jpeg`, `png`, `webp` (by extension). WebP only by URL. No GIF | — |
| Image size | 20 MB (20971520 bytes) per image | `image_max_bytes` |
| Video types | `mp4`, `mov`, `m4v` (by extension); MIME `video/mp4`, `video/quicktime`, `video/x-m4v` when the inspector reports one | — |
| Video size | 2 GB (2147483648 bytes) | `video_max_bytes` |
| Video duration | 4 s to 900 s | `video_min_duration`, `video_max_duration` |
| Cover image | JPEG or PNG | — |
| Base64 fallback | at most 20 MB of images in total, for the `Auto` retry only | `base64_max_bytes` |

- The extension is read from the URL or file path (the query string is ignored). A source without a
  recognised extension fails validation.
- Size, duration and MIME checks run on the `MediaInspector`'s metadata (ffprobe by default) or on
  `Media::$meta`. When neither knows the file, only the extension and count are checked.
- The driver sets no dimension, aspect-ratio or frame-rate rules, and converts nothing (a WebP that
  has to go as base64 must be converted by you).
- Pinterest's help pages state a 5-minute maximum for organic video, while 900 s is the production
  reference's value and is unconfirmed (live check 12). Lower `video_max_duration` if your videos
  are refused.
- `base64_max_bytes` caps only the `Auto` fallback. Forced `Base64` mode, or `Auto` with a local file,
  is not capped beyond the per-image limit.

---

## First comment

Pinterest's v5 API has no endpoint for creating a comment, and the driver implements neither
`SupportsFirstComment` nor `SupportsComments`.

- `->withComment('...')` on a Pinterest post is not validated, and once the Pin is live the job
  dispatches `CommentSkipped` with reason `unsupported`. `postNow()` does the same.
- In a fan-out, the other platforms still get their comment.

---

## Async behaviour

A publish is a small state machine. Each step is one job run, and the job carries the state from one
run to the next. `POST /pins` is the only call that creates something, and it always runs in its own
**commit phase** (see [Duplicate safety](#duplicate-safety)).

| Phase (`state['phase']`) | What happens | Next |
|---|---|---|
| `publish()` | Image or carousel: nothing is sent; the transfer mode (`url` or `base64`) is chosen and checked. Video: `POST /media {"media_type":"video"}`, then the S3 upload in the same run (every `upload_parameters` entry as a form field, the file last and streamed) | `pin` (commit) or `await_media` |
| `await_media` | `GET /media/{media_id}`. `succeeded` → the create. `failed` → `MediaRejected`. `registered`, `processing` or anything else → wait. 429 or 5xx → wait, without failing | `await_media`, or `pin` |
| `pin` | When the job flags `_recovering`: the board lookup first. Then `POST /pins` | done, or `verify_pin` |
| `verify_pin` | The board lookup; adopt the Pin if found, else `POST /pins` | done, or `verify_pin` again |

What each `Pending` means:

- **Create step** (`pin`): a commit `Pending` with recheck 0. On the queue the next run is dispatched
  without delay; `postNow()` runs it at once. For an image the state is `phase`, `kind`, `transfer`
  and `since` (the time of the first attempt). Base64 data is never stored in the state.
- **Processing** (`await_media`): recheck 10 s for the first six polls, then 30 s. A 429 or 5xx on the
  status call is not a failure: it waits at least 60 s (longer when `Retry-After` asks, at most
  `social.max_recheck_after`, 900 s) and counts as a poll. `PostProcessing` fires on every wait. The state
  carries `media_id`, `polls`, `media_status` and the deadline.
- **Verify step** (`verify_pin`): a commit `Pending` with recheck `verify_after` (60 s).

Deadlines (the job applies the last one a step declared):

- The upload run declares `upload end + video_max_wait` (1800 s). When processing is still not done
  then, the publish fails with `Timeout` (raised by the job) or `ProcessingTimeout` (raised by the
  driver when the run starts after the deadline). Neither is possibly published: no Pin exists.
- The create step after a processed video, and every `verify_pin` step, declare their own deadline:
  `(verify_retries + 1) × (verify_after + pin_timeout) + 300` s from that step, 1020 s with the
  defaults. A video processed on the last look is still pinned, and a verify is never cut short into
  a clean-looking timeout. These chains are bounded by `verify_retries`.
- An image create step declares none, so `social.async.max_wait` (3600 s, or
  `social.async.max_wait_per_platform.pinterest`) applies until a verify step declares one.

How long it takes:

| Pin | Runs |
|---|---|
| Image, carousel | 2: `publish()`, then the create right after. Pinterest itself gives up a create after about 7 s (community reports); the driver waits up to `pin_timeout` (60 s) |
| Video | `publish()` with the register call and the whole S3 upload (up to `upload_timeout`), then processing polls, then the create |

Pinterest does not document its video processing time. The driver waits up to `video_max_wait`
(1800 s); the production reference app waited at most 10 minutes. With a verify after an unanswered
create, a video chain can run for about an hour in the worst case, so the token should stay valid
that long, or bind a `CredentialsResolver` (the job re-resolves credentials on every run). A token
whose `expires_at` falls within 60 s is refused at the start of every step.

**The video upload is one request inside one job run.** A remote video is first downloaded by the
gateway (within `social.download_timeout`, 120 s), then streamed to S3 within
`api.pinterest.upload_timeout` (600 s in the shipped config). When `social.job_timeout` is null, the
job derives its timeout from that value: 960 s for Pinterest with the shipped config. The queue
connection's `retry_after` must be longer than the job timeout, or a second worker may pick up a run
that is still uploading (the package logs a warning at boot). When `social.job_timeout` is set, the
S3 timeout is capped at `job_timeout - 60` (never below 30 s). Pass a local path for large videos.

`postNow()` runs the upload in the request, then polls for up to `social.sync.max_wait` (120 s) and
hands the publish to the queue: the result is a success with state `processing`, and the final
outcome arrives as `PostPublished` or `PostFailed`. On the `sync` queue connection there is no queue
to hand over to, so the publish times out instead.

Timeouts: `POST /pins` uses `pin_timeout` (60 s, at least 10). The token probe after a video 401 uses
30 s. The S3 upload uses `upload_timeout`. Every other call uses `timeout` (`social.http_timeout`,
60 s). The connect timeout is `connect_timeout` (10 s), S3 included.

### Results

| Field | Value |
|---|---|
| `platformPostId` | The Pin id |
| `url` | `https://www.pinterest.com/pin/{id}/` for a numeric id, else null. Built for Sandbox Pins too, although they are not visible on pinterest.com |
| `idKind` | `pin_id` |
| `state` | `published` |
| `payload` | `board_id`, `board_section_id`, `kind` (`image`, `carousel`, `video`), `media_id` (video), `link`, `transfer` (`url`, `base64` or `upload`), `sandbox` (true on the Sandbox host), `response` (Pinterest's create body), `cover` (video: `url`, `base64` or `key_frame`), `fell_back` (true after the base64 retry), `recovered` (true when an earlier attempt's Pin was adopted) |

---

## Error handling

Pinterest errors are a top-level `{"code": int, "message": string}` (`error.message`, `error` and
`error_description` are read too). S3 answers the upload in XML. The exception context holds
`status`, `http_status`, `step` (`pin`, `media_register`, `media_status`, `upload`, `lookup` or
`video_cover`), `pinterest_code` and the error identifiers. The checks run in this order:

| Pinterest answer | Exception | `FailureReason` | Action |
|---|---|---|---|
| 401 on a video `POST /pins`, while `GET /media/{id}` still answers 200 | Permanent | `MediaRejected` | Context `cover_required`. Give the video a cover image and post again |
| 401 | Permanent | `InvalidToken` | Reconnect. With a `CredentialsResolver` bound: one Temporary retry with re-resolved credentials (`reresolve`) |
| 403 with code 29 | Permanent | `InsufficientPermissions` | Trial access: use the Sandbox (`api.pinterest.sandbox` or the `sandbox` credential) with a Sandbox token, or get Standard access. Context `trial_access` |
| 404 on `GET /media/{id}` | Permanent | `MediaRejected` | Context `upload_not_found`. Publish again |
| 404 on the create or the lookup, or a message containing "board not found" / "section not found" | Permanent | `NotFound` | The board (or section) is gone or not writable. Context `target_not_found` |
| A message containing "not permitted" | Permanent | `InsufficientPermissions` | The account cannot save to that board |
| 429 | Temporary | `RateLimited` | Retried after `Retry-After`, else 300 s |
| 5xx | Temporary | `ServerError` | Retried after `max(60, Retry-After)` s. On the create, the retry looks the board up first |
| A message about the images' "same width/height ratio" (a carousel of mixed shapes) | Permanent | `MediaRejected` | Context `carousel_ratio_mismatch`. Crop the images to one shape |
| A message containing "could not fetch", "couldn't fetch" or "unable to reach" | Temporary | `Timeout` | Retried after 120 s. Context `fetch_failed`. In `Auto` mode the base64 retry runs first |
| 403 on the create, message about a permission, scope or authorization | Permanent | `InsufficientPermissions` | Check the token's scopes and the board |
| any other 403 on the create | Permanent | `MediaRejected` | Pinterest documents it as "image too small, too large or broken" |
| anything else | base table | 403 `InsufficientPermissions`; 404 `NotFound`; 408/425 `ServerError` (Temporary); any other status `Unknown`, with Pinterest's message | — |

The 429 and 5xx rows, and the status poll's own 429/5xx wait (see
[Async behaviour](#async-behaviour)), read only `Retry-After`. `x-ratelimit-reset`, which Pinterest
sends on ordinary responses, counts down a quota window (often 1 s) and is not used as a wait there.
The base table's 408/425 rows still read it. Pinterest has no daily-quota mapping in this driver: every 429 is retried, and no `retry_at`
is set. The job caps any single wait at 3600 s and stops after `social.tries` (5) attempts.

**Auto base64 retry.** In `Auto` mode, a URL create that Pinterest could not fetch is retried once
as base64, when every image is a JPEG or PNG by extension, can be read locally (a remote image needs
a gateway), and the images total at most `base64_max_bytes`:

- after a 4xx fetch error ("could not fetch", "couldn't fetch", "unable to reach", or code 2786/2787):
  at once, in the same run;
- after a 5xx (Pinterest's create deadline includes the image fetch, but the Pin may have been saved):
  only after a board lookup `verify_after` seconds later did not find the Pin. If it is found, it is
  adopted (`recovered = true`).

If the base64 create fails too, its own error is mapped. The result payload then has
`fell_back = true` and `transfer = base64`.

**S3 upload errors** (`step = upload`, context `s3_code`):

| S3 answer | Exception | `FailureReason` |
|---|---|---|
| `EntityTooLarge`, `EntityTooSmall`, `MaxPostPreDataLengthExceeded` | Permanent | `MediaRejected` |
| `AccessDenied`, `ExpiredToken`, `RequestTimeout`, `RequestTimeTooSkewed`, `SlowDown`, `InternalError`, `ServiceUnavailable`, `TokenRefreshRequired`, `IncompleteBody`, or HTTP 408, 429, 5xx | Temporary, 120 s | `ServerError`. The retry runs `publish()` again: a new registration (fresh policy) and a new upload |
| anything else | Permanent | `Unknown` |

Other failures raised by the driver:

| Situation | Exception / reason |
|---|---|
| No `access_token` (and no authorizer) | Permanent `InvalidToken` (`missing_credentials`) |
| Credentials dispatched as a reference that resolve without the token or board | Permanent `Misconfigured`, with a resolver hint |
| Missing or non-numeric `board_id`, non-numeric section, foreign `api_base`, an authorizer that is not an `AuthorizesRequests` | Permanent `Misconfigured` |
| Token expires within 60 s (`expires_at`) | Permanent `InvalidToken` (`token_expired`), or Temporary with `reresolve` when a resolver is bound |
| No media, a document, a video with other media, more than 5 images (`withoutValidation()`) | Permanent `MediaRejected`, before any request |
| `Url` mode with a local image; base64 with a non-JPEG/PNG image (extension or sniffed bytes); unreadable file | Permanent `MediaRejected` |
| Base64 or video upload of a remote file without a gateway | Permanent `Misconfigured` |
| `POST /media` answers 2xx without `media_id` or `upload_url` | Temporary `ServerError` (safe to retry) |
| Video processing `failed` | Permanent `MediaRejected`, context `media_id` and Pinterest's `media` body |
| Video still processing past `video_max_wait` | `Timeout` (job) or Permanent `ProcessingTimeout` (driver), not possibly published |
| `POST /pins` answers 2xx without an id | Permanent `OutcomeUnknown`, `possibly_published = true` |
| Unknown phase, or a continuation without its `media_id` | Permanent `Unknown` |
| Connection lost on the register, upload or status call | Handled by the job as `Network` and retried (the upload from a new registration) |
| Connection lost on the create | See [Duplicate safety](#duplicate-safety) |

`FailureReason::category()` groups the reasons for your UI: `retry` (RateLimited, ServerError,
Timeout, ProcessingTimeout, Network), `reconnect` (InvalidToken, InsufficientPermissions; also
`requiresReconnect()`), `user_fix` (MediaRejected and others), `operator` (Misconfigured, Unknown),
`not_found` (NotFound) and `verify` (OutcomeUnknown). An exception flagged `possibly_published`
reports `verify` through `errorSummary()`, whatever its reason. Note that the Trial-access 403 is
`InsufficientPermissions` (category `reconnect`) although the fix is the Sandbox setting: check
`context['trial_access']`.

Every failed response is logged (`[SocialPoster] pinterest API error: ...`) with tokens redacted:
temporary failures at warning level, permanent ones at error level. Turn logging off with
`social.error_logging`, or route it with `social.error_log_channel`.

---

## Duplicate safety

Only `POST /pins` can create a Pin twice. `POST /media` is safe to repeat: an unused registration
creates nothing. Pinterest has no idempotency key, so the driver checks the board before any create
that may repeat an earlier one.

**When the board is checked first.** A `pin` run checks the board when the job flags `_recovering`:
the queue redelivered the run after a worker died, the store still marks the record as committing
(a crashed run, or `reconcile()`), or the previous attempt failed with a 5xx after the request was
sent. Every `verify_pin` run checks it too. Explicit refusals (4xx, 429, token errors) and transport
failures that provably never left (DNS, refused, TLS, connect timeout) are retried without a check.
A create run killed by the job timeout is not retried: it fails as `OutcomeUnknown` with
`possibly_published = true`, and the record is kept for `reconcile()`.

**A create without an answer.** When `POST /pins` gets no response and the request may have left,
the driver does not retry it blindly. It returns `verify_pin` and looks the board up `verify_after`
(60) seconds later, since Pinterest may not list a new Pin at once. This happens at most
`verify_retries` (5) times per publish (`ambiguous_rounds`); past that, the publish fails as
`PermanentException(OutcomeUnknown)` with `possibly_published = true`.

**The lookup.**

- `GET /boards/{board_id}/pins`, or `GET /boards/{board_id}/sections/{section_id}/pins` when a section
  is targeted, with `page_size = verify_page_size` (50, clamped to 1-250) and `ad_account_id` when set.
  One page only.
- Candidates: Pins with `created_at` at or after the first attempt (`since`, else the job's commit
  time) minus 60 s. A Pin whose `created_at` cannot be parsed is kept as a candidate.
- Match: the trimmed title and description (line endings normalised) equal to what the create
  sends, including `extra['title']` / `extra['description']` when the post leaves them blank. The
  link is not compared (Pinterest may normalise it).
- Found → `Published` with that Pin's id, `payload['recovered'] = true`. Not found → `POST /pins` again.
- A Pin with neither title nor description cannot be told apart: when any candidate exists, the
  publish fails as `OutcomeUnknown` with `possibly_published = true` and `candidates` (the count).
  When none exists, it is created.
- The lookup could not be read (connection, 429, 5xx, a body without `items`): the verify runs again
  after `verify_after`, at most `verify_retries` times (`verify_failures`), then `OutcomeUnknown` with
  `possibly_published = true`. A refused lookup (401, 403, 404...) is rethrown with
  `possibly_published = true`.
- A failure before the lookup in a `verify_pin` run (missing or expired token, misconfigured board or
  `api_base`) is also flagged `possibly_published = true`.

**`dedupe_on_timeout = false`.** No lookup ever runs. A create without an answer, and any recovering
`pin` or `verify_pin` run, fails as `OutcomeUnknown` with `possibly_published = true`. The Pin is never
created again blindly.

What this means for your code:

- `PostFailed` with `possiblyPublished === true` (reason `OutcomeUnknown`) means: look at the board
  before posting again. The store keeps the record, and `reconcile()`, given the original post, runs
  the commit step again as a recovery, so the board is looked up first.
- `PostPublished` with `payload['recovered'] === true` is an adopted Pin: the id and url are real.
- The lookup is best effort. A board the user sorted by hand may list the new Pin past the first
  page. A Pin with the same title and description saved to the board in the window is taken for the
  earlier attempt.
- Keep an idempotency store bound (the default `CacheIdempotencyStore`, or
  `DatabaseIdempotencyStore`) and give the publish your own `->idempotencyKey()`. A repeat dispatch
  then replays the recorded result instead of posting again.
- Give Pins a title or description: untitled Pins cannot be recovered when other Pins were saved to
  the board in the meantime.

---

## Configuration (`social.api.pinterest`)

Every key is optional. "Default" is the value in the shipped `config/social.php`; the driver
fallback applies when a key is set nowhere.

| Key | Default | Driver fallback | Effect |
|---|---|---|---|
| `base_url` | `https://api.pinterest.com/v5` | same | Production API root |
| `sandbox` | `false` (env `SOCIAL_PINTEREST_SANDBOX`) | `false` | Send every call to `sandbox_url`. The `sandbox` credential overrides it |
| `sandbox_url` | `https://api-sandbox.pinterest.com/v5` | same | Sandbox API root |
| `image_transfer` | `auto` | `auto` | `auto`, `url` or `base64` (a `PinterestImageTransfer` case is accepted too) |
| `base64_max_bytes` | `20971520` | same | Total image bytes above which the `Auto` base64 retry is skipped |
| `dedupe_on_timeout` | `true` | `true` | Look the board up before re-creating after an ambiguous create |
| `verify_after` | `60` | `60` | Seconds before each lookup (clamped to 5 s .. `social.max_recheck_after`, at most 900) |
| `verify_retries` | `5` | `5` | Unanswered creates, and unreadable lookups, tolerated per publish (each counted separately) |
| `verify_page_size` | `50` | `50` | Pins listed per lookup (1 to 250) |
| `pin_timeout` | `60` | `60` | Read timeout (s) of `POST /pins` (at least 10) |
| `upload_timeout` | `600` (env `SOCIAL_PINTEREST_UPLOAD_TIMEOUT`) | `social.upload_timeout` (300) | Read timeout (s) of the S3 upload. Capped at `social.job_timeout - 60` when that is set; at least 30 |
| `video_max_wait` | `1800` | `1800` | Seconds of processing allowed after the upload (at least 60) |
| `image_max_bytes` | `20971520` | same | Image size limit |
| `video_max_bytes` | `2147483648` | same | Video size limit |
| `video_min_duration` | `4` | same | Seconds |
| `video_max_duration` | `900` | same | Seconds |
| `timeout` | not set | `social.http_timeout` (60) | Read timeout (s) of the other API calls |
| `connect_timeout` | not set | `10` | Connect timeout (s), S3 included |

The job also reads `api.pinterest.publish_timeout` and `api.pinterest.upload_run_seconds` (not set)
when it derives the job timeout; the driver does not use them.

Related global keys:

- Credentials and auth: `social.credentials_resolver`, `social.authorizers.pinterest`.
- Media: `social.gateway` (`LocalMediaGateway`; `FilesystemMediaGateway` re-hosts local files for
  `Url` mode), `social.inspector`, `social.download_timeout` (120), `social.media.*`.
- Timeouts: `social.http_timeout` (60), `social.upload_timeout` (300), `social.job_timeout` (null →
  derived, 960 s for Pinterest with the shipped config).
- Waits: `social.async.max_wait` (3600) and `social.async.max_wait_per_platform.pinterest`,
  `social.sync.max_wait` (120), `social.max_recheck_after` (900).
- Retries: `social.tries` (5), `social.backoff` ([30, 120, 300, 900]).
- Idempotency and logging: `social.idempotency`, `social.error_logging`, `social.error_log_channel`.

---

## Known limitations

- No first comment: Pinterest's API cannot create one (`CommentSkipped`, reason `unsupported`).
- No scheduled Pins, drafts or story formats. The driver never obtains or refreshes tokens.
- One board per publish. The production reference app allows at most 2 boards of one account per
  post, as an anti-spam rule of its own (Pinterest does not document one); the package does not
  enforce it across dispatches.
- The secret-board scopes are never checked.
- WebP only by URL, no GIF, no automatic conversion. One alt text per Pin (none per carousel item).
- The video upload is a single S3 request, not resumable: a retry registers and uploads the whole file
  again (and downloads a remote video again).
- The duplicate check reads one page of the board and matches on title and description only (see
  [Duplicate safety](#duplicate-safety)). Untitled Pins cannot be recovered when the board changed.
- Sandbox Pins get a `www.pinterest.com` permalink that does not open them.
- The 900 s video maximum and the key-frame-only cover are not confirmed on production (live checks 1
  and 12). Error codes in the test fixtures (40, 3, 8...) are assumed, not observed (live check 2).
- `extra` is sent as given and never validated.

---

## Open live checks

Fixtures cover these Pinterest behaviours, but they have not been confirmed against the live API yet.
Run `tests/Live/PinterestLiveTest.php` with `SOCIAL_LIVE_PINTEREST_TOKEN` and
`SOCIAL_LIVE_PINTEREST_BOARD_ID` (it uses the Sandbox unless `SOCIAL_LIVE_PINTEREST_SANDBOX=false`;
the board must belong to that environment). The check that pins (and then deletes) an image also
needs `SOCIAL_LIVE_PUBLISH=1` and `SOCIAL_LIVE_PINTEREST_IMAGE_URL`. See `tests/Live/README.md`.

1. Whether a `video_id` Pin with only `cover_image_key_frame_time` (no `cover_image_url`) is accepted
   on production, or returns the misleading 401 reported on the forums, and whether the
   `GET /media/{id}` probe then answers 200.
2. The real error codes and messages: 404 board not found, 403 "image too small/large/broken", 429,
   and whether `Retry-After` or `x-ratelimit-reset` is sent.
3. The exact Trial-access 403 code 29 message, and that it never comes with another status.
4. Codes 2786/2787, 503 code 21 and 500 code 12 from the ~7 s create deadline (community reports;
   they only trigger the `Auto` base64 retry).
5. That a new Pin is listed at once and first in `GET /boards/{board}/pins`, with byte-identical title
   and description, and the `created_at` format and timezone. Automated for the board listing; the
   section listing is manual.
6. Whether `GET /boards/{board}/pins` also lists Pins saved into a section.
7. That the streamed S3 multipart (fields first, file last, with a filename) answers 204, and that an
   expired policy returns `AccessDenied`.
8. Whether video upload (`POST /media`) works in the Sandbox.
9. Whether Pinterest uses `alt_text` on video Pins, and the per-item title, description and link on
   carousels.
10. The maximum JSON body of `POST /pins` with `multiple_image_base64` (5 × 20 MB).
11. Whether `ad_account_id` is honoured on `GET /boards/{board}/pins` (Business Access), as it is on
    the create.
12. The real organic video maximum: 900 s, or the 5 minutes the help pages state.
