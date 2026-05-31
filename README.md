# Social Poster (spine scaffold)

A cleanly-architected Laravel package for publishing content to multiple social
platforms. This is the platform-agnostic spine: contracts, value objects, the
builder, validation, jobs and events. No concrete platform drivers ship yet,
that is the next step.

## Rename before use

The placeholder namespace is `SocialPoster\` and the composer name is
`vendor/social-poster`. Set your own vendor in `composer.json` and run a
find/replace on the namespace if you want it branded.

## Public surface

```php
use SocialPoster\Facades\SocialPoster;
use SocialPoster\ValueObjects\Media;

// Same simple content to several platforms, queued.
SocialPoster::on('instagram', 'facebook')
    ->media('https://cdn.example.com/sunset.mp4')
    ->caption('Sunset at the Crillon')
    ->post();

// Divergent content / options: one explicit line per platform.
SocialPoster::on('tiktok')
    ->using($credentials)                 // or omit for the config credentials
    ->media(Media::video('/path/clip.mp4'))
    ->caption('...')
    ->withOptions(new TikTokOptions(...))  // a PlatformOptions implementation
    ->post();

// Validate only, no posting. Returns an Arrayable/Jsonable result per platform.
$results = SocialPoster::on('instagram')->media($media)->caption($caption)->validate();
$results['instagram']->passes();
$results['instagram']->errors();

// Synchronous, returns PostResult per platform.
$results = SocialPoster::on('instagram')->media($media)->caption('...')->postNow();

// Skip the local validation gate (rule drift, or already validated upstream).
SocialPoster::on('instagram')->media($media)->caption('...')->withoutValidation()->post();
```

## Writing a driver

Extend `AbstractPlatform`. You get generic capability validation for free, plus
two slots for platform-specific behaviour: `validatePlatformRules()` for
conditional validation and `prepare()` for content transformation.

```php
use Illuminate\Support\MessageBag;
use SocialPoster\Capabilities\{Capabilities, CaptionRules, MediaRules, VideoRules};
use SocialPoster\Enums\Platform;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\ValueObjects\{PostResult, PreparedPost};

class ThreadsDriver extends AbstractPlatform
{
    public function platform(): Platform
    {
        return Platform::Threads;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            caption: new CaptionRules(max: 500),
            media: new MediaRules(maxCount: 20, video: new VideoRules(maxDuration: 300)),
        );
    }

    // Conditional rule that capabilities cannot express.
    protected function validatePlatformRules(PreparedPost $post, MessageBag $errors): void
    {
        if ($post->media() === [] && ($post->caption() === null || $post->caption() === '')) {
            $errors->add('caption', 'A caption is required when no media is attached.');
        }
    }

    // Transform into the platform payload (escaping, formatting). Never mutates
    // the user's original content.
    protected function prepare(PreparedPost $post): array
    {
        return [
            'text' => $post->caption(),
            // ... media containers, etc.
        ];
    }

    public function publish(PreparedPost $post): PostResult
    {
        $payload = $this->prepare($post);
        // ... call the API; map HTTP errors into Temporary/Permanent exceptions.
        return PostResult::success($this->platform(), platformPostId: 'abc', url: 'https://...');
    }
}
```

Register it in `config/social.php`:

```php
'drivers' => [
    SocialPoster\Enums\Platform::Threads->value => \App\Social\ThreadsDriver::class,
],
```

## File map

```
src/
  Capabilities/      Capabilities + CaptionRules / MediaRules / ImageRules / VideoRules
  Concerns/          ValidatesAgainstCapabilities (generic validation)
  Contracts/         SocialPlatform, PlatformOptions, MediaInspector
  Enums/             Platform, MediaType, FailureReason
  Events/            PostQueued, PostPublished, PostFailed
  Exceptions/        SocialPosterException (+ Temporary / Permanent / Validation)
  Inspectors/        FFProbeInspector (default MediaInspector)
  Jobs/              PublishToConnectionJob
  Platforms/         AbstractPlatform (base driver)
  ValueObjects/      Media, MediaMetadata, Credentials, SocialPost,
                     PreparedPost, PostResult, ValidationResult
  Poster.php         Facade root
  PostBuilder.php    Fluent surface
  SocialManager.php  Driver resolver
  SocialPosterServiceProvider.php
  Facades/SocialPoster.php
```

## Local vs remote media

A `Media` source is either a local path or a remote URL (`isRemote()`). Platforms
either pull media from a public URL (Facebook, Instagram) or need the raw bytes
(X, LinkedIn, Mastodon). A driver declares its `ingestion()` mode and never
touches the source directly: it asks the injected `MediaGateway` for `mediaUrl()`
(pull) or `mediaPath()` (upload, downloading remote media to a temp file).

The only impossible combination is a local file on a pull-only platform: there is
no URL for the platform to fetch. The default `LocalMediaGateway` reports this at
`validate()` time rather than after queuing. To post local files to pull-only
platforms, bind your own `MediaGateway` that uploads to public storage (e.g. S3)
and returns a signed URL:

```php
// config/social.php
'gateway' => \App\Social\S3MediaGateway::class,
```

## Async publishing (the container model)

Some platforms process media asynchronously: you create a container, the platform
ingests and transcodes it (seconds to many minutes), then you publish. The package
models this without ever blocking a worker.

A driver's `publish()` returns a `PublishOutcome`, either:

- `Published` (terminal) carrying a `PostResult`, or
- `Pending` carrying serialisable `state` and a `recheckAfter` delay.

When `post()` is queued and a driver returns `Pending`, the job dispatches a
delayed continuation carrying that state, which calls the driver's `resume()` on
its run. This repeats until `Published` or the poll budget runs out, so no worker
sits blocked. (`postNow()` drives the same loop in-process with real sleeps, since
there you asked to block.)

Synchronous platforms just return `Published` from `publish()` and never implement
`resume()`. A driver authoring an async flow looks like:

```php
public function publish(PreparedPost $post): PublishOutcome
{
    $id = $this->createContainer($post);                       // start
    return $this->pending(['phase' => 'await', 'id' => $id], 20);
}

public function resume(PreparedPost $post, array $state): PublishOutcome
{
    return match ($this->status($post, $state['id'])) {
        'FINISHED' => $this->published($this->publishContainer($post, $state['id'])),
        'ERROR'    => throw new PermanentException('Processing failed.', $this->platform()),
        default    => $this->pending($state, 30),              // keep waiting
    };
}
```

A "container not ready yet" response (Instagram's 2207027) is just a `Pending`
with a long `recheckAfter`, which is what a separate delayed-publication job used
to do.

## Documents and byte upload

Media is one of three types: image, video, or document (PDF, doc, docx, ppt,
pptx). A platform advertises support by providing the matching rule set
(`image`, `video`, `document`) on its `MediaRules`; anything without a rule set
is rejected with a clear message.

Pull-from-URL platforms (Facebook, Instagram) hand the platform a URL. Upload
platforms (LinkedIn) push the bytes: the driver calls `mediaPath()`, which the
gateway resolves to a local file, downloading remote media to a temp file and
cleaning it up after the upload. Because uploads always work from bytes, local
files are fine on upload platforms; only pull platforms need a public URL.

LinkedIn's `prepare()` escapes the reserved characters (`()[]{}@*<>\_~`). Mention
resolution and link-preview cards are deliberately out of scope: build the
structured "little text" yourself and pass `LinkedInOptions(escapeText: false)`
so the escaping does not clobber it.
