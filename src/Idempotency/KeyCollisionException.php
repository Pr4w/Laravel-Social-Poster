<?php

namespace SocialPoster\Idempotency;

use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;

/**
 * One idempotency key was used for two different accounts (or platforms).
 * Replaying the first account's result would hand the second account a post id
 * it never published, and claiming the key would block one of them, so the
 * second publish stops here. It is a caller bug: give every account its own key
 * (the job's default key already includes the driver's accountKey()).
 *
 * Context: key_collision = true, key_hash (sha256 of the key), bound_platform.
 * The job must not forget() the key on this failure: the record belongs to the
 * account that bound it first.
 */
class KeyCollisionException extends PermanentException
{
    public static function make(string $key, Platform|string $platform, ?string $boundPlatform): self
    {
        $value = $platform instanceof Platform ? $platform->value : $platform;

        return new self(
            'Idempotency key collision: this key is already bound to a different account or platform. '
            .'Use a distinct idempotency key per account.',
            Platform::tryFrom($value),
            FailureReason::Misconfigured,
            [
                'key_collision' => true,
                'key_hash' => hash('sha256', $key),
                'bound_platform' => $boundPlatform,
            ],
        );
    }
}
