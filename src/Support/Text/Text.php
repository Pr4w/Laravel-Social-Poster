<?php

namespace SocialPoster\Support\Text;

use Normalizer;

/**
 * Shared Unicode helpers for the counters. Every function tolerates invalid
 * UTF-8 (it is scrubbed first) and degrades when ext-intl or a recent PCRE2 is
 * missing, so validation never throws because of the runtime build.
 *
 * @internal
 */
final class Text
{
    /**
     * Extended_Pictographic as explicit code-point ranges (Unicode 15 emoji-data).
     * Spelled out instead of \p{Extended_Pictographic} because that property
     * needs PCRE2 10.40+, and distro PHP builds linked to an older system PCRE2
     * would fail to compile the pattern. The Miscellaneous Symbols and Dingbats
     * blocks (U+2600-27BF) are listed piece by piece: plain symbols there such as
     * ✓ ☆ ❶ ➔ are not emoji and must count as one character on Threads.
     */
    public const PICTOGRAPHIC = '\x{00A9}\x{00AE}'.self::PICTOGRAPHIC_WIDE;

    /** Extended_Pictographic without © and ®, which default to text presentation. */
    public const PICTOGRAPHIC_WIDE = '\x{203C}\x{2049}\x{2122}\x{2139}\x{2194}-\x{2199}\x{21A9}\x{21AA}'
        .'\x{231A}\x{231B}\x{2328}\x{2388}\x{23CF}\x{23E9}-\x{23F3}\x{23F8}-\x{23FA}\x{24C2}\x{25AA}\x{25AB}'
        .'\x{25B6}\x{25C0}\x{25FB}-\x{25FE}\x{2600}-\x{2605}\x{2607}-\x{2612}\x{2614}-\x{2685}\x{2690}-\x{2705}'
        .'\x{2708}-\x{2712}\x{2714}\x{2716}\x{271D}\x{2721}\x{2728}\x{2733}\x{2734}\x{2744}\x{2747}\x{274C}\x{274E}'
        .'\x{2753}-\x{2755}\x{2757}\x{2763}-\x{2767}\x{2795}-\x{2797}\x{27A1}\x{27B0}\x{27BF}'
        .'\x{2934}\x{2935}\x{2B05}-\x{2B07}\x{2B1B}\x{2B1C}'
        .'\x{2B50}\x{2B55}\x{3030}\x{303D}\x{3297}\x{3299}\x{1F000}-\x{1F0FF}\x{1F10D}-\x{1F10F}\x{1F12F}'
        .'\x{1F16C}-\x{1F171}\x{1F17E}\x{1F17F}\x{1F18E}\x{1F191}-\x{1F19A}\x{1F1AD}-\x{1F1E5}\x{1F201}-\x{1F20F}'
        .'\x{1F21A}\x{1F22F}\x{1F232}-\x{1F23A}\x{1F23C}-\x{1F23F}\x{1F249}-\x{1F3FA}\x{1F400}-\x{1F53D}'
        .'\x{1F546}-\x{1F64F}\x{1F680}-\x{1F6FF}\x{1F774}-\x{1F77F}\x{1F7D5}-\x{1F7FF}\x{1F80C}-\x{1F80F}'
        .'\x{1F848}-\x{1F84F}\x{1F85A}-\x{1F85F}\x{1F888}-\x{1F88F}\x{1F8AE}-\x{1F8FF}\x{1F90C}-\x{1F93A}'
        .'\x{1F93C}-\x{1F945}\x{1F947}-\x{1FAFF}\x{1FC00}-\x{1FFFD}';

    /**
     * One emoji as a unit: a flag (regional-indicator pair), a keycap, a tag
     * sequence (England, Scotland, ...), or a pictograph with its variation
     * selector, skin-tone modifiers and ZWJ continuations. The text-default
     * characters © and ® only count as emoji with an explicit U+FE0F.
     */
    public const EMOJI_SEQUENCE = '(?:'
        .'[\x{1F1E6}-\x{1F1FF}]{2}'
        .'|[0-9#*]\x{FE0F}?\x{20E3}'
        .'|\x{1F3F4}[\x{E0020}-\x{E007E}]+\x{E007F}'
        .'|(?:[\x{00A9}\x{00AE}]\x{FE0F}|['.self::PICTOGRAPHIC_WIDE.'])'
        .'(?:\x{FE0F}|[\x{1F3FB}-\x{1F3FF}])*'
        .'(?:\x{200D}['.self::PICTOGRAPHIC.'](?:\x{FE0F}|[\x{1F3FB}-\x{1F3FF}])*)*'
        .')';

    /** Code points that make a grapheme an emoji for byte-counting platforms (Threads). */
    public const EMOJI_COMPONENT = '[\x{1F1E6}-\x{1F1FF}\x{20E3}\x{FE0F}\x{200D}'.self::PICTOGRAPHIC.']';

    /** Replace invalid UTF-8 byte sequences so counting functions never fail. */
    public static function scrub(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_scrub($text, 'UTF-8');
    }

    /** NFC-normalise when ext-intl is available; otherwise return the text unchanged. */
    public static function nfc(string $text): string
    {
        $text = self::scrub($text);

        if (! class_exists(Normalizer::class)) {
            return $text;
        }

        $normalized = Normalizer::normalize($text, Normalizer::FORM_C);

        return is_string($normalized) ? $normalized : $text;
    }

    /**
     * Split into extended grapheme clusters. Falls back to code points when PCRE
     * cannot segment the text, which only over-counts (never under-counts).
     *
     * @return string[]
     */
    public static function graphemes(string $text): array
    {
        $text = self::scrub($text);

        if ($text === '') {
            return [];
        }

        $parts = @preg_split('/(\X)/u', $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);

        return is_array($parts) ? $parts : mb_str_split($text, 1, 'UTF-8');
    }

    public static function graphemeCount(string $text): int
    {
        $text = self::scrub($text);

        if ($text === '') {
            return 0;
        }

        if (function_exists('grapheme_strlen')) {
            $count = @grapheme_strlen($text);

            if (is_int($count)) {
                return $count;
            }
        }

        $count = @preg_match_all('/\X/u', $text);

        return is_int($count) ? $count : mb_strlen($text, 'UTF-8');
    }
}
