<?php

namespace SocialPoster\Events;

use DateTimeInterface;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;

/**
 * A transient failure (rate limit, 5xx, network error) on one platform, or a
 * restart of the publish from scratch (errorSummary context 'restart'); the
 * publish will try again by itself, on the queue or in postNow(). Lets an app
 * tell its user "we will try again automatically" instead of staying silent
 * until the final PostPublished or PostFailed. $retries numbers the tries of
 * the whole publish; $attempt only those of the current step.
 *
 * It carries the error as a plain array (SocialPosterException::errorSummary()),
 * not the exception, so it is cheap to queue and store.
 */
class PostRetrying
{
    use SerializesForQueue;

    /**
     * @param  array<string, mixed>  $errorSummary  SocialPosterException::errorSummary() of the failure.
     * @param  int  $attempt  Attempt (1-based) of the current step that just failed. A publish runs as
     *                        several steps and each counts its own attempts; use $retries for the publish.
     * @param  DateTimeInterface|string|null  $nextRetryAt  When the next attempt is due (ISO-8601 string when given as text).
     * @param  array<string, mixed>  $metadata  Caller correlation data from ->withMetadata().
     * @param  ?string  $correlationId  The id returned by post(), shared by a fan-out.
     * @param  int  $retries  Retries of this publish so far, this one included, across its steps and
     *                        restarts: the next run is attempt $retries + 1 of the publish.
     * @param  int  $restarts  Times this publish started over from scratch so far, this one included
     *                         when it is a restart (errorSummary context 'restart').
     */
    public function __construct(
        public readonly Platform $platform,
        public readonly array $errorSummary,
        public readonly int $attempt,
        public readonly DateTimeInterface|string|null $nextRetryAt = null,
        public readonly array $metadata = [],
        public readonly ?string $correlationId = null,
        public readonly int $retries = 0,
        public readonly int $restarts = 0,
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
