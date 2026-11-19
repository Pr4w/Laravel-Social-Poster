<?php

namespace SocialPoster\Tests\Live\LinkedIn;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use SocialPoster\Facades\SocialPoster;
use SocialPoster\Platforms\LinkedIn\LinkedInOptions;
use SocialPoster\Tests\Live\Live;

/*
 * LinkedIn live checks. AUTHOR is urn:li:person:... or urn:li:organization:...
 * The publishing checks create a post (CONNECTIONS visibility for a member)
 * with a first comment and delete the post afterwards.
 */

const REQUIRED = ['TOKEN', 'AUTHOR'];

$env = Live::env('LINKEDIN', REQUIRED);

if ($env === null) {
    it('runs the LinkedIn live checks')->skip(Live::skipMessage('LINKEDIN', REQUIRED));

    return;
}

function client(string $token): PendingRequest
{
    return Http::withToken($token)->withHeaders([
        'LinkedIn-Version' => (string) config('social.api.linkedin.version', '202605'),
        'X-Restli-Protocol-Version' => '2.0.0',
    ]);
}

beforeEach(fn () => Live::boot());

it('reads posts by author with the FINDER method (needs r_member_social or r_organization_social)', function () use ($env) {
    $response = client($env['TOKEN'])->withHeaders(['X-RestLi-Method' => 'FINDER'])->get('https://api.linkedin.com/rest/posts', [
        'q' => 'author',
        'author' => $env['AUTHOR'],
        'count' => 10,
        'sortBy' => 'LAST_MODIFIED',
    ]);

    Live::record('LINKEDIN', 'posts finder', $response);

    expect($response->status())->toBeIn([200, 403]);
});

it('publishes a post with a first comment, records both answers, then deletes the post', function () use ($env) {
    Live::requirePublishing();
    $caption = Live::caption().' (escaping check: a | b, C# and #php)';

    $visibility = str_starts_with($env['AUTHOR'], 'urn:li:person:') ? 'CONNECTIONS' : 'PUBLIC';

    $results = SocialPoster::on('linkedin')
        ->using(['access_token' => $env['TOKEN'], 'author' => $env['AUTHOR']])
        ->caption($caption)
        ->withOptions(new LinkedInOptions(visibility: $visibility))
        ->withComment('First comment from the live check')
        ->inlineComment()
        ->postNow();

    Live::record('LINKEDIN', 'text publish', $results['linkedin']);

    expect($results['linkedin']->success)->toBeTrue();

    $urn = (string) $results['linkedin']->platformPostId;
    $post = client($env['TOKEN'])->get('https://api.linkedin.com/rest/posts/'.rawurlencode($urn));

    Live::record('LINKEDIN', 'read back the commentary as sent', $post);
    Live::record('LINKEDIN', 'delete', client($env['TOKEN'])->delete('https://api.linkedin.com/rest/posts/'.rawurlencode($urn)));
});

foreach ([
    '3,000-character limit: visible text or the raw little-text string',
    'video thumbnail PUT before finalizeUpload, non-empty uploadToken in finalize',
    'uploadUrlsExpireAt in milliseconds, an expired part URL answers 401',
    'concurrent part PUTs (upload_concurrency > 1)',
    'documents reach AVAILABLE in reasonable time',
    'poll content shape {question, options[{text}], settings{duration}} and limits',
    'article content: title required, real title/description limits',
    'feedDistribution NONE and targetEntities for organizations; CONNECTIONS / LOGGED_IN per author type',
    'w_member_social alone (without w_member_social_feed) creates comments',
    'daily throttle message containing DAY',
    'MEDIA_ASSET_WAITING_UPLOAD / MEDIA_ASSET_PROCESSING_FAILED placement and RESTRICTED_MEMBER',
    '422 "Content is a duplicate of urn:li:share:..." message',
    'vanityUrl, people and organizations?q=vanityName lookups with Rest.li 2.0 headers',
] as $check) {
    it("[manual] {$check}")->skip('Manual check: see tests/Live/README.md (LinkedIn).');
}
