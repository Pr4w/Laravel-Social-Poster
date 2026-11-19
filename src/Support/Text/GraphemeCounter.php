<?php

namespace SocialPoster\Support\Text;

/**
 * Counts graphemes (user-perceived characters): a family emoji, a flag or an
 * accented letter written with a combining mark each count as 1. Bluesky
 * limits post text to 300 graphemes (pair it with CaptionRules::$maxBytes 3000).
 *
 * Uses ext-intl's grapheme_strlen() when available and PCRE's \X otherwise;
 * both follow the Unicode extended grapheme cluster rules.
 */
final class GraphemeCounter implements CaptionCounter
{
    public function count(string $text): int
    {
        return Text::graphemeCount($text);
    }

    public function describe(): string
    {
        return 'counted in graphemes, so an emoji or flag counts as 1';
    }
}
