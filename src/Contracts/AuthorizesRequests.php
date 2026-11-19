<?php

namespace SocialPoster\Contracts;

use Illuminate\Http\Client\PendingRequest;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\ValueObjects\Credentials;

/**
 * Builds the authorized HTTP client for one platform when a plain
 * `Authorization: Bearer <token>` is not enough: DPoP-bound OAuth (Bluesky),
 * signed requests, or a consumer's own client factory (e.g. laravel-social-tokens'
 * authorizedRequestFor()).
 *
 * Register one per platform in config('social.authorizers'), keyed by the
 * platform value: ['bluesky' => DefaultBlueskyAuthorizer::class]. The class is
 * resolved from the container. AbstractPlatform::http() then starts every
 * request from request() instead of Http::withToken(), and applies the package's
 * connect/read timeouts on top (drivers still add their base URL, headers and
 * longer upload timeouts).
 */
interface AuthorizesRequests
{
    /**
     * A PendingRequest that already carries the platform authorization.
     *
     * @throws SocialPosterException When the credentials cannot authorize a request.
     */
    public function request(Platform $platform, Credentials $credentials): PendingRequest;
}
