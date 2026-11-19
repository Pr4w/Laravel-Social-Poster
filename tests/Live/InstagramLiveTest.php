<?php

namespace SocialPoster\Tests\Live\Instagram;

use Illuminate\Support\Facades\Http;
use SocialPoster\Facades\SocialPoster;
use SocialPoster\Tests\Live\Live;
use SocialPoster\ValueObjects\Media;

/*
 * Instagram live checks. LOGIN is 'instagram' (Instagram Login, IG... token,
 * graph.instagram.com) or 'facebook' (Facebook Login, EAA... token,
 * graph.facebook.com); it defaults to the token prefix. The publishing check
 * creates a real post: Instagram has no delete API, so use a test account.
 */

const REQUIRED = ['ACCOUNT_ID', 'TOKEN'];

$env = Live::env('INSTAGRAM', REQUIRED, ['LOGIN', 'IMAGE_URL', 'PNG_URL']);

if ($env === null) {
    it('runs the Instagram live checks')->skip(Live::skipMessage('INSTAGRAM', REQUIRED));

    return;
}

/** @param array<string, ?string> $env */
function login(array $env): string
{
    return $env['LOGIN'] ?? (str_starts_with((string) $env['TOKEN'], 'IG') ? 'instagram' : 'facebook');
}

function host(string $login): string
{
    $version = config('social.api.instagram.graph_version', 'v25.0');

    return ($login === 'instagram' ? 'https://graph.instagram.com/' : 'https://graph.facebook.com/').$version;
}

/** @param array<string, ?string> $env */
function credentials(array $env): array
{
    return array_filter(['account_id' => $env['ACCOUNT_ID'], 'access_token' => $env['TOKEN'], 'login' => $env['LOGIN']]);
}

beforeEach(fn () => Live::boot());

it('accepts Authorization: Bearer on GETs: account, media and stories listings, publishing limit', function () use ($env) {
    $base = host(login($env));
    $client = Http::withToken($env['TOKEN']);

    $account = $client->get("{$base}/{$env['ACCOUNT_ID']}", ['fields' => 'id,username']);
    $media = $client->get("{$base}/{$env['ACCOUNT_ID']}/media", ['fields' => 'id,caption,timestamp,permalink,media_type', 'limit' => 25]);
    $stories = $client->get("{$base}/{$env['ACCOUNT_ID']}/stories", ['fields' => 'id,timestamp,permalink']);
    $limit = $client->get("{$base}/{$env['ACCOUNT_ID']}/content_publishing_limit", ['fields' => 'quota_usage,config']);

    foreach (['account' => $account, 'media listing' => $media, 'stories listing' => $stories, 'content_publishing_limit' => $limit] as $check => $response) {
        Live::record('INSTAGRAM', "bearer GET {$check}", $response);
    }

    expect($account->status())->toBe(200)
        ->and($media->status())->toBe(200)
        ->and($stories->status())->toBe(200)
        ->and($limit->status())->toBe(200)
        ->and($limit->json('data.0.quota_usage'))->toBeInt()
        ->and($limit->json('data.0.config.quota_total'))->toBeInt();
});

it('records the 190 body when the token is sent to the other Graph host', function () use ($env) {
    $other = host(login($env) === 'instagram' ? 'facebook' : 'instagram');
    $response = Http::withToken($env['TOKEN'])->get("{$other}/{$env['ACCOUNT_ID']}", ['fields' => 'id']);

    Live::record('INSTAGRAM', 'token sent to the other host', $response);

    expect($response->status())->toBeGreaterThanOrEqual(400)
        ->and($response->json('error.code'))->toBe(190);
});

it('publishes a photo with alt text and sees its container turn PUBLISHED', function () use ($env) {
    Live::requirePublishing();
    $image = Live::requireValue($env['IMAGE_URL'], 'SOCIAL_LIVE_INSTAGRAM_IMAGE_URL');

    $result = SocialPoster::on('instagram')
        ->using(credentials($env))
        ->caption(Live::caption())
        ->media(Media::image($image, 'A live-check photo'))
        ->postNow()['instagram'];

    Live::record('INSTAGRAM', 'photo publish', $result);

    expect($result->success)->toBeTrue();

    $container = Http::withToken($env['TOKEN'])->get(host(login($env)).'/'.$result->payload['container_id'], ['fields' => 'status_code,status']);

    Live::record('INSTAGRAM', 'container status after media_publish', $container);

    expect($container->json('status_code'))->toBe('PUBLISHED');
});

it('still accepts a PNG image (kept accepted unless jpeg_only)', function () use ($env) {
    Live::requirePublishing();
    $png = Live::requireValue($env['PNG_URL'], 'SOCIAL_LIVE_INSTAGRAM_PNG_URL');

    $result = SocialPoster::on('instagram')
        ->using(credentials($env))
        ->caption(Live::caption())
        ->media(Media::image($png))
        ->postNow()['instagram'];

    Live::record('INSTAGRAM', 'png publish', $result);

    expect($result->success)->toBeTrue();
});

foreach ([
    'container ERROR status text format (Media upload has failed with error code N)',
    'media_type=REELS vs VIDEO for carousel video children',
    'collaborators as JSON array, share_to_feed as JSON boolean, thumb_offset in ms',
    'alt_text on carousel image children',
    '2207051 is a spam block (AccountRestricted)',
    'Http::pool with concurrency 5 for a 10-item carousel is not throttled',
] as $check) {
    it("[manual] {$check}")->skip('Manual check: see tests/Live/README.md (Instagram).');
}
