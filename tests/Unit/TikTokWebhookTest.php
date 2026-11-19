<?php

namespace SocialPoster\Tests\Unit\TikTokWebhookTest;

use InvalidArgumentException;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Platforms\TikTok\TikTokWebhook;
use SocialPoster\Platforms\TikTok\TikTokWebhookEvent;

const SECRET = 'tt-client-secret';
const NOW = 1700000000;

function rawBody(): string
{
    return (string) file_get_contents(__DIR__.'/../Fixtures/tiktok/webhook_publicly_available.json');
}

/** The header TikTok sends: t=<unix time>,s=<hex HMAC-SHA256 of "{t}.{body}">. */
function header(string $body, int $timestamp = NOW, string $secret = SECRET): string
{
    return 't='.$timestamp.',s='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
}

// --- verify() ---------------------------------------------------------------------------

it('accepts a signature made with the client secret over "{t}.{raw body}"', function () {
    $body = rawBody();

    expect(TikTokWebhook::verify($body, header($body), SECRET, now: NOW))->toBeTrue()
        ->and(TikTokWebhook::verify($body, TikTokWebhook::sign($body, SECRET, NOW), SECRET, now: NOW))->toBeTrue()
        ->and(TikTokWebhook::sign($body, SECRET, NOW))->toBe(header($body));
});

it('rejects a tampered body, a re-encoded body or another secret', function () {
    $body = rawBody();
    $header = header($body);

    expect(TikTokWebhook::verify($body.' ', $header, SECRET, now: NOW))->toBeFalse()
        ->and(TikTokWebhook::verify(json_encode(json_decode($body, true)), $header, SECRET, now: NOW))->toBeFalse()
        ->and(TikTokWebhook::verify($body, $header, 'another-secret', now: NOW))->toBeFalse()
        ->and(TikTokWebhook::verify($body, $header, '', now: NOW))->toBeFalse();
});

it('rejects a timestamp outside the tolerance, in either direction', function () {
    $body = rawBody();

    expect(TikTokWebhook::verify($body, header($body, NOW - 300), SECRET, now: NOW))->toBeTrue()
        ->and(TikTokWebhook::verify($body, header($body, NOW - 301), SECRET, now: NOW))->toBeFalse()
        ->and(TikTokWebhook::verify($body, header($body, NOW + 301), SECRET, now: NOW))->toBeFalse()
        ->and(TikTokWebhook::verify($body, header($body, NOW - 3600), SECRET, tolerance: 7200, now: NOW))->toBeTrue()
        ->and(TikTokWebhook::verify($body, header($body, NOW - 86400), SECRET, tolerance: 0, now: NOW))->toBeTrue();

    // Against the real clock by default.
    expect(TikTokWebhook::verify($body, header($body, time()), SECRET))->toBeTrue()
        ->and(TikTokWebhook::verify($body, header($body, NOW), SECRET))->toBeFalse();
});

it('rejects a missing or malformed header without throwing', function (?string $header) {
    expect(TikTokWebhook::verify(rawBody(), $header, SECRET, now: NOW))->toBeFalse();
})->with([
    'missing' => [null],
    'empty' => [''],
    'garbage' => ['not-a-signature'],
    'no timestamp' => ['s='.str_repeat('a', 64)],
    'no signature' => ['t='.NOW],
    'signature not hex' => ['t='.NOW.',s='.str_repeat('z', 64)],
    'signature too short' => ['t='.NOW.',s=abc123'],
    'timestamp not numeric' => ['t=yesterday,s='.str_repeat('a', 64)],
    'wrong signature' => ['t='.NOW.',s='.str_repeat('a', 64)],
]);

it('tolerates spacing, upper-case hex and several signatures', function () {
    $body = rawBody();
    $signature = hash_hmac('sha256', NOW.'.'.$body, SECRET);

    expect(TikTokWebhook::verify($body, ' t='.NOW.' , s='.strtoupper($signature).' ', SECRET, now: NOW))->toBeTrue()
        ->and(TikTokWebhook::verify($body, 't='.NOW.',s='.str_repeat('0', 64).',s='.$signature, SECRET, now: NOW))->toBeTrue()
        ->and(TikTokWebhook::SIGNATURE_HEADER)->toBe('Tiktok-Signature');
});

// --- parse() ------------------------------------------------------------------------------

it('decodes the nested JSON content of a delivery', function () {
    $event = TikTokWebhook::parse(rawBody());

    expect($event)->toBeInstanceOf(TikTokWebhookEvent::class)
        ->and($event->event)->toBe(TikTokWebhookEvent::PUBLICLY_AVAILABLE)
        ->and($event->userOpenId)->toBe('act.example12345Example12345Example')
        ->and($event->clientKey)->toBe('bwo2m45353a6k85')
        ->and($event->createTime)->toBe(1615338610)
        ->and($event->publishId)->toBe('v_pub_url~v2.7301234567890123456')
        ->and($event->postId)->toBe('7301234567890123456')
        ->and($event->publishType)->toBe('DIRECT_PUBLISH')
        ->and($event->content)->toHaveKeys(['publish_id', 'publish_type', 'post_id'])
        ->and($event->raw['event'])->toBe('post.publish.publicly_available')
        ->and($event->is(TikTokWebhookEvent::PUBLICLY_AVAILABLE, TikTokWebhookEvent::PUBLISH_COMPLETE))->toBeTrue()
        ->and($event->isPublishEvent())->toBeTrue()
        ->and($event->isFailure())->toBeFalse()
        ->and($event->failureReason())->toBeNull()
        ->and($event->failureMessage())->toBeNull()
        ->and($event->postUrl('notion.scheduler'))->toBe('https://www.tiktok.com/@notion.scheduler/video/7301234567890123456')
        ->and($event->postUrl(null))->toBeNull();
});

it('maps post.publish.failed through the fail_reason table', function (string $reason, FailureReason $expected, bool $restartable) {
    $event = TikTokWebhook::parse(json_encode([
        'client_key' => 'bwo2m45353a6k85',
        'event' => 'post.publish.failed',
        'create_time' => 1615338610,
        'user_openid' => 'act.example',
        'content' => json_encode(['publish_id' => 'v_pub_file~v2.1', 'reason' => $reason, 'publish_type' => 'DIRECT_PUBLISH']),
    ]));

    expect($event->isFailure())->toBeTrue()
        ->and($event->reason)->toBe($reason)
        ->and($event->failureReason())->toBe($expected)
        ->and($event->isRestartableFailure())->toBe($restartable)
        ->and($event->failureMessage())->toBeString();
})->with([
    ['frame_rate_check_failed', FailureReason::MediaRejected, false],
    ['video_pull_failed', FailureReason::MediaRejected, true],
    ['internal', FailureReason::ServerError, true],
    ['spam_risk_user_banned_from_posting', FailureReason::AccountRestricted, false],
    ['auth_removed', FailureReason::InvalidToken, false],
]);

it('parses authorization.removed with its numeric reason', function () {
    $event = TikTokWebhook::parse('{"client_key":"k","event":"authorization.removed","create_time":"1615338610","user_openid":"act.example","content":"{\"reason\":1}"}');

    expect($event->event)->toBe(TikTokWebhookEvent::AUTHORIZATION_REMOVED)
        ->and($event->userOpenId)->toBe('act.example')
        ->and($event->reason)->toBe('1')
        ->and($event->createTime)->toBe(1615338610)
        ->and($event->publishId)->toBeNull()
        ->and($event->isPublishEvent())->toBeFalse()
        ->and($event->isFailure())->toBeTrue()
        ->and($event->failureReason())->toBe(FailureReason::InvalidToken)
        ->and($event->failureMessage())->toContain('reconnected')
        ->and($event->isRestartableFailure())->toBeFalse();
});

it('accepts already-decoded content and survives undecodable content', function () {
    $decoded = TikTokWebhook::parse('{"event":"post.publish.complete","content":{"publish_id":"p1","publish_type":"INBOX_SHARE"}}');
    $broken = TikTokWebhook::parse('{"event":"post.publish.inbox_delivered","content":"{not json"}');
    $missing = TikTokWebhook::parse('{"event":" post.publish.no_longer_publicly_available "}');

    expect($decoded->publishId)->toBe('p1')
        ->and($decoded->publishType)->toBe('INBOX_SHARE')
        ->and($decoded->userOpenId)->toBeNull()
        ->and($decoded->createTime)->toBeNull()
        ->and($broken->content)->toBe([])
        ->and($broken->publishId)->toBeNull()
        ->and($missing->event)->toBe(TikTokWebhookEvent::NO_LONGER_PUBLICLY_AVAILABLE)
        ->and($missing->postUrl('notion.scheduler'))->toBeNull();
});

it('recognises the removal event under the spelling TikTok documents and the corrected one', function (string $sent) {
    $event = TikTokWebhook::parse(json_encode([
        'client_key' => 'bwo2m45353a6k85',
        'event' => $sent,
        'create_time' => 1615338610,
        'user_openid' => 'act.example',
        'content' => json_encode(['publish_id' => 'v_pub_url~v2.1', 'post_id' => '7301234567890123456', 'publish_type' => 'DIRECT_PUBLISH']),
    ]));

    expect($event->is(TikTokWebhookEvent::NO_LONGER_PUBLICLY_AVAILABLE))->toBeTrue()
        ->and($event->is(TikTokWebhookEvent::NO_LONGER_PUBLICALY_AVAILABLE))->toBeTrue()
        ->and($event->is(TikTokWebhookEvent::PUBLISH_COMPLETE, TikTokWebhookEvent::NO_LONGER_PUBLICLY_AVAILABLE))->toBeTrue()
        ->and($event->is(TikTokWebhookEvent::PUBLICLY_AVAILABLE))->toBeFalse()
        ->and($event->event)->toBe($sent)
        ->and($event->postId)->toBe('7301234567890123456')
        ->and($event->isPublishEvent())->toBeTrue()
        ->and($event->isFailure())->toBeFalse();
})->with([
    // https://developers.tiktok.com/doc/content-posting-api-reference-get-video-status (Content Posting webhooks)
    'documented (publicaly)' => ['post.publish.no_longer_publicaly_available'],
    'corrected (publicly)' => ['post.publish.no_longer_publicly_available'],
]);

it('refuses a body that is not a JSON object with an event', function (string $body) {
    expect(fn () => TikTokWebhook::parse($body))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'not json' => ['event=post.publish.complete'],
    'scalar' => ['"post.publish.complete"'],
    'no event' => ['{"content":"{}"}'],
    'blank event' => ['{"event":"  "}'],
    'event not a string' => ['{"event":42}'],
]);
