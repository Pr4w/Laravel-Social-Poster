<?php

namespace SocialPoster\Support;

use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use Throwable;

/**
 * SSRF guard for every URL the package fetches itself: remote media downloads,
 * remote inspection, link cards and Open Graph fetches. A user-supplied URL
 * must not make the server read cloud metadata (169.254.169.254), admin ports
 * or files on the private network.
 *
 * Policy (config social.media.*):
 * - https only, unless allow_http (default true in 1.x);
 * - no user:password@ in the URL;
 * - optional allowed_hosts list (exact host, or "*.example.com" / ".example.com");
 * - block_private_networks (default: on outside the local environment and unit
 *   tests): every A/AAAA record must be a public address. IPv4-mapped, NAT64
 *   and IPv4-compatible IPv6 forms are unwrapped and checked as IPv4.
 *
 * DNS can change between this check and the request (rebinding), so fetchers
 * pin the checked address with resolvePinned() + curlOptions() (CURLOPT_RESOLVE)
 * and re-check every redirect hop (redirectOptions()). The pin covers every
 * spelling cURL may look the host up under (with and without a trailing dot,
 * and the punycode form of an internationalised name); a host with empty
 * labels ("a..b", ".a", "a..") is refused outright.
 */
final class PublicUrl
{
    /** @var array<int, array{0: string, 1: int}> */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
        ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16],
        ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
    ];

    /** @var array<int, array{0: string, 1: int}> */
    private const BLOCKED_V6 = [
        ['::', 96], ['100::', 64], ['2001::', 23], ['2001:db8::', 32], ['2002::', 16],
        ['fc00::', 7], ['fe80::', 10], ['fec0::', 10], ['ff00::', 8], ['64:ff9b:1::', 48],
    ];

    /** @var (callable(string): array<int, string>)|null */
    private static $resolver = null;

    /**
     * Throw unless $url may be fetched under the configured policy.
     *
     * @param  bool|null  $blockPrivate  Override social.media.block_private_networks for this call.
     *
     * @throws PermanentException MediaRejected when the URL breaks the policy.
     * @throws TemporaryException Network when the host cannot be resolved (DNS may recover).
     */
    public static function check(string $url, ?bool $blockPrivate = null): void
    {
        $uri = self::parse($url);

        if ($blockPrivate ?? self::blocksPrivateNetworks()) {
            self::publicAddresses($uri->getHost(), $url);
        }
    }

    /** True when $url passes the policy AND resolves only to public addresses. */
    public static function isPublic(string $url): bool
    {
        try {
            self::check($url, true);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Check $url and return the address to connect to, for CURLOPT_RESOLVE
     * pinning: cURL then connects to exactly the address that was checked.
     * With block_private_networks off the first resolved address is returned
     * unchecked.
     *
     * @return array{host: string, port: int, ip: string}
     *
     * @throws PermanentException|TemporaryException
     */
    public static function resolvePinned(string $url, ?bool $blockPrivate = null): array
    {
        $uri = self::parse($url);
        $host = self::host($uri);
        $port = $uri->getPort() ?? (strtolower($uri->getScheme()) === 'http' ? 80 : 443);

        $addresses = ($blockPrivate ?? self::blocksPrivateNetworks())
            ? self::publicAddresses($uri->getHost(), $url)
            : self::resolve($host);

        if ($addresses === []) {
            throw self::unresolvable($url);
        }

        return ['host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    /**
     * Guzzle options pinning the connection to a resolvePinned() result.
     * Empty when the cURL extension (CURLOPT_RESOLVE) is unavailable.
     *
     * cURL keys its DNS cache by the host as written in the URL (lowercased),
     * so a pin for "cdn.example.com" is ignored for "https://cdn.example.com./"
     * and cURL resolves that name again: a rebinding host could then answer
     * with a private address. Every spelling cURL may use is pinned: with and
     * without the trailing dot, and the punycode form it converts an
     * internationalised name to (libidn2 builds).
     *
     * @param  array{host: string, port: int, ip: string}  $pinned
     * @return array<string, mixed>
     */
    public static function curlOptions(array $pinned): array
    {
        if (! defined('CURLOPT_RESOLVE') || filter_var($pinned['host'], FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $ip = str_contains($pinned['ip'], ':') ? '['.$pinned['ip'].']' : $pinned['ip'];
        $host = rtrim(strtolower($pinned['host']), '.');
        $spellings = [$host];

        if (preg_match('/[^\x00-\x7F]/', $host) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (is_string($ascii) && $ascii !== '') {
                $spellings[] = strtolower($ascii);
            }
        }

        $entries = [];

        foreach ($spellings as $spelling) {
            $entries[] = "{$spelling}:{$pinned['port']}:{$ip}";
            $entries[] = "{$spelling}.:{$pinned['port']}:{$ip}";
        }

        return ['curl' => [CURLOPT_RESOLVE => array_values(array_unique($entries))]];
    }

    /**
     * Guzzle allow_redirects options that re-check every hop against the policy
     * (an open redirect to 169.254.169.254 is refused). A hop to a different
     * host is not pinned; fetchers that need pinning on every hop follow
     * redirects themselves (see LocalMediaGateway).
     *
     * @return array{max: int, strict: bool, referer: bool, track_redirects: bool, on_redirect: callable}
     */
    public static function redirectOptions(int $max = 3, ?bool $blockPrivate = null): array
    {
        return [
            'max' => $max,
            'strict' => true,
            'referer' => false,
            'track_redirects' => false,
            'on_redirect' => static function ($request, $response, UriInterface $uri) use ($blockPrivate): void {
                self::check((string) $uri, $blockPrivate);
            },
        ];
    }

    /** Whether private and reserved addresses are refused (social.media.block_private_networks). */
    public static function blocksPrivateNetworks(): bool
    {
        $configured = config('social.media.block_private_networks');

        if ($configured !== null) {
            return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
        }

        // 1.x default: permissive for local development (Herd/Valet *.test hosts
        // resolve to 127.0.0.1) and for test suites (no DNS lookups), strict elsewhere.
        try {
            $app = app();

            return ! $app->environment('local') && ! $app->runningUnitTests();
        } catch (Throwable) {
            return true;
        }
    }

    /** True for a globally routable unicast address (IPv4 or IPv6). */
    public static function isPublicIp(string $ip): bool
    {
        $ip = trim($ip, '[]');
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 16 && ($embedded = self::embeddedIpv4($binary)) !== null) {
            $binary = $embedded;
        }

        $ranges = strlen($binary) === 4 ? self::BLOCKED_V4 : self::BLOCKED_V6;

        foreach ($ranges as [$network, $bits]) {
            if (self::inRange($binary, (string) inet_pton($network), $bits)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Replace DNS resolution (tests, or apps with their own resolver).
     * Pass null to restore the system resolver.
     *
     * @param  (callable(string): array<int, string>)|null  $resolver
     */
    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /** Parse and apply the scheme, userinfo and host-list rules. */
    private static function parse(string $url): UriInterface
    {
        // Whitespace, control characters and backslashes are where URL parsers
        // disagree (Guzzle vs cURL vs ffprobe); refuse them outright.
        if ($url === '' || preg_match('/[\s\\\\\x00-\x1F\x7F]/', $url) === 1) {
            throw self::rejected($url, 'is not a valid URL');
        }

        try {
            $uri = new Uri($url);
        } catch (Throwable) {
            throw self::rejected($url, 'is not a valid URL');
        }

        $scheme = strtolower($uri->getScheme());
        $allowHttp = filter_var(config('social.media.allow_http', true), FILTER_VALIDATE_BOOLEAN);

        if ($scheme !== 'https' && ! ($scheme === 'http' && $allowHttp)) {
            throw self::rejected($url, $allowHttp ? 'must use http or https' : 'must use https');
        }

        if ($uri->getUserInfo() !== '') {
            throw self::rejected($url, 'must not contain a user name or password');
        }

        $host = self::host($uri);

        if ($host === '') {
            throw self::rejected($url, 'has no host');
        }

        // Empty labels ("a..b", ".a", "a.." once the root dot is removed) are
        // read differently by PHP's resolver, cURL and ffprobe.
        if (str_starts_with($host, '.') || str_ends_with($host, '.') || str_contains($host, '..')) {
            throw self::rejected($url, 'has an invalid host name');
        }

        $allowed = config('social.media.allowed_hosts');

        if (is_array($allowed) && $allowed !== [] && ! self::hostAllowed($host, $allowed)) {
            throw self::rejected($url, 'is not on an allowed host (social.media.allowed_hosts)');
        }

        return $uri;
    }

    /** The lowercased host without brackets and without the root (trailing) dot. */
    private static function host(UriInterface $uri): string
    {
        return self::normalizeHost($uri->getHost());
    }

    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host, '[]'));

        return str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
    }

    /** @param  array<int, mixed>  $allowed */
    private static function hostAllowed(string $host, array $allowed): bool
    {
        foreach ($allowed as $entry) {
            $entry = strtolower(trim((string) $entry));

            if ($entry === '') {
                continue;
            }

            if ($entry === $host) {
                return true;
            }

            $suffix = ltrim($entry, '*');

            if (str_starts_with($suffix, '.') && str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every address the host resolves to, refusing the URL when any is private.
     *
     * @return array<int, string>
     */
    private static function publicAddresses(string $rawHost, string $url): array
    {
        $host = self::normalizeHost($rawHost);
        $addresses = self::resolve($host);

        if ($addresses === []) {
            throw self::unresolvable($url);
        }

        foreach ($addresses as $address) {
            if (! self::isPublicIp($address)) {
                throw self::rejected($url, 'points to a private or reserved network address', ['address' => $address]);
            }
        }

        return $addresses;
    }

    /** @return array<int, string> */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if (($literal = self::numericIpv4($host)) !== null) {
            return [$literal];
        }

        if (self::$resolver !== null) {
            return array_values(array_filter(array_map('strval', (array) (self::$resolver)($host))));
        }

        if (preg_match('/[^\x00-\x7F]/', $host) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $host = is_string($ascii) ? $ascii : $host;
        }

        $addresses = [];
        $v4 = @gethostbynamel($host);

        if (is_array($v4)) {
            $addresses = $v4;
        }

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA);

            foreach (is_array($records) ? $records : [] as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * inet_aton() spellings of IPv4 that resolvers and cURL accept as literals:
     * "2130706433", "0x7f.1", "0177.0.0.1" all mean 127.0.0.1.
     */
    private static function numericIpv4(string $host): ?string
    {
        if (preg_match('/^(?:0x[0-9a-f]*|\d+)(?:\.(?:0x[0-9a-f]*|\d+)){0,3}$/i', $host) !== 1) {
            return null;
        }

        $parts = [];

        foreach (explode('.', $host) as $part) {
            if (strlen($part) > 1 && $part[0] === '0' && ! str_starts_with(strtolower($part), '0x') && preg_match('/^0[0-7]+$/', $part) !== 1) {
                return null; // "08" is not valid octal, so not an address literal
            }

            $parts[] = match (true) {
                str_starts_with(strtolower($part), '0x') => (int) hexdec(substr($part, 2) ?: '0'),
                strlen($part) > 1 && $part[0] === '0' => (int) octdec($part),
                default => (int) $part,
            };
        }

        $last = array_pop($parts);
        $limits = [1 => 0xFFFFFFFF, 2 => 0xFFFFFF, 3 => 0xFFFF, 4 => 0xFF];
        $count = count($parts) + 1;

        if ($last > $limits[$count]) {
            return null;
        }

        $number = 0;

        foreach ($parts as $index => $part) {
            if ($part > 0xFF) {
                return null;
            }

            $number |= $part << (24 - 8 * $index);
        }

        return long2ip($number | $last) ?: null;
    }

    /** The IPv4 address inside an IPv4-mapped, NAT64 (64:ff9b::/96) or IPv4-compatible IPv6 address. */
    private static function embeddedIpv4(string $binary): ?string
    {
        $prefix = substr($binary, 0, 12);

        if ($prefix === str_repeat("\0", 10)."\xff\xff" || $prefix === "\x00\x64\xff\x9b".str_repeat("\0", 8)) {
            return substr($binary, 12);
        }

        // IPv4-compatible (::a.b.c.d, deprecated); :: and ::1 stay IPv6.
        if ($prefix === str_repeat("\0", 12) && substr($binary, 12, 3) !== "\0\0\0") {
            return substr($binary, 12);
        }

        return null;
    }

    private static function inRange(string $address, string $network, int $bits): bool
    {
        if (strlen($address) !== strlen($network)) {
            return false;
        }

        $bytes = intdiv($bits, 8);

        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        $remainder = $bits % 8;

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    /** @param  array<string, mixed>  $context */
    private static function rejected(string $url, string $why, array $context = []): PermanentException
    {
        return new PermanentException(
            'The media URL '.self::display($url).' '.$why.'.',
            reason: FailureReason::MediaRejected,
            context: ['url_host' => self::displayHost($url), 'ssrf_guard' => true] + $context,
        );
    }

    private static function unresolvable(string $url): TemporaryException
    {
        return new TemporaryException(
            'The host of the media URL '.self::display($url).' could not be resolved.',
            reason: FailureReason::Network,
            context: ['url_host' => self::displayHost($url), 'unresolvable' => true],
        );
    }

    /** scheme://host/path without the query string (it may carry signatures or tokens). */
    private static function display(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return '"'.mb_substr((string) preg_replace('/[?#].*$/s', '', $url), 0, 80).'"';
        }

        return '"'.($parts['scheme'] ?? '').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '').'"';
    }

    private static function displayHost(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }
}
