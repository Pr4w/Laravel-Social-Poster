<?php

namespace SocialPoster\Capabilities;

use SocialPoster\Support\Text\CaptionCounter;
use SocialPoster\Support\Text\MbStrlenCounter;

/**
 * Caption length limits, measured the way the platform measures them.
 *
 * $counter decides what "a character" is (code points by default, X's weighted
 * count, graphemes for Bluesky, UTF-16 for TikTok, emoji bytes for Threads), and
 * validation messages name the counting rule. $maxBytes adds a UTF-8 byte cap
 * on top (Bluesky: 300 graphemes AND 3000 bytes). Text is trimmed before
 * counting.
 */
final class CaptionRules
{
    public readonly CaptionCounter $counter;

    public function __construct(
        public readonly int $min = 0,
        public readonly int $max = 2200,
        ?CaptionCounter $counter = null,
        public readonly ?int $maxBytes = null,
    ) {
        $this->counter = $counter ?? new MbStrlenCounter;
    }

    /** The length of $text as this platform counts it (after trimming). */
    public function length(string $text): int
    {
        return $this->counter->count(trim($text));
    }

    /** True when $text fits the character and byte limits (the minimum is not checked). */
    public function fits(string $text): bool
    {
        $text = trim($text);

        return $this->counter->count($text) <= $this->max
            && ($this->maxBytes === null || strlen($text) <= $this->maxBytes);
    }
}
