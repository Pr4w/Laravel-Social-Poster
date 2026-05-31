<?php

namespace SocialPoster\ValueObjects;

final class Published extends PublishOutcome
{
    public function __construct(
        public readonly PostResult $result,
    ) {}
}
