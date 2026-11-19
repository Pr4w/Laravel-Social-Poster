<?php

namespace SocialPoster\Platforms\LinkedIn;

/**
 * A LinkedIn entity mention: the display name shown in the post and the URN it
 * links to. The caption references it by its index in LinkedInOptions::$mentions
 * through a {{mention:N}} marker, so the caller writes readable text and never
 * touches LinkedIn's "little text" @[name](urn) syntax by hand.
 *
 * The name must match the member's or organization's name on LinkedIn, and the
 * match is case-sensitive: an organization needs its full name ("Acme Inc", not
 * "ACME"); a member may be matched on one of their names. When it does not
 * match, LinkedIn publishes the name as plain text with no link, and says
 * nothing. LinkedInMentionResolver looks the exact name up from a profile or
 * company URL.
 */
final class LinkedInMention
{
    /** Person or organization URN with a plain id (no spaces, parentheses or newlines). */
    private const URN_PATTERN = '/^urn:li:(person|organization):[A-Za-z0-9_-]+\z/';

    public readonly string $urn;

    public function __construct(
        string $urn,
        public readonly string $name,
    ) {
        $this->urn = trim($urn);
    }

    public static function make(string $urn, string $name): self
    {
        return new self($urn, $name);
    }

    /**
     * LinkedIn only resolves a person or organization URN, and the URN is
     * written raw into @[name](urn), so anything beyond a plain id (a ')', a
     * space, a newline) would break the commentary.
     */
    public function hasValidUrn(): bool
    {
        return (bool) preg_match(self::URN_PATTERN, $this->urn);
    }
}
