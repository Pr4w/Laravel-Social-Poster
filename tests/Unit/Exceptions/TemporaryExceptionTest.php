<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\TemporaryException;

/**
 * What Laravel's HTTP client throws for a cURL failure: a ConnectionException
 * carrying Guzzle's message, wrapping Guzzle's ConnectException with the
 * handler context (errno, error).
 */
function teTransportFailure(int $errno, string $curlMessage): ConnectionException
{
    $message = "cURL error {$errno}: {$curlMessage} (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://api.linkedin.com/rest/posts";
    $guzzle = new ConnectException($message, new Request('POST', 'https://api.linkedin.com/rest/posts'), null, ['errno' => $errno, 'error' => $curlMessage]);

    return new ConnectionException($message, 0, $guzzle);
}

it('treats every libcurl connect-phase timeout as never sent', function (string $curlMessage) {
    $e = teTransportFailure(28, $curlMessage);

    expect(TemporaryException::requestNeverSent($e))->toBeTrue()
        ->and(TemporaryException::connection(Platform::LinkedIn, $e)->context['request_sent'])->toBeFalse();
})->with([
    // lib/multi.c (curl 7.x up to 8.23)
    'multi connect' => ['Connection timed out after 10001 milliseconds'],
    'multi resolve' => ['Resolving timed out after 5000 milliseconds'],
    // lib/connect.c, curl 8.x (Ubuntu 24.04 ships 8.5.0)
    'connect.c timeout' => ['Connection timeout after 10001 ms'],
    'connect.c time-out' => ['Connection time-out'],
    'connect.c failed, 8.5' => ['Failed to connect to api.linkedin.com port 443 after 10001 ms: Timeout was reached'],
    'connect.c failed, 8.7' => ['Failed to connect to 192.0.2.1 port 443 after 2016 ms: Timeout was reached'],
    'connect.c failed, ETIMEDOUT' => ['Failed to connect to api.linkedin.com port 443 after 75002 ms: Couldn\'t connect to server'],
    'connect.c failed, 8.23' => ['Failed to connect to api.linkedin.com:443 after 10001 ms: Timeout was reached'],
    // DNS, TLS and proxy tunnel phases
    'resolver' => ["Failed to resolve 'api.linkedin.com' with timeout after 5002 ms"],
    'tls openssl' => ['SSL connection timeout'],
    'tls handshake' => ['TLS handshake timed out.'],
    'proxy tunnel' => ['Proxy CONNECT aborted due to timeout'],
]);

it('keeps a timeout after the request left the client as possibly sent', function (string $curlMessage) {
    $e = teTransportFailure(28, $curlMessage);

    expect(TemporaryException::requestNeverSent($e))->toBeFalse()
        ->and(TemporaryException::connection(Platform::LinkedIn, $e)->context['request_sent'])->toBeTrue();
})->with([
    'read timeout' => ['Operation timed out after 60001 milliseconds with 0 bytes received'],
    'read timeout with size' => ['Operation timed out after 60001 milliseconds with 512 out of 2048 bytes received'],
    'bare strerror' => ['Timeout was reached'],
    'generic' => ['Operation timed out'],
]);

it('classifies cURL errors raised before sending, and leaves the rest as possibly sent', function () {
    expect(TemporaryException::requestNeverSent(teTransportFailure(6, 'Could not resolve host: api.linkedin.com')))->toBeTrue()
        ->and(TemporaryException::requestNeverSent(teTransportFailure(7, 'Failed to connect to api.linkedin.com port 443 after 3 ms: Couldn\'t connect to server')))->toBeTrue()
        ->and(TemporaryException::requestNeverSent(teTransportFailure(35, 'OpenSSL SSL_connect: SSL_ERROR_SYSCALL in connection to api.linkedin.com:443')))->toBeTrue()
        ->and(TemporaryException::requestNeverSent(teTransportFailure(52, 'Empty reply from server')))->toBeFalse()
        ->and(TemporaryException::requestNeverSent(teTransportFailure(56, 'Recv failure: Connection reset by peer')))->toBeFalse()
        ->and(TemporaryException::requestNeverSent(new RuntimeException('Connection timed out')))->toBeFalse();
});

it('builds a Network failure with a redacted message', function () {
    $e = TemporaryException::connection(Platform::Facebook, new ConnectionException('cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received for https://graph.facebook.com/me?access_token=SECRET&x=1'));

    expect($e->reason)->toBe(FailureReason::Network)
        ->and($e->context['connection'])->toBeTrue()
        ->and($e->context['request_sent'])->toBeTrue()
        ->and($e->getMessage())->not->toContain('SECRET')->toContain('access_token=[redacted]');
});
