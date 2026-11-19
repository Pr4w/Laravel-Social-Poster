<?php

namespace SocialPoster\ValueObjects;

/**
 * Terminal outcome: the platform accepted the post. $result->state tells a live
 * post from a scheduled, draft, private or still-processing one.
 */
final class Published extends PublishOutcome
{
    public function __construct(
        public readonly PostResult $result,
    ) {}
}
