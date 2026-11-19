<?php

namespace SocialPoster\Tests\Feature\Platforms;

use const SocialPoster\Tests\Fixtures\Instagram\FB_HOST;
use const SocialPoster\Tests\Fixtures\Instagram\IG_ACCOUNT;
use const SocialPoster\Tests\Fixtures\Instagram\IG_HOST;
use const SocialPoster\Tests\Fixtures\Instagram\IG_LISTING;
use const SocialPoster\Tests\Fixtures\Instagram\IG_MEDIA;
use const SocialPoster\Tests\Fixtures\Instagram\IG_PUBLISH;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Contracts\MediaTransformer;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\MediaType;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\PostProcessing;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\Instagram\InstagramOptions;
use SocialPoster\Tests\Fixtures\Instagram\FakeInspector;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\Published;
use SocialPoster\ValueObjects\SocialPost;

use function SocialPoster\Tests\Fixtures\Instagram\igBindResolver;
use function SocialPoster\Tests\Fixtures\Instagram\igCatch;
use function SocialPoster\Tests\Fixtures\Instagram\igCreds;
use function SocialPoster\Tests\Fixtures\Instagram\igDriver;
use function SocialPoster\Tests\Fixtures\Instagram\igErr;
use function SocialPoster\Tests\Fixtures\Instagram\igFake;
use function SocialPoster\Tests\Fixtures\Instagram\igPost;
use function SocialPoster\Tests\Fixtures\Instagram\igPublishState;
use function SocialPoster\Tests\Fixtures\Instagram\igRes;
use function SocialPoster\Tests\Fixtures\Instagram\igSent;
use function SocialPoster\Tests\Fixtures\Instagram\igSeq;
use function SocialPoster\Tests\Fixtures\Instagram\igStatus;
use function SocialPoster\Tests\Fixtures\Instagram\igTime;

require_once __DIR__.'/../../Fixtures/instagram/helpers.php';

beforeEach(function () {
    // The hosts below are built on v25.0, whatever the published config says.
    config(['social.api.instagram.graph_version' => 'v25.0']);
});

// --- Single photo --------------------------------------------------------------------

it('publishes a photo: container, then the publish commit, then the permalink', function () {
    igFake([
        IG_MEDIA => igRes(['id' => 'c1']),
        IG_PUBLISH => igRes(['id' => 'm1']),
        'GET /m1' => igRes(['permalink' => 'https://www.instagram.com/p/ABC/', 'id' => 'm1']),
    ]);

    $driver = igDriver();
    $post = igPost([Media::image('https://cdn.example.com/photo.jpg', 'A dog on a beach')], "  Hello #instagram \n");

    $outcome = $driver->publish($post);

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->commits)->toBeTrue()
        ->and($outcome->recheckAfter)->toBe(0)
        ->and($outcome->deadline)->toBeNull()
        ->and($outcome->state)->toMatchArray([
            'phase' => 'publish',
            'container_id' => 'c1',
            'ready' => true,
            'media_type' => 'IMAGE',
            'caption' => 'Hello #instagram',
            'restarts' => 0,
        ])
        ->and($outcome->state['since'])->toBeLessThanOrEqual(time() - 300);

    $create = igSent(IG_MEDIA)[0];

    expect($create->url())->toBe(FB_HOST.'/'.IG_ACCOUNT.'/media')
        ->and($create->data())->toBe([
            'image_url' => 'https://cdn.example.com/photo.jpg',
            'caption' => 'Hello #instagram',
            'alt_text' => 'A dog on a beach',
        ])
        ->and($create->hasHeader('Authorization', 'Bearer EAAGtoken123'))->toBeTrue();

    $published = $driver->resume($post, $outcome->state);

    expect($published)->toBeInstanceOf(Published::class)
        ->and($published->result->platformPostId)->toBe('m1')
        ->and($published->result->url)->toBe('https://www.instagram.com/p/ABC/')
        ->and($published->result->state)->toBe(PostResult::STATE_PUBLISHED)
        ->and($published->result->payload)->toMatchArray([
            'container_id' => 'c1',
            'media_type' => 'IMAGE',
            'response' => ['id' => 'm1'],
        ]);

    $publish = igSent(IG_PUBLISH)[0];

    expect($publish->data())->toBe(['creation_id' => 'c1'])
        ->and($publish->url())->toBe(FB_HOST.'/'.IG_ACCOUNT.'/media_publish');

    // The container was not read: the create had just answered, and nothing was ambiguous.
    expect(igSent('GET /c1'))->toBe([]);
});

it('talks to graph.instagram.com for Instagram Login accounts, on every call', function () {
    igFake([
        IG_MEDIA => igRes(['id' => 'c1']),
        IG_PUBLISH => igRes(['id' => 'm1']),
        'GET /m1' => igRes(['permalink' => 'https://www.instagram.com/p/XYZ/']),
    ]);

    $driver = igDriver();
    $post = igPost(credentials: igCreds(['login' => 'instagram', 'access_token' => 'plain-token']));

    $published = $driver->resume($post, $driver->publish($post)->state);

    expect($published->result->url)->toBe('https://www.instagram.com/p/XYZ/');

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), IG_HOST.'/'.IG_ACCOUNT.'/media'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'graph.facebook.com'));
});

it('picks the Graph host from the login credential, then the token prefix, then the default', function (array $credentials, ?string $default, string $host) {
    if ($default !== null) {
        config(['social.api.instagram.default_login' => $default]);
    }

    expect(igDriver()->call('graphBaseFor', igPost(credentials: igCreds($credentials))))->toBe($host);
})->with([
    'explicit instagram' => [['login' => 'instagram'], null, IG_HOST],
    'explicit instagram_login' => [['login' => 'Instagram_Login', 'access_token' => 'EAAx'], null, IG_HOST],
    'explicit facebook wins over an IG token' => [['login' => 'facebook', 'access_token' => 'IGAAtoken'], null, FB_HOST],
    'IG token prefix' => [['access_token' => 'IGAAJx9token'], null, IG_HOST],
    'EAA token prefix' => [['access_token' => 'EAAJx9token'], null, FB_HOST],
    'unknown token, default facebook' => [['access_token' => 'opaque'], null, FB_HOST],
    'unknown token, default instagram' => [['access_token' => 'opaque'], 'instagram', IG_HOST],
    'unknown login value falls back to the token' => [['login' => 'other', 'access_token' => 'IGQVtoken'], null, IG_HOST],
]);

it('uses the configured Graph version on both hosts', function () {
    config(['social.api.instagram.graph_version' => 'v27.0']);

    expect(igDriver()->call('graphBaseFor', igPost(credentials: igCreds(['login' => 'instagram']))))->toBe('https://graph.instagram.com/v27.0')
        ->and(igDriver()->call('graphBaseFor', igPost()))->toBe('https://graph.facebook.com/v27.0');
});

it('publishes a story photo without caption, alt text or the params a story refuses', function () {
    igFake([IG_MEDIA => igRes(['id' => 'c9'])]);

    $options = new InstagramOptions(isStory: true, extra: [
        'collaborators' => ['someone'],
        'location_id' => '123',
        'user_tags' => [['username' => 'friend']],
        'caption' => 'ignored',
    ]);

    $outcome = igDriver()->publish(igPost([Media::image('https://cdn.example.com/s.jpg', 'alt')], 'Caption', $options));

    expect(igSent(IG_MEDIA)[0]->data())->toBe([
        'user_tags' => [['username' => 'friend']],
        'image_url' => 'https://cdn.example.com/s.jpg',
        'media_type' => 'STORIES',
    ])
        ->and($outcome->state)->toMatchArray(['media_type' => 'STORIES', 'caption' => '', 'phase' => 'publish']);
});

it('omits a blank caption, merges extra under the driver keys and sends collaborators', function () {
    igFake([IG_MEDIA => igRes(['id' => 'c1'])]);

    $options = new InstagramOptions(extra: ['image_url' => 'https://evil.example.com/x.jpg', 'location_id' => '42'], collaborators: ['@alice', ' bob ', '']);

    igDriver()->publish(igPost(caption: '   ', options: $options));

    expect(igSent(IG_MEDIA)[0]->data())->toBe([
        'image_url' => 'https://cdn.example.com/photo.jpg',
        'location_id' => '42',
        'collaborators' => ['alice', 'bob'],
    ]);
});

it('fails fast as Misconfigured when a credential is missing, before any request', function (array $credentials, string $missing) {
    Http::fake();

    $publish = igCatch(fn () => igDriver()->publish(igPost(credentials: $credentials)));
    $resume = igCatch(fn () => igDriver()->resume(igPost(credentials: $credentials), igPublishState()));

    foreach ([$publish, $resume] as $e) {
        expect($e)->toBeInstanceOf(PermanentException::class)
            ->and($e->reason)->toBe(FailureReason::Misconfigured)
            ->and($e->context['missing_credentials'])->toBe([$missing]);
    }

    Http::assertNothingSent();
})->with([
    'no account id' => [['access_token' => 'EAAx'], 'account_id'],
    'blank token' => [['account_id' => IG_ACCOUNT, 'access_token' => ' '], 'access_token'],
]);

it('refuses a publish with no media', function () {
    $e = igCatch(fn () => igDriver()->publish(new PreparedPost(
        Platform::Instagram,
        new SocialPost(caption: 'x'),
        new Credentials(igCreds()),
    )));

    expect($e)->toBeInstanceOf(PermanentException::class)->and($e->reason)->toBe(FailureReason::Validation);
});

it('never accepts a container create without an id', function () {
    igFake([IG_MEDIA => igRes(['success' => true])]);

    $e = igCatch(fn () => igDriver()->publish(igPost()));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::ServerError)
        ->and($e->context['missing_field'])->toBe('id');
});

it('maps a refused container create through the Instagram table', function () {
    igFake([IG_MEDIA => igErr('media_fetch_failed')]);

    $e = igCatch(fn () => igDriver()->publish(igPost()));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain('public and long-lived')
        ->and($e->context['subcode'])->toBe(2207052)
        ->and($e->context['http_status'])->toBe(400);
});

it('refuses to spend the daily quota on an expired token', function () {
    Http::fake();

    $e = igCatch(fn () => igDriver()->publish(igPost(credentials: igCreds(['expires_at' => time() - 10]))));

    expect($e)->toBeInstanceOf(PermanentException::class)->and($e->reason)->toBe(FailureReason::InvalidToken);
    Http::assertNothingSent();
});

// --- Reels and video stories -----------------------------------------------------------

it('creates a reel container with cover, share_to_feed and collaborators, then polls after 20s', function () {
    igFake([IG_MEDIA => igRes(['id' => 'r1'])]);

    $options = new InstagramOptions(
        thumbnail: Media::image('https://cdn.example.com/cover.jpg'),
        extra: ['audio_name' => 'Original audio', 'media_type' => 'VIDEO'],
        shareToFeed: false,
        collaborators: ['alice'],
    );

    $outcome = igDriver()->publish(igPost([Media::video('https://cdn.example.com/reel.mp4')], 'Watch this', $options));

    expect(igSent(IG_MEDIA)[0]->data())->toBe([
        'audio_name' => 'Original audio',
        'media_type' => 'REELS',
        'video_url' => 'https://cdn.example.com/reel.mp4',
        'caption' => 'Watch this',
        'cover_url' => 'https://cdn.example.com/cover.jpg',
        'share_to_feed' => false,
        'collaborators' => ['alice'],
    ])
        ->and($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->commits)->toBeFalse()
        ->and($outcome->recheckAfter)->toBe(20)
        ->and($outcome->state)->toMatchArray(['phase' => 'await_container', 'container_id' => 'r1', 'media_type' => 'REELS']);
});

it('falls back to the video thumbnail as the cover, only when it is a JPEG', function (string $thumbnail, bool $sent) {
    igFake([IG_MEDIA => igRes(['id' => 'r1'])]);

    igDriver()->publish(igPost([Media::video('https://cdn.example.com/reel.mp4', $thumbnail)]));

    $data = igSent(IG_MEDIA)[0]->data();

    expect(isset($data['cover_url']))->toBe($sent);

    if ($sent) {
        expect($data['cover_url'])->toBe($thumbnail);
    }
})->with([
    'jpeg thumbnail' => ['https://cdn.example.com/thumb.jpg', true],
    'png thumbnail' => ['https://cdn.example.com/thumb.png', false],
    'local jpeg the gateway cannot publish' => ['/tmp/thumb.jpg', false],
]);

it('drops a fallback cover over the cover size limit, so Instagram picks a frame', function (int $bytes, bool $sent) {
    igFake([IG_MEDIA => igRes(['id' => 'r1'])]);
    $thumbnail = 'https://cdn.example.com/big-thumb.jpg';
    $inspector = new FakeInspector([
        $thumbnail => new MediaMetadata(sizeBytes: $bytes, mimeType: 'image/jpeg'),
    ]);

    igDriver($inspector)->publish(igPost([Media::video('https://cdn.example.com/reel.mp4', $thumbnail)]));

    expect(isset(igSent(IG_MEDIA)[0]->data()['cover_url']))->toBe($sent);
})->with([
    'over 8 MB' => [8388609, false],
    'at the limit' => [8388608, true],
]);

it('sends thumb_offset instead of a cover when asked to', function () {
    igFake([IG_MEDIA => igRes(['id' => 'r1'])]);

    igDriver()->publish(igPost([Media::video('https://cdn.example.com/reel.mp4', 'https://cdn.example.com/t.jpg')], options: new InstagramOptions(thumbOffsetMs: 1500)));

    expect(igSent(IG_MEDIA)[0]->data())->toMatchArray(['thumb_offset' => 1500])
        ->and(igSent(IG_MEDIA)[0]->data())->not->toHaveKey('cover_url');
});

it('creates a video story without caption or cover', function () {
    igFake([IG_MEDIA => igRes(['id' => 's1'])]);

    $outcome = igDriver()->publish(igPost([Media::video('https://cdn.example.com/story.mp4', 'https://cdn.example.com/t.jpg')], 'Caption', new InstagramOptions(isStory: true)));

    expect(igSent(IG_MEDIA)[0]->data())->toBe(['video_url' => 'https://cdn.example.com/story.mp4', 'media_type' => 'STORIES'])
        ->and($outcome->state)->toMatchArray(['phase' => 'await_container', 'media_type' => 'STORIES', 'caption' => '']);
});

// --- Polling a container ---------------------------------------------------------------

it('keeps polling a container every minute while it is in progress or reports nothing', function (?string $status) {
    igFake(['GET /r1' => igStatus($status)]);

    $state = ['phase' => 'await_container', 'container_id' => 'r1', 'media_type' => 'REELS', 'caption' => 'x', 'since' => time() - 60, 'created_at' => time() - 60, 'restarts' => 0];
    $outcome = igDriver()->resume(igPost([Media::video('https://cdn.example.com/reel.mp4')]), $state + [Pending::RECOVERING => false, Pending::ATTEMPT => 1]);

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->recheckAfter)->toBe(60)
        ->and($outcome->commits)->toBeFalse()
        ->and($outcome->state)->toMatchArray(['phase' => 'await_container', 'container_id' => 'r1', 'poll_errors' => 0])
        ->and($outcome->state)->not->toHaveKey(Pending::ATTEMPT);

    expect(igSent('GET /r1')[0]->url())->toContain('fields=status_code%2Cstatus');
})->with(['in progress' => 'IN_PROGRESS', 'no status' => null]);

it('hands a finished container over to the publish commit', function () {
    igFake(['GET /r1' => igStatus('FINISHED')]);

    $outcome = igDriver()->resume(igPost([Media::video('https://cdn.example.com/reel.mp4')]), ['phase' => 'await_container', 'container_id' => 'r1']);

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->commits)->toBeTrue()
        ->and($outcome->recheckAfter)->toBe(0)
        ->and($outcome->state)->toMatchArray(['phase' => 'publish', 'ready' => true, 'container_id' => 'r1', 'media_type' => 'REELS']);
});

it('recovers a container that is already published instead of polling it forever', function () {
    igFake([
        'GET /r1' => igStatus('PUBLISHED'),
        IG_LISTING => igRes(['data' => [
            ['id' => 'm-other', 'caption' => 'Watch this', 'timestamp' => igTime(4000), 'media_type' => 'VIDEO'],
            ['id' => 'm-img', 'caption' => 'Watch this', 'timestamp' => igTime(30), 'media_type' => 'IMAGE'],
            ['id' => 'm-reel', 'caption' => "Watch this\r\n", 'timestamp' => igTime(60), 'media_type' => 'VIDEO', 'permalink' => 'https://www.instagram.com/reel/R/'],
        ]]),
    ]);

    $state = ['phase' => 'await_container', 'container_id' => 'r1', 'media_type' => 'REELS', 'caption' => 'Watch this', 'since' => time() - 600, 'created_at' => time() - 600];
    $outcome = igDriver()->resume(igPost([Media::video('https://cdn.example.com/reel.mp4')], 'Watch this'), $state);

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->platformPostId)->toBe('m-reel')
        ->and($outcome->result->url)->toBe('https://www.instagram.com/reel/R/')
        ->and($outcome->result->payload)->toMatchArray(['recovered' => true, 'container_id' => 'r1']);

    expect(igSent(IG_PUBLISH))->toBe([])
        ->and(igSent(IG_LISTING)[0]->url())->toContain('fields=id%2Ccaption%2Ctimestamp%2Cpermalink%2Cmedia_type');
});

it('reports a recovered post as live with a null id when it cannot be found', function (array $listing) {
    igFake(['GET /r1' => igStatus('PUBLISHED'), IG_LISTING => igRes($listing)]);

    $state = ['phase' => 'await_container', 'container_id' => 'r1', 'media_type' => 'IMAGE', 'caption' => '', 'since' => time() - 600];
    $outcome = igDriver()->resume(igPost(caption: null), $state);

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->success)->toBeTrue()
        ->and($outcome->result->platformPostId)->toBeNull()
        ->and($outcome->result->payload['recovered'])->toBeTrue();
})->with([
    'nothing new' => [['data' => [['id' => 'old', 'caption' => '', 'timestamp' => igTime(9000), 'media_type' => 'IMAGE']]]],
    'two captionless candidates' => [['data' => [
        ['id' => 'a', 'timestamp' => igTime(10), 'media_type' => 'IMAGE'],
        ['id' => 'b', 'timestamp' => igTime(20), 'media_type' => 'IMAGE'],
    ]]],
    'garbage listing' => [['data' => 'nope']],
]);

it('recovers a captionless post when it is the only candidate, and fetches its permalink', function () {
    igFake([
        'GET /r1' => igStatus('PUBLISHED'),
        IG_LISTING => igRes(['data' => [['id' => 'only', 'timestamp' => igTime(10), 'media_type' => 'IMAGE']]]),
        'GET /only' => igRes(['permalink' => 'https://www.instagram.com/p/ONLY/']),
    ]);

    $outcome = igDriver()->resume(igPost(caption: ''), ['phase' => 'await_container', 'container_id' => 'r1', 'media_type' => 'IMAGE', 'since' => time() - 600]);

    expect($outcome->result->platformPostId)->toBe('only')
        ->and($outcome->result->url)->toBe('https://www.instagram.com/p/ONLY/');
});

it('looks a published story up among the account stories', function () {
    igFake([
        'GET /s1' => igStatus('PUBLISHED'),
        'GET /'.IG_ACCOUNT.'/stories' => igRes(['data' => [['id' => 'story-1', 'timestamp' => igTime(5), 'media_type' => 'IMAGE', 'permalink' => 'https://instagram.com/stories/x/1/']]]),
    ]);

    $outcome = igDriver()->resume(
        igPost(options: new InstagramOptions(isStory: true)),
        ['phase' => 'await_container', 'container_id' => 's1', 'since' => time() - 600],
    );

    expect($outcome->result->platformPostId)->toBe('story-1')
        ->and($outcome->result->payload['media_type'])->toBe('STORIES');
});

it('fails a container that errored with a media problem, naming the error code', function () {
    igFake(['GET /r1' => igStatus('ERROR', 'Error: Media upload has failed with error code 2207026')]);

    $e = igCatch(fn () => igDriver()->resume(igPost([Media::video('https://cdn.example.com/reel.mp4')]), ['phase' => 'await_container', 'container_id' => 'r1']));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain('video format')->toContain('2207026')
        ->and($e->context)->toMatchArray(['subcode' => 2207026, 'container_id' => 'r1', 'container_status' => 'ERROR']);
});

it('fails an errored container with no code as MediaRejected', function () {
    igFake(['GET /r1' => igStatus('ERROR')]);

    $e = igCatch(fn () => igDriver()->resume(igPost([Media::video('https://cdn.example.com/reel.mp4')]), ['phase' => 'await_container', 'container_id' => 'r1']));

    expect($e->reason)->toBe(FailureReason::MediaRejected)->and($e->getMessage())->toBe('Instagram failed to process the media.');
});

it('reads the error code from any status wording', function (string $text, ?int $code, string $message) {
    igFake(['GET /r1' => igStatus('ERROR', $text)]);

    $e = igCatch(fn () => igDriver()->resume(igPost([Media::video('https://cdn.example.com/reel.mp4')]), ['phase' => 'await_container', 'container_id' => 'r1', 'restarts' => 2]));

    expect($e->context['subcode'] ?? null)->toBe($code)
        ->and($e->getMessage())->toContain($message);
})->with([
    'bare code' => ['ERROR: 2207052', 2207052, 'public and long-lived'],
    'unlisted code' => ['Media upload has failed with error code: 9999999', 9999999, '(error code 9999999)'],
    'no code' => ['Something went wrong', null, 'Instagram failed to process the media.'],
]);

it('re-creates a container that failed on Meta’s side, carrying the restart count', function () {
    igFake([
        'GET /r1' => igStatus('ERROR', 'Media upload has failed with error code 2207001'),
        IG_MEDIA => igRes(['id' => 'r2']),
    ]);

    $since = time() - 5000;
    $outcome = igDriver()->resume(
        igPost([Media::video('https://cdn.example.com/reel.mp4')], 'Watch'),
        ['phase' => 'await_container', 'container_id' => 'r1', 'media_type' => 'REELS', 'caption' => 'Watch', 'since' => $since, 'created_at' => time() - 100, 'restarts' => 0],
    );

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->state)->toMatchArray(['phase' => 'await_container', 'container_id' => 'r2', 'restarts' => 1, 'since' => $since])
        ->and(igSent(IG_MEDIA))->toHaveCount(1);
});

it('stops re-creating after two restarts', function () {
    igFake(['GET /r1' => igStatus('ERROR', 'Media upload has failed with error code 2207053')]);

    $e = igCatch(fn () => igDriver()->resume(igPost([Media::video('https://cdn.example.com/reel.mp4')]), ['phase' => 'await_container', 'container_id' => 'r1', 'restarts' => 2]));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::ServerError)
        ->and($e->context['restarts'])->toBe(2);
    expect(igSent(IG_MEDIA))->toBe([]);
});

it('re-creates an expired container once, then gives up', function () {
    igFake(['GET /r1' => igStatus('EXPIRED'), IG_MEDIA => igRes(['id' => 'r2'])]);

    $post = igPost([Media::video('https://cdn.example.com/reel.mp4')]);
    $first = igDriver()->resume($post, ['phase' => 'await_container', 'container_id' => 'r1', 'restarts' => 0]);
    $second = igCatch(fn () => igDriver()->resume($post, ['phase' => 'await_container', 'container_id' => 'r1', 'restarts' => 1]));

    expect($first->state)->toMatchArray(['container_id' => 'r2', 'restarts' => 1])
        ->and($second)->toBeInstanceOf(PermanentException::class)
        ->and($second->reason)->toBe(FailureReason::ProcessingTimeout);
});

it('treats a container close to its 24 hour lifetime as expired', function () {
    igFake(['GET /r1' => igStatus('IN_PROGRESS'), IG_MEDIA => igRes(['id' => 'r2'])]);

    $outcome = igDriver()->resume(igPost([Media::video('https://cdn.example.com/reel.mp4')]), ['phase' => 'await_container', 'container_id' => 'r1', 'created_at' => time() - 83000]);

    expect($outcome->state)->toMatchArray(['container_id' => 'r2', 'restarts' => 1]);
});

it('retries a failed status read a few times before failing the post', function () {
    igFake(['GET /r1' => igErr('graph_503')]);

    $post = igPost([Media::video('https://cdn.example.com/reel.mp4')]);
    $state = ['phase' => 'await_container', 'container_id' => 'r1'];

    $outcome = igDriver()->resume($post, $state);

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->state['poll_errors'])->toBe(1)
        ->and($outcome->recheckAfter)->toBe(60);

    $e = igCatch(fn () => igDriver()->resume($post, $state + ['poll_errors' => 5]));

    expect($e)->toBeInstanceOf(TemporaryException::class)->and($e->reason)->toBe(FailureReason::ServerError);
});

it('treats a dropped connection on a status read like a failed read', function () {
    igFake(['GET /r1' => Http::failedConnection()]);

    $post = igPost([Media::video('https://cdn.example.com/reel.mp4')]);

    $outcome = igDriver()->resume($post, ['phase' => 'await_container', 'container_id' => 'r1', 'poll_errors' => 2]);

    expect($outcome->state['poll_errors'])->toBe(3);
    expect(igCatch(fn () => igDriver()->resume($post, ['phase' => 'await_container', 'container_id' => 'r1', 'poll_errors' => 5])))
        ->toBeInstanceOf(ConnectionException::class);
});

it('waits as long as a throttled status read asks', function () {
    igFake(['GET /r1' => igErr('app_throttle')]);

    $outcome = igDriver()->resume(igPost([Media::video('https://cdn.example.com/reel.mp4')]), ['phase' => 'await_container', 'container_id' => 'r1']);

    expect($outcome->recheckAfter)->toBe(900);
});

it('lets a rejected token on a status read fail the post', function () {
    igFake(['GET /r1' => igErr('password_changed')]);

    $e = igCatch(fn () => igDriver()->resume(igPost([Media::video('https://cdn.example.com/reel.mp4')]), ['phase' => 'await_container', 'container_id' => 'r1']));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::InvalidToken)
        ->and($e->isTerminal())->toBeTrue();
});

it('never swallows a request to re-resolve credentials during a poll', function () {
    igBindResolver();
    igFake(['GET /r1' => igErr('token_invalid')]);

    $e = igCatch(fn () => igDriver()->resume(igPost([Media::video('https://cdn.example.com/reel.mp4')]), ['phase' => 'await_container', 'container_id' => 'r1']));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->context['reresolve'])->toBeTrue();
});

// --- The publish commit ----------------------------------------------------------------

it('verifies the container before publishing when the run may repeat an earlier attempt', function (array $flags) {
    igFake([
        'GET /c1' => igStatus('FINISHED'),
        IG_PUBLISH => igRes(['id' => 'm1']),
        'GET /m1' => igRes(['permalink' => 'https://www.instagram.com/p/M/']),
    ]);

    $outcome = igDriver()->resume(igPost(), igPublishState($flags));

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->platformPostId)->toBe('m1')
        ->and(igSent('GET /c1'))->toHaveCount(1)
        ->and(igSent(IG_PUBLISH))->toHaveCount(1);
})->with([
    'not known ready' => [['ready' => false]],
    'recovering' => [[Pending::RECOVERING => true]],
    'after an ambiguous round' => [['ambiguous' => true]],
]);

it('never publishes twice: a recovering run finds the container published', function () {
    igFake([
        'GET /c1' => igStatus('PUBLISHED'),
        IG_LISTING => igRes(['data' => [['id' => 'm1', 'caption' => 'Hello #instagram', 'timestamp' => igTime(30), 'media_type' => 'IMAGE', 'permalink' => 'https://www.instagram.com/p/M1/']]]),
    ]);

    $outcome = igDriver()->resume(igPost(), igPublishState([Pending::RECOVERING => true, Pending::COMMITTING_SINCE => time() - 60]));

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->platformPostId)->toBe('m1')
        ->and($outcome->result->url)->toBe('https://www.instagram.com/p/M1/')
        ->and($outcome->result->payload['recovered'])->toBeTrue();

    expect(igSent(IG_PUBLISH))->toBe([]);
});

it('goes back to polling when the container turns out to be in progress', function (string $type, string $phase) {
    igFake(['GET /c1' => igStatus('IN_PROGRESS')]);

    $outcome = igDriver()->resume(igPost(), igPublishState(['ready' => false, 'media_type' => $type]));

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->commits)->toBeFalse()
        ->and($outcome->state['phase'])->toBe($phase)
        ->and(igSent(IG_PUBLISH))->toBe([]);
})->with([
    'single' => ['REELS', 'await_container'],
    'carousel' => ['CAROUSEL', 'await_carousel'],
]);

it('retries a failed verification read as a commit, never publishing blind', function () {
    igFake(['GET /c1' => igErr('graph_503')]);

    $outcome = igDriver()->resume(igPost(), igPublishState([Pending::RECOVERING => true]));

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->commits)->toBeTrue()
        ->and($outcome->state)->toMatchArray(['phase' => 'publish', 'poll_errors' => 1, 'ready' => false, 'ambiguous' => true])
        ->and($outcome->state)->not->toHaveKey(Pending::RECOVERING)
        ->and(igSent(IG_PUBLISH))->toBe([]);
});

it('verifies on the next hop even when the job no longer says it is recovering', function () {
    igFake([
        'GET /c1' => igSeq(igErr('graph_503'), igStatus('PUBLISHED')),
        IG_LISTING => igRes(['data' => [['id' => 'm1', 'caption' => 'Hello #instagram', 'timestamp' => igTime(20), 'media_type' => 'IMAGE', 'permalink' => 'https://www.instagram.com/p/M1/']]]),
    ]);

    $hop = igDriver()->resume(igPost(), igPublishState([Pending::RECOVERING => true]));
    $outcome = igDriver()->resume(igPost(), $hop->state);

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->platformPostId)->toBe('m1')
        ->and(igSent(IG_PUBLISH))->toBe([]);
});

it('still reports a published container as live when the media listing fails', function (string $case) {
    igFake([
        'GET /c1' => igStatus('PUBLISHED'),
        IG_LISTING => $case === 'refused' ? igErr('permission') : Http::failedConnection(),
    ]);

    $outcome = igDriver()->resume(igPost(), igPublishState([Pending::RECOVERING => true]));

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->platformPostId)->toBeNull()
        ->and($outcome->result->payload)->toMatchArray(['recovered' => true, 'container_id' => 'c1']);
})->with(['refused', 'dropped']);

it('handles ERROR and EXPIRED found while verifying', function () {
    igFake(['GET /c1' => igSeq(igStatus('ERROR', 'error code 2207026'), igStatus('EXPIRED'))]);

    $error = igCatch(fn () => igDriver()->resume(igPost(), igPublishState([Pending::RECOVERING => true])));
    $expired = igCatch(fn () => igDriver()->resume(igPost(), igPublishState([Pending::RECOVERING => true, 'restarts' => 1])));

    // The status proves the container was never published, even on a
    // recovering run: the job must not report it as possibly live.
    expect($error->reason)->toBe(FailureReason::MediaRejected)
        ->and($error->context)->toHaveKey('possibly_published', false)
        ->and($expired->reason)->toBe(FailureReason::ProcessingTimeout)
        ->and($expired->context)->toHaveKey('possibly_published', false);
});

it('never publishes an ambiguous container whose status it cannot read', function () {
    igFake(['GET /c1' => igRes(['id' => 'c1']), IG_LISTING => igRes(['data' => []])]);

    $hop = igDriver()->resume(igPost(), igPublishState(['ambiguous' => true, 'ready' => false]));

    expect($hop)->toBeInstanceOf(Pending::class)
        ->and($hop->commits)->toBeTrue()
        ->and($hop->state)->toMatchArray(['phase' => 'publish', 'poll_errors' => 1, 'ambiguous' => true]);

    $e = igCatch(fn () => igDriver()->resume(igPost(), igPublishState(['ambiguous' => true, 'ready' => false, 'poll_errors' => 5])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->possiblyPublished())->toBeTrue()
        ->and(igSent(IG_LISTING))->toHaveCount(1)
        ->and(igSent(IG_PUBLISH))->toBe([]);
});

it('looks the post up before re-creating a container an ambiguous publish may have consumed', function () {
    igFake([
        'GET /c1' => igStatus('FINISHED'),
        IG_PUBLISH => igErr('media_builder_expired'),
        IG_LISTING => igSeq(
            igRes(['data' => [['id' => 'm1', 'caption' => 'Hello #instagram', 'timestamp' => igTime(30), 'media_type' => 'IMAGE', 'permalink' => 'https://www.instagram.com/p/M1/']]]),
            igRes(['data' => []]),
        ),
        IG_MEDIA => igRes(['id' => 'c2']),
    ]);

    $found = igDriver()->resume(igPost(), igPublishState(['ambiguous' => true, 'ready' => false, 'builder_retries' => 2]));
    $hop = igDriver()->resume(igPost(), igPublishState(['ambiguous' => true, 'ready' => false, 'builder_retries' => 2]));
    $capped = igCatch(fn () => igDriver()->resume(igPost(), igPublishState(['ambiguous' => true, 'ready' => false, 'builder_retries' => 2, 'restarts' => 2])));

    expect($found)->toBeInstanceOf(Published::class)
        ->and($found->result->platformPostId)->toBe('m1')
        ->and($hop->state['phase'])->toBe('restart')
        ->and($capped)->toBeInstanceOf(PermanentException::class)
        ->and($capped->possiblyPublished())->toBeTrue()
        ->and(igSent(IG_MEDIA))->toBe([]);
});

it('never asks the job to start over while an earlier publish may have gone through', function () {
    igFake(['GET /c1' => igErr('media_not_found'), IG_LISTING => igRes(['data' => []])]);

    $hop = igDriver()->resume(igPost(), igPublishState(['ambiguous' => true, 'ready' => false]));
    $e = igCatch(fn () => igDriver()->resume(igPost(), igPublishState(['ambiguous' => true, 'ready' => false, 'poll_errors' => 5])));

    expect($hop)->toBeInstanceOf(Pending::class)
        ->and($hop->commits)->toBeTrue()
        ->and($hop->state['poll_errors'])->toBe(1)
        ->and($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->possiblyPublished())->toBeTrue()
        ->and($e->context)->not->toHaveKey('restart')
        ->and($e->context['subcode'] ?? null)->toBe(2207006)
        ->and(igSent(IG_MEDIA))->toBe([])
        ->and(igSent(IG_PUBLISH))->toBe([]);
});

it('looks the post up when it runs out of tries to verify an ambiguous publish', function () {
    igFake([
        'GET /c1' => igErr('graph_503'),
        IG_LISTING => igRes(['data' => [['id' => 'm1', 'caption' => 'Hello #instagram', 'timestamp' => igTime(30), 'media_type' => 'IMAGE', 'permalink' => 'https://www.instagram.com/p/M1/']]]),
    ]);

    $outcome = igDriver()->resume(igPost(), igPublishState(['ambiguous' => true, 'ready' => false, 'poll_errors' => 5]));

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->platformPostId)->toBe('m1')
        ->and($outcome->result->payload['recovered'])->toBeTrue()
        ->and(igSent(IG_PUBLISH))->toBe([]);
});

it('flags a refused verification of an ambiguous publish as possibly published', function (string $fixture) {
    igFake(['GET /c1' => igErr($fixture), IG_LISTING => igRes(['data' => []])]);

    $e = igCatch(fn () => igDriver()->resume(igPost(), igPublishState(['ambiguous' => true, 'ready' => false])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->possiblyPublished())->toBeTrue()
        ->and(igSent(IG_LISTING))->toHaveCount(1)
        ->and(igSent(IG_PUBLISH))->toBe([]);
})->with(['token_invalid', 'password_changed', 'object_missing', 'permission']);

it('keeps the possibly published flag through a credentials re-resolve', function (string $where) {
    igBindResolver();
    igFake([
        'GET /c1' => $where === 'verification' ? igErr('token_invalid') : igStatus('FINISHED'),
        IG_PUBLISH => igErr('token_invalid'),
        IG_LISTING => igRes(['data' => []]),
    ]);

    $e = igCatch(fn () => igDriver()->resume(igPost(), igPublishState(['ambiguous' => true, 'ready' => false])));

    // The job retries once with fresh credentials, then fails with the
    // wrapped rejection: both must say the post may be live.
    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->context['reresolve'])->toBeTrue()
        ->and($e->possiblyPublished())->toBeTrue()
        ->and($e->getPrevious())->toBeInstanceOf(PermanentException::class)
        ->and($e->getPrevious()->possiblyPublished())->toBeTrue();
})->with(['verification', 'publish']);

it('tells the job no publish was sent when a readiness check of the commit keeps failing', function (string $case) {
    igFake(['GET /c1' => $case === 'outage'
        ? igErr('graph_503')
        : Http::failedConnection('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received')]);

    $e = igCatch(fn () => igDriver()->resume(igPost(), igPublishState(['ready' => false, 'not_ready' => 3, 'poll_errors' => 5])));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->context)->toMatchArray(['possibly_published' => false, 'request_sent' => false])
        ->and(igSent(IG_PUBLISH))->toBe([]);
})->with(['outage', 'dropped']);

it('keeps the chain alive past the default wait while it verifies an ambiguous publish', function () {
    igFake([IG_PUBLISH => Http::failedConnection('cURL error 28: Operation timed out after 120001 milliseconds with 0 bytes received')]);

    $createdAt = time() - 300;
    $outcome = igDriver()->resume(igPost(), igPublishState(['created_at' => $createdAt]));

    expect($outcome->state['ambiguous'])->toBeTrue()
        ->and($outcome->deadline)->toBe($createdAt + 82800 + 1800);
});

it('polls a not-ready container every minute, then backs off, within the container lifetime', function () {
    config(['social.api.instagram.not_ready_backoff' => 1800]);
    igFake([IG_PUBLISH => igErr('not_ready'), 'GET /c1' => igStatus('FINISHED')]);

    $createdAt = time() - 600;
    $first = igDriver()->resume(igPost(), igPublishState(['created_at' => $createdAt]));

    expect($first)->toBeInstanceOf(Pending::class)
        ->and($first->commits)->toBeTrue()
        ->and($first->recheckAfter)->toBe(60)
        ->and($first->deadline)->toBe($createdAt + 82800 + 1800)
        ->and($first->state)->toMatchArray(['phase' => 'publish', 'not_ready' => 1, 'ready' => false])
        ->and($first->state)->not->toHaveKey('not_before');

    $sixth = igDriver()->resume(igPost(), igPublishState(['created_at' => $createdAt, 'not_ready' => 5, 'ready' => false]));

    expect($sixth->recheckAfter)->toBe(900)
        ->and($sixth->state['not_ready'])->toBe(6)
        ->and($sixth->state['not_before'])->toBeGreaterThanOrEqual(time() + 1790)
        ->and($sixth->state['not_before'])->toBeLessThanOrEqual(time() + 1800);
});

it('says why each wait of the publish step happens, on every hop of a long one', function () {
    config(['social.api.instagram.not_ready_backoff' => 1800]);
    igFake([IG_PUBLISH => igErr('not_ready'), 'GET /c1' => igStatus('FINISHED')]);

    $createdAt = time() - 600;
    $first = igDriver()->resume(igPost(), igPublishState(['created_at' => $createdAt]));
    $sixth = igDriver()->resume(igPost(), igPublishState(['created_at' => $createdAt, 'not_ready' => 5, 'ready' => false]));
    $hop = igDriver()->resume(igPost(), $sixth->state);
    $restored = igDriver()->resume(igPost(), igPublishState(['not_before' => time() - 1, 'wait_reason' => 'not_ready', 'ready' => false]));

    expect($first->reason)->toBe(PostProcessing::REASON_NOT_READY)
        ->and($sixth->reason)->toBe(PostProcessing::REASON_NOT_READY)
        ->and($sixth->state['wait_reason'])->toBe(PostProcessing::REASON_NOT_READY)
        // A hop of the long wait, still more than the queue can delay at once.
        ->and($hop->reason)->toBe(PostProcessing::REASON_NOT_READY)
        ->and($hop->state['wait_reason'])->toBe(PostProcessing::REASON_NOT_READY)
        // The wait is over: the next wait has its own reason (here the media is still not ready).
        ->and($restored->reason)->toBe(PostProcessing::REASON_NOT_READY)
        ->and($restored->state)->not->toHaveKey('wait_reason');
});

it('says the publish step verifies after a lost answer, and retries after a temporary refusal', function () {
    igFake([IG_PUBLISH => igSeq(fn (Request $request) => (Http::failedConnection('cURL error 28: Operation timed out'))($request), igErr('app_throttle'))]);

    $lost = igDriver()->resume(igPost(), igPublishState());
    $throttled = igDriver()->resume(igPost(), igPublishState());

    expect($lost->reason)->toBe(PostProcessing::REASON_VERIFYING)
        ->and($throttled->reason)->toBe(PostProcessing::REASON_RETRYING);
});

it('never schedules a not-ready wait past the container lifetime', function () {
    igFake([IG_PUBLISH => igErr('not_ready')]);

    $outcome = igDriver()->resume(igPost(), igPublishState(['created_at' => time() - 82800 + 300, 'not_ready' => 9]));

    expect($outcome->recheckAfter)->toBeLessThanOrEqual(300)
        ->and($outcome->recheckAfter)->toBeGreaterThanOrEqual(290)
        ->and($outcome->state)->not->toHaveKey('not_before');
});

it('waits out a stored not_before without calling Instagram', function () {
    Http::fake();

    $long = igDriver()->resume(igPost(), igPublishState(['not_before' => time() + 1500, 'not_ready' => 7, 'ready' => false]));
    $short = igDriver()->resume(igPost(), igPublishState(['not_before' => time() + 300, 'not_ready' => 7, 'ready' => false]));
    $await = igDriver()->resume(igPost(), ['phase' => 'await_container', 'container_id' => 'r1', 'not_before' => time() + 120]);

    expect($long->recheckAfter)->toBe(900)
        ->and($long->commits)->toBeTrue()
        ->and($long->state['not_before'])->toBeGreaterThan(time() + 1400)
        ->and($short->recheckAfter)->toBeGreaterThanOrEqual(295)
        ->and($short->state)->not->toHaveKey('not_before')
        ->and($await->commits)->toBeFalse();

    Http::assertNothingSent();
});

it('re-creates a container too old to publish', function () {
    igFake([IG_MEDIA => igRes(['id' => 'c2'])]);

    $hop = igDriver()->resume(igPost(), igPublishState(['created_at' => time() - 90000]));
    $outcome = igDriver()->resume(igPost(), $hop->state);

    expect($hop->commits)->toBeFalse()
        ->and($hop->state['phase'])->toBe('restart')
        ->and($outcome->state)->toMatchArray(['container_id' => 'c2', 'restarts' => 1, 'phase' => 'publish'])
        ->and(igSent(IG_PUBLISH))->toBe([]);
});

it('comes back to verify after a publish that timed out, instead of re-posting blind', function () {
    igFake([IG_PUBLISH => Http::failedConnection('cURL error 28: Operation timed out after 120001 milliseconds with 0 bytes received')]);

    $outcome = igDriver()->resume(igPost(), igPublishState());

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->commits)->toBeTrue()
        ->and($outcome->recheckAfter)->toBe(60)
        ->and($outcome->state)->toMatchArray(['ambiguous' => true, 'ready' => false, 'publish_errors' => 1, 'phase' => 'publish']);
});

it('does not flag a publish that never left the client as ambiguous', function () {
    igFake([IG_PUBLISH => Http::failedConnection()]);

    $outcome = igDriver()->resume(igPost(), igPublishState());

    expect($outcome->state)->toMatchArray(['ready' => false, 'publish_errors' => 1])
        ->and($outcome->state)->not->toHaveKey('ambiguous');
});

it('hands a connection failure to the job after too many ambiguous rounds', function () {
    igFake(['GET /c1' => igStatus('FINISHED'), IG_PUBLISH => Http::failedConnection('cURL error 28: Operation timed out')]);

    expect(igCatch(fn () => igDriver()->resume(igPost(), igPublishState(['publish_errors' => 5]))))
        ->toBeInstanceOf(ConnectionException::class);
});

it('verifies after a 5xx or transient publish answer', function (string $fixture, int $recheck) {
    igFake([IG_PUBLISH => igErr($fixture)]);

    $outcome = igDriver()->resume(igPost(), igPublishState());

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->commits)->toBeTrue()
        ->and($outcome->recheckAfter)->toBe($recheck)
        ->and($outcome->state)->toMatchArray(['ambiguous' => true, 'ready' => false]);
})->with([
    'server error 2207001' => ['server_error', 60],
    'html 502' => ['html_502', 60],
    'transient 503' => ['graph_503', 60],
]);

it('treats a throttled publish as a refusal: it verifies, but never flags a later refusal as possibly published', function (string $fixture, int $recheck) {
    igFake([
        IG_PUBLISH => igSeq(igErr($fixture), igErr('daily_limit')),
        'GET /c1' => igStatus('FINISHED'),
        IG_LISTING => igRes(['data' => []]),
    ]);

    $hop = igDriver()->resume(igPost(), igPublishState());

    expect($hop)->toBeInstanceOf(Pending::class)
        ->and($hop->commits)->toBeTrue()
        ->and($hop->recheckAfter)->toBe($recheck)
        ->and($hop->state)->toMatchArray(['ready' => false, 'publish_errors' => 1])
        ->and($hop->state)->not->toHaveKey('ambiguous');

    $e = igCatch(fn () => igDriver()->resume(igPost(), $hop->state));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::RateLimited)
        ->and($e->possiblyPublished())->toBeFalse()
        ->and(igSent('GET /c1'))->toHaveCount(1)
        ->and(igSent(IG_LISTING))->toBe([]);
})->with([
    'app throttle' => ['app_throttle', 900],
    'user throttle' => ['user_throttle', 900],
    'too many actions' => ['too_many_actions', 90],
]);

it('gives up verifying after too many 5xx answers', function () {
    igFake(['GET /c1' => igStatus('FINISHED'), IG_PUBLISH => igErr('graph_503')]);

    $e = igCatch(fn () => igDriver()->resume(igPost(), igPublishState(['publish_errors' => 5])));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::ServerError)
        ->and($e->possiblyPublished())->toBeTrue();
});

it('re-creates the containers on a hop of their own when media_publish says the container is gone', function (string $fixture) {
    igFake([IG_PUBLISH => igErr($fixture), IG_MEDIA => igRes(['id' => 'c2'])]);

    $hop = igDriver()->resume(igPost(), igPublishState());

    // New containers are not a publish: they are created outside the commit,
    // so a failure there is never mistaken for a media_publish that may have
    // gone through.
    expect($hop)->toBeInstanceOf(Pending::class)
        ->and($hop->commits)->toBeFalse()
        ->and($hop->recheckAfter)->toBe(0)
        ->and($hop->state['phase'])->toBe('restart')
        ->and(igSent(IG_MEDIA))->toBe([]);

    $outcome = igDriver()->resume(igPost(), $hop->state);

    expect($outcome->state)->toMatchArray(['container_id' => 'c2', 'restarts' => 1, 'phase' => 'publish'])
        ->and(igSent(IG_MEDIA))->toHaveCount(1)
        ->and(igSent(IG_PUBLISH))->toHaveCount(1);
})->with(['container_expired', 'unknown_upload', 'media_not_found']);

it('retries the same container after a temporary 2207008, then re-creates it', function () {
    igFake([IG_PUBLISH => igErr('media_builder_expired'), IG_MEDIA => igRes(['id' => 'c2'])]);

    $post = igPost([Media::video('https://cdn.example.com/r.mp4')]);
    $first = igDriver()->resume($post, igPublishState(['media_type' => 'REELS']));

    expect($first)->toBeInstanceOf(Pending::class)
        ->and($first->commits)->toBeTrue()
        ->and($first->recheckAfter)->toBe(60)
        ->and($first->state)->toMatchArray(['phase' => 'publish', 'container_id' => 'c1', 'builder_retries' => 1, 'restarts' => 0])
        ->and(igSent(IG_MEDIA))->toBe([]);

    $second = igDriver()->resume($post, $first->state);
    $third = igDriver()->resume($post, $second->state);
    $fourth = igDriver()->resume($post, $third->state);

    expect($second->state)->toMatchArray(['container_id' => 'c1', 'builder_retries' => 2])
        ->and($third->commits)->toBeFalse()
        ->and($third->state['phase'])->toBe('restart')
        ->and($fourth->state)->toMatchArray(['phase' => 'await_container', 'container_id' => 'c2', 'restarts' => 1])
        ->and($fourth->state)->not->toHaveKey('builder_retries')
        ->and(igSent(IG_PUBLISH))->toHaveCount(3)
        ->and(igSent(IG_MEDIA))->toHaveCount(1);
});

it('stops at the restart cap when media_publish keeps saying the container is gone', function () {
    igFake([IG_PUBLISH => igErr('container_expired')]);

    $e = igCatch(fn () => igDriver()->resume(igPost(), igPublishState(['restarts' => 2])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::ServerError)
        ->and($e->context['restart'])->toBeFalse()
        ->and($e->getPrevious())->toBeInstanceOf(TemporaryException::class);
});

it('fails a refused publish permanently', function () {
    igFake([IG_PUBLISH => igErr('spam_block')]);

    $e = igCatch(fn () => igDriver()->resume(igPost(), igPublishState()));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::AccountRestricted)
        ->and($e->possiblyPublished())->toBeFalse();
});

it('looks for the post before failing a refusal that follows an ambiguous publish', function () {
    igFake([
        'GET /c1' => igStatus('FINISHED'),
        IG_PUBLISH => igErr('invalid_parameter'),
        IG_LISTING => igSeq(
            igRes(['data' => [['id' => 'm1', 'caption' => 'Hello #instagram', 'timestamp' => igTime(10), 'media_type' => 'IMAGE']]]),
            igRes(['data' => []]),
        ),
        'GET /m1' => igRes(['permalink' => 'https://www.instagram.com/p/M1/']),
    ]);

    $found = igDriver()->resume(igPost(), igPublishState(['ambiguous' => true]));
    $missing = igCatch(fn () => igDriver()->resume(igPost(), igPublishState(['ambiguous' => true])));

    expect($found)->toBeInstanceOf(Published::class)
        ->and($found->result->platformPostId)->toBe('m1')
        ->and($found->result->url)->toBe('https://www.instagram.com/p/M1/')
        ->and($missing)->toBeInstanceOf(PermanentException::class)
        ->and($missing->possiblyPublished())->toBeTrue();
});

it('treats a 2xx publish answer without an id as live and looks the id up', function () {
    igFake([
        IG_PUBLISH => igRes(['success' => true]),
        IG_LISTING => igRes(['data' => [['id' => 'm7', 'caption' => 'Hello #instagram', 'timestamp' => igTime(5), 'media_type' => 'IMAGE', 'permalink' => 'https://www.instagram.com/p/M7/']]]),
    ]);

    $outcome = igDriver()->resume(igPost(), igPublishState());

    expect($outcome->result->platformPostId)->toBe('m7')
        ->and($outcome->result->payload['recovered'])->toBeTrue()
        // The raw 2xx body stays on the result, as for an answer with an id.
        ->and($outcome->result->payload['response'] ?? null)->toBe(['success' => true]);
});

it('lets a re-resolve request on publish reach the job', function () {
    igBindResolver();
    igFake([IG_PUBLISH => igErr('token_invalid')]);

    $e = igCatch(fn () => igDriver()->resume(igPost(), igPublishState()));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->context['reresolve'])->toBeTrue()
        ->and($e->reason)->toBe(FailureReason::InvalidToken);
});

it('keeps the 1.0 publishContainer() entry point', function () {
    igFake([IG_PUBLISH => igRes(['id' => 'm1']), 'GET /m1' => igRes([])]);

    $outcome = igDriver()->call('publishContainer', igPost(), 'c1');

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->platformPostId)->toBe('m1')
        ->and($outcome->result->url)->toBeNull();
});

// --- Carousels -------------------------------------------------------------------------

it('creates carousel children in a pool and keeps the source order', function () {
    igFake([
        IG_MEDIA => function (Request $request) {
            $source = $request['image_url'] ?? $request['video_url'];

            return Http::response(['id' => 'child-'.basename((string) $source, '.'.pathinfo((string) $source, PATHINFO_EXTENSION))]);
        },
    ]);

    $media = [
        Media::image('https://cdn.example.com/a.jpg', 'First', ['instagram' => ['user_tags' => [['username' => 'x', 'x' => 0.5, 'y' => 0.5]], 'image_url' => 'https://evil.example.com/z.jpg']]),
        Media::video('https://cdn.example.com/b.mp4', altText: 'never sent on video'),
        Media::image('https://cdn.example.com/c.jpg'),
    ];

    $outcome = igDriver()->publish(igPost($media, 'Carousel caption', new InstagramOptions(extra: ['location_id' => '7'])));

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->recheckAfter)->toBe(60)
        ->and($outcome->state)->toMatchArray([
            'phase' => 'await_children',
            'children' => ['child-a', 'child-b', 'child-c'],
            'pending_videos' => ['child-b'],
            'media_type' => 'CAROUSEL',
            'caption' => 'Carousel caption',
        ]);

    $sent = collect(igSent(IG_MEDIA))->keyBy(fn (Request $r) => (string) ($r['image_url'] ?? $r['video_url']));

    expect($sent['https://cdn.example.com/a.jpg']->data())->toBe([
        'user_tags' => [['username' => 'x', 'x' => 0.5, 'y' => 0.5]],
        'image_url' => 'https://cdn.example.com/a.jpg',
        'is_carousel_item' => true,
        'alt_text' => 'First',
    ])
        ->and($sent['https://cdn.example.com/b.mp4']->data())->toBe([
            'is_carousel_item' => true,
            'video_url' => 'https://cdn.example.com/b.mp4',
            'media_type' => 'REELS',
        ])
        ->and($sent['https://cdn.example.com/c.jpg']->hasHeader('Authorization', 'Bearer EAAGtoken123'))->toBeTrue();
});

it('creates the children one by one when pooling is off', function () {
    config(['social.api.instagram.pool_children' => false]);
    igFake([IG_MEDIA => igSeq(igRes(['id' => 'k1']), igRes(['id' => 'k2']))]);

    $outcome = igDriver()->publish(igPost([Media::image('https://cdn.example.com/a.jpg'), Media::image('https://cdn.example.com/b.jpg')]));

    expect($outcome->state['children'])->toBe(['k1', 'k2'])
        ->and($outcome->state['pending_videos'])->toBe([])
        ->and($outcome->recheckAfter)->toBe(5);
});

it('turns a dropped pooled connection into a temporary failure', function () {
    igFake([
        IG_MEDIA => fn (Request $request) => ($request['image_url'] ?? '') === 'https://cdn.example.com/b.jpg'
            ? (Http::failedConnection())($request)
            : Http::response(['id' => 'ok']),
    ]);

    $e = igCatch(fn () => igDriver()->publish(igPost([Media::image('https://cdn.example.com/a.jpg'), Media::image('https://cdn.example.com/b.jpg')])));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::Network)
        ->and($e->context)->toMatchArray(['connection' => true, 'carousel_index' => 1]);
});

it('maps a refused pooled child with its position', function () {
    igFake([
        IG_MEDIA => fn (Request $request) => ($request['image_url'] ?? '') === 'https://cdn.example.com/a.jpg'
            ? Http::response(['id' => 'ok'])
            : (igErr('media_fetch_failed'))(),
    ]);

    $e = igCatch(fn () => igDriver()->publish(igPost([Media::image('https://cdn.example.com/a.jpg'), Media::image('https://cdn.example.com/b.jpg')])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->context['carousel_index'])->toBe(1);
});

it('waits for every video child, then creates the parent', function () {
    igFake([
        'GET /v1' => igSeq(igStatus('IN_PROGRESS'), igStatus('FINISHED')),
        IG_MEDIA => igRes(['id' => 'parent']),
    ]);

    $post = igPost([Media::image('https://cdn.example.com/a.jpg'), Media::video('https://cdn.example.com/b.mp4')], 'Album', new InstagramOptions(extra: ['location_id' => '7'], collaborators: ['bob']));
    $state = ['phase' => 'await_children', 'children' => ['i1', 'v1'], 'pending_videos' => ['v1'], 'media_type' => 'CAROUSEL', 'caption' => 'Album', 'since' => time() - 100, 'created_at' => time() - 100, 'restarts' => 0];

    $waiting = igDriver()->resume($post, $state);

    expect($waiting->recheckAfter)->toBe(60)
        ->and($waiting->state['pending_videos'])->toBe(['v1'])
        ->and(igSent(IG_MEDIA))->toBe([]);

    $parent = igDriver()->resume($post, $waiting->state);

    expect(igSent(IG_MEDIA)[0]->data())->toBe([
        'location_id' => '7',
        'media_type' => 'CAROUSEL',
        'children' => 'i1,v1',
        'caption' => 'Album',
        'collaborators' => ['bob'],
    ])
        ->and($parent->recheckAfter)->toBe(20)
        ->and($parent->state)->toMatchArray(['phase' => 'await_carousel', 'container_id' => 'parent', 'children' => ['i1', 'v1'], 'pending_videos' => []]);
});

it('re-creates only the carousel child that failed on Meta’s side', function () {
    igFake([
        'GET /v1' => igStatus('ERROR', 'Media upload has failed with error code 2207053'),
        'GET /v2' => igStatus('IN_PROGRESS'),
        IG_MEDIA => igRes(['id' => 'v1b']),
    ]);

    $post = igPost([Media::video('https://cdn.example.com/a.mp4'), Media::image('https://cdn.example.com/b.jpg'), Media::video('https://cdn.example.com/c.mp4')]);
    $state = ['phase' => 'await_children', 'children' => ['v1', 'i1', 'v2'], 'pending_videos' => ['v1', 'v2'], 'media_type' => 'CAROUSEL'];

    $outcome = igDriver()->resume($post, $state);

    expect($outcome->state)->toMatchArray([
        'phase' => 'await_children',
        'children' => ['v1b', 'i1', 'v2'],
        'pending_videos' => ['v1b', 'v2'],
        'child_restarts' => [0 => 1],
    ])
        ->and(igSent(IG_MEDIA)[0]->data())->toBe([
            'is_carousel_item' => true,
            'video_url' => 'https://cdn.example.com/a.mp4',
            'media_type' => 'REELS',
        ]);
});

it('re-creates no carousel child before every child read succeeded', function () {
    igFake([
        'GET /v1' => igStatus('ERROR', 'Error: Media upload has failed with error code 2207001'),
        'GET /v2' => igErr('server_error'),
        IG_MEDIA => igRes(['id' => 'v1b']),
    ]);

    $post = igPost([Media::video('https://cdn.example.com/a.mp4'), Media::video('https://cdn.example.com/b.mp4')]);
    $state = ['phase' => 'await_children', 'children' => ['v1', 'v2'], 'pending_videos' => ['v1', 'v2'], 'media_type' => 'CAROUSEL'];

    $outcome = igDriver()->resume($post, $state);

    // A container created now would be lost with the failed poll: an orphan
    // against the 400-per-day limit, and a restart the cap never counts.
    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->state)->toMatchArray(['children' => ['v1', 'v2'], 'pending_videos' => ['v1', 'v2'], 'poll_errors' => 1])
        ->and(igSent(IG_MEDIA))->toBe([]);
});

it('keeps the carousel children it re-created when re-creating a later one fails', function () {
    igFake([
        'GET /v1' => igStatus('ERROR', 'Media upload has failed with error code 2207001'),
        'GET /v2' => igStatus('ERROR', 'Media upload has failed with error code 2207053'),
        IG_MEDIA => igSeq(igRes(['id' => 'v1b']), igErr('graph_503')),
    ]);

    $post = igPost([Media::video('https://cdn.example.com/a.mp4'), Media::video('https://cdn.example.com/b.mp4')]);
    $state = ['phase' => 'await_children', 'children' => ['v1', 'v2'], 'pending_videos' => ['v1', 'v2'], 'media_type' => 'CAROUSEL'];

    $outcome = igDriver()->resume($post, $state);

    expect($outcome)->toBeInstanceOf(Pending::class)
        ->and($outcome->state)->toMatchArray(['children' => ['v1b', 'v2'], 'child_restarts' => [0 => 1], 'poll_errors' => 1])
        ->and($outcome->state['pending_videos'])->toEqualCanonicalizing(['v1b', 'v2'])
        ->and(igSent(IG_MEDIA))->toHaveCount(2);
});

it('fails the carousel when a child has a media problem, naming the item', function () {
    igFake(['GET /v2' => igStatus('ERROR', 'Media upload has failed with error code 2207026')]);

    $post = igPost([Media::image('https://cdn.example.com/a.jpg'), Media::video('https://cdn.example.com/b.mp4')]);

    $e = igCatch(fn () => igDriver()->resume($post, ['phase' => 'await_children', 'children' => ['i1', 'v2'], 'pending_videos' => ['v2']]));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain('carousel item 2')
        ->and($e->context['carousel_index'])->toBe(1);
});

it('retries a failed child status read, and counts a published child as ready', function () {
    igFake([
        'GET /v1' => igSeq(igErr('graph_503'), igStatus('PUBLISHED')),
        IG_MEDIA => igRes(['id' => 'parent']),
    ]);

    $post = igPost([Media::video('https://cdn.example.com/a.mp4'), Media::image('https://cdn.example.com/b.jpg')]);
    $state = ['phase' => 'await_children', 'children' => ['v1', 'i1'], 'pending_videos' => ['v1']];

    $retry = igDriver()->resume($post, $state);

    expect($retry->state['poll_errors'])->toBe(1)
        ->and($retry->state['pending_videos'])->toBe(['v1']);

    $parent = igDriver()->resume($post, $retry->state);

    expect($parent->state)->toMatchArray(['phase' => 'await_carousel', 'container_id' => 'parent', 'poll_errors' => 0]);
});

it('re-creates an expired carousel child once', function () {
    igFake(['GET /v2' => igStatus('EXPIRED'), IG_MEDIA => igRes(['id' => 'v2b'])]);

    $post = igPost([Media::image('https://cdn.example.com/a.jpg'), Media::video('https://cdn.example.com/b.mp4')]);
    $state = ['phase' => 'await_children', 'children' => ['i1', 'v2'], 'pending_videos' => ['v2']];

    $retry = igDriver()->resume($post, $state);
    $fail = igCatch(fn () => igDriver()->resume($post, $state + ['child_restarts' => [1 => 1]]));

    expect($retry->state['children'])->toBe(['i1', 'v2b'])
        ->and($fail->reason)->toBe(FailureReason::ProcessingTimeout);
});

it('publishes a finished carousel with its children in the payload', function () {
    igFake([
        'GET /parent' => igStatus('FINISHED'),
        IG_PUBLISH => igRes(['id' => 'album']),
        'GET /album' => igRes(['permalink' => 'https://www.instagram.com/p/ALBUM/']),
    ]);

    $post = igPost([Media::image('https://cdn.example.com/a.jpg'), Media::image('https://cdn.example.com/b.jpg')]);
    $commit = igDriver()->resume($post, ['phase' => 'await_carousel', 'container_id' => 'parent', 'children' => ['i1', 'i2'], 'pending_videos' => [], 'media_type' => 'CAROUSEL']);

    expect($commit->commits)->toBeTrue();

    $published = igDriver()->resume($post, $commit->state);

    expect($published->result->platformPostId)->toBe('album')
        ->and($published->result->payload)->toMatchArray(['children' => ['i1', 'i2'], 'media_type' => 'CAROUSEL', 'container_id' => 'parent']);
});

// --- 1.0 continuations -----------------------------------------------------------------

it('resumes a 1.0 publish_retry continuation by checking the container first', function () {
    igFake([
        'GET /c1' => igStatus('FINISHED'),
        IG_PUBLISH => igRes(['id' => 'm1']),
        'GET /m1' => igRes(['permalink' => 'https://www.instagram.com/p/M1/']),
    ]);

    $outcome = igDriver()->resume(igPost(), ['phase' => 'publish_retry', 'container_id' => 'c1']);

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->platformPostId)->toBe('m1')
        ->and(igSent('GET /c1'))->toHaveCount(1);
});

it('treats a 1.0 publish_retry that hits not-ready again as a not-ready round', function () {
    igFake(['GET /c1' => igStatus('FINISHED'), IG_PUBLISH => igErr('not_ready')]);

    // The job stamps a 1.0 continuation's _started_at with the time of this
    // run, not when the post began: it says nothing about the container.
    $outcome = igDriver()->resume(igPost(), ['phase' => 'publish_retry', 'container_id' => 'c1', Pending::STARTED_AT => time()]);

    expect($outcome->state)->toMatchArray(['phase' => 'publish', 'not_ready' => 2, 'media_type' => 'IMAGE'])
        ->and($outcome->state['since'])->toBeLessThanOrEqual(time() - 86400)
        ->and($outcome->state)->not->toHaveKey('created_at')
        ->and($outcome->state)->not->toHaveKey(Pending::STARTED_AT)
        ->and($outcome->deadline)->toBeNull();
});

it('searches the whole container lifetime when it recovers a 1.0 continuation', function () {
    igFake([
        'GET /c1' => igStatus('PUBLISHED'),
        IG_LISTING => igRes(['data' => [
            ['id' => 'm1', 'caption' => 'Hello #instagram', 'timestamp' => igTime(900), 'media_type' => 'VIDEO', 'permalink' => 'https://www.instagram.com/reel/M1/'],
        ]]),
    ]);

    // A 1.0 payload: the job injects _started_at = now (v1.0 jobs carry no start time).
    $outcome = igDriver()->resume(igPost([Media::video('https://cdn.example.com/a.mp4')]), [
        'phase' => 'await_container',
        'container_id' => 'c1',
        Pending::STARTED_AT => time(),
        Pending::RECOVERING => false,
        Pending::ATTEMPT => 1,
        Pending::RESTARTS => 0,
    ]);

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->platformPostId)->toBe('m1')
        ->and($outcome->result->url)->toBe('https://www.instagram.com/reel/M1/');
});

it('resumes 1.0 await_children and await_container states', function () {
    igFake([
        'GET /v1' => igStatus('FINISHED'),
        'GET /r1' => igStatus('FINISHED'),
        IG_MEDIA => igRes(['id' => 'parent']),
    ]);

    $carousel = igDriver()->resume(
        igPost([Media::image('https://cdn.example.com/a.jpg'), Media::video('https://cdn.example.com/b.mp4')], 'ignored'),
        ['phase' => 'await_children', 'children' => ['i1', 'v1'], 'pending_videos' => ['v1'], 'caption' => ' 1.0 caption '],
    );

    expect(igSent(IG_MEDIA)[0]->data())->toMatchArray(['caption' => '1.0 caption', 'children' => 'i1,v1'])
        ->and($carousel->state)->toMatchArray(['phase' => 'await_carousel', 'media_type' => 'CAROUSEL']);

    $reel = igDriver()->resume(igPost([Media::video('https://cdn.example.com/r.mp4')], 'Reel'), ['phase' => 'await_container', 'container_id' => 'r1']);

    expect($reel->state)->toMatchArray(['phase' => 'publish', 'media_type' => 'REELS', 'caption' => 'Reel'])
        ->and($reel->state['since'])->toBeLessThanOrEqual(time() - 86400);
});

it('refuses an unknown phase or a state without a container', function (array $state) {
    expect(igCatch(fn () => igDriver()->resume(igPost(), $state)))->toBeInstanceOf(PermanentException::class);
})->with([
    'unknown phase' => [['phase' => 'mystery']],
    'no phase' => [[]],
    'no container' => [['phase' => 'publish', 'ready' => true]],
]);

// --- Quota pre-flight, identity, scopes, transformer -------------------------------------

it('refuses to publish when the daily quota is used up (opt-in pre-flight)', function () {
    config(['social.api.instagram.check_quota' => true]);
    igFake(['GET /'.IG_ACCOUNT.'/content_publishing_limit' => igRes(['data' => [['quota_usage' => 100, 'config' => ['quota_total' => 100, 'quota_duration' => 86400]]]])]);

    $e = igCatch(fn () => igDriver()->publish(igPost()));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::RateLimited)
        ->and($e->context)->toMatchArray(['daily_limit' => true, 'retry_after_hint' => 86400, 'quota_usage' => 100])
        ->and($e->context['retry_at'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/');
    expect(igSent(IG_MEDIA))->toBe([]);
});

it('publishes when the quota pre-flight passes or cannot be read', function (string $case) {
    config(['social.api.instagram.check_quota' => true]);

    $quota = match ($case) {
        'room left' => igRes(['data' => [['quota_usage' => 3, 'config' => ['quota_total' => 100]]]]),
        'error' => igErr('graph_503'),
        default => Http::failedConnection(),
    };

    igFake(['GET /'.IG_ACCOUNT.'/content_publishing_limit' => $quota, IG_MEDIA => igRes(['id' => 'c1'])]);

    expect(igDriver()->publish(igPost()))->toBeInstanceOf(Pending::class)
        ->and(igSent(IG_MEDIA))->toHaveCount(1);
})->with(['room left', 'error', 'dropped']);

it('identifies the account by account_id', function () {
    expect(igDriver()->accountKey(new Credentials(igCreds(['access_token' => 'EAA-rotated']))))->toBe(IG_ACCOUNT);
});

it('declares the scopes of the account login', function (array $credentials, Feature $feature, array $scopes) {
    expect(igDriver()->scopesFor($feature, new Credentials($credentials)))->toBe($scopes);
})->with([
    'facebook publish' => [['access_token' => 'EAAx'], Feature::Publish, [['instagram_basic'], ['instagram_content_publish']]],
    'instagram publish' => [['login' => 'instagram'], Feature::Publish, [['instagram_business_basic'], ['instagram_business_content_publish']]],
    'unknown publish' => [[], Feature::Publish, [['instagram_basic', 'instagram_business_basic'], ['instagram_content_publish', 'instagram_business_content_publish']]],
    'facebook comment' => [['login' => 'facebook'], Feature::Comment, [['instagram_manage_comments']]],
    'instagram comment' => [['access_token' => 'IGAAx'], Feature::Comment, [['instagram_business_manage_comments']]],
    'video adds nothing' => [[], Feature::Video, []],
]);

it('sends the media a bound MediaTransformer produces, unless the gateway cannot publish it', function () {
    app()->instance(MediaTransformer::class, new class implements MediaTransformer
    {
        public function transform(Media $media, Platform $platform, Capabilities $caps): Media
        {
            return $media->extension() === 'webp'
                ? new Media('https://converted.example.com/'.basename($media->source, '.webp').'.jpg', MediaType::Image)
                : ($media->extension() === 'heic' ? new Media('/tmp/converted.jpg', MediaType::Image) : $media);
        }
    });

    igFake([IG_MEDIA => igRes(['id' => 'c1'])]);

    igDriver()->publish(igPost([Media::image('https://cdn.example.com/pic.webp', 'alt')]));
    igDriver()->publish(igPost([Media::image('https://cdn.example.com/pic.heic')]));

    expect(igSent(IG_MEDIA)[0]->data())->toMatchArray(['image_url' => 'https://converted.example.com/pic.jpg', 'alt_text' => 'alt'])
        ->and(igSent(IG_MEDIA)[1]->data()['image_url'])->toBe('https://cdn.example.com/pic.heic');
});
