<?php

namespace SocialPoster\Tests\Live\YouTube;

use Illuminate\Support\Facades\Http;
use SocialPoster\Facades\SocialPoster;
use SocialPoster\Platforms\YouTube\YouTubeOptions;
use SocialPoster\Platforms\YouTube\YouTubePrivacy;
use SocialPoster\Tests\Live\Live;
use SocialPoster\ValueObjects\Media;

/*
 * YouTube live checks. TOKEN needs youtube.upload (and youtube.force-ssl for
 * the delete). Every session open spends videos.insert quota. The publishing
 * check uploads VIDEO_PATH (a small local mp4) as PRIVATE and deletes it.
 */

const REQUIRED = ['TOKEN', 'CHANNEL_ID'];
const UPLOAD = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status';

$env = Live::env('YOUTUBE', REQUIRED, ['VIDEO_PATH']);

if ($env === null) {
    it('runs the YouTube live checks')->skip(Live::skipMessage('YOUTUBE', REQUIRED));

    return;
}

beforeEach(fn () => Live::boot());

it('reads the channel the token belongs to', function () use ($env) {
    $response = Http::withToken($env['TOKEN'])->get('https://www.googleapis.com/youtube/v3/channels', ['part' => 'id,status', 'mine' => 'true']);

    Live::record('YOUTUBE', 'channels mine', $response);

    expect($response->status())->toBe(200)
        ->and($response->json('items.0.id'))->toBe($env['CHANNEL_ID']);
});

it('opens a resumable session on a googleapis.com host and answers a status query with 308', function () use ($env) {
    Live::requirePublishing();

    $open = Http::withToken($env['TOKEN'])
        ->withHeaders(['X-Upload-Content-Length' => '524288', 'X-Upload-Content-Type' => 'video/mp4'])
        ->post(UPLOAD.'&notifySubscribers=false', [
            'snippet' => ['title' => 'social-poster live check (never uploaded)'],
            'status' => ['privacyStatus' => 'private'],
        ]);

    Live::record('YOUTUBE', 'session open', $open);

    $session = (string) $open->header('Location');

    expect($open->status())->toBe(200)
        ->and(parse_url($session, PHP_URL_HOST))->toEndWith('googleapis.com');

    $query = Http::withToken($env['TOKEN'])->withHeaders(['Content-Range' => 'bytes */524288'])->withBody('', 'video/mp4')->put($session);

    Live::record('YOUTUBE', 'status query on an empty session', ['status' => $query->status(), 'range' => $query->header('Range')]);

    expect($query->status())->toBe(308)
        ->and($query->header('Range'))->toBe('');

    Http::withToken($env['TOKEN'])->delete($session);
});

it('uploads a private video through the package, then deletes it', function () use ($env) {
    Live::requirePublishing();
    $path = Live::requireValue($env['VIDEO_PATH'], 'SOCIAL_LIVE_YOUTUBE_VIDEO_PATH');

    $result = SocialPoster::on('youtube')
        ->using(['access_token' => $env['TOKEN'], 'channel_id' => $env['CHANNEL_ID']])
        ->title('social-poster live check')
        ->caption(Live::caption())
        ->media(Media::video($path))
        ->withOptions(new YouTubeOptions(privacyStatus: YouTubePrivacy::Private, notifySubscribers: false))
        ->postNow()['youtube'];

    Live::record('YOUTUBE', 'private upload', $result);

    expect($result->success)->toBeTrue()
        ->and($result->state)->toBe('private');

    // videos.delete takes the id in the query string (delete()'s $data would go in a JSON body).
    $delete = Http::withToken($env['TOKEN'])->delete('https://www.googleapis.com/youtube/v3/videos?'.http_build_query(['id' => $result->platformPostId]));

    Live::record('YOUTUBE', 'delete', $delete);

    expect($delete->status())->toBe(204);
});

foreach ([
    'a chunk sent from a stale (lower) offset: 400 or 308 with the real Range',
    'a finished session replays the original 201/200 video resource',
    'onBehalfOfContentOwner(+Channel) and the full part list on the session POST',
    'videos.insert error shapes: uploadLimitExceeded, quotaExceeded, ErrorInfo details',
    'daily quota reset at midnight America/Los_Angeles',
    'Short thumbnails only after processingStatus=succeeded; 404/429 right after upload',
    'playlistItems.insert right after the upload, and with a youtube.upload-only token',
    'commentThreads.insert on a fresh upload: videoNotFound duration, ids, 403 reasons, madeForKids',
    'Range GET support and ETag stability on the media hosts (S3/R2/CDN signed URLs)',
    'unaudited projects forcing private (privacy_warning)',
    'rejectionReason "length" wording for unverified channels (15 minutes)',
] as $check) {
    it("[manual] {$check}")->skip('Manual check: see tests/Live/README.md (YouTube).');
}
