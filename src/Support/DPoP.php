<?php

namespace SocialPoster\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * DPoP (RFC 9449) proof-of-possession for OAuth access tokens, as AT Protocol
 * (Bluesky) requires: the token is bound to a P-256 key, and every request
 * carries a fresh proof, a compact ES256 JWS naming the method and URL, signed
 * with that key. A token sent without its proof is refused.
 *
 * Plain ext-openssl, no JWT library. The one classic trap is the signature
 * format: openssl_sign() returns an ASN.1 DER `SEQUENCE { INTEGER r, INTEGER s }`
 * (70-72 bytes, integers with sign padding), while JWS ES256 (RFC 7518 §3.4)
 * wants the raw 64-byte r || s with each integer left-padded to 32 bytes.
 * derToJose() and joseToDer() convert between the two.
 *
 * Servers also hand out a nonce (DPoP-Nonce response header) that they rotate.
 * The middleware remembers the last one per origin (scheme://host[:port]) for
 * five minutes and, when a response asks for a new one (use_dpop_nonce), sends
 * the request once more with it, rewinding the body first.
 *
 * Keys are accepted as a PEM string (EC or PKCS#8, literal "\n" escapes from
 * .env files tolerated), a private JWK array {kty: EC, crv: P-256, x, y, d}, or
 * that JWK as a JSON string.
 */
final class DPoP
{
    /** Seconds a server nonce is remembered (servers rotate them every few minutes). */
    public const NONCE_TTL = 300;

    /** Cache key prefix of the per-origin nonce. */
    public const NONCE_CACHE_PREFIX = 'social-poster:dpop-nonce:';

    /** DER of OID 1.2.840.10045.3.1.7 (prime256v1 / P-256). */
    private const P256_OID = "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";

    /** DER of OID 1.2.840.10045.2.1 (id-ecPublicKey). */
    private const EC_PUBLIC_KEY_OID = "\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01";

    /** Byte length of a P-256 coordinate, scalar and signature half. */
    private const P256_BYTES = 32;

    /** A new P-256 private key, PEM encoded (handy for tests and connect flows). */
    public static function generateKey(): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        if ($key === false || ! openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('Could not generate a DPoP key: '.(openssl_error_string() ?: 'unknown OpenSSL error'));
        }

        return (string) $pem;
    }

    /**
     * The private key as PEM, from any accepted form (PEM, JWK array, JWK JSON).
     *
     * @param  string|array<string, mixed>  $key
     *
     * @throws InvalidArgumentException When the key is not a usable P-256 private key.
     */
    public static function privateKeyPem(#[\SensitiveParameter] string|array $key): string
    {
        if (is_string($key)) {
            $trimmed = trim($key);

            if (str_starts_with($trimmed, '{')) {
                $decoded = json_decode($trimmed, true);

                if (! is_array($decoded)) {
                    throw new InvalidArgumentException('The DPoP key looks like JSON but could not be decoded.');
                }

                $key = $decoded;
            } else {
                // Keys copied into .env files often carry literal "\n" escapes.
                $pem = str_contains($trimmed, "\n") ? $trimmed : str_replace('\n', "\n", $trimmed);
                self::assertP256(self::load($pem));

                return $pem;
            }
        }

        $pem = self::jwkToPem($key);
        self::assertP256(self::load($pem));

        return $pem;
    }

    /**
     * The public JWK {kty, crv, x, y} of a P-256 key, as a proof's header carries it.
     *
     * @param  string|array<string, mixed>  $key
     * @return array{kty: string, crv: string, x: string, y: string}
     */
    public static function publicJwk(#[\SensitiveParameter] string|array $key): array
    {
        $details = openssl_pkey_get_details(self::load(self::privateKeyPem($key)));

        if ($details === false || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new InvalidArgumentException('DPoP keys must be P-256 (ES256) keys.');
        }

        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => self::base64url(str_pad((string) $details['ec']['x'], self::P256_BYTES, "\0", STR_PAD_LEFT)),
            'y' => self::base64url(str_pad((string) $details['ec']['y'], self::P256_BYTES, "\0", STR_PAD_LEFT)),
        ];
    }

    /**
     * The SubjectPublicKeyInfo PEM of a public P-256 JWK, for openssl_verify().
     *
     * @param  array<string, mixed>  $jwk
     */
    public static function publicKeyPem(array $jwk): string
    {
        [$x, $y] = self::coordinates($jwk);

        $point = "\x04".$x.$y;
        $algorithm = self::der(0x30, self::EC_PUBLIC_KEY_OID.self::P256_OID);
        $spki = self::der(0x30, $algorithm.self::der(0x03, "\x00".$point));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /**
     * A compact ES256 JWS (header.payload.signature) with a raw r || s signature.
     *
     * @param  string|array<string, mixed>  $key
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $payload
     */
    public static function sign(#[\SensitiveParameter] string|array $key, array $header, array $payload): string
    {
        $input = self::base64url(json_encode(['alg' => 'ES256'] + $header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            .'.'.self::base64url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        if (! openssl_sign($input, $der, self::load(self::privateKeyPem($key)), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the DPoP proof: '.(openssl_error_string() ?: 'unknown OpenSSL error'));
        }

        return $input.'.'.self::base64url(self::derToJose((string) $der));
    }

    /**
     * Check a compact ES256 JWS against a public JWK (default: the 'jwk' in its
     * own header, as for a DPoP proof). Converts the r || s signature back to
     * DER for openssl_verify().
     *
     * @param  array<string, mixed>|null  $jwk
     */
    public static function verify(string $jws, ?array $jwk = null): bool
    {
        $parts = explode('.', $jws);

        if (count($parts) !== 3) {
            return false;
        }

        try {
            $header = json_decode(self::base64urlDecode($parts[0]), true, 16, JSON_THROW_ON_ERROR);

            if (! is_array($header) || ($header['alg'] ?? null) !== 'ES256') {
                return false;
            }

            $jwk ??= is_array($header['jwk'] ?? null) ? $header['jwk'] : null;

            if ($jwk === null) {
                return false;
            }

            $der = self::joseToDer(self::base64urlDecode($parts[2]));

            return openssl_verify($parts[0].'.'.$parts[1], $der, self::publicKeyPem($jwk), OPENSSL_ALGO_SHA256) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The proof for one request (RFC 9449 §4.2): header {typ: dpop+jwt, alg:
     * ES256, jwk}, claims {jti, htm, htu, iat, nonce?, ath?}. Pass $accessToken
     * for resource requests (it is bound through ath = base64url(sha256(token)));
     * leave it out at the authorization server's token endpoint.
     *
     * @param  string|array<string, mixed>  $key
     */
    public static function proof(
        #[\SensitiveParameter] string|array $key,
        string $method,
        string $url,
        ?string $nonce = null,
        #[\SensitiveParameter] ?string $accessToken = null,
        ?int $issuedAt = null,
    ): string {
        $pem = self::privateKeyPem($key);

        return self::proofFor($pem, self::publicJwk($pem), $method, $url, $nonce, $accessToken, $issuedAt);
    }

    /**
     * Guzzle middleware that signs every request (Laravel:
     * Http::withMiddleware(DPoP::middleware($key, $token))). With $accessToken it
     * also sets `Authorization: DPoP <token>`. Remembers each server nonce and
     * resends a request once when the server asks for a fresh nonce.
     *
     * @param  string|array<string, mixed>  $key
     *
     * @throws InvalidArgumentException When the key is not a usable P-256 private key (checked now, not per request).
     */
    public static function middleware(#[\SensitiveParameter] string|array $key, #[\SensitiveParameter] ?string $accessToken = null): callable
    {
        $pem = self::privateKeyPem($key);
        $jwk = self::publicJwk($pem);

        return static function (callable $handler) use ($pem, $jwk, $accessToken): callable {
            return static function (RequestInterface $request, array $options) use ($handler, $pem, $jwk, $accessToken): PromiseInterface {
                $send = static function (RequestInterface $request, ?string $nonce) use ($handler, $options, $pem, $jwk, $accessToken): PromiseInterface {
                    $url = (string) $request->getUri();
                    $signed = $request->withHeader('DPoP', self::proofFor($pem, $jwk, $request->getMethod(), $url, $nonce, $accessToken));

                    if ($accessToken !== null) {
                        $signed = $signed->withHeader('Authorization', 'DPoP '.$accessToken);
                    }

                    return $handler($signed, $options)->then(static function (ResponseInterface $response) use ($url): ResponseInterface {
                        if ($response->hasHeader('DPoP-Nonce')) {
                            self::rememberNonce($url, $response->getHeaderLine('DPoP-Nonce'));
                        }

                        return $response;
                    });
                };

                return $send($request, self::nonce((string) $request->getUri()))->then(
                    static function (ResponseInterface $response) use ($send, $request): PromiseInterface|ResponseInterface {
                        if (! self::asksForNonce($response)) {
                            return $response;
                        }

                        $body = $request->getBody();

                        // A streamed body that cannot be rewound cannot be sent twice.
                        if (! $body->isSeekable() && $body->getSize() !== 0) {
                            return $response;
                        }

                        if ($body->isSeekable()) {
                            $body->rewind();
                        }

                        return $send($request, $response->getHeaderLine('DPoP-Nonce'));
                    },
                );
            };
        };
    }

    /**
     * Whether a response refuses the request for want of a (new) nonce: it
     * carries DPoP-Nonce and says use_dpop_nonce, in WWW-Authenticate (resource
     * servers, any status) or in a 400 body's 'error' (authorization servers).
     */
    public static function asksForNonce(ResponseInterface $response): bool
    {
        if (! $response->hasHeader('DPoP-Nonce') || trim($response->getHeaderLine('DPoP-Nonce')) === '') {
            return false;
        }

        if (str_contains(strtolower($response->getHeaderLine('WWW-Authenticate')), 'use_dpop_nonce')) {
            return true;
        }

        if ($response->getStatusCode() !== 400) {
            return false;
        }

        $body = $response->getBody();
        $contents = (string) $body;

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) && ($decoded['error'] ?? null) === 'use_dpop_nonce';
    }

    /** The last nonce the server at $url's origin handed out, if still fresh. */
    public static function nonce(string $url): ?string
    {
        try {
            $nonce = Cache::get(self::NONCE_CACHE_PREFIX.self::origin($url));
        } catch (Throwable) {
            return null; // a broken cache only costs one extra round trip
        }

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }

    public static function rememberNonce(string $url, string $nonce): void
    {
        $nonce = trim($nonce);

        if ($nonce === '') {
            return;
        }

        try {
            Cache::put(self::NONCE_CACHE_PREFIX.self::origin($url), $nonce, self::NONCE_TTL);
        } catch (Throwable) {
            // ignore: the next request asks again
        }
    }

    /** The htu claim: the URL without query and fragment (RFC 9449 §4.2). */
    public static function htu(string $url): string
    {
        return preg_replace('/[?#].*$/s', '', $url) ?? $url;
    }

    /** scheme://host[:port] of $url, the scope of a server nonce. */
    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        $parts = is_array($parts) ? $parts : [];

        return strtolower(($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** The ath claim: base64url(SHA-256(access token)). */
    public static function accessTokenHash(#[\SensitiveParameter] string $accessToken): string
    {
        return self::base64url(hash('sha256', $accessToken, true));
    }

    /**
     * openssl's DER ECDSA signature (SEQUENCE of two INTEGERs) to the raw
     * r || s that JWS wants, each integer stripped of its sign byte and
     * left-padded to $partLength bytes.
     *
     * @throws InvalidArgumentException On anything that is not a DER ECDSA signature.
     */
    public static function derToJose(string $der, int $partLength = self::P256_BYTES): string
    {
        $offset = 0;
        $sequence = self::readDer($der, $offset, 0x30);

        if ($offset !== strlen($der)) {
            throw new InvalidArgumentException('Trailing bytes after the DER signature.');
        }

        $inner = 0;
        $raw = '';

        for ($i = 0; $i < 2; $i++) {
            $integer = ltrim(self::readDer($sequence, $inner, 0x02), "\0");

            if (strlen($integer) > $partLength) {
                throw new InvalidArgumentException('A DER signature integer is longer than the curve allows.');
            }

            $raw .= str_pad($integer, $partLength, "\0", STR_PAD_LEFT);
        }

        if ($inner !== strlen($sequence)) {
            throw new InvalidArgumentException('Unexpected data inside the DER signature.');
        }

        return $raw;
    }

    /**
     * The raw r || s of a JWS ES256 signature back to DER, for openssl_verify().
     *
     * @throws InvalidArgumentException When $jose is not two equal halves.
     */
    public static function joseToDer(string $jose): string
    {
        $length = strlen($jose);

        if ($length === 0 || $length % 2 !== 0) {
            throw new InvalidArgumentException('A JOSE ECDSA signature is two integers of equal length.');
        }

        $integers = '';

        foreach (str_split($jose, intdiv($length, 2)) as $part) {
            $part = ltrim($part, "\0");
            $part = $part === '' ? "\0" : $part;

            // DER INTEGERs are signed: a leading 1 bit needs a 0x00 in front.
            if (ord($part[0]) & 0x80) {
                $part = "\0".$part;
            }

            $integers .= self::der(0x02, $part);
        }

        return self::der(0x30, $integers);
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @throws InvalidArgumentException On characters outside the base64url alphabet. */
    public static function base64urlDecode(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $value) !== 1) {
            throw new InvalidArgumentException('Not a base64url string.');
        }

        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        if ($decoded === false) {
            throw new InvalidArgumentException('Not a base64url string.');
        }

        return $decoded;
    }

    // --- Internals ----------------------------------------------------------------

    /** @param  array{kty: string, crv: string, x: string, y: string}  $jwk */
    private static function proofFor(
        #[\SensitiveParameter] string $pem,
        array $jwk,
        string $method,
        string $url,
        ?string $nonce,
        #[\SensitiveParameter] ?string $accessToken,
        ?int $issuedAt = null,
    ): string {
        $claims = [
            'jti' => self::base64url(random_bytes(16)),
            'htm' => strtoupper($method),
            'htu' => self::htu($url),
            'iat' => $issuedAt ?? time(),
        ];

        if ($nonce !== null && $nonce !== '') {
            $claims['nonce'] = $nonce;
        }

        if ($accessToken !== null) {
            $claims['ath'] = self::accessTokenHash($accessToken);
        }

        return self::sign($pem, ['typ' => 'dpop+jwt', 'jwk' => $jwk], $claims);
    }

    private static function load(#[\SensitiveParameter] string $pem): \OpenSSLAsymmetricKey
    {
        $key = @openssl_pkey_get_private($pem);

        if ($key === false) {
            throw new InvalidArgumentException('The DPoP key is not a readable private key (PEM, or a P-256 JWK with d, x and y).');
        }

        return $key;
    }

    private static function assertP256(\OpenSSLAsymmetricKey $key): void
    {
        $details = openssl_pkey_get_details($key);

        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new InvalidArgumentException('DPoP keys must be P-256 (ES256) EC keys.');
        }
    }

    /**
     * A private P-256 JWK as a SEC1 "EC PRIVATE KEY" PEM (RFC 5915):
     * SEQUENCE { 1, OCTET STRING d, [0] prime256v1, [1] BIT STRING 04||x||y }.
     *
     * @param  array<string, mixed>  $jwk
     */
    private static function jwkToPem(#[\SensitiveParameter] array $jwk): string
    {
        if (($jwk['kty'] ?? null) !== 'EC' || ($jwk['crv'] ?? null) !== 'P-256' || ! is_string($jwk['d'] ?? null)) {
            throw new InvalidArgumentException('A DPoP JWK must be a private P-256 EC key {kty: EC, crv: P-256, x, y, d}.');
        }

        [$x, $y] = self::coordinates($jwk);
        $d = self::base64urlDecode($jwk['d']);

        if (strlen($d) > self::P256_BYTES || ltrim($d, "\0") === '') {
            throw new InvalidArgumentException('The DPoP JWK private scalar d has the wrong length.');
        }

        $der = self::der(0x30,
            self::der(0x02, "\x01")
            .self::der(0x04, str_pad($d, self::P256_BYTES, "\0", STR_PAD_LEFT))
            .self::der(0xA0, self::P256_OID)
            .self::der(0xA1, self::der(0x03, "\x00\x04".$x.$y)),
        );

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    /**
     * The decoded, 32-byte x and y of a P-256 JWK.
     *
     * @param  array<string, mixed>  $jwk
     * @return array{0: string, 1: string}
     */
    private static function coordinates(array $jwk): array
    {
        if (! is_string($jwk['x'] ?? null) || ! is_string($jwk['y'] ?? null)) {
            throw new InvalidArgumentException('A P-256 JWK needs its x and y coordinates.');
        }

        $x = self::base64urlDecode($jwk['x']);
        $y = self::base64urlDecode($jwk['y']);

        if (strlen($x) > self::P256_BYTES || strlen($y) > self::P256_BYTES) {
            throw new InvalidArgumentException('A P-256 JWK coordinate has the wrong length.');
        }

        return [str_pad($x, self::P256_BYTES, "\0", STR_PAD_LEFT), str_pad($y, self::P256_BYTES, "\0", STR_PAD_LEFT)];
    }

    /** A DER TLV with a definite length. */
    private static function der(int $tag, string $value): string
    {
        $length = strlen($value);

        if ($length < 0x80) {
            return chr($tag).chr($length).$value;
        }

        $bytes = ltrim(pack('N', $length), "\0");

        return chr($tag).chr(0x80 | strlen($bytes)).$bytes.$value;
    }

    /** Read one DER TLV of the expected tag at $offset, advancing it; returns the value. */
    private static function readDer(string $der, int &$offset, int $tag): string
    {
        if (strlen($der) < $offset + 2 || ord($der[$offset]) !== $tag) {
            throw new InvalidArgumentException('Malformed DER signature.');
        }

        $length = ord($der[$offset + 1]);
        $offset += 2;

        if ($length & 0x80) {
            $count = $length & 0x7F;

            if ($count < 1 || $count > 2 || strlen($der) < $offset + $count) {
                throw new InvalidArgumentException('Malformed DER length.');
            }

            $length = 0;

            for ($i = 0; $i < $count; $i++) {
                $length = ($length << 8) | ord($der[$offset + $i]);
            }

            $offset += $count;
        }

        if (strlen($der) < $offset + $length) {
            throw new InvalidArgumentException('Truncated DER signature.');
        }

        $value = substr($der, $offset, $length);
        $offset += $length;

        return $value;
    }
}
