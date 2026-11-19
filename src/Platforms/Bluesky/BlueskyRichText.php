<?php

namespace SocialPoster\Platforms\Bluesky;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SocialPoster\Support\Text\Text;
use SocialPoster\Support\Text\Urls;
use Throwable;

/**
 * Bluesky rich text. The API never detects links, hashtags or mentions: without
 * app.bsky.richtext.facet entries they render as plain text. A facet marks a
 * UTF-8 BYTE range of the exact text sent (preg OFFSET_CAPTURE offsets are byte
 * offsets) and says what it is:
 *
 * - links: found with the shared Urls rules (trailing punctuation and an
 *   unbalanced ')' left out; bare domains with a real TLD become https://),
 *   with the path run on through characters those rules stop at (paths in
 *   other scripts, { } ^ `: see extend()), plus any scheme URL those rules do
 *   not recognise (IP hosts);
 * - #hashtags: not inside a word, trailing punctuation left out, not only
 *   digits, at most 64 graphemes and 640 bytes; the range starts at the '#'
 *   (half-width or full-width ＃);
 * - @mentions: @handle.domain resolved to a DID through the public AppView
 *   (com.atproto.identity.resolveHandle), all handles at once, cached for a
 *   day. An unresolved handle stays plain text and never fails the post.
 *
 * Facets are sorted by start and overlapping ones dropped (a '#https://…' tag
 * inside a link). With shortenLinks, each link is shown as "host/path..." like
 * the Bluesky app while its facet keeps the full URL; every offset is computed
 * on the shortened text, which is also what the length limits apply to.
 */
class BlueskyRichText
{
    public const LINK = 'app.bsky.richtext.facet#link';

    public const TAG = 'app.bsky.richtext.facet#tag';

    public const MENTION = 'app.bsky.richtext.facet#mention';

    private const MENTION_PATTERN = '/(?<![^\s(])@((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]*[a-z0-9])/iu';

    private const TAG_PATTERN = '/(?<!\S)([#＃])([^\s#＃]+)/u';

    /**
     * A character a link written in text can contain: not whitespace, < > ",
     * nor CJK or full-width punctuation (、。「」〜…, ！（），．：？…), which ends
     * the link in Chinese and Japanese text. Letters such as 々 are kept.
     */
    private const RUN = '[^\s<>"\x{3000}-\x{3004}\x{3008}-\x{3020}\x{3030}\x{3036}\x{3037}\x{303D}-\x{303F}\x{FF01}-\x{FF0F}\x{FF1A}-\x{FF20}\x{FF3B}-\x{FF40}\x{FF5B}-\x{FF65}]';

    /** Scheme URLs, for the hosts Urls does not treat as links (IP addresses, single-label TLDs). */
    private const SCHEME_URL_PATTERN = '~(?<![\w/@.:])https?://'.self::RUN.'+~iu';

    private const HANDLE_CACHE_PREFIX = 'social-poster:bsky-handle:';

    /** Cached marker for a handle the AppView does not know. */
    private const UNRESOLVED = '-';

    public function __construct(
        protected string $appview = 'https://public.api.bsky.app',
        protected int $timeout = 5,
        protected int $cacheSeconds = 86400,
        protected int $missCacheSeconds = 600,
    ) {}

    /**
     * The text to send and its facets.
     *
     * @param  bool  $rawFacets  Append BlueskyOptions::$facets (posts only, not comments).
     * @param  bool|null  $shorten  Override BlueskyOptions::$shortenLinks.
     * @return array{text: string, facets: list<array<string, mixed>>}
     */
    public function build(string $text, ?BlueskyOptions $options = null, bool $rawFacets = true, ?bool $shorten = null): array
    {
        $text = Text::scrub($text);
        $detect = $options === null || $options->detectFacets;
        $shorten ??= $options !== null && $options->shortenLinks;

        $links = $detect || $shorten ? self::links($text) : [];
        [$text, $links] = $shorten ? self::shorten($text, $links) : [$text, $links];

        $facets = array_map(
            static fn (array $link) => self::facet($link['start'], $link['end'], ['$type' => self::LINK, 'uri' => $link['uri']]),
            $links,
        );

        if ($detect) {
            // A link wins over a tag or mention written into it ('#https://…').
            foreach (array_merge(self::tagFacets($text), $this->mentionFacets($text, $options?->mentionMap() ?? [])) as $facet) {
                if (! self::overlaps($links, $facet['index']['byteStart'], $facet['index']['byteEnd'])) {
                    $facets[] = $facet;
                }
            }
        }

        if ($rawFacets && $options !== null) {
            foreach ($options->facets as $facet) {
                if (is_array($facet)) {
                    $facets[] = $facet;
                }
            }
        }

        return ['text' => $text, 'facets' => self::normalise($facets, strlen($text))];
    }

    /** The text as it will be sent (shortened links when $shorten), for length checks. */
    public static function displayText(string $text, bool $shorten = false): string
    {
        $text = Text::scrub($text);

        return $shorten ? self::shorten($text, self::links($text))[0] : $text;
    }

    /**
     * The links in $text with byte offsets, in order.
     *
     * @return list<array{start: int, end: int, uri: string, scheme: bool}>
     */
    public static function links(string $text): array
    {
        $text = Text::scrub($text);
        $links = [];

        foreach (Urls::entities($text) as $entity) {
            $url = self::extend($text, $entity['url'], $entity['end']);

            $links[] = [
                'start' => $entity['start'],
                'end' => $entity['start'] + strlen($url),
                'uri' => $entity['scheme'] ? $url : Urls::absolute($url),
                'scheme' => $entity['scheme'],
            ];
        }

        if (@preg_match_all(self::SCHEME_URL_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$match, $start]) {
                $url = self::trimUrl($match);
                $end = $start + strlen($url);

                if (! is_string(parse_url($url, PHP_URL_HOST)) || self::overlaps($links, $start, $end)) {
                    continue;
                }

                $links[] = ['start' => $start, 'end' => $end, 'uri' => $url, 'scheme' => true];
            }
        }

        usort($links, static fn (array $a, array $b) => $a['start'] <=> $b['start']);

        return $links;
    }

    /**
     * The Bluesky app's short form of a link: host plus path, query and
     * fragment, the path part cut to 13 characters and '...' past 15.
     */
    public static function shortUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return $url;
        }

        $host = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = (($parts['path'] ?? '/') === '/' ? '' : $parts['path'])
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');

        return mb_strlen($path, 'UTF-8') > 15 ? $host.mb_substr($path, 0, 13, 'UTF-8').'...' : $host.$path;
    }

    /**
     * #hashtag facets (the range covers the '#').
     *
     * @return list<array<string, mixed>>
     */
    public static function tagFacets(string $text): array
    {
        if (@preg_match_all(self::TAG_PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }

        $facets = [];

        foreach ($matches as $match) {
            $start = (int) $match[0][1];
            $tag = (string) preg_replace('/\p{P}+$/u', '', (string) $match[2][0]);

            if (! self::isTag($tag)) {
                continue;
            }

            $end = $start + strlen((string) $match[1][0]) + strlen($tag);
            $facets[] = self::facet($start, $end, ['$type' => self::TAG, 'tag' => $tag]);
        }

        return $facets;
    }

    /**
     * Whether $tag (without its '#') can be a Bluesky hashtag: not empty, not
     * only digits or punctuation, not a keycap emoji, 64 graphemes / 640 bytes
     * at most.
     */
    public static function isTag(string $tag): bool
    {
        return $tag !== ''
            && ! str_starts_with($tag, "\u{FE0F}")
            && preg_match('/[^\d\p{P}\p{M}\x{FE0F}\x{20E3}]/u', $tag) === 1
            && Text::graphemeCount($tag) <= 64
            && strlen($tag) <= 640;
    }

    /**
     * @mention facets for the handles that resolve to a DID.
     *
     * @param  array<string, string>  $known  handle => did, skips the lookup.
     * @return list<array<string, mixed>>
     */
    public function mentionFacets(string $text, array $known = []): array
    {
        if (! str_contains($text, '@') || @preg_match_all(self::MENTION_PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }

        $handles = array_values(array_unique(array_map(static fn (array $m) => strtolower((string) $m[1][0]), $matches)));
        $dids = $this->resolveHandles($handles, $known);
        $facets = [];

        foreach ($matches as $match) {
            $did = $dids[strtolower((string) $match[1][0])] ?? null;

            if ($did !== null) {
                $start = (int) $match[0][1];
                $facets[] = self::facet($start, $start + strlen((string) $match[0][0]), ['$type' => self::MENTION, 'did' => $did]);
            }
        }

        return $facets;
    }

    /**
     * handle => DID for every handle that resolves (known map, cache, then the
     * AppView, all lookups concurrently). Failures leave the handle out.
     *
     * @param  list<string>  $handles  Lowercase, without '@'.
     * @param  array<string, string>  $known
     * @return array<string, string>
     */
    public function resolveHandles(array $handles, array $known = []): array
    {
        $resolved = [];
        $pending = [];

        foreach ($handles as $handle) {
            if (isset($known[$handle])) {
                $resolved[$handle] = $known[$handle];

                continue;
            }

            $cached = $this->cacheGet(self::HANDLE_CACHE_PREFIX.$handle);

            if (is_string($cached) && str_starts_with($cached, 'did:')) {
                $resolved[$handle] = $cached;
            } elseif ($cached !== self::UNRESOLVED) {
                $pending[] = $handle;
            }
        }

        if ($pending === []) {
            return $resolved;
        }

        $url = rtrim($this->appview, '/').'/xrpc/com.atproto.identity.resolveHandle';

        try {
            $responses = Http::pool(function (Pool $pool) use ($pending, $url) {
                foreach ($pending as $handle) {
                    $pool->as($handle)->acceptJson()->connectTimeout(min(3, $this->timeout))->timeout($this->timeout)->get($url, ['handle' => $handle]);
                }
            }, 5);
        } catch (Throwable) {
            return $resolved; // mentions are best effort
        }

        foreach ($pending as $handle) {
            $response = $responses[$handle] ?? null;

            if (! $response instanceof Response) {
                continue;
            }

            $did = $response->successful() ? $response->json('did') : null;

            if (is_string($did) && str_starts_with($did, 'did:')) {
                $resolved[$handle] = $did;
                $this->cachePut(self::HANDLE_CACHE_PREFIX.$handle, $did, $this->cacheSeconds);
            } elseif ($response->status() === 400 || $response->status() === 404) {
                // "Unable to resolve handle": remember briefly, a new account may claim it.
                $this->cachePut(self::HANDLE_CACHE_PREFIX.$handle, self::UNRESOLVED, $this->missCacheSeconds);
            }
        }

        return $resolved;
    }

    /**
     * Keep well-formed facets inside the text, sorted by start, dropping any
     * that overlaps an earlier (or, at the same start, longer) one.
     *
     * @param  array<int, mixed>  $facets
     * @return list<array<string, mixed>>
     */
    public static function normalise(array $facets, int $textBytes): array
    {
        $valid = [];

        foreach ($facets as $facet) {
            $start = is_array($facet) ? ($facet['index']['byteStart'] ?? null) : null;
            $end = is_array($facet) ? ($facet['index']['byteEnd'] ?? null) : null;
            $features = is_array($facet) ? ($facet['features'] ?? null) : null;

            if (is_int($start) && is_int($end) && $start >= 0 && $end > $start && $end <= $textBytes && is_array($features) && $features !== []) {
                $valid[] = ['index' => ['byteStart' => $start, 'byteEnd' => $end], 'features' => array_values($features)];
            }
        }

        usort($valid, static fn (array $a, array $b) => [$a['index']['byteStart'], -$a['index']['byteEnd']] <=> [$b['index']['byteStart'], -$b['index']['byteEnd']]);

        $kept = [];
        $lastEnd = 0;

        foreach ($valid as $facet) {
            if ($facet['index']['byteStart'] >= $lastEnd) {
                $kept[] = $facet;
                $lastEnd = $facet['index']['byteEnd'];
            }
        }

        return $kept;
    }

    // --- Internals ----------------------------------------------------------------

    /**
     * @param  list<array{start: int, end: int, uri: string, scheme: bool}>  $links
     * @return array{0: string, 1: list<array{start: int, end: int, uri: string, scheme: bool}>}
     */
    private static function shorten(string $text, array $links): array
    {
        $out = '';
        $cursor = 0;
        $shortened = [];

        foreach ($links as $link) {
            $out .= substr($text, $cursor, $link['start'] - $cursor);
            $display = self::shortUrl($link['uri']);
            $start = strlen($out);
            $out .= $display;
            $shortened[] = ['start' => $start, 'end' => strlen($out), 'uri' => $link['uri'], 'scheme' => $link['scheme']];
            $cursor = $link['end'];
        }

        return [$out.substr($text, $cursor), $shortened];
    }

    /**
     * The rest of a link that the Urls (twitter-text) rules stop short of. Their
     * path characters are Latin, Cyrillic, digits and some ASCII symbols, while
     * the Bluesky app links the whole non-space run: "…/wiki/東京", "?q=서울",
     * "/a{b}c". The run after $url is taken (trailing punctuation trimmed) when:
     * - $url has a path or query, so the host, which Urls ends at a known TLD,
     *   is never extended ("https://example.comです", "example.com.Thanks");
     * - and either $url ends on a delimiter ("/", "=", "-"…: a new segment or
     *   value starts) or the run starts with an ASCII symbol Urls leaves out
     *   ({ } ^ ` \), possibly after punctuation. A letter or digit glued to
     *   other-script text ("/news/123をご覧ください") ends the link there, as
     *   Japanese and Chinese posts write links without a space after them.
     *
     * The run stops at whitespace, < > " and CJK or full-width punctuation
     * (。、「」（）…), which URLs written in text do not contain.
     */
    private static function extend(string $text, string $url, int $end): string
    {
        if (strpbrk((string) preg_replace('~^https?://~i', '', $url), '/?') === false
            || preg_match('~\G'.self::RUN.'+~u', $text, $match, 0, $end) !== 1) {
            return $url;
        }

        $extra = substr(self::trimUrl($url.$match[0]), strlen($url));

        if ($extra === '' || (preg_match('/[^\p{L}\p{N})\]]$/u', $url) !== 1 && preg_match('/^[.,;:!?\'()\[\]]*[{}^`\\\\]/', $extra) !== 1)) {
            return $url;
        }

        return $url.$extra;
    }

    /** Trailing sentence punctuation is not part of a link; ')' only when it closes a '(' inside it. */
    private static function trimUrl(string $url): string
    {
        do {
            $before = $url;
            $url = rtrim($url, '.,;:!?\'"');

            if (str_ends_with($url, ')') && substr_count($url, '(') < substr_count($url, ')')) {
                $url = substr($url, 0, -1);
            }
        } while ($url !== $before);

        return $url;
    }

    /** @param  list<array{start: int, end: int, uri: string, scheme: bool}>  $links */
    private static function overlaps(array $links, int $start, int $end): bool
    {
        foreach ($links as $link) {
            if ($start < $link['end'] && $end > $link['start']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $feature
     * @return array<string, mixed>
     */
    private static function facet(int $start, int $end, array $feature): array
    {
        return ['index' => ['byteStart' => $start, 'byteEnd' => $end], 'features' => [$feature]];
    }

    private function cacheGet(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (Throwable) {
            return null;
        }
    }

    private function cachePut(string $key, string $value, int $seconds): void
    {
        try {
            Cache::put($key, $value, $seconds);
        } catch (Throwable) {
            // ignore: resolved again next time
        }
    }
}
