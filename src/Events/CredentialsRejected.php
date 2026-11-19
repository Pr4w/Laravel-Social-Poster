<?php

namespace SocialPoster\Events;

use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;

/**
 * The platform refused the account's credentials (expired or revoked token,
 * missing scope). Dispatched by the jobs whenever a failure requires a reconnect,
 * so an app can flag the connection and ask its user to re-authorise.
 *
 * $terminal is true when the credential can never recover on its own (password
 * changed, app removed, consent withdrawn); false when a refresh may still fix it.
 */
class CredentialsRejected
{
    use SerializesForQueue;

    /**
     * @param  string  $accountKey  The driver's stable account identity (see AbstractPlatform::accountKey()); never a token.
     * @param  array<string, mixed>  $metadata  Caller correlation data from ->withMetadata().
     * @param  array<string, mixed>  $errorSummary  SocialPosterException::errorSummary() of the rejection, when known.
     */
    public function __construct(
        public readonly Platform $platform,
        public readonly string $accountKey,
        public readonly FailureReason $reason,
        public readonly bool $terminal = false,
        public readonly array $metadata = [],
        public readonly array $errorSummary = [],
    ) {}

    /** @return array<string, mixed> */
    public function errorSummary(): array
    {
        return $this->errorSummary;
    }
}
