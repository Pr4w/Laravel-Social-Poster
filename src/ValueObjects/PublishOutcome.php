<?php

namespace SocialPoster\ValueObjects;

/**
 * The result of one publish step. Either terminal (Published) or a request to
 * be resumed after the platform finishes async processing (Pending). Failures
 * are signalled by throwing Temporary/Permanent exceptions, not by an outcome.
 */
abstract class PublishOutcome
{
    public static function published(PostResult $result): Published
    {
        return new Published($result);
    }

    /**
     * @param  array<string, mixed>  $state  Serializable continuation state (phase, container ids, ...).
     * @param  bool  $committed  The post (or the platform's publish job) already exists.
     * @param  ?int  $deadline  Absolute unix time the driver expects completion by.
     */
    public static function pending(array $state, int $recheckAfter = 30, bool $committed = false, ?int $deadline = null): Pending
    {
        return new Pending($state, $recheckAfter, false, $committed, $deadline);
    }

    /**
     * Continue with a non-idempotent create on the next resume() (POST /feed,
     * media_publish, /2/tweets...). Running it as its own step lets the job mark
     * the commit first, so a repeat of that step arrives with `_recovering` set
     * and the driver verifies before creating again. Runs immediately by default.
     *
     * @param  array<string, mixed>  $state  Serializable continuation state; its phase should be the create.
     * @param  ?int  $deadline  Absolute unix time the driver expects completion by.
     * @param  bool  $possiblySent  This run's create may have gone out (its answer was lost) and the
     *                              step verifies before creating again (see Pending::$possiblySent).
     */
    public static function commit(array $state, int $recheckAfter = 0, ?int $deadline = null, bool $possiblySent = false): Pending
    {
        return new Pending($state, $recheckAfter, true, false, $deadline, $possiblySent);
    }
}
