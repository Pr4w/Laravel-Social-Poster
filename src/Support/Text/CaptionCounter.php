<?php

namespace SocialPoster\Support\Text;

/**
 * Measures text the way a platform does before it applies its length limit.
 * Platforms disagree: X weighs links and wide characters, Bluesky counts
 * graphemes, TikTok counts UTF-16 code units and Threads counts emoji by their
 * UTF-8 bytes. CaptionRules and CommentRules take one of these so the generic
 * validator reports the same number the platform would.
 *
 * Implementations must be stateless and never throw (invalid UTF-8 included).
 */
interface CaptionCounter
{
    /** The length of $text as the platform counts it. */
    public function count(string $text): int;

    /**
     * A short, human explanation of the counting rule, appended to validation
     * messages so a user understands why "280 characters" was exceeded.
     */
    public function describe(): string;
}
