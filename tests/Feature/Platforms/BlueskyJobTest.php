<?php

/*
 * The Bluesky driver run through the real PublishToConnectionJob (sync queue):
 * what the job concludes from failures inside the 'create' commit phase.
 */

namespace SocialPoster\Tests\Feature\Platforms\BlueskyJob;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\PostFailed;
use SocialPoster\Events\PostPublished;
use SocialPoster\Jobs\PublishToConnectionJob;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\SocialPost;

require_once __DIR__.'/../../Fixtures/bluesky/helpers.php';

beforeEach(function () {
    Cache::flush();
    config(['social.authorizers' => []]);
});

afterEach(function () {
    bskyCleanup();
});

it('does not call a post possibly published when it failed before the create, and lets it be sent again', function () {
    Event::fake([PostFailed::class, PostPublished::class]);
    Http::fake([
        '*/xrpc/com.atproto.repo.uploadBlob' => Http::sequence()
            ->push(['error' => 'UpstreamFailure', 'message' => 'Upstream Failure'], 502)
            ->push(bskyFixture('upload_blob.json')),
        '*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord(),
    ]);

    $post = new SocialPost([Media::image(bskyImage())], 'Hello');
    $credentials = new Credentials(bskyCredentials());

    dispatch(new PublishToConnectionJob(Platform::Bluesky, $post, $credentials, false, null, 0, 'corr-1', 'bsky-same-key'));

    $failed = null;
    Event::assertDispatched(PostFailed::class, function (PostFailed $event) use (&$failed) {
        $failed = $event;

        return true;
    });

    expect(bskySent('createRecord'))->toBe([])
        ->and($failed->possiblyPublished)->toBeFalse();

    // The key was released, not kept as "may be live": the same post goes out.
    dispatch(new PublishToConnectionJob(Platform::Bluesky, $post, $credentials, false, null, 0, 'corr-2', 'bsky-same-key'));

    Event::assertDispatched(PostPublished::class);
    expect(bskySent('createRecord'))->toHaveCount(1);
});
