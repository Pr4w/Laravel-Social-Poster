<?php

namespace SocialPoster\Support\Text;

/**
 * X's weighted length (twitter-text v3 parseTweet), checked against 280.
 *
 * 1. The text is NFC-normalised (with ext-intl), as X does before counting.
 * 2. Each link counts 23 whatever its length (t.co wrapping), bare domains included.
 * 3. Each emoji sequence counts 2 as one unit: ZWJ families, flags, keycaps,
 *    skin tones and variation selectors do not add up per code point.
 * 4. Every other code point counts 1 inside U+0000-10FF, U+2000-200D,
 *    U+2010-201F and U+2032-2037 (Latin, Greek, Cyrillic, Hebrew, Arabic,
 *    Devanagari, Thai, typographic punctuation), and 2 otherwise (CJK, ...).
 *
 * Weights are per code point, not per grapheme: a Devanagari or Thai cluster
 * with combining marks counts each mark, exactly as X does.
 */
final class XWeightedCounter implements CaptionCounter
{
    /** [first, last] code-point ranges that weigh 1; everything else weighs DEFAULT_WEIGHT. */
    private const RANGES = [
        [0x0000, 0x10FF],
        [0x2000, 0x200D],
        [0x2010, 0x201F],
        [0x2032, 0x2037],
    ];

    private const DEFAULT_WEIGHT = 2;

    public function __construct(
        private readonly int $urlLength = 23,
        private readonly int $emojiWeight = 2,
    ) {}

    public function count(string $text): int
    {
        $text = Text::nfc($text);
        $weight = 0;
        $cursor = 0;

        foreach (Urls::entities($text) as $entity) {
            $weight += $this->weighText(substr($text, $cursor, $entity['start'] - $cursor));
            $weight += $this->urlLength;
            $cursor = $entity['end'];
        }

        return $weight + $this->weighText(substr($text, $cursor));
    }

    public function describe(): string
    {
        return "X counts each link as {$this->urlLength} and each emoji or CJK character as 2";
    }

    /** Weigh link-free text: emoji sequences as units, then code points by range. */
    private function weighText(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        if (@preg_match_all('/'.Text::EMOJI_SEQUENCE.'/u', $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return $this->weighCodePoints($text);
        }

        $weight = 0;
        $cursor = 0;

        foreach ($matches[0] as [$emoji, $offset]) {
            $weight += $this->weighCodePoints(substr($text, $cursor, $offset - $cursor));
            $weight += $this->emojiWeight;
            $cursor = $offset + strlen($emoji);
        }

        return $weight + $this->weighCodePoints(substr($text, $cursor));
    }

    private function weighCodePoints(string $text): int
    {
        $weight = 0;

        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            $weight += $this->weightOf((int) mb_ord($char, 'UTF-8'));
        }

        return $weight;
    }

    private function weightOf(int $codePoint): int
    {
        foreach (self::RANGES as [$first, $last]) {
            if ($codePoint >= $first && $codePoint <= $last) {
                return 1;
            }
        }

        return self::DEFAULT_WEIGHT;
    }
}
