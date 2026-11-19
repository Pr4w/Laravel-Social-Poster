<?php

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SocialPoster\Platforms\Bluesky\BlueskyLinkCardResolver;
use SocialPoster\Platforms\Bluesky\BlueskyOptions;
use SocialPoster\Support\PublicUrl;
use SocialPoster\ValueObjects\Media;

require_once __DIR__.'/../../Fixtures/bluesky/helpers.php';

function bskyHtml(string $head, string $charset = 'utf-8'): PromiseInterface
{
    return Http::response("<!doctype html><html><head>{$head}</head><body>x</body></html>", 200, ['Content-Type' => "text/html; charset={$charset}"]);
}

function bskyPng(): string
{
    return (string) file_get_contents(bskyImage('png', 8, 8));
}

/** The card the driver put in the commit state (null when none). */
function bskyCardFor(string $caption, ?BlueskyOptions $options = null, array $media = []): ?array
{
    return bskyDriver()->publish(bskyPost($caption, $media, $options))->state['embed'] ?? null;
}

beforeEach(function () {
    Cache::flush();
    config(['social.authorizers' => []]);
});

afterEach(function () {
    bskyCleanup();
    PublicUrl::resolveUsing(null);
});

it('builds a card from Open Graph tags and uploads the thumbnail', function () {
    $png = bskyPng();

    Http::fake([
        'https://example.com/blog/post' => Http::response(bskyFixture('og_page.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        'https://example.com/static/cards/launch.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        '*/xrpc/com.atproto.repo.uploadBlob' => Http::response(bskyFixture('upload_blob.json')),
        '*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord(),
    ]);

    $driver = bskyDriver();
    $post = bskyPost('Our recap: https://example.com/blog/post.');
    $pending = $driver->publish($post);

    $card = [
        '$type' => 'app.bsky.embed.external',
        'external' => [
            'uri' => 'https://example.com/blog/post',
            'title' => "Launch week: what's new",
            'description' => 'Five features in five days. Read the recap.',
            'thumb' => bskyFixture('upload_blob.json')['blob'],
        ],
    ];

    expect($pending->state['embed'])->toBe($card)
        ->and(bskySent('uploadBlob')[0]->body())->toBe($png)
        ->and(bskySent('uploadBlob')[0]->header('Content-Type'))->toBe(['image/png'])
        ->and(bskySent('example.com/blog/post')[0]->header('Accept')[0])->toContain('text/html');

    $driver->resume($post, $pending->state);

    expect(bskySent('createRecord')[0]->data()['record']['embed'])->toBe($card);
});

it('follows redirects by hand and resolves og:image against the final URL', function (string $image, string $expected) {
    Http::fake([
        'https://example.com/go' => Http::response('', 301, ['Location' => '/blog/2026/post']),
        'https://example.com/blog/2026/post' => bskyHtml('<meta property="og:title" content="T"><meta property="og:image" content="'.$image.'">'),
        $expected => Http::response(bskyPng(), 200, ['Content-Type' => 'image/png']),
        '*/xrpc/com.atproto.repo.uploadBlob' => Http::response(bskyFixture('upload_blob.json')),
    ]);

    $card = bskyCardFor('https://example.com/go');

    expect($card['external']['uri'])->toBe('https://example.com/go')
        ->and($card['external'])->toHaveKey('thumb')
        ->and(bskySent($expected))->toHaveCount(1);
})->with([
    'path-relative' => ['img/a.png', 'https://example.com/blog/2026/img/a.png'],
    'root-relative' => ['/a.png', 'https://example.com/a.png'],
    'dot-dot' => ['../b.png', 'https://example.com/blog/b.png'],
    'protocol-relative' => ['//cdn.example.com/c.png', 'https://cdn.example.com/c.png'],
    'absolute with port' => ['https://cdn.example.com:8443/d.png', 'https://cdn.example.com:8443/d.png'],
]);

it('falls back to twitter tags, the title tag, the description meta and the host', function () {
    $resolver = new BlueskyLinkCardResolver;

    expect($resolver->parse('<head><title> Page  title </title><meta name="description" content="Plain description"></head>'))->toBe([
        'title' => 'Page title', 'description' => 'Plain description', 'image' => '',
    ])->and($resolver->parse('<head><meta name="twitter:title" content="TT"><meta name="twitter:description" content="TD"><meta name="twitter:image" content="https://x.example/i.png"></head>'))->toBe([
        'title' => 'TT', 'description' => 'TD', 'image' => 'https://x.example/i.png',
    ])->and($resolver->parse(''))->toBe(['title' => '', 'description' => '', 'image' => '']);

    Http::fake(['https://example.org/*' => bskyHtml('<meta charset="utf-8">')]);

    expect(bskyCardFor('https://example.org/page')['external'])->toBe([
        'uri' => 'https://example.org/page',
        'title' => 'example.org',
        'description' => '',
    ]);
});

it('limits the title to 300 and the description to 1000 characters', function () {
    Http::fake(['https://example.com/*' => bskyHtml('<meta property="og:title" content="'.str_repeat('t', 400).'"><meta property="og:description" content="'.str_repeat('d', 1200).'">')]);

    $external = bskyCardFor('https://example.com/long')['external'];

    expect(mb_strlen($external['title']))->toBe(300)
        ->and(mb_strlen($external['description']))->toBe(1000);
});

it('decodes pages in another charset', function () {
    Http::fake(['https://example.com/*' => bskyHtml(mb_convert_encoding('<meta property="og:title" content="Café crème">', 'ISO-8859-1', 'UTF-8'), 'iso-8859-1')]);

    expect(bskyCardFor('https://example.com/fr')['external']['title'])->toBe('Café crème');
});

it('refuses a redirect into the private network and still publishes', function () {
    config(['social.media.block_private_networks' => true]);
    PublicUrl::resolveUsing(fn (string $host) => ['93.184.216.34']);
    Http::fake([
        'https://example.com/*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data/iam']),
        '*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord(),
    ]);

    $result = bskyRun(bskyDriver(), bskyPost('See https://example.com/r'));

    expect(bskySent('169.254.169.254'))->toBe([])
        ->and(bskySent('createRecord')[0]->data()['record'])->not->toHaveKey('embed')
        ->and($result->platformPostId)->toStartWith('at://');
});

it('refuses a page on a private address and an og:image on one', function () {
    config(['social.media.block_private_networks' => true]);
    PublicUrl::resolveUsing(fn (string $host) => match ($host) {
        'internal.example.com', 'img.internal.example.com' => ['10.0.0.8'],
        default => ['93.184.216.34'],
    });
    Http::fake([
        'https://example.com/*' => bskyHtml('<meta property="og:title" content="Public"><meta property="og:image" content="https://img.internal.example.com/x.png">'),
    ]);

    expect(bskyCardFor('https://internal.example.com/admin'))->toBeNull()
        ->and(bskyCardFor('https://example.com/ok')['external'])->toBe(['uri' => 'https://example.com/ok', 'title' => 'Public', 'description' => ''])
        ->and(bskySent('internal.example.com'))->toBe([]);
});

it('stops after three redirects and on non-https hops', function () {
    Http::fake([
        'https://example.com/loop*' => fn (Request $r) => Http::response('', 302, ['Location' => $r->url().'x']),
        'https://example.net/*' => Http::response('', 302, ['Location' => 'http://example.net/plain']),
    ]);

    expect(bskyCardFor('https://example.com/loop'))->toBeNull()
        ->and(bskySent('example.com/loop'))->toHaveCount(4)
        ->and(bskyCardFor('https://example.net/a'))->toBeNull()
        ->and(bskySent('http://example.net/plain'))->toBe([]);
});

it('gives no card for non-HTML pages, errors and http links', function () {
    Http::fake([
        'https://example.com/file.pdf' => Http::response('%PDF-1.7', 200, ['Content-Type' => 'application/pdf']),
        'https://example.com/broken' => Http::response('oops', 500, ['Content-Type' => 'text/html']),
        'https://example.com/slow' => bskyConnectionFailure(),
    ]);

    expect(bskyCardFor('https://example.com/file.pdf'))->toBeNull()
        ->and(bskyCardFor('https://example.com/broken'))->toBeNull()
        ->and(bskyCardFor('https://example.com/slow'))->toBeNull()
        ->and(bskyCardFor('http://example.com/plain'))->toBeNull()
        ->and(bskySent('http://example.com/plain'))->toBe([]);
});

it('builds no card with media, a quote, or linkPreview off', function () {
    Http::fake();

    $quote = ['uri' => 'at://did:plc:other/app.bsky.feed.post/3m5kqzw7yx22c', 'cid' => 'bafy'];

    expect(bskyCardFor('https://example.com', new BlueskyOptions(linkPreview: false)))->toBeNull()
        ->and(bskyCardFor('https://example.com', new BlueskyOptions(quote: $quote)))->toBeNull()
        ->and(bskyCardFor('https://example.com', null, [Media::image(bskyImage())]))->toBeNull()
        ->and(bskyCardFor('No link here'))->toBeNull();

    Http::assertNothingSent();
});

it('uses an explicit card without fetching the page', function () {
    $thumb = bskyImage('jpg');

    Http::fake([
        'https://cdn.example.com/card.png' => Http::response(bskyPng(), 200, ['Content-Type' => 'image/png']),
        '*/xrpc/com.atproto.repo.uploadBlob' => Http::response(bskyFixture('upload_blob.json')),
    ]);

    $remote = bskyCardFor('Text https://example.com/ignored', new BlueskyOptions(linkCard: ['uri' => 'https://example.com/landing', 'title' => 'Landing', 'description' => 'Desc', 'thumb' => 'https://cdn.example.com/card.png']));
    $local = bskyCardFor('', new BlueskyOptions(linkCard: ['uri' => 'https://example.com/landing', 'thumb' => $thumb]));

    expect($remote['external'])->toBe([
        'uri' => 'https://example.com/landing',
        'title' => 'Landing',
        'description' => 'Desc',
        'thumb' => bskyFixture('upload_blob.json')['blob'],
    ])->and($local['external']['title'])->toBe('example.com')
        ->and($local['external']['thumb'])->toBe(bskyFixture('upload_blob.json')['blob'])
        ->and(bskySent('example.com/ignored'))->toBe([])
        ->and(bskySent('example.com/landing'))->toBe([])
        ->and(bskySent('/xrpc/com.atproto.repo.uploadBlob')[1]->header('Content-Type'))->toBe(['image/jpeg']);
});

it('scales an oversized thumbnail down to a JPEG under 1 MB', function () {
    $big = bskyNoisePng(700, 700);

    Http::fake([
        'https://example.com/page' => bskyHtml('<meta property="og:title" content="Big"><meta property="og:image" content="/big.png">'),
        'https://example.com/big.png' => Http::response($big, 200, ['Content-Type' => 'image/png']),
        '*/xrpc/com.atproto.repo.uploadBlob' => Http::response(bskyFixture('upload_blob.json')),
    ]);

    $card = bskyCardFor('https://example.com/page');
    $upload = bskySent('/xrpc/com.atproto.repo.uploadBlob')[0];

    expect(strlen($big))->toBeGreaterThan(1_000_000)
        ->and($card['external'])->toHaveKey('thumb')
        ->and($upload->header('Content-Type'))->toBe(['image/jpeg'])
        ->and(strlen($upload->body()))->toBeLessThanOrEqual(1_000_000)
        ->and(getimagesizefromstring($upload->body())[0])->toBe(700);
});

it('drops a thumbnail it cannot shrink, upload or read', function () {
    Http::fake([
        'https://example.com/garbage' => bskyHtml('<meta property="og:title" content="G"><meta property="og:image" content="/g.jpg">'),
        'https://example.com/g.jpg' => Http::response(random_bytes(1_100_000), 200, ['Content-Type' => 'image/jpeg']),
        'https://example.com/huge' => bskyHtml('<meta property="og:title" content="H"><meta property="og:image" content="/h.png">'),
        'https://example.com/h.png' => Http::response(str_repeat('x', 5_000_001), 200, ['Content-Type' => 'image/png']),
        'https://example.com/html-image' => bskyHtml('<meta property="og:title" content="I"><meta property="og:image" content="/i.png">'),
        'https://example.com/i.png' => bskyHtml(''),
        'https://example.com/upload-fails' => bskyHtml('<meta property="og:title" content="U"><meta property="og:image" content="/u.png">'),
        'https://example.com/u.png' => Http::response(bskyPng(), 200, ['Content-Type' => 'image/png']),
        '*/xrpc/com.atproto.repo.uploadBlob' => Http::response(['error' => 'InternalServerError', 'message' => 'Internal Server Error'], 500),
    ]);

    foreach (['garbage' => 'G', 'huge' => 'H', 'html-image' => 'I', 'upload-fails' => 'U'] as $path => $title) {
        $card = bskyCardFor('https://example.com/'.$path);

        expect($card['external']['title'])->toBe($title)
            ->and($card['external'])->not->toHaveKey('thumb');
    }

    expect(bskySent('/xrpc/com.atproto.repo.uploadBlob'))->toHaveCount(1);
});

it('parses the head of a page larger than the cap', function () {
    config(['social.api.bluesky.link_preview_max_bytes' => 300]);
    Http::fake(['https://example.com/*' => bskyHtml('<meta property="og:title" content="Early">'.str_repeat('<meta name="x" content="padding">', 200))]);

    expect(bskyCardFor('https://example.com/big')['external']['title'])->toBe('Early');
});

it('sends the configured user agent and honours the timeout config', function () {
    config(['social.api.bluesky.link_preview_user_agent' => 'PosterBot/1.1 (+https://example.com/bot)']);
    Http::fake(['https://example.com/*' => bskyHtml('<meta property="og:title" content="UA">')]);

    bskyCardFor('https://example.com/ua');

    expect(bskySent('example.com/ua')[0]->header('User-Agent'))->toBe(['PosterBot/1.1 (+https://example.com/bot)']);
});

it('fetches the card for the whole link, a non-Latin path included', function () {
    Http::fake(['https://ja.wikipedia.org/*' => bskyHtml('<meta property="og:title" content="東京都">')]);

    $card = bskyCardFor('Read https://ja.wikipedia.org/wiki/東京 now');
    $fetches = bskySent('ja.wikipedia.org');

    expect($card['external']['title'])->toBe('東京都')
        ->and($card['external']['uri'])->toBe('https://ja.wikipedia.org/wiki/東京')
        ->and($fetches)->toHaveCount(1)
        ->and(rawurldecode($fetches[0]->url()))->toBe('https://ja.wikipedia.org/wiki/東京');
});

it('cards the first https link written with a scheme, not bare domains', function () {
    Http::fake(['https://second.example.com/*' => bskyHtml('<meta property="og:title" content="Second">')]);

    expect(bskyCardFor('Visit example.com then https://second.example.com/page')['external']['title'])->toBe('Second')
        ->and(bskySent('https://example.com'))->toBe([]);
});

it('keeps the DNS pin on every hop: no streamed transfer, the size bounded by aborting', function () {
    config(['social.media.block_private_networks' => true]);
    PublicUrl::resolveUsing(fn (string $host) => ['93.184.216.34']);

    $resolver = new class extends BlueskyLinkCardResolver
    {
        /** @return array<string, mixed> */
        public function optionsFor(string $url, ?int $abortAfter): array
        {
            return $this->transferOptions($url, $abortAfter);
        }
    };

    $options = $resolver->optionsFor('https://example.com/page', 1000);

    expect($options)->not->toHaveKey('stream')
        ->and($options['curl'] ?? [])->toHaveKey(CURLOPT_RESOLVE)
        ->and($options['progress'])->toBeCallable()
        ->and(fn () => ($options['progress'])(0, 1001))->toThrow(RuntimeException::class)
        ->and(fn () => ($options['on_headers'])(new Response(200, ['Content-Length' => '5000'])))->toThrow(RuntimeException::class);

    ($options['progress'])(0, 900);
    ($options['on_headers'])(new Response(200, ['Content-Length' => '900']));
});

it('builds no card when HTML cannot be parsed (no ext-dom) instead of a bare host card', function () {
    Http::fake(['https://example.org/*' => bskyHtml('<meta property="og:title" content="T">')]);

    $resolver = new class extends BlueskyLinkCardResolver
    {
        protected function canParse(): bool
        {
            return false;
        }
    };

    expect($resolver->forUrl('https://example.org/page', fn () => null))->toBeNull();
    Http::assertNothingSent();
});
