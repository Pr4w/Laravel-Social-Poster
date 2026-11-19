<?php

namespace SocialPoster\Contracts;

use Illuminate\Support\MessageBag;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\PublishOutcome;

/**
 * The driver contract. It stays this small on purpose so third-party drivers
 * keep working across 1.x: every 1.1 addition is optional, either a separate
 * interface (SupportsFirstComment, the deprecated SupportsComments) or a
 * protected hook with a safe default on AbstractPlatform (requiredCredentials(),
 * identityKeys(), requiredScopes(), classifyError()...). Extending
 * AbstractPlatform is the recommended way to write a driver.
 */
interface SocialPlatform
{
    public function platform(): Platform;

    public function capabilities(): Capabilities;

    /**
     * Returns the validation errors (an empty bag means the content is valid).
     * Runs without credentials during form validation, so it must not require
     * them, and it must never throw.
     */
    public function validate(PreparedPost $post): MessageBag;

    /** Start (or, for synchronous platforms, complete) publishing. */
    public function publish(PreparedPost $post): PublishOutcome;

    /**
     * Continue an async publish after the platform has had time to process.
     *
     * @param  array<string, mixed>  $state  The state carried by the previous Pending outcome,
     *                                       plus the job-owned '_' keys (see Pending).
     */
    public function resume(PreparedPost $post, array $state): PublishOutcome;
}
