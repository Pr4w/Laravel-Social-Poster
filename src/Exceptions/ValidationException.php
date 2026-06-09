<?php

namespace SocialPoster\Exceptions;

use Illuminate\Support\MessageBag;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;

/**
 * Pre-flight failure: the content never reached the platform. Thrown by the
 * post paths; the validate() path returns a ValidationResult instead. The
 * per-field reasons are folded into the message (and into context['errors']) so
 * a thrown exception explains itself in logs and stack traces, not just a
 * generic "failed validation" line.
 */
class ValidationException extends SocialPosterException
{
    public function __construct(
        string $message,
        public readonly MessageBag $errors,
        ?Platform $platform = null,
        array $context = [],
    ) {
        parent::__construct(
            static::describe($message, $errors),
            $platform,
            FailureReason::Unknown,
            $context + ['errors' => $errors->toArray()],
        );
    }

    public static function fromMessageBag(Platform $platform, MessageBag $errors): self
    {
        return new self("Content failed validation for {$platform->value}.", $errors, $platform);
    }

    /** Append the flattened "field: reason" pairs to the base message. */
    protected static function describe(string $message, MessageBag $errors): string
    {
        $parts = [];

        foreach ($errors->toArray() as $field => $messages) {
            foreach ((array) $messages as $reason) {
                $parts[] = "{$field}: {$reason}";
            }
        }

        return $parts === [] ? $message : $message.' ('.implode('; ', $parts).')';
    }
}
