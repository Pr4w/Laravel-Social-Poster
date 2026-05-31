<?php

namespace SocialPoster\ValueObjects;

/**
 * An opaque bag of credentials. The package never manages token lifecycle:
 * it uses whatever it is given, and surfaces a PermanentException if the
 * platform rejects them. Refresh and persistence are the consumer's concern.
 */
final class Credentials
{
    /** @param array<string, mixed> $values */
    public function __construct(
        public readonly array $values = [],
    ) {}

    public static function make(array $values): self
    {
        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }
}
