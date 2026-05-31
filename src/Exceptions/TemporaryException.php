<?php

namespace SocialPoster\Exceptions;

use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use Throwable;

/**
 * Retryable. The publish job releases itself with backoff (honouring
 * $retryAfter when the platform supplied one) until $tries is exhausted.
 */
class TemporaryException extends SocialPosterException
{
    public function __construct(
        string $message,
        ?Platform $platform = null,
        FailureReason $reason = FailureReason::ServerError,
        public readonly ?int $retryAfter = null,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $platform, $reason, $context, $previous);
    }
}
