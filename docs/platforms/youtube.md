# YouTube

| | |
|---|---|
| Platform key | `'youtube'` / `Platform::YouTube` |
| Driver | `SocialPoster\Platforms\YouTube\YouTubeDriver` (implements `SupportsFirstComment`) |
| API | YouTube Data API v3. Uploads go to `https://www.googleapis.com/upload/youtube/v3`, every other call to `https://www.googleapis.com/youtube/v3` |
| Auth | Google OAuth 2.0 access token, sent as `Authorization: Bearer` |
| Media transfer | Upload. The driver sends the bytes itself with Google's resumable protocol, in chunks |
| Options class | `SocialPoster\Platforms\YouTube\YouTubeOptions` (+ `YouTubePrivacy`), or `RawOptions(Platform::YouTube, [...])` |
| First comment | Yes, as a top-level comment thread (`commentThreads.insert`) |
| Async | Always. `publish()` opens the upload session, the upload runs in slices of about 45 s, then an optional finalize stage polls processing and sets the thumbnail and playlists |

Settings are read from `config('social.api.youtube.*')`. The package's `config/social.php` is merged
into your app's config at boot, and a key that is set nowhere falls back to the driver default shown on
this page. The code in `src/Platforms/YouTube/` is the reference.

---

## What you can publish

Every YouTube post is exactly one video upload.

| Post | Media | Notes |
|---|---|---|
| Video | 1 video | `->title()` is the video title (required), `->caption()` the description |
| Short | 1 vertical or square video, 3 minutes or less | YouTube decides what is a Short. The API has no Shorts flag; the driver only reports a guess (`likely_short`, `shorts_url`) |
| Scheduled video | 1 video | `YouTubeOptions::$publishAt`. Uploaded as private, YouTube makes it public at that time |
| Private or unlisted video | 1 video | `YouTubeOptions::$privacyStatus` |
| Video with a custom thumbnail | 1 video + 1 image | `YouTubeOptions::$thumbnail`, else the video's own `Media::video(thumbnail: ...)` |
| Video added to playlists | 1 video | `YouTubeOptions::$playlistIds` |

Text-only posts, several media items, images and documents are refused by validation (`media`, or
`media.N` for an item of the wrong type). There are no stories. Community
posts are not supported: the Data API has no endpoint to create them. The driver only calls
`videos.insert`; live streams are out of scope.

### Video

```php
use SocialPoster\Facades\SocialPoster;
use SocialPoster\ValueObjects\Media;

SocialPoster::on('youtube')
    ->using(['access_token' => $token, 'channel_id' => $channelId])
    ->title('Release 1.1 walkthrough')
    ->caption("What changed in 1.1.\n\n0:00 Intro\n1:20 Staged uploads")
    ->media(Media::video('https://cdn.example.com/videos/walkthrough.mp4'))
    ->post();
```

The description is optional. An empty caption sends no `description` field.

### Short

```php
SocialPoster::on('youtube')
    ->using($credentials)
    ->title('Dark mode in 20 seconds')
    ->media(Media::video(storage_path('app/clips/dark-mode.mp4'), meta: ['width' => 1080, 'height' => 1920, 'duration' => 20]))
    ->post();
```

Nothing is sent to mark a Short. When the video's width, height and duration are known (from the
`MediaInspector`, ffprobe by default, or from `meta`), the result payload says `likely_short = true`
for a video whose height is at least its width and whose duration is at most
`api.youtube.shorts_max_seconds` (180), and adds `shorts_url` (`https://www.youtube.com/shorts/{id}`).
`PostResult::$url` is always the `watch?v=` URL.

### Scheduled video

```php
use SocialPoster\Platforms\YouTube\YouTubeOptions;

SocialPoster::on('youtube')
    ->using($credentials)
    ->title('Launch day')
    ->media(Media::video($path))
    ->withOptions(new YouTubeOptions(publishAt: now()->addDay()->setTime(9, 0)))
    ->post();
```

`publishAt` is sent as `status.publishAt` in UTC (`2026-12-01T09:00:00Z`) and forces
`privacyStatus = private` when no privacy is given. The result has state `scheduled` and payload
`scheduled_for`. The 1.0 form, `extra: ['status' => ['publishAt' => '...']]`, still works and also forces
private.

### Private or unlisted video, with tags, category, thumbnail and playlists

```php
use SocialPoster\Platforms\YouTube\{YouTubeOptions, YouTubePrivacy};

SocialPoster::on('youtube')
    ->using($credentials)
    ->title('Internal demo')
    ->caption('For the team.')
    ->media(Media::video($path))
    ->withOptions(new YouTubeOptions(
        privacyStatus: YouTubePrivacy::Unlisted,
        tags: ['laravel', 'release notes'],
        categoryId: '28',
        thumbnail: Media::image('https://cdn.example.com/covers/demo.jpg'),
        playlistIds: [$playlistId],
    ))
    ->post();
```

The thumbnail and the playlists are follow-ups: they run once the upload is finished and never fail the
post. A problem is reported in the result payload (`thumbnail_warning`, `playlist_warnings`).

### Large videos

```php
SocialPoster::on('youtube')
    ->using($credentials)
    ->title('Conference keynote')
    ->media(Media::fromDisk('local', 'videos/keynote.mp4'))
    ->post();
```

The upload is spread over several job runs (see [Async behaviour](#async-behaviour)). How each source
is read:

| Source | How chunks are read |
|---|---|
| Local file path, or `Media::fromDisk()` on a local disk | Directly from the file. Its size must not change during the upload |
| Remote `http(s)` URL on a host that answers `Range: bytes=0-0` with `206` and a `Content-Range` total | One Range GET per chunk, with no copy. The size and `ETag` are recorded at `publish()` and checked on every chunk |
| Remote URL on a host without Range support, or with `api.youtube.range_reads = false` | Downloaded once, in `publish()` (within `social.download_timeout`, 120 s), and the copy is kept for the rest of the upload |
| `Media::fromDisk()` on a cloud disk (S3, R2...) | Stream-copied from the disk once, and the copy is kept for the rest of the upload |
| A custom gateway that does not extend `LocalMediaGateway` | Its `path()` is read every run; a copy it makes under its own naming is not kept |

A copy the gateway made is kept between runs as `sp_youtube_<16 hex>` in the temp directory
(`social.temp_dir`, default `storage/app/social-poster-tmp`). A later run reuses it while its size and
modification time match; it is deleted when the upload finishes, fails for good, or starts over from
`publish()`. A run on another worker host (or after the copy was removed) makes its own copy, so temp
disk use lasts the whole upload. A host that stops honouring ranges mid-upload falls back to such a
copy too. A chain the job ended (the deadline, retries used up, a killed worker, a run that moved to
another worker host) can leave a copy behind: schedule `social-poster:prune-temp`, which deletes
`sp_youtube_*` copies older than `--hours` (24).

Source requests never carry the YouTube token, follow the `social.media.*` URL policy (private
networks, allowed hosts) and re-check every redirect (3 at most).

A remote URL read in ranges must stay valid for the whole upload, which can last the upload window:
`max(3600, size / 512 KiB + 900)` seconds, or `api.youtube.upload_window`. Notion file URLs and S3
presigned URLs typically expire after 1 hour. A source that answers 401, 403 or 410 mid-upload fails
the post (see [Error handling](#error-handling)). For signed URLs that expire (Notion, S3 presigned),
set `api.youtube.range_reads = false`: the video is downloaded once when the upload starts, while the
URL is fresh, and every run reads the kept copy. This needs temp disk space for the whole file for the
length of the upload (on each worker host that runs part of it; a run on another host downloads it
again, which needs the URL to still be valid). `Media::fromDisk()` or a long-lived URL works too.

---

## Credentials

| Key | Required | Meaning |
|---|---|---|
| `access_token` | yes | Google OAuth 2.0 access token. Google access tokens last about 1 hour |
| `channel_id` | recommended | The channel's id (`UC...`). It is the account identity in idempotency keys, and the `channelId` of first comments |
| `expires_at` | optional | When the token expires: unix seconds or milliseconds, an ISO-8601 string, or a `DateTimeInterface` |
| `scopes` | optional | The granted scopes, as a list or a space- or comma-separated string. Short names (`youtube.upload`) and full URLs (`https://www.googleapis.com/auth/youtube.upload`) both work |

A missing `access_token` fails with `PermanentException(Misconfigured)` and context
`missing_credentials`. The check runs at the start of `publish()`, of every upload run and of
`addFirstComment()`, before any request, and never in `validate()`. The finalize stage never fails over
credentials (see [Async behaviour](#async-behaviour)).

```php
use SocialPoster\ValueObjects\Credentials;

Credentials::make([
    'access_token' => $token,
    'channel_id' => $channelId,
    'expires_at' => $expiresAt,
    'scopes' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.force-ssl',
]);

// Or keep the token out of the queue payload and resolve it on every job run:
Credentials::ref('social_account:42', ['channel_id' => $channelId]);
```

Without `channel_id` (or an `account` credential, or a `ref()`), the idempotency key has no account
part: it is scoped by platform and `->idempotencyKey()` only, so two channels dispatched with the same
key share one record. The token is never used as the identity.

### Token lifetime

The driver never refreshes tokens. `publish()`, every upload run and every comment refuse a token
whose `expires_at` is less than 60 s away, before spending a call:

- no custom `CredentialsResolver` bound: `PermanentException(InvalidToken)` with `token_expired = true`
  and `expires_at`;
- a custom resolver bound (`social.credentials_resolver`): `TemporaryException(InvalidToken)` with
  `reresolve = true`. The job resolves the credentials again and retries once in the same run; a second
  refusal is final.

A 401 from Google is handled the same way: permanent without a custom resolver, one retry with
re-resolved credentials with one. That retry reuses the same upload session.

An upload that lasts longer than the token needs a resolver that returns a fresh token on every run.
Without one, the upload fails with `InvalidToken` when the token expires, and a new dispatch starts a new
upload session.

```php
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\Credentials;

final class FreshTokenResolver implements CredentialsResolver
{
    public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
    {
        // Your code: look the connection up by $dispatched->reference() and
        // refresh its token when it expires within a few minutes.
        $connection = app(Connections::class)->fresh($dispatched->reference());

        return Credentials::make([
            'access_token' => $connection->accessToken,
            'channel_id' => $connection->channelId,
            'expires_at' => $connection->expiresAt,
        ]);
    }
}

// config/social.php
'credentials_resolver' => FreshTokenResolver::class,
```

### Auth model

- Every request carries `Authorization: Bearer <access_token>`, including each chunk sent to the upload
  session. If `config('social.authorizers.youtube')` names an `AuthorizesRequests` class, requests are
  built from it instead.
- The session URI Google returns must be `https` on the upload endpoint's host or on `googleapis.com`
  / `*.googleapis.com`. Any other host is refused with `PermanentException(Misconfigured)` and context
  `session_host`, so the token is never sent there.
- Requests to the media host (Range GETs, downloads) never carry the token.

### Scopes

`SocialPoster::requiredScopes()` returns the groups the driver checks:

| Feature | Scopes (any one of each group) |
|---|---|
| `Feature::Publish` | `youtube.upload`, `youtube`, `youtube.force-ssl` or `youtubepartner` |
| `Feature::Comment` | `youtube.force-ssl` |
| Playlists (`playlistIds`) | `youtube`, `youtube.force-ssl` or `youtubepartner`. `youtube.upload` is not enough. Checked before each insert when `scopes` is known |

```php
use SocialPoster\Enums\{Feature, Platform};

SocialPoster::requiredScopes(Platform::YouTube, Feature::Comment);
// [['https://www.googleapis.com/auth/youtube.force-ssl', 'youtube.force-ssl']]
```

- With `scopes` in the credentials, a missing upload scope is a validation error under
  `credentials.scopes`. Without `scopes`, nothing is checked at validation.
- With `scopes` known and no playlist scope, no `playlistItems.insert` is sent and the payload carries a
  `playlist_warnings` entry for each playlist.
- A token for a Google account without a YouTube channel is refused by Google with
  `youtubeSignupRequired` (`AccountRestricted`).
- App review: an app that asks other Google users for these scopes goes through Google's OAuth app
  verification first. Quota above the default allocation requires Google's YouTube API Services audit
  ([Google: quota and compliance audits](https://developers.google.com/youtube/v3/guides/quota_and_compliance_audits)).

### Quota

Each call spends quota of the Google Cloud project behind the token
([Google: quota](https://developers.google.com/youtube/v3/getting-started#quota)):

| Call | When | Cost |
|---|---|---|
| `videos.insert` (opening an upload session) | `publish()`, and each session restart | One call of the separate `videos.insert` bucket (100 a day per project by default) |
| `videos.list?part=status,processingDetails` | Each processing poll | 1 unit |
| `playlistItems.list` | Before an insert on a run that may repeat an earlier one | 1 unit per playlist |
| `playlistItems.insert` | Each playlist | 50 units |
| `commentThreads.insert` | The first comment | 50 units |
| `commentThreads.list?part=snippet&videoId=...&order=time` | A first comment that is a recovery (an earlier attempt's answer was lost) | 1 unit |
| `channels.list?part=id&mine=true` | First comment without `channel_id` in the credentials or in the upload response | 1 unit |

Daily quotas reset at midnight Pacific Time. Exhausting them fails the post with a `retry_at` (see
[Quotas and rate limits](#quotas-and-rate-limits)).

---

## Typed options

```php
use SocialPoster\Platforms\YouTube\{YouTubeOptions, YouTubePrivacy};

new YouTubeOptions(
    privacyStatus: YouTubePrivacy::Unlisted,
    tags: ['laravel', 'php'],
    categoryId: '28',
    madeForKids: false,
    notifySubscribers: false,
    containsSyntheticMedia: false,
    defaultLanguage: 'en',
    waitForProcessing: true,
);
```

`YouTubeOptions` passed to another platform in a fan-out is ignored there, and options for another
platform never reach YouTube.

| Property | Type | Default | Sent as | Effect and rules |
|---|---|---|---|---|
| `privacyStatus` | `?YouTubePrivacy` | `null` | `status.privacyStatus` | `null`: public, or private when scheduled. `Public` or `Unlisted` together with a publish time is a validation error (`publishAt`) |
| `tags` | `string[]` | `[]` | `snippet.tags` | Not sent when empty. No empty tag, no `<` or `>`, at most 500 in total counted as below (`tags`) |
| `categoryId` | `?string` | `null` | `snippet.categoryId` | Numeric, e.g. `'22'` for People & Blogs (`categoryId`). `null` leaves the choice to YouTube |
| `madeForKids` | `bool` | `false` | `status.selfDeclaredMadeForKids` | Always sent. `true` means no first comment |
| `thumbnail` | `?Media` | `null` | `thumbnails.set` after the upload | A `.jpg`, `.jpeg` or `.png` image, or one without an extension (sent as `application/octet-stream`), at most `thumbnail_max_bytes` (50 MB), MIME `image/jpeg`, `image/png` or `application/octet-stream` when known (`thumbnail`). Wins over the video's own cover |
| `extra` | `array` | `[]` | see below | Raw fields and query parameters |
| `publishAt` | `?DateTimeInterface` | `null` | `status.publishAt`, UTC `Y-m-d\TH:i:s\Z` | Must be in the future (`publishAt`). Forces a private upload |
| `notifySubscribers` | `bool` | `true` | `notifySubscribers=false` (query) | Sent only when `false` |
| `containsSyntheticMedia` | `?bool` | `null` | `status.containsSyntheticMedia` | The "altered or synthetic content" disclosure. Not sent when `null` |
| `embeddable` | `?bool` | `null` | `status.embeddable` | Not sent when `null` |
| `license` | `?string` | `null` | `status.license` | `'youtube'` or `'creativeCommon'` (`YouTubeOptions::LICENSES`, key `license`) |
| `defaultLanguage` | `?string` | `null` | `snippet.defaultLanguage` | Language of the title and description, e.g. `'fr'`. Required when `extra` has `localizations` |
| `defaultAudioLanguage` | `?string` | `null` | `snippet.defaultAudioLanguage` | Language spoken in the video |
| `playlistIds` | `string[]` | `[]` | one `playlistItems.insert` each | Non-empty strings (`playlistIds`). Trimmed, duplicates dropped. Failures are warnings |
| `waitForProcessing` | `bool` | `false` | — | Report `Published` only once YouTube finished processing the video, or failed it (see [Async behaviour](#async-behaviour)) |

The positional order of the first six parameters is the 1.0 one. Validation messages from the options
land under the keys in parentheses (no `options.` prefix).

`YouTubePrivacy` cases: `Public` (`public`), `Unlisted` (`unlisted`), `Private` (`private`).

Helpers: `scheduledAt(): ?string` (the UTC string sent, from `publishAt` or the 1.0 `extra` form),
`isScheduled(): bool`, `resolvedPrivacy(): YouTubePrivacy`, `tagsLength(): int`.

### The `extra` passthrough

`YouTubeOptions::$extra`, or `RawOptions(Platform::YouTube, [...])`, adds what the class does not
model:

- `extra['snippet']` and `extra['status']` are merged into those objects (`publicStatsViewable`...).
  Keys the driver sets win: the title, the description, every typed option that is set, and always
  `privacyStatus` and `selfDeclaredMadeForKids`.
- The other writable parts, `recordingDetails`, `localizations`, `paidProductPlacementDetails` and
  `brandPartner`, are sent as given. The `part` parameter is built from the parts present.
- `extra['query']` holds `videos.insert` query parameters (scalars; booleans become `true` / `false`).
  `uploadType` and `part` cannot be overridden. `onBehalfOfContentOwner` requires
  `onBehalfOfContentOwnerChannel` (validation key `extra.query`) and is also sent to `videos.list`,
  `thumbnails.set`, `playlistItems.insert` and `playlistItems.list`. It is not sent to
  `commentThreads.insert` or `channels.list`.
- `localizations` without a default language (typed or `extra['snippet']['defaultLanguage']`) is a
  validation error (`extra.localizations`).
- Any other top-level key is not sent. It is listed in the result payload as `ignored_extra_parts`.

```php
new YouTubeOptions(
    defaultLanguage: 'en',
    extra: [
        'status' => ['publicStatsViewable' => false],
        'recordingDetails' => ['recordingDate' => '2026-11-02T00:00:00Z'],
        'localizations' => ['fr' => ['title' => 'Démo', 'description' => 'Pour l’équipe.']],
    ],
);
```

With `RawOptions` there are no typed options: the video is uploaded as public and not made for kids,
since those driver keys win over `extra`. Use `YouTubeOptions` to schedule.

---

## Limits

### Text

| Field | Limit | Config |
|---|---|---|
| Title (`->title()`) | Required. At most 100 characters (`mb_strlen`, trimmed). No `<` or `>` | Fixed (`$titleLimit`) |
| Description (`->caption()`) | At most 5000 bytes of UTF-8 and 5000 characters, counted on the trimmed text. No `<` or `>` | `description_max_bytes` (5000); the character limit is fixed |
| Tags | At most `YouTubeOptions::TAGS_MAX_LENGTH` (500) in total: the length of every tag, plus 1 for each comma between tags, plus 2 for each tag that contains a space (YouTube quotes it). `['ab', 'c d', 'e']` counts 2 + 1 + 5 + 1 + 1 = 10 | Fixed |
| First comment | At most 10,000 characters (`mb_strlen`) | Fixed |

Accents and emoji take 2 to 4 bytes each, so a description of 2,600 `é` (5,200 bytes) is refused.
Validation errors land under `title`, `caption`, `comment`, `media` / `media.N`, `thumbnail`,
`extra.query`, `extra.localizations`, `credentials.scopes`, and the option keys of the table above
(`tags`, `categoryId`, `license`, `publishAt`, `playlistIds`).

### Media

| | Video | Thumbnail (`YouTubeOptions::$thumbnail`) |
|---|---|---|
| Count | Exactly 1 per post | 0 or 1 |
| Formats | `mov`, `mp4`, `m4v`, `avi`, `wmv`, `flv`, `webm`, `mpg`, `mpeg`, `3gp` | `jpg`, `jpeg`, `png`, or no extension |
| Size | `max_video_bytes` = 274,877,906,944 (256 GB) | `thumbnail_max_bytes` = 52,428,800 (50 MB) |
| Duration | `max_duration` = 43,200 s (12 h) | n/a |

- Extensions are always checked. Size and duration are checked only when metadata is known: from the
  bound `MediaInspector` or from the `meta` array of `Media::video()` / `Media::image()`.
- Dimensions, aspect ratio, codec and frame rate are not checked.
- Channels that are not verified can upload up to 15 minutes. The driver cannot know whether a channel
  is verified, so it does not check this. YouTube rejects such a video during processing
  (`rejectionReason = length`), which the driver only sees when it waits for processing (see
  [Processing outcome](#processing-outcome)).
- The `Content-Type` of the upload follows the extension: `mov` `video/quicktime`, `avi`
  `video/x-msvideo`, `wmv` `video/x-ms-wmv`, `flv` `video/x-flv`, `webm` `video/webm`, `mpg`/`mpeg`
  `video/mpeg`, `3gp` `video/3gpp`, anything else `video/mp4`.

The video's own cover, `Media::video($source, thumbnail: $url)`, is used when `YouTubeOptions::$thumbnail`
is not set. It is never validated, so it can never refuse a post in a fan-out (1.0 ignored it). When
the upload finishes:

- a cover with a known extension other than `jpg`, `jpeg` or `png` (`webp`, `gif`...) is not sent; the
  payload carries `thumbnail_warning`;
- a cover URL without an extension is sent to `thumbnails.set` as `application/octet-stream`, and
  YouTube decides from the bytes;
- `.png` is sent as `image/png`, `.jpg` and `.jpeg` as `image/jpeg`.

No carousel exists on YouTube, so there are no item counts.

---

## First comment

`->withComment('...')` adds a top-level comment to the video once it is published, with
`commentThreads.insert` (`POST https://www.googleapis.com/youtube/v3/commentThreads?part=snippet`).

```php
SocialPoster::on('youtube')
    ->using(['access_token' => $token, 'channel_id' => $channelId, 'scopes' => $grantedScopes])
    ->title('Release 1.1 walkthrough')
    ->media(Media::video($path))
    ->withComment('Changelog: https://example.com/changelog')
    ->post();
```

| Condition | Behaviour |
|---|---|
| Length | 10,000 characters. Checked by the builder's validation before dispatch, under `comment`, and again by the comment job (`InvalidContent`) |
| Scope | `youtube.force-ssl`. With `scopes` in the credentials and the scope missing: skipped (`CommentSkipped`, reason `missing_scope`). Without `scopes`: attempted |
| Made for kids | No comment: `CommentSkipped`, reason `story` (the driver declares no comment rules) |
| Private or scheduled video | No comment: `CommentSkipped`, reason `scheduled`. This includes a public upload that YouTube made private (`privacy_warning`) |
| Delay | The larger of `social.comment_delay` (60) and `api.youtube.comment_delay` (60) seconds after the post is published |
| Video not commentable yet | `404 videoNotFound` is a `TemporaryException(NotFound)` with a 60 s retry and `video_processing = true`, retried within `social.comment_tries` (5) and `social.comment_backoff` |
| Channel | The `channel_id` credential, else `snippet.channelId` from the upload response (payload `channel_id`), else `channels.list?part=id&mine=true`. A token with no channel fails as `AccountRestricted` (`no_channel`) |
| Token | Refused before the call when `expires_at` is less than 60 s away, as for uploads |
| Quota | 50 units per comment (plus 1 for the lookup in a recovery) |
| Recovery | When an earlier attempt's answer was lost (a connection dropped after sending, a 5xx, a worker killed mid-call), the comment job passes `_recovering`. The driver first reads the video's 100 newest comments (`commentThreads.list`, `order=time`) and returns the channel's own top-level comment with the same text (payload `recovered = true`) instead of adding a second one. Nothing found: the comment is added. The list cannot be read: a temporary error is retried; a refusal fails as `OutcomeUnknown` with `possibly_published = true`, `recovering = true` and `lookup_reason` |

The result's id is the top-level comment id (the thread id when Google returns none), its URL
`https://www.youtube.com/watch?v={video}&lc={comment}`, and its payload `thread_id`, `comment_id`,
`channel_id` and `video_id` (and `recovered = true` for a comment found in a recovery). Outcomes arrive
as `CommentPublished`, `CommentFailed` or `CommentSkipped`.

Comment errors:

| Google reason | Exception / reason |
|---|---|
| `videoNotFound` (404) | Temporary `NotFound`, retry after 60 s |
| `commentsDisabled` | Permanent `InvalidContent`, `comments_disabled = true` |
| `forbidden` | Permanent `AccountRestricted`, `comments_forbidden = true` |
| `ineligibleAccount` | Permanent `AccountRestricted` |
| `commentTextTooLong`, `commentTextRequired`, `invalidCommentThreadMetadata`, `processingFailure` | Permanent `InvalidContent`, `field = comment` |
| `channelOrVideoIdMissing` | Permanent `Misconfigured` |
| 2xx without an id | Permanent `OutcomeUnknown`, `possibly_published = true`. Never retried |
| Anything else | The [error table](#error-handling) |

---

## Async behaviour

A YouTube publish is a chain of job runs. The job carries the state between runs; `PostProcessing`
fires for every wait (none of them is a commit).

| Stage (`state['stage']`) | What happens | Next |
|---|---|---|
| `publish()` | Checks the token. Measures the source (a 1-byte Range probe on a remote URL, else the file itself or one full copy). Opens the session: `POST .../upload/youtube/v3/videos?uploadType=resumable&part=...` with the metadata and `X-Upload-Content-Length` / `X-Upload-Content-Type`. No byte is sent yet | `upload`, at once |
| `upload` | On a run that may repeat earlier work, asks the session where it is first. Sends chunks of `chunk_bytes` (16 MiB) with `Content-Range`, each answered by `308` and the stored `Range`, for at most `upload_run_seconds` (45 s) | `upload` again at once, or the finished video |
| (upload finished) | Checks the video id and the upload outcome. Adds the video to playlists and sets the thumbnail when it need not wait | `Published`, or `finalize` when something is left |
| `finalize` | Polls `videos.list?part=status,processingDetails` while waiting for processing, then sets the thumbnail and retries a playlist or thumbnail call that deserves one retry | `finalize` again, or `Published` |

The `finalize` stage only exists when needed:

- `waitForProcessing` is `true`;
- a thumbnail is set and the video is a likely Short, or its shape is unknown: YouTube applies a
  Short's thumbnail only after processing;
- a thumbnail or playlist call failed in a way that deserves one retry (`404 videoNotFound`, 429,
  `uploadRateLimitExceeded`, a 401 with a custom resolver bound; for the thumbnail also 5xx and network
  errors).

Otherwise the video is reported `Published` by the run that sent the last chunk, without waiting for
processing.

### The upload stage

- The per-run budget starts once the source is ready. When a run must stage the source first (see
  [Large videos](#large-videos): the first run on a worker host, for a source that is not read in
  ranges), the copy does not count, so that run lasts the copy time plus up to 45 s plus the chunk in
  flight. Later runs reuse the kept copy.
- Between runs the continuation is dispatched with no delay. The next offset is kept in the state.
- A run that may repeat earlier work (a retry or a redelivery: `_attempt > 1` or `_recovering`) first
  sends an empty `PUT` with `Content-Range: bytes */{size}` to the session. `308` gives the stored range,
  `200`/`201` is the finished video, `404`/`410` means the session expired. See
  [Duplicate safety](#duplicate-safety).
- A connection lost mid-chunk, a 408 or a 5xx keeps the session: the next run asks the session where it
  is, after 5, 10, 20, 40, 80, then 160 s (or the server's `Retry-After`). After
  `max_upload_stalls` (6) such failures in a row, the next one is thrown as a `TemporaryException`
  (`ServerError`, or `Network` for a lost connection) with `session_kept = true`, and the job's own
  retries apply (`social.tries`, `social.backoff`).
- An expired session (404/410) is replaced by a new one, from byte 0, at most `max_session_restarts`
  (2) times. Each new session spends a `videos.insert` call.

### Deadlines

The driver declares a deadline on every step, and the job honours it before `social.async.max_wait*`:

- upload stage: the start of the upload plus the upload window, `max(3600, ceil(size / 524288) + 900)`
  seconds (one hour, or the time to move the file at 512 KiB/s plus 15 minutes), or
  `api.youtube.upload_window`. A session restart extends it;
- finalize stage: the end of the upload plus `processing_window` (3600) plus 600 seconds.

Past the deadline:

- during the upload, before the last chunk may have arrived: `PostFailed` with `Timeout`;
- after the last chunk may have arrived, or in the finalize stage: `PostFailed` with
  `ProcessingTimeout` and `possiblyPublished = true`.

### The finalize stage

- Processing is finished when `processingDetails.processingStatus = succeeded` or
  `status.uploadStatus = processed`. Definitive failures are described under
  [Processing outcome](#processing-outcome).
- The wait after the n-th poll is `processing_poll_seconds` (30) × (1 + floor(n / 5)): 30 s up to the
  4th poll, 60 s from the 5th, 90 s from the 10th, 120 s from the 15th. A failed or empty
  `videos.list` answer is polled again; it never fails the post.
- After `max_processing_polls` (20) polls (about 25 minutes of waits by default) or
  `processing_window` (3600 s) since the upload finished, whichever comes first, the video is
  published with `processing_warning`. A thumbnail is then set anyway, with a `thumbnail_warning` that
  YouTube may not apply it to a Short.
- Credentials: when the token is missing or expires within 60 s, the stage never fails the live video.
  With a token present and a custom `CredentialsResolver` bound, it waits up to 2 runs
  (`processing_poll_seconds` apart, state key `token_waits`) for the job to resolve a fresh one.
  Otherwise, or once those waits are spent, it publishes with `follow_up_warning`, skipping what was
  left.

### Results

The published `PostResult` has the video id (`idKind = video_id`), the URL
`https://www.youtube.com/watch?v={id}`, and a state from the privacy YouTube applied: `scheduled`
(publish time set), `private`, or `published` (public and unlisted). Payload keys:

| Key | When |
|---|---|
| `upload_status` | `status.uploadStatus` of the upload response (usually `uploaded`), updated by processing polls |
| `privacy_status` | The privacy YouTube applied, else the requested one |
| `privacy_warning` | YouTube applied another privacy than requested (for example, creator account settings can force private uploads) |
| `failure_reason`, `rejection_reason` | When the upload response has them |
| `channel_id` | `snippet.channelId` of the upload response |
| `scheduled_for` | The publish time of a scheduled video |
| `likely_short` | When the video's shape and length are known |
| `shorts_url` | When `likely_short` is true |
| `processing_status` | After a processing poll |
| `processing_warning` | Processing outlasted the polls |
| `thumbnail_warning`, `thumbnail_error_reason` | The thumbnail was not set (message, Google reason), the video's cover is not a JPG or PNG, or the thumbnail was set before processing finished |
| `playlist_items` | Playlist id => playlist item id (null when Google returned none) |
| `playlist_warnings` | Playlist id => why the video was not added, or may not have been |
| `follow_up_warning` | The token was missing or expired before the follow-ups finished |
| `ignored_extra_parts` | Top-level `extra` keys that were not sent |

### What `Pending` means for your code

- With `->post()`, nothing to do: the job dispatches a continuation for each step and ends with
  `PostPublished` or `PostFailed`. No worker sleeps on the upload.
- With `->postNow()`, the upload runs in-process to its end: upload slices are not charged to
  `social.sync.max_wait`, only to the upload window, so a large video blocks the request for the whole
  upload. The finalize stage's waits run while they fit `social.sync.max_wait` (120 s). Past the limit
  the publish is handed to the queue and `postNow()` returns a success with state `processing`, a null
  id and payload `handed_off = true`; the outcome arrives as `PostPublished` / `PostFailed`.
- On the `sync` queue connection there is no queue to hand over to: the finalize stage's waits must
  fit `social.sync.max_wait`, or the publish fails with a timeout. Use a real queue for YouTube.

How long it takes: the upload moves at the speed between your worker and Google, plus queue latency
between runs (one run per 45 s of sending). Unless `waitForProcessing` is set or a thumbnail waits for a
Short's processing, the post is reported as soon as the last chunk is accepted. Otherwise add
YouTube's processing time, polled as above.

### Queue and timeouts

| Call | Read timeout |
|---|---|
| Session open, session status queries, `videos.list`, `thumbnails.set`, playlists, comments | `api.youtube.timeout` (`social.http_timeout`, 60 s) |
| One chunk `PUT` | `api.youtube.upload_timeout` (`social.upload_timeout`, 300 s) |
| Range GETs and downloads from the media host | `social.download_timeout` (120 s), connect timeout 10 s |

The connect timeout of Google calls is `api.youtube.connect_timeout` (10 s). The job's run timeout is
`social.job_timeout`, or derived for YouTube as `max(300, 240 + 300) + 120 = 660` s with the default
settings (`api.youtube.upload_run_seconds` is read with 240 there unless you set it). Every queue
connection that runs the job needs a `retry_after` longer than that, or the queue hands a running upload
to a second worker; the service provider logs a warning at boot when it is not. The run that stages the
source (see [Large videos](#large-videos)) also takes the copy time: keep that inside the job timeout.
A run killed by the job timeout is failed, except an upload run, which the queue runs again after
`retry_after` (see [Duplicate safety](#duplicate-safety)).

---

## Error handling

Google errors look like `{error: {code, message, status, errors: [{reason, location, message}],
details: [{reason, ...}]}}`. The reason is read from `errors[0].reason`, else the first `ErrorInfo`
`details[].reason`, else `status`. The message is Google's `message`. The context holds `status`,
`http_status`, `error` (the error object), `operation` (`upload` or `comment`), and when present
`reason`, `google_status`, `error_info` (the `ErrorInfo` reasons) and `location`. Checks run in this
order:

| Google answer | Exception | `FailureReason` | Retry / action |
|---|---|---|---|
| `quotaExceeded`, `dailyLimitExceeded`, `variableTermExpiredDailyExceeded` | Permanent | `RateLimited` | Daily quota. Context `quota = daily`, `retry_at`, `retry_after_hint`. Reschedule after `retry_at` |
| `rateLimitExceeded`, `userRateLimitExceeded`, `concurrentLimitExceeded`, `limitExceeded`, `servingLimitExceeded`, `variableTermLimitExceeded`, `uploadRateLimitExceeded`, `RATE_LIMIT_EXCEEDED`, `RESOURCE_EXHAUSTED` | Temporary | `RateLimited` | After `Retry-After`, else 60 s |
| `uploadLimitExceeded` | Permanent | `RateLimited` | The channel's own upload limit. Context `upload_limit = true`, no `retry_at`. Reschedule later |
| `invalidTitle`, `invalidDescription`, `invalidTags`, `invalidCategoryId`, `invalidPublishAt`, `defaultLanguageNotSet`, `invalidRecordingDetails`, `invalidVideoGameRating`, `invalidVideoMetadata` | Permanent | `InvalidContent` | Context `field` (`title`, `description`, `tags`, `categoryId`, `publishAt`, `defaultLanguage`, `recordingDetails`, `videoGameRating`, `metadata`) |
| `mediaBodyRequired`, `invalidFilename`, `invalidImage` | Permanent | `MediaRejected` | — |
| `forbiddenPrivacySetting`, `forbiddenLicenseSetting` | Permanent | `InsufficientPermissions` | Context `field` (`privacyStatus`, `license`) and `reconnect = false`: a reconnect does not help, so no `CredentialsRejected`, and `errorSummary()` reports `user_fix` |
| `accessNotConfigured`, or `SERVICE_DISABLED`, `API_KEY_SERVICE_BLOCKED`, `CONSUMER_INVALID` (reason or `ErrorInfo`) | Permanent | `Misconfigured` | The Data API is off for the Google Cloud project. Context `developer_config = true`. An operator fix |
| `youtubeSignupRequired`, `ineligibleAccount`, any reason starting with `account` | Permanent | `AccountRestricted` | — |
| Comment-only reasons | | | See [First comment](#first-comment) |
| HTTP 401, or `authError`, `UNAUTHENTICATED`, `ACCESS_TOKEN_EXPIRED`, `ACCESS_TOKEN_TYPE_UNSUPPORTED`, `CREDENTIALS_MISSING` | Permanent | `InvalidToken` | Context `token_expired = true` for an expired token. With a custom resolver bound, retried once with re-resolved credentials. Reconnect |
| `insufficientPermissions`, `ACCESS_TOKEN_SCOPE_INSUFFICIENT` (reason or `ErrorInfo`) | Permanent | `InsufficientPermissions` | Context `missing_scope = true`. Reconnect with the scope |
| Anything else | Base table | 401 `InvalidToken`, 403 `InsufficientPermissions`, 404 `NotFound` (Permanent); 429 `RateLimited` and 408/425/5xx `ServerError` (Temporary, `Retry-After`); other `Unknown` (Permanent) | Non-JSON bodies land here too |

A Temporary failure is retried by the job up to `social.tries` (5), waiting the delay above (at most
3600 s) or `social.backoff`. A Permanent failure ends the publish with `PostFailed`.
`requiresReconnect()` is true for `InvalidToken` and `InsufficientPermissions`, and the job then also
fires `CredentialsRejected`, except for an exception with `reconnect = false` (`$e->needsReconnect()`
is then false). Every failed response is logged (`[SocialPoster] youtube API error: ...`)
with tokens redacted; turn it off with `social.error_logging` or route it with
`social.error_log_channel`.

### Upload session answers

| Session answer (chunk `PUT` or status query) | Action |
|---|---|
| `308` | Continue at the end of its `Range: bytes=0-N` plus one (0 when there is no `Range`) |
| `200` / `201` | The upload is finished: the body is the video resource |
| `404` / `410` | Session expired: a new session, at most `max_session_restarts` (2) times, then `PermanentException(NotFound)` with `session_expired = true` and `restarts`. After the last chunk may have arrived: see [Duplicate safety](#duplicate-safety) |
| `408`, `5xx`, lost connection | Keep the session and wait (see [The upload stage](#the-upload-stage)) |
| Any other status | Classified with the table above. A chunk refused with a 4xx other than 401, 403, 404, 410 and 429, in a run that has not asked the session yet, makes the run ask the session first (the offset may be stale) |

When the classified error is `videos.insert`'s own verdict (one of the field reasons above,
`mediaBodyRequired`, `invalidFilename`, `invalidImage`, a daily quota, or `uploadLimitExceeded`), the
exception carries `possibly_published = false`, even after a final chunk whose answer was lost: no
video was created. `PostFailed::$possiblyPublished` is false and the idempotency key is released.
Token, scope and unknown errors keep the job's default.

### Processing outcome

The upload response, and every processing poll, is checked. These outcomes fail the post with the
video id in the context (`video_id`, `upload_status`, `processing_status`, `rejection_reason`,
`failure_reason`) and `possibly_published = false`:

| Outcome | `FailureReason` |
|---|---|
| `uploadStatus = rejected`, `rejectionReason = duplicate` | `DuplicateContent` |
| `rejected` with `uploaderAccountSuspended` or `uploaderAccountClosed` | `AccountRestricted` |
| `rejected` with `length` | `MediaRejected`, `field = duration`. The channel is not verified and the video is longer than 15 minutes |
| `rejected` for any other reason (copyright, ...) | `MediaRejected` |
| `uploadStatus = failed` | `MediaRejected` |
| `uploadStatus = deleted` | `MediaRejected` |
| `processingStatus = failed` or `terminated` | `MediaRejected` |

Such a video never goes live, so the idempotency key is released: the same key can post again once
the cause is fixed, for example after verifying the channel for the 15-minute limit. Most rejections
are decided during processing, so the driver sees them only when it waits for processing
(`waitForProcessing`, or a thumbnail on a likely or unknown-shape Short). Otherwise the post is
reported `Published` with `upload_status = uploaded`.

### The video source

| Source answer during the upload | Exception / action |
|---|---|
| `206` with the expected range | The chunk is sent |
| `200` (ranges ignored), or a `206` with another range or length | The file is staged once and the copy is kept for the rest of the upload, and `range_reads` is turned off for the following runs |
| A size or `ETag` other than at `publish()`, or `416` | Permanent `MediaRejected`, `source_changed = true` |
| `401`, `403`, `410` | Permanent `MediaRejected`, `source_url_expired = true`, `http_status`, `url_host`, `media_download = true`. The message never contains the URL (its query string can hold a signature) |
| `408`, `425`, `429`, `5xx` | Temporary `Network`, after `Retry-After` when given |
| Any other status | Permanent `MediaRejected`, `http_status`, `url_host`, `media_download = true` |

The same 401/403/410 mapping applies when a run downloads the whole file. A source that fails at
`publish()` is reported by the media gateway as usual.

### Quotas and rate limits

- Daily quotas fail at once as `PermanentException(RateLimited)` with `quota = daily`, `retry_at`
  (the next midnight in `America/Los_Angeles`, as ISO-8601 UTC, e.g. `2026-11-20T08:00:00Z`) and
  `retry_after_hint` (seconds until then). The queue never waits for the reset: re-dispatch after
  `retry_at`.
- The channel's `uploadLimitExceeded` gives no reset time.
- Short-term limits are retried after `Retry-After`, else 60 s.

### Other failures raised by the driver

| Situation | Exception / reason |
|---|---|
| No `access_token` | Permanent `Misconfigured` (`missing_credentials`) |
| Token expires within 60 s | Permanent `InvalidToken` (`token_expired`); Temporary with `reresolve` when a custom resolver is bound |
| No video in the post | Permanent `MediaRejected`, `field = media` |
| Empty, missing or unreadable file | Permanent `MediaRejected` |
| Session open answered without a `Location` | Temporary `ServerError`, `stage = session` |
| Session URL on an unexpected host | Permanent `Misconfigured`, `session_host` |
| Upload state without a session or size | Temporary, `restart = true`: the job starts over, within `social.max_restarts` (3) |
| Unknown stage | Permanent `Unknown` |
| Finished upload without an id, or an id that is not 11 characters | Permanent `OutcomeUnknown`, `possibly_published = true` (`video_id` when there is one) |
| Finalize state without a video id | Permanent `OutcomeUnknown`, `possibly_published = true` |

---

## Duplicate safety

A video is created by the upload session, once the last byte has arrived. The driver never opens a
second session while the first one may have created the video, and it asks the session, not the
channel, what happened.

| Situation | What the driver does |
|---|---|
| A worker process died and the queue redelivered the job, or a run is retried after a Temporary failure | The run sends an empty `PUT` with `Content-Range: bytes */{size}` before any byte. `308`: continue at the stored offset. `200`/`201`: the finished video, no second upload |
| The job timeout killed a run | An upload run is queued with `failOnTimeout = false`: the queue runs it again once `retry_after` has passed, and that run asks the session first, so a video whose last chunk arrived is found and published, and an unfinished upload continues from the session's offset. If the queue gives up on it (the job's tries or `maxExceptions`), `PostFailed(OutcomeUnknown)` with `possiblyPublished = true`, and the key is kept, since the last kill may have hit the last chunk. Other runs are not retried: `PostFailed(Timeout)`, with `possiblyPublished = true` only when the chain was already committed (a stall after the last chunk, or the finalize stage). A run that has already sent chunks still hands the last chunk to a fresh run unless the chunk's `upload_timeout` plus one HTTP call fit in what is left of the job timeout, which saves the `retry_after` wait |
| A chunk's connection was lost, or it got 408/5xx | The session is kept, the offset forgotten, and the next run asks the session. When it was the last chunk, the wait is committed: a later failure is reported with `possiblyPublished = true`, unless it is one of the definitive refusals below |
| Every byte acknowledged, but no video in the answer | The run asks the session for the final answer; if there is still none, a committed wait |
| The session expired (404/410) before the last chunk | A new session from byte 0 (an unfinished session creates no video), at most 2 times |
| The session expired after the last chunk may have arrived | No new upload. `PermanentException(OutcomeUnknown)` with `possibly_published = true`, `session_expired = true`, `final_sent = true` and `upload_started_at`. Check the channel |
| The session answers with a `videos.insert` refusal | `possibly_published = false`: no video exists |
| YouTube rejects or fails the video in processing | `possibly_published = false`: the video never goes live |
| A playlist insert lost its answer (network, 408, 5xx, `backendError`, `internalError`) | Never retried: a playlist accepts the same video twice. Warning "may or may not have been added" |
| A repeated run adds the video to playlists | `playlistItems.list?playlistId=...&videoId=...` first (1 unit per playlist). An existing item is reused. When the check fails, the video is not added and the warning says it could not be checked |
| A thumbnail call failed | Retried once when it may help (network, 5xx, `videoNotFound`, 429): setting a thumbnail twice is harmless |
| A comment answered 2xx without an id | `OutcomeUnknown`, `possibly_published = true`, never retried. The comment job also records the comment under `{key}:comment` and skips one it already holds |

The driver does not search the channel for the video. After an expired session it cannot tell whether
the video exists.

What this means for your code:

- `PostFailed` with `possiblyPublished === true` (`OutcomeUnknown`, or `ProcessingTimeout`): check the
  channel before uploading again. The idempotency record is kept as `failed` for reconcile.
- `PostFailed` with `possiblyPublished === false`: the same idempotency key can post again.
- Keep a persistent idempotency store bound (the default `CacheIdempotencyStore` on a non-evicting
  cache, or `DatabaseIdempotencyStore`), so the session state survives a crashed worker, and give the
  publish your own `->idempotencyKey()`. Put `channel_id` in the credentials so the account part of the
  key survives token refreshes.

---

## Configuration (`social.api.youtube`)

| Key | Default | Effect |
|---|---|---|
| `chunk_bytes` | `16777216` | Chunk size (16 MiB), rounded down to a multiple of 262,144 (256 KiB), at least 256 KiB |
| `upload_run_seconds` | `45` | Seconds one run keeps sending chunks, counted once the source is ready. Commented out in the published config |
| `upload_timeout` | `social.upload_timeout` (300) | Read timeout (s) of one chunk `PUT` |
| `upload_window` | `null` | Seconds the whole upload may take. `null`: `max(3600, ceil(size / 524288) + 900)` |
| `max_session_restarts` | `2` | New sessions after an expired one |
| `max_upload_stalls` | `6` | Network/408/5xx failures in a row waited out before a `TemporaryException` |
| `range_reads` | `true` | Read remote videos chunk by chunk with HTTP Range requests. `false`: download remote videos once and keep the copy for the upload (for signed URLs that expire) |
| `thumbnail_max_bytes` | `52428800` | Thumbnail size limit (50 MB) |
| `max_video_bytes` | `274877906944` | Video size limit (256 GB) |
| `max_duration` | `43200` | Video duration limit (s) |
| `description_max_bytes` | `5000` | Description limit in UTF-8 bytes |
| `shorts_max_seconds` | `180` | Longest vertical or square video reported as `likely_short` |
| `max_processing_polls` | `20` | Processing polls before publishing with `processing_warning` |
| `processing_poll_seconds` | `30` | Base interval of processing polls (grows every 5 polls) and of token waits; at least 5 |
| `processing_window` | `3600` | Seconds after the upload before publishing with `processing_warning`; at least 60 |
| `comment_delay` | `60` | Minimum delay (s) before the first comment |
| `timeout` | `social.http_timeout` (60) | Read timeout (s) of every other Google call |
| `connect_timeout` | `10` | Connect timeout (s) of Google calls |

Related global keys: `social.job_timeout` (null: derived, 660 s for YouTube by default),
`social.tries` (5) and `social.backoff`, `social.max_restarts` (3), `social.download_timeout` (120),
`social.upload_timeout` (300), `social.http_timeout` (60), `social.sync.max_wait` (120),
`social.max_recheck_after` (900), `social.comment_delay` (60), `social.comment_tries` (5) and
`social.comment_backoff`, `social.credentials_resolver`, `social.authorizers.youtube`,
`social.gateway`, `social.media.*`, `social.idempotency`, `social.error_logging` and
`social.error_log_channel`. `social.async.max_wait_per_platform.youtube` (14400) does not apply to this
driver: every step declares its own deadline.

---

## Extending the driver and v1.0 compatibility

- In 1.0, `publish()` uploaded the whole file and returned `Published`. In 1.1 it returns a `Pending`
  after opening the session. The builder and the job handle this; code that calls the driver directly
  must call `resume()` with the state until it gets `Published`.
- `YouTubeOptions` keeps the 1.0 positional constructor (`privacyStatus`, `tags`, `categoryId`,
  `madeForKids`, `thumbnail`, `extra`); the new parameters come after them. Two defaults changed:
  `privacyStatus` is now `?YouTubePrivacy = null` (was `Public`; `null` still uploads as public unless
  scheduled), and `categoryId` is now `null` (was `'22'`).
- `Media::video(thumbnail: ...)` is now used as a fallback cover; 1.0 ignored it. It is never
  validated.
- `$resumable`: only the part before `?` is used; the query is built per request.
- `setThumbnail(PreparedPost, string $videoId, Media): ?string` is kept for subclasses (returns the
  warning, or null). The driver itself calls `uploadThumbnail()`.
- `thumbnailMime()` returns `application/octet-stream` for an image without an extension (it returned
  `image/jpeg`).
- `classifyError($response, string $operation = 'upload')`: `'comment'` turns on the comment-only
  reasons.
- `resume()` accepts `phase` as an alias of `stage`.
- Hooks: `buildBody()`, `uploadUrl()`, `uploadQuery()`, `contentOwnerQuery()`, `chunkBytes()`,
  `uploadWindowSeconds()`, `thumbnailFor()`, `likelyShort()`, `videoRules()`, and for the kept source
  copy `uploadRun()`, `uploadPath()`, `keepCopy()`, `stagedEntry()`, `keptCopy()`, `stagedPath()`,
  `discardStaged()` and the constant `STAGED_COPY_PATTERN`. `measureSource()` also returns a `staged`
  entry, and the upload state gains `staged` (`{path, size, mtime}`); an older state without it still
  resumes and makes a copy.

---

## Known limitations

- One video per post. No images, documents, community posts or live streams.
- Shorts are guessed from shape and length; the API has no Shorts flag.
- The 15-minute limit of unverified channels is not checked before the upload, and most processing
  rejections (`length`, `duplicate`, copyright) are seen only when the driver waits for processing.
- Every session open, including restarts, spends a `videos.insert` call (100 a day per project by
  default).
- A remote URL read in ranges must stay valid for the whole upload. Signed URLs that expire after an
  hour fail large uploads (`source_url_expired`) unless `range_reads` is `false`.
- When chunks cannot be read with Range requests, the whole file is copied once per worker host: the
  download must finish within `social.download_timeout` (120 s), and the copy time adds to that run.
  Raise `social.download_timeout` and `social.job_timeout` (with the queue's `retry_after`) for large
  files on such hosts or on cloud disks, or use a local file.
- An upload run killed by the job timeout runs again only after the queue's `retry_after`, so the
  video goes out that much later. Once the queue gives up on it, it is reported as possibly published,
  even if the kills hit earlier chunks.
- Uploads that outlast the token need a `CredentialsResolver` that refreshes it.
- After a session expired following the last chunk, the outcome is unknown; the driver does not search
  the channel.
- A playlist insert whose answer was lost is not retried, and on a repeated run a failed check means
  the video is not added.
- `onBehalfOfContentOwner` is not sent to `commentThreads.insert` or `channels.list`.
- `RawOptions` cannot set the privacy, made-for-kids, or a schedule.
- YouTube may apply another privacy than requested (`privacy_warning`); such a video gets no first
  comment.
- The first-comment lookup in a recovery reads the video's 100 newest top-level comments: a comment
  pushed out by more than 100 newer ones since the lost attempt is not found, and the comment is added
  again.
- On the `sync` queue connection the finalize stage's waits must fit `social.sync.max_wait`.

---

## Open live checks

These behaviours are covered by fixtures but not yet confirmed against the live API. Run
`tests/Live/YouTubeLiveTest.php` with `SOCIAL_LIVE_YOUTUBE_TOKEN` (`youtube.upload`, plus
`youtube.force-ssl` for the delete) and `SOCIAL_LIVE_YOUTUBE_CHANNEL_ID`, plus `SOCIAL_LIVE_PUBLISH=1`
and `SOCIAL_LIVE_YOUTUBE_VIDEO_PATH` (a small local mp4) for the checks that upload a private video and
delete it. Every session open spends `videos.insert` quota. See `tests/Live/README.md`.

1. A status query on a fresh session answers `308` with no `Range` (automated). The `Range: bytes=0-N`
   format after a partial upload, and that a finished session replays the original `200`/`201` video
   resource.
2. What YouTube returns for a chunk sent from a stale (lower) offset: a 400, or a `308` with the real
   `Range`.
3. The session `Location` host is always `*.googleapis.com` (automated).
4. `notifySubscribers=false` on the session open (automated). `onBehalfOfContentOwner` with
   `onBehalfOfContentOwnerChannel`, and the full part list
   `snippet,status,recordingDetails,localizations,paidProductPlacementDetails,brandPartner`.
5. Error shapes of `videos.insert`: `uploadLimitExceeded` as HTTP 400, at session open or on the last
   `PUT`; `quotaExceeded` on the session open; the `ErrorInfo` reasons `SERVICE_DISABLED`,
   `ACCESS_TOKEN_EXPIRED`, `ACCESS_TOKEN_SCOPE_INSUFFICIENT`.
6. The daily quota resets at midnight `America/Los_Angeles`, as used for `retry_at`.
7. Short thumbnails: `thumbnails.set` applies only after `processingStatus = succeeded`; `404
   videoNotFound` and `429 uploadRateLimitExceeded` right after the upload.
8. `playlistItems.insert` right after the upload (`videoNotFound` timing), and with a
   `youtube.upload`-only token (403 `insufficientPermissions`).
9. `commentThreads.insert` on a fresh upload: how long `videoNotFound` lasts, whether the top-level
   comment id equals the thread id, the 403 `forbidden` and `commentsDisabled` reasons, refusal on
   made-for-kids videos. For the recovery lookup: that `commentThreads.list?order=time` lists a
   comment right after `commentThreads.insert`, with `authorChannelId.value` and `textOriginal` for
   the channel's own comment.
10. Range GET support and `ETag` stability on your media hosts (S3, R2, CDN signed URLs), and the
    `206` `Content-Range` format.
11. `privacy_warning` when YouTube applied another privacy (automated, recorded). An unaudited API
    project forcing private on a public upload.
12. The `rejectionReason = length` wording, and the 15-minute limit of unverified channels.

Not yet in the live suite: the status an expired S3 presigned URL or Notion file URL returns
mid-upload (the driver treats 401, 403 and 410 as expired), `thumbnails.set` with
`application/octet-stream`, and `playlistItems.list` filtered by `videoId` as the repeat check.
