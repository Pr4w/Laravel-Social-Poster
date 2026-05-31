<?php

namespace SocialPoster\Exceptions;

use RuntimeException;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use Throwable;

abstract class SocialPosterException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?Platform $platform = null,
        public readonly FailureReason $reason = FailureReason::Unknown,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
