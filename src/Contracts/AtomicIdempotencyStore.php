<?php

namespace SocialPoster\Contracts;

use SocialPoster\Enums\Platform;
use SocialPoster\Idempotency\KeyCollisionException;

/**
 * An idempotency store that can also serialise the runs of one key. The base
 * IdempotencyStore only remembers outcomes after the fact, so two workers that
 * pick up the same publish (a redelivery after retry_after, a scheduler
 * double-dispatch, a crashed job retried while its continuation is queued) both
 * read "nothing recorded" and both post. This contract adds the missing pieces:
 *
 * - a lease (claim/release) so only one owner runs a key at a time, with expiry
 *   so a worker that died does not block the key forever;
 * - a 'committing' status written before a non-idempotent create is re-run, so
 *   the next run knows the post may already exist and verifies before creating;
 * - an account binding, so a key reused for a second account is reported as a
 *   collision instead of replaying the first account's post id.
 *
 * It extends IdempotencyStore, so custom v1.0 stores keep working unchanged; the
 * job uses the extra protocol only when the bound store implements this.
 *
 * Statuses: pending (in flight, state = resumable async state), committing (a
 * create may have been sent; state = what to verify against), published (done;
 * the result replays), failed (terminal and possibly live; kept for reconcile).
 *
 * The job's order of calls for one run:
 *
 *   bindAccount($key, $platform, $accountKey)   // throws KeyCollisionException
 *   completed($key)                             // replay and stop
 *   claim($key, $owner, $lease)                 // false: status() says why
 *   committing($key) ?? pendingState($key)      // resume (with _recovering)
 *   markCommitting($key, $state)                // before a commit-phase resume
 *   markPending | markPublished | markFailed | forget
 *   release($key, $owner)                       // when the chain stops early
 *
 * Every write is idempotent and none of them ever turns a 'published' record
 * back into anything else; forget() keeps published records too.
 *
 * Optional: a public record(string $key): ?array returning ['status', 'state',
 * 'platform', 'account', 'since', 'updated_at'] (the first-party stores have
 * it). Poster::reconcile() uses it when present to resume a 'failed' record
 * from the state markFailed() kept; without it such records reconcile as
 * unrecoverable.
 */
interface AtomicIdempotencyStore extends IdempotencyStore
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMMITTING = 'committing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_COMMITTING,
        self::STATUS_PUBLISHED,
        self::STATUS_FAILED,
    ];

    /**
     * Take (or renew) the lease on $key for $owner. True when $owner now holds
     * it: the key was free, its lease expired, or $owner already held it (a
     * continuation of the same chain renews). False when another owner holds a
     * live lease, or the record is 'published' or 'failed' (see status()).
     * Pass a lease longer than one run of the job (its timeout plus a margin).
     */
    public function claim(string $key, string $owner, int $leaseSeconds): bool;

    /** Give up $owner's lease on $key. A no-op when someone else holds it. */
    public function release(string $key, string $owner): void;

    /**
     * Record that the next run performs (or re-performs) a non-idempotent
     * create with $state. Moves pending/failed records to 'committing' and
     * keeps the original 'since' when the record is already committing, and
     * when a failed record that was committing is reopened (reconcile): the
     * time the first create may have gone out is where a driver starts
     * looking for it. Only a record coming from 'pending' (or a new one)
     * starts a new clock. Never touches a published record.
     *
     * @param  array<string, mixed>  $state
     */
    public function markCommitting(string $key, array $state): void;

    /**
     * The state saved by markCommitting() and the unix time the record first
     * became 'committing', or null when the record is not committing. A run
     * that finds one must verify on the platform before creating again.
     *
     * @return array{state: array<string, mixed>, since: int}|null
     */
    public function committing(string $key): ?array;

    /**
     * Tie $key to one platform account (the driver's accountKey()). The first
     * call records it; later calls with the same account are no-ops. Throws
     * when the key is already bound to a different account or platform, so a
     * key reused across accounts never replays another account's post. Records
     * written before 1.1 (no account) are bound on first use.
     *
     * @throws KeyCollisionException
     */
    public function bindAccount(string $key, Platform|string $platform, string $account): void;

    /**
     * Record a terminal failure that must not be retried blindly (the post may
     * be live). Keeps the row for reconcile instead of forgetting it, clears the
     * lease, keeps the stored state when $state is null, and keeps the
     * committing time (see markCommitting()). claim() refuses a
     * failed record until markCommitting()/markPending() reopens it or forget()
     * clears it. Never touches a published record.
     *
     * @param  array<string, mixed>|null  $state
     */
    public function markFailed(string $key, ?array $state = null): void;

    /** The record's status (one of STATUSES), or null when nothing is recorded. */
    public function status(string $key): ?string;
}
