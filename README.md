# Social Poster

A cleanly architected Laravel package for publishing photos, videos, and documents to multiple social platforms behind one fluent API. You describe the post once; each platform driver handles its own validation, media transfer, async processing, and error mapping.

Supported platforms: **Facebook, Instagram, Threads, LinkedIn, X, TikTok, YouTube.**

## Contents

-   [Install](#install)
-   [Quick start](#quick-start)
-   [The builder](#the-builder)
-   [Credentials](#credentials)
-   [Media](#media)
-   [Platform options](#platform-options)
-   [Extra parameters (the escape hatch)](#extra-parameters-the-escape-hatch)
-   [Correlation metadata](#correlation-metadata)
-   [First comments](#first-comments)
-   [Async publishing](#async-publishing)
-   [Idempotency and duplicate protection](#idempotency-and-duplicate-protection)
-   [Validation and errors](#validation-and-errors)
-   [Media transfer: pull vs upload](#media-transfer-pull-vs-upload)
-   [Configuration](#configuration)
-   [Writing a driver](#writing-a-driver)

## Install

```bash
composer require pr4w/laravel-social-poster
php artisan vendor:publish --tag=social-poster-config
```

The package namespace is `SocialPoster\`.

## Quick start

```php
use SocialPoster\Facades\SocialPoster;
use SocialPoster\ValueObjects\Media;

// Same simple content fanned out to several platforms, queued.
SocialPoster::on('instagram', 'facebook')
    ->media('https://cdn.example.com/sunset.mp4')
    ->caption('Sunset at the Crillon')
    ->post();

// One platform, explicit credentials, queued.
SocialPoster::on('linkedin')
    ->using(['author' => 'urn:li:person:abc', 'access_token' => $token])
    ->media(Media::image('https://cdn.example.com/lobby.jpg'))
    ->caption('A quiet morning in the lobby.')
    ->post();
```

Fan-out is for identical, simple content. When platforms need different media or options, use one call each.

## The builder

Every call is built fluently and ends in one of three terminals.

```php
SocialPoster::on(...$platforms)   // 'instagram', or Platform::Instagram, variadic
    ->using($credentials)         // optional; falls back to config credentials
    ->media(...$media)            // string URLs/paths or Media objects, variadic
    ->caption($caption)
    ->title($title)               // platforms that use a title (YouTube, LinkedIn docs)
    ->withOptions($options)       // a PlatformOptions implementation, per platform
    ->withMetadata($data)         // opaque correlation data returned with the result
    ->withComment($text)          // first comment, added after the post is live (supported platforms)
    ->withoutValidation()         // skip the local validation gate
    ->post();                     // terminal
```

| Terminal     | Returns                             | Behaviour                                                          |
| ------------ | ----------------------------------- | ------------------------------------------------------------------ |
| `validate()` | `array<platform, ValidationResult>` | Runs validation only. Never throws. Frontend friendly.             |
| `postNow()`  | `array<platform, PostResult>`       | Validates all-or-nothing, then publishes synchronously in-process. |
| `post()`     | `void`                              | Validates, then queues one job per platform.                       |

```php
$results = SocialPoster::on('instagram')->media($media)->caption($caption)->validate();

if ($results['instagram']->fails()) {
    return back()->withErrors($results['instagram']->errors());
}
```

## Credentials

Pass credentials per call with `->using()`, or set them in `config/social.php` under `platforms`. Token acquisition and refresh are out of scope: the package is a pure publishing engine and expects a current token.

| Platform    | Required credentials                                |
| ----------- | --------------------------------------------------- |
| `facebook`  | `account_id` (Page ID), `page_access_token`         |
| `instagram` | `account_id` (IG user ID), `access_token`           |
| `threads`   | `account_id`, `access_token`                        |
| `linkedin`  | `author` (e.g. `urn:li:person:...`), `access_token` |
| `x`         | `access_token`                                      |
| `tiktok`    | `access_token`                                      |
| `youtube`   | `access_token`                                      |

```php
use SocialPoster\ValueObjects\Credentials;

->using(Credentials::make(['access_token' => $token]))
->using(['account_id' => $pageId, 'page_access_token' => $token]) // array also accepted
```

## Media

```php
use SocialPoster\ValueObjects\Media;

Media::image('https://cdn.example.com/a.jpg', altText: 'Lobby at dawn');
Media::video('https://cdn.example.com/clip.mp4', thumbnail: 'https://cdn.example.com/cover.jpg');
Media::document('https://cdn.example.com/deck.pdf');   // LinkedIn
Media::guess('/local/path/reel.mp4');                  // infers type from extension

// A bare string is wrapped with Media::guess() for you.
->media('https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.jpg') // carousel
```

A remote URL and a local filesystem path are both valid sources. Which one you need depends on the platform (see [Media transfer](#media-transfer-pull-vs-upload)).

## Platform options

Typed options are reserved for knobs that are structural, required by the API, or that interact with validation. Everything else rides [`extra`](#extra-parameters-the-escape-hatch). Each options object is bound to one platform, so in a fan-out it only ever applies to its own driver.

### Instagram / Facebook

```php
use SocialPoster\Platforms\Instagram\InstagramOptions;

->withOptions(new InstagramOptions(
    isStory: true,                                    // post as a story
    thumbnail: Media::image('https://.../cover.jpg'), // reel cover
))
```

`FacebookOptions` mirrors this (`isStory`, `thumbnail`).

### LinkedIn

```php
use SocialPoster\Platforms\LinkedIn\LinkedInOptions;

->withOptions(new LinkedInOptions(
    escapeText: false, // keep your hand-built mentions; default true escapes reserved chars
))
```

### TikTok

TikTok has two posting targets. `Drafts` (the default) uploads to the creator's inbox to finish in-app and needs only the `video.upload` scope. `DirectPost` publishes straight to the profile, needs `video.publish` plus an audited client, and requires a privacy level.

```php
use SocialPoster\Platforms\TikTok\{TikTokOptions, TikTokTarget, TikTokPrivacy};

// Safe default: upload to drafts, no audit required.
->withOptions(new TikTokOptions())

// Direct post to the feed.
->withOptions(new TikTokOptions(
    target: TikTokTarget::DirectPost,
    privacyLevel: TikTokPrivacy::PublicToEveryone,
    disableComment: true,        // optional; omitted unless set or force-disabled
    coverTimestampMs: 1500,      // video cover frame
))
```

`TikTokPrivacy`: `PublicToEveryone`, `MutualFollowFriends`, `FollowerOfCreator`, `SelfOnly`. The level you choose is validated against what the creator actually permits.

### YouTube

YouTube uploads one video. The builder's `->title()` becomes the video title (required, max 100) and `->caption()` becomes the description (max 5000), since YouTube has both fields. An optional thumbnail is set after the upload and never fails the post; if it can't be set (for example the channel is not eligible for custom thumbnails) the reason lands in `PostResult->payload['thumbnail_warning']`.

```php
use SocialPoster\Platforms\YouTube\{YouTubeOptions, YouTubePrivacy};

SocialPoster::on('youtube')
    ->using($creds)
    ->media(Media::video('https://cdn.example.com/episode.mp4'))
    ->title('Vive la Vie, Episode 1')
    ->caption("The full description goes here.")
    ->withOptions(new YouTubeOptions(
        privacyStatus: YouTubePrivacy::Unlisted, // default is Public
        tags: ['paris', 'piano'],
        categoryId: '22',                        // People & Blogs (default)
        madeForKids: false,
        thumbnail: Media::image('https://cdn.example.com/cover.jpg'),
    ))
    ->post();
```

`YouTubePrivacy`: `Public`, `Unlisted`, `Private`. Community posts are not supported, since the Data API exposes no endpoint to create them.

### X / Threads

X and Threads have no typed options of their own. Use `RawOptions` to bind a raw payload to them (see below).

## Extra parameters (the escape hatch)

No package should mirror every vendor's full parameter surface. The long tail of platform fields (Instagram trial reels, X reply settings, TikTok disclosure flags, YouTube scheduling, and whatever ships next quarter) flows through one `extra` bag that the driver merges into its post-level create request. Anything in `extra` is unvalidated by design, and driver-computed keys always win on collision, so the hatch can add fields but never quietly override what the driver already decided.

Every typed options object accepts `extra`. Platforms without a typed class use `RawOptions`.

### Instagram reels: common extras

These are the fields people reach for most when creating a reel, merged into the `/media` container create. This is a convenience shortlist, not the full surface; the authoritative list is Meta's [IG User media reference](https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-user/media#creating).

| Key                           | Type   | Notes                                                            |
| ----------------------------- | ------ | ---------------------------------------------------------------- |
| `share_to_feed`               | bool   | Show the reel in the Feed tab as well as Reels.                  |
| `collaborators`               | array  | Up to 3 Instagram usernames as collaborators.                    |
| `user_tags`                   | array  | `[{username, x, y}]` to tag users.                               |
| `location_id`                 | string | Page ID of a location to tag.                                    |
| `audio_name`                  | string | Rename the reel's audio (once).                                  |
| `thumb_offset`                | int    | Cover frame in ms (ignored if a cover is set via `thumbnail`).   |
| `trial_params`                | object | `{graduation_strategy: MANUAL\|SS_PERFORMANCE}` for trial reels. |
| `is_paid_partnership`         | bool   | Show the paid-partnership label.                                 |
| `branded_content_sponsor_ids` | array  | Up to 2 sponsor IG user IDs.                                     |

Note that `thumbnail` (the reel cover) is already a typed option on `InstagramOptions`, so reach for that rather than `cover_url`.

```php
->withOptions(new InstagramOptions(
    extra: [
        'share_to_feed' => true,
        'collaborators' => ['laura', 'theo'],
        'location_id'   => '123456789',
    ],
))
```

### Instagram: trial reels

```php
->withOptions(new InstagramOptions(
    extra: ['trial_params' => ['graduation_strategy' => 'MANUAL']],
))
```

### Instagram: tagged users and a location

```php
->withOptions(new InstagramOptions(
    extra: [
        'user_tags'   => [['username' => 'laura', 'x' => 0.5, 'y' => 0.4]],
        'location_id' => '123456789',
    ],
))
```

### X: reply settings (no typed class needed)

```php
use SocialPoster\ValueObjects\RawOptions;
use SocialPoster\Enums\Platform;

->withOptions(new RawOptions(Platform::X, [
    'reply_settings' => 'following', // only people you follow can reply
]))
```

### TikTok: branded content disclosure and AI labelling

For TikTok the bag merges into `post_info`, which is where its tweakable fields live.

```php
->withOptions(new TikTokOptions(
    target: TikTokTarget::DirectPost,
    privacyLevel: TikTokPrivacy::PublicToEveryone,
    extra: [
        'brand_content_toggle' => true,  // paid partnership
        'is_aigc'              => true,  // label as AI-generated
    ],
))
```

### YouTube: schedule a publish

For YouTube the bag deep-merges into the matching `snippet` or `status` sub-object, so you can add fields without clobbering the rest. A scheduled publish requires a private video.

```php
->withOptions(new YouTubeOptions(
    privacyStatus: YouTubePrivacy::Private,
    extra: ['status' => ['publishAt' => '2026-07-01T09:00:00Z']],
))
```

### Facebook: schedule instead of publish now

```php
use SocialPoster\Platforms\Facebook\FacebookOptions;

->withOptions(new FacebookOptions(
    extra: [
        'published'              => false,
        'scheduled_publish_time' => now()->addDay()->timestamp,
    ],
))
```

## Correlation metadata

`extra` goes out to the platform. `withMetadata()` is its mirror image: opaque data that never leaves the package and rides back to you, so you can tie an outcome to whatever you track internally (a scheduled post id, a job uuid, a batch tag).

```php
SocialPoster::on('instagram', 'youtube')
    ->using($creds)
    ->media($video)
    ->title('Episode 1')
    ->caption('...')
    ->withMetadata(['scheduled_post_id' => 10])
    ->post();
```

It comes back on every outcome. `postNow()` returns results with it attached on success and failure, and the queued path carries it through job serialization onto the events, including a failure deep in the async resume loop.

```php
public function handle(PostPublished $event): void
{
    $id = $event->result->metadata['scheduled_post_id'] ?? null;
    // mark scheduled post #10 published; store $event->result->platformPostId
}

public function handle(PostFailed $event): void
{
    $id = $event->metadata['scheduled_post_id'] ?? null;
    // mark scheduled post #10 failed; log $event->exception->reason
}
```

`PostQueued` carries it too. It is kept separate from `PostResult->payload` (which is what the platform returned) so your keys can never collide with a platform's. Because it serializes into the queue payload for `post()`, keep it to scalars and arrays (ids, strings, flags), not Eloquent models or closures.

## First comments

`->withComment('text')` adds a first comment to a post, the common "link in the first comment" pattern. It runs as a separate step after the post is confirmed live, so the comment can never delay or fail the post itself.

```php
SocialPoster::on('instagram', 'linkedin')
    ->using($creds)
    ->media($image)
    ->caption('New on the blog.')
    ->withComment('Read it here: https://example.com/post')
    ->withMetadata(['scheduled_post_id' => 10])
    ->post();
```

Supported on Instagram, Facebook feed posts, and LinkedIn. On any other platform in the call (X, Threads, TikTok, YouTube) the comment is simply skipped, so the example above comments on Instagram and LinkedIn and does nothing on the rest. Outcomes arrive via their own events, which carry the same `metadata` so you can correlate them:

```php
use SocialPoster\Events\{CommentPublished, CommentFailed};

public function handle(CommentPublished $event): void
{
    // $event->platform, $event->postId, $event->commentId, $event->metadata
}
```

For `post()` the comment is a separate queued job dispatched once the post is live (after a small `comment_delay`, configurable). For `postNow()` it runs inline right after the post. TikTok is not included because its API has no endpoint to create comments.

Many platforms process media asynchronously (TikTok, Instagram reels and carousels, LinkedIn video, X video, Facebook reels). The package models this without ever blocking a worker. YouTube, by contrast, returns the video id as soon as the upload completes, so it publishes synchronously.

A driver's `publish()` returns either a finished result or a `Pending` state with a recheck delay. When pending, the queued job re-dispatches itself with a delay, carrying the state, and calls `resume()` until the post completes. Synchronous platforms simply return finished and never implement `resume()`.

`post()` dispatches these jobs on the queue from `config('social.queue')`. The jobs are unique until processing, so a duplicate dispatch of the same content is collapsed. Events fire along the way:

```php
use SocialPoster\Events\{PostQueued, PostPublished, PostFailed};

// PostPublished carries a PostResult; PostFailed carries the platform, exception, and metadata.
```

## Idempotency and duplicate protection

The queue guarantees at-least-once delivery of the publish job, which is what you want, but it means a job can be retried after its publish call already succeeded on the platform (worker killed, timeout reading the response, a deploy mid-flight), and the retry would post again. No native queue feature can prevent this, because the duplicate happens inside one job's retry cycle and the already-created post lives on the platform, outside Laravel. Social APIs do not offer idempotency keys for content creation, so the package keeps the record itself.

By default nothing is remembered and behaviour is unchanged. To turn on protection, point the store at the database and publish its migration:

```php
// config/social.php
'idempotency' => SocialPoster\Idempotency\DatabaseIdempotencyStore::class,
```

```bash
php artisan vendor:publish --tag=social-poster-migrations
php artisan migrate
```

With it enabled, a retry of an already-published post replays the recorded result instead of posting again, and a retry of an interrupted async upload resumes from the saved state rather than re-creating. Each `post()` call gets a stable key per platform; tie it to your own identifier so two separate calls for the same record never double-post:

```php
SocialPoster::on('instagram')->media($m)->caption('...')
    ->idempotencyKey('schedule:10')
    ->post();
```

One honest limit: a crash in the narrow window between the platform call returning and the store writing cannot be fully closed without platform-side idempotency keys, which these APIs do not provide. The store shrinks the risk from "every retry re-posts" to that fraction-of-a-second window. This protection covers the queued `post()` path; `postNow()` runs once in-process and is not subject to queue retries.

## Validation and errors

`validate()` returns a `ValidationResult` per platform and never throws, so it suits form submissions.

```php
$result = SocialPoster::on('x')->media($media)->caption($caption)->validate()['x'];
$result->passes();          // bool
$result->errors();          // Illuminate\Support\MessageBag, keyed by field
$result->toArray();         // JSON friendly
```

`postNow()` and the queued jobs throw three exceptions, keyed on how you should handle them. Each carries the `platform`, a `FailureReason`, and a `context` array.

| Exception             | Meaning                | Extra                  |
| --------------------- | ---------------------- | ---------------------- |
| `ValidationException` | Local rules failed     | `errors` (MessageBag)  |
| `TemporaryException`  | Transient; retry later | `retryAfter` (seconds) |
| `PermanentException`  | Will not succeed as-is | —                      |

`FailureReason` gives the granularity without an exception per case: `RateLimited`, `ServerError`, `Timeout`, `InvalidToken`, `InsufficientPermissions`, `AccountRestricted`, `MediaRejected`, `DuplicateContent`, `Unknown`.

```php
use SocialPoster\Exceptions\{TemporaryException, PermanentException};

try {
    SocialPoster::on('tiktok')->using($creds)->media($video)->caption('...')->postNow();
} catch (TemporaryException $e) {
    // requeue after $e->retryAfter seconds
} catch (PermanentException $e) {
    report($e); // $e->reason, $e->context
}
```

## Media transfer: pull vs upload

Platforms either pull media from a public URL or have bytes uploaded to them. The `MediaGateway` resolves this so drivers never care.

-   **Pull (Facebook, Instagram, Threads):** you must hand a publicly reachable URL. A local file fails validation early with a clear message.
-   **Upload (LinkedIn, X, YouTube):** local files and remote URLs both work; remote is downloaded to a temp file and pushed as bytes.
-   **Both (TikTok):** a public URL uses `PULL_FROM_URL`; a local filesystem path uses chunked `FILE_UPLOAD`. Note that `Storage::disk('public')->url('clip.mp4')` is still a URL (pull, needs a verified domain), whereas `Storage::disk('public')->path('clip.mp4')` is a local path (upload, no domain check). TikTok photos are pull-only.

The default `LocalMediaGateway` passes remote URLs through and downloads them when bytes are needed. Bind your own gateway (for example to sign or publish local files) in the config.

## Configuration

`config/social.php`:

```php
return [
    'default'  => null,                         // default platform when none is given
    'queue'    => env('SOCIAL_QUEUE'),          // queue for post() jobs
    'inspector'=> FFProbeInspector::class,      // probes media for validation
    'gateway'  => LocalMediaGateway::class,     // resolves pull vs upload
    'drivers'  => [ /* platform => driver class; override or extend here */ ],
    'platforms'=> [
        'instagram' => ['credentials' => [
            'account_id'   => env('IG_USER_ID'),
            'access_token' => env('IG_ACCESS_TOKEN'),
        ]],
        // ...one block per platform
    ],
];
```

Media probing (durations, dimensions) uses `ffprobe` when available and degrades gracefully when it is not.

## Writing a driver

Extend `AbstractPlatform`. You get generic capability validation, the async publish/resume loop, and media transfer for free. Declare your rules with `Capabilities`, and use the hooks for anything conditional.

```php
use SocialPoster\Capabilities\{Capabilities, CaptionRules, MediaRules, VideoRules};
use SocialPoster\Enums\Platform;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\ValueObjects\{PreparedPost, PublishOutcome};

class MyPlatformDriver extends AbstractPlatform
{
    public function platform(): Platform { return Platform::MyPlatform; }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            caption: new CaptionRules(max: 2000),
            media: new MediaRules(minCount: 1, maxCount: 1, video: new VideoRules(extensions: ['mp4'])),
        );
    }

    public function publish(PreparedPost $post): PublishOutcome
    {
        // ... call the API, then:
        return $this->published(/* PostResult */);
        // or, for async processing:
        // return $this->pending(['phase' => 'await_processing', 'id' => $id], recheckAfter: 15);
    }
}
```

Useful hooks and helpers on the base class: `rulesFor()` (resolve which capabilities apply to this specific post), `validatePlatformRules()` (conditional and cross-field checks), `prepare()` (content transformation), `mediaUrl()` / `mediaPath()` (pull vs upload), `mergeExtra()` (fold the escape hatch into a create request), and `pending()` / `published()`.

Register it in the `drivers` map and you are done.
