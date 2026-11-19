<?php

use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\PostFailed;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;

it('buckets every failure reason, and never files a possibly-live post under retry', function () {
    $buckets = [];

    foreach (FailureReason::cases() as $reason) {
        $buckets[$reason->value] = $reason->category();
    }

    expect($buckets)->toBe([
        'rate_limited' => 'retry',
        'server_error' => 'retry',
        'timeout' => 'retry',
        'invalid_token' => 'reconnect',
        'insufficient_permissions' => 'reconnect',
        'account_restricted' => 'user_fix',
        'media_rejected' => 'user_fix',
        'duplicate_content' => 'user_fix',
        'unknown' => 'operator',
        'invalid_content' => 'user_fix',
        'validation' => 'user_fix',
        'outcome_unknown' => 'verify',
        'processing_timeout' => 'retry',
        'network' => 'retry',
        'not_found' => 'not_found',
        'quota_exceeded' => 'operator',
        'expired' => 'user_fix',
        'misconfigured' => 'operator',
    ]);
});

it('reports a possibly published failure as verify whatever its reason', function (FailureReason $reason) {
    $possiblyLive = new PermanentException('It may still go live.', Platform::TikTok, $reason, ['possibly_published' => true]);
    $notLive = new PermanentException('No post was created.', Platform::Pinterest, $reason, ['possibly_published' => false]);

    expect($possiblyLive->errorSummary()['category'])->toBe('verify')
        ->and($possiblyLive->errorSummary()['reason'])->toBe($reason->value)
        ->and($notLive->errorSummary()['category'])->toBe($reason->category());
})->with([
    'committed post outlived the wait' => FailureReason::ProcessingTimeout,
    'ambiguous create' => FailureReason::OutcomeUnknown,
    'connection lost after sending' => FailureReason::Network,
    'token revoked after the publish began' => FailureReason::InvalidToken,
]);

it('reports verify on PostFailed when the event says the post may be live', function () {
    $exception = new TemporaryException('Gateway timeout', Platform::LinkedIn, FailureReason::ServerError);

    $flagged = new PostFailed(Platform::LinkedIn, $exception, possiblyPublished: true);
    $plain = new PostFailed(Platform::LinkedIn, $exception);

    expect($flagged->errorSummary()['category'])->toBe('verify')
        ->and($plain->errorSummary()['category'])->toBe('retry');
});

it('drops objects and closures nested anywhere in the context, so the summary always serialises', function () {
    $e = new TemporaryException('Server error', Platform::X, FailureReason::ServerError, 60, [
        'http_status' => 503,
        'flags' => ['possibly_published' => false, 'callback' => fn () => null],
        'deep' => ['level' => ['response' => new stdClass, 'code' => 'x', 'list' => [1, null, fn () => 1]]],
        'object' => new stdClass,
    ]);

    $summary = $e->errorSummary();

    expect($summary['context'])->toBe([
        'http_status' => 503,
        'flags' => ['possibly_published' => false],
        'deep' => ['level' => ['code' => 'x', 'list' => [1, null]]],
    ])
        ->and(serialize($summary))->toBeString()
        ->and(json_encode($summary))->toBeString()
        ->and($summary['platform'])->toBe('x')
        ->and($summary['reason'])->toBe(FailureReason::ServerError->value);
});

it('files a permission refusal a reconnect cannot fix under user_fix, not reconnect', function () {
    $privacy = new PermanentException('Privacy level not allowed', Platform::TikTok, FailureReason::InsufficientPermissions, ['reconnect' => false]);
    $scope = new PermanentException('Missing scope', Platform::TikTok, FailureReason::InsufficientPermissions);

    expect($privacy->needsReconnect())->toBeFalse()
        ->and($privacy->errorSummary()['category'])->toBe('user_fix')
        ->and($privacy->errorSummary()['reason'])->toBe('insufficient_permissions')
        ->and($scope->needsReconnect())->toBeTrue()
        ->and($scope->errorSummary()['category'])->toBe('reconnect')
        ->and((new PermanentException('x', null, FailureReason::MediaRejected))->needsReconnect())->toBeFalse();
});
