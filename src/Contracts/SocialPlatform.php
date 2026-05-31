<?php

namespace SocialPoster\Contracts;

use Illuminate\Support\MessageBag;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\PublishOutcome;

interface SocialPlatform
{
    public function platform(): Platform;

    public function capabilities(): Capabilities;

    /** Returns the validation errors (an empty bag means the content is valid). */
    public function validate(PreparedPost $post): MessageBag;

    /** Start (or, for synchronous platforms, complete) publishing. */
    public function publish(PreparedPost $post): PublishOutcome;

    /**
     * Continue an async publish after the platform has had time to process.
     *
     * @param array<string, mixed> $state The state carried by the previous Pending outcome.
     */
    public function resume(PreparedPost $post, array $state): PublishOutcome;
}
