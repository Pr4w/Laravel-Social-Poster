<?php

namespace SocialPoster\Platforms\TikTok;

use InvalidArgumentException;

/**
 * Framework-agnostic helpers for TikTok webhooks (no route or controller ships
 * with the package):
 *
 *     $raw = $request->getContent();
 *
 *     if (! TikTokWebhook::verify($raw, $request->header(TikTokWebhook::SIGNATURE_HEADER), $clientSecret)) {
 *         abort(401);
 *     }
 *
 *     $event = TikTokWebhook::parse($raw);
 *
 * The signature header has the form `t=<unix time>,s=<hex>`, where s is the
 * HMAC-SHA256 of "{t}.{raw body}" keyed with the app's client secret. verify()
 * compares in constant time and rejects timestamps further than $tolerance
 * seconds from now (replays). Always verify the RAW body: re-encoded JSON does
 * not match the signature.
 *
 * Webhooks are the only way to hear about a post that later stops being
 * public (post.publish.no_longer_publicaly_available, as TikTok spells it:
 * match it with $event->is(TikTokWebhookEvent::NO_LONGER_PUBLICLY_AVAILABLE))
 * or a creator who revokes the app (authorization.removed); the driver's
 * status poll ends at publish.
 */
final class TikTokWebhook
{
    public const SIGNATURE_HEADER = 'Tiktok-Signature';

    public const DEFAULT_TOLERANCE = 300;

    /**
     * True when $signatureHeader is a valid signature of $rawBody made with
     * $clientSecret within $tolerance seconds of $now. A missing, malformed or
     * stale header is false, never an exception. $tolerance <= 0 disables the
     * timestamp check (not recommended).
     */
    public static function verify(
        string $rawBody,
        ?string $signatureHeader,
        #[\SensitiveParameter] string $clientSecret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): bool {
        if ($clientSecret === '' || $signatureHeader === null) {
            return false;
        }

        $parsed = self::parseSignatureHeader($signatureHeader);

        if ($parsed === null) {
            return false;
        }

        if ($tolerance > 0 && abs(($now ?? time()) - $parsed['t']) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $parsed['t'].'.'.$rawBody, $clientSecret);

        foreach ($parsed['s'] as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a signature header for $rawBody, as TikTok does. For tests and
     * local tooling that replays deliveries.
     */
    public static function sign(string $rawBody, #[\SensitiveParameter] string $clientSecret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',s='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $clientSecret);
    }

    /**
     * Decode a delivery. TikTok sends 'content' as a JSON string inside the
     * JSON body; it is decoded here (an already-decoded object is accepted
     * too, and an undecodable one becomes []).
     *
     * @throws InvalidArgumentException When the body is not a JSON object with an 'event'.
     */
    public static function parse(string $rawBody): TikTokWebhookEvent
    {
        $body = json_decode($rawBody, true);

        if (! is_array($body)) {
            throw new InvalidArgumentException('The TikTok webhook body is not a JSON object.');
        }

        $event = $body['event'] ?? null;

        if (! is_string($event) || trim($event) === '') {
            throw new InvalidArgumentException("The TikTok webhook body has no 'event'.");
        }

        $content = $body['content'] ?? [];

        if (is_string($content)) {
            $content = json_decode($content, true);
        }

        $content = is_array($content) ? $content : [];

        return new TikTokWebhookEvent(
            event: trim($event),
            userOpenId: self::stringOrNull($body['user_openid'] ?? null),
            publishId: self::stringOrNull($content['publish_id'] ?? null),
            postId: self::stringOrNull($content['post_id'] ?? null),
            reason: self::stringOrNull($content['reason'] ?? null),
            clientKey: self::stringOrNull($body['client_key'] ?? null),
            createTime: is_numeric($body['create_time'] ?? null) ? (int) $body['create_time'] : null,
            publishType: self::stringOrNull($content['publish_type'] ?? null),
            content: $content,
            raw: $body,
        );
    }

    /**
     * Split `t=..,s=..` into its timestamp and signature(s).
     *
     * @return array{t: int, s: list<string>}|null
     */
    private static function parseSignatureHeader(string $header): ?array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = [strtolower(trim($pair[0])), trim($pair[1])];

            if ($key === 't' && preg_match('/^\d{1,12}$/', $value) === 1) {
                $timestamp = (int) $value;
            } elseif ($key === 's' && preg_match('/^[0-9a-f]{64}$/i', $value) === 1) {
                $signatures[] = strtolower($value);
            }
        }

        return $timestamp !== null && $signatures !== [] ? ['t' => $timestamp, 's' => $signatures] : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
