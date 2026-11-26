# Social Poster

A Laravel package that publishes posts, carousels, videos, stories and first comments to Facebook,
Instagram, Threads, X, LinkedIn, TikTok, YouTube, Bluesky and Pinterest through one fluent API.

You describe the post once. Each platform driver validates it against that platform's rules, moves
the media (by URL or by upload), waits for asynchronous processing without blocking a worker, maps
the platform's errors to a small set of exceptions and reasons, and protects you against posting
twice when a queue retries a job.

The package publishes. It does not run OAuth flows, store tokens or refresh them: you pass current
credentials, or bind a resolver that looks them up (and refreshes them) each time a job runs.

## Contents

- [Supported platforms](#supported-platforms)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [The builder](#the-builder)
- [Results](#results)
- [Credentials](#credentials)
- [Media](#media)
- [Validation](#validation)
- [Queued and synchronous publishing](#queued-and-synchronous-publishing)
- [Duplicate safety](#duplicate-safety)
- [Errors](#errors)
- [Events](#events)
- [First comments](#first-comments)
- [Platform options and the `extra` passthrough](#platform-options-and-the-extra-passthrough)
- [Testing your application](#testing-your-application)
- [Configuration](#configuration)
- [Queue and worker requirements](#queue-and-worker-requirements)
- [Writing a driver](#writing-a-driver)
- [Live verification](#live-verification)
- [Running the package's tests](#running-the-packages-tests)
- [Upgrading, changelog, license](#upgrading-changelog-license)

## Supported platforms

| Platform | Key | Post types | Media transfer | First comment | Guide |
|---|---|---|---|---|---|
| Facebook Pages | `facebook` | Text or link, 1 to 10 photos, video, Reel, photo or video story, scheduled post | Facebook pulls a public URL; a local Reel or story is uploaded | Yes (not on stories or scheduled posts) | [facebook.md](docs/platforms/facebook.md) |
| Instagram (professional accounts) | `instagram` | Photo, Reel, carousel of 2 to 10, story | Instagram pulls a public URL | Yes (not on stories) | [instagram.md](docs/platforms/instagram.md) |
| Threads | `threads` | Text, image, video, carousel of 2 to 20, poll, link preview, reply, quote | Threads pulls a public URL | Yes, as a reply (needs `threads_manage_replies`) | [threads.md](docs/platforms/threads.md) |
| X | `x` | Text, 1 to 4 photos, GIF, video, poll, reply, quote | Upload (chunked for GIF and video) | Yes, as a self-reply | [x.md](docs/platforms/x.md) |
| LinkedIn | `linkedin` | Text, link card, poll, reshare, 1 to 20 images, video, document | Upload | Yes, at least 60 s after the post | [linkedin.md](docs/platforms/linkedin.md) |
| TikTok | `tiktok` | Video or 1 to 35 photos, as an inbox draft or a direct post | Photos pulled by URL; video pulled or uploaded in chunks | No (TikTok has no comment API) | [tiktok.md](docs/platforms/tiktok.md) |
| YouTube | `youtube` | One video (public, unlisted, private or scheduled), thumbnail, playlists | Resumable upload | Yes, at least 60 s after the post | [youtube.md](docs/platforms/youtube.md) |
| Bluesky | `bluesky` | Text, link card, 1 to 4 images, video, quote, reply | Upload | Yes, as a reply | [bluesky.md](docs/platforms/bluesky.md) |
| Pinterest | `pinterest` | Image Pin, carousel of 2 to 5, video Pin | Images by URL or base64; video uploaded | No (no comment API) | [pinterest.md](docs/platforms/pinterest.md) |

Each guide lists the credentials, scopes, typed options, limits, async phases, error mapping,
duplicate-safety rules, configuration keys and known limitations of its platform. The code is the
reference when a guide and the code disagree.

`Platform::Mastodon` is a reserved case without a driver (see [Writing a driver](#writing-a-driver)).

## Requirements

- PHP 8.2 or later (8.3 or later for Laravel 13), Laravel 12 or 13. Laravel 11 apps can stay on 1.1.0.
- PHP extensions: `fileinfo`, `json`, `mbstring`, `openssl` (required); `intl` (accurate grapheme
  counting for Bluesky and Threads), `dom` (Bluesky link cards), `gd` or `imagick` (image
  conversion and link-card thumbnails) are suggested.
- `ffprobe` (from FFmpeg) is recommended. Without it, size, duration, dimension and frame-rate checks
  only run on metadata you pass yourself (see [Media](#media)).
- A queue worker for `->post()`, and a cache store that every worker shares (redis, database,
  memcached) for the job locks and the default idempotency store.

## Installation

```bash
composer require pr4w/laravel-social-poster
php artisan vendor:publish --tag=social-poster-config
```

The service provider and the `SocialPoster` facade are auto-discovered. The package namespace is
`SocialPoster\`.

The config file is optional: every key is read with an inline default, and the `api`, `async`,
`sync` and `media` blocks are deep-merged with the package defaults at boot, so new per-platform
defaults reach an older published copy.

**Durable duplicate protection (optional).** The default idempotency store keeps its records in the
cache and needs no table. For records that survive a cache flush, switch to the database store and
publish the migrations:

```php
// config/social.php
'idempotency' => SocialPoster\Idempotency\DatabaseIdempotencyStore::class,
```

```bash
php artisan vendor:publish --tag=social-poster-migrations
php artisan migrate
```

**Scheduled commands.** Add them to your scheduler (`routes/console.php`):

```php
use Illuminate\Support\Facades\Schedule;

// Deletes stale temporary media (downloads, conversions, kept upload copies) and,
// with FilesystemMediaGateway, re-hosted copies older than 24 hours.
Schedule::command('social-poster:prune-temp')->daily();

// DatabaseIdempotencyStore only: deletes records not updated for 30 days.
Schedule::command('social-poster:prune-idempotency --days=30')->daily();
```

Then read [Queue and worker requirements](#queue-and-worker-requirements): the queue connection's
`retry_after` must be longer than the publish job's timeout.

## Quick start

```php
use SocialPoster\Facades\SocialPoster;
use SocialPoster\ValueObjects\Media;

$queued = SocialPoster::on('instagram')
    ->using(['account_id' => $igUserId, 'access_token' => $token])
    ->media(Media::image('https://cdn.example.com/launch.jpg', altText: 'The new dashboard'))
    ->caption('Version 1.1 is out.')
    ->idempotencyKey("scheduled-post:{$scheduledPost->id}")
    ->withMetadata(['scheduled_post_id' => $scheduledPost->id])
    ->post();
```

`post()` validates, queues one job per platform and returns a `QueuedPost`. The outcome arrives
through events:

```php
use Illuminate\Support\Facades\Event;
use SocialPoster\Events\{PostFailed, PostPublished};

Event::listen(function (PostPublished $event) {
    $id = $event->result->metadata['scheduled_post_id'] ?? null;
    // store $event->result->platformPostId and $event->result->url (idempotently: see Events)
});

Event::listen(function (PostFailed $event) {
    // $event->exception->reason, $event->possiblyPublished, $event->exception->context['retry_at'] ...
});
```

## The builder

```php
SocialPoster::on(...$platforms)          // 'instagram' or Platform::Instagram, variadic
    ->using($credentials, $for = null)    // one bag, one bag for one platform, or a map per platform
    ->media(...$media)                    // URLs, paths or Media objects
    ->caption($caption)
    ->title($title)                       // YouTube title, TikTok photo title, Facebook video title...
    ->withOptions(...$options)            // one PlatformOptions object per platform
    ->withMetadata($data)                 // opaque correlation data, returned with every outcome
    ->withComment($text)                  // first comment, added once the post is live
    ->idempotencyKey($key)                // tie duplicate protection to your own id
    ->bestEffort()                        // per-platform validation instead of all-or-nothing
    ->afterCommit()                       // dispatch after the open DB transaction commits
    ->notAfter($moment)                   // never create the post after this time
    ->inlineComment()                     // postNow(): add the comment in-process
    ->withoutValidation()                 // skip the local validation gate
    ->post();                             // or postNow() / validate()
```

| Terminal | Returns | Behaviour |
|---|---|---|
| `validate()` | `array<platform, ValidationResult>` | Validation only. Never throws for a platform |
| `post()` | `QueuedPost` | Validates (all-or-nothing unless `bestEffort()`), then queues one job per platform. Outcomes arrive as events |
| `postNow()` | `array<platform, PostResult>` | Validates, then publishes in-process. Publish failures come back as failed results, never as exceptions |

Also on the builder: `outcome(?string $key = null)` and `reconcile(?string $key = null)` (see
[Duplicate safety](#duplicate-safety)) and `fromPost(SocialPost $post)` to start from an existing
post. With no `on()`, `config('social.default')` is used; with neither, the terminals throw.

### Fan-out with per-platform credentials and options

Every platform needs its own credentials. Pass a map keyed by platform, or one call per platform:

```php
use SocialPoster\Platforms\Instagram\InstagramOptions;
use SocialPoster\Platforms\LinkedIn\LinkedInOptions;
use SocialPoster\Platforms\X\{XOptions, XReplySettings};

SocialPoster::on('instagram', 'linkedin', 'x')
    ->using([
        'instagram' => ['account_id' => $igUserId, 'access_token' => $igToken],
        'linkedin' => ['author' => 'urn:li:organization:12345678', 'access_token' => $liToken],
        'x' => ['access_token' => $xToken, 'user_id' => $xUserId],
    ])
    ->media(Media::image('https://cdn.example.com/launch.jpg'))
    ->caption('Version 1.1 is out.')
    ->withOptions(
        new InstagramOptions(collaborators: ['example.partner']),
        new LinkedInOptions(visibility: LinkedInOptions::VISIBILITY_CONNECTIONS),
        new XOptions(replySettings: XReplySettings::Following),
    )
    ->withComment('Release notes: https://example.com/changelog')
    ->idempotencyKey('release-1.1')
    ->post();

// Equivalent: ->using($igCredentials, 'instagram')->using($liCredentials, 'linkedin')...
```

- A platform's own bag wins over a shared bag (`->using($bag)`), which wins over
  `config('social.platforms.<platform>.credentials')`.
- Each options object applies only to its own platform. Two objects for one platform in the same
  call throw an `InvalidArgumentException`.
- A fan-out never stops half way: every platform gets its own result or event.
- Content that differs per platform (other media, other captions) needs one call per platform.

### Other builder switches

- **`bestEffort()`**: a platform that fails validation (or has no driver) is reported with
  `PostFailed` and skipped; the others are published. `post()` lists it in `QueuedPost::$failed`,
  `postNow()` returns a failed `PostResult` for it.
- **`afterCommit(bool = true)`**: dispatch only after the surrounding database transaction commits,
  so a rolled-back record never gets a post. The default is `social.after_commit` (`true`). On
  Laravel 12 releases without rollback callbacks, `post()` inside an open transaction takes the unique
  lock, fires `PostQueued` and pushes only when the transaction commits: `QueuedPost` then reports the
  platform as queued, and a duplicate found at commit is reported only through `PostDeduplicated`.
- **`notAfter(DateTimeInterface|int|null)`**: a post that has not been created by then fails with
  `FailureReason::Expired` and nothing is published. A post the platform already accepted is still
  finished.
- **`withoutValidation()`**: skips the local gate. The platform then refuses what it does not accept.

### Correlation metadata

`withMetadata()` is the mirror of `extra`: data that never leaves your application and comes back on
every outcome (`PostResult::$metadata`, and `$metadata` on `PostQueued`, `PostFailed`, `PostRetrying`,
`PostProcessing`, `PostDeduplicated`, the comment events and `CredentialsRejected`). It travels in
the queue payload, so keep it to scalars and arrays. Repeated calls merge.

## Results

A `PostResult` (`SocialPoster\ValueObjects\PostResult`) carries:

| Property | Meaning |
|---|---|
| `platform` | The `Platform` case |
| `success` | `false` for a failed `postNow()` result |
| `platformPostId` | The platform's id for the post (null for a recovered post whose id is unknown, or a handed-off `postNow()`) |
| `url` | A public link, when the platform has one (null for stories, drafts, scheduled posts...) |
| `state` | `published`, `draft`, `scheduled`, `private` or `processing` (`PostResult::STATE_*`) |
| `idKind` | What the id is: `post_id`, `video_id`, `media_id`, `urn`, `at_uri`, `publish_id`, `pin_id`... |
| `payload` | What the platform returned, plus driver notes (`recovered`, `thumbnail_warning`, `processing_warning`...) |
| `metadata` | Your `withMetadata()` data |
| `error` | The `SocialPosterException` of a failed result |

Helpers: `isPublished()`, `isScheduled()`, `isDraft()`, `isProcessing()`, `withMetadata()`,
`toArray()` and `PostResult::fromArray()`.

Check `state` before acting on a result: a scheduled post, a TikTok inbox draft or a video still
processing is a success too. On TikTok only a direct `PUBLIC_TO_EVERYONE` post whose public id
arrives within `api.tiktok.public_id_wait` gets `idKind = video_id` and a permalink; every other TikTok
result carries the `publish_id` (`idKind = publish_id`), which is also always in
`payload['publish_id']` and is the key of TikTok's webhooks.

## Credentials

Pass credentials per call with `->using()`, or per platform in `config('social.platforms')`.

| Platform | Required | Recommended / optional |
|---|---|---|
| `facebook` | `account_id` (Page id), `page_access_token` (or `access_token`) | `scopes` |
| `instagram` | `account_id` (IG user id), `access_token` | `login` (`facebook` or `instagram`), `expires_at`, `scopes` |
| `threads` | `account_id` (numeric Threads user id, not the Instagram id), `access_token` | `scopes` |
| `x` | `access_token` (OAuth 2.0 user context) | `user_id`, `expires_at`, `scopes` |
| `linkedin` | `access_token`, `author` (`urn:li:person:…` or `urn:li:organization:…`) | `expires_at`, `scopes`, `organization` |
| `tiktok` | `access_token` | `open_id`, `expires_at`, `scopes` |
| `youtube` | `access_token` | `channel_id`, `expires_at`, `scopes` |
| `bluesky` | `did`, plus `access_token` (+ `dpop_private_key` when DPoP-bound), or `access_jwt`, or `identifier` + `app_password` (opt-in) | `pds`, `pds_url`, `token_type`, `scopes` |
| `pinterest` | `access_token`, `board_id` (or `account_id`) | `board_section_id`, `sandbox`, `api_base`, `expires_at`, `scopes` |

A missing required key fails as `PermanentException(Misconfigured)` with
`context['missing_credentials']`, before any request (Pinterest reports a missing token as
`InvalidToken`).

```php
use SocialPoster\ValueObjects\Credentials;

->using(Credentials::make(['access_token' => $token, 'user_id' => $xUserId]))
->using(['access_token' => $token, 'user_id' => $xUserId])   // an array works too
```

**Well-known keys.**

- `expires_at` (unix seconds or milliseconds, ISO-8601, or a `DateTimeInterface`): drivers that read
  it refuse a token that expires within about 60 s before they spend a call.
- `scopes` (a list, or a space- or comma-separated string): when present, validation reports a
  missing publish scope under `credentials.scopes`, and a missing comment scope skips the first
  comment (`CommentSkipped`, reason `missing_scope`). Without it, nothing is checked.
- The **identity key** (`account_id`, `user_id`, `author`, `open_id`, `channel_id`, `did`,
  `board_id`, or a generic `account`) names the account in idempotency keys. Pass it: the token is
  never used as an identity, so without one an `->idempotencyKey()` is shared by every account of
  that platform. `validate()` warns about it (see [Validation](#validation)).

**Scopes for your connect flow.**

```php
use SocialPoster\Enums\{Feature, Platform};

SocialPoster::requiredScopes(Platform::LinkedIn, Feature::Publish, ['author' => 'urn:li:organization:1']);
// [['w_organization_social']]: every group is required, any one scope of a group satisfies it
```

`Feature` has `Publish`, `Comment`, `Video` and `Document`.

### Token refresh: the CredentialsResolver hook

The jobs call `SocialPoster\Contracts\CredentialsResolver::resolve()` at the start of every run
(first attempt, retry, continuation, first comment). Bind your own to look tokens up and refresh them,
and dispatch a reference so no token sits in the queue payload:

```php
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Enums\{FailureReason, Platform};
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\ValueObjects\Credentials;

final class SocialAccountResolver implements CredentialsResolver
{
    public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
    {
        if (! $dispatched->isReference()) {
            return $dispatched;
        }

        $account = SocialAccount::find($dispatched->reference())
            ?? throw new PermanentException('The social account is gone.', $platform, FailureReason::Misconfigured);

        $account->refreshTokenIfExpiringWithin(300); // your code

        return $dispatched->with([
            'access_token' => $account->access_token,
            'expires_at' => $account->expires_at,
        ]);
    }
}
```

```php
// config/social.php
'credentials_resolver' => App\Social\SocialAccountResolver::class,

// dispatch: identifiers only (a token-like key is refused with InvalidArgumentException)
SocialPoster::on('youtube')
    ->using(Credentials::ref((string) $account->id, ['channel_id' => $account->channel_id]))
    ->...->post();
```

With a custom resolver bound:

- a rejected token that is not terminal (a 401, Meta 190, an expiring `expires_at`) is retried once,
  at once, with re-resolved credentials; a second rejection is final;
- `postNow()` resolves references too;
- when the dispatched credentials name no account, the resolver is called once at dispatch so the
  idempotency key can use the resolved account (the token is not stored);
- with the default `IdentityCredentialsResolver`, a `Credentials::ref()` fails as `Misconfigured`.

Platforms whose tokens are short-lived need this for queued posts: X (2 hours), YouTube (about 1
hour), TikTok (24 hours), Bluesky OAuth (about 15 minutes).

### Request authorizers

`config('social.authorizers.<platform>')` may name a class implementing
`SocialPoster\Contracts\AuthorizesRequests`, whose
`request(Platform $platform, Credentials $credentials): PendingRequest` returns the request to use.
The driver then builds its API requests from that `PendingRequest` instead of a bearer token, for
DPoP-bound or app-signed requests, or to delegate to a token manager. Bluesky uses
`DefaultBlueskyAuthorizer` when none is set.

`Credentials` redacts itself in `var_dump()`, `print_r()` and `json_encode()` (so in most log
contexts and error trackers). `dump()`/`dd()` and `var_export()` still show `$values`: dump
`$credentials->redacted()` instead.

## Media

```php
use SocialPoster\ValueObjects\Media;

Media::image('https://cdn.example.com/a.jpg', altText: 'Lobby at dawn');
Media::video('/var/app/storage/clip.mp4', thumbnail: 'https://cdn.example.com/cover.jpg', altText: '...');
Media::document('https://cdn.example.com/deck.pdf');        // LinkedIn
Media::guess('https://cdn.example.com/reel.mp4');            // type from the extension
Media::fromDisk('s3', 'videos/keynote.mp4');                 // a file on a Laravel disk

->media('https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.jpg')   // strings go through guess()
```

Every factory takes a trailing `array $meta` of metadata you already know: `width`, `height`,
`duration`, `size`, `frame_rate`, `mime`, `codec`, `bitrate`, `rotation`. It is used when the
inspector cannot read the file, so validation can still check limits. Some drivers also read
platform-keyed extras (`meta['instagram']` for carousel children).

`Media::fromDisk($disk, $path, ?MediaType $type = null, array $meta = [], ?string $altText = null)`:
on a local disk the source is the absolute path; on a cloud disk it is the disk's URL, and the gateways
stream the bytes from the disk when they need them. The path is normalised, and a path that leaves
the disk root (`../`) is refused (`MediaRejected`).

### How media reaches each platform

| Mode | Platforms | What you need |
|---|---|---|
| Pull: the platform downloads a public URL | Facebook (photos, regular videos), Instagram, Threads, TikTok photos | A publicly reachable URL, or a gateway that re-hosts local files (`FilesystemMediaGateway`). A local file otherwise fails validation |
| Upload: the driver sends the bytes | X, LinkedIn, YouTube, Bluesky, Pinterest video, Facebook Reels and stories from a local file | Local files and URLs both work; a URL is downloaded by the gateway first (YouTube reads it in Range requests when the host allows) |
| Both | TikTok video (`TikTokTransfer`), Pinterest images (`PinterestImageTransfer`) | See the platform guides |

### Gateways

`social.gateway` names the `SocialPoster\Contracts\MediaGateway` that turns a `Media` into a URL or
a local path.

- **`LocalMediaGateway`** (default) passes remote URLs through and downloads them when bytes are
  needed. It cannot invent a public URL for a local file.
- **`FilesystemMediaGateway`** also copies media to a Laravel disk and hands out that disk's URL,
  for pull-only platforms. `social.media.rehost`: `local_only` (default: local files and local-disk
  files are copied, remote URLs passed through), `always` (remote URLs are downloaded and copied too,
  for short-lived or signed URLs and TikTok's verified domains), `never`. Copies go to
  `social.media.rehost_disk` (`public`) under `social.media.rehost_prefix` (`social-poster`), named by a
  hash of the source, and keep their extension only for media formats (anything else is stored as
  `.bin`). `social.media.temporary_urls` uses `temporaryUrl()` (TTL `social.media.url_ttl`), which
  some platforms refuse.

### Transformers

`social.transformer` names a `SocialPoster\Contracts\MediaTransformer`, applied before upload.
`NullMediaTransformer` (default) changes nothing. `ImageConvertingTransformer` (GD or Imagick)
converts images a platform refuses but can take in another format: PNG to JPEG for TikTok photos
(and Instagram with `jpeg_only`), HEIC/AVIF/BMP/TIFF to JPEG for Bluesky... It applies EXIF
orientation, flattens transparency, fits the output to the platform's pixel and size limits (this is
what keeps a phone HEIC under Bluesky's 1 MB) and refuses images above `social.media.max_convert_pixels`
(50,000,000) before decoding them. The converted file is local: pull-only platforms then need
`FilesystemMediaGateway`.

### Inspection

`social.inspector` (`FFProbeInspector`) reads size, duration, dimensions, frame rate and codec for
the deep validation checks. Set `social.ffprobe_path` (env `SOCIAL_FFPROBE_PATH`) to an absolute
path when queue workers lack it on their `PATH`. `social.inspect_remote` (`true`) lets it read remote
URLs during validation: a HEAD, a capped Range GET for images (`social.inspector_range_bytes`), and,
while private networks are blocked, the first `social.inspector_video_range_bytes` (4 MiB) of a
video (plus its last bytes when the index is at the end), probed locally, so ffprobe never fetches a
URL itself. A value nothing can read is not checked.

### URL and local-path safety

Media URLs and local paths usually come from users. The gateways, the inspector and the link-card
fetchers apply one policy:

| Setting | Default | Effect |
|---|---|---|
| `social.media.block_private_networks` | `null`: on, except in the `local` environment and while running unit tests | Refuse URLs that resolve to private or reserved addresses. The connection is pinned to the checked address, and every redirect (at most 3) is checked again |
| `social.media.allowed_hosts` | `null` | Optional host allow-list, `['cdn.example.com', '*.example.org']` |
| `social.media.allow_http` | `true` | Allow plain-http media URLs |
| `social.media.local_roots` | `null` (any readable path, with a deprecation notice once per process) | Directories local media may come from |
| `social.media.max_download_bytes` | `null` (free disk space, minus a 64 MB reserve) | Download cap; a larger `Content-Length` aborts at the headers, and the body is capped while it streams |

Sources on any scheme other than `http(s)://` and `file://` (`ftp://`, `phar://`, `php://`, a
registered `s3://` wrapper...) are refused before PHP opens them. The 1.x defaults for
`local_roots` and `allow_http` are permissive; 2.0 will require `local_roots`, refuse plain http and
block private networks by default. Set them now if your media comes from user input.

### Temporary files

Downloads, conversions and inspection ranges are written as `sp_*` files to `social.temp_dir`
(default `storage/app/social-poster-tmp`) and deleted when the publish run (or `postNow()`) ends.
TikTok keeps a downloaded upload source between runs as `sp_tiktok_*`, YouTube as `sp_youtube_*`;
each driver deletes its copy when the upload ends. `social-poster:prune-temp` is the backstop for
workers killed mid-run:

```bash
php artisan social-poster:prune-temp [--hours=24] [--rehosted] [--skip-rehosted] [--legacy] [--dry-run]
```

It deletes `sp_*`, `sp_tiktok_*` and `sp_youtube_*` files older than `--hours` (a YouTube copy is left
behind by an upload chain the job ended: a deadline, retries used up, a killed worker, or a run that
moved to another worker host), and re-hosted copies when the gateway is `FilesystemMediaGateway` (or
with `--rehosted`). `--legacy` also sweeps v1.0 `sp_*` files from the system temp directory.

## Validation

```php
$results = SocialPoster::on('instagram', 'x')->using($credentials)->media($media)->caption($caption)->validate();

$results['x']->passes();      // bool
$results['x']->errors();      // MessageBag keyed by field: caption, media.0, comment, credentials.scopes...
$results['x']->warnings();    // MessageBag; never fails the result
$results['x']->hasWarnings(); // bool
$results['x']->toArray();     // JSON friendly; a 'warnings' key only when there are some
```

- `validate()` needs no credentials, never throws for a platform, and runs every check the driver can
  run locally: caption length with the platform's counting rule, media count, type and limits, option
  rules, scopes when the credentials list them, and the first comment's length.
- Captions are counted the way each platform counts them: X weights characters and counts every link
  as 23 (`XWeightedCounter`), Threads counts emoji as their UTF-8 bytes (`ThreadsCounter`), Bluesky
  counts graphemes plus a byte limit (`GraphemeCounter`), TikTok counts UTF-16 code units
  (`Utf16Counter`), the others count code points (`MbStrlenCounter`). The counters live in
  `SocialPoster\Support\Text`.
- A warning under `idempotency` says that an explicit `->idempotencyKey()` cannot keep two accounts
  apart because the credentials name no account (see [Duplicate safety](#duplicate-safety)). Warnings
  never block a post.
- `post()` and `postNow()` run the same validation first. Without `bestEffort()` it is all-or-nothing:
  a `ValidationException` whose `errors` are keyed `{platform}.{field}`, and nothing is sent anywhere.

## Queued and synchronous publishing

### `post()`

Queues one `PublishToConnectionJob` per platform on `social.connection` / `social.queue` and returns
a `QueuedPost`:

```php
$queued = SocialPoster::on('instagram', 'linkedin')->using($map)->media($media)->caption('...')->post();

$queued->correlationId;              // your ->idempotencyKey(), else a fresh UUID
$queued->queued('instagram');        // a job was queued
$queued->wasDeduplicated('linkedin'); // the same key is already queued or running: PostDeduplicated
$queued->key('instagram');           // the store key the events report; accepted by outcome()/reconcile()
$queued->errorFor('linkedin');       // why nothing was queued (bestEffort, no driver, push failed)
$queued->hasFailures();
```

Per platform, the job's unique lock is taken first (a refusal means the same post is already queued),
then `PostQueued` fires, then the job is pushed. A publish is a chain of job runs: each asynchronous
wait (video processing, container status) is a delayed continuation carrying the state, so no worker
sleeps on a platform. Large uploads run in slices (`api.<platform>.upload_run_seconds`, 240 s; YouTube
45 s) and hand over to the next run.

Job payloads are encrypted when `social.encrypt_jobs` is on (default) and the app has a key, because
they carry credentials. Dispatch `Credentials::ref()` to keep tokens out entirely.

### `postNow()`

Publishes in-process and returns one `PostResult` per platform, whatever happens to the others.

- A publish failure is a failed result (`success = false`, `error` set) plus a `PostFailed` event.
  Only a failed validation (without `bestEffort()`) and setup errors (an unknown platform, no platform
  at all, a missing driver under `withoutValidation()`) throw.
- A `TemporaryException` is retried in-process up to `social.sync.retries` (3) times, waiting the
  platform's `Retry-After` or `social.sync.backoff` ([2, 5, 10] s).
- Processing waits are polled while they fit `social.sync.max_wait` (120 s). Past it, the publish is
  handed to the queue: the result is a success with `state = processing`, a null id and
  `payload['handed_off'] = true`, and the outcome arrives as `PostPublished` / `PostFailed`. Upload
  slices and a create that is ready are not charged to `sync.max_wait`: they run until the driver's
  deadline (else `social.async.max_wait`), so `postNow()` of a large YouTube, X, LinkedIn or TikTok
  upload blocks the request for the whole upload. Use `post()` for large files.
- `postNow()` uses the idempotency store like `post()`: it replays a published key, continues an
  unfinished run of the same `->idempotencyKey()` instead of starting over, and records its outcome,
  so its keys work with `outcome()` and `reconcile()`. It renews its lease on the key before every
  step and records each step, so a `post()` or `postNow()` with the same key during a long synchronous
  upload steps aside (`PostDeduplicated`), and a dispatch that takes over a crashed or stalled run
  resumes it from the recorded step.
- The first comment is queued once the post is live; `->inlineComment()` (or
  `social.sync.inline_comments`) adds it in-process instead, except where the platform requires a
  delay (LinkedIn, YouTube), which is always queued.
- Temporary media is cleaned up at the end, unless a platform was handed to the queue.

### The `sync` queue connection

On a connection whose driver is `sync` there is no queue to hand over to or to delay with: a
temporary failure is final (`PostFailed`), processing waits at most `social.sync.max_wait`, and a
platform's minimum comment delay is slept in-process (or reported as `CommentFailed(Misconfigured)`
when it exceeds `sync.max_wait`). Use a real queue in production.

## Duplicate safety

A queue delivers a job at least once. A worker can die, time out or lose its connection after a
platform created the post but before the answer arrived, and a plain retry would post again. Social
APIs have no idempotency keys, so the package keeps its own record and, when in doubt, asks the
platform before creating anything.

**1. The idempotency key.** Each platform of a publish gets one store key, computed once at dispatch
from your `->idempotencyKey()` (else a fresh UUID per call), the platform and the account's identity
key. Every job run and continuation carries it. A second dispatch of the same key while the first is
queued is dropped (`PostDeduplicated`); once published, it replays the recorded result
(`PostPublished` with `$replayed = true`) instead of posting. Tie the key to your own record:

```php
->idempotencyKey("scheduled-post:{$scheduledPost->id}")
```

The account part comes from the driver's identity credential (`account_id`, `user_id`, `author`,
`open_id`, `channel_id`, `did`, `board_id`), a generic `account` credential, or a `Credentials::ref()`;
never from the token. When the credentials name no account (X without `user_id`, TikTok without
`open_id`, YouTube without `channel_id`...), two accounts that share an `->idempotencyKey()` share one
record: the second replays the first instead of posting. The package logs a one-time warning per
platform and adds an `idempotency` warning to `validate()`. Add the identity credential (or
`account`), or use one key per account.

**2. The store.** `social.idempotency` names it:

| Store | Durability | Notes |
|---|---|---|
| `CacheIdempotencyStore` (default) | The cache's: records live `social.idempotency_ttl` (7 days) after their last write; a flush or an eviction forgets them | No migration. Records in `social.idempotency_cache_store`, leases in `social.lock_store` (both default to the default cache store). Use a non-evicting, shared store |
| `DatabaseIdempotencyStore` | Durable | Needs the migrations (`social.idempotency_table`, `social.idempotency_connection`). Prune with `social-poster:prune-idempotency` |
| `NullIdempotencyStore` | None | A redelivered or retried publish posts again. A boot warning is logged on an asynchronous queue |

The first-party stores implement `SocialPoster\Contracts\AtomicIdempotencyStore`: a lease per key
(so two workers never run the same publish at once), an account binding (a key reused for another
account fails with `KeyCollisionException`, `Misconfigured`), and the statuses `pending`,
`committing`, `published` and `failed`.

**3. The commit protocol.** Every driver runs the one call that creates the post (POST /feed,
`media_publish`, POST /2/tweets, `createRecord`...) in its own step, a *commit phase*. The job marks
the record `committing` before that call. When such a step runs again after an unclear outcome (a
worker that died or timed out, a redelivery, a lost connection or 5xx after the request may have been
sent), the driver gets `_recovering = true` and verifies on the platform first: it looks the post up,
reads the container status, or uses a record key it chose itself. When the platform cannot be asked
(LinkedIn without read access, a TikTok pull init), the driver fails with `OutcomeUnknown` instead of
risking a second post. A request that provably never left (DNS, refused connection, TLS, connect
timeout) is retried as a clean try. Each platform guide has a *Duplicate safety* section with its
exact rules.

**4. `possiblyPublished`.** A failure that may have left a live post is reported with
`PostFailed::$possiblyPublished = true` (and `possibly_published = true` in the exception context).
The store keeps the record as `failed` instead of forgetting it, and a re-dispatch with the same key
is refused (`OutcomeUnknown`, context `previously_failed`). A failure without the flag published
nothing: the key is released, and the same key can be dispatched again (for example at `retry_at`).

### What to do with a possibly published post

1. Never re-post automatically. `errorSummary()['category']` is `verify` for these failures.
2. Let the package check: `reconcile()` resumes the recorded step with `_recovering` set, so the driver
   looks the post up before creating anything. It needs the original content:

   ```php
   $actions = SocialPoster::on('instagram')
       ->using($credentials)
       ->media($media)->caption($caption)              // the same post
       ->idempotencyKey("scheduled-post:{$scheduledPost->id}")
       ->reconcile();                                   // ['instagram' => 'resumed']
   ```

   The result per platform is a `Poster::RECONCILE_*` value: `replayed` (a published record:
   `PostPublished` again with `$replayed = true`), `resumed` (a recovery job was queued; the outcome
   arrives as events), `nothing`, `needs_content`, or `unrecoverable` (a failed record with no state to
   verify: check the platform by hand).
3. Or check the platform yourself. If the post is not there, publish again with a new
   `->idempotencyKey()`.

Reading the store:

```php
SocialPoster::outcome($key, 'instagram', $credentials);   // ?PostResult: the recorded published result
SocialPoster::status($key, 'instagram', $credentials);    // 'pending' | 'committing' | 'published' | 'failed' | null
SocialPoster::reconcile($key, 'instagram', $credentials, $socialPost);
```

`$key` is a store key from the events or `QueuedPost::key()`, or your own `->idempotencyKey()` (then
pass the platform and, for an account-bound key, the credentials). The builder forms
`->outcome()` / `->reconcile()` fill all of that from the builder.

From the command line:

```bash
php artisan social-poster:reconcile {key} [--platform=] [--ref=] [--status]
```

It shows what is recorded and replays a published record. The replayed `PostPublished` carries the
stored result and `$idempotencyKey`, but no metadata and no correlation id: listeners must find their
record by `$event->idempotencyKey` (or replay from code with
`->withMetadata(...)->idempotencyKey(...)->reconcile()`). Resuming an unfinished record needs the original post and is done from code. A
replay whose credentials name another account than the record's succeeds without the first comment
(`CommentSkipped`, reason `account_mismatch`).

Publishes recorded by v1.0 under the same `->idempotencyKey()` are replayed too (v1.0 keys carried no
account, so such a record replays for any account that reuses the key on that platform).

## Errors

Three exceptions, all extending `SocialPoster\Exceptions\SocialPosterException`, with `platform`, a
`FailureReason` (`reason`) and a `context` array:

| Exception | Meaning | Extra |
|---|---|---|
| `ValidationException` | Local rules failed; nothing was sent | `errors` (`MessageBag`) |
| `TemporaryException` | Transient; the job retries it | `retryAfter` (seconds) |
| `PermanentException` | Will not succeed as it is | — |

`UnsupportedPlatformException` (a `PermanentException`, `Misconfigured`) is thrown for a platform
name without a driver. `KeyCollisionException` (`Misconfigured`, reported through `PostFailed`) means
an idempotency key is already bound to another account.

Helpers on every exception: `possiblyPublished()`, `isTerminal()` (the token can never recover:
revoked, password changed), `needsReconnect()`, and `errorSummary()`, a JSON-safe array with
`platform`, `reason`, `category`, `message`, `class` and a plain `context`.

### `FailureReason`

`RateLimited`, `ServerError`, `Timeout`, `Network`, `ProcessingTimeout`, `InvalidToken`,
`InsufficientPermissions`, `AccountRestricted`, `MediaRejected`, `InvalidContent`, `DuplicateContent`,
`Validation`, `Expired`, `OutcomeUnknown`, `NotFound`, `QuotaExceeded`, `Misconfigured`, `Unknown`.
New cases may be added in minor releases: give `match` blocks a default arm, or branch on the category.

`$reason->category()` groups them, and `errorSummary()['category']` refines it per failure:

| Category | Reasons | What to do |
|---|---|---|
| `retry` | RateLimited, ServerError, Timeout, Network, ProcessingTimeout | Transient; posting again is safe |
| `verify` | OutcomeUnknown, and any failure flagged `possibly_published` | The post may be live: check the platform first, never re-post automatically |
| `reconnect` | InvalidToken, InsufficientPermissions | The account must be reconnected (new token or scopes) |
| `user_fix` | AccountRestricted, MediaRejected, DuplicateContent, InvalidContent, Validation, Expired | Content or account the user must change |
| `operator` | Misconfigured, QuotaExceeded, Unknown | Package, app or billing setup |
| `not_found` | NotFound | The target is gone (board, parent post, upload session) |

### Reconnect handling

`$reason->requiresReconnect()` is true for `InvalidToken` and `InsufficientPermissions`.
`$e->needsReconnect()` is the per-failure answer: a refusal that a reconnect cannot fix (a TikTok
privacy level the creator does not allow, an unaudited TikTok client, YouTube's
`forbiddenPrivacySetting`) keeps `InsufficientPermissions` with context `reconnect = false`, reports
the category `user_fix`, and fires no `CredentialsRejected`. When a reconnect is needed, the jobs fire
`CredentialsRejected` with the account key and `$terminal`.

### Daily quotas and `retry_at`

A limit that resets in hours is never waited out in the queue. YouTube's daily quota, TikTok's daily
post cap and active-user cap, Instagram's daily publishing limit (2207042), Bluesky's daily write
budget and video limit, LinkedIn's daily throttles and X's 24-hour limits fail as
`PermanentException(RateLimited)` with `context['retry_at']` (ISO-8601 UTC) and
`context['retry_after_hint']` (seconds). Reschedule the post for `retry_at`; the key was released, so
the same `->idempotencyKey()` works.

```php
Event::listen(function (PostFailed $event) {
    if ($event->exception->reason === FailureReason::RateLimited && isset($event->exception->context['retry_at'])) {
        // reschedule for $event->exception->context['retry_at']
    }
});
```

Short throttles and 5xx answers are `TemporaryException`s, retried by the job (`social.tries`, 5 per
step) after the platform's `Retry-After` (at most 3600 s) or `social.backoff` ([30, 120, 300, 900]).
Connection failures (no HTTP response) become `TemporaryException` with reason `Network` and context
`request_sent`.

### Logging unmapped errors

Every failed platform response is logged through one chokepoint, with tokens and auth headers
redacted: `[SocialPoster] {platform} API error: ...` (temporary failures at warning level, permanent
ones at error level). The context has `unmapped` (true when the error fell through to `Unknown`), the
driver, the HTTP status, the body, headers, and `identifiers` (code, subcode, type, message, trace id).
Grep for `"unmapped":true` to find codes worth adding to a driver's `classifyError()`. Connection
failures are logged by the job with a `transport` flag. Turn it off with `SOCIAL_ERROR_LOGGING=false`,
or route it with `SOCIAL_ERROR_LOG_CHANNEL`.

## Events

All in `SocialPoster\Events`. Each can be handled by a queued listener: events serialise safely, and
an event queued by an older release unserialises with the new properties at their defaults.

| Event | When | Main properties |
|---|---|---|
| `PostQueued` | A publish job was accepted by the queue | `platform`, `post`, `metadata`, `correlationId`, `idempotencyKey` |
| `PostDeduplicated` | A dispatch was dropped because the same key is already queued or running, or a redelivered copy of a finished step was dropped | `platform`, `key`, `metadata`, `correlationId` |
| `PostProcessing` | Each wait of a continuation, a create step's included (not the immediate hop into a create) | `platform`, `poll`, `elapsedSeconds`, `metadata`, `correlationId`, `reason` (`processing`, `not_ready`, `verifying`, `retrying`, or null), `identifiers` (ids the platform already gave, e.g. TikTok's `publish_id`; empty when none yet) |
| `PostRetrying` | A transient failure, or a restart from scratch; the publish will try again (queued or `postNow()`) | `platform`, `errorSummary`, `attempt`, `nextRetryAt`, `metadata`, `correlationId`, `retries`, `restarts`; `reason()` |
| `PostPublished` | The post exists (also scheduled, draft, private or processing: check `$result->state`) | `result`, `correlationId`, `idempotencyKey`, `attempt`, `replayed`, `retries`, `restarts` |
| `PostFailed` | Publishing failed for good | `platform`, `exception` (`context['identifiers']` when the platform already gave ids), `metadata`, `correlationId`, `idempotencyKey`, `attempt`, `willRetry`, `possiblyPublished`, `retries`, `restarts`; `errorSummary()` |
| `CredentialsRejected` | A failure that needs a reconnect | `platform`, `accountKey`, `reason`, `terminal`, `metadata`, `errorSummary` |
| `CommentPublished` | The first comment was added (or found already there) | `platform`, `postId`, `commentId`, `metadata`, `url`, `payload` |
| `CommentFailed` | The first comment could not be added after its retries | `platform`, `postId`, `exception`, `metadata`, `attempt`; `errorSummary()` |
| `CommentSkipped` | A first comment was requested but deliberately not attempted | `platform`, `postId`, `reason`, `metadata` |

**Counting attempts.** A publish runs as several steps (upload, processing waits, the create), and
`attempt` is the attempt of the current step: it starts at 1 again on every step. For the publish as a
whole, use `retries`: the retries so far across all steps and restarts (on `PostRetrying` it includes
that retry). A post published or failed with `retries = 2` did so on its third attempt. Besides the
`PostRetrying` events, a run the queue delivered again counts too: one whose worker died or timed out,
and one held back because another run had the step. A delivery the worker failed without running it
does not. A dispatch that takes over a stopped publish (a re-dispatch with the same key, `reconcile()`)
continues its count from the store. `restarts` counts the times the publish started over from scratch,
each of which is also a retry: a TikTok publish the platform discarded, LinkedIn upload URLs that
expired, a Bluesky blob that is gone, a file that changed during an X upload, an Instagram container
gone outside the publish step. Waits a driver handles by itself (media not ready, a verification, a
throttled create, Instagram containers it re-creates) are neither: they are `PostProcessing` events
with a `reason`. A replayed `PostPublished` counts the run that replayed, not the original publish.

**Make listeners idempotent.** `PostPublished` can fire more than once for one post: a replay after a
worker crash or a redelivery, a second dispatch of a published key, and every `reconcile()` replay
(`$replayed = true`). Key your handling on `$event->idempotencyKey` (or your metadata) and make it
safe to repeat.

A queued listener receives a portable copy of the exception: same class, message, reason and plain
context, without frame arguments, HTTP requests and responses, `Credentials` or `PreparedPost`, so no
token reaches the queue. `getPrevious()->getRequest()` is therefore not available there; use
`errorSummary()`.

## First comments

`->withComment('...')` adds a first comment (the "link in the first comment" pattern) as a separate
step once the post is live, so a comment never delays or fails the post.

| Platform | Comment | Notes |
|---|---|---|
| Facebook | Comment as the Page | Feed posts, videos and Reels; 8,000 characters. Looks for an identical Page comment first |
| Instagram | Comment | Not on stories; 2,200 characters. Looks for the account's identical comment first |
| Threads | Reply | 500 characters; needs `threads_manage_replies`, skipped when the credentials list no scopes (`api.threads.comment_unknown_scopes`) |
| X | Self-reply | 280 weighted characters; billed like a post on pay-per-use plans |
| LinkedIn | Comment as the author | 1,250 characters; at least 60 s after the post |
| YouTube | Top-level comment | 10,000 characters; at least 60 s after the post; not on private, scheduled or made-for-kids videos |
| Bluesky | Reply | 300 graphemes; the record key is derived from the parent, so a retry writes to the same key |
| TikTok, Pinterest | — | Not supported: `CommentSkipped`, reason `unsupported` |

- The comment is validated with the post (`validate()` reports it under `comment`), so an over-long
  comment never fails after the post is live.
- `post()`: the publish job queues `AddFirstCommentJob` after `social.comment_delay` (60 s), or the
  platform's minimum when longer. A parent still processing when the publish returned (a Facebook
  Reel past its poll budget) waits `social.comment_processing_delay` (300 s).
- `postNow()`: queued the same way by default; see `inlineComment()` above.
- The comment job retries temporary errors (`social.comment_tries`, 5, waiting `Retry-After` up to 600 s
  or `social.comment_backoff`). A final failure is `CommentFailed` (and `CredentialsRejected` when the
  token or permission was refused); `social.comment_fail_jobs` also fails the job in the queue.
- `CommentSkipped::$reason` is one of `unsupported`, `story`, `missing_scope`, `empty`, `no_post_id`
  (a recovered post whose id is unknown), `scheduled` (scheduled, draft or private), `already_done`
  (a replay of a comment already recorded) and `account_mismatch` (a reconcile replay with another
  account's credentials).
- The comment is recorded under `{key}:comment` and never sent twice by a replay. When an attempt's
  answer was lost (a connection dropped after sending, a 5xx, a worker killed mid-call), the next
  attempt is a recovery: the driver gets the parent with `payload['_recovering'] = true` and checks
  before commenting again. Facebook, Instagram, LinkedIn and YouTube look for the account's own
  comment with the same text; Threads reads the reply container in the same run and, in a recovery,
  the account's replies; Bluesky reuses its record key; an X duplicate refusal is read as the earlier
  reply. A comment found this way is reported as `CommentPublished` with `payload['recovered']`. When
  the comments cannot be read (LinkedIn without `r_organization_social_feed` or the restricted
  `r_member_social_feed`, Threads without `threads_read_replies`), the recovery fails as
  `OutcomeUnknown` with `possiblyPublished` instead of commenting twice. A LinkedIn comment whose
  connection dropped after sending fails the same way at once.

## Platform options and the `extra` passthrough

Typed options cover what is structural, required by the API, or checked by validation. Each class
binds to one platform, and in a fan-out applies only there:

| Platform | Options | Highlights |
|---|---|---|
| Facebook | `FacebookOptions` | `isStory`, `asReel`, `link`, `scheduledAt`, `thumbnail` (regular videos only; ignored with a logged warning on Reels, stories, photos and text) |
| Instagram | `InstagramOptions` | `isStory`, `thumbnail` (reel cover), `shareToFeed`, `thumbOffsetMs`, `collaborators` |
| Threads | `ThreadsOptions`, `ThreadsPoll`, `ThreadsReplyControl` | `replyControl`, `topicTag`, `linkAttachment`, `pollAttachment`, `replyToId`, `quotePostId`, `locationId`, `madeWithAi`, `spoilerMedia` |
| X | `XOptions`, `XPoll`, `XReplySettings` | `replySettings`, `poll`, `replyToTweetId`, `quoteTweetId` |
| LinkedIn | `LinkedInOptions`, `LinkedInMention`, `LinkedInPoll`, `LinkedInArticle` | `mentions` (`{{mention:N}}` markers), `visibility`, `poll`, `article`, `reshareOf`, `autoLinkCard`, `autoMentionUrls` |
| TikTok | `TikTokOptions`, `TikTokTarget`, `TikTokPrivacy`, `TikTokTransfer` | `target` (drafts by default), `privacyLevel`, `privacyFallbacks`, `brandContent`, `brandOrganic`, `isAigc`, `transfer` |
| YouTube | `YouTubeOptions`, `YouTubePrivacy` | `privacyStatus`, `publishAt`, `tags`, `categoryId`, `thumbnail`, `playlistIds`, `waitForProcessing` |
| Bluesky | `BlueskyOptions` | `langs`, `linkCard`, `replyTo`, `quote`, `replyControl`, `disableQuotes`, `videoCaptions` |
| Pinterest | `PinterestOptions`, `PinterestImageTransfer` | `link`, `altText`, `boardSectionId`, `thumbnail`, `carouselItems`, `imageTransfer` |

The long tail of platform fields goes through `extra`, which every options class accepts and which is
merged into the platform's create request. It is never validated, and the keys the driver computes
win on collision, so `extra` can add fields but not silently override what the driver decided. A
platform without the options you need can take `new RawOptions(Platform::X, [...])`, which carries
`extra` only. Each guide documents where its `extra` goes.

```php
use SocialPoster\Platforms\Instagram\InstagramOptions;

->withOptions(new InstagramOptions(extra: ['location_id' => '123456789']))
```

## Testing your application

`SocialPoster::fake()` swaps the poster for a recording fake. Nothing is published or queued, no
driver or HTTP call runs, and credentials are never required.

```php
use SocialPoster\Enums\FailureReason;
use SocialPoster\Facades\SocialPoster;
use SocialPoster\ValueObjects\PreparedPost;

$fake = SocialPoster::fake()
    ->succeed('instagram', 'ig-1', 'https://instagram.com/p/abc')
    ->fail('linkedin', FailureReason::InvalidToken)
    ->pending('tiktok')                       // accepted, state 'processing'
    ->rejectValidation('x', ['caption' => 'Too long.'])
    ->dispatchEvents();                       // fire PostQueued / PostPublished / PostFailed as the real terminals would

// ... the code under test calls SocialPoster::on(...)->...->post() ...

$fake->assertQueued('instagram', fn (PreparedPost $post) => $post->caption() === 'Hello');
$fake->assertCommented('instagram', 'First!');
$fake->assertNotPosted('x');
$fake->assertPostedTimes('instagram', 1);
```

Assertions: `assertPosted`, `assertPostedTimes`, `assertNotPosted`, `assertNothingPosted`,
`assertQueued`, `assertPostedNow`, `assertValidated`, `assertCommented`, `assertReconciled`.
Queries: `posted()`, `queued()`, `postedNow()`, `validated()`, `recorded()`. Unscripted platforms
succeed with a generated id (`fake-<platform>-<n>`). Callbacks receive
`(PreparedPost $post, string $terminal)`. Events are only dispatched after `dispatchEvents()`.

## Configuration

`config/social.php` documents every key with its default. The main groups:

| Group | Keys |
|---|---|
| Queue | `connection`, `queue`, `after_commit` (true), `encrypt_jobs` (true), `tries` (5), `backoff` ([30, 120, 300, 900]), `job_timeout` (null: derived), `max_restarts` (3), `lock_store` |
| Waits | `async.max_wait` (3600) and `async.max_wait_per_platform.*`, `sync.max_wait` (120), `sync.retries` (3), `sync.backoff`, `sync.inline_comments` (false), `max_recheck_after` (900) |
| First comment | `comment_delay` (60), `comment_processing_delay` (300), `comment_tries` (5), `comment_backoff`, `comment_fail_jobs` (false), `comment_timeout` (`SOCIAL_COMMENT_TIMEOUT`; null: derived) |
| Timeouts | `http_timeout` (60), `publish_timeout` (120), `upload_timeout` (300), `download_timeout` (120); per platform `api.<platform>.timeout`, `connect_timeout`, `publish_timeout`, `upload_timeout`, `upload_run_seconds` |
| Duplicate protection | `idempotency` (`CacheIdempotencyStore`), `idempotency_table`, `idempotency_connection`, `idempotency_ttl` (604800), `idempotency_cache_store` |
| Credentials | `credentials_resolver`, `authorizers`, `platforms.<platform>.credentials` |
| Logging | `error_logging` (true), `error_log_channel` |
| Per platform | `api.<platform>.*`: API versions, media limits, poll intervals, retry policies. Each value shown is the driver's default; raise limits for accounts that have higher ones (X Premium, verified YouTube channels) |
| Media | `inspector`, `inspector_timeout`, `ffprobe_path`, `inspect_remote`, `inspector_range_bytes`, `inspector_video_range_bytes`, `gateway`, `temp_dir`, `transformer`, `media.*` |
| Drivers | `drivers`: override a first-party driver by name, set it to `null` to disable it, or give a reserved `Platform` case a driver |

API versions to keep current: `api.facebook.graph_version` and `api.instagram.graph_version`
(`v25.0`; a published config with an older version logs a boot warning), `api.threads.graph_version`
(`v1.0`), `api.linkedin.version` (`202605`; LinkedIn retires versions yearly and answers 426).

After upgrading the package, diff your published `config/social.php` against the package's, or
re-publish it with `--force`.

## Queue and worker requirements

- **`retry_after` must exceed the job timeout.** The publish job declares its own `$timeout`, which
  takes precedence over the worker's `--timeout`: `social.job_timeout`, or derived per platform as the
  longest upload slice plus the longest single call plus 120 s. That is 660 s by default, and 960 s for
  Pinterest (its S3 upload timeout is 600 s). Every queue connection the package uses needs a
  `retry_after` longer than that (on SQS, the queue's visibility timeout). Otherwise the queue hands a
  running step to a second worker, which waits for the step's lock and so delays the publish. The
  provider logs a warning at boot when it is not.
- The first-comment job derives its own timeout (`social.comment_timeout` when set): about 390 s by
  default, 400 s for Threads. The `retry_after` warning counts it too: with a low `social.job_timeout`,
  the comment run can be the longer one.
- A publish run killed by its timeout is failed, not retried blind: inside a create step it is
  reported as `OutcomeUnknown` with `possiblyPublished`. Two exceptions are redelivered once
  `retry_after` has passed: a YouTube upload run, which asks the upload session first (when the queue
  gives up on it, `OutcomeUnknown` with `possiblyPublished`), and a comment run, as a recovery.
- **A shared cache store.** The unique-dispatch locks, the step locks and, with
  `CacheIdempotencyStore`, the records and leases live in the cache (`social.lock_store`,
  `social.idempotency_cache_store`, else `cache.default`). An `array` or `null` store on an
  asynchronous queue protects nothing across workers; the provider warns about it.
- **Deploys.** Jobs queued by v1.0 still run and resume after the upgrade (they keep their v1.0
  store key). Restart the workers after deploying (`php artisan queue:restart`) so they load the new
  code, and let the queues drain first if you can.
- Large uploads run in slices of `upload_run_seconds` and hand over to a continuation, so a 10 GB
  YouTube upload never needs a 10 GB job timeout. A source that must be copied first (a cloud disk,
  a host without Range support) adds the copy time to the run that makes it.

## Writing a driver

Extend `SocialPoster\Platforms\AbstractPlatform`: you get capability validation, error mapping and
logging, credential and scope checks, media transfer, and the async loop. Declare the rules, publish,
and run the create in a commit phase:

```php
use SocialPoster\Capabilities\{Capabilities, CaptionRules, ImageRules, MediaRules};
use SocialPoster\Enums\Platform;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\ValueObjects\{Pending, PostResult, PreparedPost, PublishOutcome};

final class MastodonDriver extends AbstractPlatform
{
    public function platform(): Platform
    {
        return Platform::Mastodon;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            caption: new CaptionRules(max: 500),
            media: new MediaRules(maxCount: 4, image: new ImageRules(extensions: ['jpg', 'jpeg', 'png'])),
        );
    }

    protected function requiredCredentials(): array
    {
        return ['access_token' => 'an OAuth token', 'account_id' => 'the account id'];
    }

    protected function identityKeys(): array
    {
        return ['account_id'];
    }

    public function publish(PreparedPost $post): PublishOutcome
    {
        $this->assertCredentials($post);

        // Upload media here if needed, then hand the create to its own commit phase.
        return $this->commit(['phase' => 'create']);
    }

    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        if (Pending::isRecovering($state)) {
            // An earlier try may have created the post: look it up first, or throw
            // PermanentException(FailureReason::OutcomeUnknown, ['possibly_published' => true]).
        }

        $response = $this->http($post)->post('https://mastodon.example/api/v1/statuses', [
            'status' => $post->caption(),
        ]);

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        // ambiguous: true, a 2xx without an id fails as OutcomeUnknown instead of a blank id.
        $id = $this->requireId($response, 'post', 'id', true);

        return $this->published(PostResult::success(Platform::Mastodon, $id, $response->json('url')));
    }
}
```

- Return `$this->pending($state, $recheckAfter)` to wait (processing), `$this->commit($state)` before a
  non-idempotent create, `PublishOutcome::pending($state, $recheckAfter, committed: true, deadline: $time)`
  once the post exists or to declare a deadline. A `TemporaryException` with context `restart = true`
  makes the job start over (within `social.max_restarts`).
- Reserved state keys start with `_` and are written by the job (`Pending::RECOVERING`,
  `Pending::COMMITTING_SINCE`, `Pending::STARTED_AT`, `Pending::ATTEMPT`, `Pending::RESTARTS`). Read
  them; never write them.
- Hooks with safe defaults: `rulesFor()`, `validatePlatformRules()`, `ingestion()`,
  `requiredScopes()`, `classifyError()` (end with `return parent::classifyError($response)`),
  `mediaUrl()` / `mediaPath()` / `transformMedia()`, `mergeExtra()`, `assertTokenFresh()`,
  `clampDelay()`.
- First comments: implement `SocialPoster\Contracts\SupportsFirstComment` (`commentRules()` and
  `addFirstComment()`). When `Pending::isRecovering($parent->payload)` is true, an earlier attempt may
  have added the comment: look it up, or throw `OutcomeUnknown` with `possibly_published = true`.
  `SupportsComments::comment()` is the deprecated v1.0 contract.

Register it in `config('social.drivers')`, or with
`app(SocialManager::class)->extend('mastodon', fn ($app) => $app->make(MastodonDriver::class))`. In
1.x the builder only posts to `SocialPoster\Enums\Platform` cases: a driver can replace a first-party
one by its name, or take the reserved `mastodon` case. Any other name logs a boot warning and cannot
be posted to (custom platform names arrive in 2.0). Drivers are resolved from the container and
receive the inspector and gateway.

## Live verification

The test suite never leaves the machine: every request is faked from production-shaped fixtures. Some
platform behaviours could only be confirmed against real accounts; `tests/Live` holds those checks,
opt-in per platform with `SOCIAL_LIVE_*` variables, read-only unless `SOCIAL_LIVE_PUBLISH=1`. See
[tests/Live/README.md](tests/Live/README.md) and the *Open live checks* section of each platform guide.

## Running the package's tests

```bash
composer install
vendor/bin/pest                                  # Unit and Feature (sqlite in memory)
vendor/bin/pest --testsuite=Database             # concurrency and lease tests; needs ext-pcntl and ext-posix
vendor/bin/phpstan analyse && vendor/bin/pint --test
```

`DB_CONNECTION` (`mysql`, `mariadb`, `pgsql`, `sqlsrv`) with `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME` and `DB_PASSWORD` runs the suite on a database server. Every table is dropped before each
test, and a database whose name lacks `test` is refused. `REDIS_HOST` and `MEMCACHED_HOST` add those
stores to the lease and claim-race tests.

## Upgrading, changelog, license

- [UPGRADE.md](UPGRADE.md): moving from 1.0 to 1.1.
- [CHANGELOG.md](CHANGELOG.md).
- MIT, see [LICENSE](LICENSE).
