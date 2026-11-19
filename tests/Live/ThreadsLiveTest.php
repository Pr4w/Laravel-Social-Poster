<?php

namespace SocialPoster\Tests\Live\Threads;

use Illuminate\Support\Facades\Http;
use SocialPoster\Facades\SocialPoster;
use SocialPoster\Tests\Live\Live;

/*
 * Threads live checks. ACCOUNT_ID is the numeric Threads user id (not the
 * Instagram id). The publishing check makes a real text post and deletes it
 * afterwards when the token has threads_delete.
 */

const REQUIRED = ['ACCOUNT_ID', 'TOKEN'];

$env = Live::env('THREADS', REQUIRED);

if ($env === null) {
    it('runs the Threads live checks')->skip(Live::skipMessage('THREADS', REQUIRED));

    return;
}

function base(): string
{
    return 'https://graph.threads.net/'.config('social.api.threads.graph_version', 'v1.0');
}

beforeEach(fn () => Live::boot());

it('accepts Authorization: Bearer on GETs: profile, threads listing, publishing limit', function () use ($env) {
    $client = Http::withToken($env['TOKEN']);

    $profile = $client->get(base().'/me', ['fields' => 'id,username']);
    $listing = $client->get(base()."/{$env['ACCOUNT_ID']}/threads", ['fields' => 'id,text,timestamp,permalink', 'limit' => 25]);
    $limit = $client->get(base()."/{$env['ACCOUNT_ID']}/threads_publishing_limit", ['fields' => 'quota_usage,config,reply_quota_usage,reply_config']);

    foreach (['profile' => $profile, 'threads listing' => $listing, 'publishing limit' => $limit] as $check => $response) {
        Live::record('THREADS', "bearer GET {$check}", $response);
    }

    expect($profile->status())->toBe(200)
        ->and($profile->json('id'))->toBe($env['ACCOUNT_ID'])
        ->and($listing->status())->toBe(200)
        ->and($limit->status())->toBe(200);

    foreach ((array) $listing->json('data') as $post) {
        // ISO-8601 with a +0000 offset, as the PUBLISHED recovery parses it.
        expect($post['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+0000$/');
    }
});

it('publishes a text post whose id the listing, permalink and replies accept, then deletes it', function () use ($env) {
    Live::requirePublishing();
    $caption = Live::caption()."\nwith a second line ✨";

    $result = SocialPoster::on('threads')
        ->using(['account_id' => $env['ACCOUNT_ID'], 'access_token' => $env['TOKEN']])
        ->caption($caption)
        ->postNow()['threads'];

    Live::record('THREADS', 'text publish', $result);

    expect($result->success)->toBeTrue()
        ->and($result->url)->not->toBeNull();

    $listing = Http::withToken($env['TOKEN'])->get(base()."/{$env['ACCOUNT_ID']}/threads", ['fields' => 'id,text,timestamp,permalink', 'limit' => 5]);
    $found = collect($listing->json('data'))->firstWhere('id', $result->platformPostId);

    Live::record('THREADS', 'listing after publish', $listing);

    expect($found)->not->toBeNull()
        ->and($found['text'])->toBe(trim($caption));

    Live::record('THREADS', 'delete', Http::withToken($env['TOKEN'])->delete(base().'/'.$result->platformPostId));
});

foreach ([
    'POST with both the Bearer header and an access_token body field (creates, threads_publish, pooled items)',
    'poll_attachment as a nested JSON object; Threads\' per-option limit against the 25 characters enforced',
    'allowlisted_country_codes as "US,CA" vs an array',
    'is_spoiler_media placement on carousels, topic_tag without #',
    'threads_publish error for a container that is already PUBLISHED',
    'THREADS_API__LINK_LIMIT_EXCEEDED body shape',
    '1920 px video limit: display (rotated) width or coded width',
    'TEXT reply containers: IN_PROGRESS or no status, and 4279009 on reply publish',
    'error code and body of the 250/24h post quota and the 1,000/24h reply quota',
    'Http::pool with 10 concurrent carousel-item creates is not throttled',
] as $check) {
    it("[manual] {$check}")->skip('Manual check: see tests/Live/README.md (Threads).');
}
