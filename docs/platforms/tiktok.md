# TikTok

| | |
|---|---|
| Platform key | `'tiktok'` / `Platform::TikTok` |
| Driver | `SocialPoster\Platforms\TikTok\TikTokDriver` (no first comment: TikTok has no comment API) |
| API | TikTok Content Posting API, `https://open.tiktokapis.com/v2` (the driver's `$api` property; there is no config key) |
| Auth | TikTok user access token (OAuth 2.0), sent as `Authorization: Bearer` |
| Targets | `TikTokTarget::Drafts` (default): the creator's TikTok inbox. `TikTokTarget::DirectPost`: straight to the profile |
| Media transfer | Photos: pulled by TikTok from a public https URL (`PULL_FROM_URL`). Videos: pulled, or uploaded in chunks by the driver (`FILE_UPLOAD`), see `TikTokOptions::$transfer` |
| Options class | `SocialPoster\Platforms\TikTok\TikTokOptions` (+ `TikTokTarget`, `TikTokPrivacy`, `TikTokTransfer`), or `RawOptions(Platform::TikTok, [...])` |
| First comment | No |
| Async | Always: an init (commit) step, the chunk upload for `FILE_UPLOAD`, then status polls |
| Webhooks | `TikTokWebhook` (verify, parse) and `TikTokWebhookEvent`. No route or controller ships |

Every limit and setting on this page is read from `config('social.api.tiktok.*')` with the default
shown, so nothing depends on the published config file. The code in `src/Platforms/TikTok/` is the
reference.

---

## What you can publish

| Post | Target | Media | Endpoint | Notes |
|---|---|---|---|---|
| Video draft | `Drafts` (default) | 1 video | `POST /post/publish/inbox/video/init/` | Lands in the creator's inbox; the creator writes the caption and posts it in the TikTok app. Nothing but the video is sent |
| Video | `DirectPost` | 1 video | `POST /post/publish/video/init/` | Caption sent as `post_info.title`. Privacy level required |
| Photo draft | `Drafts` | 1 to 35 images | `POST /post/publish/content/init/`, `post_mode=MEDIA_UPLOAD` | Caption as `description`, `->title()` as `title` |
| Photo post | `DirectPost` | 1 to 35 images | `POST /post/publish/content/init/`, `post_mode=DIRECT_POST` | Caption as `description`, `->title()` as `title`. Privacy level required |

- One video is a video post. One image, or any post with more than one item, is a photo post
  (`media_type=PHOTO`): images only, videos and images cannot be mixed.
- A post needs media. Text-only posts, documents, stories and scheduling are not supported. The
  driver never schedules: a direct post goes live when TikTok has processed it.
- `->title()` is used only on photo posts. Alt text is never sent. A video `thumbnail` is not sent
  (see [Covers](#covers)).

### Video draft (the default)

```php
use SocialPoster\Facades\SocialPoster;
use SocialPoster\ValueObjects\Media;

SocialPoster::on('tiktok')
    ->using(['access_token' => $token, 'open_id' => $openId])
    ->media(Media::video('https://media.example.com/clips/demo.mp4'))
    ->post();
```

An inbox video carries no `post_info`, so a caption, privacy level or other option has nothing to go
in. A caption is accepted (it has no length limit here) and the result payload then carries
`caption_ignored = true`. The result's `state` is `draft` and its id is the `publish_id`.

### Direct video

```php
use SocialPoster\Platforms\TikTok\{TikTokOptions, TikTokPrivacy, TikTokTarget};

SocialPoster::on('tiktok')
    ->using(['access_token' => $token, 'open_id' => $openId])
    ->media(Media::video('https://media.example.com/clips/launch.mp4'))
    ->caption('Launch day. #buildinpublic')
    ->withOptions(new TikTokOptions(
        target: TikTokTarget::DirectPost,
        privacyLevel: TikTokPrivacy::PublicToEveryone,
    ))
    ->post();
```

A direct post always queries `creator_info` first (see [Async behaviour](#async-behaviour)).

### Photo (draft or direct)

```php
SocialPoster::on('tiktok')
    ->using($credentials)
    ->media(Media::image('https://media.example.com/shots/dashboard.jpg'))
    ->title('The new dashboard')                    // post_info.title, at most 90
    ->caption('What changed in 1.1, in one picture.') // post_info.description, at most 4000
    ->withOptions(new TikTokOptions(
        target: TikTokTarget::DirectPost,
        privacyLevel: TikTokPrivacy::FollowerOfCreator,
        autoAddMusic: true,
    ))
    ->post();
```

Leave out `withOptions()` (or pass `new TikTokOptions`) to send the photo to the inbox instead.

### Photo carousel (2 to 35 images)

```php
SocialPoster::on('tiktok')
    ->using($credentials)
    ->media(
        Media::image('https://media.example.com/a.jpg'),
        Media::image('https://media.example.com/b.webp'),
        Media::image('https://media.example.com/cover.jpg'),
    )
    ->caption('Before, after, and the cover.')
    ->withOptions(new TikTokOptions(photoCoverIndex: 2))
    ->post();
```

`photoCoverIndex` is zero-based and points at one of the images. The images are sent in the given
order as `source_info.photo_images`.

### Local or downloaded video (chunked upload)

```php
use SocialPoster\Platforms\TikTok\{TikTokOptions, TikTokTransfer};

// A local file is uploaded in chunks (Auto).
SocialPoster::on('tiktok')
    ->using($credentials)
    ->media(Media::video(storage_path('app/renders/launch.mp4')))
    ->post();

// A remote video, downloaded by the MediaGateway and uploaded: no domain verification needed.
SocialPoster::on('tiktok')
    ->using($credentials)
    ->media(Media::video('https://bucket.example.com/launch.mp4'))
    ->withOptions(new TikTokOptions(transfer: TikTokTransfer::FileUpload))
    ->post();
```

### How media reaches TikTok

Videos follow `TikTokOptions::$transfer`:

| `TikTokTransfer` | Behaviour |
|---|---|
| `Auto` (default) | A remote `https` URL the gateway can provide is pulled (`PULL_FROM_URL`). A local file, `Media::fromDisk()` on a local disk, or an `http` URL is uploaded (`FILE_UPLOAD`). A local video is uploaded even when the gateway could re-host it. When TikTok refuses a pull with `url_ownership_unverified`, the driver downloads the video and initialises again as `FILE_UPLOAD`, once; the payload then has `fallback = url_ownership_unverified` |
| `PullFromUrl` | Always pulled. The URL must be https. A local file needs a gateway that publishes it (`FilesystemMediaGateway`), else a validation error (`media.0`). No fallback |
| `FileUpload` | Always uploaded. Remote media is downloaded through the `MediaGateway` first (the default `LocalMediaGateway` does this) |

Photos are always pulled: TikTok has no photo upload. Every image needs a public https URL.

Rules TikTok sets for a pull
([TikTok: media transfer guide](https://developers.tiktok.com/docs/en/content-posting-api-media-transfer-guide)):

- the URL is https and does not redirect;
- its domain or URL prefix is verified for your app in the TikTok developer portal; otherwise the
  init fails with `url_ownership_unverified` (`MediaRejected`);
- the URL stays reachable until TikTok has downloaded the file. TikTok gives up one hour after the
  init (`video_pull_failed` / `photo_pull_failed`).

Validation reports an `http` photo URL, a local photo without a gateway that can publish it, and an
`http` or local video under `PullFromUrl` (key `media.N`). At publish time a URL that is not https is
`PermanentException(MediaRejected)`.

PNG photos: TikTok takes JPEG and WebP only. A `.png` is a validation error unless:

1. `social.transformer` is `SocialPoster\Transformers\ImageConvertingTransformer` (needs ext-gd or
   ext-imagick), which converts it to `.jpg`, and
2. the gateway can publish the converted local file: `FilesystemMediaGateway` with `rehost` other
   than `never`, or your own gateway. The default `LocalMediaGateway` cannot, and validation says so.

`Media::fromDisk()` on a cloud disk gives the disk's URL, which `Auto` pulls; on a local disk it gives
a path, which is uploaded.

---

## Credentials

| Key | Required | Meaning |
|---|---|---|
| `access_token` | yes | A TikTok user access token |
| `open_id` | recommended | The creator's `open_id`. It is the account identity in idempotency keys, and the `user_openid` of TikTok's webhooks. Without it the identity is a generic `account` credential when set, else `ref:<key>` for a `Credentials::ref()`, else none: the key is then scoped by platform and `->idempotencyKey()` only, so two creators dispatched with the same key share one record |
| `scopes` | optional | The granted scopes: a list, or a space- or comma-separated string (TikTok returns them comma-separated). When present, validation checks them |
| `expires_at` | optional | Token expiry: unix seconds or milliseconds, an ISO-8601 string or a `DateTimeInterface` |

A missing `access_token` fails with `PermanentException(Misconfigured)` and context
`missing_credentials = ['access_token']`, at the start of `publish()` and `resume()`, before any
request, never in `validate()`.

```php
use SocialPoster\ValueObjects\Credentials;

Credentials::make([
    'access_token' => $token,
    'open_id' => $openId,
    'scopes' => 'user.info.basic,video.upload,video.publish',
    'expires_at' => $expiresAt,
]);

// Or keep the token out of the queue payload and resolve it on every job run:
Credentials::ref('social_account:42', ['open_id' => $openId]);
```

### Auth model

- Every API call goes to `https://open.tiktokapis.com/v2` with `Authorization: Bearer <access_token>`
  and a JSON body (`Content-Type: application/json; charset=UTF-8`). `creator_info` is sent with no
  body, because TikTok rejects `[]`.
- Chunks are `PUT` to the pre-signed `upload_url` the init returns (a `https://open-upload.tiktokapis.com/...`
  URL). No `Authorization` header is sent there.
- When `config('social.authorizers.tiktok')` names an `AuthorizesRequests` class, the API calls are
  built from it instead of the bearer header. Chunk uploads are not.
- The driver never refreshes tokens. TikTok access tokens expire after 24 hours
  ([TikTok: manage user access tokens](https://developers.tiktok.com/doc/oauth-user-access-token-management)),
  so a post that waits in the queue, or a long upload and poll, can outlive the token it was dispatched
  with. Bind a `CredentialsResolver` (`social.credentials_resolver`) that returns a current token: the
  job calls it at the start of every run.
- With a custom resolver bound, a token rejection answered with HTTP 401 (`access_token_invalid`, or a
  401 without a code) is retried once with re-resolved credentials (at the init as a fresh commit step, state `init_reresolved`; elsewhere
  immediately, by the job). A second rejection is `PermanentException(InvalidToken)`.
- `expires_at` is checked by `publish()` only, with a 60 s margin: with a custom resolver bound it is a
  `TemporaryException(InvalidToken, 0 s)` flagged `reresolve`; without one it is
  `PermanentException(InvalidToken)` with `token_expired = true`. Continuations do not check it.

### Scopes

| Feature | Scopes (any one) |
|---|---|
| `Feature::Publish`, `Feature::Video` | `video.publish` or `video.upload` |
| `Feature::Comment` | none (no comment API) |

```php
use SocialPoster\Enums\{Feature, Platform};

SocialPoster::requiredScopes(Platform::TikTok, Feature::Publish);
// [['video.publish', 'video.upload']]
```

When `scopes` is in the credentials, validation adds a `credentials.scopes` error when:

- neither `video.publish` nor `video.upload` is granted;
- the target is `DirectPost` and only `video.upload` is granted;
- the target is `Drafts` and only `video.publish` is granted.

Without `scopes`, nothing is checked before publishing. A missing scope then comes back from TikTok
as `scope_not_authorized` (`InsufficientPermissions`).

### App setup and audit

- Add the Content Posting API to your TikTok app. Request `video.upload` for drafts and `video.publish`
  for direct posts.
- Until TikTok audits your API client, everything it direct-posts is restricted to private viewing
  ([TikTok: direct post](https://developers.tiktok.com/docs/en/content-posting-api-reference-direct-post)).
  `creator_info` then usually allows only `SELF_ONLY`, and other levels fail with
  `unaudited_client_can_only_post_to_private_accounts` or `privacy_level_option_mismatch`
  (`InsufficientPermissions`). Use `TikTokPrivacy::SelfOnly` while testing. Drafts work without the
  audit: the creator posts them from the app.
- Verify every domain or URL prefix TikTok should pull from (photos, and pulled videos) in the
  developer portal.
- Drafts (`MEDIA_UPLOAD`) need TikTok app 31.8 or later on the creator's phone; older apps fail with
  `app_version_check_failed`.
- TikTok's content sharing guidelines ask apps to show the creator's info and let them pick the
  privacy level and interaction settings. The driver never picks a privacy level on its own (fallbacks
  are opt-in), applies the creator's comment, duet and stitch locks, and checks the creator's maximum
  video duration.
- For webhooks, register a callback URL in the developer portal; deliveries are signed with your
  app's client secret (see [Webhooks](#webhooks)).

---

## Typed options

```php
use SocialPoster\Platforms\TikTok\{TikTokOptions, TikTokPrivacy, TikTokTarget, TikTokTransfer};

new TikTokOptions(
    target: TikTokTarget::DirectPost,
    privacyLevel: TikTokPrivacy::PublicToEveryone,
    disableDuet: true,
    coverTimestampMs: 1500,
    transfer: TikTokTransfer::Auto,
    brandOrganic: true,
    isAigc: false,
    privacyFallbacks: [TikTokPrivacy::FollowerOfCreator],
);
```

The first nine constructor positions are v1.0's (`target` to `extra`); the 1.1 options follow, so pass
them by name. `TikTokOptions` given to another platform in a fan-out is ignored there.

| Property | Type | Default | Sent as | Effect and rules |
|---|---|---|---|---|
| `target` | `TikTokTarget` | `Drafts` | endpoint / `post_mode` | `DirectPost` publishes to the profile; `Drafts` sends to the inbox |
| `privacyLevel` | `?TikTokPrivacy` | `null` | `post_info.privacy_level` | Required for `DirectPost` (validation key `privacy_level`). Checked against `creator_info`, see below. Not used for drafts |
| `disableComment` | `?bool` | `null` | `post_info.disable_comment` | Direct posts (video and photo) |
| `disableDuet` | `?bool` | `null` | `post_info.disable_duet` | Direct videos |
| `disableStitch` | `?bool` | `null` | `post_info.disable_stitch` | Direct videos |
| `coverTimestampMs` | `?int` | `null` | `post_info.video_cover_timestamp_ms` | Direct videos: the frame used as cover. 0 or more (`cover_timestamp_ms`) |
| `photoCoverIndex` | `int` | `0` | `source_info.photo_cover_index` | Photo posts, direct and draft. Zero-based; 0 or more and below the number of images (`photo_cover_index`) |
| `autoAddMusic` | `?bool` | `null` | `post_info.auto_add_music` | Direct photo posts. Not sent when `null` |
| `extra` | `array` | `[]` | merged into `post_info` | See [The `extra` passthrough](#the-extra-passthrough) |
| `transfer` | `TikTokTransfer` | `Auto` | `source_info.source` | Videos only, see [How media reaches TikTok](#how-media-reaches-tiktok) |
| `brandContent` | `?bool` | `null` | `post_info.brand_content_toggle` | Direct posts: a paid partnership promoting a third party. Cannot be private (`SELF_ONLY`) |
| `brandOrganic` | `?bool` | `null` | `post_info.brand_organic_toggle` | Direct posts: the creator promotes their own business |
| `isAigc` | `?bool` | `null` | `is_aigc` | AI-generated content. Direct video: in `post_info`. Photo post, direct or draft: a top-level field of `content/init`. Draft video: not sent (no `post_info`). Not sent when `null` |
| `privacyFallbacks` | `TikTokPrivacy[]` | `[]` | — | Levels to use, in order, when the creator no longer allows `privacyLevel`. Each must be a `TikTokPrivacy` (`privacy_fallbacks`) |

Enums:

- `TikTokTarget`: `DirectPost` (`direct_post`), `Drafts` (`drafts`).
- `TikTokPrivacy`: `PublicToEveryone` (`PUBLIC_TO_EVERYONE`), `MutualFollowFriends`
  (`MUTUAL_FOLLOW_FRIENDS`), `FollowerOfCreator` (`FOLLOWER_OF_CREATOR`), `SelfOnly` (`SELF_ONLY`).
- `TikTokTransfer`: `Auto` (`auto`), `PullFromUrl` (`pull_from_url`), `FileUpload` (`file_upload`).

Drafts send none of the `post_info` options: a draft video sends only `source_info`; a draft photo
sends `description`, `title` and `extra` (without the brand toggles), plus `photo_cover_index` and
`is_aigc`.

### Privacy level and fallbacks

For a direct post, `publish()` reads `privacy_level_options` from `creator_info`:

1. the chosen `privacyLevel` is used when it is listed, or when TikTok lists nothing;
2. otherwise the first `privacyFallbacks` entry that is listed is used, and the result payload gets
   `privacy_requested` and `privacy_fallback_used`;
3. otherwise the post fails before the init with `PermanentException(InsufficientPermissions)`; the
   message lists the allowed levels, and the context has `privacy_level`, `privacy_level_options` and
   `reconnect = false` (no `CredentialsRejected`: a reconnect does not change what the creator allows).

Branded content (`brandContent: true`) is never private: `privacyLevel: SelfOnly` is a validation
error (`brand_content`) and `PermanentException(InvalidContent)` at publish; `SelfOnly` in
`privacyFallbacks` is a validation error (`privacy_fallbacks`) and is skipped at publish. A direct post
without a privacy level fails at publish with `PermanentException(Validation)` when validation was
skipped.

### Comment, duet and stitch

The `disable_*` fields are sent only when needed:

- when `creator_info` says the creator turned the feature off (`comment_disabled`, `duet_disabled`,
  `stitch_disabled`), `true` is sent whatever the option says;
- otherwise the option's value is sent when it is not `null`;
- otherwise the field is left out, and TikTok applies its default for the privacy level. (Sending
  `disable_duet=false` on a `SELF_ONLY` post is refused as `invalid_params`.)

### Disclosure: brand toggles and AI label

`post_info` of a direct post is layered, last wins:

1. `brand_content_toggle = false` and `brand_organic_toggle = false`, when
   `api.tiktok.send_brand_defaults` is `true` (the default; the production app has always sent them);
2. `extra`;
3. the typed `brandContent`, `brandOrganic` and, for videos, `isAigc`;
4. the keys the driver computes: `privacy_level`, `title`, `description`, `disable_*`,
   `video_cover_timestamp_ms`, `auto_add_music`.

Draft posts never carry the brand toggles: TikTok accepts them on `DIRECT_POST` only, and the creator
discloses in the app. Whether `false` defaults are accepted on an unaudited client is open live
check 1.

### The `extra` passthrough

`TikTokOptions::$extra`, or `RawOptions(Platform::TikTok, [...])`, adds `post_info` fields the class
does not model:

- it is merged into `post_info` of direct posts and draft photos, never into the top level of the
  request;
- the computed keys above win over it; it can override the brand defaults, and the typed disclosure
  options win over it;
- a draft video has no `post_info`: `extra` there is a validation error (`extra`), and is dropped when
  validation is skipped;
- on a draft photo, `brand_content_toggle` and `brand_organic_toggle` are removed from it;
- it is not validated;
- `RawOptions` carries no target, so the post uses the defaults (`Drafts`): use
  `new TikTokOptions(..., extra: [...])` for direct posts.

### Covers

- Video: `coverTimestampMs` picks a frame (direct posts only). TikTok has no cover-image upload, so a
  `thumbnail` passed to `Media::video()` is not used and the result payload carries
  `thumbnail_warning`. On a direct post the warning points to `coverTimestampMs`; on a draft it says
  the creator picks the cover in the TikTok app.
- Photos: put the cover image in the media list and point `photoCoverIndex` at it.

---

## Limits

### Text

Counted in UTF-16 code units on the trimmed text (`Utf16Counter`; an emoji such as `😀` counts 2):

| Post | Field | Limit | Config key |
|---|---|---|---|
| Direct video | caption → `post_info.title` | 2200 | `api.tiktok.video_title_limit` |
| Draft video | caption | none: not sent (`caption_ignored`) | — |
| Photo, direct or draft | caption → `post_info.description` | 4000 | `api.tiktok.photo_description_limit` |
| Photo, direct or draft | `->title()` → `post_info.title` | 90 | `api.tiktok.photo_title_limit` |
| Video | `->title()` | ignored | — |

Blank text is left out of the request. `capabilities()` (the fan-out summary) reports the video limit,
2200. Validation errors land under `caption`, `title`, `media`, `media.N`, `privacy_level`,
`photo_cover_index`, `cover_timestamp_ms`, `privacy_fallbacks`, `brand_content`, `extra` and
`credentials.scopes`.

### Media

| | Photos | Videos |
|---|---|---|
| Formats | `jpg`, `jpeg`, `webp` (`png` converted to `jpg`, see above) | `mp4`, `webm`, `mov`; codecs `video_codecs` = `h264`, `hevc`, `vp8`, `vp9` |
| Size | `photo_max_bytes` = 20,971,520 (20 MB) | `video_max_bytes` = 4,294,967,296 (4 GB) |
| Duration | n/a | `video_min_duration` = 3 s to `video_max_duration` = 600 s. Direct posts: also the creator's `max_video_post_duration_sec` from `creator_info`, checked at publish (`MediaRejected`, context `max_video_post_duration_sec`, `duration`) |
| Dimensions | `photo_max_width`, `photo_max_height`: `null` (not checked) | 360 to 4096 px wide and high (fixed in the driver) |
| Frame rate | n/a | `video_min_frame_rate` = 23 to `video_max_frame_rate` = 60 fps, with 0.5 fps of tolerance |
| Aspect ratio | not checked | not checked |
| Count | 1 to 35 per post | 1 per post |

- Extensions are always checked. Size, duration, dimensions, codec and frame rate are checked only when
  metadata is known: from the bound `MediaInspector` (ffprobe by default) or from the `meta` array
  given to `Media::image()` / `Media::video()`. For a PNG that will be converted, only pixel rules
  apply. The creator duration check also needs a known duration.
- TikTok documents photos as at most 1080p. The driver sets no dimension limit by default; set
  `photo_max_width` / `photo_max_height` to check one (open live check 9).
- An empty or unreadable local video fails before the init (`MediaRejected`).

### Carousel

2 to 35 images (`media` errors outside that range), JPEG or WebP, no video. A single image is a photo
post too. `photoCoverIndex` must point at one of them.

### Chunked upload (`FILE_UPLOAD`)

- A file of at most 67,108,864 bytes (64 MiB) goes as one chunk.
- A larger file is cut into chunks of `api.tiktok.chunk_size` (10,485,760 bytes; clamped to
  5,242,880 to 67,108,864). `total_chunk_count` is the size divided by the chunk size, rounded down;
  the last chunk carries the remainder (TikTok accepts a final chunk larger than the others, up to
  128 MB). When that gives a single chunk, the file is cut in two halves. A 4 GB file is 409 chunks.
- Each `PUT` carries `Content-Range: bytes {start}-{end}/{size}` and the video's type (`.mov`
  `video/quicktime`, `.webm` `video/webm`, otherwise `video/mp4`). One chunk is held in memory at a
  time.
- TikTok's `upload_url` is valid for one hour after the init
  ([TikTok: direct post](https://developers.tiktok.com/docs/en/content-posting-api-reference-direct-post));
  a chunk sent later is refused with 403 and the publish restarts (see [Error handling](#error-handling)).

---

## First comment

Not supported: TikTok's API has no endpoint to create comments. `TikTokDriver` implements neither
`SupportsFirstComment` nor `SupportsComments`. A `->withComment()` is not validated for TikTok and is
never posted; the job fires `CommentSkipped` instead:

- reason `unsupported` for a published post, or one whose public id is still pending;
- reason `scheduled` for a draft (`state = draft`) or a private post (`state = private`), because
  the job only comments on published or processing results.

In a fan-out, the comment still goes to the platforms that support one.

---

## Async behaviour

Every TikTok post runs as a chain of job runs; the state travels with each continuation.
`PostProcessing` fires for every wait (`reason` `retrying` while a refused init waits); not for the
immediate hop into the init.

| Phase (`state['phase']`) | What happens | Next |
|---|---|---|
| `publish()` | Direct posts: `POST /post/publish/creator_info/query/` (privacy check and fallback, username, interaction locks, maximum duration). Photos: PNG conversion and URLs. Videos: transfer choice; for `FILE_UPLOAD`, the local file (a download is moved to `sp_tiktok_<16 hex>`), its size and the chunk plan. No create request is sent here | `init`, a commit step, at once |
| `init` | The create: `video/init`, `inbox/video/init` or `content/init`. TikTok answers with a `publish_id`, plus an `upload_url` for `FILE_UPLOAD` | Pull: `await_publish` after 15 s. Upload: `upload` at once. Refused with an error code: `init` again (see [Error handling](#error-handling)). `url_ownership_unverified` under `Auto`: `prepare_upload` |
| `prepare_upload` | `Auto` fallback: downloads the video and plans the chunks | `init` again, as `FILE_UPLOAD` |
| `upload` | `PUT`s chunks until the run has spent `upload_run_seconds` (240 s), keeping the offset in the state | `upload` again at once, or `await_publish` after 15 s once TikTok holds every byte |
| `await_publish` | `POST /post/publish/status/fetch/` with the `publish_id` (see the table below) | `await_publish`, `await_public_id`, done, or a failure |
| `await_public_id` | Direct `PUBLIC_TO_EVERYONE` posts that completed without a public video id: reads the status every `public_id_poll_interval` (30 s) for up to `public_id_wait` (600 s) | Done |

`await` is accepted as an alias of `await_publish`.

Status in `await_publish`:

| `data.status` | Action |
|---|---|
| `PROCESSING_UPLOAD`, `PROCESSING_DOWNLOAD`, or any other | Read again. Reads 1 to 4 are 15 s apart (`poll_interval`), then 30 s, 60 s, and 120 s (`poll_max_interval`): the interval doubles every 4 reads. `uploaded_bytes` / `downloaded_bytes` are kept in `state['progress']` |
| `SEND_TO_USER_INBOX` | Done: a draft |
| `PUBLISH_COMPLETE` | Done, with the public video id when TikTok gives one. A direct `PUBLIC_TO_EVERYONE` post without it goes to `await_public_id` (TikTok fills `publicaly_available_post_id` only once the post is public and has passed moderation) |
| `FAILED` | See [`fail_reason`](#failed-status-fail_reason) |

A status read refused with HTTP 429 or `rate_limit_exceeded` waits `Retry-After`, else 60 s, without
spending a retry (TikTok allows 30 status reads a minute per token).

### Results

| Outcome | `platformPostId` | `idKind` | `url` | `state` |
|---|---|---|---|---|
| `SEND_TO_USER_INBOX` | `publish_id` | `publish_id` | `null` | `draft` |
| `PUBLISH_COMPLETE` with a public video id | the video id (digits) | `video_id` | `https://www.tiktok.com/@{username}/video/{id}` | `published` |
| Direct `PUBLIC_TO_EVERYONE`, no id within `public_id_wait`, or the read refused | `publish_id` | `publish_id` | `null` | `processing`; payload `public_id_pending = true` (+ `public_id_error`) |
| Direct `SELF_ONLY`, no public id | `publish_id` | `publish_id` | `null` | `private` |
| Other privacy levels, or `public_id_wait = 0`, no public id | `publish_id` | `publish_id` | `null` | `published` |

- The permalink needs the creator's username, which only `creator_info` (direct posts) provides. It
  is built only for a numeric id and a username matching `^[\w.]{2,24}$`; otherwise `url` is `null`.
  `TikTokDriver::permalink(?string $username, ?string $videoId)` is public.
- A `processing` result is final: the post is live, the chain ends with `PostPublished`, and the video
  id arrives later through the `post.publish.publicly_available` webhook (match the `publish_id`).

Payload keys: `status`, `publish_id` (always), `post_id` (the video id or `null`), and when they apply
`privacy`, `privacy_requested`, `privacy_fallback_used`, `transfer` (`PULL_FROM_URL` or
`FILE_UPLOAD`), `fallback`, `thumbnail_warning`, `caption_ignored`, `public_id_pending`,
`public_id_error`, `creator_username`.

### What a `Pending` means for your code

- With `->post()`, nothing to do: each wait is a delayed continuation (an upload slice continues at
  once), and the chain ends with `PostPublished` or `PostFailed`. No worker sleeps on TikTok.
- With `->postNow()`, the waits run in-process while they fit `social.sync.max_wait` (120 s). A draft
  photo or a small pulled video can finish (the init, then a first status read 15 s later). Past the
  limit the publish is handed to the queue and `postNow()` returns a success with state `processing`,
  a `null` id and payload `handed_off = true`; the outcome arrives as `PostPublished` / `PostFailed`.
  On the `sync` queue connection there is no queue to hand over to, so it fails with a timeout
  instead.

### How long it takes

- The init runs one queue hop after `publish()`. An upload runs at the speed of TikTok's upload host,
  in runs of `upload_run_seconds` (240 s).
- The first status read is 15 s after the init (pull) or after the last chunk (upload).
- TikTok's pull download times out one hour after the init. The driver declares a deadline of
  `max_wait` (3900 s) from the init, reset to 3900 s from the end of the upload for `FILE_UPLOAD`.
  It wins over `social.async.max_wait_per_platform.tiktok`.
- The public video id is awaited for `public_id_wait` (600 s); the chain's deadline is then that wait
  plus 60 s after `PUBLISH_COMPLETE`.
- The package records no typical processing times. How long the public id takes to appear is open
  live check 7.

At the deadline, the job's last run still reads the status once: a post that completed in the final
interval is reported as published. If TikTok still reports processing, or rate-limits that last read,
the post fails with `PermanentException(ProcessingTimeout)` and `possiblyPublished = true`; the
driver puts `publish_id` and the last `progress` in the context (when the job raises it instead,
because the read landed exactly on the deadline, the context has `deadline`, `elapsed_seconds` and
`polls`). Check the profile or the webhooks for that `publish_id` before posting again.

Timeouts: API calls (creator_info, init, status) use `api.tiktok.timeout` (`social.http_timeout`,
60 s) and `api.tiktok.connect_timeout` (10 s). Each chunk `PUT` uses `api.tiktok.upload_timeout`
(`social.upload_timeout`, 300 s). With these defaults the derived job timeout for TikTok is 660 s
(240 + 300 + 120), unless `social.job_timeout` is set.

---

## Error handling

TikTok answers errors as `{"error": {"code", "message", "log_id"}}`, and some of them with HTTP 200
(`creator_info` returns `spam_risk_too_many_posts`, `spam_risk_user_banned_from_posting` and
`reached_active_user_cap` that way). A response is a success only when it is 2xx **and**
`error.code` is `ok` or absent. Codes are matched exactly first
([TikTok: error handling](https://developers.tiktok.com/docs/en/tiktok-api-v2-error-handling)). The
exception message is TikTok's `error.message` or a readable line; the context holds `status`,
`http_status`, `error` (TikTok's error object), `code`, `trace_id` (TikTok's `log_id`) and, for init
errors, `sent`: the request body with URL query strings replaced by `?[redacted]`.

| `error.code` | Exception | `FailureReason` | Notes |
|---|---|---|---|
| `access_token_invalid` | Permanent | `InvalidToken` | Reconnect. Retried once with re-resolved credentials when a custom resolver is bound and TikTok answered 401 |
| `scope_not_authorized` | Permanent | `InsufficientPermissions` | Reconnect with `video.publish` (direct) or `video.upload` (drafts) |
| `spam_risk_user_banned_from_posting` | Permanent | `AccountRestricted` | — |
| `spam_risk_too_many_posts` | Permanent | `RateLimited` | The creator's daily post cap. Context `retry_after_hint = 86400`, `retry_at` |
| `reached_active_user_cap` | Permanent | `RateLimited` | Your client's daily quota of active publishing users. Context `retry_after_hint = 86400`, `retry_at` |
| `spam_risk_too_many_pending_share` | Temporary | `RateLimited` | After 3600 s (at the init: 900 s per try, see below). At most 5 pending inbox shares per 24 hours: the creator must post or delete drafts |
| `app_version_check_failed` | Permanent | `AccountRestricted` | The creator must update the TikTok app (31.8 or later) to receive drafts |
| `invalid_param`, `invalid_params` | Permanent | `InvalidContent` | TikTok's message is kept |
| `token_not_authorized_for_specified_publish_id`, `invalid_publish_id` | Permanent | `NotFound` | Status reads. Not a token problem. Context `publish_id` |
| `url_ownership_unverified` | Permanent | `MediaRejected` | Unverified pull host. Under `Auto`, a pulled video first falls back to `FILE_UPLOAD` |
| `privacy_level_option_mismatch` | Permanent | `InsufficientPermissions` | Context `reconnect = false` (see below) |
| `unaudited_client_can_only_post_to_private_accounts` | Permanent | `InsufficientPermissions` | Context `reconnect = false`. See [App setup and audit](#app-setup-and-audit) |
| `rate_limit_exceeded` | Temporary | `RateLimited` | After `Retry-After`, else 60 s |
| `internal_error` | Temporary | `ServerError` | After `Retry-After`, else 60 s |
| No code, HTTP 401 | Permanent | `InvalidToken` | — |
| No code, HTTP 429 | Temporary | `RateLimited` | After `Retry-After`, else 60 s |
| No code, any other status | Temporary | `ServerError` | After `Retry-After`, else 60 s (an empty, non-JSON or code-less error body is treated as a transient glitch) |
| Another code containing `rate_limit` | Temporary | `RateLimited` | 60 s |
| Another code containing `internal`, or HTTP 5xx | Temporary | `ServerError` | 60 s |
| Another code starting with `access_token` | Permanent | `InvalidToken` | — |
| Another code containing `scope`, `permission` or `unaudited` | Permanent | `InsufficientPermissions` | — |
| Another code containing `spam` or `frequency` | Temporary | `RateLimited` | 1800 s |
| Anything else | Base table | 401 `InvalidToken`, 403 `InsufficientPermissions`, 404 `NotFound` (Permanent); 408/425 `ServerError`, 429 `RateLimited` (Temporary); other `Unknown` (Permanent) | — |

`requiresReconnect()` is true for `InvalidToken` and `InsufficientPermissions`, and the job then also
fires `CredentialsRejected` (`terminal` for `auth_removed`). The privacy refusals are the exception:
`privacy_level_option_mismatch`, `unaudited_client_can_only_post_to_private_accounts` and a privacy
level the creator does not allow keep `InsufficientPermissions` but carry context
`reconnect = false`, because reconnecting does not help. `$e->needsReconnect()` is then `false`, no
`CredentialsRejected` is fired, and `errorSummary()` reports the category `user_fix`.

### Where the error happens

| Step | Temporary | Permanent |
|---|---|---|
| `creator_info` (direct posts, in `publish()`) | Retried by the job, up to `social.tries` (5), waiting the delay above or `social.backoff` | `PostFailed`, not possibly published |
| `init` | When TikTok answered with an error code, or with HTTP 429 or 503, it created nothing: the init is sent again as a fresh commit step after the delay (clamped to 5 to 900 s, `social.max_recheck_after`), at most `init_retries` (4) times, then `PermanentException` with the same reason ("... still refused after N retries; giving up", context `init_attempts`). A pending-share refusal therefore waits 900 s per try. A Temporary without a code (a 502 HTML page, a code-less 500) is ambiguous: see [Duplicate safety](#duplicate-safety) | `PostFailed`, not possibly published |
| `upload` | See [Chunk upload errors](#chunk-upload-errors) | |
| `await_publish` | Rate limits: wait (above). Others: retried by the job; a final failure is possibly published | A refused status read: `PostFailed` with `possiblyPublished = true` and `publish_id` in the context. A `FAILED` status: see below |
| `await_public_id` | Waits and reads again (connection failures too) | Not a failure: the result is the `publish_id` with `public_id_pending` and `public_id_error` (the code, or the reason) |

### FAILED status (`fail_reason`)

`TikTokFailReason` maps TikTok's `fail_reason` (from `status/fetch`, and the webhook's `reason`):

| `fail_reason` | `FailureReason` | Action |
|---|---|---|
| `internal` | `ServerError` | A fresh publish (new init) after 60 s |
| `video_pull_failed`, `photo_pull_failed` | `MediaRejected` | A fresh publish after 60 s. Check the URL rules: https, no redirect, verified host, reachable for an hour |
| `file_format_check_failed`, `duration_check_failed`, `frame_rate_check_failed`, `picture_size_check_failed` | `MediaRejected` | Permanent, with a readable message |
| `auth_removed` | `InvalidToken` | Permanent, context `terminal = true`: the creator revoked your app |
| `spam_risk_too_many_posts` | `RateLimited` | Permanent, context `retry_after_hint = 86400`, `retry_at` |
| `spam_risk_user_banned_from_posting` | `AccountRestricted` | Permanent |
| `spam_risk_text`, `spam_risk` | `InvalidContent` | Permanent |
| `publish_cancelled` | `Unknown` | Permanent |
| empty, or a reason TikTok adds later | `MediaRejected` | Permanent (empty is reported as `unknown`) |

- The fresh publishes are counted: at most `api.tiktok.max_restarts` (2), and never more than
  `social.max_restarts` (3). Then the failure is permanent with the same reason and context
  `restarts`. The job drops the state and runs `publish()` again, without validation.
- The context holds `publish_id`, `fail_reason` and `status = FAILED`.
- A `FAILED` reported in `await_public_id`, after `PUBLISH_COMPLETE`, is never restarted: it is
  permanent, with the reason from the table and `possibly_published = true`.
- A `FAILED` in `await_publish`, before `PUBLISH_COMPLETE`, is TikTok saying nothing was posted: it
  carries `possibly_published = false` (also once the restarts are used up). `PostFailed` then has
  `possiblyPublished = false` and the idempotency key is forgotten, so the same post can be
  dispatched again with the same key once the media is fixed.

### Chunk upload errors

| Upload host answer | Action |
|---|---|
| 206 | Next chunk |
| 201 | TikTok holds every byte: on to `await_publish` |
| 416, or a 2xx on the last chunk other than 201 | Resync: continue from the byte count in `Content-Range` / `Range` (`bytes 0-N/size`, or `bytes */size` for none). At most 3 resyncs per run; without a usable header, or past that, a fresh publish. A plain 200 on the last chunk without the header counts as complete |
| 5xx, or a connection error | The same chunk again, up to `chunk_attempts` (3) attempts, waiting `chunk_retry_ms` × attempt (2 s, then 4 s), and only while the retry still starts within `upload_run_seconds`. Then `TemporaryException`: `ServerError` (after `Retry-After`, else 30 s) or `Network`; the job runs the phase again from the same offset, up to `social.tries` |
| 408, 429 | `TemporaryException` (`ServerError`, `RateLimited`), after `Retry-After`, else 30 s; not retried within the run |
| 403 (`upload_url` expired), 404 (no upload task) | A fresh publish (new init), within `max_restarts`; then `PermanentException(NotFound)` |
| 400 | `PermanentException(Unknown)`: TikTok calls the chunk malformed |
| Any other 4xx | `PermanentException(Unknown)` |

A chunk that reads short, or a file whose size changed between runs, also restarts the publish
(`MediaRejected`). The context holds `publish_id` and `offset`, plus `http_status` for an answer and
`chunk_attempts` for a lost connection.

Before the run that sends the last chunk, every upload failure carries
`possibly_published = false`: TikTok publishes a `FILE_UPLOAD` only once it holds every byte. See
[Duplicate safety](#duplicate-safety).

### Other failures raised by the driver

| Situation | Exception / reason |
|---|---|
| No `access_token` | Permanent `Misconfigured` (`missing_credentials`) |
| `expires_at` within 60 s at publish | `InvalidToken` (see [Auth model](#auth-model)) |
| No media | Permanent `Validation` |
| Direct post without a privacy level | Permanent `Validation` |
| Branded content with `SELF_ONLY` | Permanent `InvalidContent` |
| Privacy level not allowed, no usable fallback | Permanent `InsufficientPermissions` |
| Direct video longer than the creator's maximum | Permanent `MediaRejected` |
| Pull URL not https (at publish) | Permanent `MediaRejected` |
| `FILE_UPLOAD` of a remote video with no gateway that downloads it | Permanent `Misconfigured` |
| Empty or unreadable local video | Permanent `MediaRejected` |
| A pull init answered 2xx without `publish_id` | Permanent `OutcomeUnknown`, `possibly_published = true` |
| A `FILE_UPLOAD` init answered without `upload_url` | A fresh publish, within `max_restarts` |
| A continuation without a `publish_id` (a v1.0 init that returned none) | Permanent `OutcomeUnknown`, `possibly_published = true` |
| An unknown phase or an incomplete init or upload state | Permanent `Unknown` (an incomplete upload state restarts first) |

Every failed response is logged (`[SocialPoster] tiktok API error: ...`) with tokens redacted. Turn
it off with `social.error_logging`, or route it with `social.error_log_channel`.

### Daily caps and `retry_at`

`spam_risk_too_many_posts` (the creator's daily post cap) and `reached_active_user_cap` (your
client's daily quota of active publishing users) are `PermanentException(RateLimited)` wherever they
come back (`creator_info`, the init, or a `FAILED` status), with:

- `retry_after_hint`: 86400 (`TikTokFailReason::DAILY_CAP_SECONDS`);
- `retry_at`: now plus that hint, ISO-8601 UTC, e.g. `2026-11-20T09:15:00Z`.

The job never waits for the reset. Read `retry_at` from `PostFailed` and dispatch again then; the 24 h
is an estimate, since TikTok does not say when the cap resets.

```php
use SocialPoster\Enums\FailureReason;
use SocialPoster\Events\PostFailed;

Event::listen(function (PostFailed $event) {
    if ($event->exception->reason === FailureReason::RateLimited && isset($event->exception->context['retry_at'])) {
        // reschedule the post for $event->exception->context['retry_at']
    }
});
```

TikTok also limits each token per minute: 6 init requests
([TikTok: direct post](https://developers.tiktok.com/docs/en/content-posting-api-reference-direct-post),
[photo post](https://developers.tiktok.com/docs/en/content-posting-api-reference-photo-post)) and 30
status reads. These come back as `rate_limit_exceeded` and are waited out as above.

---

## Duplicate safety

The non-idempotent call is the init. TikTok offers no way to look an init up, so the driver decides
from what it knows whether an init may have created a post.

| Situation | What the driver does |
|---|---|
| `publish()`: creator_info, URLs, the local copy | Nothing is created on TikTok; a failure is clean |
| Pull init: the answer was lost after the request may have been sent (a read timeout, a reset), a code-less 5xx, a worker that died or timed out, a queue redelivery, a store row still `committing` | The job re-runs the step with `_recovering`. The driver does **not** send the init again: `PermanentException(OutcomeUnknown)` with `possibly_published = true`, context `transfer` and `target` (`direct_post` or `drafts`). Check the profile (direct) or the inbox (drafts), or the `post.publish.*` webhooks, before posting again |
| Pull init answered 2xx without `publish_id` | The same `OutcomeUnknown` |
| Any init that never left the client (DNS, refused, TLS, connect timeout) | Retried as a clean try |
| Any init refused with an error code, 429 or 503 | Sent again, up to `init_retries`: TikTok created nothing |
| `FILE_UPLOAD` init interrupted | Sent again: an upload task that never receives bytes never publishes. The earlier task is orphaned (whether it counts against the 5 pending drafts is open live check 5) |
| Upload runs before the one that sends the last chunk | Not committed. A failure that ends the publish there (a chunk 400; a 403/404 once the restarts are used up; 5xx or lost connections once the retries are used up; the deadline; a killed worker) ends in `PostFailed` with `possiblyPublished = false`, and the idempotency key is forgotten, so the same post can be dispatched again |
| The run that sends the last chunk, and every run after it | Committed: a failure is reported as possibly published. A single-chunk upload is committed from its first run |
| `await_publish`, `await_public_id` | Only read the status of the kept `publish_id`; nothing is created again |
| A fresh publish (restart) | Only after TikTok reported `FAILED` with `internal`, `video_pull_failed` or `photo_pull_failed` before `PUBLISH_COMPLETE`, or when the upload task is gone or inconsistent. Never after `PUBLISH_COMPLETE` |

What this means for your code:

- Keep the `publish_id` (`payload['publish_id']`, always set). It is the key of TikTok's webhooks and
  of `status/fetch`, and the only handle of a draft, a private post or a public post whose video id
  is still pending.
- `PostFailed` with `possiblyPublished === true` (`OutcomeUnknown`, `ProcessingTimeout`, a status
  read refused after the init) means: check the account before posting again.
- Keep an idempotency store bound (the default `CacheIdempotencyStore`, or
  `DatabaseIdempotencyStore`) and give the publish your own `->idempotencyKey()`, so a repeated
  dispatch replays the recorded result. Pass `open_id` so the key does not change when the token is
  refreshed.

---

## Webhooks

TikTok's webhooks are the only way to hear about a post after the chain has ended: a video id that
arrived after `public_id_wait`, a post that stops being public, or a creator who revokes your app.
The package ships helpers, not a route.

```php
use Illuminate\Http\Request;
use SocialPoster\Platforms\TikTok\{TikTokWebhook, TikTokWebhookEvent};

Route::post('/webhooks/tiktok', function (Request $request) {
    $raw = $request->getContent(); // the raw body: re-encoded JSON does not match the signature

    if (! TikTokWebhook::verify($raw, $request->header(TikTokWebhook::SIGNATURE_HEADER), config('services.tiktok.client_secret'))) {
        abort(401);
    }

    $event = TikTokWebhook::parse($raw);

    match (true) {
        $event->is(TikTokWebhookEvent::PUBLICLY_AVAILABLE) => /* store $event->postId for $event->publishId */ null,
        $event->is(TikTokWebhookEvent::NO_LONGER_PUBLICLY_AVAILABLE) => /* mark $event->publishId removed */ null,
        $event->is(TikTokWebhookEvent::PUBLISH_FAILED) => /* $event->failureReason(), $event->isRestartableFailure() */ null,
        $event->is(TikTokWebhookEvent::AUTHORIZATION_REMOVED) => /* disconnect the account whose open_id is $event->userOpenId */ null,
        default => null,
    };

    return response()->noContent();
});
```

`TikTokWebhook::verify(string $rawBody, ?string $signatureHeader, string $clientSecret, int $tolerance = 300, ?int $now = null): bool`:

- the header (`Tiktok-Signature`) has the form `t=<unix time>,s=<hex>`; `s` is the HMAC-SHA256 of
  `"{t}.{raw body}"` keyed with your app's client secret, compared in constant time; several `s=`
  values are accepted;
- a timestamp more than `$tolerance` seconds from now, in either direction, is refused (replays);
  `$tolerance <= 0` turns the check off;
- a missing or malformed header, or an empty secret, returns `false`; it never throws.

`TikTokWebhook::sign($rawBody, $clientSecret, ?int $timestamp = null)` builds a header, for tests.
`TikTokWebhook::parse($rawBody)` decodes the body, including the `content` field TikTok sends as a
JSON string, and throws `InvalidArgumentException` when the body is not a JSON object with an
`event`.

| Constant | `event` | Fields |
|---|---|---|
| `AUTHORIZATION_REMOVED` | `authorization.removed` | `userOpenId`, `reason` (TikTok's numeric reason, as a string). `failureReason()` is `InvalidToken` |
| `PUBLISH_INBOX_DELIVERED` | `post.publish.inbox_delivered` | `publishId` |
| `PUBLISH_COMPLETE` | `post.publish.complete` | `publishId` |
| `PUBLICLY_AVAILABLE` | `post.publish.publicly_available` | `publishId`, `postId` (the video id); `postUrl($username)` builds the permalink |
| `NO_LONGER_PUBLICALY_AVAILABLE`, `NO_LONGER_PUBLICLY_AVAILABLE` | `post.publish.no_longer_publicaly_available` (TikTok's spelling) | `publishId`, `postId` |
| `PUBLISH_FAILED` | `post.publish.failed` | `publishId`, `reason` (a `fail_reason`); `failureReason()`, `failureMessage()` and `isRestartableFailure()` use the [`fail_reason` table](#failed-status-fail_reason) |

Every event also has `clientKey`, `createTime`, `publishType`, `content` (decoded) and `raw`, plus
`isPublishEvent()` and `isFailure()` (`post.publish.failed` or `authorization.removed`).

TikTok documents the removal event as `post.publish.no_longer_publicaly_available`. `$event->event`
keeps the string TikTok sent, and `is()` treats both spellings as the same event, so test events with
`$event->is(...)` rather than comparing `$event->event`. Store each creator's `open_id` to match
`authorization.removed`.

---

## Configuration (`social.api.tiktok`)

| Key | Default | Effect |
|---|---|---|
| `send_brand_defaults` | `true` | Send `brand_content_toggle` / `brand_organic_toggle` as `false` on direct posts when the options leave them `null` |
| `max_wait` | `3900` (at least 60) | Seconds TikTok may take: the deadline, counted from the init, and again from the end of a `FILE_UPLOAD` |
| `public_id_wait` | `600` | Seconds to wait for the public video id of a direct public post after `PUBLISH_COMPLETE`. `0` = do not wait |
| `public_id_poll_interval` | `30` (at least 5) | Seconds between those reads |
| `poll_interval` | `15` (at least 1) | First status-read interval; doubles every 4 reads |
| `poll_max_interval` | `120` | Longest status-read interval |
| `init_retries` | `4` | Re-sends of an init TikTok refused with an error code (or 429/503) |
| `max_restarts` | `2` | Fresh publishes after a restartable `FAILED` or a lost upload task (also capped by `social.max_restarts`) |
| `chunk_size` | `10485760` | Chunk size for files above 64 MiB, clamped to 5,242,880 to 67,108,864 (commented out in the published file) |
| `chunk_attempts` | `3` | Attempts per chunk on 5xx and connection errors within a run |
| `chunk_retry_ms` | `2000` | Wait before a chunk retry, times the attempt number |
| `video_title_limit` | `2200` | Direct video caption limit (commented out in the published file) |
| `photo_title_limit` | `90` | Photo `->title()` limit |
| `photo_description_limit` | `4000` | Photo caption limit |
| `photo_max_bytes` | `20971520` | Photo size limit |
| `photo_max_width`, `photo_max_height` | `null` | Photo dimension limits; `null` = not checked |
| `video_max_bytes` | `4294967296` | Video size limit |
| `video_min_duration`, `video_max_duration` | `3`, `600` | Seconds |
| `video_codecs` | `['h264', 'hevc', 'vp8', 'vp9']` | Accepted codecs |
| `video_min_frame_rate`, `video_max_frame_rate` | `23`, `60` | fps |
| `timeout` | `social.http_timeout` (60) | Read timeout (s) of API calls. Not in the published file |
| `connect_timeout` | `10` | Connect timeout (s), chunk `PUT`s included. Not in the published file |
| `upload_timeout` | `social.upload_timeout` (300) | Read timeout (s) of one chunk `PUT`. Not in the published file |
| `upload_run_seconds` | `240` | Seconds one job run keeps uploading before it hands over. Also sizes the derived job timeout. Not in the published file |

Related global keys: `social.async.max_wait_per_platform.tiktok` (3900; used only when no deadline is
declared, as for v1.0 continuations), `social.max_restarts` (3), `social.tries` (5) and
`social.backoff`, `social.max_recheck_after` (900), `social.sync.max_wait` (120),
`social.http_timeout`, `social.upload_timeout`, `social.job_timeout`, `social.gateway`,
`social.transformer`, `social.media.rehost`, `social.temp_dir`, `social.credentials_resolver`,
`social.authorizers.tiktok`, `social.idempotency`, `social.error_logging` and
`social.error_log_channel`.

Temporary files: a video downloaded for `FILE_UPLOAD` is kept between runs as
`sp_tiktok_<16 hex>` in the temp directory. It is deleted when the upload ends, when the init is
refused for good or gives up (`init_retries`, a second token rejection), and on a restart. Your own
local file is never deleted. Schedule `social-poster:prune-temp` daily: it also removes stale
`sp_tiktok_*` copies (older than `--hours`, 24) left by a chain the job ended.

---

## Extending the driver and v1.0 continuations

- `directPostInfo(PreparedPost $post, TikTokOptions $options, ?array $creator, bool $video): array`
  keeps its v1.0 signature, and an override is still used for direct posts.
- The v1.0 protected helpers `initVideo()`, `initPhoto()`, `putChunks()` and `awaitPublish()` were
  removed by the phased flow: overrides of them are no longer called. Override
  `prepareVideo()`, `preparePhoto()`, `prepareFileUpload()`, `putChunk()` or `wait()` instead.
- Other protected hooks: `draftPhotoInfo()`, `resolvePrivacy()`, `queryCreatorInfo()`,
  `chunkPlan()`, `classifyError($response, array $sent = [])`.
- `utf16Length()` is deprecated; captions are counted with `Support\Text\Utf16Counter`. The
  `$captionLimit` property is 2200.
- Continuations queued by v1.0 still resume: phase `await_publish` keeps its name. Such a state
  declares no deadline (the job then uses `social.async.max_wait_per_platform.tiktok`), and a v1.0
  direct post has no username or privacy in its state: it gets the public id when TikTok reports it
  at once, without a URL, and never waits for it. A v1.0 state with an empty `publish_id` ends as
  `OutcomeUnknown` with `possibly_published = true`.
- `TikTokOptions` serialised by v1.0 unserialise with the 1.1 defaults for the new properties.

---

## Known limitations

- No first comment, no scheduling, no text-only posts. Alt text and video thumbnails are not sent.
- Draft videos carry no caption or options; the creator adds them in the TikTok app.
- An interrupted pull init cannot be verified and is never repeated: it is reported as
  `OutcomeUnknown` (possibly published), including after a code-less 5xx or a read timeout on the init.
- A `FAILED` status after `PUBLISH_COMPLETE` is reported with `possiblyPublished = true` and is never
  published again; see [`fail_reason`](#failed-status-fail_reason).
- The public video id and permalink exist only for direct `PUBLIC_TO_EVERYONE` posts, once moderation
  has passed and within `public_id_wait`. Every other result carries the `publish_id`. A post that
  later stops being public is reported only by webhook.
- Privacy refusals are `InsufficientPermissions` with `reconnect = false`: an app that branches on
  `$e->reason->requiresReconnect()` alone still sees `true`; use `$e->needsReconnect()`.
- Aspect ratio and photo dimensions are not checked by default. Duration, dimensions, codec and frame
  rate need metadata from the inspector or `Media` meta.
- A `FILE_UPLOAD` must finish within the hour TikTok gives the `upload_url`, and within `max_wait` of
  the init. A re-sent `FILE_UPLOAD` init leaves an orphaned upload task on TikTok.
- `expires_at` is checked once, at `publish()`, and tokens are never refreshed.
- The brand toggles are sent as `false` by default; whether an unaudited client accepts them is not
  confirmed (open live check 1). Set `send_brand_defaults` to `false` if TikTok refuses them.
- With `->withoutValidation()`, `publish()` does not refuse a document, or a post mixing a video with
  images; TikTok then rejects the request.

---

## Open live checks

These behaviours are covered by fixtures but not yet confirmed against the live API. Run
`tests/Live/TikTokLiveTest.php` with `SOCIAL_LIVE_TIKTOK_TOKEN` and `SOCIAL_LIVE_TIKTOK_OPEN_ID`, plus
`SOCIAL_LIVE_TIKTOK_IMAGE_URL` (on a domain verified for the app) and `SOCIAL_LIVE_PUBLISH=1` for the
check that sends a draft photo. Drafts count against the 5 pending shares per 24 hours. See
`tests/Live/README.md`.

1. `DIRECT_POST` with `brand_content_toggle=false` and `brand_organic_toggle=false` on an unaudited
   (`SELF_ONLY`) client, for video and photo. Commit 18162da saw `invalid_params` there; production (an
   audited client) sends them.
2. A `MEDIA_UPLOAD` photo without caption or title: TikTok accepts `post_info` as `{}`. Automated.
3. The upload host's `Content-Range` format on 416 (`bytes 0-N/size` or `bytes */size`), and whether a
   re-sent chunk TikTok already holds answers 416, 206 or 201.
4. Whether the upload host ever answers 200 instead of 206 or 201. A final 200 without
   `Content-Range` is treated as complete.
5. Whether an orphaned `FILE_UPLOAD` init (one sent again after an interrupted init or a restart)
   counts against `spam_risk_too_many_pending_share`.
6. The `url_ownership_unverified` fallback to `FILE_UPLOAD`, for `inbox/video/init` and `video/init`,
   on the same account.
7. Whether `publicaly_available_post_id` arrives as a JSON number or a string (both are handled), and
   how long after `PUBLISH_COMPLETE` it appears (`public_id_wait` is 600 s).
8. `is_aigc` in `post_info` of a `DIRECT_POST` `video/init`, and at the top level of a photo
   `content/init` (direct and draft).
9. Which photo dimensions trigger `picture_size_check_failed` ("1080p").
10. The webhook signature header is exactly `t=<ts>,s=<hex>` signed over `"{t}.{raw body}"`;
    `authorization.removed` has a numeric `content.reason`; the removal event's spelling
    (`no_longer_publicaly_available`). Check with `TikTokWebhook::verify()` on a real delivery.
11. `creator_info` accepts the empty body (automated), and its HTTP-200 error codes stop the publish
    (manual).
12. A rejected token comes back as HTTP 401 `access_token_invalid`, which drives the re-resolve path.
    Automated.
