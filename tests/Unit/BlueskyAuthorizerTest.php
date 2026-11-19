<?php

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\Bluesky\DefaultBlueskyAuthorizer;
use SocialPoster\Support\DPoP;
use SocialPoster\ValueObjects\Credentials;

require_once __DIR__.'/../Fixtures/bluesky/helpers.php';

/** @param array<string, mixed> $credentials */
function bskyAuthorize(array $credentials): PendingRequest
{
    return (new DefaultBlueskyAuthorizer)->request(Platform::Bluesky, new Credentials($credentials));
}

const BSKY_APP_PASSWORD = ['did' => BSKY_DID, 'identifier' => 'posterbot.bsky.social', 'app_password' => 'abcd-efgh-ijkl-mnop', 'pds' => BSKY_PDS];

beforeEach(function () {
    Cache::flush();
});

it('signs with DPoP when a key is present (PEM or JWK)', function () {
    $pem = DPoP::generateKey();
    $jwk = DPoP::publicJwk($pem) + ['d' => DPoP::base64url((string) openssl_pkey_get_details(openssl_pkey_get_private($pem))['ec']['d'])];

    Http::fake(['*' => Http::response(['ok' => true])]);

    bskyAuthorize(['access_token' => 'oauth-1', 'dpop_private_key' => $pem])->get(BSKY_PDS.'/xrpc/a.b.c?x=1');
    bskyAuthorize(['access_token' => 'oauth-2', 'dpop_private_key' => $jwk, 'access_jwt' => 'ignored'])->get(BSKY_PDS.'/xrpc/a.b.c');

    $requests = bskySent('a.b.c');

    expect($requests[0]->header('Authorization'))->toBe(['DPoP oauth-1'])
        ->and(DPoP::verify($requests[0]->header('DPoP')[0]))->toBeTrue()
        ->and($requests[0]->header('Accept'))->toBe(['application/json'])
        ->and($requests[1]->header('Authorization'))->toBe(['DPoP oauth-2'])
        ->and(json_decode(DPoP::base64urlDecode(explode('.', $requests[1]->header('DPoP')[0])[0]), true)['jwk'])->toBe(DPoP::publicJwk($pem));
});

it('refuses unusable DPoP setups as misconfigured', function (array $credentials, string $message) {
    $e = bskyCatch(fn () => bskyAuthorize($credentials));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Misconfigured)
        ->and($e->platform)->toBe(Platform::Bluesky)
        ->and($e->getMessage())->toContain($message);
})->with([
    'key without token' => [['dpop_private_key' => "-----BEGIN EC PRIVATE KEY-----\nx\n-----END EC PRIVATE KEY-----"], 'no access_token'],
    'broken key' => [['access_token' => 't', 'dpop_private_key' => 'not a key'], 'not a usable P-256'],
    'DPoP token without key' => [['access_token' => 't', 'token_type' => 'DPoP'], 'DPoP-bound'],
    'nothing at all' => [['did' => BSKY_DID], 'No Bluesky credentials'],
    'app password off' => [BSKY_APP_PASSWORD, 'app_password_mode'],
]);

it('sends bearer tokens for sessions and plain access tokens', function () {
    Http::fake(['*' => Http::response([])]);

    bskyAuthorize(['access_jwt' => 'session-jwt', 'access_token' => 'other'])->get('https://pds.example.com/a');
    bskyAuthorize(['access_token' => 'plain', 'token_type' => 'Bearer'])->get('https://pds.example.com/b');

    expect(bskySent('example.com/a')[0]->header('Authorization'))->toBe(['Bearer session-jwt'])
        ->and(bskySent('example.com/a')[0]->hasHeader('DPoP'))->toBeFalse()
        ->and(bskySent('example.com/b')[0]->header('Authorization'))->toBe(['Bearer plain']);
});

it('logs in with an app password once and reuses the cached session', function () {
    config(['social.api.bluesky.app_password_mode' => true]);
    Http::fake([
        '*/xrpc/com.atproto.server.createSession' => Http::response(['accessJwt' => 'jwt-1', 'refreshJwt' => 'r', 'did' => BSKY_DID, 'handle' => 'posterbot.bsky.social', 'active' => true]),
        '*/xrpc/app.test' => Http::response([]),
    ]);

    bskyAuthorize(BSKY_APP_PASSWORD)->get(BSKY_PDS.'/xrpc/app.test');
    bskyAuthorize(BSKY_APP_PASSWORD)->get(BSKY_PDS.'/xrpc/app.test');

    $login = bskySent('createSession');

    expect($login)->toHaveCount(1)
        ->and($login[0]->url())->toBe(BSKY_PDS.'/xrpc/com.atproto.server.createSession')
        ->and($login[0]->data())->toBe(['identifier' => 'posterbot.bsky.social', 'password' => 'abcd-efgh-ijkl-mnop'])
        ->and(array_map(fn (Request $r) => $r->header('Authorization')[0], bskySent('app.test')))->toBe(['Bearer jwt-1', 'Bearer jwt-1']);

    // Cached under a hash of host, identifier and password, never the password itself.
    expect(Cache::has(DefaultBlueskyAuthorizer::SESSION_CACHE_PREFIX.hash('sha256', BSKY_PDS.'|posterbot.bsky.social|abcd-efgh-ijkl-mnop')))->toBeTrue();
});

it('logs in again once when the session expired, resending the body', function () {
    config(['social.api.bluesky.app_password_mode' => true]);
    Http::fake([
        '*/xrpc/com.atproto.server.createSession' => Http::sequence()
            ->push(['accessJwt' => 'old-jwt', 'did' => BSKY_DID])
            ->push(['accessJwt' => 'new-jwt', 'did' => BSKY_DID]),
        '*/xrpc/com.atproto.repo.createRecord' => function (Request $request) {
            return $request->header('Authorization')[0] === 'Bearer old-jwt'
                ? Http::response(['error' => 'ExpiredToken', 'message' => 'Token has expired'], 400)
                : Http::response(['uri' => 'at://x', 'cid' => 'c']);
        },
    ]);

    $response = bskyAuthorize(BSKY_APP_PASSWORD)->post(BSKY_PDS.'/xrpc/com.atproto.repo.createRecord', ['repo' => BSKY_DID]);
    $creates = bskySent('createRecord');

    expect($response->json('uri'))->toBe('at://x')
        ->and(bskySent('createSession'))->toHaveCount(2)
        ->and($creates)->toHaveCount(2)
        ->and($creates[1]->header('Authorization'))->toBe(['Bearer new-jwt'])
        ->and($creates[1]->data())->toBe(['repo' => BSKY_DID]);
});

it('uses the entryway to log in when no PDS is known', function () {
    config(['social.api.bluesky.app_password_mode' => true]);
    Http::fake(['bsky.social/xrpc/com.atproto.server.createSession' => Http::response(['accessJwt' => 'j'])]);

    bskyAuthorize(['identifier' => 'a.bsky.social', 'app_password' => 'p']);

    expect(bskySent('createSession')[0]->url())->toBe('https://bsky.social/xrpc/com.atproto.server.createSession');
});

it('logs in at the PDS URL the driver uses', function (array $credentials, string $url) {
    config(['social.api.bluesky.app_password_mode' => true]);
    Http::fake(['*/xrpc/com.atproto.server.createSession' => Http::response(['accessJwt' => 'j'])]);

    bskyAuthorize($credentials + ['identifier' => 'a.example.com', 'app_password' => 'p']);

    expect(bskySent('createSession')[0]->url())->toBe($url);
})->with([
    'pds_url with a trailing /xrpc' => [['pds_url' => 'https://pds.example.com/xrpc'], 'https://pds.example.com/xrpc/com.atproto.server.createSession'],
    'pds with a port and /xrpc/' => [['pds' => 'https://pds.example.com:2583/xrpc/'], 'https://pds.example.com:2583/xrpc/com.atproto.server.createSession'],
    'pds without a scheme, pds_url with one' => [['pds' => 'pds.example.com', 'pds_url' => 'https://other.example.com'], 'https://other.example.com/xrpc/com.atproto.server.createSession'],
    'no usable URL: the entryway' => [['pds' => 'pds.example.com'], 'https://bsky.social/xrpc/com.atproto.server.createSession'],
]);

it('reports a login limit that resets hours away as permanent, with a retry hint', function () {
    config(['social.api.bluesky.app_password_mode' => true]);
    Http::fake(['*/xrpc/com.atproto.server.createSession' => Http::response(['error' => 'RateLimitExceeded', 'message' => 'Rate Limit Exceeded'], 429, [
        'ratelimit-limit' => '300', 'ratelimit-remaining' => '0', 'ratelimit-reset' => (string) (time() + 20 * 3600), 'ratelimit-policy' => '300;w=86400',
    ])]);

    $e = bskyCatch(fn () => bskyAuthorize(BSKY_APP_PASSWORD));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::RateLimited)
        ->and($e->context['daily_limit'])->toBeTrue()
        ->and($e->context['retry_after_hint'])->toBeGreaterThanOrEqual(71998)->toBeLessThanOrEqual(72000)
        ->and($e->context['retry_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

it('classifies login failures', function (int $status, array $body, string $class, FailureReason $reason, bool $terminal) {
    config(['social.api.bluesky.app_password_mode' => true]);
    Http::fake(['*/xrpc/com.atproto.server.createSession' => Http::response($body, $status)]);

    $e = bskyCatch(fn () => bskyAuthorize(BSKY_APP_PASSWORD));

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason)
        ->and($e->isTerminal())->toBe($terminal)
        ->and($e->getMessage())->not->toContain('abcd-efgh-ijkl-mnop');
})->with([
    'wrong password' => [401, ['error' => 'AuthenticationRequired', 'message' => 'Invalid identifier or password'], PermanentException::class, FailureReason::InvalidToken, true],
    'main password with 2FA' => [401, ['error' => 'AuthFactorTokenRequired', 'message' => 'A sign in code has been sent to your email address'], PermanentException::class, FailureReason::InvalidToken, true],
    'takedown' => [400, ['error' => 'AccountTakedown', 'message' => 'Account has been taken down'], PermanentException::class, FailureReason::AccountRestricted, false],
    'takedown as a 401' => [401, ['error' => 'AccountTakedown', 'message' => 'Account has been taken down'], PermanentException::class, FailureReason::AccountRestricted, false],
    'suspended repo as a 401' => [401, ['error' => 'RepoSuspended', 'message' => 'Repo has been suspended'], PermanentException::class, FailureReason::AccountRestricted, false],
    'login rate limit' => [429, ['error' => 'RateLimitExceeded', 'message' => 'Rate Limit Exceeded'], TemporaryException::class, FailureReason::RateLimited, false],
    'server error' => [502, ['error' => 'UpstreamFailure'], TemporaryException::class, FailureReason::ServerError, false],
    // Not a verdict on the password: a wrong URL or a malformed request.
    'wrong URL (404)' => [404, ['error' => 'XRPCNotSupported', 'message' => 'XRPCNotSupported'], PermanentException::class, FailureReason::Misconfigured, false],
    'invalid request' => [400, ['error' => 'InvalidRequest', 'message' => 'Input must have the property "identifier"'], PermanentException::class, FailureReason::Misconfigured, false],
]);

it('does not blame the app password for a login sent to the wrong place', function () {
    config(['social.api.bluesky.app_password_mode' => true]);
    Http::fake(['*/xrpc/com.atproto.server.createSession' => Http::response(['error' => 'XRPCNotSupported', 'message' => 'XRPCNotSupported'], 404)]);

    $e = bskyCatch(fn () => bskyAuthorize(BSKY_APP_PASSWORD));

    expect($e->getMessage())->not->toContain('check the identifier and app password')
        ->and($e->getMessage())->toContain(BSKY_PDS)
        ->and($e->reason->requiresReconnect())->toBeFalse();
});

it('retries a login that returned no token, and needs an identifier', function () {
    config(['social.api.bluesky.app_password_mode' => true]);
    Http::fake(['*/xrpc/com.atproto.server.createSession' => Http::response(['did' => BSKY_DID])]);

    $noToken = bskyCatch(fn () => bskyAuthorize(BSKY_APP_PASSWORD));
    $noIdentifier = bskyCatch(fn () => bskyAuthorize(['app_password' => 'p']));

    expect($noToken)->toBeInstanceOf(TemporaryException::class)
        ->and($noIdentifier->reason)->toBe(FailureReason::Misconfigured)
        ->and($noIdentifier->context['missing_credentials'])->toBe(['identifier']);
});

it('creates an app-password session on the PDS of the DID document when no pds credential is given', function () {
    config(['social.api.bluesky.app_password_mode' => true]);
    Http::fake([
        'https://plc.directory/*' => Http::response(['id' => BSKY_DID, 'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.selfhosted.example']]]),
        'https://pds.selfhosted.example/xrpc/com.atproto.server.createSession' => Http::response(['accessJwt' => 'jwt-self', 'refreshJwt' => 'r', 'did' => BSKY_DID, 'handle' => 'me.selfhosted.example', 'active' => true]),
        'https://pds.selfhosted.example/xrpc/app.test' => Http::response([]),
    ]);

    $credentials = ['did' => BSKY_DID, 'identifier' => 'me.selfhosted.example', 'app_password' => 'abcd-efgh-ijkl-mnop'];
    bskyAuthorize($credentials)->get('https://pds.selfhosted.example/xrpc/app.test');

    expect(bskySent('bsky.social'))->toBe([])
        ->and(bskySent('pds.selfhosted.example/xrpc/com.atproto.server.createSession'))->toHaveCount(1)
        ->and(bskySent('app.test')[0]->header('Authorization'))->toBe(['Bearer jwt-self']);
});
