<?php

namespace SocialPoster\Exceptions;

use Illuminate\Support\MessageBag;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;

/**
 * Pre-flight failure: the content never reached the platform. Thrown by the
 * post paths; the validate() path returns a ValidationResult instead.
 */
class ValidationException extends SocialPosterException
{
    public function __construct(
        string $message,
        public readonly MessageBag $errors,
        ?Platform $platform = null,
        array $context = [],
    ) {
        parent::__construct($message, $platform, FailureReason::Unknown, $context);
    }

    public static function fromMessageBag(Platform $platform, MessageBag $errors): self
    {
        return new self("Content failed validation for {$platform->value}.", $errors, $platform);
    }
}
