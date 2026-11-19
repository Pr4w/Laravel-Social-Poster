<?php

namespace SocialPoster\Platforms\Bluesky;

use InvalidArgumentException;

/**
 * AT Protocol timestamp identifiers (TIDs), the record keys of
 * app.bsky.feed.post. A TID is a 64-bit integer (top bit 0, then 53 bits of
 * microseconds since the epoch, then a 10-bit clock id) written as 13
 * characters of base32-sortable ('234567abcdefghijklmnopqrstuvwxyz').
 *
 * The driver picks the record key itself instead of letting the PDS choose:
 * with the key fixed before the create call, a lost response is checked with
 * getRecord, and a repeated create can never produce a second post.
 *
 * @see https://atproto.com/specs/tid
 */
final class Tid
{
    public const ALPHABET = '234567abcdefghijklmnopqrstuvwxyz';

    /** Syntax check from the spec: 13 characters, the first one limits the top bit to 0. */
    public const PATTERN = '/^[234567abcdefghij][234567abcdefghijklmnopqrstuvwxyz]{12}$/';

    /** Largest timestamp a TID can hold (53 bits of microseconds). */
    private const MAX_MICROS = (1 << 53) - 1;

    private static int $lastMicros = 0;

    private static ?int $clockId = null;

    /**
     * A new TID for now. Monotonic within the process (two calls in the same
     * microsecond still differ), with a random clock id so two workers do not
     * collide.
     */
    public static function next(): string
    {
        $micros = (int) floor(microtime(true) * 1_000_000);

        if ($micros <= self::$lastMicros) {
            $micros = self::$lastMicros + 1;
        }

        self::$lastMicros = $micros;
        self::$clockId ??= random_int(0, 1023);

        return self::fromParts($micros, self::$clockId);
    }

    /** The TID for a given microsecond timestamp and clock id. */
    public static function fromParts(int $micros, int $clockId = 0): string
    {
        if ($micros < 0 || $micros > self::MAX_MICROS) {
            throw new InvalidArgumentException('A TID timestamp must fit in 53 bits of microseconds.');
        }

        $value = ($micros << 10) | ($clockId & 0x3FF);
        $tid = '';

        for ($i = 0; $i < 13; $i++) {
            $tid = self::ALPHABET[$value & 31].$tid;
            $value >>= 5;
        }

        return $tid;
    }

    public static function isValid(string $tid): bool
    {
        return preg_match(self::PATTERN, $tid) === 1;
    }

    /**
     * The timestamp (microseconds) and clock id of a TID, or null when it is
     * not one.
     *
     * @return array{micros: int, clock_id: int}|null
     */
    public static function parse(string $tid): ?array
    {
        if (! self::isValid($tid)) {
            return null;
        }

        $value = 0;

        foreach (str_split($tid) as $char) {
            $value = ($value << 5) | (int) strpos(self::ALPHABET, $char);
        }

        // The syntax admits a first character up to 'j', but the top bit must be 0.
        if ($value < 0) {
            return null;
        }

        return ['micros' => $value >> 10, 'clock_id' => $value & 0x3FF];
    }

    /**
     * A deterministic TID placed shortly after $anchor (another TID), derived
     * from $seed: the same anchor and seed always give the same key. Used for
     * the first-comment reply, so a retried comment job targets the same record
     * key and cannot reply twice. The offset is $minOffsetMicros plus up to one
     * second taken from the seed, and the clock id comes from the seed too.
     * Null when $anchor is not a TID.
     */
    public static function derive(string $anchor, string $seed, int $minOffsetMicros = 1_000_000): ?string
    {
        $parts = self::parse($anchor);

        if ($parts === null) {
            return null;
        }

        $hash = unpack('N2', hash('sha256', $seed, true));
        $offset = max(1, $minOffsetMicros) + ((int) ($hash[1] ?? 0) % 1_000_000);
        $micros = min(self::MAX_MICROS, $parts['micros'] + $offset);

        return self::fromParts($micros, (int) ($hash[2] ?? 0) & 0x3FF);
    }
}
