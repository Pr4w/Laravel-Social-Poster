<?php

namespace SocialPoster\Contracts;

use SocialPoster\ValueObjects\PreparedPost;

/**
 * Implemented by drivers that can add a comment to an already-published post.
 * The builder's ->withComment() only takes effect on platforms that implement
 * this; others are skipped. The comment runs as a separate step after the post
 * is confirmed live, so a comment failure never affects the post itself.
 */
interface SupportsComments
{
    /**
     * Add a comment to a published post. $context carries the platform and
     * credentials (its media/caption are irrelevant here). Returns the platform
     * comment id, or null if the platform does not return one. Throws the usual
     * TemporaryException / PermanentException on failure.
     */
    public function comment(PreparedPost $context, string $postId, string $comment): ?string;
}
