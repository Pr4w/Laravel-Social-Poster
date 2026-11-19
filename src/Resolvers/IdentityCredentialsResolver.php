<?php

namespace SocialPoster\Resolvers;

use Illuminate\Container\Container;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\ValueObjects\Credentials;

/**
 * The default CredentialsResolver: the dispatched credentials are the ones to
 * use, unchanged (the v1.0 behaviour). A Credentials::ref() reference cannot be
 * turned into a token here, so it fails as Misconfigured with a pointer to
 * config('social.credentials_resolver').
 */
class IdentityCredentialsResolver implements CredentialsResolver
{
    public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
    {
        if ($dispatched->isReference()) {
            throw new PermanentException(
                "The {$platform->value} credentials were dispatched as a reference ('{$dispatched->reference()}'), but no CredentialsResolver is bound to turn it into a token. Set config('social.credentials_resolver') to your resolver class.",
                $platform,
                FailureReason::Misconfigured,
                ['credentials_ref' => $dispatched->reference()],
            );
        }

        return $dispatched;
    }

    /**
     * True when the app bound a resolver of its own, i.e. a re-resolve can
     * produce different credentials. Checks the container binding first, then
     * config('social.credentials_resolver'). Only this exact class counts as
     * "no resolver": a subclass (reusing the pass-through and adding reference
     * lookups or refreshes) is the app's own resolver.
     */
    public static function customResolverBound(): bool
    {
        $container = Container::getInstance();

        try {
            if ($container->bound(CredentialsResolver::class)) {
                return get_class($container->make(CredentialsResolver::class)) !== self::class;
            }

            $configured = $container->bound('config')
                ? $container->make('config')->get('social.credentials_resolver')
                : null;
        } catch (\Throwable) {
            return false;
        }

        if (is_object($configured)) {
            return $configured instanceof CredentialsResolver && get_class($configured) !== self::class;
        }

        // Class names are case-insensitive and may carry a leading backslash.
        return is_string($configured) && $configured !== ''
            && strcasecmp(ltrim($configured, '\\'), self::class) !== 0;
    }
}
