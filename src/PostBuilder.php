<?php

namespace SocialPoster;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\MessageBag;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Contracts\SocialPlatform;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\PostFailed;
use SocialPoster\Events\PostPublished;
use SocialPoster\Events\PostQueued;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Exceptions\ValidationException;
use SocialPoster\Jobs\PublishToConnectionJob;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\Published;
use SocialPoster\ValueObjects\SocialPost;
use SocialPoster\ValueObjects\ValidationResult;

/**
 * The fluent surface. Terminal methods are just stages of one pipeline:
 *   validate()  -> stage 1 only, returns results (never throws)
 *   postNow()   -> validate (all-or-nothing), then publish synchronously
 *   post()      -> validate (all-or-nothing), then queue one job per platform
 * withoutValidation() skips stage 1 in the post paths.
 */
class PostBuilder
{
    /** @var Platform[] */
    protected array $platforms = [];

    protected ?Credentials $credentials = null;

    /** @var Media[] */
    protected array $media = [];

    protected ?string $caption = null;

    protected ?string $title = null;

    protected ?PlatformOptions $options = null;

    /** @var array<string, mixed> */
    protected array $metadata = [];

    protected bool $skipValidation = false;

    public function __construct(
        protected SocialManager $manager,
        protected Dispatcher $events,
        protected array $config = [],
    ) {}

    public function on(string|Platform ...$platforms): static
    {
        foreach ($platforms as $platform) {
            $this->platforms[] = $platform instanceof Platform ? $platform : Platform::from($platform);
        }

        return $this;
    }

    public function using(Credentials|array $credentials): static
    {
        $this->credentials = $credentials instanceof Credentials
            ? $credentials
            : Credentials::make($credentials);

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

    public function withOptions(PlatformOptions $options): static
    {
        $this->options = $options;

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
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): static
    {
        $this->metadata = array_merge($this->metadata, $metadata);

        return $this;
    }

    public function withoutValidation(): static
    {
        $this->skipValidation = true;

        return $this;
    }

    /**
     * Validate only, no posting. Keyed by platform value.
     *
     * @return array<string, ValidationResult>
     */
    public function validate(): array
    {
        $results = [];

        foreach ($this->buildPrepared(requireCredentials: false) as $value => [$driver, $prepared]) {
            $results[$value] = new ValidationResult($prepared->platform, $driver->validate($prepared));
        }

        return $results;
    }

    /**
     * Publish synchronously. Validation is all-or-nothing up front; publishing
     * is best-effort per platform, so one platform failing does not undo another.
     *
     * For async platforms this drives the publish/resume loop in-process, sleeping
     * between polls. That blocking is the cost of asking for it synchronously; for
     * reels, stories and other slow flows, prefer post().
     *
     * @return array<string, PostResult>
     */
    public function postNow(): array
    {
        $prepared = $this->buildPrepared(requireCredentials: true);
        $this->guardValidation($prepared);

        $results = [];

        foreach ($prepared as $value => [$driver, $post]) {
            try {
                $outcome = $driver->publish($post);
                $polls = 0;

                while ($outcome instanceof Pending) {
                    if ($polls++ >= 60) {
                        throw new TemporaryException(
                            'Timed out waiting for the platform to finish processing.',
                            $post->platform,
                            FailureReason::Timeout,
                        );
                    }

                    sleep(max(1, $outcome->recheckAfter));
                    $outcome = $driver->resume($post, $outcome->state);
                }

                /** @var Published $outcome */
                $result = $outcome->result->withMetadata($post->metadata());
                $this->events->dispatch(new PostPublished($result));
            } catch (SocialPosterException $e) {
                $this->events->dispatch(new PostFailed($post->platform, $e, $post->metadata()));
                $result = PostResult::failed($post->platform, $e)->withMetadata($post->metadata());
            }

            $results[$value] = $result;
        }

        return $results;
    }

    /** Queue one job per platform. Outcomes arrive via PostPublished / PostFailed. */
    public function post(): void
    {
        $prepared = $this->buildPrepared(requireCredentials: true);
        $this->guardValidation($prepared);

        $queue = $this->config['queue'] ?? null;

        foreach ($prepared as [, $post]) {
            // Validation already ran synchronously above, so the job need not repeat it.
            $job = new PublishToConnectionJob($post->platform, $post->post, $post->credentials, skipValidation: true);

            if (! empty($queue)) {
                $job->onQueue($queue);
            }

            dispatch($job);
            $this->events->dispatch(new PostQueued($post->platform, $post->post, $post->metadata()));
        }
    }

    /**
     * @return array<string, array{0: SocialPlatform, 1: PreparedPost}>
     */
    protected function buildPrepared(bool $requireCredentials): array
    {
        $post = new SocialPost($this->media, $this->caption, $this->title, $this->options, $this->metadata);
        $map = [];

        foreach ($this->platforms as $platform) {
            $driver = $this->manager->driver($platform->value);
            $prepared = new PreparedPost($platform, $post, $this->credentialsFor($platform, $requireCredentials));
            $map[$platform->value] = [$driver, $prepared];
        }

        return $map;
    }

    /** @param array<string, array{0: SocialPlatform, 1: PreparedPost}> $prepared */
    protected function guardValidation(array $prepared): void
    {
        if ($this->skipValidation) {
            return;
        }

        $combined = new MessageBag();

        foreach ($prepared as $value => [$driver, $post]) {
            foreach ($driver->validate($post)->toArray() as $field => $messages) {
                foreach ($messages as $message) {
                    $combined->add("{$value}.{$field}", $message);
                }
            }
        }

        if ($combined->isNotEmpty()) {
            throw new ValidationException('Content failed validation before posting.', $combined);
        }
    }

    protected function credentialsFor(Platform $platform, bool $required): Credentials
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $configured = $this->config['platforms'][$platform->value]['credentials'] ?? [];

        if (empty($configured) && $required) {
            throw new PermanentException(
                "No credentials supplied or configured for {$platform->value}.",
                $platform,
                FailureReason::InvalidToken,
            );
        }

        return Credentials::make($configured);
    }
}
