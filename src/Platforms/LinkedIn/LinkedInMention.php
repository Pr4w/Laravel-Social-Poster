<?php

namespace SocialPoster\Platforms\LinkedIn;

/**
 * A LinkedIn entity mention: the display name shown in the post and the URN it
 * links to. The caption references it by its index in LinkedInOptions::$mentions
 * through a {{mention:N}} marker, so the caller writes readable text and never
 * touches LinkedIn's "little text" @[name](urn) syntax by hand.
 */
final class LinkedInMention
{
    public function __construct(
        public readonly string $urn,
        public readonly string $name,
    ) {}

    public static function make(string $urn, string $name): self
    {
        return new self($urn, $name);
    }

    /** LinkedIn only resolves a person or organization URN. */
    public function hasValidUrn(): bool
    {
        return (bool) preg_match('/^urn:li:(person|organization):.+/', $this->urn);
    }
}
