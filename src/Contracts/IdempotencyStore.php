<?php

namespace SocialPoster\Contracts;

use SocialPoster\ValueObjects\PostResult;

/**
 * Records whether a post's external side effect has already happened, keyed by a
 * stable per-post-per-platform key. This is what closes the gap the queue cannot:
 * the queue guarantees at-least-once delivery of the job, but a retry re-runs a
 * non-idempotent publish call, and no native queue feature can see that the post
 * was already created on the platform. The store remembers, so a retry of an
 * already-published post becomes a no-op replay instead of a duplicate.
 *
 * It also persists the in-flight async state so a retry resumes the existing
 * upload rather than starting a new one. The residual window it cannot close is
 * a crash between the platform call returning and markPublished writing, which is
 * irreducible without platform-side idempotency keys (which the social APIs do
 * not offer).
 */
interface IdempotencyStore
{
    /** A previously published result for this key, or null. */
    public function completed(string $key): ?PostResult;

    /** Record that this key has been published. Call before dispatching events. */
    public function markPublished(string $key, PostResult $result): void;

    /** The persisted in-flight async state for this key, or null. */
    public function pendingState(string $key): ?array;

    /** Persist the in-flight async state so a retry resumes instead of re-creating. */
    public function markPending(string $key, array $state): void;

    /** Clear the key (e.g. on permanent failure) so a fresh post can proceed. */
    public function forget(string $key): void;
}
