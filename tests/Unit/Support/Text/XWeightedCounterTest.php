<?php

use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Support\Text\XWeightedCounter;

/*
 * Cases follow twitter-text's conformance suite (validate.yml, WeightedTweetsCounterTest
 * and WeightedTweetsWithDiscountedEmojiCounterTest) with the v3 config: scale 100,
 * default weight 200, ranges 0-4351/8192-8205/8208-8223/8242-8247 at 100,
 * transformedURLLength 23, emoji parsing enabled.
 */

it('weighs text like twitter-text v3', function (string $text, int $expected) {
    expect((new XWeightedCounter)->count($text))->toBe($expected);
})->with([
    'empty' => ['', 0],
    'plain ASCII' => ['This is a test.', 15],
    'Latin-1 accents' => ['Café déjà vu', 12],
    'CJK counts 2 per character' => ['日本語', 6],
    'Hangul counts 2' => ['한국어', 6],
    'Devanagari weighs per code point (combining marks included)' => ['नमस्ते', 6],
    'Thai weighs per code point' => ['สวัสดี', 6],
    'typographic punctuation in the light ranges' => ['“hi” — ok…', 11],
    'emoji in and out of the BMP' => ['😷👾😡🔥💩', 10],
    'skin tone and ZWJ sequences are one unit' => ['🙋🏽👨‍🎤', 4],
    'emoji 11 ZWJ with modifiers and FE0F' => ['🦹🏼‍♀️🦸🏼‍♀️', 4],
    'family ZWJ sequence' => ['👨‍👩‍👧‍👦', 2],
    'flags (regional-indicator pairs)' => ['🇯🇵🇺🇸', 4],
    'keycaps' => ['1️⃣#️⃣*️⃣', 6],
    'subdivision flag tag sequence' => ['🏴󠁧󠁢󠁥󠁮󠁧󠁿', 2],
    'copyright with emoji presentation' => ['©️', 2],
    'copyright as plain text' => ['©', 1],
    'a mixed tweet' => ['Hello 世界 https://example.com/x 🎉', 37],
]);

it('counts every URL as 23 characters', function (string $text, int $expected) {
    expect((new XWeightedCounter)->count($text))->toBe($expected);
})->with([
    'long https URL' => ['https://example.com/'.str_repeat('a', 200), 23],
    'short t.co URL' => ['https://t.co/abc', 23],
    'http URL with query' => ['http://example.com/path?q=1&b=2', 23],
    'bare domain' => ['twitter.com', 23],
    'bare domain in a sentence' => ['check twitter.com now', 33],
    'www domain with path' => ['www.example.com/foo', 23],
    'ccTLD bare domain' => ['example.co.uk/page', 23],
    'two bare domains' => ['a.com b.com', 47],
    'trailing period excluded' => ['Visit example.com.', 30],
    'trailing punctuation after a path' => ['Go to https://example.com/path.', 30],
    'trailing ! after a query' => ['https://example.com/?q=1!', 24],
    'quoted URL' => ['"https://example.com"', 25],
    'unbalanced closing paren excluded' => ['https://example.com/path)', 24],
    'Wikipedia-style balanced parens kept' => ['(see https://en.wikipedia.org/wiki/Foo_(bar))', 29],
    // twitter-text: a link may directly follow CJK text (only ASCII alphanumerics, @, $, # block it).
    'URL glued to Japanese text' => [str_repeat('あ', 120).'詳しくはこちら'.'https://example.com/'.str_repeat('a', 40), 277],
    'bare domain glued to Japanese text' => ['これはexample.com/path', 29],
    // ... and ends at a known TLD followed by something that cannot continue it.
    'sentence glued after a scheme URL' => ['Visit https://example.com.Thanks', 36],
    'Japanese glued after a scheme URL' => ['詳細 https://example.comです', 32],
]);

it('does not treat non-links as URLs', function (string $text) {
    expect((new XWeightedCounter)->count($text))->toBe(mb_strlen($text));
})->with([
    'email address' => ['mail user@example.com'],
    'file name with a non-TLD extension' => ['notes.txt'],
    'version number' => ['v1.2.3'],
    'abbreviations' => ['e.g. i.e. Mr.Smith'],
    'cashtag-like' => ['$cash.com'],
    'domain glued after an underscore' => ['foo_bar.com'],
]);

it('normalises to NFC before counting', function () {
    $counter = new XWeightedCounter;

    // e + COMBINING ACUTE becomes é (1); decomposed Hangul jamo become one syllable (2).
    expect($counter->count("e\u{301}"))->toBe(1)
        ->and($counter->count("\u{1100}\u{1161}"))->toBe(2);
})->skip(! class_exists(Normalizer::class), 'ext-intl is required for NFC');

it('accepts a 280-limit post whose long link would fail mb_strlen', function () {
    $text = str_repeat('a', 250).' https://example.com/'.str_repeat('x', 100);
    $rules = new CaptionRules(max: 280, counter: new XWeightedCounter);

    expect(mb_strlen($text))->toBeGreaterThan(280)
        ->and($rules->length($text))->toBe(274)
        ->and($rules->fits($text))->toBeTrue();
});

it('rejects 141 CJK characters (282 weighted) but accepts 140', function () {
    $rules = new CaptionRules(max: 280, counter: new XWeightedCounter);

    expect($rules->fits(str_repeat('あ', 140)))->toBeTrue()
        ->and($rules->fits(str_repeat('あ', 141)))->toBeFalse();
});

it('never throws on invalid UTF-8', function () {
    expect((new XWeightedCounter)->count("\xff\xfe abc"))->toBeInt();
});
