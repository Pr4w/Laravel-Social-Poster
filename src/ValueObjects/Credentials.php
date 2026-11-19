<?php

namespace SocialPoster\ValueObjects;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;

/**
 * An opaque bag of credentials. The package never manages token lifecycle:
 * it uses whatever it is given, and surfaces a PermanentException if the
 * platform rejects them. Refresh and persistence are the consumer's concern.
 *
 * To keep tokens out of queue payloads, dispatch a reference instead
 * (Credentials::ref('social_account:42', ['account_id' => '1784...'])) and bind
 * a CredentialsResolver (config social.credentials_resolver) that turns it
 * into real credentials each time a job runs. The resolver may also refresh.
 *
 * Optional, well-known keys the package reads:
 *
 * - `expires_at`: when the token expires (unix seconds or milliseconds, an
 *   ISO-8601 string, or a DateTimeInterface). Drivers refuse to spend a quota
 *   costing call on a token about to expire (AbstractPlatform::assertTokenFresh()).
 * - `scopes`: the permissions actually granted (a list, or a space/comma
 *   separated string). When present, validation and the comment step check
 *   them against the driver's requiredScopes(); when absent they are unknown.
 *
 * Redaction of token-like values (see redacted()):
 *
 * - var_dump() and print_r() show only the redacted copy (__debugInfo()).
 * - json_encode() returns the redacted copy (jsonSerialize()), which covers
 *   Monolog log contexts, Telescope job entries and most error trackers,
 *   since they normalise objects through JSON. To persist credentials as
 *   JSON, encode $credentials->values, not the object.
 * - Symfony VarDumper (Laravel's dump()/dd(), Telescope's dump watcher) and
 *   var_export() read the public $values property as well, so they DO show the
 *   raw tokens: dump $credentials->redacted() instead.
 * - serialize() (queue payloads) keeps the real values; dispatch a ref() to
 *   keep tokens out of the queue.
 */
final class Credentials implements JsonSerializable
{
    /** The key ref() stores the connection reference under. */
    public const REFERENCE_KEY = '_ref';

    /** Placeholder shown instead of a secret value. */
    public const REDACTED = '[redacted]';

    /** Key fragments that mark a value as secret (case-insensitive). */
    private const SECRET_PATTERN = '/token|secret|password|passwd|jwt|authorization|cookie|signature|private_?key|api_?key|access_?key|client_?key|session/i';

    /** @param array<string, mixed> $values */
    public function __construct(
        #[\SensitiveParameter]
        public readonly array $values = [],
    ) {}

    /** @param array<string, mixed> $values */
    public static function make(#[\SensitiveParameter] array $values): self
    {
        return new self($values);
    }

    /**
     * Credentials that carry only a reference to a stored connection, plus
     * non-secret identifiers (account_id, author, did, board_id...). A bound
     * CredentialsResolver turns it into real credentials when the job runs; with
     * the default identity resolver a reference fails as Misconfigured.
     *
     * @param  array<string, mixed>  $nonSecret  Identifiers only; a token-like key is refused.
     *
     * @throws InvalidArgumentException When $nonSecret holds a token-like key.
     */
    public static function ref(string $connectionKey, array $nonSecret = []): self
    {
        if (trim($connectionKey) === '') {
            throw new InvalidArgumentException('Credentials::ref() needs a non-empty connection key.');
        }

        foreach (array_keys($nonSecret) as $key) {
            if (self::isSecretKey((string) $key)) {
                throw new InvalidArgumentException("Credentials::ref() carries non-secret identifiers only; [{$key}] looks like a secret. Return it from your CredentialsResolver instead.");
            }
        }

        return new self([self::REFERENCE_KEY => $connectionKey] + $nonSecret);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /** True when the key holds a usable value (not null, '', whitespace or []). */
    public function filled(string $key): bool
    {
        $value = $this->values[$key] ?? null;

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return $value !== null && $value !== [];
    }

    /**
     * A copy with $values merged in (new keys win). Handy in a resolver that
     * swaps a reference for a fresh token while keeping the identifiers.
     *
     * @param  array<string, mixed>  $values
     */
    public function with(#[\SensitiveParameter] array $values): self
    {
        return new self(array_replace($this->values, $values));
    }

    /** True when these credentials were built by ref() and still need resolving. */
    public function isReference(): bool
    {
        return $this->filled(self::REFERENCE_KEY);
    }

    /** The connection reference passed to ref(), if any. */
    public function reference(): ?string
    {
        $reference = $this->values[self::REFERENCE_KEY] ?? null;

        return is_scalar($reference) && trim((string) $reference) !== '' ? (string) $reference : null;
    }

    /**
     * When the token expires, read from 'expires_at'. Accepts unix seconds or
     * milliseconds, a numeric string, an ISO-8601/RFC date string or a
     * DateTimeInterface. Null when absent or unreadable (expiry unknown).
     */
    public function expiresAt(): ?DateTimeImmutable
    {
        $value = $this->values['expires_at'] ?? null;

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
            $timestamp = (float) $value;

            if ($timestamp >= 1_000_000_000_000) {
                $timestamp /= 1000;
            }

            if ($timestamp <= 0) {
                return null;
            }

            return (new DateTimeImmutable('@'.(int) $timestamp))->setTimezone(new DateTimeZone('UTC'));
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return new DateTimeImmutable(trim($value));
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /** True when the expiry is known and falls within $seconds from now (or has passed). */
    public function expiresWithin(int $seconds, ?int $now = null): bool
    {
        $expiresAt = $this->expiresAt();

        return $expiresAt !== null && $expiresAt->getTimestamp() <= ($now ?? time()) + $seconds;
    }

    /**
     * The granted scopes from the 'scopes' key, or null when unknown (absent).
     *
     * @return list<string>|null
     */
    public function scopes(): ?array
    {
        if (! array_key_exists('scopes', $this->values) || $this->values['scopes'] === null) {
            return null;
        }

        $raw = $this->values['scopes'];
        $items = is_array($raw) ? $raw : preg_split('/[\s,]+/', (string) (is_scalar($raw) ? $raw : ''));
        $scopes = [];

        foreach ((array) $items as $scope) {
            if (is_scalar($scope) && trim((string) $scope) !== '') {
                $scopes[] = trim((string) $scope);
            }
        }

        return array_values(array_unique($scopes));
    }

    /**
     * Which any-of scope groups the granted scopes do not satisfy. $required is
     * in conjunctive normal form: every group must be satisfied, each by any one
     * of its members, e.g. [['pages_manage_engagement'], ['pages_read_user_content']].
     * Returns null when the granted scopes are unknown (no 'scopes' key).
     *
     * @param  array<int, string|array<int, string>>  $required
     * @return list<list<string>>|null
     */
    public function missingScopes(array $required): ?array
    {
        $granted = $this->scopes();

        if ($granted === null) {
            return null;
        }

        $missing = [];

        foreach (self::normaliseScopeGroups($required) as $group) {
            if (array_intersect($group, $granted) === []) {
                $missing[] = $group;
            }
        }

        return $missing;
    }

    /**
     * Normalise a CNF scope list: a bare string becomes a one-member group,
     * blanks and empty groups are dropped.
     *
     * @param  array<int, string|array<int, string>>  $groups
     * @return list<list<string>>
     */
    public static function normaliseScopeGroups(array $groups): array
    {
        $normalised = [];

        foreach ($groups as $group) {
            $members = [];

            foreach ((array) $group as $scope) {
                if (is_scalar($scope) && trim((string) $scope) !== '') {
                    $members[] = trim((string) $scope);
                }
            }

            if ($members !== []) {
                $normalised[] = array_values(array_unique($members));
            }
        }

        return $normalised;
    }

    /**
     * The values with every token-like entry replaced by a placeholder: safe to
     * log or dump.
     *
     * @return array<string, mixed>
     */
    public function redacted(): array
    {
        $redacted = [];

        foreach ($this->values as $key => $value) {
            $redacted[$key] = self::isSecretKey((string) $key) && $value !== null && $value !== ''
                ? self::REDACTED
                : $value;
        }

        return $redacted;
    }

    /** True when a credential key names a secret (token, password, private key...). */
    public static function isSecretKey(string $key): bool
    {
        return (bool) preg_match(self::SECRET_PATTERN, $key);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['values' => $this->redacted()];
    }

    /**
     * JSON never carries a token: log contexts and error trackers encode
     * objects this way. Encode $values yourself to persist credentials.
     *
     * @return array{values: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return ['values' => $this->redacted()];
    }
}
