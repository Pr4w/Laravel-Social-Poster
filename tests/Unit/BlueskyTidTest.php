<?php

use SocialPoster\Platforms\Bluesky\Tid;

it('generates valid, unique, sortable TIDs', function () {
    $tids = [];

    for ($i = 0; $i < 2000; $i++) {
        $tids[] = Tid::next();
    }

    $sorted = $tids;
    sort($sorted, SORT_STRING);

    expect(array_unique($tids))->toHaveCount(2000)
        ->and($sorted)->toBe($tids)
        ->and(array_filter($tids, fn ($tid) => ! Tid::isValid($tid)))->toBe([]);
});

it('encodes the current time in microseconds', function () {
    $parts = Tid::parse(Tid::next());

    expect($parts['micros'])->toBeGreaterThan((time() - 5) * 1_000_000)
        ->and($parts['micros'])->toBeLessThanOrEqual((time() + 5) * 1_000_000)
        ->and($parts['clock_id'])->toBeBetween(0, 1023);
});

it('round-trips parts and decodes the spec example', function () {
    $tid = Tid::fromParts(1_700_000_000_123_456, 42);

    expect($tid)->toHaveLength(13)
        ->and(Tid::parse($tid))->toBe(['micros' => 1_700_000_000_123_456, 'clock_id' => 42])
        ->and(Tid::fromParts(0, 0))->toBe('2222222222222')
        // atproto.com/specs/tid example: a TID from 2023.
        ->and(gmdate('Y', intdiv(Tid::parse('3jzfcijpj2z2a')['micros'], 1_000_000)))->toBe('2023');
});

it('validates the syntax', function (string $tid, bool $valid) {
    expect(Tid::isValid($tid))->toBe($valid);
})->with([
    ['3jzfcijpj2z2a', true],
    ['7777777777777', true],
    ['3jzfcijpj2z21', false],     // '1' is not in the alphabet
    ['3jzfcijpj2z2', false],      // 12 characters
    ['kjzfcijpj2z2a', false],     // high bit set
    ['3JZFCIJPJ2Z2A', false],
    ['', false],
]);

it('refuses a top bit it cannot hold, and out-of-range parts', function () {
    expect(Tid::parse('jzzzzzzzzzzzz'))->toBeNull()
        ->and(Tid::parse('nope'))->toBeNull()
        ->and(fn () => Tid::fromParts(-1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Tid::fromParts(1 << 53))->toThrow(InvalidArgumentException::class);
});

it('derives a deterministic key shortly after its anchor', function () {
    $anchor = Tid::fromParts(1_763_460_000_000_000, 7);

    $a = Tid::derive($anchor, 'first-comment|at://x|Hello');
    $b = Tid::derive($anchor, 'first-comment|at://x|Hello');
    $c = Tid::derive($anchor, 'first-comment|at://x|Other');

    $delta = Tid::parse($a)['micros'] - Tid::parse($anchor)['micros'];

    expect($a)->toBe($b)->not->toBe($c)
        ->and(Tid::isValid($a))->toBeTrue()
        ->and(strcmp($a, $anchor))->toBeGreaterThan(0)
        ->and($delta)->toBeGreaterThanOrEqual(1_000_000)->toBeLessThan(2_000_000)
        ->and(Tid::derive('not-a-tid', 'x'))->toBeNull();
});
