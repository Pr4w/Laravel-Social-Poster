<?php

namespace SocialPoster\Exceptions;

use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;

/**
 * The requested platform has no registered driver (unknown name, a reserved
 * enum case such as Mastodon, or a driver removed from config).
 */
class UnsupportedPlatformException extends PermanentException
{
    /**
     * @param  string[]  $registered
     * @param  ?string  $message  Replaces the default message (e.g. to explain why a registered name cannot be used).
     */
    public function __construct(
        public readonly string $requested,
        public readonly array $registered = [],
        ?string $message = null,
    ) {
        $list = $registered === [] ? 'none' : implode(', ', $registered);

        parent::__construct(
            $message ?? "No social-poster driver is registered for [{$requested}]. Registered drivers: {$list}.",
            Platform::tryFrom($requested),
            FailureReason::Misconfigured,
            ['requested' => $requested, 'registered' => $registered],
        );
    }
}
