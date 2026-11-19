<?php

namespace SocialPoster\Tests\Live\Pinterest;

use Illuminate\Support\Facades\Http;
use SocialPoster\Facades\SocialPoster;
use SocialPoster\Tests\Live\Live;
use SocialPoster\ValueObjects\Media;

/*
 * Pinterest live checks. They run against the Sandbox unless
 * SOCIAL_LIVE_PINTEREST_SANDBOX=false; Sandbox boards are separate from
 * production boards, so BOARD_ID must belong to the environment used. The
 * publishing check pins IMAGE_URL and deletes the Pin.
 */

const REQUIRED = ['TOKEN', 'BOARD_ID'];

$env = Live::env('PINTEREST', REQUIRED, ['SANDBOX', 'IMAGE_URL']);

if ($env === null) {
    it('runs the Pinterest live checks')->skip(Live::skipMessage('PINTEREST', REQUIRED));

    return;
}

function sandbox(array $env): bool
{
    return $env['SANDBOX'] === null || filter_var($env['SANDBOX'], FILTER_VALIDATE_BOOLEAN);
}

function api(array $env): string
{
    return sandbox($env) ? 'https://api-sandbox.pinterest.com/v5' : 'https://api.pinterest.com/v5';
}

beforeEach(function () use ($env) {
    Live::boot();
    config(['social.api.pinterest.sandbox' => sandbox($env)]);
});

it('reads the board and its Pins, recording the order and created_at format', function () use ($env) {
    $board = Http::withToken($env['TOKEN'])->get(api($env).'/boards/'.$env['BOARD_ID']);
    $pins = Http::withToken($env['TOKEN'])->get(api($env).'/boards/'.$env['BOARD_ID'].'/pins', ['page_size' => 25]);

    Live::record('PINTEREST', 'board', $board);
    Live::record('PINTEREST', 'board pins', $pins);

    expect($board->status())->toBe(200)
        ->and($pins->status())->toBe(200)
        ->and($pins->json('items'))->toBeArray();
});

it('pins an image, finds it first on the board with identical text, then deletes it', function () use ($env) {
    Live::requirePublishing();
    $image = Live::requireValue($env['IMAGE_URL'], 'SOCIAL_LIVE_PINTEREST_IMAGE_URL');
    $caption = Live::caption()." — l'été ✨";

    $result = SocialPoster::on('pinterest')
        ->using(['access_token' => $env['TOKEN'], 'board_id' => $env['BOARD_ID'], 'sandbox' => sandbox($env)])
        ->title('social-poster live check')
        ->caption($caption)
        ->media(Media::image($image))
        ->postNow()['pinterest'];

    Live::record('PINTEREST', 'image pin', $result);

    expect($result->success)->toBeTrue();

    $pins = Http::withToken($env['TOKEN'])->get(api($env).'/boards/'.$env['BOARD_ID'].'/pins', ['page_size' => 10]);

    Live::record('PINTEREST', 'board pins after create', $pins);

    expect($pins->json('items.0.id'))->toBe($result->platformPostId)
        ->and($pins->json('items.0.description'))->toBe($caption);

    Live::record('PINTEREST', 'delete', Http::withToken($env['TOKEN'])->delete(api($env).'/pins/'.$result->platformPostId));
});

foreach ([
    'video_id Pin with only cover_image_key_frame_time (no cover_image_url), and the media/{id} probe',
    'real error codes: 404 board not found, 403 image too small/large/broken, 429 and its headers',
    'Trial-access 403 code 29 message',
    'codes 2786/2787, 503 code 21 and 500 code 12 from the ~7 s create deadline',
    'boards/{board}/pins also lists Pins saved into a section',
    'S3 upload: streamed multipart answers 204, AccessDenied on an expired policy',
    'video upload (POST /media) in the Sandbox',
    'alt_text on video Pins; per-item title/description/link on carousels',
    'maximum JSON body of POST /pins with multiple_image_base64 (5 x 20 MB)',
    'ad_account_id honoured on boards/{board}/pins',
    'real organic video maximum duration (900 s vs 5 minutes)',
] as $check) {
    it("[manual] {$check}")->skip('Manual check: see tests/Live/README.md (Pinterest).');
}
