<?php

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SocialPoster\Support\DPoP;

/**
 * RFC 7515 Appendix A.3 (ES256): the private JWK, the signing input and the
 * signature exactly as published.
 */
const DPOP_RFC7515_JWK = [
    'kty' => 'EC',
    'crv' => 'P-256',
    'x' => 'f83OJ3D2xF1Bg8vub9tLe1gHMzV76e8Tus9uPHvRVEU',
    'y' => 'x_FEzRu9m36HLN_tue659LNpXW6pCyStikYjKIWI5a0',
    'd' => 'jpsQnnGQmL-YBIffH1136cspYG6-0iY7X1fCE9-E9LI',
];

const DPOP_RFC7515_INPUT = 'eyJhbGciOiJFUzI1NiJ9.eyJpc3MiOiJqb2UiLA0KICJleHAiOjEzMDA4MTkzODAsDQogImh0dHA6Ly9leGFtcGxlLmNvbS9pc19yb290Ijp0cnVlfQ';

const DPOP_RFC7515_SIGNATURE = 'DtEhU3ljbEg8L38VWAfUAqOyKAM6-Xx-F4GawxaepmXFCgfTjDxw5djxLa8ISlSApmWQxfKTUJqPP3-Kg6NU1Q';

/** RFC 9449 §4.1, Figure 4: a DPoP proof as published (public key in its header). */
const DPOP_RFC9449_PROOF = 'eyJ0eXAiOiJkcG9wK2p3dCIsImFsZyI6IkVTMjU2IiwiandrIjp7Imt0eSI6IkVDIiwieCI6Imw4dEZyaHgtMzR0VjNoUklDUkRZOXpDa0RscEJoRjQyVVFVZldWQVdCRnMiLCJ5IjoiOVZFNGpmX09rX282NHpiVFRsY3VOSmFqSG10NnY5VERWclUwQ2R2R1JEQSIsImNydiI6IlAtMjU2In19.eyJqdGkiOiItQndDM0VTYzZhY2MybFRjIiwiaHRtIjoiUE9TVCIsImh0dSI6Imh0dHBzOi8vc2VydmVyLmV4YW1wbGUuY29tL3Rva2VuIiwiaWF0IjoxNTYyMjYyNjE2fQ.2-GxA6T8lP4vfrg8v-FdWP0A0zdrj8igiMLvqRMUvwnQg4PtFLbdLXiOSsX0x7NVY-FNyJK70nfbV37xRZT3Lg';

/** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string} header, claims, signing input, raw signature */
function dpopDecode(string $jws): array
{
    [$header, $payload, $signature] = explode('.', $jws);

    return [
        json_decode(DPoP::base64urlDecode($header), true),
        json_decode(DPoP::base64urlDecode($payload), true),
        $header.'.'.$payload,
        DPoP::base64urlDecode($signature),
    ];
}

/** Verify independently of DPoP::verify(): r || s back to DER, then openssl_verify(). */
function dpopOpensslVerifies(string $jws, array $jwk): bool
{
    [, , $input, $raw] = dpopDecode($jws);

    return openssl_verify($input, DPoP::joseToDer($raw), DPoP::publicKeyPem($jwk), OPENSSL_ALGO_SHA256) === 1;
}

/** A DER ECDSA signature built by hand from two big-endian integers. */
function dpopDer(string $r, string $s): string
{
    $int = fn (string $v) => "\x02".chr(strlen($v)).$v;
    $body = $int($r).$int($s);

    return "\x30".chr(strlen($body)).$body;
}

beforeEach(function () {
    Cache::flush();
});

// --- Test vectors -----------------------------------------------------------------

it('verifies the RFC 7515 A.3 ES256 signature after converting r||s back to DER', function () {
    $raw = DPoP::base64urlDecode(DPOP_RFC7515_SIGNATURE);
    $public = array_intersect_key(DPOP_RFC7515_JWK, array_flip(['kty', 'crv', 'x', 'y']));

    expect(strlen($raw))->toBe(64)
        ->and(openssl_verify(DPOP_RFC7515_INPUT, DPoP::joseToDer($raw), DPoP::publicKeyPem($public), OPENSSL_ALGO_SHA256))->toBe(1)
        ->and(DPoP::verify(DPOP_RFC7515_INPUT.'.'.DPOP_RFC7515_SIGNATURE, $public))->toBeTrue()
        // A flipped signature byte must fail.
        ->and(DPoP::verify(DPOP_RFC7515_INPUT.'.'.DPoP::base64url(substr($raw, 0, 63).chr(ord($raw[63]) ^ 1)), $public))->toBeFalse()
        // Round trip: DER -> JOSE gives the published bytes back.
        ->and(DPoP::derToJose(DPoP::joseToDer($raw)))->toBe($raw);
});

it('imports the RFC 7515 private JWK and derives the same public key', function () {
    $pem = DPoP::privateKeyPem(DPOP_RFC7515_JWK);

    expect($pem)->toStartWith('-----BEGIN EC PRIVATE KEY-----')
        ->and(DPoP::publicJwk($pem))->toBe(['kty' => 'EC', 'crv' => 'P-256', 'x' => DPOP_RFC7515_JWK['x'], 'y' => DPOP_RFC7515_JWK['y']])
        ->and(DPoP::publicJwk(json_encode(DPOP_RFC7515_JWK)))->toBe(DPoP::publicJwk($pem));
});

it('signs with the RFC 7515 key so that openssl_verify accepts the converted signature', function () {
    $jws = DPoP::sign(DPOP_RFC7515_JWK, ['typ' => 'JWT'], ['iss' => 'joe']);
    [$header, $claims, , $raw] = dpopDecode($jws);

    expect($header)->toBe(['alg' => 'ES256', 'typ' => 'JWT'])
        ->and($claims)->toBe(['iss' => 'joe'])
        ->and(strlen($raw))->toBe(64)
        ->and(dpopOpensslVerifies($jws, DPOP_RFC7515_JWK))->toBeTrue();
});

it('verifies the RFC 9449 example proof with the key in its own header', function () {
    [$header, $claims] = dpopDecode(DPOP_RFC9449_PROOF);

    expect($header['typ'])->toBe('dpop+jwt')
        ->and($header['alg'])->toBe('ES256')
        ->and($claims['htm'])->toBe('POST')
        ->and($claims['htu'])->toBe('https://server.example.com/token')
        ->and(dpopOpensslVerifies(DPOP_RFC9449_PROOF, $header['jwk']))->toBeTrue()
        ->and(DPoP::verify(DPOP_RFC9449_PROOF))->toBeTrue();
});

// --- DER <-> JOSE edge cases ----------------------------------------------------------

it('strips the DER sign byte and pads short integers to 32 bytes', function () {
    $high = "\x80".str_repeat("\x11", 31);     // needs a 0x00 sign byte in DER
    $short = str_repeat("\x22", 30);            // a 30-byte integer (leading zeros dropped)
    $der = dpopDer("\x00".$high, $short);

    $raw = DPoP::derToJose($der);

    expect($raw)->toBe($high."\0\0".$short)
        ->and(DPoP::joseToDer($raw))->toBe($der);
});

it('encodes a zero integer and keeps r and s in order', function () {
    $raw = str_repeat("\0", 32).str_repeat("\x01", 32);

    expect(DPoP::joseToDer($raw))->toBe(dpopDer("\0", str_repeat("\x01", 32)))
        ->and(DPoP::derToJose(DPoP::joseToDer($raw)))->toBe($raw);
});

it('round-trips many real signatures', function () {
    $pem = DPoP::generateKey();
    $jwk = DPoP::publicJwk($pem);

    for ($i = 0; $i < 40; $i++) {
        $jws = DPoP::sign($pem, [], ['n' => $i]);

        expect(dpopOpensslVerifies($jws, $jwk))->toBeTrue();
    }
});

it('rejects malformed signatures', function (string $der) {
    expect(fn () => DPoP::derToJose($der))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'not a sequence' => ["\x02\x01\x01"],
    'truncated' => [substr(dpopDer(str_repeat("\x01", 32), str_repeat("\x01", 32)), 0, 40)],
    'integer too long' => [dpopDer(str_repeat("\x01", 33), "\x01")],
    'trailing garbage' => [dpopDer("\x01", "\x01")."\x00"],
]);

it('rejects odd-length JOSE signatures', function () {
    expect(fn () => DPoP::joseToDer(str_repeat("\x01", 63)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DPoP::joseToDer(''))->toThrow(InvalidArgumentException::class);
});

// --- Keys -----------------------------------------------------------------------

it('accepts PEM keys with literal \n escapes and refuses non P-256 keys', function () {
    $pem = DPoP::generateKey();
    $escaped = str_replace("\n", '\n', trim($pem));

    $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    openssl_pkey_export($rsa, $rsaPem);

    $p384 = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
    openssl_pkey_export($p384, $p384Pem);

    expect(DPoP::publicJwk($escaped))->toBe(DPoP::publicJwk($pem))
        ->and(fn () => DPoP::privateKeyPem($rsaPem))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DPoP::privateKeyPem($p384Pem))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DPoP::privateKeyPem('not a key'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DPoP::privateKeyPem('{"kty":'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DPoP::privateKeyPem(['kty' => 'EC', 'crv' => 'P-256', 'x' => 'AA', 'y' => 'AA']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DPoP::privateKeyPem(['kty' => 'RSA', 'd' => 'AA']))->toThrow(InvalidArgumentException::class);
});

// --- Proofs -----------------------------------------------------------------------

it('builds a proof with the RFC 9449 claims', function () {
    $pem = DPoP::generateKey();
    $proof = DPoP::proof($pem, 'post', 'https://pds.example.com/xrpc/com.atproto.repo.createRecord?x=1#frag', 'nonce-1', 'token-abc', 1_700_000_000);
    $again = DPoP::proof($pem, 'GET', 'https://pds.example.com/xrpc/a');

    [$header, $claims] = dpopDecode($proof);
    [, $second] = dpopDecode($again);

    expect($header['typ'])->toBe('dpop+jwt')
        ->and($header['alg'])->toBe('ES256')
        ->and($header['jwk'])->toBe(DPoP::publicJwk($pem))
        ->and($header['jwk'])->not->toHaveKey('d')
        ->and($claims['htm'])->toBe('POST')
        ->and($claims['htu'])->toBe('https://pds.example.com/xrpc/com.atproto.repo.createRecord')
        ->and($claims['iat'])->toBe(1_700_000_000)
        ->and($claims['nonce'])->toBe('nonce-1')
        ->and($claims['ath'])->toBe(rtrim(strtr(base64_encode(hash('sha256', 'token-abc', true)), '+/', '-_'), '='))
        ->and($claims['jti'])->toBeString()->not->toBe($second['jti'])
        ->and($second)->not->toHaveKeys(['nonce', 'ath'])
        ->and($second['iat'])->toBeGreaterThanOrEqual(time() - 5)
        ->and(dpopOpensslVerifies($proof, $header['jwk']))->toBeTrue();
});

// --- Middleware -------------------------------------------------------------------

it('signs every request and sets Authorization: DPoP', function () {
    $pem = DPoP::generateKey();
    Http::fake(['pds.example.com/*' => Http::response(['ok' => true])]);

    Http::withMiddleware(DPoP::middleware($pem, 'tok-1'))->get('https://pds.example.com/xrpc/a.b.c', ['q' => 'v']);

    Http::assertSent(function (Request $request) use ($pem) {
        [$header, $claims] = dpopDecode($request->header('DPoP')[0]);

        return $request->header('Authorization')[0] === 'DPoP tok-1'
            && $claims['htm'] === 'GET'
            && $claims['htu'] === 'https://pds.example.com/xrpc/a.b.c'
            && $claims['ath'] === DPoP::accessTokenHash('tok-1')
            && ! isset($claims['nonce'])
            && $header['jwk'] === DPoP::publicJwk($pem)
            && DPoP::verify($request->header('DPoP')[0]);
    });
});

it('retries once with the server nonce after a 401 use_dpop_nonce, rewinding the body', function () {
    $pem = DPoP::generateKey();
    $calls = 0;

    Http::fake(['pds.example.com/*' => function () use (&$calls) {
        $calls++;

        return $calls === 1
            ? Http::response(['error' => 'use_dpop_nonce', 'message' => 'Authorization server requires nonce in DPoP proof'], 401, [
                'WWW-Authenticate' => 'DPoP error="use_dpop_nonce", error_description="Authorization server requires nonce in DPoP proof"',
                'DPoP-Nonce' => 'n-1',
            ])
            : Http::response(['uri' => 'at://x'], 200, ['DPoP-Nonce' => 'n-2']);
    }]);

    $response = Http::withMiddleware(DPoP::middleware($pem, 'tok'))
        ->withBody('{"repo":"did:plc:abc"}', 'application/json')
        ->post('https://pds.example.com/xrpc/com.atproto.repo.createRecord');

    $sent = [];
    Http::assertSent(function (Request $request) use (&$sent) {
        $sent[] = $request;

        return true;
    });

    [, $first] = dpopDecode($sent[0]->header('DPoP')[0]);
    [, $second] = dpopDecode($sent[1]->header('DPoP')[0]);

    expect($response->status())->toBe(200)
        ->and($calls)->toBe(2)
        ->and($first)->not->toHaveKey('nonce')
        ->and($second['nonce'])->toBe('n-1')
        ->and($sent[1]->body())->toBe('{"repo":"did:plc:abc"}')
        ->and($first['jti'])->not->toBe($second['jti'])
        // The latest nonce is remembered for the origin.
        ->and(DPoP::nonce('https://pds.example.com/other'))->toBe('n-2');
});

it('retries once on a 400 use_dpop_nonce body (authorization servers)', function () {
    $pem = DPoP::generateKey();
    $calls = 0;

    Http::fake(['auth.example.com/*' => function () use (&$calls) {
        $calls++;

        return $calls === 1
            ? Http::response(['error' => 'use_dpop_nonce', 'error_description' => 'Authorization server requires nonce in DPoP proof'], 400, ['DPoP-Nonce' => 'as-nonce'])
            : Http::response(['access_token' => 'x']);
    }]);

    $response = Http::withMiddleware(DPoP::middleware($pem))->asForm()->post('https://auth.example.com/oauth/token', ['grant_type' => 'refresh_token']);

    expect($response->status())->toBe(200)->and($calls)->toBe(2);

    Http::assertSent(function (Request $request) {
        [, $claims] = dpopDecode($request->header('DPoP')[0]);

        return ($claims['nonce'] ?? null) === 'as-nonce' && ! isset($claims['ath']) && $request->hasHeader('Authorization') === false;
    });
});

it('does not retry without a DPoP-Nonce header, nor more than once', function () {
    $pem = DPoP::generateKey();
    $calls = 0;

    Http::fake(['a.example.com/*' => function () use (&$calls) {
        $calls++;

        return Http::response(['error' => 'InvalidToken'], 401, ['WWW-Authenticate' => 'DPoP error="invalid_token"']);
    }, 'b.example.com/*' => function () use (&$calls) {
        $calls++;

        return Http::response([], 401, ['WWW-Authenticate' => 'DPoP error="use_dpop_nonce"', 'DPoP-Nonce' => 'again-'.$calls]);
    }]);

    $client = fn () => Http::withMiddleware(DPoP::middleware($pem, 'tok'));

    expect($client()->get('https://a.example.com/x')->status())->toBe(401)
        ->and($calls)->toBe(1);

    $calls = 0;

    expect($client()->get('https://b.example.com/x')->status())->toBe(401)
        ->and($calls)->toBe(2);
});

it('uses the cached nonce on the next request to the same origin', function () {
    $pem = DPoP::generateKey();
    DPoP::rememberNonce('https://pds.example.com:8443/xrpc/a', 'cached');

    Http::fake(['*' => Http::response([])]);

    Http::withMiddleware(DPoP::middleware($pem, 'tok'))->get('https://pds.example.com:8443/xrpc/b');
    Http::withMiddleware(DPoP::middleware($pem, 'tok'))->get('https://pds.example.com/xrpc/b');

    $nonces = [];
    Http::assertSent(function (Request $request) use (&$nonces) {
        [, $claims] = dpopDecode($request->header('DPoP')[0]);
        $nonces[] = $claims['nonce'] ?? null;

        return true;
    });

    expect($nonces)->toBe(['cached', null])
        ->and(DPoP::origin('HTTPS://PDS.Example.com:8443/a?b'))->toBe('https://pds.example.com:8443');
});

it('does not resend a body that cannot be rewound', function () {
    $pem = DPoP::generateKey();
    $calls = 0;

    Http::fake(['*' => function () use (&$calls) {
        $calls++;

        return Http::response([], 401, ['WWW-Authenticate' => 'DPoP error="use_dpop_nonce"', 'DPoP-Nonce' => 'n']);
    }]);

    $stream = new NoSeekStream(Utils::streamFor('video-bytes'));

    $response = Http::withMiddleware(DPoP::middleware($pem, 'tok'))->withBody($stream, 'video/mp4')->post('https://pds.example.com/upload');

    expect($response->status())->toBe(401)->and($calls)->toBe(1);
});

it('detects nonce requests from responses', function () {
    expect(DPoP::asksForNonce(new PsrResponse(401, ['WWW-Authenticate' => 'DPoP error="use_dpop_nonce"', 'DPoP-Nonce' => 'n'])))->toBeTrue()
        ->and(DPoP::asksForNonce(new PsrResponse(200, ['WWW-Authenticate' => 'DPoP error="use_dpop_nonce"', 'DPoP-Nonce' => 'n'])))->toBeTrue()
        ->and(DPoP::asksForNonce(new PsrResponse(400, ['DPoP-Nonce' => 'n'], '{"error":"use_dpop_nonce"}')))->toBeTrue()
        ->and(DPoP::asksForNonce(new PsrResponse(400, ['DPoP-Nonce' => 'n'], '{"error":"invalid_grant"}')))->toBeFalse()
        ->and(DPoP::asksForNonce(new PsrResponse(401, ['WWW-Authenticate' => 'DPoP error="use_dpop_nonce"'])))->toBeFalse()
        ->and(DPoP::asksForNonce(new PsrResponse(500, ['DPoP-Nonce' => 'n'], '{"error":"use_dpop_nonce"}')))->toBeFalse();
});

it('refuses an unusable key when the middleware is built, not per request', function () {
    expect(fn () => DPoP::middleware('garbage', 'tok'))->toThrow(InvalidArgumentException::class);
});

it('rejects malformed or foreign JWS in verify()', function () {
    $pem = DPoP::generateKey();
    $jwk = DPoP::publicJwk($pem);
    $jws = DPoP::sign($pem, ['typ' => 'dpop+jwt', 'jwk' => $jwk], ['a' => 1]);
    [$header, $payload, $signature] = explode('.', $jws);
    $hs256 = DPoP::base64url('{"alg":"HS256","jwk":'.json_encode($jwk).'}');

    expect(DPoP::verify($jws))->toBeTrue()
        ->and(DPoP::verify($header.'.'.$payload))->toBeFalse()
        ->and(DPoP::verify($hs256.'.'.$payload.'.'.$signature))->toBeFalse()
        ->and(DPoP::verify(DPoP::base64url('{"alg":"ES256"}').'.'.$payload.'.'.$signature))->toBeFalse()
        ->and(DPoP::verify($jws, DPoP::publicJwk(DPoP::generateKey())))->toBeFalse()
        ->and(DPoP::verify('a.b.c!'))->toBeFalse()
        ->and(fn () => DPoP::base64urlDecode('a+b'))->toThrow(InvalidArgumentException::class);
});
