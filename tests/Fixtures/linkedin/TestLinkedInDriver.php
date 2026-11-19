<?php

namespace SocialPoster\Tests\Fixtures\linkedin;

use SocialPoster\Platforms\LinkedIn\LinkedInDriver;

/**
 * LinkedInDriver with two test hooks: call() reaches protected methods, and
 * $partBudget limits how many upload batches one run may send (simulating a
 * spent upload_run_seconds budget without sleeping).
 */
class TestLinkedInDriver extends LinkedInDriver
{
    /** Batches the current run may still send; null = the real time budget. */
    public ?int $partBudget = null;

    public function call(string $method, mixed ...$args): mixed
    {
        return $this->{$method}(...$args);
    }

    protected function budgetLeft(float $startedAt): bool
    {
        if ($this->partBudget === null) {
            return parent::budgetLeft($startedAt);
        }

        return $this->partBudget-- > 0;
    }
}
