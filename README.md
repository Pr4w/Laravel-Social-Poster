# Social Poster

A cleanly architected Laravel package for publishing photos, videos, and documents to multiple social platforms behind one fluent API. You describe the post once; each platform driver handles its own validation, media transfer, async processing, and error mapping.

Supported platforms: **Facebook, Instagram, Threads, LinkedIn, X, TikTok.**

## Contents

-   [Install](#install)
-   [Quick start](#quick-start)
-   [The builder](#the-builder)
-   [Credentials](#credentials)
-   [Media](#media)
-   [Platform options](#platform-options)
-   [Extra parameters (the escape hatch)](#extra-parameters-the-escape-hatch)
-   [Async publishing](#async-publishing)
-   [Validation and errors](#validation-and-errors)
-   [Media transfer: pull vs upload](#media-transfer-pull-vs-upload)
-   [Configuration](#configuration)
-   [Writing a driver](#writing-a-driver)

## Install

```bash
composer require vendor/social-poster
php artisan vendor:publish --tag=social-poster-config
```

The placeholder namespace is `SocialPoster\` and the composer name is `vendor/social-poster`. Set your own vendor in `composer.json` and find/replace the namespace if you want it branded.

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
    ->title($title)               // platforms that use a title (LinkedIn docs, etc.)
    ->withOptions($options)       // a PlatformOptions implementation, per platform
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
    isStory: true,                                  // post as a story
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

### X / Threads

X and Threads have no typed options of their own. Use `RawOptions` to bind a raw payload to them (see below).

## Extra parameters (the escape hatch)

No package should mirror every vendor's full parameter surface. The long tail of platform fields (Instagram trial reels, X reply settings, TikTok disclosure flags, and whatever ships next quarter) flows through one `extra` bag that the driver merges into its post-level create request. Anything in `extra` is unvalidated by design, and driver-computed keys always win on collision, so the hatch can add fields but never quietly override what the driver already decided.

Every typed options object accepts `extra`. Platforms without a typed class use `RawOptions`.

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

For TikTok the bag merges into `post_info`, which is where its tweakable fields live. Disclosure defaults to off but `extra` can turn it on.

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

## Async publishing

Many platforms process media asynchronously (TikTok, Instagram reels and carousels, LinkedIn video, X video, Facebook reels). The package models this without ever blocking a worker.

A driver's `publish()` returns either a finished result or a `Pending` state with a recheck delay. When pending, the queued job re-dispatches itself with a delay, carrying the state, and calls `resume()` until the post completes. Synchronous platforms simply return finished and never implement `resume()`.

`post()` dispatches these jobs on the queue from `config('social.queue')`. The jobs are unique until processing, so a duplicate dispatch of the same content is collapsed. Events fire along the way:

```php
use SocialPoster\Events\{PostQueued, PostPublished, PostFailed};

// PostPublished carries a PostResult; PostFailed carries the platform and exception.
```

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
-   **Upload (LinkedIn, X):** local files and remote URLs both work; remote is downloaded to a temp file and pushed as bytes.
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
