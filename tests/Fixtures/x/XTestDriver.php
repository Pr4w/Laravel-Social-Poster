<?php

namespace SocialPoster\Tests\Fixtures\x;

use SocialPoster\Platforms\X\XDriver;

/**
 * XDriver with its protected helpers reachable from tests, and a run budget
 * the test controls: $budgetChecks = N lets N extra segments through per run
 * after the first one (null keeps the real clock-based budget).
 */
class XTestDriver extends XDriver
{
    public ?int $budgetChecks = null;

    public function call(string $method, mixed ...$args): mixed
    {
        return $this->{$method}(...$args);
    }

    protected function budgetLeft(float $startedAt): bool
    {
        if ($this->budgetChecks === null) {
            return parent::budgetLeft($startedAt);
        }

        return $this->budgetChecks-- > 0;
    }
}
