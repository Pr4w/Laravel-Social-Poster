<?php

namespace SocialPoster\Contracts;

use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\ValueObjects\Credentials;

/**
 * Supplies the credentials a job should use at the moment it runs. The queued
 * jobs call it at the start of every run (first attempt, release, continuation,
 * first comment), so a long wait never replays a token that expired meanwhile,
 * and the queue payload can carry only a reference (Credentials::ref()).
 *
 * Bind your implementation through config('social.credentials_resolver'). The
 * default (IdentityCredentialsResolver) returns the dispatched credentials
 * unchanged. The package still never refreshes or stores tokens itself: your
 * resolver looks them up, and may refresh them (e.g. with laravel-social-tokens).
 *
 * When a custom resolver is bound, a rejected (non-terminal) token is retried
 * once with re-resolved credentials before it is reported as InvalidToken.
 */
interface CredentialsResolver
{
    /**
     * @param  Credentials  $dispatched  What was dispatched: a full bag or a ref() reference.
     * @param  array<string, mixed>  $metadata  The caller's correlation data from ->withMetadata().
     *
     * @throws SocialPosterException PermanentException when the connection is gone, TemporaryException when the token store is unreachable.
     */
    public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials;
}
