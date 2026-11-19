<?php

namespace SocialPoster\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * What a driver returns after adding a first comment: the platform comment id
 * (null when the platform returns none), a public URL when one can be built,
 * and the raw response or extra ids (e.g. the Bluesky reply cid) in $payload.
 * Blank strings are stored as null so a missing id never looks like a real one.
 *
 * @implements Arrayable<string, mixed>
 */
final class CommentResult implements Arrayable, JsonSerializable
{
    public readonly ?string $id;

    public readonly ?string $url;

    /** @param array<string, mixed> $payload */
    public function __construct(
        ?string $id = null,
        ?string $url = null,
        public readonly array $payload = [],
    ) {
        $this->id = self::blankToNull($id);
        $this->url = self::blankToNull($url);
    }

    /** @return array{id: ?string, url: ?string, payload: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'payload' => $this->payload,
        ];
    }

    /** @return array{id: ?string, url: ?string, payload: array<string, mixed>} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
