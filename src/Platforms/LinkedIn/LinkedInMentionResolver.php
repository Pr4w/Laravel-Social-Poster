<?php

namespace SocialPoster\Platforms\LinkedIn;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Support\RetryAfter;

/**
 * Looks up the exact LinkedIn name and URN behind a profile or company URL, so a
 * mention links instead of silently rendering as plain text (LinkedIn matches
 * the mention name case-sensitively against the entity's real name).
 *
 *   $resolver = new LinkedInMentionResolver;
 *   $acme = $resolver->organization($token, 'acme');            // GET v2/organizations?q=vanityName
 *   $jane = $resolver->person($token, $orgUrn, 'jane-doe');     // rest/vanityUrl + rest/people
 *   $any  = $resolver->fromUrl($token, 'https://www.linkedin.com/company/acme/');
 *
 * Organizations and schools resolve with any member token. A person resolves
 * only through vanityUrlAsOrganization, which needs an organization the token's
 * member administers.
 *
 * Every method returns null when LinkedIn knows no such entity, and throws the
 * usual Temporary/PermanentException when the call itself fails (bad token,
 * missing permission, throttling), so a caller can tell the two apart. The
 * token is never put in a message or a query string.
 */
final class LinkedInMentionResolver
{
    private const REST = 'https://api.linkedin.com/rest';

    private const V2 = 'https://api.linkedin.com/v2';

    public function __construct(
        private readonly string $version = '202605',
        private readonly int $timeout = 15,
    ) {}

    /**
     * The entity a LinkedIn URL points to: 'person' for /in/{vanity},
     * 'organization' for /company/{vanity} and /school/{vanity}. Null for any
     * other URL.
     *
     * @return array{type: 'person'|'organization', vanity: string}|null
     */
    public static function parseUrl(string $url): ?array
    {
        $pattern = '~^(?:https?://)?(?:[a-z]{2,3}\.|www\.)?linkedin\.com/(in|company|school)/([^/?#\s]+)~i';

        if (preg_match($pattern, trim($url), $match) !== 1) {
            return null;
        }

        $vanity = trim(rawurldecode($match[2]));

        if ($vanity === '') {
            return null;
        }

        return [
            'type' => strtolower($match[1]) === 'in' ? 'person' : 'organization',
            'vanity' => $vanity,
        ];
    }

    /**
     * Resolve a profile, company or school URL. A person URL needs
     * $organizationUrn (an organization the member administers) and returns null
     * without one.
     */
    public function fromUrl(string $token, string $url, ?string $organizationUrn = null): ?LinkedInMention
    {
        $parsed = self::parseUrl($url);

        if ($parsed === null) {
            return null;
        }

        if ($parsed['type'] === 'organization') {
            return $this->organization($token, $parsed['vanity']);
        }

        return $organizationUrn !== null && trim($organizationUrn) !== ''
            ? $this->person($token, $organizationUrn, $parsed['vanity'])
            : null;
    }

    /** An organization or school by its vanity name (linkedin.com/company/{vanity}). */
    public function organization(string $token, string $vanity): ?LinkedInMention
    {
        $response = $this->request($token)->get(self::V2.'/organizations', [
            'q' => 'vanityName',
            'vanityName' => $vanity,
        ]);

        if (! $response->successful()) {
            return $this->notFoundOrThrow($response);
        }

        $id = $response->json('elements.0.id');
        $name = $response->json('elements.0.localizedName');

        if (! is_scalar($id) || trim((string) $id) === '' || ! is_string($name) || trim($name) === '') {
            return null;
        }

        return new LinkedInMention('urn:li:organization:'.trim((string) $id), trim($name));
    }

    /**
     * A member by their profile vanity (linkedin.com/in/{vanity}), looked up
     * through an organization the token's member administers.
     */
    public function person(string $token, string $organizationUrn, string $vanity): ?LinkedInMention
    {
        $response = $this->request($token)->get(self::REST.'/vanityUrl', [
            'q' => 'vanityUrlAsOrganization',
            'vanityUrl' => 'https://www.linkedin.com/in/'.$vanity,
            'organization' => trim($organizationUrn),
        ]);

        if (! $response->successful()) {
            return $this->notFoundOrThrow($response);
        }

        $member = $response->json('elements.0.member');

        if (! is_string($member) || preg_match('/^urn:li:person:([A-Za-z0-9_-]+)$/', trim($member), $match) !== 1) {
            return null;
        }

        $profile = $this->request($token)->get(self::REST.'/people/(id:'.$match[1].')');

        if (! $profile->successful()) {
            return $this->notFoundOrThrow($profile);
        }

        $name = trim(implode(' ', array_filter([
            is_string($profile->json('localizedFirstName')) ? trim($profile->json('localizedFirstName')) : '',
            is_string($profile->json('localizedLastName')) ? trim($profile->json('localizedLastName')) : '',
        ])));

        return $name !== '' ? new LinkedInMention(trim($member), $name) : null;
    }

    private function request(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->withHeaders([
                'X-Restli-Protocol-Version' => '2.0.0',
                'LinkedIn-Version' => $this->version,
            ])
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(max(1, $this->timeout));
    }

    /**
     * Null for "no such entity" (404); an exception for every other failure.
     *
     * @throws PermanentException|TemporaryException
     */
    private function notFoundOrThrow(Response $response): null
    {
        $status = $response->status();

        if ($status === 404) {
            return null;
        }

        $message = is_string($response->json('message')) ? $response->json('message') : "HTTP {$status}";
        $context = ['http_status' => $status, 'code' => $response->json('code'), 'operation' => 'resolve_mention'];

        throw match (true) {
            $status === 401 => new PermanentException("LinkedIn rejected the token while resolving a mention: {$message}", Platform::LinkedIn, FailureReason::InvalidToken, $context),
            $status === 403 => new PermanentException("LinkedIn refused the mention lookup: {$message}", Platform::LinkedIn, FailureReason::InsufficientPermissions, $context),
            $status === 429 => new TemporaryException("LinkedIn throttled the mention lookup: {$message}", Platform::LinkedIn, FailureReason::RateLimited, RetryAfter::fromResponse($response) ?? 60, $context),
            $status >= 500 => new TemporaryException("LinkedIn could not resolve the mention right now: {$message}", Platform::LinkedIn, FailureReason::ServerError, RetryAfter::fromResponse($response), $context),
            default => new PermanentException("LinkedIn could not resolve the mention: {$message}", Platform::LinkedIn, FailureReason::Unknown, $context),
        };
    }
}
