<?php

namespace SocialPoster\Tests\Live\X;

use Illuminate\Support\Facades\Http;
use SocialPoster\Facades\SocialPoster;
use SocialPoster\Tests\Live\Live;

/*
 * X live checks. TOKEN is an OAuth 2.0 user-context token (tweet.read,
 * tweet.write, users.read, media.write). Every read is billed on pay-per-use
 * plans. The publishing check posts, provokes the duplicate answer, and
 * deletes the post.
 */

const REQUIRED = ['TOKEN', 'USER_ID'];
const API = 'https://api.x.com/2';

$env = Live::env('X', REQUIRED);

if ($env === null) {
    it('runs the X live checks')->skip(Live::skipMessage('X', REQUIRED));

    return;
}

beforeEach(fn () => Live::boot());

it('identifies the token owner with GET /2/users/me', function () use ($env) {
    $response = Http::withToken($env['TOKEN'])->get(API.'/users/me');

    Live::record('X', 'users/me', $response);

    expect($response->status())->toBe(200)
        ->and($response->json('data.id'))->toBe($env['USER_ID']);
});

it('records the user timeline format: media_keys, entities in text, note_tweet', function () use ($env) {
    $response = Http::withToken($env['TOKEN'])->get(API."/users/{$env['USER_ID']}/tweets", [
        'start_time' => gmdate('Y-m-d\TH:i:s\Z', time() - 7 * 86400),
        'exclude' => 'retweets',
        'tweet.fields' => 'attachments,note_tweet,created_at',
        'max_results' => 10,
    ]);

    Live::record('X', 'users/{id}/tweets', $response);

    expect($response->status())->toBe(200);

    foreach ((array) $response->json('data') as $post) {
        foreach ((array) ($post['attachments']['media_keys'] ?? []) as $key) {
            expect($key)->toMatch('/^(3|7|16)_\d+$/');
        }
    }
});

it('posts, records the duplicate answer to an identical re-send, then deletes the post', function () use ($env) {
    Live::requirePublishing();
    $caption = Live::caption();

    $result = SocialPoster::on('x')
        ->using(['access_token' => $env['TOKEN'], 'user_id' => $env['USER_ID']])
        ->caption($caption)
        ->postNow()['x'];

    Live::record('X', 'text publish', $result);

    expect($result->success)->toBeTrue();

    $duplicate = Http::withToken($env['TOKEN'])->post(API.'/tweets', ['text' => $caption]);

    Live::record('X', 'duplicate re-send', $duplicate);

    expect($duplicate->status())->toBe(403);

    Live::record('X', 'rate-limit headers', [
        'x-rate-limit-limit' => $duplicate->header('x-rate-limit-limit'),
        'x-user-limit-24hour-limit' => $duplicate->header('x-user-limit-24hour-limit'),
        'x-app-limit-24hour-limit' => $duplicate->header('x-app-limit-24hour-limit'),
    ]);

    Live::record('X', 'delete', Http::withToken($env['TOKEN'])->delete(API.'/tweets/'.$result->platformPostId));
});

foreach ([
    'a re-send that reuses the same media_ids, and reusing a media id in a second post',
    'v2 bodies: locked and suspended accounts, 402 CreditsDepleted, 429 usage-capped, Feb 2026 reply restriction, video over the duration cap',
    'x-user-limit-24hour-* / x-app-limit-24hour-* semantics on a POST /2/tweets 429',
    'STATUS without processing_info',
    'POST /2/media/metadata alt text on a processed GIF, refused or ignored on video',
    'APPEND re-sent segment_index after a lost connection',
    'quote_tweet_id together with media; poll options count 25 by code point',
    '4K and extreme aspect-ratio videos within 1:3..3:1',
    'media.write needed for one-shot uploads, INITIALIZE/APPEND/FINALIZE/STATUS and metadata',
] as $check) {
    it("[manual] {$check}")->skip('Manual check: see tests/Live/README.md (X).');
}
