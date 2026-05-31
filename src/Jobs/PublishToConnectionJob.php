<?php

namespace SocialPoster\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\PostFailed;
use SocialPoster\Events\PostPublished;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Exceptions\ValidationException;
use SocialPoster\SocialManager;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\Published;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\SocialPost;

/**
 * Drives one platform's publish to completion. On the first run it validates
 * (unless skipped) and calls publish(). If the driver returns Pending, a delayed
 * continuation job is dispatched carrying the state, which calls resume() on its
 * run. This repeats until Published or the poll budget is exhausted, so a worker
 * never blocks waiting on the platform.
 *
 * Carrying state forward requires a fresh dispatch, not release(): a released job
 * re-runs its original payload. Same-state temporary errors do use release(),
 * since this job's payload already holds the current state.
 *
 * Note: credentials are serialised into the queue payload; use an encrypted or
 * trusted queue store if your tokens are sensitive.
 *
 * Uniqueness: ShouldBeUniqueUntilProcessing prevents the same publish being
 * dispatched twice while one is still queued. The lock releases when the job
 * starts, so it does not block the continuation this job dispatches for itself.
 * It guards duplicate dispatch, not at-least-once redelivery; surviving a retry
 * that already published needs a persisted idempotency store. Without an explicit
 * correlationId the lock key is derived from the post content, so two genuinely
 * identical posts queued at the same moment will collide; pass a correlationId to
 * make each publish distinct. Requires a cache store that supports atomic locks.
 */
class PublishToConnectionJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $maxPolls = 60;

    public int $uniqueFor = 3600;

    /**
     * @param array<string, mixed>|null $state
     */
    public function __construct(
        public readonly Platform $platform,
        public readonly SocialPost $post,
        public readonly Credentials $credentials,
        public readonly bool $skipValidation = false,
        public readonly ?array $state = null,
        public readonly int $pollCount = 0,
        public readonly ?string $correlationId = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->correlationId ?? $this->contentKey();
    }

    protected function contentKey(): string
    {
        $sources = array_map(static fn ($media) => $media->source, $this->post->media);

        return $this->platform->value.':'.md5((string) json_encode([
            $this->post->caption,
            $this->post->title,
            $sources,
        ]));
    }

    public function handle(SocialManager $platforms, Dispatcher $events): void
    {
        $driver = $platforms->driver($this->platform->value);
        $prepared = new PreparedPost($this->platform, $this->post, $this->credentials);

        try {
            if ($this->state === null && ! $this->skipValidation) {
                $errors = $driver->validate($prepared);

                if ($errors->isNotEmpty()) {
                    throw ValidationException::fromMessageBag($this->platform, $errors);
                }
            }

            $outcome = $this->state === null
                ? $driver->publish($prepared)
                : $driver->resume($prepared, $this->state);

            if ($outcome instanceof Pending) {
                $this->scheduleContinuation($outcome, $events);

                return;
            }

            if ($outcome instanceof Published) {
                $events->dispatch(new PostPublished($outcome->result));
            }
        } catch (TemporaryException $e) {
            if ($this->attempts() >= $this->tries) {
                $events->dispatch(new PostFailed($this->platform, $e));
                $this->fail($e);

                return;
            }

            $this->release($e->retryAfter ?? $this->backoffFor($this->attempts()));
        } catch (SocialPosterException $e) {
            $events->dispatch(new PostFailed($this->platform, $e));
            $this->fail($e);
        }
    }

    protected function scheduleContinuation(Pending $pending, Dispatcher $events): void
    {
        if ($this->pollCount >= $this->maxPolls) {
            $error = new TemporaryException(
                'Publishing timed out while the platform was still processing the media.',
                $this->platform,
                FailureReason::Timeout,
            );

            $events->dispatch(new PostFailed($this->platform, $error));
            $this->fail($error);

            return;
        }

        $next = static::dispatch(
            $this->platform,
            $this->post,
            $this->credentials,
            true,
            $pending->state,
            $this->pollCount + 1,
            $this->correlationId,
        )->delay(now()->addSeconds($pending->recheckAfter));

        if (! empty($this->queue)) {
            $next->onQueue($this->queue);
        }
    }

    /** @return int[] */
    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    protected function backoffFor(int $attempt): int
    {
        $schedule = $this->backoff();
        $index = min($attempt, count($schedule)) - 1;

        return $schedule[$index] ?? (int) end($schedule);
    }
}