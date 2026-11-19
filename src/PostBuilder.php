<?php

namespace SocialPoster;

use DateTimeInterface;
use Illuminate\Bus\UniqueLock;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use InvalidArgumentException;
use LogicException;
use SocialPoster\Contracts\AtomicIdempotencyStore;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Contracts\IdempotencyStore;
use SocialPoster\Contracts\MediaGateway;
use SocialPoster\Contracts\MediaTransformer;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Contracts\SocialPlatform;
use SocialPoster\Contracts\SupportsComments;
use SocialPoster\Contracts\SupportsFirstComment;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\CommentFailed;
use SocialPoster\Events\CommentSkipped;
use SocialPoster\Events\CredentialsRejected;
use SocialPoster\Events\PostDeduplicated;
use SocialPoster\Events\PostFailed;
use SocialPoster\Events\PostProcessing;
use SocialPoster\Events\PostPublished;
use SocialPoster\Events\PostQueued;
use SocialPoster\Events\PostRetrying;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Exceptions\UnsupportedPlatformException;
use SocialPoster\Exceptions\ValidationException;
use SocialPoster\Idempotency\KeyCollisionException;
use SocialPoster\Jobs\AddFirstCommentJob;
use SocialPoster\Jobs\PublishToConnectionJob;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\Resolvers\IdentityCredentialsResolver;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\Published;
use SocialPoster\ValueObjects\QueuedPost;
use SocialPoster\ValueObjects\SocialPost;
use SocialPoster\ValueObjects\ValidationResult;
use Throwable;

/**
 * The fluent surface. Terminal methods are just stages of one pipeline:
 *
 *   validate()  -> stage 1 only, returns results (never throws)
 *   postNow()   -> validate (all-or-nothing), then publish synchronously
 *   post()      -> validate (all-or-nothing), then queue one job per platform
 *
 * withoutValidation() skips stage 1 in the post paths; bestEffort() turns
 * all-or-nothing into per platform (a platform that fails validation is
 * reported with PostFailed and skipped, the others go ahead).
 *
 * Per-platform inputs: using($bag, 'instagram') or using(['instagram' => [...],
 * 'linkedin' => [...]]) gives each platform its own credentials (a fan-out
 * with one shared bag sends one platform's token to the other), and
 * withOptions($instagramOptions, $linkedInOptions) gives each its own options.
 *
 * A fan-out never aborts half way: every platform gets its own result or
 * event, whatever another platform throws.
 *
 * With no ->on(), config('social.default') is used; with neither, the
 * terminals throw instead of silently posting nowhere.
 */
class PostBuilder
{
    /** @var Platform[] */
    protected array $platforms = [];

    /** Shared credentials for every platform (v1.0's single bag). */
    protected ?Credentials $credentials = null;

    /** @var Media[] */
    protected array $media = [];

    protected ?string $caption = null;

    protected ?string $title = null;

    /** The v1.0 single options slot: the last options object given. */
    protected ?PlatformOptions $options = null;

    /** @var array<string, mixed> */
    protected array $metadata = [];

    protected ?string $comment = null;

    protected ?string $idempotencyKey = null;

    protected bool $skipValidation = false;

    // --- 1.1 ----------------------------------------------------------------

    /** @var array<string, Credentials> Per-platform credentials, by platform value. */
    protected array $platformCredentials = [];

    /** @var array<string, PlatformOptions> Per-platform options, by platform value. */
    protected array $platformOptions = [];

    /** True: dispatch after the open transaction commits; false: before; null: config. */
    protected ?bool $afterCommit = null;

    /** Unix time after which the post must not be created. */
    protected ?int $notAfter = null;

    protected bool $bestEffort = false;

    /** postNow(): add the first comment in-process instead of queueing it; null: config. */
    protected ?bool $inlineComment = null;

    /**
     * The in-process publishes of the current postNow(), by platform value:
     * the store key and correlation id (the comment step reuses them), the
     * idempotency store (null when it could not be reached) and the lease owner.
     *
     * @var array<string, array{key: string, correlation_id: string, store: ?IdempotencyStore, owner: string, driver: SocialPlatform}>
     */
    protected array $runs = [];

    /**
     * Platforms this process already logged the "credentials name no
     * account" warning for (see unidentifiedAccountWarning()): once each.
     *
     * @var array<string, true>
     */
    protected static array $warnedUnidentified = [];

    /** @param array<string, mixed> $config config('social') */
    public function __construct(
        protected SocialManager $manager,
        protected Dispatcher $events,
        protected array $config = [],
    ) {}

    /**
     * The platforms to post to: values ('instagram') or Platform cases. An
     * unknown name throws UnsupportedPlatformException right away; a platform
     * listed twice is posted once.
     */
    public function on(string|Platform ...$platforms): static
    {
        foreach ($platforms as $platform) {
            $resolved = $this->platformFrom($platform);

            if (! in_array($resolved, $this->platforms, true)) {
                $this->platforms[] = $resolved;
            }
        }

        return $this;
    }

    /**
     * Credentials, in one of three forms:
     *
     *   ->using($bag)                                          // every platform (v1.0)
     *   ->using($bag, 'instagram')                             // one platform
     *   ->using(['instagram' => [...], 'linkedin' => [...]])   // a map keyed by platform
     *
     * A platform's own bag wins over the shared one, which wins over
     * config('social.platforms.<platform>.credentials'). Later calls replace
     * the bags they name. A map is recognised when every key is a platform
     * name and every value an array or Credentials.
     *
     * @param  Credentials|array<string, mixed>  $credentials
     */
    public function using(Credentials|array $credentials, string|Platform|null $for = null): static
    {
        if ($for !== null) {
            $this->platformCredentials[$this->platformFrom($for)->value] = $this->toCredentials($credentials);

            return $this;
        }

        if (is_array($credentials) && $this->isPlatformMap($credentials)) {
            foreach ($credentials as $platform => $bag) {
                $this->platformCredentials[$this->platformFrom((string) $platform)->value] = $this->toCredentials($bag);
            }

            return $this;
        }

        $this->credentials = $this->toCredentials($credentials);

        return $this;
    }

    public function media(string|Media ...$media): static
    {
        foreach ($media as $item) {
            $this->media[] = $item instanceof Media ? $item : Media::guess($item);
        }

        return $this;
    }

    public function caption(?string $caption): static
    {
        $this->caption = $caption;

        return $this;
    }

    public function title(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Platform options, one object per platform, each applied only to its own
     * platform:
     *
     *   ->withOptions(new InstagramOptions(isStory: true), new LinkedInOptions(...))
     *
     * Two objects for one platform in the same call throw; a later call
     * replaces the options of the platforms it names and keeps the others.
     *
     * @throws InvalidArgumentException
     */
    public function withOptions(PlatformOptions ...$options): static
    {
        $given = [];

        foreach ($options as $option) {
            $value = $option->platform()->value;

            if (isset($given[$value])) {
                throw new InvalidArgumentException("withOptions() received two options objects for [{$value}]; pass one per platform.");
            }

            $given[$value] = $option;
        }

        if ($given !== []) {
            $this->platformOptions = array_replace($this->platformOptions, $given);
            $this->options = $given[array_key_last($given)];
        }

        return $this;
    }

    /**
     * Attach opaque correlation data to this post. The package never sends it to
     * any platform; it rides back on the PostResult and on the PostQueued/
     * PostFailed events so you can tie an outcome to your own records.
     *
     *   ->withMetadata(['scheduled_post_id' => 10])->post();
     *
     * Repeated calls merge.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): static
    {
        $this->metadata = array_merge($this->metadata, $metadata);

        return $this;
    }

    /**
     * Add a first comment, posted as a separate step once the post is live. Takes
     * effect only on platforms that support commenting; elsewhere CommentSkipped
     * says why. Outcomes arrive via the CommentPublished / CommentFailed events,
     * and a comment failure never affects the post.
     */
    public function withComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    /**
     * Set a stable idempotency key for this post, tying duplicate protection to
     * your own identifier (e.g. "schedule:10"). The key is combined with each
     * platform and account, computed once per call, and carried by every job:
     *
     * - ->post(): a second call with the same key while the first is queued is
     *   dropped (PostDeduplicated); once published, a repeat replays the result;
     * - ->postNow(): the same store is consulted (replay, claim, record), and
     *   a run of the key that stopped mid-way is continued, not restarted;
     * - ->outcome() / ->reconcile() find the publish again by this key.
     *
     * A publish v1.0 recorded under the same key is replayed too. Defaults to
     * a fresh key per call (the events still report the store key it gives).
     */
    public function idempotencyKey(string $key): static
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    public function withoutValidation(): static
    {
        $this->skipValidation = true;

        return $this;
    }

    /**
     * Validate per platform instead of all-or-nothing: a platform that fails
     * validation (or has no driver) is reported with PostFailed and skipped,
     * the others are published. post() lists the skipped ones in
     * QueuedPost::$failed; postNow() returns a failed PostResult for them.
     */
    public function bestEffort(bool $bestEffort = true): static
    {
        $this->bestEffort = $bestEffort;

        return $this;
    }

    /**
     * Dispatch the publish jobs only after the open database transaction
     * commits (false: right away), so a rolled-back record never gets a post.
     * Without it, config('social.after_commit') and the connection decide.
     */
    public function afterCommit(bool $afterCommit = true): static
    {
        $this->afterCommit = $afterCommit;

        return $this;
    }

    /**
     * Never create the post after this moment (a time-sensitive announcement,
     * a retry window). A post past the window fails with FailureReason::Expired
     * and nothing is published; a post the platform already accepted is still
     * finished.
     */
    public function notAfter(DateTimeInterface|int|null $moment): static
    {
        $this->notAfter = $moment instanceof DateTimeInterface ? $moment->getTimestamp() : $moment;

        return $this;
    }

    /**
     * postNow() only: add the first comment in-process right after the post,
     * instead of queueing it (the default, which also honours
     * social.comment_delay). A platform that requires a delay before
     * commenting (CommentRules::$minDelaySeconds, e.g. LinkedIn) is always
     * queued; on the 'sync' queue connection, which cannot delay a job,
     * postNow() waits that minimum in-process instead (up to
     * social.sync.max_wait; beyond it CommentFailed says why).
     */
    public function inlineComment(bool $inline = true): static
    {
        $this->inlineComment = $inline;

        return $this;
    }

    // --- Terminals ----------------------------------------------------------------

    /**
     * Validate only, no posting. Keyed by platform value. Never throws for a
     * platform: a platform without a driver, or whose validation could not
     * run, gets a ValidationResult error under 'platform'. Warnings never fail
     * the result: 'idempotency' says the ->idempotencyKey() cannot keep two
     * accounts apart because the credentials name no account.
     *
     * @return array<string, ValidationResult>
     */
    public function validate(): array
    {
        $results = [];

        foreach ($this->prepareTargets(false) as $value => $target) {
            $warning = $target['driver'] !== null ? $this->unidentifiedAccountWarning($target['driver'], $target['post']) : null;

            $results[$value] = new ValidationResult(
                $target['platform'],
                $this->validationErrors($target),
                new MessageBag($warning !== null ? ['idempotency' => [$warning]] : []),
            );
        }

        return $results;
    }

    /**
     * Publish synchronously, returning one PostResult per platform (keyed by
     * platform value) whatever happens to the others. Publish failures do not
     * throw: they come back as failed results (and PostFailed events).
     * Validation is all-or-nothing up front (ValidationException) unless
     * bestEffort() is on.
     *
     * Per platform: a TemporaryException is retried in-process (social.sync.retries,
     * waiting the platform's Retry-After or social.sync.backoff); async
     * processing is polled while it fits social.sync.max_wait. Past that, the
     * publish is handed to the queue (PublishToConnectionJob continues it) and
     * the result is a success with state 'processing': the final outcome
     * arrives through PostPublished / PostFailed. On the 'sync' queue
     * connection there is no queue to hand over to, so it times out instead.
     *
     * The first comment is queued once the post is live (inline with
     * inlineComment()); a comment failure never changes the post's result.
     *
     * @return array<string, PostResult>
     */
    public function postNow(): array
    {
        $targets = $this->prepareTargets(true);
        $rejected = $this->gate($targets);
        $correlationId = $this->correlationId();
        $results = [];
        $this->runs = [];

        try {
            foreach ($targets as $value => $target) {
                $post = $target['post'];
                $driver = $target['driver'];

                if (isset($rejected[$value]) || $driver === null) {
                    $error = $rejected[$value] ?? $this->driverException($target);
                    $this->safeDispatch(new PostFailed($post->platform, $error, $post->metadata(), $correlationId));
                    $results[$value] = PostResult::failed($post->platform, $error)->withMetadata($post->metadata());

                    continue;
                }

                $results[$value] = $this->publishNow($driver, $post, $correlationId);

                // After the publish decided the result: nothing here may change it.
                try {
                    $this->commentInline($driver, $post, $results[$value]);
                } catch (Throwable $e) {
                    $this->reportQuietly($e);
                }
            }
        } finally {
            $this->cleanupMedia($results);
        }

        return $results;
    }

    /**
     * Delete this process's temporary media (gateway downloads, transformer
     * conversions) once every platform is done, as the job does when a chain
     * ends. Skipped when a platform was handed to the queue: a continuation
     * running on this machine may still read a file. social-poster:prune-temp
     * is the backstop either way.
     *
     * @param  array<string, PostResult>  $results
     */
    protected function cleanupMedia(array $results): void
    {
        foreach ($results as $result) {
            if (($result->payload['handed_off'] ?? false) === true) {
                return;
            }
        }

        $container = Container::getInstance();

        foreach ([MediaGateway::class, MediaTransformer::class] as $abstract) {
            try {
                if (! $container->bound($abstract)) {
                    continue;
                }

                $service = $container->make($abstract);

                if (is_object($service) && method_exists($service, 'cleanup')) {
                    $service->cleanup();
                }
            } catch (Throwable $e) {
                $this->reportQuietly($e);
            }
        }
    }

    /**
     * Queue one job per platform and return what was queued. Outcomes arrive
     * via PostPublished / PostFailed, which carry QueuedPost::$correlationId.
     *
     * Every job is built (and its idempotency key computed, once) before any is
     * pushed. Per platform: the unique lock is taken first (a refusal means the
     * same post is already queued: PostDeduplicated), then PostQueued fires,
     * then the job is pushed; if the push throws, the lock is released and
     * PostFailed fires. One platform's failure never stops the others.
     */
    public function post(): QueuedPost
    {
        $targets = $this->prepareTargets(true);
        $failed = $this->gate($targets);
        $correlationId = $this->correlationId();
        $jobs = [];
        $keys = [];

        foreach ($targets as $value => $target) {
            $driver = $target['driver'];

            if (isset($failed[$value])) {
                continue;
            }

            if ($driver === null) {
                $failed[$value] = $this->driverException($target);

                continue;
            }

            $post = $target['post'];

            try {
                $key = $this->keyFor($driver, $post, $correlationId);
                $this->adoptLegacyRecord($key, $post->platform);
                $jobs[$value] = [$this->makeJob($post, $correlationId, $key), $post, $key];
                $keys[$value] = $key;
            } catch (Throwable $e) {
                $failed[$value] = $this->asPackageException($e, $post->platform, 'could not be prepared for the queue');
            }
        }

        foreach ($failed as $value => $error) {
            $post = $targets[$value]['post'];
            $this->safeDispatch(new PostFailed($post->platform, $error, $post->metadata(), $correlationId, $keys[$value] ?? null));
        }

        $queued = [];
        $deduplicated = [];

        foreach ($jobs as $value => [$job, $post, $key]) {
            $outcome = $this->queue($job, $post, $key, $correlationId);

            if ($outcome === true) {
                $queued[] = $post->platform;
            } elseif ($outcome === false) {
                $deduplicated[] = $post->platform;
            } else {
                $failed[$value] = $outcome;
            }
        }

        return new QueuedPost($correlationId, $queued, $keys, $deduplicated, $failed);
    }

    /**
     * The recorded outcome of an earlier publish of this post, per platform:
     * the published PostResult, or null when nothing published is recorded
     * (yet). Finds it by ->idempotencyKey() (or the $key given, which may also
     * be a store key from an event), this builder's platforms and credentials.
     *
     * @return array<string, PostResult|null>
     */
    public function outcome(?string $key = null): array
    {
        $key = $this->lookupKey($key, 'outcome');
        $poster = $this->poster();
        $results = [];

        foreach ($this->prepareTargets(false) as $value => $target) {
            $resolved = $this->resolvedStoreKey($key, $target);

            $results[$value] = ($resolved !== null ? $poster->outcome($resolved, $target['platform']) : null)
                ?? $poster->outcome($key, $target['platform'], $target['post']->credentials);
        }

        return $results;
    }

    /**
     * Finish publishes whose outcome is unknown (a PostFailed with
     * possiblyPublished, a ProcessingTimeout, a worker that died), using this
     * builder's platforms, credentials and content, found by
     * ->idempotencyKey() (or the $key given). Per platform, one of the
     * Poster::RECONCILE_* values: a published record is replayed (PostPublished
     * again, $replayed = true); a pending, committing or failed one is resumed
     * by a job that verifies on the platform before creating anything.
     *
     * @return array<string, string>
     */
    public function reconcile(?string $key = null): array
    {
        $key = $this->lookupKey($key, 'reconcile');
        $poster = $this->poster();
        $results = [];

        foreach ($this->prepareTargets(false) as $value => $target) {
            $resolved = $this->resolvedStoreKey($key, $target);
            $result = $resolved !== null
                ? $poster->reconcile($resolved, $target['platform'], $target['post']->credentials, $target['post']->post)
                : Poster::RECONCILE_NOTHING;

            $results[$value] = $result !== Poster::RECONCILE_NOTHING
                ? $result
                : $poster->reconcile($key, $target['platform'], $target['post']->credentials, $target['post']->post);
        }

        return $results;
    }

    /**
     * Start from an existing post: copies its media, caption, title, options,
     * metadata and comment (e.g. a SocialPost kept from an earlier call).
     */
    public function fromPost(SocialPost $post): static
    {
        $this->media = $post->media;
        $this->caption = $post->caption;
        $this->title = $post->title;
        $this->metadata = $post->metadata;
        $this->comment = $post->comment;
        $this->platformOptions = $post->platformOptions();
        $this->options = $post->options;

        return $this;
    }

    // --- Preparation --------------------------------------------------------------

    /**
     * The selected platforms: ->on(), else config('social.default').
     *
     * @return list<Platform>
     *
     * @throws PermanentException When no platform is selected.
     */
    protected function targets(): array
    {
        if ($this->platforms !== []) {
            return array_values($this->platforms);
        }

        $default = $this->config['default'] ?? null;

        if ($default instanceof Platform || (is_string($default) && trim($default) !== '')) {
            return [$this->platformFrom($default)];
        }

        throw new PermanentException(
            'No platform selected: call ->on(...) or set config(\'social.default\').',
            null,
            FailureReason::Misconfigured,
        );
    }

    /**
     * Every selected platform with its prepared post and driver. A driver that
     * cannot be resolved, or that is not a SocialPlatform (a wrong class in
     * config('social.drivers')), is kept with its error, so it is reported per
     * platform instead of failing the fan-out half way.
     *
     * @return array<string, array{platform: Platform, post: PreparedPost, driver: ?SocialPlatform, error: ?Throwable}>
     */
    protected function prepareTargets(bool $requireCredentials): array
    {
        $post = $this->socialPost();
        $targets = [];

        foreach ($this->targets() as $platform) {
            $driver = null;
            $error = null;

            try {
                $resolved = $this->manager->driver($platform->value);

                if ($resolved instanceof SocialPlatform) {
                    $driver = $resolved;
                } else {
                    $error = new PermanentException(
                        "The driver registered for {$platform->value} (".get_debug_type($resolved).') does not implement '.SocialPlatform::class.'; check config(\'social.drivers\') or your extend() call.',
                        $platform,
                        FailureReason::Misconfigured,
                        ['driver' => get_debug_type($resolved)],
                    );
                }
            } catch (Throwable $e) {
                $error = $e;
            }

            // A platform without a driver is reported as such, not as missing credentials.
            $credentials = $this->credentialsFor($platform, $requireCredentials && $driver !== null);
            $prepared = new PreparedPost($platform, $post, $credentials);

            $targets[$platform->value] = ['platform' => $platform, 'post' => $prepared, 'driver' => $driver, 'error' => $error];
        }

        return $targets;
    }

    /** The post this builder describes; the same object goes to every platform. */
    protected function socialPost(): SocialPost
    {
        // The v1.0 single slot is filled only when it is unambiguous.
        $legacy = count($this->platformOptions) <= 1 ? $this->options : null;

        return new SocialPost(
            $this->media,
            $this->caption,
            $this->title,
            $legacy,
            $this->metadata,
            $this->comment,
            $this->platformOptions,
        );
    }

    /**
     * v1.0 shape of the preparation: [driver, post] per platform. Kept for
     * subclasses; throws UnsupportedPlatformException for a platform without
     * a driver. The 1.1 terminals use prepareTargets().
     *
     * @return array<string, array{0: SocialPlatform, 1: PreparedPost}>
     */
    protected function buildPrepared(bool $requireCredentials): array
    {
        $map = [];

        foreach ($this->prepareTargets($requireCredentials) as $value => $target) {
            if ($target['driver'] === null) {
                throw $this->driverException($target);
            }

            $map[$value] = [$target['driver'], $target['post']];
        }

        return $map;
    }

    /**
     * Validation errors of one platform. A missing driver, or a driver whose
     * validate() throws, becomes an error under 'platform' instead of an
     * exception.
     *
     * @param  array{platform: Platform, post: PreparedPost, driver: ?SocialPlatform, error: ?Throwable}  $target
     */
    protected function validationErrors(array $target): MessageBag
    {
        if ($target['driver'] === null) {
            return new MessageBag(['platform' => [$this->driverException($target)->getMessage()]]);
        }

        try {
            $errors = $target['driver']->validate($target['post']);
        } catch (Throwable $e) {
            $this->reportQuietly($e);

            return new MessageBag(['platform' => ["Validation could not run for {$target['platform']->value}: ".$e->getMessage()]]);
        }

        $this->validateComment($target['driver'], $target['post'], $errors);

        return $errors;
    }

    /**
     * Check the first comment against the driver's CommentRules before
     * anything is published, so an over-long comment never fails after the
     * post is live. Drivers leave this to the builder (Facebook, Instagram,
     * Threads...); one that already reported 'comment' errors itself is left
     * alone, to avoid saying the same thing twice. A null rule set (a story or
     * scheduled post takes no comment) is not an error: the job skips it.
     */
    protected function validateComment(SocialPlatform $driver, PreparedPost $post, MessageBag $errors): void
    {
        $comment = $post->comment();

        if (! $driver instanceof SupportsFirstComment || $comment === null || trim($comment) === '' || $errors->has('comment')) {
            return;
        }

        try {
            $rules = $driver->commentRules($post);
        } catch (Throwable $e) {
            $this->reportQuietly($e);

            return;
        }

        foreach ($rules?->errors($comment) ?? [] as $message) {
            $errors->add('comment', $message);
        }
    }

    /**
     * Stage 1 of the post paths. Returns the platforms that must not be
     * published, with the reason. Without bestEffort(), any validation error
     * throws one ValidationException listing every platform's errors, and a
     * missing driver throws UnsupportedPlatformException.
     *
     * @param  array<string, array{platform: Platform, post: PreparedPost, driver: ?SocialPlatform, error: ?Throwable}>  $targets
     * @return array<string, SocialPosterException>
     *
     * @throws ValidationException
     * @throws SocialPosterException
     */
    protected function gate(array $targets): array
    {
        $rejected = [];

        foreach ($targets as $value => $target) {
            if ($target['driver'] === null && ! $this->bestEffort && $this->skipValidation) {
                throw $this->driverException($target);
            }
        }

        if ($this->skipValidation) {
            return $rejected;
        }

        $combined = new MessageBag;
        $perPlatform = [];

        foreach ($targets as $value => $target) {
            $errors = $this->validationErrors($target);

            if ($errors->isEmpty()) {
                continue;
            }

            $perPlatform[$value] = $errors;

            foreach ($errors->toArray() as $field => $messages) {
                foreach ($messages as $message) {
                    $combined->add("{$value}.{$field}", $message);
                }
            }
        }

        if ($perPlatform === []) {
            return $rejected;
        }

        if (! $this->bestEffort) {
            throw new ValidationException('Content failed validation before posting.', $combined);
        }

        foreach ($perPlatform as $value => $errors) {
            $rejected[$value] = $targets[$value]['driver'] === null
                ? $this->driverException($targets[$value])
                : ValidationException::fromMessageBag($targets[$value]['platform'], $errors);
        }

        return $rejected;
    }

    /**
     * v1.0 validation gate over buildPrepared() output: throws one
     * ValidationException listing every platform's errors. Kept for
     * subclasses; the 1.1 terminals use gate().
     *
     * @param  array<string, array{0: SocialPlatform, 1: PreparedPost}>  $prepared
     */
    protected function guardValidation(array $prepared): void
    {
        if ($this->skipValidation) {
            return;
        }

        $combined = new MessageBag;

        foreach ($prepared as $value => [$driver, $post]) {
            $target = ['platform' => $post->platform, 'post' => $post, 'driver' => $driver, 'error' => null];

            foreach ($this->validationErrors($target)->toArray() as $field => $messages) {
                foreach ($messages as $message) {
                    $combined->add("{$value}.{$field}", $message);
                }
            }
        }

        if ($combined->isNotEmpty()) {
            throw new ValidationException('Content failed validation before posting.', $combined);
        }
    }

    /**
     * The dispatched credentials for a platform: its own bag, else the shared
     * one, else config('social.platforms.<platform>.credentials'). Required
     * credentials may only be missing when a custom CredentialsResolver is
     * bound (it decides at run time, e.g. from the metadata).
     *
     * @throws PermanentException When required and none are available.
     */
    protected function credentialsFor(Platform $platform, bool $required): Credentials
    {
        if (isset($this->platformCredentials[$platform->value])) {
            return $this->platformCredentials[$platform->value];
        }

        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $configured = $this->config['platforms'][$platform->value]['credentials'] ?? [];
        $configured = is_array($configured) ? $configured : [];

        if ($configured === [] && $required && ! IdentityCredentialsResolver::customResolverBound()) {
            throw new PermanentException(
                "No credentials supplied or configured for {$platform->value}: pass them with ->using(...) or set config('social.platforms.{$platform->value}.credentials').",
                $platform,
                FailureReason::Misconfigured,
            );
        }

        return Credentials::make($configured);
    }

    // --- Queue (post) -------------------------------------------------------------

    /**
     * A publish job for one platform, carrying the idempotency key computed
     * once here (never recomputed by the job, so a token refresh cannot change
     * it).
     *
     * @param  array<string, mixed>|null  $state
     */
    protected function makeJob(PreparedPost $post, ?string $correlationId, string $key, ?array $state = null, int $pollCount = 0): PublishToConnectionJob
    {
        // Validation already ran synchronously, so the job need not repeat it.
        $job = new PublishToConnectionJob(
            $post->platform,
            $post->post,
            $post->credentials,
            true,
            $state,
            $pollCount,
            $correlationId,
            $key,
            $this->notAfter,
        );

        $connection = $this->config['connection'] ?? null;
        $queue = $this->config['queue'] ?? null;

        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }

        if ($this->afterCommit === true) {
            $job->afterCommit();
        } elseif ($this->afterCommit === false) {
            $job->beforeCommit();
        }

        return $job;
    }

    /**
     * Lock, announce, push. Returns true when queued, false when the same post
     * is already queued (the unique lock is held), or the error when the push
     * failed (the lock is released and PostFailed dispatched).
     *
     * The job goes straight to the queue (dispatchToQueue), never through
     * dispatch(): PendingDispatch would try to take the unique lock again,
     * find it held (by us) and silently drop the job.
     *
     * A job that dispatches after commit, inside an open transaction, on a
     * framework that cannot release its unique lock when the transaction rolls
     * back (Laravel 11, early 12): lock, announce and push all wait for the
     * commit, so a rollback (a deadlock retry, an app error) leaves neither a
     * held lock that would deduplicate the retry into nothing nor a PostQueued
     * for a post that was never queued. The result then counts it as queued.
     */
    protected function queue(PublishToConnectionJob $job, PreparedPost $post, string $key, string $correlationId): bool|SocialPosterException
    {
        $transactions = $this->deferringTransactions($job);

        if ($transactions === null) {
            return $this->lockAndPush($job, $post, $key, $correlationId);
        }

        $outcome = true;

        // Runs at once when no transaction is open, else at commit; a rollback discards it.
        $transactions->addCallback(function () use ($job, $post, $key, $correlationId, &$outcome): void {
            $outcome = $this->lockAndPush($job, $post, $key, $correlationId);
        });

        return $outcome;
    }

    /**
     * The transactions manager to defer a queue() call to: only for a job that
     * dispatches after commit, and only when the framework's rollback does not
     * release the unique lock of such a job by itself.
     */
    protected function deferringTransactions(PublishToConnectionJob $job): ?object
    {
        $container = Container::getInstance();

        try {
            if (! $this->dispatchesAfterCommit($job) || ! $container->bound('db.transactions')) {
                return null;
            }

            $transactions = $container->make('db.transactions');
        } catch (Throwable $e) {
            $this->reportQuietly($e);

            return null;
        }

        if (! is_object($transactions) || ! method_exists($transactions, 'addCallback') || $this->rollbackReleasesUniqueLocks($transactions)) {
            return null;
        }

        return $transactions;
    }

    /**
     * Whether a rolled-back transaction releases the unique lock of a job
     * queued after commit: Laravel 12.x since rollback callbacks exist, and
     * 13. Laravel 11 has no rollback hook, so the lock stays for uniqueFor.
     */
    protected function rollbackReleasesUniqueLocks(object $transactions): bool
    {
        return method_exists($transactions, 'addCallbackForRollback');
    }

    /** The queue's own rule (Queue::shouldDispatchAfterCommit): the job's flag, else its connection's after_commit. */
    protected function dispatchesAfterCommit(PublishToConnectionJob $job): bool
    {
        if ($job->afterCommit !== null) {
            return (bool) $job->afterCommit;
        }

        $connection = is_string($job->connection) && $job->connection !== '' ? $job->connection : config('queue.default');

        return is_string($connection) && (bool) config("queue.connections.{$connection}.after_commit", false);
    }

    /** queue() without the deferral: lock, announce, push. */
    protected function lockAndPush(PublishToConnectionJob $job, PreparedPost $post, string $key, string $correlationId): bool|SocialPosterException
    {
        $platform = $post->platform;
        $lock = null;

        try {
            $lock = new UniqueLock(Container::getInstance()->make(CacheRepository::class));

            if (! $lock->acquire($job)) {
                $this->safeDispatch(new PostDeduplicated($platform, $key, $post->metadata(), $correlationId));

                return false;
            }
        } catch (Throwable $e) {
            // No usable lock store: the job's idempotency claim still guards the publish.
            $this->reportQuietly($e);
            $lock = null;
        }

        $this->safeDispatch(new PostQueued($platform, $post->post, $post->metadata(), $correlationId, $key));

        try {
            $this->push($job);
        } catch (Throwable $e) {
            try {
                $lock?->release($job);
            } catch (Throwable) {
                // The lock expires on its own (uniqueFor).
            }

            $error = $this->asPackageException($e, $platform, 'could not be queued', true);
            $this->safeDispatch(new PostFailed($platform, $error, $post->metadata(), $correlationId, $key));

            return $error;
        }

        return true;
    }

    /** Push a job to its queue, skipping the unique-lock check (the caller holds or skips the lock). */
    protected function push(object $job): void
    {
        $bus = Container::getInstance()->make(BusDispatcher::class);

        if ($bus instanceof QueueingDispatcher) {
            $bus->dispatchToQueue($job);

            return;
        }

        $bus->dispatch($job);
    }

    // --- Synchronous publish (postNow) --------------------------------------------

    /**
     * Publish one platform in-process. Never throws: every failure becomes a
     * failed PostResult and a PostFailed event.
     */
    protected function publishNow(SocialPlatform $driver, PreparedPost $post, string $correlationId): PostResult
    {
        $platform = $post->platform;
        $value = $platform->value;
        $key = $this->keyFor($driver, $post, $correlationId);
        // Always through the store, as on the queue, with or without
        // ->idempotencyKey(): the key the events report (PostFailed::
        // $idempotencyKey, PostPublished::$idempotencyKey) then has a record
        // that outcome(), status() and reconcile() can find.
        $store = $this->resolveStore();
        $owner = (string) Str::uuid();
        $recorded = null;

        $this->runs[$value] = ['key' => $key, 'correlation_id' => $correlationId, 'store' => $store, 'owner' => $owner, 'driver' => $driver];

        // Replay a recorded publish, refuse to race another owner of the key,
        // and continue a run of the key that never finished, as the queued
        // path does.
        if ($store !== null) {
            try {
                if ($store instanceof AtomicIdempotencyStore) {
                    $account = $driver instanceof AbstractPlatform ? $driver->accountKey($post->credentials) : '';

                    if ($account !== '') {
                        $store->bindAccount($key, $platform, $account);
                    }
                }

                $prior = $store->completed($key) ?? $this->legacyRecord($store, $key, $platform);

                if ($prior !== null) {
                    return $this->announce($prior, $post, 1, true);
                }

                if ($store instanceof AtomicIdempotencyStore && ! $store->claim($key, $owner, $this->leaseSeconds($platform))) {
                    return $this->claimRefused($store, $post);
                }

                $recorded = $this->recordedRun($store, $key);
            } catch (KeyCollisionException $e) {
                // The record belongs to the account that bound the key first.
                return $this->failNow($e, $post, 1, false, null, false);
            } catch (Throwable $e) {
                // An unreachable store must not block the post.
                $this->reportQuietly($e);
                $this->runs[$value]['store'] = null;
                $recorded = null;
            }
        }

        $startedAt = $this->now();
        $budgetEnd = $startedAt + $this->syncMaxWait();
        $retries = $this->setting('sync.retries', 3);
        $maxRestarts = $this->setting('max_restarts', 3);

        $state = null;
        $commits = false;
        $committed = false;
        $recovering = false;
        // The step before this one created ambiguously and handed over to
        // this verify step (Pending::$possiblySent): the post may exist.
        $commitSent = false;
        $committingSince = null;
        $deadline = null;
        $restarts = 0;
        $attempt = 1;
        $polls = 0;
        $reresolved = false;
        $credentials = null;
        // The lease was just taken: it is renewed before every later step.
        $renew = false;

        if ($recorded !== null) {
            // An earlier run of this key stopped mid-way (a crash, a request
            // timeout, a chain whose lease ran out): continue its state with
            // its markers instead of creating the post a second time. A
            // 'committing' record may already have created it, so the driver
            // verifies on the platform first (_recovering), as in the job.
            $stored = $recorded['state'];
            $state = Pending::withoutReservedKeys($stored);
            $commits = $recorded['committing'] || (bool) ($stored['_commits'] ?? false);
            $committed = (bool) ($stored['_committed'] ?? false);
            $deadline = is_numeric($stored['_deadline'] ?? null) ? (int) $stored['_deadline'] : null;
            $startedAt = is_numeric($stored[Pending::STARTED_AT] ?? null) ? (int) $stored[Pending::STARTED_AT] : $startedAt;
            $restarts = max(0, (int) ($stored[Pending::RESTARTS] ?? 0));
            $recovering = $recorded['committing'];
            $committingSince = $recorded['since'];
        }

        while (true) {
            $leased = $this->runs[$value]['store'];

            if ($renew && $leased instanceof AtomicIdempotencyStore && ! $this->renewClaim($leased, $key, $owner, $platform)) {
                // This process outlived its lease and another dispatch of the
                // key took over: it continues from the recorded step.
                return $this->claimRefused($leased, $post);
            }

            $renew = true;
            $error = null;
            $sent = false;
            $outcome = null;
            $commitPhase = $state !== null && $commits;

            try {
                if (! $committed && ! ($commitPhase && ($recovering || $commitSent))) {
                    $this->assertNotExpired($platform);
                }

                $credentials ??= $this->resolveCredentials($platform, $post->credentials, $post->metadata());
                $prepared = new PreparedPost($platform, $post->post, $credentials);

                if ($commitPhase && $state !== null) {
                    // Before a create: a repeat of this step (crash, redelivery
                    // after a hand-off) then knows to verify first.
                    $committingSince ??= $this->now();
                    $committingState = $this->storedState($state, true, $committed, $deadline, $startedAt, $restarts);
                    $this->storeCall($value, fn (AtomicIdempotencyStore $store) => $store->markCommitting($key, $committingState));
                }

                $sent = true;
                $outcome = $state === null
                    ? $driver->publish($prepared)
                    : $driver->resume($prepared, Pending::withoutReservedKeys($state) + array_filter([
                        Pending::RECOVERING => $commitPhase && $recovering,
                        Pending::STARTED_AT => $startedAt,
                        Pending::ATTEMPT => $attempt,
                        Pending::RESTARTS => $restarts,
                        Pending::COMMITTING_SINCE => $commitPhase ? $committingSince : null,
                    ], static fn ($v) => $v !== null));
            } catch (ConnectionException $e) {
                $error = TemporaryException::connection($platform, $e);
            } catch (TemporaryException $e) {
                $error = $e;
            } catch (SocialPosterException $e) {
                $possibly = array_key_exists('possibly_published', $e->context)
                    ? $e->possiblyPublished()
                    : ($committed || ($commitPhase && ($recovering || $commitSent)));

                return $this->failNow($e, $post, $attempt, $possibly, $this->storedStateOrNull($state, $commits, $committed, $deadline, $startedAt, $restarts));
            } catch (Throwable $e) {
                if ($e instanceof \Error || $e instanceof LogicException) {
                    $possibly = $committed || ($sent && $commitPhase);

                    return $this->failNow(new PermanentException(
                        $possibly
                            ? "The {$value} publish stopped with an unexpected error during a create step; the post may be live. ".$this->redact($e->getMessage())
                            : "The {$value} publish failed with an unexpected error: ".$this->redact($e->getMessage()),
                        $platform,
                        $possibly ? FailureReason::OutcomeUnknown : FailureReason::Unknown,
                        ['unexpected' => true, 'exception' => $e::class],
                        $e,
                    ), $post, $attempt, $possibly, $this->storedStateOrNull($state, $commits, $committed, $deadline, $startedAt, $restarts));
                }

                $error = new TemporaryException(
                    "The {$value} publish hit an unexpected error: ".$this->redact($e->getMessage()),
                    $platform,
                    FailureReason::Unknown,
                    null,
                    ['unexpected' => true, 'exception' => $e::class],
                    $e,
                );
            }

            if ($error !== null) {
                if (($error->context['restart'] ?? false) === true) {
                    $unsafe = array_key_exists('possibly_published', $error->context)
                        ? $error->possiblyPublished()
                        : $commitPhase && ($recovering || $commitSent || ($sent && $this->isAmbiguous($error)));

                    if ($unsafe) {
                        // Starting over would create the post a second time.
                        return $this->failNow(new PermanentException(
                            $error->getMessage().' Starting over is not safe: an earlier create of this post may have gone out. Check the platform before re-posting.',
                            $platform,
                            FailureReason::OutcomeUnknown,
                            ['possibly_published' => true, 'restart_refused' => true] + array_diff_key($error->context, ['restart' => true]),
                            $error,
                        ), $post, $attempt, true, $this->storedStateOrNull($state, $commits, $committed, $deadline, $startedAt, $restarts));
                    }

                    // The platform discarded the upload: start over from publish().
                    if (++$restarts > $maxRestarts) {
                        return $this->failNow(new PermanentException(
                            $error->getMessage().' (gave up after '.($restarts - 1).' restart(s)).',
                            $platform,
                            $error->reason,
                            ['restarts' => $restarts - 1] + array_diff_key($error->context, ['restart' => true]),
                            $error,
                        ), $post, $attempt, $committed);
                    }

                    $delay = max(0, $error->retryAfter ?? 0);

                    if ($this->now() + $delay > $budgetEnd) {
                        return $this->failNow($error, $post, $attempt, $committed);
                    }

                    $state = null;
                    $commits = $committed = $recovering = $commitSent = false;
                    $committingSince = $deadline = null;
                    $attempt = 1;
                    $this->sleep($delay);

                    continue;
                }

                if (($error->context['reresolve'] ?? false) === true) {
                    // A custom resolver may hand out a fresh token: once.
                    if ($reresolved || ! $sent) {
                        return $this->failNow($this->rejected($error, $platform), $post, $attempt, $committed);
                    }

                    $reresolved = true;
                    $credentials = null;

                    continue;
                }

                if ($commitPhase && $sent && $this->isAmbiguous($error)) {
                    // The create may have gone out: verify before creating again.
                    $recovering = true;
                }

                $delay = $this->retryDelay($error, $attempt);

                if ($attempt > $retries || $this->now() + $delay > $budgetEnd) {
                    $possibly = array_key_exists('possibly_published', $error->context)
                        ? $error->possiblyPublished()
                        : ($committed || ($commitPhase && ($recovering || $commitSent)));

                    return $this->failNow($error, $post, $attempt, $possibly, $this->storedStateOrNull($state, $commits, $committed, $deadline, $startedAt, $restarts));
                }

                $this->safeDispatch(new PostRetrying(
                    $platform,
                    $error->errorSummary(),
                    $attempt,
                    now()->addSeconds($delay),
                    $post->metadata(),
                    $correlationId,
                ));

                $attempt++;
                $this->sleep($delay);

                continue;
            }

            // The driver returned: nothing below may re-run it.
            if ($outcome instanceof Published) {
                $this->storeCall($value, fn (IdempotencyStore $store) => $store->markPublished($key, $outcome->result), false);

                return $this->announce($outcome->result, $post, $attempt, false);
            }

            if (! $outcome instanceof Pending) {
                $possibly = $committed || $commitPhase;

                return $this->failNow(new PermanentException(
                    "The {$value} driver returned an unknown outcome (".get_debug_type($outcome).').',
                    $platform,
                    FailureReason::Unknown,
                    ['outcome' => get_debug_type($outcome)] + ($possibly ? ['possibly_published' => true] : []),
                    new LogicException('Unknown PublishOutcome '.get_debug_type($outcome)),
                ), $post, $attempt, $possibly, $this->storedStateOrNull($state, $commits, $committed, $deadline, $startedAt, $restarts));
            }

            $committed = $committed || $outcome->committed;
            $deadline = $outcome->deadline ?? $deadline;
            $state = Pending::withoutReservedKeys($outcome->state);
            $commits = $outcome->commits;
            $sentCommit = $outcome->commits && $outcome->possiblySent;
            $graceable = $outcome->commits && ! $commitPhase && ! $commitSent;
            $recovering = false;
            // A verify step searches from the original create time.
            $committingSince = $sentCommit ? $committingSince : null;
            $commitSent = $sentCommit;
            $attempt = 1;
            $polls++;

            $wait = max(0, $outcome->recheckAfter);
            $now = $this->now();
            $late = $deadline !== null && $now >= $deadline;
            // A step that runs again at once (the next upload slice, a create
            // that is ready) is work, not a wait on the platform: it is not
            // charged to sync.max_wait (v1.0 uploaded inline), only bounded by
            // the driver's deadline, else the platform's async wait.
            $over = $wait === 0
                ? $now >= ($deadline ?? $startedAt + $this->asyncMaxWait($platform))
                : $now + $wait > $budgetEnd;
            // Processing finished on the last look: the create runs once.
            $grace = $wait === 0 && $graceable && ($late || $over);

            if (! $grace && ($late || $over)) {
                return $this->handOff($post, $state, $polls, $wait, $commits, $committed, $deadline, $startedAt, $restarts, $sentCommit);
            }

            // Record the step, as the job does before each continuation: a
            // same-key dispatch that takes the key over once this process is
            // gone (a crash, a stall past the lease) resumes here instead of
            // publishing a second time. Same markers as the job's own record.
            $recordedStep = $this->storedState($state, $commits, $committed, $deadline, $startedAt, $restarts) + ['_step' => $polls, '_chain' => $owner];
            $this->storeCall($value, fn (IdempotencyStore $store) => $store->markPending($key, $recordedStep), false);

            if (! $commits) {
                $this->safeDispatch(new PostProcessing($platform, $polls, $now - $startedAt, $post->metadata(), $correlationId));
            }

            $this->sleep($wait);
        }
    }

    /**
     * The platform is still processing and the synchronous wait is over. Hand
     * the state to PublishToConnectionJob (same key, owner and clock), which
     * continues on the queue, and report the post as 'processing'. On the
     * 'sync' connection, or once the driver's own deadline passed, it is a
     * timeout instead: a post the platform already accepted is reported with
     * possiblyPublished and kept for reconcile, as is a verify step after a
     * create whose answer was lost ($possiblySent).
     *
     * @param  array<string, mixed>  $state
     */
    protected function handOff(PreparedPost $post, array $state, int $polls, int $wait, bool $commits, bool $committed, ?int $deadline, int $startedAt, int $restarts, bool $possiblySent = false): PostResult
    {
        $platform = $post->platform;
        $run = $this->runs[$platform->value];
        $now = $this->now();
        $stored = $this->storedState($state, $commits, $committed, $deadline, $startedAt, $restarts);

        if (($deadline !== null && $now >= $deadline) || $this->isSyncConnection()) {
            $context = [
                'elapsed_seconds' => $now - $startedAt,
                'polls' => $polls,
            ] + ($deadline !== null ? ['deadline' => gmdate(DATE_ATOM, $deadline)] : []);

            $error = match (true) {
                $committed => new PermanentException(
                    'The platform was still processing the post when the wait limit passed; it may still go live. Check the platform before re-posting.',
                    $platform,
                    FailureReason::ProcessingTimeout,
                    $context + ['possibly_published' => true],
                ),
                $possiblySent => new PermanentException(
                    'The wait limit passed while checking whether an earlier create went through; the post may be live. Check the platform before re-posting.',
                    $platform,
                    FailureReason::OutcomeUnknown,
                    $context + ['possibly_published' => true],
                ),
                default => new TemporaryException(
                    $wait === 0
                        ? 'Publishing timed out while the media was still being uploaded or prepared.'
                        : 'Publishing timed out while the platform was still processing the media.',
                    $platform,
                    FailureReason::Timeout,
                    null,
                    $context,
                ),
            };

            return $this->failNow($error, $post, 1, $committed || $possiblySent, $stored);
        }

        $job = $this->makeJob($post, $run['correlation_id'], $run['key'], $state, $polls);
        $job->owner = $run['owner'];
        $job->commits = $commits;
        $job->committed = $committed;
        $job->deadline = $deadline;
        $job->startedAt = $startedAt;
        $job->restarts = $restarts;
        $job->commitSent = $possiblySent;

        if ($committed || $possiblySent) {
            // The platform has the post whatever the app's transaction does:
            // a rollback must not drop the job that reports it (after_commit
            // would). An uncommitted publish keeps after_commit: dropping it
            // means no post, which is what after_commit asks for.
            $job->beforeCommit();
        }

        $max = max(5, $this->setting('max_recheck_after', 900));

        if ($wait > $max) {
            $job->notBefore = $now + $wait;
            $wait = $max;
        }

        if ($wait > 0) {
            $job->delay($wait);
        }

        // The continuation carries the state; the store copy helps a re-dispatch after a crash.
        $this->storeCall($platform->value, fn (IdempotencyStore $store) => $store->markPending($run['key'], $stored), false);

        try {
            $this->push($job);
        } catch (Throwable $e) {
            $error = $this->asPackageException($e, $platform, 'could not be handed to the queue after the synchronous wait', ! $committed && ! $possiblySent);

            if ($committed || $possiblySent) {
                $error->withFlags(['possibly_published' => true]);
            }

            return $this->failNow($error, $post, 1, $committed || $possiblySent, $stored);
        }

        if (! $commits) {
            $this->safeDispatch(new PostProcessing($platform, $polls, $now - $startedAt, $post->metadata(), $run['correlation_id']));
        }

        return $this->processingResult($post, ['handed_off' => true, 'polls' => $polls]);
    }

    /** Another owner holds the key: replay a published record, refuse a failed one, else step aside. */
    protected function claimRefused(AtomicIdempotencyStore $store, PreparedPost $post): PostResult
    {
        $run = $this->runs[$post->platform->value];
        $status = $store->status($run['key']);

        if ($status === AtomicIdempotencyStore::STATUS_PUBLISHED) {
            $prior = $store->completed($run['key']);

            if ($prior !== null) {
                return $this->announce($prior, $post, 1, true);
            }
        }

        if ($status === AtomicIdempotencyStore::STATUS_FAILED) {
            return $this->failNow(new PermanentException(
                'An earlier attempt with this idempotency key ended with an unknown outcome, so the post may already be live. '
                .'Check the platform (or reconcile) before re-posting, or use a new idempotency key.',
                $post->platform,
                FailureReason::OutcomeUnknown,
                ['possibly_published' => true, 'previously_failed' => true],
            ), $post, 1, true, null, false, false);
        }

        // A live chain (a queued post() or another postNow()) owns the key and reports the outcome.
        $this->safeDispatch(new PostDeduplicated($post->platform, $run['key'], $post->metadata(), $run['correlation_id']));

        return $this->processingResult($post, ['deduplicated' => true]);
    }

    /** The post exists: release the lease, announce it (a listener can never fail it) and return it. */
    protected function announce(PostResult $result, PreparedPost $post, int $attempt, bool $replayed): PostResult
    {
        $run = $this->runs[$post->platform->value];
        $this->releaseLease($post->platform->value);

        $result = $result->withMetadata($post->metadata());
        $this->safeDispatch(new PostPublished($result, $run['correlation_id'], $run['key'], $attempt, $replayed));

        return $result;
    }

    /**
     * Final failure of one platform: keep a possibly published record for
     * reconcile ('failed'), otherwise forget the key so a fresh post can
     * proceed; report PostFailed (and CredentialsRejected for a rejected
     * token); return the failed result.
     *
     * @param  array<string, mixed>|null  $storedState
     */
    protected function failNow(SocialPosterException $e, PreparedPost $post, int $attempt, bool $possiblyPublished, ?array $storedState = null, bool $forget = true, bool $mark = true): PostResult
    {
        $platform = $post->platform;
        $run = $this->runs[$platform->value] ?? null;

        if ($possiblyPublished) {
            $e->withFlags(['possibly_published' => true]);

            if ($mark) {
                $this->storeCall($platform->value, fn (AtomicIdempotencyStore $store) => $store->markFailed($run['key'] ?? '', $storedState));
            }
        } elseif ($forget) {
            $this->storeCall($platform->value, fn (IdempotencyStore $store) => $store->forget($run['key'] ?? ''), false);
        }

        $this->releaseLease($platform->value);

        $this->safeDispatch(new PostFailed(
            $platform,
            $e,
            $post->metadata(),
            $run['correlation_id'] ?? null,
            $run['key'] ?? null,
            $attempt,
            false,
            $possiblyPublished,
        ));

        if ($e instanceof PermanentException && $e->needsReconnect()) {
            $driver = $run['driver'] ?? null;

            $this->safeDispatch(new CredentialsRejected(
                $platform,
                $driver instanceof AbstractPlatform ? $this->accountKey($driver, $post->credentials) : '',
                $e->reason,
                $e->isTerminal(),
                $post->metadata(),
                $e->errorSummary(),
            ));
        }

        return PostResult::failed($platform, $e)->withMetadata($post->metadata());
    }

    /**
     * A success that is not final: the post is being finished elsewhere (the
     * queue, after the synchronous wait; another owner of the key). The outcome
     * arrives through PostPublished / PostFailed.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function processingResult(PreparedPost $post, array $payload): PostResult
    {
        $run = $this->runs[$post->platform->value];

        return new PostResult(
            $post->platform,
            true,
            null,
            null,
            $payload + ['idempotency_key' => $run['key'], 'correlation_id' => $run['correlation_id']],
            null,
            $post->metadata(),
            PostResult::STATE_PROCESSING,
        );
    }

    /**
     * The first comment for a postNow() result, after the publish decided the
     * result, so a comment failure (network, listener, anything) can only ever
     * produce CommentFailed / CommentSkipped. Queued as AddFirstCommentJob by
     * default; run in-process with inlineComment(), unless the platform needs
     * a delay first. A 'processing' post gets its comment from the job that
     * finishes it.
     */
    protected function commentInline(SocialPlatform $driver, PreparedPost $post, PostResult $result): void
    {
        $comment = $post->comment();

        if ($comment === null || ! $result->success || $result->isProcessing()) {
            return;
        }

        $platform = $post->platform;
        $metadata = $post->metadata();
        $run = $this->runs[$platform->value] ?? null;
        $postId = $result->platformPostId !== null && trim($result->platformPostId) !== '' ? $result->platformPostId : null;
        $skip = fn (string $reason, ?string $id) => $this->safeDispatch(new CommentSkipped($platform, $id, $reason, $metadata));

        if (trim($comment) === '') {
            $skip(CommentSkipped::REASON_EMPTY, $postId);

            return;
        }

        if (! $result->isPublished()) {
            $skip(CommentSkipped::REASON_SCHEDULED, $postId);

            return;
        }

        if ($postId === null) {
            $skip(CommentSkipped::REASON_NO_POST_ID, null);

            return;
        }

        if (! $driver instanceof SupportsFirstComment && ! $driver instanceof SupportsComments) {
            $skip(CommentSkipped::REASON_UNSUPPORTED, $postId);

            return;
        }

        $delay = max(0, $this->setting('comment_delay', 60));
        $minDelay = 0;

        if ($driver instanceof SupportsFirstComment) {
            $rules = $driver->commentRules($post, $result);

            if ($rules === null) {
                $skip(CommentSkipped::REASON_STORY, $postId);

                return;
            }

            $delay = $rules->delay($delay);
            $minDelay = $rules->minDelaySeconds;
        }

        $job = new AddFirstCommentJob(
            $platform,
            $postId,
            $comment,
            $post->credentials,
            $metadata,
            $post->post,
            $result->withMetadata([]),
            $run['key'] ?? null,
            $run['correlation_id'] ?? null,
        );

        if ($minDelay > 0 && $this->queueIgnoresDelays()) {
            // The 'sync' queue ignores delays: a queued comment would run now
            // and fail (LinkedIn answers 404 until the post's activity
            // propagates). Wait the platform's minimum here, within the
            // synchronous budget, or say why there is no comment.
            if ($minDelay > $this->syncMaxWait()) {
                $this->safeDispatch(new CommentFailed($platform, $postId, new PermanentException(
                    "The 'sync' queue connection cannot delay the {$platform->value} first comment by the {$minDelay}s the platform requires "
                    ."(social.sync.max_wait is {$this->syncMaxWait()}s). Use an asynchronous queue connection, or raise social.sync.max_wait.",
                    $platform,
                    FailureReason::Misconfigured,
                    ['min_delay_seconds' => $minDelay, 'sync_max_wait' => $this->syncMaxWait()],
                ), $metadata));

                return;
            }

            $this->sleep($minDelay);
            $job->handle($this->manager, $this->events);

            return;
        }

        if ($this->inlineComments() && $minDelay <= 0) {
            // The job's own handle(): duplicate check, rules, re-resolve, events.
            $job->handle($this->manager, $this->events);

            return;
        }

        $connection = $this->config['connection'] ?? null;
        $queue = $this->config['queue'] ?? null;

        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }

        $job->uniqueFor = max($job->uniqueFor, $delay + 3600);

        if ($delay > 0) {
            $job->delay($delay);
        }

        // Through PendingDispatch, so the comment's unique lock drops a duplicate.
        dispatch($job);
    }

    // --- Helpers ------------------------------------------------------------------

    /**
     * The idempotency (store) key of one platform's publish: computed once per
     * call, from the account identity of the dispatched credentials. When
     * those carry none (nothing dispatched) and a custom CredentialsResolver
     * picks the account at run time (e.g. from the metadata), the account of
     * the resolved credentials is used instead, so one ->idempotencyKey()
     * fanned out to several accounts never collides. Only that identity
     * enters the key; the resolved token is never kept or dispatched.
     */
    protected function keyFor(SocialPlatform $driver, PreparedPost $post, string $correlationId): string
    {
        $account = $this->resolvedAccountKey($driver, $post);

        if ($account !== null) {
            return PublishToConnectionJob::makeIdempotencyKey($post->platform, $correlationId, $account);
        }

        $this->warnUnidentifiedAccount($driver, $post);

        return PublishToConnectionJob::idempotencyKeyFor($post->platform, $post->post, $post->credentials, $correlationId, $driver);
    }

    /**
     * Why ->idempotencyKey() cannot keep two accounts apart here, or null when
     * it can (or no key is given). Without an identity credential the store
     * key is the platform and the given key alone (as in v1.0): a second
     * account published with the same key finds the first one's record and
     * replays its post id instead of posting. The token is never used as the
     * identity (it changes on every refresh, and a refreshed re-dispatch must
     * replay), and requiring the identity would break v1.0 call sites, so
     * this is a warning, not an error.
     */
    protected function unidentifiedAccountWarning(SocialPlatform $driver, PreparedPost $post): ?string
    {
        if ($this->idempotencyKey === null || $this->idempotencyKey === '' || ! $driver instanceof AbstractPlatform
            || $this->accountKey($driver, $post->credentials) !== '' || $this->resolvedAccountKey($driver, $post) !== null) {
            return null;
        }

        $platform = $post->platform->value;
        $identity = $this->identityCredentialKeys($driver)[0] ?? null;
        $add = ($identity !== null ? "'{$identity}' or " : '')."a generic 'account' credential";

        return "The {$platform} credentials name no account, so ->idempotencyKey() alone keys the publish: another {$platform} "
            .'account published with the same key replays this record instead of posting. Add '.$add
            .' to the credentials, or use one key per account.';
    }

    /** Log unidentifiedAccountWarning() once per platform and process: post() and postNow() call this per publish. */
    protected function warnUnidentifiedAccount(SocialPlatform $driver, PreparedPost $post): void
    {
        $platform = $post->platform->value;

        if (isset(static::$warnedUnidentified[$platform])) {
            return;
        }

        $warning = $this->unidentifiedAccountWarning($driver, $post);

        if ($warning === null) {
            return;
        }

        static::$warnedUnidentified[$platform] = true;

        try {
            $channel = $this->config['error_log_channel'] ?? null;
            $message = '[SocialPoster] '.$warning;

            is_string($channel) && $channel !== ''
                ? Log::channel($channel)->warning($message, ['platform' => $platform])
                : Log::warning($message, ['platform' => $platform]);
        } catch (Throwable) {
            // Advisory only: never stop a post over a log line.
        }
    }

    /**
     * The driver's identity credential keys
     * (AbstractPlatform::identityCredentialKeys()), only to name them in the
     * warning above.
     *
     * @return list<string>
     */
    protected function identityCredentialKeys(AbstractPlatform $driver): array
    {
        try {
            return $driver->identityCredentialKeys();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The account identity of the resolved credentials, when the key needs it:
     * the dispatched credentials identify no account and a custom resolver is
     * bound. Null otherwise (or when the resolver cannot answer now: the run
     * reports that itself).
     */
    protected function resolvedAccountKey(SocialPlatform $driver, PreparedPost $post): ?string
    {
        if (! $driver instanceof AbstractPlatform
            || $this->accountKey($driver, $post->credentials) !== ''
            || ! IdentityCredentialsResolver::customResolverBound()) {
            return null;
        }

        try {
            $account = $driver->accountKey($this->resolveCredentials($post->platform, $post->credentials, $post->metadata()));
        } catch (Throwable $e) {
            $this->reportQuietly($e);

            return null;
        }

        return $account !== '' ? $account : null;
    }

    protected function correlationId(): string
    {
        return $this->idempotencyKey ?? (string) Str::uuid();
    }

    /** The key outcome()/reconcile() look up: the one given, else ->idempotencyKey(). */
    protected function lookupKey(?string $key, string $method): string
    {
        $key ??= $this->idempotencyKey;

        if ($key === null || trim($key) === '') {
            throw new LogicException("{$method}() needs the ->idempotencyKey() the post was published with (or a key from QueuedPost / the events).");
        }

        return $key;
    }

    /**
     * outcome()/reconcile(): the store key a publish of these
     * metadata-resolved credentials was recorded under (see keyFor()), which
     * the Poster cannot derive from the empty dispatched credentials. Null
     * when the key is a store key already or the dispatched ones suffice.
     *
     * @param  array{platform: Platform, post: PreparedPost, driver: ?SocialPlatform, error: ?Throwable}  $target
     */
    protected function resolvedStoreKey(string $key, array $target): ?string
    {
        if ($target['driver'] === null || str_starts_with($key, 'sp:')) {
            return null;
        }

        $account = $this->resolvedAccountKey($target['driver'], $target['post']);

        return $account !== null ? PublishToConnectionJob::makeIdempotencyKey($target['platform'], $key, $account) : null;
    }

    /** The Poster that answers outcome()/reconcile() for this builder. */
    protected function poster(): Poster
    {
        return new Poster($this->manager, $this->events, $this->config);
    }

    /** @throws UnsupportedPlatformException */
    protected function platformFrom(string|Platform $platform): Platform
    {
        if ($platform instanceof Platform) {
            return $platform;
        }

        return Platform::tryFrom(strtolower(trim($platform)))
            ?? throw $this->manager->unsupportedPlatform($platform);
    }

    /** @param  Credentials|array<string, mixed>  $credentials */
    protected function toCredentials(Credentials|array $credentials): Credentials
    {
        return $credentials instanceof Credentials ? $credentials : Credentials::make($credentials);
    }

    /**
     * True when every key is a platform name and every value a credentials bag.
     *
     * @param  array<array-key, mixed>  $credentials
     */
    protected function isPlatformMap(array $credentials): bool
    {
        if ($credentials === []) {
            return false;
        }

        foreach ($credentials as $key => $value) {
            if (! is_string($key) || Platform::tryFrom(strtolower(trim($key))) === null) {
                return false;
            }

            if (! is_array($value) && ! $value instanceof Credentials) {
                return false;
            }
        }

        return true;
    }

    /**
     * The exception for a platform whose driver could not be resolved.
     *
     * @param  array{platform: Platform, post: PreparedPost, driver: ?SocialPlatform, error: ?Throwable}  $target
     */
    protected function driverException(array $target): SocialPosterException
    {
        $error = $target['error'];

        if ($error instanceof SocialPosterException) {
            return $error;
        }

        return $error === null
            ? $this->manager->unsupportedPlatform($target['platform']->value)
            : $this->asPackageException($error, $target['platform'], 'has no usable driver');
    }

    /** Wrap a foreign throwable: Temporary when retrying later may help, else Permanent. */
    protected function asPackageException(Throwable $e, Platform $platform, string $what, bool $temporary = false): SocialPosterException
    {
        if ($e instanceof SocialPosterException) {
            return $e;
        }

        $message = "The {$platform->value} post {$what}: ".$this->redact($e->getMessage());
        $context = ['unexpected' => true, 'exception' => $e::class];

        return $temporary
            ? new TemporaryException($message, $platform, FailureReason::Unknown, null, $context, $e)
            : new PermanentException($message, $platform, FailureReason::Unknown, $context, $e);
    }

    /**
     * The credentials to publish with, through the bound CredentialsResolver
     * (as the queued job does on every run), so a Credentials::ref() works in
     * postNow() too.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function resolveCredentials(Platform $platform, Credentials $dispatched, array $metadata): Credentials
    {
        $container = Container::getInstance();
        $resolver = $container->bound(CredentialsResolver::class)
            ? $container->make(CredentialsResolver::class)
            : new IdentityCredentialsResolver;

        return $resolver->resolve($platform, $dispatched, $metadata);
    }

    /** The permanent form of a token rejection that survived one re-resolve. */
    protected function rejected(TemporaryException $e, Platform $platform): PermanentException
    {
        $previous = $e->getPrevious();

        if ($previous instanceof PermanentException) {
            return $previous->withFlags(['reresolved' => true]);
        }

        return new PermanentException(
            $e->getMessage(),
            $e->platform ?? $platform,
            FailureReason::InvalidToken,
            ['reresolved' => true] + array_diff_key($e->context, ['reresolve' => true]),
            $e,
        );
    }

    /**
     * Whether a failed try of a create may still have created the post: a
     * provably unsent request, an explicit refusal (4xx, rate limit, token
     * rejection) cannot have; a lost connection after sending, a 5xx or
     * anything unclassified may have. Same rule as the queued job.
     */
    protected function isAmbiguous(TemporaryException $e): bool
    {
        if ($e->possiblyPublished()) {
            return true;
        }

        $context = $e->context;

        if (($context['request_sent'] ?? null) === false || ($context['reresolve'] ?? false) === true) {
            return false;
        }

        if ($e->reason === FailureReason::RateLimited || $e->reason === FailureReason::InvalidToken) {
            return false;
        }

        $status = $context['http_status'] ?? $context['status'] ?? null;

        return ! (is_numeric($status) && (int) $status >= 400 && (int) $status < 500);
    }

    /** Seconds before the next in-process retry: the platform's Retry-After, else social.sync.backoff. */
    protected function retryDelay(TemporaryException $e, int $attempt): int
    {
        if ($e->retryAfter !== null) {
            return max(0, $e->retryAfter);
        }

        $configured = Arr::get($this->config, 'sync.backoff');
        $schedule = is_array($configured) ? array_values(array_map('intval', array_filter($configured, 'is_numeric'))) : [];
        $schedule = $schedule === [] ? [2, 5, 10] : $schedule;

        return max(0, $schedule[min($attempt, count($schedule)) - 1]);
    }

    /** Throws PermanentException(Expired) once the ->notAfter() window closed. */
    protected function assertNotExpired(Platform $platform): void
    {
        if ($this->notAfter === null || $this->now() <= $this->notAfter) {
            return;
        }

        throw new PermanentException(
            'The publish window closed at '.gmdate(DATE_ATOM, $this->notAfter).' before the post could go out; nothing was published.',
            $platform,
            FailureReason::Expired,
            ['not_after' => gmdate(DATE_ATOM, $this->notAfter)],
        );
    }

    /**
     * The state the store keeps for a step, in the job's format (the driver's
     * state plus its markers), so a continuation or a reconcile resumes it.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function storedState(array $state, bool $commits, bool $committed, ?int $deadline, int $startedAt, int $restarts): array
    {
        return Pending::withoutReservedKeys($state) + array_filter([
            '_commits' => $commits ?: null,
            '_committed' => $committed ?: null,
            '_deadline' => $deadline,
            Pending::STARTED_AT => $startedAt,
            Pending::RESTARTS => $restarts ?: null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return array<string, mixed>|null
     */
    protected function storedStateOrNull(?array $state, bool $commits, bool $committed, ?int $deadline, int $startedAt, int $restarts): ?array
    {
        return $state === null ? null : $this->storedState($state, $commits, $committed, $deadline, $startedAt, $restarts);
    }

    /**
     * Run a store write for a platform's run, when it uses a store (atomic
     * calls only when $atomic). A store failure is reported, never thrown.
     */
    protected function storeCall(string $platform, callable $call, bool $atomic = true): void
    {
        $store = $this->runs[$platform]['store'] ?? null;

        if ($store === null || ($atomic && ! $store instanceof AtomicIdempotencyStore)) {
            return;
        }

        try {
            $call($store);
        } catch (Throwable $e) {
            $this->reportQuietly($e);
        }
    }

    protected function releaseLease(string $platform): void
    {
        $run = $this->runs[$platform] ?? null;

        if ($run !== null) {
            $this->storeCall($platform, fn (AtomicIdempotencyStore $store) => $store->release($run['key'], $run['owner']));
        }
    }

    /**
     * The run of $key an earlier owner left unfinished, in the job's order: a
     * 'committing' record (a create may have gone out: verify first), else a
     * stored pending state. Null when there is nothing to continue.
     *
     * @return array{state: array<string, mixed>, committing: bool, since: ?int}|null
     */
    protected function recordedRun(IdempotencyStore $store, string $key): ?array
    {
        $row = $store instanceof AtomicIdempotencyStore ? $store->committing($key) : null;

        if ($row !== null) {
            return ['state' => $row['state'], 'committing' => true, 'since' => (int) $row['since']];
        }

        $pending = $store->pendingState($key);

        return $pending !== null ? ['state' => $pending, 'committing' => false, 'since' => null] : null;
    }

    /**
     * post(): copy a v1.0 record of this ->idempotencyKey() under the 1.1 key
     * before the job is queued, so the job replays it (see legacyRecord()). A
     * store problem never stops the post.
     */
    protected function adoptLegacyRecord(string $key, Platform $platform): void
    {
        if ($this->idempotencyKey === null || $this->idempotencyKey === '') {
            return;
        }

        $store = $this->resolveStore();

        if ($store === null) {
            return;
        }

        try {
            if ($store->completed($key) === null) {
                $this->legacyRecord($store, $key, $platform);
            }
        } catch (Throwable $e) {
            $this->reportQuietly($e);
        }
    }

    /**
     * A publish v1.0 recorded for this ->idempotencyKey() under its own key
     * format ("{key}:{platform}"), copied under the 1.1 key so it replays
     * instead of posting again (v1.0 promised that a repeated key never
     * double-publishes). Null without an explicit key or such a record.
     */
    protected function legacyRecord(IdempotencyStore $store, string $key, Platform $platform): ?PostResult
    {
        if ($this->idempotencyKey === null || $this->idempotencyKey === '') {
            return null;
        }

        $legacy = $this->idempotencyKey.':'.$platform->value;

        if ($legacy === $key) {
            return null;
        }

        $result = $store->completed($legacy);

        if ($result === null || $result->platform !== $platform) {
            return null;
        }

        try {
            $store->markPublished($key, $result);
        } catch (Throwable $e) {
            // The replay below still holds; only the copy is lost.
            $this->reportQuietly($e);
        }

        return $result;
    }

    protected function resolveStore(): ?IdempotencyStore
    {
        try {
            return Container::getInstance()->make(IdempotencyStore::class);
        } catch (Throwable $e) {
            $this->reportQuietly($e);

            return null;
        }
    }

    protected function accountKey(AbstractPlatform $driver, Credentials $credentials): string
    {
        try {
            return $driver->accountKey($credentials);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Renew this run's lease before its next step, as the job renews it on
     * every step. postNow() runs upload slices back to back until the
     * driver's deadline or async.max_wait (an hour or more), far past the
     * lease taken at the start; once that lapsed, a same-key post() or
     * postNow() would claim the key and publish a second time in parallel.
     * False when another owner holds the key now (this process outlived its
     * lease). A store failure never stops the post.
     */
    protected function renewClaim(AtomicIdempotencyStore $store, string $key, string $owner, Platform $platform): bool
    {
        try {
            return $store->claim($key, $owner, $this->leaseSeconds($platform));
        } catch (Throwable $e) {
            $this->reportQuietly($e);

            return true;
        }
    }

    /**
     * The lease a postNow() takes on its key, renewed before every step: one
     * step (at most one job run) plus the longest wait before the next one
     * (sync.max_wait bounds it) and a margin.
     */
    protected function leaseSeconds(Platform $platform): int
    {
        return PublishToConnectionJob::configuredTimeout($platform) + $this->syncMaxWait() + 60;
    }

    protected function syncMaxWait(): int
    {
        return max(0, $this->setting('sync.max_wait', 120));
    }

    /** social.async.max_wait_per_platform.<platform>, else social.async.max_wait (3600), as the job reads them. */
    protected function asyncMaxWait(Platform $platform): int
    {
        $perPlatform = $this->setting('async.max_wait_per_platform.'.$platform->value, 0);

        return $perPlatform > 0 ? $perPlatform : max(1, $this->setting('async.max_wait', 3600));
    }

    protected function inlineComments(): bool
    {
        return $this->inlineComment ?? (bool) Arr::get($this->config, 'sync.inline_comments', false);
    }

    /** The 'sync' queue connection (or none at all): nothing can be handed over. */
    protected function isSyncConnection(): bool
    {
        $connection = $this->config['connection'] ?? null;
        $name = is_string($connection) && $connection !== '' ? $connection : config('queue.default');

        return ! is_string($name) || $name === '' || config("queue.connections.{$name}.driver") === 'sync';
    }

    /**
     * The queue a comment job goes to runs it at once, whatever its delay:
     * the 'sync' connection. A Queue::fake() records jobs instead of running
     * them, so it honours the delay as the app's real queue would.
     */
    protected function queueIgnoresDelays(): bool
    {
        try {
            if (Container::getInstance()->make('queue') instanceof QueueFake) {
                return false;
            }
        } catch (Throwable) {
            // No queue manager: decide from the config alone.
        }

        return $this->isSyncConnection();
    }

    /** An integer setting from config('social'). */
    protected function setting(string $key, int $default): int
    {
        $value = Arr::get($this->config, $key);

        return is_numeric($value) ? (int) $value : $default;
    }

    protected function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            Sleep::for($seconds)->seconds();
        }
    }

    protected function now(): int
    {
        return now()->getTimestamp();
    }

    protected function safeDispatch(object $event): void
    {
        try {
            $this->events->dispatch($event);
        } catch (Throwable $e) {
            // A listener must never fail or re-run a publish.
            $this->reportQuietly($e);
        }
    }

    protected function reportQuietly(Throwable $e): void
    {
        try {
            report($e);
        } catch (Throwable) {
            // Reporting is best effort.
        }
    }

    protected function redact(string $message): string
    {
        return (string) preg_replace('/((?:access_token|token|key|signature|password)=)[^&\s"]+/i', '$1[redacted]', $message);
    }
}
