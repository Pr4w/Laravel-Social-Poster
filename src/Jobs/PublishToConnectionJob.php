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
use Illuminate\Http\Client\ConnectionException;
use SocialPoster\Contracts\IdempotencyStore;
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
        // Per platform: a shared correlation id across a fan-out must not collide.
        // contentKey() already includes the platform.
        return $this->correlationId !== null
            ? $this->correlationId.':'.$this->platform->value
            : $this->contentKey();
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

    public function handle(SocialManager $platforms, Dispatcher $events, IdempotencyStore $idempotency): void
    {
        $driver = $platforms->driver($this->platform->value);
        $prepared = new PreparedPost($this->platform, $this->post, $this->credentials);
        $key = $this->uniqueId();

        // Already published on a prior attempt: replay, never post again.
        $prior = $idempotency->completed($key);

        if ($prior !== null) {
            $result = $prior->withMetadata($this->post->metadata);
            $events->dispatch(new PostPublished($result));
            $this->dispatchComment($driver, $result);

            return;
        }

        // Resume from the persisted async state if a crashed attempt left one,
        // so the upload is continued rather than re-created.
        $state = $this->state ?? $idempotency->pendingState($key);

        try {
            if ($state === null && ! $this->skipValidation) {
                $errors = $driver->validate($prepared);

                if ($errors->isNotEmpty()) {
                    throw ValidationException::fromMessageBag($this->platform, $errors);
                }
            }

            $outcome = $state === null
                ? $driver->publish($prepared)
                : $driver->resume($prepared, $state);

            if ($outcome instanceof Pending) {
                $idempotency->markPending($key, $outcome->state);
                $this->scheduleContinuation($outcome, $events);

                return;
            }

            if ($outcome instanceof Published) {
                $idempotency->markPublished($key, $outcome->result);
                $result = $outcome->result->withMetadata($this->post->metadata);
                $events->dispatch(new PostPublished($result));
                $this->dispatchComment($driver, $result);
            }
        } catch (ConnectionException $e) {
            // cURL-level failure (timeout, DNS, refused, TLS): no response was
            // ever returned, so the driver could not classify it. Transient.
            $this->logTransport($e);
            $this->releaseOrFail(TemporaryException::connection($this->platform, $e), $events, $idempotency, $key);
        } catch (TemporaryException $e) {
            $this->releaseOrFail($e, $events, $idempotency, $key);
        } catch (SocialPosterException $e) {
            $idempotency->forget($key);
            $events->dispatch(new PostFailed($this->platform, $e, $this->post->metadata));
            $this->fail($e);
        }
    }

    protected function logTransport(\Throwable $e): void
    {
        if (! (bool) config('social.error_logging', true)) {
            return;
        }

        $context = [
            'platform' => $this->platform->value,
            'transport' => true,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'attempt' => $this->attempts(),
            'tries' => $this->tries,
            'will_retry' => $this->attempts() < $this->tries,
        ];

        $message = '[SocialPoster] '.$this->platform->value.' transport error: '.$e->getMessage();

        try {
            if ($channel = config('social.error_log_channel')) {
                \Illuminate\Support\Facades\Log::channel($channel)->error($message, $context);
            } else {
                \Illuminate\Support\Facades\Log::error($message, $context);
            }
        } catch (\Throwable) {
            // never let logging interfere with retry handling
        }
    }

    protected function releaseOrFail(TemporaryException $e, Dispatcher $events, IdempotencyStore $idempotency, string $key): void
    {
        if ($this->attempts() >= $this->tries) {
            $idempotency->forget($key);
            $events->dispatch(new PostFailed($this->platform, $e, $this->post->metadata));
            $this->fail($e);

            return;
        }

        $this->release($e->retryAfter ?? $this->backoffFor($this->attempts()));
    }

    protected function dispatchComment(\SocialPoster\Contracts\SocialPlatform $driver, \SocialPoster\ValueObjects\PostResult $result): void
    {
        $comment = $this->post->comment;

        if ($comment === null || $result->platformPostId === null || ! $driver instanceof \SocialPoster\Contracts\SupportsComments) {
            return;
        }

        $delay = (int) (config('social.comment_delay', 10));

        $job = AddFirstCommentJob::dispatch(
            $this->platform,
            $result->platformPostId,
            $comment,
            $this->credentials,
            $this->post->metadata,
        )->delay(now()->addSeconds(max(0, $delay)));

        if (! empty($this->queue)) {
            $job->onQueue($this->queue);
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

            $events->dispatch(new PostFailed($this->platform, $error, $this->post->metadata));
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
