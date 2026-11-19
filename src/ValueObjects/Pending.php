<?php

namespace SocialPoster\ValueObjects;

/**
 * The platform is still processing, or the publish has more steps to run. The
 * job re-dispatches a continuation carrying $state after $recheckAfter seconds,
 * which calls the driver's resume().
 *
 * Flags (all optional, so v1.0's `new Pending($state, 30)` keeps working):
 *
 * - $commits: the NEXT resume() performs a non-idempotent create (POST /feed,
 *   media_publish, /2/tweets, /rest/posts, createRecord...). The job records the
 *   commit before running it, so a run that may repeat it (crash, redelivery,
 *   ambiguous network failure) arrives with `_recovering` set. Build these with
 *   PublishOutcome::commit().
 * - $committed: the post, or an equivalent remote publish job (TikTok after
 *   init), already exists. Any later failure is reported with
 *   possiblyPublished = true, and running out of time is a ProcessingTimeout on
 *   a post that may still go live rather than a clean failure.
 * - $deadline: absolute unix time by which the driver expects completion. The
 *   job honours it before social.async.max_wait(_per_platform). Keep it in your
 *   state and return it on every Pending, since only $state crosses runs.
 * - $possiblySent: the run that returned this Pending may have created the
 *   post (its create's answer was lost), and the next step (a commit phase)
 *   verifies on the platform before creating again. The driver verifies in its
 *   own phase, so the job does not set `_recovering`; it uses the flag so that
 *   its own exits (deadline, publish window, exhausted tries, a failure the
 *   driver did not flag, a restart) report the post as possibly published and
 *   keep the record for reconcile instead of forgetting it.
 * - $reason: why the publish waits (a PostProcessing::REASON_* value), handed
 *   to the app as PostProcessing::$reason. Set it with withReason().
 *
 * Reserved state keys. Keys starting with '_' are written by the job only, on
 * every run; drivers read them and never set them (a value a driver carries
 * forward is overwritten):
 *
 * - `_recovering` (bool): this run re-executes a commit phase after an ambiguous
 *   outcome: the worker died or timed out, the queue redelivered the job
 *   (attempts() > 1), the store holds a 'committing' row, or a previous
 *   Temporary/connection failure may have sent the request. A driver in a commit
 *   phase MUST verify on the platform before re-creating when it is set (look
 *   the post up, read the container status...). If the platform cannot be
 *   queried (LinkedIn posts, TikTok init), throw PermanentException(OutcomeUnknown)
 *   with context possibly_published = true instead of creating a duplicate.
 * - `_committing_since` (int): unix time the commit phase was first entered.
 * - `_started_at` (int): unix time of the first publish() call; drives the
 *   wall-clock deadline and PostProcessing::$elapsedSeconds.
 * - `_attempt` (int): queue attempt (1-based) of the current run.
 * - `_restarts` (int): fresh publishes so far after a TemporaryException with
 *   context restart = true (the job drops the state and starts over).
 */
final class Pending extends PublishOutcome
{
    public const RECOVERING = '_recovering';

    public const COMMITTING_SINCE = '_committing_since';

    public const STARTED_AT = '_started_at';

    public const ATTEMPT = '_attempt';

    public const RESTARTS = '_restarts';

    /** Prefix of every job-owned state key. */
    public const RESERVED_PREFIX = '_';

    /**
     * @param  array<string, mixed>  $state  Serialisable continuation state (phase, container ids, ...).
     * @param  int  $recheckAfter  Seconds before resume() runs; the job clamps it.
     * @param  bool  $commits  The next resume() performs a non-idempotent create.
     * @param  bool  $committed  The post (or the platform's publish job) already exists.
     * @param  ?int  $deadline  Absolute unix time the driver expects completion by.
     * @param  bool  $possiblySent  The create of the run that returned this may have gone out; the next step verifies.
     * @param  ?string  $reason  Why the publish waits, for PostProcessing::$reason (its REASON_* values).
     */
    public function __construct(
        public readonly array $state,
        public readonly int $recheckAfter = 30,
        public readonly bool $commits = false,
        public readonly bool $committed = false,
        public readonly ?int $deadline = null,
        public readonly bool $possiblySent = false,
        public readonly ?string $reason = null,
    ) {}

    /**
     * The same outcome carrying a different state (the job uses it to inject
     * the reserved keys without losing the flags).
     *
     * @param  array<string, mixed>  $state
     */
    public function withState(array $state): self
    {
        return new self($state, $this->recheckAfter, $this->commits, $this->committed, $this->deadline, $this->possiblySent, $this->reason);
    }

    /** The same outcome with the reason the publish waits (PostProcessing::REASON_*). */
    public function withReason(?string $reason): self
    {
        return new self($this->state, $this->recheckAfter, $this->commits, $this->committed, $this->deadline, $this->possiblySent, $reason);
    }

    /** True for a job-owned state key ('_' prefix). */
    public static function isReservedKey(string $key): bool
    {
        return str_starts_with($key, self::RESERVED_PREFIX);
    }

    /**
     * The driver's own part of a state, without the job-owned '_' keys.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function withoutReservedKeys(array $state): array
    {
        return array_filter(
            $state,
            static fn ($key) => ! self::isReservedKey((string) $key),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Whether the job flagged this run as a possible repeat of a commit phase.
     *
     * @param  array<string, mixed>  $state
     */
    public static function isRecovering(array $state): bool
    {
        return (bool) ($state[self::RECOVERING] ?? false);
    }
}
