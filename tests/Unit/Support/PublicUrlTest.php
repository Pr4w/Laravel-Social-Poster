<?php

use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Uri;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Support\PublicUrl;

beforeEach(function () {
    config(['social.media.block_private_networks' => true]);
    PublicUrl::resolveUsing(fn (string $host) => match ($host) {
        'example.com', 'cdn.example.com', 'a.media.example.org' => ['93.184.216.34'],
        'v6.example.com' => ['2606:2800:220:1:248:1893:25c8:1946'],
        'internal.example.com' => ['10.0.0.7'],
        'rebind.example.com' => ['93.184.216.34', '127.0.0.1'],
        'metadata.example.com' => ['169.254.169.254'],
        default => [],
    });
});

afterEach(function () {
    PublicUrl::resolveUsing(null);
});

it('classifies IP addresses', function (string $ip, bool $public) {
    expect(PublicUrl::isPublicIp($ip))->toBe($public);
})->with([
    'public IPv4' => ['93.184.216.34', true],
    'public DNS' => ['8.8.8.8', true],
    'RFC1918 10/8' => ['10.0.0.1', false],
    'RFC1918 172.16/12' => ['172.16.5.4', false],
    'RFC1918 192.168/16' => ['192.168.1.1', false],
    'loopback' => ['127.0.0.1', false],
    'cloud metadata' => ['169.254.169.254', false],
    'carrier-grade NAT' => ['100.64.0.1', false],
    'this network' => ['0.0.0.0', false],
    'multicast' => ['224.0.0.1', false],
    'broadcast' => ['255.255.255.255', false],
    'public IPv6' => ['2606:4700:4700::1111', true],
    'IPv6 loopback' => ['::1', false],
    'IPv6 unspecified' => ['::', false],
    'IPv6 link-local' => ['fe80::1', false],
    'IPv6 unique local' => ['fd12:3456::1', false],
    'IPv6 multicast' => ['ff02::1', false],
    'IPv6 documentation' => ['2001:db8::1', false],
    'IPv4-mapped loopback (dotted)' => ['::ffff:127.0.0.1', false],
    'IPv4-mapped loopback (hex)' => ['::ffff:7f00:1', false],
    'IPv4-mapped metadata' => ['::ffff:169.254.169.254', false],
    'IPv4-mapped private' => ['::ffff:10.0.0.1', false],
    'IPv4-mapped public' => ['::ffff:8.8.8.8', true],
    'NAT64 metadata' => ['64:ff9b::a9fe:a9fe', false],
    'IPv4-compatible loopback' => ['::127.0.0.1', false],
    '6to4' => ['2002:7f00:1::', false],
    'Teredo' => ['2001::1', false],
    'bracketed' => ['[::1]', false],
    'garbage' => ['not-an-ip', false],
]);

it('refuses private, reserved and mapped targets', function (string $url) {
    expect(fn () => PublicUrl::check($url))->toThrow(PermanentException::class);
})->with([
    'metadata endpoint' => ['https://169.254.169.254/latest/meta-data/'],
    'IPv6 loopback' => ['https://[::1]/a.jpg'],
    'IPv4-mapped IPv6' => ['https://[::ffff:127.0.0.1]/a.jpg'],
    'IPv4-mapped IPv6 hex' => ['https://[::ffff:a9fe:a9fe]/a.jpg'],
    'host resolving to a private address' => ['https://internal.example.com/a.jpg'],
    'host resolving to metadata' => ['https://metadata.example.com/a.jpg'],
    'DNS answer mixing public and private (rebinding)' => ['https://rebind.example.com/a.jpg'],
    'decimal IPv4 literal of 127.0.0.1' => ['http://2130706433/'],
]);

it('accepts public hosts and literals', function (string $url) {
    PublicUrl::check($url);

    expect(PublicUrl::isPublic($url))->toBeTrue();
})->with([
    ['https://example.com/a.jpg'],
    ['https://v6.example.com/a.jpg'],
    ['https://93.184.216.34/a.jpg'],
    ['https://[2606:4700:4700::1111]/a.jpg'],
]);

it('rejects credentials in the URL without echoing them', function () {
    try {
        PublicUrl::check('https://user:s3cret@example.com/a.jpg');
        $this->fail('Expected a PermanentException.');
    } catch (PermanentException $e) {
        expect($e->reason)->toBe(FailureReason::MediaRejected)
            ->and($e->getMessage())->not->toContain('s3cret')
            ->and($e->getMessage())->toContain('user name or password');
    }
});

it('enforces the http policy', function () {
    PublicUrl::check('http://example.com/a.jpg'); // 1.x default: allow_http true

    config(['social.media.allow_http' => false]);

    expect(fn () => PublicUrl::check('http://example.com/a.jpg'))->toThrow(PermanentException::class, 'must use https');
    PublicUrl::check('https://example.com/a.jpg');
});

it('rejects other schemes and parser-confusing URLs', function (string $url) {
    expect(fn () => PublicUrl::check($url))->toThrow(PermanentException::class);
})->with([
    ['file:///etc/passwd'],
    ['ftp://example.com/a.jpg'],
    ['gopher://example.com/'],
    ['https://exa mple.com/a.jpg'],
    ['https://example.com\\@evil.com/'],
    ["https://example.com/\x00"],
    [''],
]);

it('treats an unresolvable host as a transient network failure', function () {
    expect(fn () => PublicUrl::check('https://nowhere.example.net/a.jpg'))
        ->toThrow(TemporaryException::class, 'could not be resolved');
});

it('allows private targets when block_private_networks is off, but isPublic() still says no', function () {
    config(['social.media.block_private_networks' => false]);

    PublicUrl::check('http://127.0.0.1:8000/a.jpg');
    PublicUrl::check('https://app.example.test/a.jpg');

    expect(PublicUrl::isPublic('http://127.0.0.1:8000/a.jpg'))->toBeFalse();
});

it('defaults to blocking outside local development and unit tests', function () {
    config(['social.media.block_private_networks' => null]);

    expect(PublicUrl::blocksPrivateNetworks())->toBeFalse(); // running unit tests

    $original = app()['env'];

    try {
        app()['env'] = 'production';
        expect(PublicUrl::blocksPrivateNetworks())->toBeTrue();

        app()['env'] = 'local';
        expect(PublicUrl::blocksPrivateNetworks())->toBeFalse();
    } finally {
        app()['env'] = $original;
    }
});

it('restricts hosts to social.media.allowed_hosts', function () {
    config(['social.media.allowed_hosts' => ['cdn.example.com', '*.media.example.org']]);

    PublicUrl::check('https://cdn.example.com/a.jpg');
    PublicUrl::check('https://a.media.example.org/a.jpg');

    expect(fn () => PublicUrl::check('https://example.com/a.jpg'))->toThrow(PermanentException::class, 'allowed host')
        ->and(fn () => PublicUrl::check('https://cdn.example.com.evil.net/a.jpg'))->toThrow(PermanentException::class);
});

it('returns the checked address for CURLOPT_RESOLVE pinning', function () {
    expect(PublicUrl::resolvePinned('https://cdn.example.com/a.jpg'))
        ->toBe(['host' => 'cdn.example.com', 'port' => 443, 'ip' => '93.184.216.34'])
        ->and(PublicUrl::resolvePinned('http://cdn.example.com:8080/a.jpg')['port'])->toBe(8080)
        ->and(PublicUrl::resolvePinned('http://cdn.example.com/a.jpg')['port'])->toBe(80);

    // Both spellings: cURL looks a host written with a trailing dot up under that spelling.
    expect(PublicUrl::curlOptions(['host' => 'cdn.example.com', 'port' => 443, 'ip' => '93.184.216.34']))
        ->toBe(['curl' => [CURLOPT_RESOLVE => ['cdn.example.com:443:93.184.216.34', 'cdn.example.com.:443:93.184.216.34']]])
        ->and(PublicUrl::curlOptions(['host' => 'v6.example.com', 'port' => 443, 'ip' => '2606:4700::1']))
        ->toBe(['curl' => [CURLOPT_RESOLVE => ['v6.example.com:443:[2606:4700::1]', 'v6.example.com.:443:[2606:4700::1]']]])
        ->and(PublicUrl::curlOptions(['host' => '93.184.216.34', 'port' => 443, 'ip' => '93.184.216.34']))->toBe([]);

    expect(fn () => PublicUrl::resolvePinned('https://internal.example.com/a.jpg'))->toThrow(PermanentException::class);
});

it('pins the spelling cURL looks up for a trailing-dot host, so DNS cannot be rebound through it', function () {
    $pins = PublicUrl::curlOptions(PublicUrl::resolvePinned('https://cdn.example.com./a.jpg'))['curl'][CURLOPT_RESOLVE];

    expect($pins)->toContain('cdn.example.com.:443:93.184.216.34')
        ->toContain('cdn.example.com:443:93.184.216.34');
});

it('pins the punycode spelling of an internationalised host (cURL converts it before the lookup)', function () {
    PublicUrl::resolveUsing(fn () => ['93.184.216.34']);

    $pins = PublicUrl::curlOptions(PublicUrl::resolvePinned('https://bücher.example.com/a.jpg'))['curl'][CURLOPT_RESOLVE];

    expect($pins)->toContain('xn--bcher-kva.example.com:443:93.184.216.34')
        ->toContain('xn--bcher-kva.example.com.:443:93.184.216.34');
})->skip(! function_exists('idn_to_ascii'), 'ext-intl is required for IDN hosts');

it('refuses hosts with empty labels, which resolvers and cURL may read differently', function (string $url) {
    expect(fn () => PublicUrl::check($url))->toThrow(PermanentException::class);
})->with([
    ['https://cdn.example.com../a.jpg'],
    ['https://.cdn.example.com/a.jpg'],
    ['https://cdn..example.com/a.jpg'],
]);

it('re-checks every redirect hop', function () {
    $options = PublicUrl::redirectOptions(3);
    $request = new PsrRequest('GET', 'https://example.com/a.jpg');
    $response = new PsrResponse(302);

    expect($options['max'])->toBe(3)
        ->and($options['referer'])->toBeFalse();

    ($options['on_redirect'])($request, $response, new Uri('https://cdn.example.com/b.jpg'));

    expect(fn () => ($options['on_redirect'])($request, $response, new Uri('http://169.254.169.254/latest/meta-data')))
        ->toThrow(PermanentException::class);
});
