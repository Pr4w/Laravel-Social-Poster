<?php

namespace SocialPoster\Support\Text;

/**
 * Counts Unicode code points (mb_strlen). The v1.0 behaviour and the default
 * for platforms that document a plain character limit (Facebook, Instagram,
 * LinkedIn, YouTube descriptions).
 */
final class MbStrlenCounter implements CaptionCounter
{
    public function count(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }

    public function describe(): string
    {
        return 'counted in Unicode characters';
    }
}
