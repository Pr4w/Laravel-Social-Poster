<?php

use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Support\Text\GraphemeCounter;
use SocialPoster\Support\Text\MbStrlenCounter;
use SocialPoster\Support\Text\Text;
use SocialPoster\Support\Text\ThreadsCounter;
use SocialPoster\Support\Text\Utf16Counter;

it('counts code points by default', function () {
    $counter = new MbStrlenCounter;

    expect($counter->count('abc'))->toBe(3)
        ->and($counter->count('😀'))->toBe(1)
        ->and($counter->count('👨‍👩‍👧‍👦'))->toBe(7);
});

it('counts UTF-16 code units for TikTok', function (string $text, int $expected) {
    expect((new Utf16Counter)->count($text))->toBe($expected);
})->with([
    ['abc', 3],
    ['é', 1],
    ['日本', 2],
    ['😀', 2],
    ['👨‍👩‍👧‍👦', 11],
]);

it('counts graphemes for Bluesky', function (string $text, int $expected) {
    expect((new GraphemeCounter)->count($text))->toBe($expected);
})->with([
    ['abc', 3],
    ["e\u{301}", 1],
    ['👨‍👩‍👧‍👦', 1],
    ['🇫🇷🇺🇸', 2],
    ['👍🏽', 1],
    ['', 0],
]);

it('counts Threads emoji by their UTF-8 bytes', function (string $text, int $expected) {
    expect((new ThreadsCounter)->count($text))->toBe($expected);
})->with([
    'plain text' => ['Hello world', 11],
    'CJK counts per code point' => ['日本語', 3],
    'single emoji' => ['😀', 4],
    'flag (regional indicators)' => ['🇫🇷', 8],
    'keycap' => ['1️⃣', 7],
    'heart with variation selector' => ['❤️', 6],
    'skin tone' => ['👍🏽', 8],
    'family ZWJ sequence' => ['👨‍👩‍👧‍👦', 25],
    'verifier example' => ['Hi 🇫🇷 ❤️ 👍🏽 é', 29],
    // Dingbats that are not emoji (Emoji=No, not Extended_Pictographic) count as one character.
    'check mark is not an emoji' => ['✓', 1],
    'white star is not an emoji' => ['☆', 1],
    'circled digit is not an emoji' => ['❶', 1],
    'heavy arrow is not an emoji' => ['➔', 1],
    'heavy check mark is an emoji' => ['✔', 3],
    'heavy check mark with variation selector' => ['✔️', 6],
    'bullet list of check marks' => [str_repeat("✓ done\n", 3), 21],
]);

it('treats exactly the Extended_Pictographic code points as pictographs', function () {
    $mismatches = [];

    for ($codePoint = 0x2000; $codePoint <= 0x2BFF; $codePoint++) {
        $char = mb_chr($codePoint, 'UTF-8');
        $ours = preg_match('/^['.Text::PICTOGRAPHIC.']$/u', $char) === 1;
        $unicode = preg_match('/^\p{Extended_Pictographic}$/u', $char) === 1;

        if ($ours !== $unicode) {
            $mismatches[] = sprintf('U+%04X', $codePoint);
        }
    }

    expect($mismatches)->toBe([]);
})->skip(@preg_match('/\p{Extended_Pictographic}/u', 'a') === false, 'PCRE2 10.40+ is required for \p{Extended_Pictographic}');

it('never counts Threads text below mb_strlen, so CaptionRules(max: 500) stays a valid pre-check', function (string $text) {
    expect((new ThreadsCounter)->count($text))->toBeGreaterThanOrEqual(mb_strlen($text));
})->with([
    ['नमस्ते दुनिया'],
    ['สวัสดีครับ'],
    ["e\u{301}e\u{301}"],
    ['Mixed 🇫🇷 नमस्ते 👨‍👩‍👧‍👦'],
]);

it('never throws on invalid UTF-8', function (object $counter) {
    expect($counter->count("\xff\xfe\x80 abc"))->toBeInt();
})->with([
    fn () => new MbStrlenCounter,
    fn () => new Utf16Counter,
    fn () => new GraphemeCounter,
    fn () => new ThreadsCounter,
]);

it('defaults CaptionRules to the v1.0 counter and keeps positional arguments', function () {
    $rules = new CaptionRules(5, 100);

    expect($rules->min)->toBe(5)
        ->and($rules->max)->toBe(100)
        ->and($rules->counter)->toBeInstanceOf(MbStrlenCounter::class)
        ->and($rules->maxBytes)->toBeNull();
});

it('applies the Bluesky grapheme and byte limits together', function () {
    $rules = new CaptionRules(max: 300, counter: new GraphemeCounter, maxBytes: 3000);
    $families = str_repeat('👨‍👩‍👧‍👦', 300); // 300 graphemes, 7500 bytes

    expect($rules->length($families))->toBe(300)
        ->and($rules->fits($families))->toBeFalse()
        ->and($rules->fits(str_repeat('👨‍👩‍👧‍👦', 100)))->toBeTrue();
});

it('trims before counting', function () {
    $rules = new CaptionRules(max: 5);

    expect($rules->length("  hello \n"))->toBe(5)
        ->and($rules->fits("  hello \n"))->toBeTrue();
});
