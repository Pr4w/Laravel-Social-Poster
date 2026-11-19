<?php

namespace SocialPoster\Tests\Live\Facebook;

use Illuminate\Support\Facades\Http;
use SocialPoster\Facades\SocialPoster;
use SocialPoster\Platforms\Facebook\FacebookOptions;
use SocialPoster\Tests\Live\Live;
use SocialPoster\ValueObjects\Media;

/*
 * Facebook Page live checks. Needs a Page access token with pages_manage_posts
 * and pages_read_engagement. Publishing checks create SCHEDULED posts (never
 * visible) and delete them afterwards.
 */

const REQUIRED = ['PAGE_ID', 'PAGE_TOKEN'];

$env = Live::env('FACEBOOK', REQUIRED, ['IMAGE_URL', 'IMAGE_URL_2']);

if ($env === null) {
    it('runs the Facebook live checks')->skip(Live::skipMessage('FACEBOOK', REQUIRED));

    return;
}

function graph(): string
{
    return 'https://graph.facebook.com/'.config('social.api.facebook.graph_version', 'v25.0');
}

beforeEach(fn () => Live::boot());

it('lists published_posts with attachment targets and honours since', function () use ($env) {
    $since = time() - 7 * 86400;
    $response = Http::withToken($env['PAGE_TOKEN'])->get(graph()."/{$env['PAGE_ID']}/published_posts", [
        'fields' => 'id,message,created_time,attachments{target{id},subattachments{target{id}}}',
        'since' => $since,
        'limit' => 25,
    ]);

    Live::record('FACEBOOK', 'published_posts', $response);

    expect($response->status())->toBe(200)
        ->and($response->json('data'))->toBeArray();

    foreach ($response->json('data') as $post) {
        expect(strtotime($post['created_time']))->toBeGreaterThanOrEqual($since);
    }
});

it('lists the Page stories with post_id, media_id and creation_time since a time', function () use ($env) {
    $response = Http::withToken($env['PAGE_TOKEN'])->get(graph()."/{$env['PAGE_ID']}/stories", [
        'since' => time() - 7 * 86400,
        'limit' => 25,
    ]);

    Live::record('FACEBOOK', 'stories', $response);

    expect($response->status())->toBe(200)
        ->and($response->json('data'))->toBeArray();
});

it('reads a post\'s top-level comments newest first, with their author', function () use ($env) {
    $listing = Http::withToken($env['PAGE_TOKEN'])->get(graph()."/{$env['PAGE_ID']}/published_posts", ['fields' => 'id', 'limit' => 1]);
    $id = $listing->json('data.0.id');

    if (! is_string($id)) {
        test()->markTestSkipped('The Page has no published post to read comments from.');
    }

    $response = Http::withToken($env['PAGE_TOKEN'])->get(graph()."/{$id}/comments", [
        'fields' => 'id,message,from,created_time',
        'filter' => 'toplevel',
        'order' => 'reverse_chronological',
        'limit' => 25,
    ]);

    Live::record('FACEBOOK', 'post comments', $response);

    expect($response->status())->toBe(200)
        ->and($response->json('data'))->toBeArray();
});

it('lists a just-scheduled /feed post with its message in scheduled_posts, then deletes it', function () use ($env) {
    Live::requirePublishing();
    $caption = Live::caption();

    $result = SocialPoster::on('facebook')
        ->using(['account_id' => $env['PAGE_ID'], 'page_access_token' => $env['PAGE_TOKEN']])
        ->caption($caption)
        ->withOptions(new FacebookOptions(scheduledAt: now()->addDays(2)))
        ->postNow()['facebook'];

    Live::record('FACEBOOK', 'scheduled /feed post', $result);

    expect($result->success)->toBeTrue()
        ->and($result->state)->toBe('scheduled');

    $listing = Http::withToken($env['PAGE_TOKEN'])->get(graph()."/{$env['PAGE_ID']}/scheduled_posts", [
        'fields' => 'id,message,created_time,attachments{target{id},subattachments{target{id}}}',
        'limit' => 25,
    ]);

    Live::record('FACEBOOK', 'scheduled_posts', $listing);

    expect(collect($listing->json('data'))->firstWhere('message', $caption))->not->toBeNull();

    Live::record('FACEBOOK', 'delete scheduled post', Http::withToken($env['PAGE_TOKEN'])->delete(graph().'/'.$result->platformPostId));
});

it('attaches unpublished photos whose ids match the subattachment targets (scheduled, then deleted)', function () use ($env) {
    Live::requirePublishing();
    $first = Live::requireValue($env['IMAGE_URL'], 'SOCIAL_LIVE_FACEBOOK_IMAGE_URL');
    $media = [Media::image($first)];

    if ($env['IMAGE_URL_2'] !== null) {
        $media[] = Media::image($env['IMAGE_URL_2']);
    }

    $caption = Live::caption();
    $result = SocialPoster::on('facebook')
        ->using(['account_id' => $env['PAGE_ID'], 'page_access_token' => $env['PAGE_TOKEN']])
        ->caption($caption)
        ->media(...$media)
        ->withOptions(new FacebookOptions(scheduledAt: now()->addDays(2)))
        ->postNow()['facebook'];

    Live::record('FACEBOOK', 'scheduled photo post', $result);

    expect($result->success)->toBeTrue();

    $post = Http::withToken($env['PAGE_TOKEN'])->get(graph().'/'.$result->platformPostId, [
        'fields' => 'id,message,attachments{target{id},subattachments{target{id}}}',
    ]);

    Live::record('FACEBOOK', 'subattachment targets vs media_fbid', ['post' => $post->json(), 'payload' => $result->payload]);
    Live::record('FACEBOOK', 'delete scheduled photo post', Http::withToken($env['PAGE_TOKEN'])->delete(graph().'/'.$result->platformPostId));
});

it('lists regular videos with title and description and honours since', function () use ($env) {
    $response = Http::withToken($env['PAGE_TOKEN'])->get(graph()."/{$env['PAGE_ID']}/videos", [
        'fields' => 'id,title,description,created_time,published',
        'since' => time() - 30 * 86400,
    ]);

    Live::record('FACEBOOK', 'videos listing', $response);

    expect($response->status())->toBe(200)
        ->and($response->json('data'))->toBeArray();
});

it('records the X-Business-Use-Case-Usage header shape of a Page-token call', function () use ($env) {
    $response = Http::withToken($env['PAGE_TOKEN'])->get(graph()."/{$env['PAGE_ID']}", ['fields' => 'id,name']);

    Live::record('FACEBOOK', 'business use case usage header', [
        'x-business-use-case-usage' => $response->header('X-Business-Use-Case-Usage'),
        'x-app-usage' => $response->header('X-App-Usage'),
    ]);

    expect($response->status())->toBe(200);
});

it('resolves the www.facebook.com/{id} permalink fallback for a post id', function () use ($env) {
    $listing = Http::withToken($env['PAGE_TOKEN'])->get(graph()."/{$env['PAGE_ID']}/published_posts", ['fields' => 'id,permalink_url', 'limit' => 1]);
    $id = $listing->json('data.0.id');

    if (! is_string($id)) {
        test()->markTestSkipped('The Page has no published post to resolve.');
    }

    $page = Http::withoutRedirecting()->get('https://www.facebook.com/'.$id);

    Live::record('FACEBOOK', 'permalink fallback', ['id' => $id, 'permalink_url' => $listing->json('data.0.permalink_url'), 'status' => $page->status(), 'location' => $page->header('Location')]);

    expect($page->status())->toBeLessThan(500);
});

foreach ([
    'video status shapes for Reels and video stories (uploading/processing/publishing phases, errors, publish_status)',
    'rupload hosted-file and local upload bodies, and the debug_info error shape',
    'Reel finish: post_id presence, video_state=SCHEDULED, extra keys, finish before upload completes',
    '/videos form-encoded published=false with scheduled_publish_time, and multipart with thumb',
    '/photo_stories success value type and bare post_id',
    'GET /{page}/stories media_id equals the photo_id of the unpublished /photos upload a photo story was made from',
    'a Page comment listed by GET /{post}/comments carries from.id = the Page id',
    'permalink_url on a Video node and on a Comment node',
    'subcode 2207003, codes 1363xxx and 1609005 as code or subcode',
] as $check) {
    it("[manual] {$check}")->skip('Manual check: see tests/Live/README.md (Facebook).');
}
