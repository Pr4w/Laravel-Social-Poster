<?php

namespace SocialPoster\Events;

use DateTimeInterface;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;

/**
 * A transient failure (rate limit, 5xx, network error) on one platform; the job
 * will try again by itself. Lets an app tell its user "we will try again
 * automatically" instead of staying silent until the final PostPublished or
 * PostFailed.
 *
 * It carries the error as a plain array (SocialPosterException::errorSummary()),
 * not the exception, so it is cheap to queue and store.
 */
class PostRetrying
{
    use SerializesForQueue;

    /**
     * @param  array<string, mixed>  $errorSummary  SocialPosterException::errorSummary() of the failure.
     * @param  int  $attempt  Queue attempt (1-based) that just failed.
     * @param  DateTimeInterface|string|null  $nextRetryAt  When the next attempt is due (ISO-8601 string when given as text).
     * @param  array<string, mixed>  $metadata  Caller correlation data from ->withMetadata().
     * @param  ?string  $correlationId  The id returned by post(), shared by a fan-out.
     */
    public function __construct(
        public readonly Platform $platform,
        public readonly array $errorSummary,
        public readonly int $attempt,
        public readonly DateTimeInterface|string|null $nextRetryAt = null,
        public readonly array $metadata = [],
        public readonly ?string $correlationId = null,
    ) {}

    /** @return array<string, mixed> */
    public function errorSummary(): array
    {
        return $this->errorSummary;
    }

    /** The failure reason, when the summary names a known one. */
    public function reason(): ?FailureReason
    {
        $reason = $this->errorSummary['reason'] ?? null;

        return is_string($reason) ? FailureReason::tryFrom($reason) : null;
    }
}
