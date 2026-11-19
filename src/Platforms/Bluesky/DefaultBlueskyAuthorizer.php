<?php

namespace SocialPoster\Platforms\Bluesky;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SocialPoster\Contracts\AuthorizesRequests;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\SocialManager;
use SocialPoster\Support\DPoP;
use SocialPoster\Support\RetryAfter;
use SocialPoster\ValueObjects\Credentials;
use Throwable;

/**
 * The Bluesky request authorizer the driver uses when config
 * ('social.authorizers.bluesky') names none. It picks the mode from the
 * credentials, in this order:
 *
 * 1. OAuth DPoP: 'access_token' + 'dpop_private_key' (PEM, or a P-256 JWK).
 *    Every request carries a DPoP proof and `Authorization: DPoP <token>`; the
 *    server nonce is cached per origin and a use_dpop_nonce refusal is resent
 *    once (Support\DPoP).
 * 2. Bearer: 'access_jwt' (a createSession accessJwt), or an 'access_token'
 *    without a DPoP key. A token whose 'token_type' is DPoP but that comes
 *    without its key is refused up front (Misconfigured): sent as a bearer it
 *    would only earn a 401 that looks like a revoked connection.
 * 3. App password (opt-in, config social.api.bluesky.app_password_mode):
 *    'identifier' + 'app_password' are exchanged for a session with
 *    com.atproto.server.createSession on the account's PDS (or the entryway).
 *    The session is cached (createSession is limited to 30 per 5 minutes and
 *    300 per day), and an ExpiredToken answer drops it and logs in once more.
 *
 * Apps that keep tokens elsewhere (e.g. laravel-social-tokens, whose
 * authorizedRequestFor() renews them on demand) bind their own
 * AuthorizesRequests in config('social.authorizers.bluesky') instead.
 */
class DefaultBlueskyAuthorizer implements AuthorizesRequests
{
    public const SESSION_CACHE_PREFIX = 'social-poster:bsky-session:';

    public function request(Platform $platform, Credentials $credentials): PendingRequest
    {
        $key = $credentials->get('dpop_private_key');
        $accessToken = $this->string($credentials, 'access_token');
        $accessJwt = $this->string($credentials, 'access_jwt');

        if ((is_string($key) && trim($key) !== '') || (is_array($key) && $key !== [])) {
            if ($accessToken === null) {
                throw $this->misconfigured('The Bluesky credentials carry a dpop_private_key but no access_token to bind it to.', ['missing_credentials' => ['access_token']]);
            }

            try {
                $middleware = DPoP::middleware($key, $accessToken);
            } catch (Throwable $e) {
                throw $this->misconfigured('The Bluesky dpop_private_key is not a usable P-256 private key (PEM, or a JWK with d, x and y).', ['dpop_key_invalid' => true], $e);
            }

            return Http::acceptJson()->withMiddleware($middleware);
        }

        if ($accessJwt !== null) {
            return Http::acceptJson()->withToken($accessJwt);
        }

        if ($accessToken !== null) {
            if (strtolower((string) $this->string($credentials, 'token_type')) === 'dpop') {
                throw $this->misconfigured(
                    'This Bluesky access token is DPoP-bound (token_type DPoP), so it only works with the private key it was issued for: pass it as dpop_private_key, or bind a request authorizer in config(\'social.authorizers.bluesky\').',
                    ['missing_credentials' => ['dpop_private_key']],
                );
            }

            return Http::acceptJson()->withToken($accessToken);
        }

        if ($this->string($credentials, 'app_password') !== null) {
            return $this->appPasswordRequest($credentials);
        }

        throw $this->misconfigured(
            'No Bluesky credentials to authorize with: pass access_token (+ dpop_private_key for OAuth DPoP tokens), access_jwt, or identifier + app_password (with app_password_mode enabled).',
            ['missing_credentials' => ['access_token|access_jwt|app_password']],
        );
    }

    // --- App password ---------------------------------------------------------------

    protected function appPasswordRequest(Credentials $credentials): PendingRequest
    {
        if (! filter_var(config('social.api.bluesky.app_password_mode', false), FILTER_VALIDATE_BOOLEAN)) {
            throw $this->misconfigured(
                'Bluesky app passwords are disabled. Prefer OAuth (access_token + dpop_private_key); to use an app password, set config(\'social.api.bluesky.app_password_mode\') to true.',
                ['app_password_mode' => false],
            );
        }

        if ($this->string($credentials, 'identifier') === null) {
            throw $this->misconfigured('A Bluesky app password needs the account identifier (handle, DID or email).', ['missing_credentials' => ['identifier']]);
        }

        $session = $this->session($credentials);

        return Http::acceptJson()
            ->withToken($session['accessJwt'])
            ->withMiddleware($this->renewOnExpiry($credentials));
    }

    /**
     * The cached session for these credentials, creating one when needed.
     *
     * @return array{accessJwt: string, did: ?string}
     */
    public function session(Credentials $credentials, bool $fresh = false): array
    {
        $cacheKey = $this->sessionKey($credentials);

        if (! $fresh) {
            try {
                $cached = Cache::get($cacheKey);
            } catch (Throwable) {
                $cached = null;
            }

            if (is_array($cached) && is_string($cached['accessJwt'] ?? null) && $cached['accessJwt'] !== '') {
                return ['accessJwt' => $cached['accessJwt'], 'did' => is_string($cached['did'] ?? null) ? $cached['did'] : null];
            }
        }

        $host = $this->sessionHost($credentials);
        $response = Http::acceptJson()
            ->connectTimeout(10)
            ->timeout(30)
            ->post($host.'/xrpc/com.atproto.server.createSession', [
                'identifier' => $this->string($credentials, 'identifier'),
                'password' => $this->string($credentials, 'app_password'),
            ]);

        if (! $response->successful()) {
            throw $this->sessionFailed($response, $host);
        }

        $jwt = $response->json('accessJwt');

        if (! is_string($jwt) || $jwt === '') {
            throw new TemporaryException('Bluesky createSession returned no accessJwt.', Platform::Bluesky, FailureReason::ServerError, 60);
        }

        $session = ['accessJwt' => $jwt, 'did' => is_string($response->json('did')) ? $response->json('did') : null];

        try {
            Cache::put($cacheKey, $session, max(60, (int) config('social.api.bluesky.session_ttl', 3600)));
        } catch (Throwable) {
            // ignore: the next request logs in again
        }

        return $session;
    }

    public function forgetSession(Credentials $credentials): void
    {
        try {
            Cache::forget($this->sessionKey($credentials));
        } catch (Throwable) {
            // ignore
        }
    }

    /**
     * Guzzle middleware: when the PDS answers ExpiredToken, drop the cached
     * session, log in again and resend the request once.
     */
    protected function renewOnExpiry(Credentials $credentials): callable
    {
        return function (callable $handler) use ($credentials): callable {
            return function (RequestInterface $request, array $options) use ($handler, $credentials): PromiseInterface {
                return $handler($request, $options)->then(function (ResponseInterface $response) use ($handler, $request, $options, $credentials) {
                    if (! $this->isExpiredToken($response)) {
                        return $response;
                    }

                    $body = $request->getBody();

                    if (! $body->isSeekable() && $body->getSize() !== 0) {
                        return $response;
                    }

                    if ($body->isSeekable()) {
                        $body->rewind();
                    }

                    $this->forgetSession($credentials);
                    $session = $this->session($credentials, true);

                    return $handler($request->withHeader('Authorization', 'Bearer '.$session['accessJwt']), $options);
                });
            };
        };
    }

    protected function isExpiredToken(ResponseInterface $response): bool
    {
        if (! in_array($response->getStatusCode(), [400, 401], true)) {
            return false;
        }

        $body = $response->getBody();
        $contents = (string) $body;

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) && ($decoded['error'] ?? null) === 'ExpiredToken';
    }

    /**
     * A refused createSession. Only an authentication refusal (401, the
     * PDS's "Invalid identifier or password") blames the app password, as a
     * terminal InvalidToken; any other 4xx (a wrong URL, a malformed request)
     * is Misconfigured, naming the host, so a URL problem never disables a
     * valid connection. createSession is limited to 30 per 5 minutes and 300
     * per day: a reset beyond max_rate_limit_wait is Permanent with retry_at.
     */
    protected function sessionFailed(Response $response, string $host): TemporaryException|PermanentException
    {
        $status = $response->status();

        try {
            $body = $response->json();
        } catch (Throwable) {
            $body = null;
        }

        $body = is_array($body) ? $body : [];
        $code = is_string($body['error'] ?? null) ? $body['error'] : null;
        $message = is_string($body['message'] ?? null) && trim($body['message']) !== '' ? trim($body['message']) : null;
        $context = ['status' => $status, 'http_status' => $status, 'error' => $code, 'create_session' => true];

        if ($status === 429 || $code === 'RateLimitExceeded') {
            return Xrpc::rateLimited($response, 'Bluesky is limiting logins for this account (createSession)', (int) config('social.api.bluesky.max_rate_limit_wait', 3600), $context);
        }

        if ($status >= 500) {
            return new TemporaryException('Bluesky could not open a session (HTTP '.$status.').', Platform::Bluesky, FailureReason::ServerError, RetryAfter::fromResponse($response) ?? 60, $context);
        }

        if ($code === 'AuthFactorTokenRequired') {
            return new PermanentException(
                'Bluesky asked for a two-factor code: this is the account password, not an app password. Create an app password in Bluesky (Settings → Privacy and security → App passwords).',
                Platform::Bluesky,
                FailureReason::InvalidToken,
                $context + ['terminal' => true],
            );
        }

        // The same account codes the driver maps (createSession is not known to
        // send the Repo* ones, but a suspended repo must never blame the password).
        if (in_array($code, ['AccountTakedown', 'AccountDeactivated', 'RepoTakendown', 'RepoDeactivated', 'RepoSuspended'], true)) {
            return new PermanentException('The Bluesky account is suspended or deactivated'.($message !== null ? " ({$message})" : '').'.', Platform::Bluesky, FailureReason::AccountRestricted, $context);
        }

        if ($status === 401 || in_array($code, ['AuthenticationRequired', 'AuthRequired', 'AuthMissing', 'InvalidToken'], true)) {
            return new PermanentException(
                'Bluesky refused the app password'.($message !== null ? " ({$message})" : '').'; check the identifier and app password.',
                Platform::Bluesky,
                FailureReason::InvalidToken,
                $context + ['terminal' => true],
            );
        }

        $detail = implode(': ', array_filter([$code, $message !== null && $message !== $code ? $message : null]));

        return new PermanentException(
            "Bluesky could not log in at {$host} (HTTP {$status}".($detail !== '' ? ", {$detail}" : '').'); check that the pds / pds_url credential is the PDS origin (e.g. https://bsky.social).',
            Platform::Bluesky,
            FailureReason::Misconfigured,
            $context,
        );
    }

    /**
     * The PDS URL the driver uses (Xrpc::pdsUrl(): http(s), no trailing /xrpc):
     * the 'pds' credential, else the PDS of the 'did' credential's DID document
     * (as the driver resolves it), else the entryway. A self-hosted account
     * known only by its DID must log in at its own PDS: the entryway answers
     * 'Invalid identifier or password' for it.
     */
    protected function sessionHost(Credentials $credentials): string
    {
        $host = Xrpc::pdsUrl($credentials->get('pds'))
            ?? Xrpc::pdsUrl($credentials->get('pds_url'))
            ?? $this->didPds($credentials)
            ?? (string) config('social.api.bluesky.entryway', 'https://bsky.social');

        return rtrim($host, '/');
    }

    /** The PDS of the DID document, or null without a 'did' credential. Resolution errors propagate (a retry beats logging in at the wrong host). */
    protected function didPds(Credentials $credentials): ?string
    {
        if ($this->string($credentials, 'did') === null) {
            return null;
        }

        $driver = app(SocialManager::class)->driver(Platform::Bluesky->value);
        $driver = $driver instanceof BlueskyDriver ? $driver : app(BlueskyDriver::class);

        return Xrpc::pdsUrl($driver->pdsFor($credentials));
    }

    protected function sessionKey(Credentials $credentials): string
    {
        return self::SESSION_CACHE_PREFIX.hash('sha256', $this->sessionHost($credentials).'|'.$this->string($credentials, 'identifier').'|'.$this->string($credentials, 'app_password'));
    }

    // --- Helpers ----------------------------------------------------------------------

    protected function string(Credentials $credentials, string $key): ?string
    {
        $value = $credentials->get($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param  array<string, mixed>  $context */
    protected function misconfigured(string $message, array $context = [], ?Throwable $previous = null): PermanentException
    {
        return new PermanentException($message, Platform::Bluesky, FailureReason::Misconfigured, $context, $previous);
    }
}
