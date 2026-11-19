<?php

use SocialPoster\Support\Text\Urls;

it('extracts links as written, with twitter-text trimming', function (string $text, array $expected) {
    expect(Urls::extract($text))->toBe($expected);
})->with([
    'scheme URL' => ['see https://example.com/a?b=1 now', ['https://example.com/a?b=1']],
    'bare and www domains' => ['example.com and www.example.org/x', ['example.com', 'www.example.org/x']],
    'trailing punctuation' => ['Go: https://example.com/path. Or example.com!', ['https://example.com/path', 'example.com']],
    'balanced parens kept, closing paren dropped' => ['(https://en.wikipedia.org/wiki/Foo_(bar))', ['https://en.wikipedia.org/wiki/Foo_(bar)']],
    'quotes' => ['"https://example.com/q"', ['https://example.com/q']],
    'no links in emails or mentions' => ['mail me@example.com or @someone', []],
    'no links in file names' => ['open notes.txt', []],
    'port' => ['https://example.com:8443/x', ['https://example.com:8443/x']],
    'IDN with scheme' => ['https://münchen.de/karte', ['https://münchen.de/karte']],
    'duplicates kept' => ['a.com a.com', ['a.com', 'a.com']],
]);

/*
 * twitter-text conformance (conformance/extract.yml): only ASCII letters, digits,
 * @, $ and # may not precede a link, so links glued to CJK text are found.
 */
it('finds links glued to CJK text like twitter-text', function (string $text, array $expected) {
    expect(Urls::extract($text))->toBe($expected);
})->with([
    'long URL without protocol surrounded by CJK characters' => [
        'これは日本語です。example.com/path/index.html中国語example.com/path한국',
        ['example.com/path/index.html', 'example.com/path'],
    ],
    'short URL without protocol surrounded by CJK characters' => [
        'twitter.comこれは日本語です。example.com中国語t.co/abcde한국twitter.com example2.comテストtwitter.com/abcde',
        ['twitter.com', 'example.com', 't.co/abcde', 'twitter.com', 'example2.com', 'twitter.com/abcde'],
    ],
    'URLs with and without protocol surrounded by CJK characters' => [
        'http://twitter.com/これは日本語です。example.com中国語http://t.co/abcde한국twitter.comテストexample2.comテストhttp://twitter.com/abcde',
        ['http://twitter.com/', 'example.com', 'http://t.co/abcde', 'twitter.com', 'example2.com', 'http://twitter.com/abcde'],
    ],
    'domain followed by Japanese characters' => [
        'example.comてすとですtwitter.みんなです',
        ['example.com', 'twitter.みんな'],
    ],
    'short URLs without protocol on ccTLD domains without path' => [
        'twitter.jp日本語it.so中国語foo.jp it.so foo.jp',
        ['twitter.jp', 'it.so', 'foo.jp', 'it.so', 'foo.jp'],
    ],
    'scheme URL glued after Japanese text' => [
        str_repeat('あ', 3).'詳しくはこちらhttps://example.com/aaa',
        ['https://example.com/aaa'],
    ],
]);

it('ends a link at its top-level domain when text is glued after it', function (string $text, array $expected) {
    expect(Urls::extract($text))->toBe($expected);
})->with([
    'Japanese after a scheme URL' => ['詳細 https://example.comです', ['https://example.com']],
    'sentence glued after a period' => ['Visit https://example.com.Thanks', ['https://example.com']],
    'bare domain with a sentence glued after a period' => ['Visit example.com.Thanks', ['example.com']],
    'a rare gTLD with a path is kept' => ['see https://my.site.photography/gallery', ['https://my.site.photography/gallery']],
    'a ccTLD second level is kept' => ['https://www.example.co.uk.', ['https://www.example.co.uk']],
    'IDN with a scheme is kept' => ['https://例え.テスト/a', ['https://例え.テスト/a']],
]);

it('scans hostile input in linear time', function (string $text) {
    $started = microtime(true);
    Urls::extract($text);
    $elapsed = microtime(true) - $started;

    expect($elapsed)->toBeLessThan(1.0);
})->with([
    'dotted letters (60 KB)' => [str_repeat('a.', 30000)],
    'dotted digits (60 KB)' => [str_repeat('1.', 30000)],
    'underscores (60 KB)' => [str_repeat('a_', 30000)],
    'hyphens then a dot (40 KB)' => [str_repeat('a-', 20000).'a.'],
    'long labels then a dot' => [str_repeat('aaaa.1', 10000)],
    'a path ending in 60 KB of dots' => ['https://example.com/'.str_repeat('.', 60000)],
    'unbalanced parentheses at the end of a path' => ['https://example.com/a'.str_repeat(')', 30000)],
]);

it('returns byte offsets for facets', function () {
    $text = 'Héllo 👋 https://example.com/x end';
    $entity = Urls::entities($text)[0];

    expect($entity['url'])->toBe('https://example.com/x')
        ->and(substr($text, $entity['start'], $entity['end'] - $entity['start']))->toBe('https://example.com/x')
        ->and($entity['scheme'])->toBeTrue();
});

it('can require a scheme', function () {
    expect(Urls::extract('example.com https://b.example.org', schemeOnly: true))->toBe(['https://b.example.org'])
        ->and(Urls::first('x example.com y'))->toBe('example.com')
        ->and(Urls::first('nothing here'))->toBeNull();
});

it('makes bare domains absolute and counts unique links', function () {
    expect(Urls::absolute('example.com/a'))->toBe('https://example.com/a')
        ->and(Urls::absolute('http://example.com'))->toBe('http://example.com')
        ->and(Urls::unique('https://Example.com/ example.com https://b.com'))->toBe(['https://Example.com/', 'https://b.com']);
});
