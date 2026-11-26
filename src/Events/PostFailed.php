<?php

namespace SocialPoster\Events;

use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\SocialPosterException;

/**
 * Publishing to one platform failed for good (or, with $willRetry, a failure the
 * job reports while it still has attempts left). Transient failures that will be
 * retried normally surface as PostRetrying instead.
 *
 * When $possiblyPublished is true the create call was ambiguous (a timeout after
 * the request was sent, a deadline passed on a post the platform already
 * accepted): the post may be live, so check the platform before re-posting.
 *
 * Queued listeners: use errorSummary() for a plain array. The exception survives
 * the queue too, but as a portable copy when the original cannot be serialised
 * (stack frames lose their arguments).
 */
class PostFailed
{
    use SerializesForQueue;

    /**
     * @param  array<string, mixed>  $metadata  Caller correlation data from ->withMetadata().
     * @param  ?string  $correlationId  The id returned by post() / passed to ->correlationId(), shared by a fan-out.
     * @param  ?string  $idempotencyKey  The per-platform, per-account key the store records this publish under.
     * @param  int  $attempt  Attempt (1-based) of the step that failed; use $retries for the publish.
     * @param  bool  $willRetry  True when the job will run again after this event.
     * @param  bool  $possiblyPublished  True when the post may exist on the platform despite the failure.
     * @param  int  $retries  Retries before this failure, across the publish's steps and restarts (the
     *                        PostRetrying events it fired, plus any run the queue redelivered): it failed on attempt $retries + 1.
     * @param  int  $restarts  Times the publish started over from scratch.
     */
    public function __construct(
        public readonly Platform $platform,
        public readonly SocialPosterException $exception,
        public readonly array $metadata = [],
        public readonly ?string $correlationId = null,
        public readonly ?string $idempotencyKey = null,
        public readonly int $attempt = 1,
        public readonly bool $willRetry = false,
        public readonly bool $possiblyPublished = false,
        public readonly int $retries = 0,
        public readonly int $restarts = 0,
    ) {}

    /**
     * A serialisable, JSON-safe summary of the failure (platform, reason,
     * category, message, class, context reduced to plain values). The category
     * is 'verify' when $possiblyPublished is set, as for the exception's own
     * possibly_published flag.
     *
     * @return array{platform: ?string, reason: string, category: string, message: string, class: string, context: array<string, mixed>}
     */
    public function errorSummary(): array
    {
        $summary = QueueSafe::summary($this->exception, $this->platform);

        if ($this->possiblyPublished) {
            $summary['category'] = 'verify';
        }

        return $summary;
    }
}
