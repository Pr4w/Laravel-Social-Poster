<?php

namespace SocialPoster\Tests\Live\Bluesky;

use Illuminate\Support\Facades\Http;
use SocialPoster\Facades\SocialPoster;
use SocialPoster\Tests\Live\Live;

/*
 * Bluesky live checks. Authenticate with ACCESS_JWT (a session JWT) or with
 * IDENTIFIER + APP_PASSWORD (the app-password mode is switched on for these
 * tests only). PDS is resolved from the DID when unset. The publishing check
 * posts and deletes the record.
 */

const REQUIRED = ['DID'];

$env = Live::env('BLUESKY', REQUIRED, ['PDS', 'ACCESS_JWT', 'IDENTIFIER', 'APP_PASSWORD']);

if ($env === null || ($env['ACCESS_JWT'] === null && ($env['IDENTIFIER'] === null || $env['APP_PASSWORD'] === null))) {
    it('runs the Bluesky live checks')->skip(Live::skipMessage('BLUESKY', ['DID', 'ACCESS_JWT (or IDENTIFIER + APP_PASSWORD)']));

    return;
}

/** The PDS from the env, else from the DID document. */
function pds(array $env): string
{
    if ($env['PDS'] !== null) {
        return rtrim($env['PDS'], '/');
    }

    $document = Http::get('https://plc.directory/'.$env['DID'])->json();

    foreach ((array) ($document['service'] ?? []) as $service) {
        if (($service['id'] ?? null) === '#atproto_pds') {
            return rtrim((string) $service['serviceEndpoint'], '/');
        }
    }

    throw new \RuntimeException('No #atproto_pds service in the DID document.');
}

/** @return array<string, string> */
function credentials(array $env): array
{
    return array_filter([
        'did' => $env['DID'],
        'pds' => pds($env),
        'access_jwt' => $env['ACCESS_JWT'],
        'identifier' => $env['ACCESS_JWT'] === null ? $env['IDENTIFIER'] : null,
        'app_password' => $env['ACCESS_JWT'] === null ? $env['APP_PASSWORD'] : null,
    ]);
}

beforeEach(function () {
    Live::boot();
    config(['social.api.bluesky.app_password_mode' => true]);
});

it('resolves the PDS from plc.directory with the raw did:plc path', function () use ($env) {
    if (! str_starts_with($env['DID'], 'did:plc:')) {
        test()->markTestSkipped('The DID is not a did:plc.');
    }

    $response = Http::get('https://plc.directory/'.$env['DID']);

    Live::record('BLUESKY', 'plc.directory', $response);

    expect($response->status())->toBe(200)
        ->and(collect($response->json('service'))->firstWhere('id', '#atproto_pds'))->not->toBeNull();
});

it('reads a record of the account through the public AppView getRecord', function () use ($env) {
    $response = Http::get('https://public.api.bsky.app/xrpc/com.atproto.repo.getRecord', [
        'repo' => $env['DID'],
        'collection' => 'app.bsky.actor.profile',
        'rkey' => 'self',
    ]);

    Live::record('BLUESKY', 'appview getRecord', $response);

    expect($response->status())->toBeIn([200, 400]);
});

it('records the app-password session lifetime', function () use ($env) {
    if ($env['IDENTIFIER'] === null || $env['APP_PASSWORD'] === null) {
        test()->markTestSkipped('Set SOCIAL_LIVE_BLUESKY_IDENTIFIER and SOCIAL_LIVE_BLUESKY_APP_PASSWORD.');
    }

    $response = Http::post(pds($env).'/xrpc/com.atproto.server.createSession', [
        'identifier' => $env['IDENTIFIER'],
        'password' => $env['APP_PASSWORD'],
    ]);

    $jwt = (string) $response->json('accessJwt');
    $claims = json_decode((string) base64_decode(strtr(explode('.', $jwt.'..')[1], '-_', '+/')), true);

    Live::record('BLUESKY', 'createSession on the PDS', ['status' => $response->status(), 'lifetime_seconds' => isset($claims['exp'], $claims['iat']) ? $claims['exp'] - $claims['iat'] : null]);

    expect($response->status())->toBe(200);
});

it('posts, records the error for a second create on the same rkey, then deletes the record', function () use ($env) {
    Live::requirePublishing();

    $result = SocialPoster::on('bluesky')->using(credentials($env))->caption(Live::caption())->postNow()['bluesky'];

    Live::record('BLUESKY', 'text publish', $result);

    expect($result->success)->toBeTrue();

    $rkey = basename((string) $result->platformPostId);
    $token = $env['ACCESS_JWT'];

    if ($token !== null) {
        $again = Http::withToken($token)->post(pds($env).'/xrpc/com.atproto.repo.createRecord', [
            'repo' => $env['DID'],
            'collection' => 'app.bsky.feed.post',
            'rkey' => $rkey,
            'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'duplicate rkey check', 'createdAt' => gmdate('Y-m-d\TH:i:s.v\Z')],
        ]);

        Live::record('BLUESKY', 'createRecord on an existing rkey', $again);

        Live::record('BLUESKY', 'delete', Http::withToken($token)->post(pds($env).'/xrpc/com.atproto.repo.deleteRecord', [
            'repo' => $env['DID'],
            'collection' => 'app.bsky.feed.post',
            'rkey' => $rkey,
        ]));
    }
});

foreach ([
    'BlobNotFound name and message for a garbage-collected temp blob',
    'DPoP against a bsky.social PDS: use_dpop_nonce, retry, per-origin nonce reuse, token on the resolved PDS',
    'getServiceAuth with an OAuth token for uploadBlob and getUploadLimits',
    'video service status codes for an unverified email and the daily quota; already_exists status',
    'deterministic reply rkey (TID 1-2 s after the parent) accepted and indexed',
    'video alt text over 1000 graphemes, text/vtt captions, presentation gif, threadgate/postgate via applyWrites',
    'current limits: 300 MB / 10 min video, 2 MB images; self-hosted PDS behaviour',
    'ExpiredToken re-login path for app-password sessions',
    'link cards on real sites: charset, og:image:secure_url, CDN redirects',
] as $check) {
    it("[manual] {$check}")->skip('Manual check: see tests/Live/README.md (Bluesky).');
}
