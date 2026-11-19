<?php

namespace SocialPoster\Contracts;

use SocialPoster\Capabilities\CommentRules;
use SocialPoster\ValueObjects\CommentResult;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;

/**
 * First-comment contract (v2). Implemented by drivers that can add a comment,
 * or a reply, to a post they just published. ->withComment() takes effect only
 * on drivers implementing this (or the legacy SupportsComments); others report
 * CommentSkipped. The comment runs as its own step after the post is live, so a
 * comment failure never affects the post.
 *
 * Compared with SupportsComments, the driver receives the full post (options
 * included, e.g. a story flag) and the parent PostResult (payload included,
 * e.g. the Bluesky cid), declares its limits, delay and scopes up front, and
 * returns the comment's id, url and raw payload.
 */
interface SupportsFirstComment
{
    /**
     * The rules for a first comment on this post, or null when this variant
     * cannot take one (a story, a scheduled or draft post...). Called before
     * dispatch (with $parent = null, for validation) and again by the comment
     * job with the published parent.
     */
    public function commentRules(PreparedPost $post, ?PostResult $parent = null): ?CommentRules;

    /**
     * Add $text as the first comment on $parent. $context carries the full post,
     * its options and the (re-resolved) credentials. Throws the usual
     * TemporaryException / PermanentException on failure.
     *
     * Recovery: when an earlier attempt may have added the comment (its answer
     * was lost, a 5xx, or its worker died mid-call), the comment job passes
     * $parent with payload `_recovering` = true (Pending::RECOVERING; read it
     * with Pending::isRecovering($parent->payload)). Look the comment up first
     * and return it (payload recovered = true) instead of adding it again;
     * when the platform cannot be checked, throw
     * PermanentException(FailureReason::OutcomeUnknown) with context
     * possibly_published = true. A TemporaryException with context
     * possibly_published = false tells the job the attempt provably added
     * nothing.
     */
    public function addFirstComment(PreparedPost $context, PostResult $parent, string $text): CommentResult;
}
