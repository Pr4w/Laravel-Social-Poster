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

    /**
     * A connection-level failure (cURL timeout, DNS, refused connection, TLS).
     * These never produce a response, so a driver's classifyError() never sees
     * them; they are transient by nature and retried with backoff.
     */
    public static function connection(?Platform $platform, Throwable $e): self
    {
        return new self(
            'Network error contacting '.($platform?->value ?? 'the platform').': '.$e->getMessage(),
            $platform,
            FailureReason::Timeout,
            null,
            ['connection' => true],
            $e,
        );
    }
}
