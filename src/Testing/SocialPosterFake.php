<?php

namespace SocialPoster\Testing;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\MessageBag;
use PHPUnit\Framework\Assert as PHPUnit;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\PostFailed;
use SocialPoster\Events\PostProcessing;
use SocialPoster\Events\PostPublished;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\PostBuilder;
use SocialPoster\Poster;
use SocialPoster\SocialManager;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\SocialPost;
use SocialPoster\ValueObjects\ValidationResult;

/**
 * A recording stand-in for the Poster, swapped in by SocialPoster::fake().
 * No driver, queue or HTTP call is involved: every terminal call (post(),
 * postNow(), validate()) records the PreparedPost of each platform, and the
 * publish outcome is scripted per platform:
 *
 *   $fake = SocialPoster::fake();
 *   $fake->succeed('instagram', 'ig-1', 'https://instagram.com/p/x')
 *        ->fail('linkedin', FailureReason::InvalidToken)
 *        ->pending('tiktok');
 *
 *   // ... code under test calls SocialPoster::on(...)->...->post() ...
 *
 *   $fake->assertQueued('instagram', fn (PreparedPost $post) => $post->caption() === 'Hello');
 *   $fake->assertCommented('instagram', 'First!');
 *   $fake->assertNotPosted('x');
 *
 * Unscripted platforms succeed with a generated id ('fake-<platform>-<n>').
 * Credentials are never required. Events are not dispatched unless
 * dispatchEvents() is on; then post() dispatches PostQueued followed by the
 * scripted outcome's event, as if the queue ran at once, and postNow() the
 * outcome's event.
 *
 * Assertion callbacks receive (PreparedPost $post, string $terminal).
 */
class SocialPosterFake extends Poster
{
    public const TERMINAL_POST = 'post';

    public const TERMINAL_POST_NOW = 'postNow';

    public const TERMINAL_VALIDATE = 'validate';

    /** @var list<array{terminal: string, post: PreparedPost, correlation_id: ?string, idempotency_key: ?string, result: PostResult|ValidationResult|null}> */
    protected array $recorded = [];

    /** @var array<string, PostResult|SocialPosterException|string> Scripted outcome per platform value ('pending' for processing). */
    protected array $scripts = [];

    /** @var array<string, MessageBag> Scripted validation errors per platform value. */
    protected array $invalid = [];

    protected bool $dispatchEvents = false;

    /** @var list<array{key: string, platform: ?Platform}> */
    protected array $reconciled = [];

    protected int $sequence = 0;

    /** @param array<string, mixed> $config A fixed config('social'); empty reads it live. */
    public function __construct(?SocialManager $manager = null, ?Dispatcher $events = null, array $config = [])
    {
        $container = Container::getInstance();

        parent::__construct(
            $manager ?? $container->make(SocialManager::class),
            $events ?? $container->make(Dispatcher::class),
            $config,
            $config === [],
        );
    }

    public function newBuilder(): PostBuilder
    {
        return new FakePostBuilder($this, $this->manager, $this->events(), $this->config());
    }

    // --- Scripting ----------------------------------------------------------------

    /**
     * Publishes to $platform succeed with this id and url (default: a
     * generated id, no url).
     *
     * @param  array<string, mixed>  $payload
     */
    public function succeed(Platform|string $platform, ?string $id = null, ?string $url = null, array $payload = [], ?string $state = null): static
    {
        $platform = $this->platform($platform);
        $this->scripts[$platform->value] = PostResult::success($platform, $id, $url, $payload, $state);

        return $this;
    }

    /**
     * Publishes to $platform fail with this reason (a PermanentException, or
     * a TemporaryException with $temporary).
     *
     * @param  array<string, mixed>  $context
     */
    public function fail(Platform|string $platform, FailureReason $reason = FailureReason::Unknown, ?string $message = null, bool $temporary = false, array $context = []): static
    {
        $platform = $this->platform($platform);
        $message ??= "The {$platform->value} post failed ({$reason->value}) in the fake.";

        $this->scripts[$platform->value] = $temporary
            ? new TemporaryException($message, $platform, $reason, null, $context)
            : new PermanentException($message, $platform, $reason, $context);

        return $this;
    }

    /** Publishes to $platform are accepted but still processing (PostResult state 'processing'). */
    public function pending(Platform|string $platform): static
    {
        $this->scripts[$this->platform($platform)->value] = 'pending';

        return $this;
    }

    /**
     * $platform fails validation with these errors: validate() reports them and
     * the post paths throw ValidationException (or skip it under bestEffort()).
     *
     * @param  array<string, string|list<string>>  $errors  Field => message(s).
     */
    public function rejectValidation(Platform|string $platform, array $errors = ['caption' => 'Rejected by SocialPosterFake.']): static
    {
        $bag = new MessageBag;

        foreach ($errors as $field => $messages) {
            foreach ((array) $messages as $message) {
                $bag->add((string) $field, (string) $message);
            }
        }

        $this->invalid[$this->platform($platform)->value] = $bag;

        return $this;
    }

    /** Dispatch the package events the real terminals would (PostQueued, PostPublished, PostFailed...). */
    public function dispatchEvents(bool $dispatch = true): static
    {
        $this->dispatchEvents = $dispatch;

        return $this;
    }

    // --- Queries ------------------------------------------------------------------

    /**
     * Posts published with post() or postNow().
     *
     * @return list<PreparedPost>
     */
    public function posted(Platform|string|null $platform = null, ?callable $callback = null): array
    {
        return $this->matching([self::TERMINAL_POST, self::TERMINAL_POST_NOW], $platform, $callback);
    }

    /** @return list<PreparedPost> Posts queued with post(). */
    public function queued(Platform|string|null $platform = null, ?callable $callback = null): array
    {
        return $this->matching([self::TERMINAL_POST], $platform, $callback);
    }

    /** @return list<PreparedPost> Posts published with postNow(). */
    public function postedNow(Platform|string|null $platform = null, ?callable $callback = null): array
    {
        return $this->matching([self::TERMINAL_POST_NOW], $platform, $callback);
    }

    /** @return list<PreparedPost> Posts checked with validate(). */
    public function validated(Platform|string|null $platform = null, ?callable $callback = null): array
    {
        return $this->matching([self::TERMINAL_VALIDATE], $platform, $callback);
    }

    /**
     * Every recorded terminal call, in order.
     *
     * @return list<array{terminal: string, post: PreparedPost, correlation_id: ?string, idempotency_key: ?string, result: PostResult|ValidationResult|null}>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    // --- Assertions ---------------------------------------------------------------

    public function assertPosted(Platform|string $platform, ?callable $callback = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->posted($platform, $callback),
            "The expected [{$this->platform($platform)->value}] post was not published.",
        );
    }

    public function assertPostedTimes(Platform|string $platform, int $times = 1): void
    {
        $count = count($this->posted($platform));

        PHPUnit::assertSame(
            $times,
            $count,
            "The [{$this->platform($platform)->value}] post was published {$count} time(s) instead of {$times}.",
        );
    }

    public function assertNotPosted(Platform|string $platform, ?callable $callback = null): void
    {
        PHPUnit::assertEmpty(
            $this->posted($platform, $callback),
            "An unexpected [{$this->platform($platform)->value}] post was published.",
        );
    }

    public function assertNothingPosted(): void
    {
        $platforms = array_unique(array_map(static fn (PreparedPost $post) => $post->platform->value, $this->posted()));

        PHPUnit::assertEmpty($platforms, 'Posts were published to: '.implode(', ', $platforms).'.');
    }

    public function assertQueued(Platform|string $platform, ?callable $callback = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->queued($platform, $callback),
            "The expected [{$this->platform($platform)->value}] post was not queued with post().",
        );
    }

    public function assertPostedNow(Platform|string $platform, ?callable $callback = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->postedNow($platform, $callback),
            "The expected [{$this->platform($platform)->value}] post was not published with postNow().",
        );
    }

    public function assertValidated(Platform|string|null $platform = null, ?callable $callback = null): void
    {
        $what = $platform === null ? 'Nothing was' : "[{$this->platform($platform)->value}] was not";

        PHPUnit::assertNotEmpty($this->validated($platform, $callback), "{$what} validated.");
    }

    /**
     * A post to $platform was published with a first comment: any comment, the
     * exact text, or one the callback accepts (it receives the comment text and
     * the PreparedPost).
     */
    public function assertCommented(Platform|string $platform, string|callable|null $comment = null): void
    {
        $matches = $this->posted($platform, static function (PreparedPost $post) use ($comment): bool {
            $text = $post->comment();

            if ($text === null || trim($text) === '') {
                return false;
            }

            if ($comment === null) {
                return true;
            }

            return is_string($comment) ? $text === $comment : (bool) $comment($text, $post);
        });

        PHPUnit::assertNotEmpty(
            $matches,
            "No [{$this->platform($platform)->value}] post was published with the expected first comment.",
        );
    }

    /** reconcile() was called (for this key). */
    public function assertReconciled(?string $key = null): void
    {
        $keys = array_column($this->reconciled, 'key');

        PHPUnit::assertTrue(
            $key === null ? $keys !== [] : in_array($key, $keys, true),
            $key === null ? 'Nothing was reconciled.' : "The key [{$key}] was not reconciled.",
        );
    }

    // --- Poster -------------------------------------------------------------------

    /** The published result a faked publish recorded under the key (correlation id or idempotency key). */
    public function outcome(string $key, Platform|string|null $platform = null, Credentials|array|null $credentials = null): ?PostResult
    {
        $platform = $this->platformOrNull($platform);

        foreach (array_reverse($this->recorded) as $entry) {
            $result = $entry['result'];

            if (! $result instanceof PostResult || ! $result->isPublished()) {
                continue;
            }

            if (! in_array($key, [$entry['correlation_id'], $entry['idempotency_key']], true)) {
                continue;
            }

            if ($platform === null || $entry['post']->platform === $platform) {
                return $result;
            }
        }

        return null;
    }

    public function status(string $key, Platform|string|null $platform = null, Credentials|array|null $credentials = null): ?string
    {
        return $this->outcome($key, $platform) !== null ? 'published' : null;
    }

    /** Records the call; a faked publish under the key counts as replayed. */
    public function reconcile(string $key, Platform|string|null $platform = null, Credentials|array|null $credentials = null, ?SocialPost $post = null): string
    {
        $platform = $this->platformOrNull($platform);
        $this->reconciled[] = ['key' => $key, 'platform' => $platform];

        return $this->outcome($key, $platform) !== null ? self::RECONCILE_REPLAYED : self::RECONCILE_NOTHING;
    }

    // --- Used by FakePostBuilder --------------------------------------------------

    /** @internal */
    public function validationErrorsFor(Platform $platform): MessageBag
    {
        return isset($this->invalid[$platform->value]) ? clone $this->invalid[$platform->value] : new MessageBag;
    }

    /** @internal The idempotency key a faked publish reports. */
    public function keyFor(Platform $platform, string $correlationId): string
    {
        return 'fake:'.$platform->value.':'.$correlationId;
    }

    /** @internal The scripted result of one publish. */
    public function resultFor(PreparedPost $post, string $correlationId, string $key): PostResult
    {
        $platform = $post->platform;
        $script = $this->scripts[$platform->value] ?? null;

        if ($script instanceof SocialPosterException) {
            return PostResult::failed($platform, $script)->withMetadata($post->metadata());
        }

        if ($script === 'pending') {
            return new PostResult(
                $platform,
                true,
                null,
                null,
                ['idempotency_key' => $key, 'correlation_id' => $correlationId],
                null,
                $post->metadata(),
                PostResult::STATE_PROCESSING,
            );
        }

        $result = $script instanceof PostResult ? $script : PostResult::success($platform);

        if ($result->platformPostId === null) {
            $result = PostResult::success($platform, 'fake-'.$platform->value.'-'.(++$this->sequence), $result->url, $result->payload, $result->state, $result->idKind);
        }

        return $result->withMetadata($post->metadata());
    }

    /** @internal */
    public function record(string $terminal, PreparedPost $post, ?string $correlationId, ?string $key, PostResult|ValidationResult|null $result): void
    {
        $this->recorded[] = [
            'terminal' => $terminal,
            'post' => $post,
            'correlation_id' => $correlationId,
            'idempotency_key' => $key,
            'result' => $result,
        ];
    }

    /** @internal */
    public function shouldDispatchEvents(): bool
    {
        return $this->dispatchEvents;
    }

    /** @internal Dispatch the event the real pipeline sends for this outcome, when events are on. */
    public function announce(PreparedPost $post, PostResult $result, string $correlationId, ?string $key): void
    {
        if (! $this->dispatchEvents) {
            return;
        }

        $events = $this->events();

        if (! $result->success && $result->error !== null) {
            $events->dispatch(new PostFailed($post->platform, $result->error, $post->metadata(), $correlationId, $key, 1, false, $result->error->possiblyPublished()));

            return;
        }

        if ($result->isProcessing()) {
            $events->dispatch(new PostProcessing($post->platform, 1, 0, $post->metadata(), $correlationId));

            return;
        }

        $events->dispatch(new PostPublished($result, $correlationId, $key));
    }

    /** @internal */
    public function dispatcher(): Dispatcher
    {
        return $this->events();
    }

    // --- Internals ----------------------------------------------------------------

    /**
     * @param  list<string>  $terminals
     * @return list<PreparedPost>
     */
    protected function matching(array $terminals, Platform|string|null $platform, ?callable $callback): array
    {
        $platform = $platform === null ? null : $this->platform($platform);
        $posts = [];

        foreach ($this->recorded as $entry) {
            if (! in_array($entry['terminal'], $terminals, true)) {
                continue;
            }

            if ($platform !== null && $entry['post']->platform !== $platform) {
                continue;
            }

            if ($callback !== null && ! $callback($entry['post'], $entry['terminal'])) {
                continue;
            }

            $posts[] = $entry['post'];
        }

        return $posts;
    }

    protected function platform(Platform|string $platform): Platform
    {
        return $this->platformOrNull($platform) ?? throw new \LogicException('A platform is required.');
    }
}
