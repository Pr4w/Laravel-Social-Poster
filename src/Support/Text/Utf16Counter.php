<?php

namespace SocialPoster\Support\Text;

/**
 * Counts UTF-16 code units, as JavaScript's String.length does. TikTok
 * measures its caption limits this way, so every character outside the Basic
 * Multilingual Plane (most emoji) counts as 2.
 */
final class Utf16Counter implements CaptionCounter
{
    public function count(string $text): int
    {
        $text = Text::scrub($text);

        return intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    public function describe(): string
    {
        return 'counted in UTF-16 code units, so most emoji count as 2';
    }
}
