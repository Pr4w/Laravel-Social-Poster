<?php

namespace SocialPoster\Support\Text;

/**
 * Threads' 500 limit: "emojis are counted as the number of UTF-8 bytes".
 *
 * Works per grapheme. A grapheme holding an emoji component (a pictograph, a
 * regional indicator, U+FE0F, U+200D or U+20E3) counts as its UTF-8 byte
 * length, so a flag counts 8 and a thumbs-up with a skin tone 8. Any other
 * grapheme counts as its code points (not 1), which keeps the total at or
 * above mb_strlen(): a plain CaptionRules(max: 500) check stays a consistent,
 * cheaper pre-check for combining-mark scripts such as Devanagari.
 */
final class ThreadsCounter implements CaptionCounter
{
    public function count(string $text): int
    {
        $total = 0;

        foreach (Text::graphemes($text) as $grapheme) {
            $isEmoji = @preg_match('/'.Text::EMOJI_COMPONENT.'/u', $grapheme);

            $total += $isEmoji === 1 ? strlen($grapheme) : mb_strlen($grapheme, 'UTF-8');
        }

        return $total;
    }

    public function describe(): string
    {
        return 'Threads counts each emoji as its UTF-8 byte length (usually 4 to 8)';
    }
}
