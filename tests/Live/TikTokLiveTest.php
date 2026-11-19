<?php

namespace SocialPoster\Tests\Live\TikTok;

use Illuminate\Support\Facades\Http;
use SocialPoster\Facades\SocialPoster;
use SocialPoster\Platforms\TikTok\TikTokOptions;
use SocialPoster\Tests\Live\Live;
use SocialPoster\ValueObjects\Media;

/*
 * TikTok live checks. The publishing check sends a photo to the creator's
 * inbox as a draft (MEDIA_UPLOAD): nothing is posted publicly, but drafts
 * count against the 5 pending shares per 24 h. IMAGE_URL must be on a domain
 * verified for the app.
 */

const REQUIRED = ['TOKEN', 'OPEN_ID'];
const API = 'https://open.tiktokapis.com/v2';

$env = Live::env('TIKTOK', REQUIRED, ['IMAGE_URL']);

if ($env === null) {
    it('runs the TikTok live checks')->skip(Live::skipMessage('TIKTOK', REQUIRED));

    return;
}

beforeEach(fn () => Live::boot());

it('queries creator_info with a null body and reads the privacy options', function () use ($env) {
    $response = Http::withToken($env['TOKEN'])
        ->withBody('', 'application/json; charset=UTF-8')
        ->post(API.'/post/publish/creator_info/query/');

    Live::record('TIKTOK', 'creator_info', $response);

    expect($response->status())->toBe(200)
        ->and($response->json('error.code'))->toBe('ok')
        ->and($response->json('data.privacy_level_options'))->toBeArray();
});

it('answers an invalid token with HTTP 401 access_token_invalid', function () {
    $response = Http::withToken('act.invalid-live-check-token')
        ->withBody('', 'application/json; charset=UTF-8')
        ->post(API.'/post/publish/creator_info/query/');

    Live::record('TIKTOK', 'invalid token', $response);

    expect($response->status())->toBe(401)
        ->and($response->json('error.code'))->toBe('access_token_invalid');
});

it('sends a caption-less draft photo with post_info as an empty object', function () use ($env) {
    Live::requirePublishing();
    $image = Live::requireValue($env['IMAGE_URL'], 'SOCIAL_LIVE_TIKTOK_IMAGE_URL');

    $result = SocialPoster::on('tiktok')
        ->using(['access_token' => $env['TOKEN'], 'open_id' => $env['OPEN_ID']])
        ->media(Media::image($image))
        ->withOptions(new TikTokOptions)
        ->postNow()['tiktok'];

    Live::record('TIKTOK', 'draft photo without caption', $result);

    expect($result->success)->toBeTrue();
});

foreach ([
    'DIRECT_POST with brand toggles false on an unaudited (SELF_ONLY) client, video and photo',
    'upload host Content-Range on 416, and 416/206/201 for a re-sent chunk',
    'upload host answering 200 instead of 206/201',
    'orphaned FILE_UPLOAD inits and spam_risk_too_many_pending_share',
    'url_ownership_unverified fallback to FILE_UPLOAD on the same account',
    'publicaly_available_post_id as number or string, and its delay after PUBLISH_COMPLETE',
    'is_aigc on DIRECT_POST video/init (post_info) and on photo content/init (top level)',
    'photo dimensions that trigger picture_size_check_failed (1080p)',
    'webhook signature header t=<ts>,s=<hex> over "{t}.{raw body}", numeric authorization.removed reason, the removal event spelling (no_longer_publicaly_available)',
    'HTTP-200 error codes from creator_info stop the publish',
] as $check) {
    it("[manual] {$check}")->skip('Manual check: see tests/Live/README.md (TikTok).');
}
