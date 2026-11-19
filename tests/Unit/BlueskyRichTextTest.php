<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SocialPoster\Platforms\Bluesky\BlueskyOptions;
use SocialPoster\Platforms\Bluesky\BlueskyRichText;

require_once __DIR__.'/../Fixtures/bluesky/helpers.php';

/** facet => [the text it covers, its feature] */
function bskyFacetMap(array $built): array
{
    return array_map(fn (array $facet) => [
        substr($built['text'], $facet['index']['byteStart'], $facet['index']['byteEnd'] - $facet['index']['byteStart']),
        $facet['features'][0],
    ], $built['facets']);
}

beforeEach(function () {
    Cache::flush();
});

it('builds byte-offset facets for the spec example', function () {
    Http::fake([
        'public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=alice.bsky.social' => Http::response(['did' => 'did:plc:alice0000000000000000000']),
        'public.api.bsky.app/*' => Http::response(['error' => 'InvalidRequest', 'message' => 'Unable to resolve handle'], 400),
    ]);

    $built = (new BlueskyRichText)->build('😀 #tag https://example.com/c). @alice.bsky.social @nobody.example.com');

    expect($built['facets'])->toBe([
        ['index' => ['byteStart' => 5, 'byteEnd' => 9], 'features' => [['$type' => BlueskyRichText::TAG, 'tag' => 'tag']]],
        ['index' => ['byteStart' => 10, 'byteEnd' => 31], 'features' => [['$type' => BlueskyRichText::LINK, 'uri' => 'https://example.com/c']]],
        ['index' => ['byteStart' => 34, 'byteEnd' => 52], 'features' => [['$type' => BlueskyRichText::MENTION, 'did' => 'did:plc:alice0000000000000000000']]],
    ])->and(bskyFacetMap($built))->toBe([
        ['#tag', ['$type' => BlueskyRichText::TAG, 'tag' => 'tag']],
        ['https://example.com/c', ['$type' => BlueskyRichText::LINK, 'uri' => 'https://example.com/c']],
        ['@alice.bsky.social', ['$type' => BlueskyRichText::MENTION, 'did' => 'did:plc:alice0000000000000000000']],
    ]);
});

it('starts a full-width hashtag at its 3-byte sign', function () {
    $built = (new BlueskyRichText)->build('é ＃日本語 and #two');

    expect(bskyFacetMap($built))->toBe([
        ['＃日本語', ['$type' => BlueskyRichText::TAG, 'tag' => '日本語']],
        ['#two', ['$type' => BlueskyRichText::TAG, 'tag' => 'two']],
    ]);
});

it('applies the hashtag rules', function (string $text, array $tags) {
    $built = (new BlueskyRichText)->build($text);

    expect(array_column(array_column(array_column($built['facets'], 'features'), 0), 'tag'))->toBe($tags);
})->with([
    'trailing punctuation' => ['Hi #launch!! and #ok.', ['launch', 'ok']],
    'digits only' => ['Room #123 and #2026goals', ['2026goals']],
    'inside a word' => ['email#tag x#y', []],
    'keycap emoji' => ["Press #\u{FE0F}\u{20E3} now", []],
    '64 graphemes' => ['#'.str_repeat('a', 64), [str_repeat('a', 64)]],
    '65 graphemes' => ['#'.str_repeat('a', 65), []],
    'emoji tag' => ['#🎉party', ['🎉party']],
    'url fragment' => ['https://example.com/#section', []],
]);

it('keeps the link when a tag overlaps it', function () {
    $built = (new BlueskyRichText)->build('#https://example.com/x');

    expect(array_column(array_column($built['facets'], 'features'), 0))->toBe([
        ['$type' => BlueskyRichText::LINK, 'uri' => 'https://example.com/x'],
    ]);
});

it('detects links the way the Urls rules do, plus scheme URLs on IP hosts', function (string $text, array $links) {
    expect(array_column(BlueskyRichText::links($text), 'uri'))->toBe($links);
})->with([
    'balanced parenthesis kept' => ['(see https://en.wikipedia.org/wiki/Foo_(bar)).', ['https://en.wikipedia.org/wiki/Foo_(bar)']],
    'bare domain becomes https' => ['visit example.com/docs today', ['https://example.com/docs']],
    'unknown TLD stays text' => ['file.notatld here', []],
    'ip host' => ['api at https://192.0.2.10:8443/v1?x=1, ok', ['https://192.0.2.10:8443/v1?x=1']],
    'quotes trimmed' => ['"https://example.com/a"', ['https://example.com/a']],
    'two links' => ['https://a.example.com and http://b.example.org/x', ['https://a.example.com', 'http://b.example.org/x']],
    'email is not a link' => ['mail bob@example.com', []],
    // Paths in other scripts and ASCII symbols twitter-text stops at: the
    // whole non-space run is the link, as the Bluesky app detects it.
    'japanese path' => ['Read https://ja.wikipedia.org/wiki/東京 now', ['https://ja.wikipedia.org/wiki/東京']],
    'arabic path' => ['See https://ar.wikipedia.org/wiki/القاهرة today', ['https://ar.wikipedia.org/wiki/القاهرة']],
    'greek path before a full stop' => ['Go https://el.wikipedia.org/wiki/Αθήνα. ok', ['https://el.wikipedia.org/wiki/Αθήνα']],
    'korean query value' => ['https://example.com/search?q=서울 ok', ['https://example.com/search?q=서울']],
    'ideographic full stop after the path' => ['東京 https://ja.wikipedia.org/wiki/東京。', ['https://ja.wikipedia.org/wiki/東京']],
    'braces' => ['Docs https://example.com/a{b}c and more', ['https://example.com/a{b}c']],
    'caret' => ['x https://example.com/path?q=a^b ok', ['https://example.com/path?q=a^b']],
    'backtick' => ['x https://example.com/a`b ok', ['https://example.com/a`b']],
    'bare domain with a non-latin path' => ['see ja.wikipedia.org/wiki/東京 now', ['https://ja.wikipedia.org/wiki/東京']],
    // Text glued to a host, or to a path that ends mid-word, stays text.
    'text glued to a host' => ['https://example.comです', ['https://example.com']],
    'text glued to a path' => ['詳しくはhttps://example.com/news/123をご覧ください', ['https://example.com/news/123']],
]);

it('covers the whole URL with the link facet, whatever the script of its path', function () {
    $built = (new BlueskyRichText)->build('Read https://ja.wikipedia.org/wiki/東京 now');

    expect(bskyFacetMap($built))->toBe([
        ['https://ja.wikipedia.org/wiki/東京', ['$type' => BlueskyRichText::LINK, 'uri' => 'https://ja.wikipedia.org/wiki/東京']],
    ]);
});

it('shortens links like the Bluesky app and keeps the full URL in the facet', function () {
    $text = 'Read https://example.com/2026/11/a-very-long-article-slug?utm_source=x now';
    $built = (new BlueskyRichText)->build($text, new BlueskyOptions(shortenLinks: true));

    expect($built['text'])->toBe('Read example.com/2026/11/a-ve... now')
        ->and(bskyFacetMap($built))->toBe([
            ['example.com/2026/11/a-ve...', ['$type' => BlueskyRichText::LINK, 'uri' => 'https://example.com/2026/11/a-very-long-article-slug?utm_source=x']],
        ])
        ->and(BlueskyRichText::displayText($text, true))->toBe($built['text'])
        ->and(BlueskyRichText::displayText($text))->toBe($text);
});

it('shortens with the official rules', function (string $url, string $short) {
    expect(BlueskyRichText::shortUrl($url))->toBe($short);
})->with([
    ['https://example.com/', 'example.com'],
    ['https://example.com', 'example.com'],
    ['https://example.com/short/path', 'example.com/short/path'],
    ['https://example.com:8080/abcdefghijklmnop', 'example.com:8080/abcdefghijkl...'],
    ['https://example.com/abcdefghijklmn', 'example.com/abcdefghijklmn'],
    ['https://example.com/?q=1#top', 'example.com?q=1#top'],
    ['ftp://example.com/x', 'ftp://example.com/x'],
]);

it('keeps link facets when shortening without detection', function () {
    $built = (new BlueskyRichText)->build('Go https://example.com/a/very/long/path/indeed #tag', new BlueskyOptions(detectFacets: false, shortenLinks: true));

    expect(bskyFacetMap($built))->toBe([
        ['example.com/a/very/long/...', ['$type' => BlueskyRichText::LINK, 'uri' => 'https://example.com/a/very/long/path/indeed']],
    ]);
});

it('detects nothing when detection is off, but keeps raw facets', function () {
    $raw = ['index' => ['byteStart' => 0, 'byteEnd' => 2], 'features' => [['$type' => BlueskyRichText::TAG, 'tag' => 'Hi']]];
    $built = (new BlueskyRichText)->build('Hi #tag https://example.com', new BlueskyOptions(detectFacets: false, facets: [$raw]));

    expect($built['facets'])->toBe([$raw]);
});

it('validates, sorts and de-overlaps raw facets', function () {
    $tag = fn (int $s, int $e) => ['index' => ['byteStart' => $s, 'byteEnd' => $e], 'features' => [['$type' => BlueskyRichText::TAG, 'tag' => 't']]];

    $built = (new BlueskyRichText)->build('0123456789', new BlueskyOptions(detectFacets: false, facets: [
        $tag(6, 8),
        $tag(0, 4),
        $tag(2, 5),           // overlaps 0..4
        $tag(0, 2),           // same start, shorter
        $tag(8, 11),          // past the end
        $tag(5, 5),           // empty
        ['index' => ['byteStart' => '1', 'byteEnd' => 3], 'features' => [['x' => 1]]],
        ['index' => ['byteStart' => 1, 'byteEnd' => 3], 'features' => []],
        'garbage',
    ]));

    expect(array_column($built['facets'], 'index'))->toBe([
        ['byteStart' => 0, 'byteEnd' => 4],
        ['byteStart' => 6, 'byteEnd' => 8],
    ]);
});

it('ignores raw facets for comments', function () {
    $raw = ['index' => ['byteStart' => 0, 'byteEnd' => 2], 'features' => [['$type' => BlueskyRichText::TAG, 'tag' => 'Hi']]];

    expect((new BlueskyRichText)->build('Hi', new BlueskyOptions(facets: [$raw]), false)['facets'])->toBe([]);
});

it('resolves every handle in one concurrent batch and caches the answers', function () {
    Http::fake(['public.api.bsky.app/*' => function (Request $request) {
        return match ($request['handle']) {
            'alice.bsky.social' => Http::response(['did' => 'did:plc:alice']),
            'bob.example.com' => Http::response(['did' => 'did:web:bob.example.com']),
            'gone.bsky.social' => Http::response(['error' => 'InvalidRequest', 'message' => 'Unable to resolve handle'], 400),
            default => Http::response(['did' => 'not-a-did']),
        };
    }]);

    $rich = new BlueskyRichText;
    $text = '@Alice.bsky.social (@bob.example.com) @gone.bsky.social @weird.example.org @alice.bsky.social';

    $first = $rich->build($text);
    $second = $rich->build($text);

    expect(array_column(array_column(array_column($first['facets'], 'features'), 0), 'did'))->toBe(['did:plc:alice', 'did:web:bob.example.com', 'did:plc:alice'])
        ->and($second['facets'])->toBe($first['facets'])
        // alice, bob, gone and weird in the first batch; the second build only asks for 'weird' again,
        // whose answer was not a DID and so was not cached ('gone' is cached as a miss).
        ->and(bskySent('resolveHandle'))->toHaveCount(5)
        ->and(bskySent('resolveHandle')[4]['handle'])->toBe('weird.example.org');

    expect(Cache::get('social-poster:bsky-handle:alice.bsky.social'))->toBe('did:plc:alice')
        ->and(Cache::has('social-poster:bsky-handle:gone.bsky.social'))->toBeTrue()
        ->and(Cache::has('social-poster:bsky-handle:weird.example.org'))->toBeFalse();
});

it('skips the lookup for handles mapped in the options', function () {
    Http::fake();

    $built = (new BlueskyRichText)->build('Hi @Alice.bsky.social', new BlueskyOptions(mentions: ['@alice.bsky.social' => 'did:plc:mapped']));

    expect($built['facets'][0]['features'][0])->toBe(['$type' => BlueskyRichText::MENTION, 'did' => 'did:plc:mapped']);
    Http::assertNothingSent();
});

it('leaves mentions as text when the AppView is unreachable', function () {
    Http::fake(['public.api.bsky.app/*' => bskyConnectionFailure()]);

    expect((new BlueskyRichText)->build('Hi @alice.bsky.social')['facets'])->toBe([]);
});

it('does not treat emails or words as mentions', function () {
    Http::fake();

    expect((new BlueskyRichText)->build('mail bob@example.com or @nodot')['facets'])->toBe([]);
    Http::assertNothingSent();
});

it('uses the configured AppView', function () {
    Http::fake(['appview.example.com/*' => Http::response(['did' => 'did:plc:x'])]);

    (new BlueskyRichText('https://appview.example.com/', 2))->build('@a.example.com');

    expect(bskySent('appview.example.com')[0]->url())->toBe('https://appview.example.com/xrpc/com.atproto.identity.resolveHandle?handle=a.example.com');
});

it('scrubs invalid UTF-8 so offsets stay valid', function () {
    $built = (new BlueskyRichText)->build("bad \xC3( #tag");

    expect(mb_check_encoding($built['text'], 'UTF-8'))->toBeTrue()
        ->and(bskyFacetMap($built))->toBe([['#tag', ['$type' => BlueskyRichText::TAG, 'tag' => 'tag']]]);
});

it('counts tag limits in graphemes and bytes', function () {
    expect(BlueskyRichText::isTag(str_repeat('👨‍👩‍👧‍👦', 25)))->toBeTrue()   // 25 graphemes, 625 bytes
        ->and(BlueskyRichText::isTag(str_repeat('👨‍👩‍👧‍👦', 26)))->toBeFalse()  // 650 bytes
        ->and(BlueskyRichText::isTag('...'))->toBeFalse()
        ->and(BlueskyRichText::isTag(''))->toBeFalse();
});
