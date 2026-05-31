<?php

namespace SocialPoster\ValueObjects;

/**
 * The platform is still processing. The job re-dispatches a continuation
 * carrying $state after $recheckAfter seconds, which calls the driver's resume().
 */
final class Pending extends PublishOutcome
{
    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        public readonly array $state,
        public readonly int $recheckAfter = 30,
    ) {}
}
