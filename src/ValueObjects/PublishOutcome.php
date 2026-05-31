<?php

namespace SocialPoster\ValueObjects;

/**
 * The result of one publish step. Either terminal (Published) or a request to
 * be resumed after the platform finishes async processing (Pending). Failures
 * are signalled by throwing Temporary/Permanent exceptions, not by an outcome.
 */
abstract class PublishOutcome
{
    public static function published(PostResult $result): Published
    {
        return new Published($result);
    }

    /**
     * @param array<string, mixed> $state Serializable continuation state (phase, container ids, ...).
     */
    public static function pending(array $state, int $recheckAfter = 30): Pending
    {
        return new Pending($state, $recheckAfter);
    }
}
