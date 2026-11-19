<?php

namespace SocialPoster\Support\Text;

/**
 * Finds the links in post text with twitter-text's rules, so every feature that
 * cares about links agrees on what a link is: X's weighted count (each link is
 * 23), the Threads 5-link limit, Facebook/LinkedIn link cards, Pinterest's
 * first link and Bluesky link facets.
 *
 * A link is an http(s):// URL, or a bare "domain.tld" / "www.domain.tld" whose
 * TLD is a real one (see Tlds). As in twitter-text:
 * - only an ASCII letter or digit, @, $ or # may not precede a link, so a link
 *   glued to CJK text ("詳しくはこちらhttps://...", "これはexample.com") is found;
 * - the host ends at the longest known TLD that is not followed by an ASCII
 *   letter, digit, @, + or -, so "https://example.comです" and
 *   "Visit https://example.com.Thanks" link only "https://example.com";
 * - trailing punctuation (. , ; : ! ? ' ") is not part of a link, and neither
 *   is a closing parenthesis that has no opening partner inside the URL, so
 *   "(see https://en.wikipedia.org/wiki/Foo_(bar))" yields ".../Foo_(bar)".
 * Email addresses, @mentions and $cashtags are not links.
 *
 * Tlds is not the whole root zone, so a URL written with a scheme keeps a host
 * whose last label is an unknown TLD when a port or path follows it, or when
 * no earlier label is a known TLD ("https://shop.example.boston/x").
 *
 * The scan runs in linear time: hosts are matched possessively (labels of at
 * most 63 characters, never given back) and the TLD is chosen afterwards in
 * PHP, so hostile captions ("a.a.a.a..." for 60 KB) cannot make validation
 * backtrack for minutes.
 */
final class Urls
{
    /** Characters that may not directly follow a TLD (twitter-text validGTLD lookahead). */
    private const TLD_FOLLOWERS = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ@+-';

    private const PATTERN = '~'
        .'(?<![A-Za-z0-9@\x{FF20}$\#\x{FF03}])'
        .'(?:'
        .'(?<scheme>https?://)(?<host>[\p{L}\p{N}_-]{1,63}+(?:\.[\p{L}\p{N}_-]{1,63}+)++)'
        .'|(?<bare>[a-z0-9_-]{1,63}+(?:\.(?:[a-z0-9_-]{1,63}+|(?:(?![\x00-\x7F])\p{L}){1,63}+))++)'
        .')'
        .'(?<port>:[0-9]{1,5}+)?'
        .'(?<rest>[/?][\p{Latin}\p{Cyrillic}\p{N}!*\';:=+,.$/%\#\[\]\-\x{2013}_\~@|&()?]*+)?'
        .'~iu';

    /**
     * The links in $text, in order and as written (duplicates kept; a bare
     * domain stays scheme-less: use absolute() before fetching it).
     *
     * @param  bool  $schemeOnly  Only return links written with http:// or https://.
     * @return string[]
     */
    public static function extract(string $text, bool $schemeOnly = false): array
    {
        return array_column(self::entities($text, $schemeOnly), 'url');
    }

    /**
     * The links in $text with their BYTE offsets in the UTF-8 string, as
     * Bluesky facets need them ($end is exclusive).
     *
     * @return list<array{url: string, start: int, end: int, scheme: bool}>
     */
    public static function entities(string $text, bool $schemeOnly = false): array
    {
        $text = Text::scrub($text);

        if ($text === '' || ! str_contains($text, '.')) {
            return [];
        }

        if (@preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) === false) {
            return [];
        }

        $entities = [];

        foreach ($matches as $match) {
            $start = (int) $match[0][1];
            $scheme = (string) ($match['scheme'][0] ?? '');

            if ($scheme === '' && $schemeOnly) {
                continue;
            }

            $run = (string) ($scheme !== '' ? $match['host'][0] : $match['bare'][0]);
            $port = (string) ($match['port'][0] ?? '');
            $rest = (string) ($match['rest'][0] ?? '');
            $next = self::charAt($text, $start + strlen($scheme) + strlen($run));
            $domain = self::domain($run, $next, $scheme !== '', $port.$rest !== '');

            if ($domain === null || ($scheme === '' && ! self::bareDomainIsLink($text, $start))) {
                continue;
            }

            // A port or path belongs to the link only when the host ends where the match did.
            $url = $scheme.$domain.($domain === $run ? $port.self::trimTail($rest) : '');

            $entities[] = [
                'url' => $url,
                'start' => $start,
                'end' => $start + strlen($url),
                'scheme' => $scheme !== '',
            ];
        }

        return $entities;
    }

    /** The first link in $text, or null. */
    public static function first(string $text, bool $schemeOnly = false): ?string
    {
        return self::extract($text, $schemeOnly)[0] ?? null;
    }

    /** A fetchable form of an extracted link: https:// is added to a bare domain. */
    public static function absolute(string $url): string
    {
        return preg_match('~^https?://~i', $url) === 1 ? $url : 'https://'.$url;
    }

    /**
     * Distinct links, compared case-insensitively in their absolute form, as
     * platforms that cap "unique links" (Threads) count them.
     *
     * @return string[]
     */
    public static function unique(string $text): array
    {
        $unique = [];

        foreach (self::extract($text) as $url) {
            $unique[strtolower(rtrim(self::absolute($url), '/'))] ??= $url;
        }

        return array_values($unique);
    }

    /**
     * The link's host inside a run of host characters ("example.com.Thanks",
     * "twitter.みんなです"): the longest prefix that ends in a known TLD not
     * followed by [0-9a-z@+-], as twitter-text picks it. A scheme URL falls back
     * to its last letters-only label (Tlds is a subset of the root zone), unless
     * nothing follows the host and an earlier label is a known TLD: that is
     * text glued after the link. Null when the run holds no link.
     */
    private static function domain(string $run, string $next, bool $scheme, bool $hasTail): ?string
    {
        $labels = explode('.', $run);
        $last = count($labels) - 1;
        $subdomains = self::subdomainPrefix($labels, $scheme);
        $name = $scheme ? '/^[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?$/u' : '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i';
        $known = null;
        $fallback = null;

        for ($i = $last; $i >= 1; $i--) {
            // Label $i can end the host only after a domain name preceded by subdomains.
            if ($i - 1 > $subdomains || preg_match($name, $labels[$i - 1]) !== 1) {
                continue;
            }

            $after = $i < $last ? '.' : $next;

            foreach (self::tldCandidates($labels[$i], $after) as $tld) {
                if (Tlds::isKnown($tld)) {
                    $known = [$i, $tld];
                    break;
                }
            }

            if ($scheme && $fallback === null && self::tldShaped($labels[$i])
                && preg_match('/^[\p{L}\p{N}@+-]/u', $after) !== 1) {
                $fallback = $i;
            }

            if ($known !== null) {
                break;
            }
        }

        if ($known !== null && ($fallback === null || $known[0] >= $fallback || ! $hasTail)) {
            return implode('.', array_slice($labels, 0, $known[0])).'.'.$known[1];
        }

        return $fallback !== null ? implode('.', array_slice($labels, 0, $fallback + 1)) : null;
    }

    /**
     * How many leading labels are valid subdomain labels (no leading or
     * trailing "-"; ASCII only without a scheme).
     *
     * @param  string[]  $labels
     */
    private static function subdomainPrefix(array $labels, bool $scheme): int
    {
        $pattern = $scheme ? '/^[\p{L}\p{N}_](?:[\p{L}\p{N}_-]*[\p{L}\p{N}_])?$/u' : '/^[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?$/i';

        foreach ($labels as $index => $label) {
            if (preg_match($pattern, $label) !== 1) {
                return $index;
            }
        }

        return count($labels);
    }

    /**
     * The TLDs label $label may end with, longest first: the label itself, the
     * ASCII letters it starts with, or a prefix of the non-ASCII letters it
     * starts with ("みんなです" → "みんなです", "みんなで", "みんな", ...), each
     * only when the character after it may follow a TLD.
     *
     * @return string[]
     */
    private static function tldCandidates(string $label, string $after): array
    {
        if (preg_match('/^(?:xn--[a-z0-9-]+|[a-z]+)$/i', $label) === 1) {
            return self::mayFollowTld($after) ? [$label] : [];
        }

        if (preg_match('/^[a-z]+/i', $label, $ascii) === 1) {
            return self::mayFollowTld(self::charAt($label, strlen($ascii[0]))) ? [$ascii[0]] : [];
        }

        if (preg_match('/^(?:(?![\x00-\x7F])\p{L})+/u', $label, $letters) !== 1) {
            return [];
        }

        $candidates = [];
        $length = mb_strlen($letters[0], 'UTF-8');

        for ($n = min($length, 63); $n >= 2; $n--) {
            $prefix = mb_substr($letters[0], 0, $n, 'UTF-8');
            $following = $prefix === $label ? $after : self::charAt($label, strlen($prefix));

            if (self::mayFollowTld($following)) {
                $candidates[] = $prefix;
            }
        }

        return $candidates;
    }

    /** A label the v1 pattern accepted as a TLD: 2-63 letters of any script, or punycode. */
    private static function tldShaped(string $label): bool
    {
        return preg_match('/^(?:xn--[a-z0-9-]+|\p{L}{2,63})$/iu', $label) === 1;
    }

    private static function mayFollowTld(string $char): bool
    {
        return $char === '' || strlen($char) > 1 || ! str_contains(self::TLD_FOLLOWERS, $char);
    }

    /** The UTF-8 character at byte $offset, or '' at the end of $text. */
    private static function charAt(string $text, int $offset): string
    {
        if ($offset >= strlen($text)) {
            return '';
        }

        $byte = ord($text[$offset]);
        $length = match (true) {
            $byte >= 0xF0 => 4,
            $byte >= 0xE0 => 3,
            $byte >= 0xC0 => 2,
            default => 1,
        };

        return substr($text, $offset, $length);
    }

    private static function bareDomainIsLink(string $text, int $start): bool
    {
        // twitter-text: no bare domain right after "-", "_", "." or "/" (paths, file names).
        return $start === 0 || ! str_contains('-_./', $text[$start - 1]);
    }

    /**
     * Drop trailing characters a link cannot end with. A ")" stays only when
     * it closes a "(" inside the link (Wikipedia-style URLs). Linear: the end
     * is found by walking back over UTF-8 lead bytes, never by re-scanning.
     */
    private static function trimTail(string $rest): string
    {
        $end = strlen($rest);
        $open = substr_count($rest, '(');
        $close = substr_count($rest, ')');

        while ($end > 0) {
            $start = $end - 1;

            while ($start > 0 && (ord($rest[$start]) & 0xC0) === 0x80) {
                $start--;
            }

            $char = substr($rest, $start, $end - $start);

            if ($char === ')') {
                if ($open >= $close) {
                    break;
                }

                $close--;
            } elseif (preg_match('/^[\p{L}\p{N}\/=_#+&-]$/u', $char) === 1) {
                break;
            } elseif ($char === '(') {
                $open--;
            }

            $end = $start;
        }

        return substr($rest, 0, $end);
    }
}
