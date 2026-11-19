<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\LinkedIn\LinkedInOptions;
use SocialPoster\Support\PublicUrl;
use SocialPoster\Tests\Fixtures\linkedin\LinkedInKit as Kit;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;

afterEach(function () {
    PublicUrl::resolveUsing(null);
    Kit::cleanup();
});

/** @return array<string, mixed> an upload_video state over the fixture's three parts */
function liUploadState(array $overrides = []): array
{
    return $overrides + [
        'phase' => 'upload_video', 'video' => Kit::VIDEO, 'size' => 200000, 'upload_token' => '',
        'instructions' => Kit::json('video_init')['value']['uploadInstructions'], 'etags' => [], 'next' => 0,
    ];
}

// --- Upload internals -------------------------------------------------------------------

it('keeps the deprecated v1.0 upload helpers working', function () {
    Http::fake([
        'www.linkedin.com/dms-uploads/*' => fn (Request $r) => Http::response('', 200, ['ETag' => 'e'.substr($r->url(), -1)]),
        'api.linkedin.com/rest/videos/*' => Kit::fixture('video_status_processing'),
    ]);

    $driver = Kit::driver();
    $path = Kit::file(200000, 'mp4');

    expect($driver->call('uploadVideoChunks', Kit::post(), $path, Kit::json('video_init')['value']['uploadInstructions']))->toBe(['e0', 'e1', 'e2'])
        ->and($driver->call('assetStatus', Kit::post(), Kit::VIDEO))->toBe('PROCESSING')
        ->and($driver->call('urnId', Kit::VIDEO))->toBe('C5505AQH-oV1qvnFtKA');
});

it('fails a staged upload resumed for a post without its video', function () {
    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post(), liUploadState()));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Misconfigured);
});

it('fails a continuation that lost its urn', function () {
    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post(), ['phase' => 'await_video']));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->context['missing_field'])->toBe('video');
});

it('reports a part that the file can no longer fill', function () {
    $e = Kit::catch(fn () => Kit::driver()->call('partBytes', Kit::file(100, 'mp4'), ['uploadUrl' => 'https://x', 'firstByte' => 50, 'lastByte' => 149]));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->context['read'])->toBe(50);
});

it('turns a part connection failure into a temporary error naming the part', function () {
    Http::fake(['www.linkedin.com/dms-uploads/*' => Kit::connectionFailure('cURL error 56: Recv failure: Connection reset by peer')]);

    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post([Media::video(Kit::file(200000, 'mp4'))]), liUploadState()));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::Network)
        ->and($e->context['part'])->toBe(0);
});

it('turns a failed concurrent transfer into a temporary network error', function () {
    config(['social.api.linkedin.upload_concurrency' => 3]);
    Http::fake(['www.linkedin.com/dms-uploads/*' => Kit::connectionFailure('cURL error 7: Failed to connect')]);

    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post([Media::video(Kit::file(200000, 'mp4'))]), liUploadState()));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::Network);
});

it('uploads parts one at a time when an authorizer is configured', function () {
    config(['social.api.linkedin.upload_concurrency' => 3]);

    expect(Kit::driver()->call('uploadConcurrency'))->toBe(3);

    config(['social.authorizers.linkedin' => 'App\\Auth\\LinkedInAuthorizer']);

    expect(Kit::driver()->call('uploadConcurrency'))->toBe(1);
});

it('reads upload expiry in seconds or milliseconds', function () {
    $driver = Kit::driver();

    expect($driver->call('uploadUrlsExpired', ['upload_expires_at' => time() - 5]))->toBeTrue()
        ->and($driver->call('uploadUrlsExpired', ['upload_expires_at' => (time() + 600) * 1000]))->toBeFalse()
        ->and($driver->call('uploadUrlsExpired', []))->toBeFalse();
});

it('keeps the finalize error when the status cannot be read either', function () {
    Http::fake([
        'api.linkedin.com/rest/videos?action=finalizeUpload' => Http::response(Kit::json('error_409_conflict'), 409),
        'api.linkedin.com/rest/videos/*' => Http::response(Kit::json('error_500'), 500),
    ]);

    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post([Media::video(Kit::file(200000, 'mp4'))]), ['phase' => 'finalize_video', 'video' => Kit::VIDEO, 'etags' => ['a']]));

    // finalize_video reads the status first: a failing read is a temporary error.
    expect($e)->toBeInstanceOf(TemporaryException::class);

    $direct = Kit::catch(fn () => Kit::driver()->call('finalizeVideo', Kit::post(), null, ['video' => Kit::VIDEO, 'etags' => ['a']]));

    expect($direct)->toBeInstanceOf(TemporaryException::class)
        ->and($direct->getMessage())->toContain('write conflict');
});

// --- Recovery reads -----------------------------------------------------------------------

it('verifies with r_organization_social for page authors', function () {
    Http::fake(['api.linkedin.com/rest/posts*' => Http::response(['elements' => [['id' => Kit::SHARE, 'commentary' => 'Hello LinkedIn']]])]);

    $post = Kit::post(credentials: Kit::credentials(['author' => Kit::ORG, 'scopes' => ['w_organization_social', 'r_organization_social']]));
    $outcome = Kit::driver()->resume($post, ['phase' => 'create_post', 'commentary' => 'Hello LinkedIn', 'content' => null, Pending::RECOVERING => true]);

    expect($outcome->result->platformPostId)->toBe(Kit::SHARE);
    Http::assertSent(fn (Request $r) => $r['author'] === Kit::ORG);
});

it('retries a recovery read that hit the network', function () {
    config(['social.api.linkedin.verify_reads' => true]);
    Http::fake(['api.linkedin.com/rest/posts*' => Kit::connectionFailure('cURL error 28: Operation timed out after 1000 milliseconds with 0 bytes received')]);

    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post(), ['phase' => 'create_post', 'commentary' => 'x', 'content' => null, Pending::RECOVERING => true]));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->context['verify'])->toBeTrue();
});

it('matches recovered posts on commentary, time and content', function () {
    $driver = Kit::driver();
    $payload = ['commentary' => 'Hi \\(there\\)', 'content' => ['multiImage' => ['images' => [['id' => 'urn:li:image:A']]]]];
    $since = time() - 30;

    expect($driver->call('matchesPost', ['commentary' => 'Hi (there)', 'createdAt' => time() * 1000, 'content' => ['multiImage' => ['images' => [['id' => 'urn:li:image:A']]]]], $payload, $since))->toBeTrue()
        ->and($driver->call('matchesPost', ['commentary' => "Hi  \\(there\\)\n"], $payload, $since))->toBeTrue()
        ->and($driver->call('matchesPost', ['commentary' => 'Hi (there)', 'content' => ['multiImage' => ['images' => [['id' => 'urn:li:image:B']]]]], $payload, $since))->toBeFalse()
        ->and($driver->call('matchesPost', ['commentary' => 'Hi (there)', 'createdAt' => ($since - 3600) * 1000], $payload, $since))->toBeFalse()
        ->and($driver->call('matchesPost', ['commentary' => 'Bye'], $payload, $since))->toBeFalse()
        ->and($driver->call('contentKey', ['article' => ['source' => 'https://x']]))->toBe('article.source=https://x')
        ->and($driver->call('contentKey', ['poll' => ['question' => 'Q']]))->toBe('poll.question=Q')
        ->and($driver->call('contentKey', 'nope'))->toBeNull();
});

// --- Link cards ----------------------------------------------------------------------------

it('never fetches a link card from a private address when the SSRF guard is on', function () {
    config(['social.media.block_private_networks' => true]);
    PublicUrl::resolveUsing(fn () => ['127.0.0.1']);
    Http::fake();

    $pending = Kit::driver()->publish(Kit::post(caption: 'https://intranet.example.com/page', options: new LinkedInOptions(autoLinkCard: true)));

    expect($pending->state['content'])->toBeNull();
    Http::assertNothingSent();
});

it('pins the checked address when fetching a public link card', function () {
    config(['social.media.block_private_networks' => true]);
    PublicUrl::resolveUsing(fn () => ['93.184.216.34']);
    Http::fake(['example.com/*' => Http::response('<title>Example &amp; co</title>', 200)]);

    $pending = Kit::driver()->publish(Kit::post(caption: 'https://example.com/a', options: new LinkedInOptions(autoLinkCard: true)));

    expect($pending->state['content'])->toBe(['article' => ['source' => 'https://example.com/a', 'title' => 'Example & co']]);
});

it('reads Open Graph, Twitter and plain HTML tags', function () {
    $driver = Kit::driver();

    $twitter = $driver->call('parseOpenGraph', '<meta name="twitter:title" content="T"><meta name=description content=Plain><meta name="twitter:image" content="//cdn.example.com/i.png">', 'https://example.com/blog/post');
    $relative = $driver->call('parseOpenGraph', '<title> Spaced   title </title><meta property="og:image" content="img/c.png">', 'https://example.com/blog/post');
    $long = $driver->call('parseOpenGraph', '<meta property="og:title" content="'.str_repeat('x', 250).'"><meta property="og:image" content="https://cdn.example.com/a.png">', 'https://example.com');

    expect($twitter)->toBe(['title' => 'T', 'description' => 'Plain', 'image' => 'https://cdn.example.com/i.png'])
        ->and($relative)->toBe(['title' => 'Spaced title', 'image' => 'https://example.com/blog/img/c.png'])
        ->and(mb_strlen($long['title']))->toBe(200)
        ->and($long['title'])->toEndWith('…')
        ->and($long['image'])->toBe('https://cdn.example.com/a.png')
        ->and($driver->call('absoluteUrl', '/root.png', 'https://example.com:8443/a/b'))->toBe('https://example.com:8443/root.png')
        ->and($driver->call('parseOpenGraph', '<p>no tags</p>', 'https://example.com'))->toBe([]);
});

it('skips remote card thumbnails that are not images or are too large', function (array $headers, string $body) {
    config(['social.api.linkedin.link_card_image_max_bytes' => 10]);
    Http::fake(['cdn.example.com/*' => Http::response($body, 200, $headers)]);

    expect(Kit::driver()->call('fetchRemoteImage', 'https://cdn.example.com/x'))->toBeNull();
})->with([
    'html' => [['Content-Type' => 'text/html'], '<html>'],
    'too large' => [['Content-Type' => 'image/png'], str_repeat('x', 11)],
    'empty' => [['Content-Type' => 'image/png'], ''],
]);

it('uploads a local card thumbnail and drops an article that is not valid at runtime', function () {
    Http::fake([
        'api.linkedin.com/rest/images?action=initializeUpload' => Kit::fixture('image_init'),
        'www.linkedin.com/dms-uploads/*' => Http::response('', 201),
    ]);

    $local = Kit::driver()->publish(Kit::post(options: new LinkedInOptions(article: ['source' => 'https://example.com', 'title' => 'T', 'thumbnail' => Kit::file(64, 'png')])));
    $untitled = Kit::driver()->publish(Kit::post(options: new LinkedInOptions(article: ['source' => 'https://example.com'])));

    expect($local->state['content']['article']['thumbnail'])->toBe(Kit::IMAGE)
        ->and($untitled->state['content'])->toBeNull();
});

// --- Mentions from URLs -------------------------------------------------------------------

it('resolves profiles through a page author, up to max_auto_mentions links', function () {
    config(['social.api.linkedin.max_auto_mentions' => 1]);
    Http::fake([
        'api.linkedin.com/rest/vanityUrl*' => Kit::fixture('vanity_url_member'),
        'api.linkedin.com/rest/people/*' => Kit::fixture('people_jane'),
    ]);

    $pending = Kit::driver()->publish(Kit::post(
        caption: 'linkedin.com/in/jane-doe and linkedin.com/company/acme',
        options: new LinkedInOptions(autoMentionUrls: true),
        credentials: Kit::credentials(['author' => Kit::ORG]),
    ));

    expect($pending->state['commentary'])->toBe('@[Jane Doe](urn:li:person:Jd0eXyZ12) and linkedin.com/company/acme');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'vanityUrl') && $r['organization'] === Kit::ORG);
    expect(Kit::sent('v2/organizations'))->toBe(0);
});

it('stops looking links up after twice max_auto_mentions, even when none resolve', function () {
    config(['social.api.linkedin.max_auto_mentions' => 1]);
    Http::fake(['api.linkedin.com/rest/vanityUrl*' => Http::response(['status' => 404, 'message' => 'Not found'], 404)]);

    Kit::driver()->publish(Kit::post(
        caption: 'linkedin.com/company/a linkedin.com/company/b linkedin.com/company/c linkedin.com/company/d',
        options: new LinkedInOptions(autoMentionUrls: true),
    ));

    expect(Kit::sent('rest/vanityUrl'))->toBeLessThanOrEqual(2);
});

it('does not resolve mentions in invalid UTF-8 captions', function () {
    Http::fake();

    $pending = Kit::driver()->publish(Kit::post(caption: "bad \xC3 linkedin.com/company/acme", options: new LinkedInOptions(autoMentionUrls: true)));

    expect($pending->state['commentary'])->toContain('linkedin.com/company/acme');
    Http::assertNothingSent();
});

// --- Logging --------------------------------------------------------------------------------

it('logs non-fatal problems as warnings, on the configured channel, never with the token', function () {
    config(['social.error_logging' => true, 'social.error_log_channel' => null]);
    Log::spy();
    Http::fake(['blog.example.com/*' => Kit::connectionFailure('cURL error 6: Could not resolve host: blog.example.com')]);

    Kit::driver()->publish(Kit::post(caption: 'https://blog.example.com/x', options: new LinkedInOptions(autoLinkCard: true)));

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_starts_with($message, '[SocialPoster] linkedin: The LinkedIn link card could not be built')
        && $context['platform'] === 'linkedin'
        && ! str_contains(json_encode($context), Kit::TOKEN));
});

it('stays quiet when error logging is off', function () {
    config(['social.error_logging' => false]);
    Log::spy();

    Kit::driver()->call('logWarning', 'nothing');

    Log::shouldNotHaveReceived('warning');
});
