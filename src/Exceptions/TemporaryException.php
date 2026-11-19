<?php

namespace SocialPoster\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use Throwable;

/**
 * Retryable. The publish job releases itself with backoff (honouring
 * $retryAfter when the platform supplied one) until $tries is exhausted.
 */
class TemporaryException extends SocialPosterException
{
    /** cURL errors raised before any request byte left the client (DNS, refused, TLS handshake). */
    protected const NOT_SENT_ERRNOS = [5, 6, 7, 35, 51, 53, 58, 60, 77, 83];

    /**
     * Lower-cased fragments of the libcurl messages for cURL 28 raised in the
     * DNS, TCP connect, TLS handshake or proxy-tunnel phase, i.e. before the
     * request was written. The wording changed across libcurl versions:
     * lib/multi.c "Connection timed out after N milliseconds" / "Resolving timed
     * out after N milliseconds"; lib/connect.c (8.x) "Connection timeout after
     * N ms", "Connection time-out" and "Failed to connect to <host> port <p>
     * after N ms: Timeout was reached"; the resolver's "Failed to resolve
     * '<host>' with timeout"; the TLS backends' "SSL connection timeout" /
     * "handshake timed out"; the tunnel's "Proxy CONNECT aborted due to
     * timeout". A timeout after sending reads "Operation timed out after N
     * milliseconds with N bytes received" and matches none of them.
     */
    protected const NOT_SENT_TIMEOUTS = [
        'connection timed out',
        'connection timeout',
        'connection time-out',
        'connect timeout',
        'failed to connect to',
        'resolving timed out',
        'failed to resolve',
        'name lookup timed out',
        'ssl connection timeout',
        'handshake timed out',
        'proxy connect aborted due to timeout',
    ];

    public function __construct(
        string $message,
        ?Platform $platform = null,
        FailureReason $reason = FailureReason::ServerError,
        public readonly ?int $retryAfter = null,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $platform, $reason, $context, $previous);
    }

    /**
     * A connection-level failure (cURL timeout, DNS, refused connection, TLS).
     * These never produce a response, so a driver's classifyError() never sees
     * them; they are transient by nature and retried with backoff.
     *
     * context['request_sent'] tells a commit phase whether a retry is safe:
     * false means the request provably never reached the platform (DNS failure,
     * refused connection, TLS error, connect-phase timeout); true or null means
     * the platform may have received and acted on it. context['curl_errno']
     * carries the cURL error number when the transport reported one.
     *
     * The previous exception is a stand-in ConnectionException with the
     * (redacted) transport message, not the transport exception itself: that
     * one holds the PSR-7 request, Authorization header and token query
     * included, which would otherwise travel into queued PostFailed /
     * CommentFailed payloads, failed_jobs and logs.
     */
    public static function connection(?Platform $platform, Throwable $e): self
    {
        $errno = static::curlErrno($e);

        return new self(
            'Network error contacting '.($platform->value ?? 'the platform').': '.static::redact($e->getMessage()),
            $platform,
            FailureReason::Network,
            null,
            ['connection' => true, 'request_sent' => ! static::requestNeverSent($e)] + ($errno !== null ? ['curl_errno' => $errno] : []),
            new ConnectionException(static::redact($e->getMessage()), (int) $e->getCode()),
        );
    }

    /** The cURL error number of a transport failure (handler context or message), or null. */
    protected static function curlErrno(Throwable $e): ?int
    {
        for ($cursor = $e; $cursor !== null; $cursor = $cursor->getPrevious()) {
            $context = method_exists($cursor, 'getHandlerContext') ? (array) $cursor->getHandlerContext() : [];

            if (isset($context['errno']) && is_numeric($context['errno'])) {
                return (int) $context['errno'];
            }

            if (preg_match('/curl error (\d+)/i', $cursor->getMessage(), $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * True when the transport failure happened before the request was sent, so
     * re-sending a non-idempotent create cannot duplicate it. Conservative: any
     * doubt (read timeout, reset mid-response, unknown errno) returns false.
     */
    public static function requestNeverSent(Throwable $e): bool
    {
        for ($cursor = $e; $cursor !== null; $cursor = $cursor->getPrevious()) {
            // A connection() failure already decided it (its previous is a stand-in).
            if ($cursor instanceof SocialPosterException && ($cursor->context['connection'] ?? false) === true && is_bool($cursor->context['request_sent'] ?? null)) {
                return $cursor->context['request_sent'] === false;
            }

            $context = method_exists($cursor, 'getHandlerContext') ? (array) $cursor->getHandlerContext() : [];
            $errno = isset($context['errno']) ? (int) $context['errno'] : null;
            $message = strtolower($cursor->getMessage());

            if ($errno === null && preg_match('/curl error (\d+)/', $message, $m)) {
                $errno = (int) $m[1];
            }

            if ($errno !== null && in_array($errno, static::NOT_SENT_ERRNOS, true)) {
                return true;
            }

            // cURL 28 is both a connect-phase timeout (never sent) and "operation
            // timed out ... bytes received" (sent). Only the connect phase is safe.
            if ($errno === 28) {
                foreach (static::NOT_SENT_TIMEOUTS as $fragment) {
                    if (str_contains($message, $fragment)) {
                        return true;
                    }
                }

                return false;
            }
        }

        return false;
    }

    /** Strip query-string tokens (access_token=...) that Guzzle appends to transport messages. */
    protected static function redact(string $message): string
    {
        return (string) preg_replace('/((?:access_token|token|key|signature)=)[^&\s"]+/i', '$1[redacted]', $message);
    }
}
