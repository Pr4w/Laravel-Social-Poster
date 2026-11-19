<?php

namespace SocialPoster\Capabilities;

use InvalidArgumentException;
use SocialPoster\Events\CommentSkipped;
use SocialPoster\Support\Text\CaptionCounter;
use SocialPoster\Support\Text\MbStrlenCounter;
use SocialPoster\ValueObjects\Credentials;

/**
 * What a platform accepts as a first comment on a given post, returned by
 * SupportsFirstComment::commentRules().
 *
 * - $max / $counter / $maxBytes: the length limit, counted the way the platform
 *   counts it (X weighted, Bluesky graphemes plus a byte cap, ...).
 * - $minDelaySeconds: how long after the post the comment may be sent at the
 *   earliest (LinkedIn needs time before a new post accepts comments). The job
 *   waits max(config comment_delay, this).
 * - $requiredScopes: CNF scope groups, e.g. [['pages_manage_engagement'],
 *   ['pages_read_user_content']]: every group must be granted, each by any one
 *   of its members.
 * - $unknownScopePolicy: what to do when the credentials do not say which
 *   scopes were granted: 'attempt' (call the platform, report CommentFailed on
 *   refusal) or 'skip' (report CommentSkipped without calling it).
 */
final class CommentRules
{
    public const ATTEMPT = 'attempt';

    public const SKIP = 'skip';

    public readonly CaptionCounter $counter;

    /** @var list<list<string>> */
    public readonly array $requiredScopes;

    /**
     * @param  array<int, string|array<int, string>>  $requiredScopes  CNF groups; a bare string is a one-member group.
     *
     * @throws InvalidArgumentException On a negative limit or an unknown policy.
     */
    public function __construct(
        public readonly int $max,
        ?CaptionCounter $counter = null,
        public readonly int $minDelaySeconds = 0,
        array $requiredScopes = [],
        public readonly string $unknownScopePolicy = self::ATTEMPT,
        public readonly ?int $maxBytes = null,
    ) {
        if ($max < 0 || $minDelaySeconds < 0 || ($maxBytes !== null && $maxBytes < 0)) {
            throw new InvalidArgumentException('CommentRules limits and delays cannot be negative.');
        }

        if (! in_array($unknownScopePolicy, [self::ATTEMPT, self::SKIP], true)) {
            throw new InvalidArgumentException("CommentRules unknownScopePolicy must be 'attempt' or 'skip', got '{$unknownScopePolicy}'.");
        }

        $this->counter = $counter ?? new MbStrlenCounter;
        $this->requiredScopes = Credentials::normaliseScopeGroups($requiredScopes);
    }

    /** The length of $text as the platform counts it (after trimming). */
    public function length(string $text): int
    {
        return $this->counter->count(trim($text));
    }

    /** True when $text is not blank and fits the character and byte limits. */
    public function fits(string $text): bool
    {
        return $this->errors($text) === [];
    }

    /**
     * Human-readable reasons $text cannot be posted as this comment (empty when
     * it can).
     *
     * @return list<string>
     */
    public function errors(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return ['The comment is empty.'];
        }

        $errors = [];
        $length = $this->counter->count($text);

        if ($length > $this->max) {
            $errors[] = "The comment is {$length} characters long; the limit is {$this->max} ({$this->counter->describe()}).";
        }

        if ($this->maxBytes !== null && strlen($text) > $this->maxBytes) {
            $errors[] = 'The comment is '.strlen($text)." bytes long; the limit is {$this->maxBytes} bytes.";
        }

        return $errors;
    }

    /** The delay to wait before commenting, given the configured default. */
    public function delay(int $configuredSeconds): int
    {
        return max($this->minDelaySeconds, $configuredSeconds, 0);
    }

    /**
     * Required scope groups the credentials are known not to grant. Null when
     * the granted scopes are unknown (no 'scopes' credential).
     *
     * @return list<list<string>>|null
     */
    public function missingScopes(Credentials $credentials): ?array
    {
        if ($this->requiredScopes === []) {
            return $credentials->scopes() === null ? null : [];
        }

        return $credentials->missingScopes($this->requiredScopes);
    }

    /**
     * Why the comment step should be skipped without calling the platform, as a
     * CommentSkipped reason, or null to attempt it. Known-missing scopes always
     * skip; unknown scopes skip only under the 'skip' policy. Both report
     * CommentSkipped::REASON_MISSING_SCOPE.
     */
    public function skipReason(Credentials $credentials): ?string
    {
        if ($this->requiredScopes === []) {
            return null;
        }

        $missing = $credentials->missingScopes($this->requiredScopes);

        if ($missing === null) {
            return $this->unknownScopePolicy === self::SKIP ? CommentSkipped::REASON_MISSING_SCOPE : null;
        }

        return $missing === [] ? null : CommentSkipped::REASON_MISSING_SCOPE;
    }
}
