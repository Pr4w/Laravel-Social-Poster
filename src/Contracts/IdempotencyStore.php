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
 * upload rather than starting a new one.
 *
 * This interface only remembers outcomes after the fact. It cannot stop two
 * workers that run the same key at the same time, and it cannot see a create
 * call that timed out after the platform accepted it. Stores that implement
 * AtomicIdempotencyStore add a lease and a 'committing' status for those cases;
 * the first-party stores all do. Even then, a request that the platform accepted
 * but whose answer never came back can only be resolved by checking the
 * platform, which the drivers do when a run is flagged as recovering.
 *
 * Implementations should never delete or downgrade a 'published' record: a
 * published key must keep replaying, or the next retry posts again.
 */
interface IdempotencyStore
{
    /** A previously published result for this key, or null. */
    public function completed(string $key): ?PostResult;

    /** Record that this key has been published. Call before dispatching events. */
    public function markPublished(string $key, PostResult $result): void;

    /** The persisted in-flight async state for this key, or null. */
    public function pendingState(string $key): ?array;

    /** Persist the in-flight async state so a retry resumes instead of re-creating. Never overwrites a published record. */
    public function markPending(string $key, array $state): void;

    /** Clear the key (e.g. on permanent failure) so a fresh post can proceed. Published records are kept. */
    public function forget(string $key): void;
}
