<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\LinkedIn\LinkedInArticle;
use SocialPoster\Platforms\LinkedIn\LinkedInMention;
use SocialPoster\Platforms\LinkedIn\LinkedInOptions;
use SocialPoster\Platforms\LinkedIn\LinkedInPoll;
use SocialPoster\Tests\Fixtures\linkedin\LinkedInKit as Kit;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\Published;

afterEach(fn () => Kit::cleanup());

/** Run the create_post commit step the way the job would, returning the Published outcome. */
function liCreate(Pending $pending, ?PreparedPost $post = null): Published
{
    expect($pending->commits)->toBeTrue()
        ->and($pending->state['phase'])->toBe('create_post');

    $outcome = Kit::driver()->resume($post ?? Kit::post(), $pending->state);

    expect($outcome)->toBeInstanceOf(Published::class);

    return $outcome;
}

/** @return array<string, mixed> The JSON body of the first recorded request matching $needle and $method. */
function liBody(string $needle, string $method = 'POST'): array
{
    $request = Http::recorded(fn (Request $r) => str_contains($r->url(), $needle) && $r->method() === $method)->first()[0] ?? null;

    expect($request)->not->toBeNull();

    return $request->data();
}

/** The standard video fakes; $statuses are the GET /rest/videos/{urn} answers in order. */
function liVideoFakes(array $statuses = ['video_status_available'], array $overrides = []): void
{
    $sequence = Http::sequence();

    foreach ($statuses as $status) {
        $sequence->push(Kit::json($status));
    }

    Http::fake($overrides + [
        'api.linkedin.com/rest/videos?action=initializeUpload' => Kit::fixture('video_init'),
        'www.linkedin.com/dms-uploads/sp/C5505AQH/uploadedThumbnail*' => Http::response('', 201),
        'www.linkedin.com/dms-uploads/*' => fn (Request $r) => Http::response('', 200, ['ETag' => '/ambry-video/etag-'.substr($r->url(), -1)]),
        'api.linkedin.com/rest/videos?action=finalizeUpload' => Http::response('', 200),
        'api.linkedin.com/rest/videos/*' => $sequence,
        'api.linkedin.com/rest/posts' => Kit::created(),
    ]);
}

function liVideo(?string $thumbnail = null, ?string $alt = null): Media
{
    return Media::video(Kit::file(200000, 'mp4'), $thumbnail, $alt);
}

// --- Text -----------------------------------------------------------------------

it('prepares a text post, then creates it in its own commit step', function () {
    Http::fake(['api.linkedin.com/rest/posts' => Kit::created()]);

    $post = Kit::post(caption: 'Launch day (finally) | C# & #php8 @team ~ *bold* <tag> [x] {y} back\\slash');
    $pending = Kit::driver()->publish($post);

    expect($pending)->toBeInstanceOf(Pending::class)
        ->and($pending->commits)->toBeTrue()
        ->and($pending->recheckAfter)->toBe(0)
        ->and($pending->state['phase'])->toBe('create_post')
        ->and($pending->state['content'])->toBeNull()
        ->and($pending->state['commentary'])->toBe('Launch day \\(finally\\) \\| C\\# & #php8 \\@team \\~ \\*bold\\* \\<tag\\> \\[x\\] \\{y\\} back\\\\slash');

    Http::assertNothingSent();

    $result = liCreate($pending, $post)->result;

    expect($result->platformPostId)->toBe(Kit::SHARE)
        ->and($result->url)->toBe('https://www.linkedin.com/feed/update/'.Kit::SHARE.'/')
        ->and($result->state)->toBe(PostResult::STATE_PUBLISHED)
        ->and($result->idKind)->toBe('urn')
        ->and($result->payload)->toBe(['urn' => Kit::SHARE]);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.linkedin.com/rest/posts'
        && $r->method() === 'POST'
        && $r->hasHeader('LinkedIn-Version', '202605')
        && $r->hasHeader('X-Restli-Protocol-Version', '2.0.0')
        && $r->hasHeader('Authorization', 'Bearer '.Kit::TOKEN)
        && $r['author'] === Kit::PERSON
        && $r['commentary'] === $pending->state['commentary']
        && $r['visibility'] === 'PUBLIC'
        && $r['distribution'] === ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []]
        && $r['lifecycleState'] === 'PUBLISHED'
        && $r['isReshareDisabledByAuthor'] === false
        && ! isset($r['content'])
        && ! isset($r['reshareContext']));
});

it('reads the post urn from x-linkedin-id when x-restli-id is absent, and sends the configured version', function () {
    config(['social.api.linkedin.version' => '202611']);
    Http::fake(['api.linkedin.com/rest/posts' => Kit::created('urn:li:ugcPost:7012345678901234000', 'x-linkedin-id')]);

    $result = liCreate(Kit::driver()->publish(Kit::post()))->result;

    expect($result->platformPostId)->toBe('urn:li:ugcPost:7012345678901234000');
    Http::assertSent(fn (Request $r) => $r->hasHeader('LinkedIn-Version', '202611'));
});

it('treats a created post without an urn as a possibly published outcome', function () {
    Http::fake(['api.linkedin.com/rest/posts' => Http::response('', 201)]);

    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post(), ['phase' => 'create_post', 'commentary' => 'Hi', 'content' => null]));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->possiblyPublished())->toBeTrue();
});

it('sends hand-built little text untouched with escapeText false', function () {
    Http::fake(['api.linkedin.com/rest/posts' => Kit::created()]);

    $pending = Kit::driver()->publish(Kit::post(caption: 'Hi @[Acme](urn:li:organization:1) {hashtag|\\#|x}', options: new LinkedInOptions(escapeText: false)));

    expect($pending->state['commentary'])->toBe('Hi @[Acme](urn:li:organization:1) {hashtag|\\#|x}');
});

it('escapes hashtag look-alikes but keeps real hashtags, including in invalid UTF-8', function () {
    $driver = Kit::driver();

    expect($driver->call('escape', 'C# dev, # 1, end#'))->toBe('C\\# dev, \\# 1, end\\#')
        ->and($driver->call('escape', '#coding #café #2026 ##twice'))->toBe('#coding #café #2026 \\##twice')
        ->and($driver->call('escape', "bad \xC3( utf | x"))->toBe("bad \xC3\\( utf \\| x")
        ->and($driver->call('escape', "#\xC3\xA9t\xE9"))->toBe("#\xC3\xA9t\xE9");
});

it('renders first-class mentions exactly as v1.0 did, escaping names and text', function () {
    $options = new LinkedInOptions(mentions: [
        new LinkedInMention('urn:li:organization:2414183', 'Acme (EU) Inc'),
        ['urn' => ' urn:li:person:Jd0eXyZ12 ', 'name' => 'Jane Doe'],
    ]);

    $pending = Kit::driver()->publish(Kit::post(caption: 'Thanks {{mention:0}} & {{mention:1}} (again) {{mention:7}}', options: $options));

    expect($pending->state['commentary'])
        ->toBe('Thanks @[Acme \\(EU\\) Inc](urn:li:organization:2414183) & @[Jane Doe](urn:li:person:Jd0eXyZ12) \\(again\\) \\{\\{mention:7\\}\\}');
});

it('sends the typed visibility, reshare and distribution options, which extra cannot override', function () {
    Http::fake(['api.linkedin.com/rest/posts' => Kit::created()]);

    $options = new LinkedInOptions(
        extra: ['visibility' => 'CONNECTIONS', 'isReshareDisabledByAuthor' => false, 'contentLandingPage' => 'https://example.com', 'content' => ['poll' => ['question' => 'raw']]],
        visibility: 'logged_in',
        disableReshare: true,
        feedDistribution: 'NONE',
        targetEntities: [['geoLocations' => ['urn:li:geo:103644278']]],
    );
    $post = Kit::post(options: $options, credentials: Kit::credentials(['author' => Kit::ORG]));

    liCreate(Kit::driver()->publish($post), $post);

    $body = liBody('rest/posts');

    expect($body['author'])->toBe(Kit::ORG)
        ->and($body['visibility'])->toBe('LOGGED_IN')
        ->and($body['isReshareDisabledByAuthor'])->toBeTrue()
        ->and($body['distribution'])->toBe(['feedDistribution' => 'NONE', 'targetEntities' => [['geoLocations' => ['urn:li:geo:103644278']]], 'thirdPartyDistributionChannels' => []])
        ->and($body['contentLandingPage'])->toBe('https://example.com')
        // Text posts set no content of their own, so extra may still supply it.
        ->and($body['content'])->toBe(['poll' => ['question' => 'raw']]);
});

it('reshares a post with empty commentary', function () {
    Http::fake(['api.linkedin.com/rest/posts' => Kit::created()]);

    $post = Kit::post(caption: '', options: new LinkedInOptions(reshareOf: 'urn:li:share:7012345678901230000'));

    expect(Kit::driver()->validate($post)->all())->toBe([]);

    liCreate(Kit::driver()->publish($post), $post);
    $body = liBody('rest/posts');

    expect($body['commentary'])->toBe('')
        ->and($body['reshareContext'])->toBe(['parent' => 'urn:li:share:7012345678901230000'])
        ->and($body)->not->toHaveKey('content');
});

it('publishes an organic poll', function () {
    Http::fake(['api.linkedin.com/rest/posts' => Kit::created()]);

    $post = Kit::post(caption: 'Vote!', options: new LinkedInOptions(poll: ['question' => 'Tabs or spaces?', 'options' => ['Tabs', 'Spaces'], 'duration' => 'seven_days']));
    $pending = Kit::driver()->publish($post);

    expect($pending->state['content'])->toBe(['poll' => [
        'question' => 'Tabs or spaces?',
        'options' => [['text' => 'Tabs'], ['text' => 'Spaces']],
        'settings' => ['duration' => 'SEVEN_DAYS'],
    ]]);

    liCreate($pending, $post);
    expect(liBody('rest/posts')['content']['poll']['question'])->toBe('Tabs or spaces?');
});

// --- Link cards ---------------------------------------------------------------------

it('sends an explicit link card with its thumbnail uploaded through the Images API', function () {
    Http::fake([
        'cdn.example.com/og.png' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png']),
        'api.linkedin.com/rest/images?action=initializeUpload' => Kit::fixture('image_init'),
        'www.linkedin.com/dms-uploads/*' => Http::response('', 201),
        'api.linkedin.com/rest/posts' => Kit::created(),
    ]);

    $article = LinkedInArticle::make('https://example.com/post', 'A title', 'A description', 'https://cdn.example.com/og.png', 'Card alt');
    $post = Kit::post(caption: 'Read this', options: new LinkedInOptions(article: $article));
    $pending = Kit::driver()->publish($post);

    expect($pending->state['content'])->toBe(['article' => [
        'source' => 'https://example.com/post',
        'title' => 'A title',
        'description' => 'A description',
        'thumbnail' => Kit::IMAGE,
        'thumbnailAltText' => 'Card alt',
    ]]);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'dms-uploads') && $r->method() === 'PUT' && $r->body() === 'PNGDATA');

    liCreate($pending, $post);
});

it('uses an already uploaded image urn as the card thumbnail and drops a thumbnail that fails', function () {
    Http::fake([
        'cdn.example.com/*' => Http::response('nope', 404),
        'api.linkedin.com/rest/posts' => Kit::created(),
    ]);

    $withUrn = Kit::driver()->publish(Kit::post(options: new LinkedInOptions(article: ['source' => 'https://example.com/a', 'title' => 'T', 'thumbnail' => Kit::IMAGE])));
    $broken = Kit::driver()->publish(Kit::post(options: new LinkedInOptions(article: ['url' => 'https://example.com/b', 'title' => 'T', 'thumbnail' => 'https://cdn.example.com/missing.png'])));

    expect($withUrn->state['content'])->toBe(['article' => ['source' => 'https://example.com/a', 'title' => 'T', 'thumbnail' => Kit::IMAGE]])
        ->and($broken->state['content'])->toBe(['article' => ['source' => 'https://example.com/b', 'title' => 'T']]);
});

it('builds a card from the first non-LinkedIn link with autoLinkCard', function () {
    $html = <<<'HTML'
        <html><head>
        <title>Fallback title</title>
        <meta content="Ship &amp; tell" property="og:title">
        <meta property='og:description' content='How we ship weekly.'>
        <meta property="og:image" content="/img/card.png">
        </head><body></body></html>
        HTML;

    Http::fake([
        'blog.example.com/img/*' => Http::response('IMG', 200, ['Content-Type' => 'image/png']),
        'blog.example.com/*' => Http::response($html, 200, ['Content-Type' => 'text/html']),
        'api.linkedin.com/rest/images?action=initializeUpload' => Kit::fixture('image_init'),
        'www.linkedin.com/dms-uploads/*' => Http::response('', 201),
    ]);

    $post = Kit::post(caption: 'See https://www.linkedin.com/in/someone and https://blog.example.com/post?id=1 today', options: new LinkedInOptions(autoLinkCard: true));
    $pending = Kit::driver()->publish($post);

    expect($pending->state['content'])->toBe(['article' => [
        'source' => 'https://blog.example.com/post?id=1',
        'title' => 'Ship & tell',
        'description' => 'How we ship weekly.',
        'thumbnail' => Kit::IMAGE,
    ]]);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://blog.example.com/img/card.png');
});

it('posts without a card when the page cannot be read or has no title', function (Closure $page) {
    Http::fake(['blog.example.com/*' => $page()]);

    $pending = Kit::driver()->publish(Kit::post(caption: 'See blog.example.com/post', options: new LinkedInOptions(autoLinkCard: true)));

    expect($pending->state['content'])->toBeNull();
})->with([
    '404' => fn () => Http::response('missing', 404),
    'no title' => fn () => Http::response('<html><head><meta property="og:description" content="x"></head></html>', 200),
    'network' => fn () => Kit::connectionFailure('cURL error 6: Could not resolve host: blog.example.com'),
]);

it('builds no card for media posts, polls or captions without links', function () {
    Http::fake();

    $noLink = Kit::driver()->publish(Kit::post(caption: 'No links here', options: new LinkedInOptions(autoLinkCard: true)));
    $onlyLinkedIn = Kit::driver()->publish(Kit::post(caption: 'https://lnkd.in/abc and https://www.linkedin.com/feed', options: new LinkedInOptions(autoLinkCard: true)));
    $poll = Kit::driver()->publish(Kit::post(caption: 'https://example.com', options: new LinkedInOptions(autoLinkCard: true, poll: LinkedInPoll::make('Q?', ['A', 'B']))));

    expect($noLink->state['content'])->toBeNull()
        ->and($onlyLinkedIn->state['content'])->toBeNull()
        ->and($poll->state['content'])->toHaveKey('poll');

    Http::assertNothingSent();
});

// --- Mentions resolved from URLs ------------------------------------------------------

it('turns linkedin company and profile links into mentions with autoMentionUrls', function () {
    Http::fake([
        'api.linkedin.com/v2/organizations*' => Kit::fixture('organization_by_vanity'),
        'api.linkedin.com/rest/vanityUrl*' => Kit::fixture('vanity_url_member'),
        'api.linkedin.com/rest/people/*' => Kit::fixture('people_jane'),
    ]);

    $options = new LinkedInOptions(mentions: [LinkedInMention::make('urn:li:person:Bob1', 'Bob')], autoMentionUrls: true);
    $post = Kit::post(
        caption: 'Hi {{mention:0}}, https://www.linkedin.com/company/acme/ and linkedin.com/in/jane-doe! (again https://www.linkedin.com/company/acme/)',
        options: $options,
        credentials: Kit::credentials(['organization' => Kit::ORG]),
    );

    $pending = Kit::driver()->publish($post);

    expect($pending->state['commentary'])->toBe('Hi @[Bob](urn:li:person:Bob1), @[Acme Inc](urn:li:organization:2414183) and @[Jane Doe](urn:li:person:Jd0eXyZ12)! \\(again @[Acme Inc](urn:li:organization:2414183)\\)');

    // One lookup per distinct link.
    expect(Kit::sent('v2/organizations'))->toBe(1);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'v2/organizations')
        && $r['q'] === 'vanityName' && $r['vanityName'] === 'acme'
        && $r->hasHeader('Authorization', 'Bearer '.Kit::TOKEN));
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'rest/vanityUrl')
        && $r['q'] === 'vanityUrlAsOrganization'
        && $r['vanityUrl'] === 'https://www.linkedin.com/in/jane-doe'
        && $r['organization'] === Kit::ORG);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'rest/people/(id:Jd0eXyZ12)'));
});

it('keeps links it cannot resolve as escaped text', function () {
    Http::fake(['api.linkedin.com/v2/organizations*' => Http::response(Kit::json('error_500'), 500)]);

    // A person author without an organization cannot resolve profiles at all.
    $pending = Kit::driver()->publish(Kit::post(
        caption: 'Hi https://www.linkedin.com/in/jane_doe and https://www.linkedin.com/company/acme',
        options: new LinkedInOptions(autoMentionUrls: true),
    ));

    expect($pending->state['commentary'])->toBe('Hi https://www.linkedin.com/in/jane\\_doe and https://www.linkedin.com/company/acme');
    expect(Kit::sent('rest/vanityUrl'))->toBe(0);
});

it('leaves hand-built little text alone even with autoMentionUrls', function () {
    Http::fake();

    $pending = Kit::driver()->publish(Kit::post(
        caption: 'Hi https://www.linkedin.com/company/acme',
        options: new LinkedInOptions(escapeText: false, autoMentionUrls: true),
    ));

    expect($pending->state['commentary'])->toBe('Hi https://www.linkedin.com/company/acme');
    Http::assertNothingSent();
});

// --- Images -------------------------------------------------------------------------

it('uploads a single image with alt text before the create step', function () {
    Http::fake([
        'api.linkedin.com/rest/images?action=initializeUpload' => Kit::fixture('image_init'),
        'www.linkedin.com/dms-uploads/*' => Http::response('', 201),
        'api.linkedin.com/rest/posts' => Kit::created(),
    ]);

    $image = Kit::image(' A cat on a keyboard ');
    $post = Kit::post([$image]);
    $pending = Kit::driver()->publish($post);

    expect($pending->state['content'])->toBe(['media' => ['id' => Kit::IMAGE, 'altText' => 'A cat on a keyboard']]);

    $init = liBody('images?action=initializeUpload');
    expect($init)->toBe(['initializeUploadRequest' => ['owner' => Kit::PERSON]]);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'dms-uploads')
        && $r->method() === 'PUT'
        && $r->body() === file_get_contents($image->source)
        && $r->hasHeader('Authorization', 'Bearer '.Kit::TOKEN)
        && $r->hasHeader('Content-Type', 'application/octet-stream'));

    liCreate($pending, $post);
    expect(liBody('rest/posts')['content'])->toBe(['media' => ['id' => Kit::IMAGE, 'altText' => 'A cat on a keyboard']]);
});

it('uploads a multi-image post with per-image alt text', function () {
    Http::fake([
        'api.linkedin.com/rest/images?action=initializeUpload' => Http::sequence()
            ->push(['value' => ['uploadUrl' => 'https://www.linkedin.com/dms-uploads/sp/1', 'image' => 'urn:li:image:A1']])
            ->push(['value' => ['uploadUrl' => 'https://www.linkedin.com/dms-uploads/sp/2', 'image' => 'urn:li:image:A2']])
            ->push(['value' => ['uploadUrl' => 'https://www.linkedin.com/dms-uploads/sp/3', 'image' => 'urn:li:image:A3']]),
        'www.linkedin.com/dms-uploads/*' => Http::response('', 201),
    ]);

    $pending = Kit::driver()->publish(Kit::post([Kit::image('One'), Kit::image(), Kit::image('Three')]));

    expect($pending->state['content'])->toBe(['multiImage' => ['images' => [
        ['id' => 'urn:li:image:A1', 'altText' => 'One'],
        ['id' => 'urn:li:image:A2'],
        ['id' => 'urn:li:image:A3', 'altText' => 'Three'],
    ]]]);
});

it('fails image uploads with clear errors', function (array $fakes, string $class, FailureReason $reason, string $message) {
    Http::fake($fakes);

    $e = Kit::catch(fn () => Kit::driver()->publish(Kit::post([Kit::image()])));

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason)
        ->and($e->getMessage())->toContain($message);
})->with([
    'init revoked token' => [fn () => ['api.linkedin.com/rest/images*' => Http::response(Kit::json('error_401_revoked'), 401)], PermanentException::class, FailureReason::InvalidToken, 'revoked'],
    'init without upload url' => [fn () => ['api.linkedin.com/rest/images*' => Http::response(Kit::json('image_init_no_url'))], TemporaryException::class, FailureReason::ServerError, 'image upload URL'],
    'put refused' => [fn () => ['api.linkedin.com/rest/images*' => Http::response(Kit::json('image_init')), 'www.linkedin.com/dms-uploads/*' => Http::response('', 500)], TemporaryException::class, FailureReason::ServerError, 'Failed to upload an image to LinkedIn (HTTP 500)'],
    'put expired' => [fn () => ['api.linkedin.com/rest/images*' => Http::response(Kit::json('image_init')), 'www.linkedin.com/dms-uploads/*' => Http::response('', 401)], TemporaryException::class, FailureReason::ServerError, 'HTTP 401'],
    'put too large' => [fn () => ['api.linkedin.com/rest/images*' => Http::response(Kit::json('image_init')), 'www.linkedin.com/dms-uploads/*' => Http::response('', 413)], PermanentException::class, FailureReason::MediaRejected, 'refused the upload of an image (HTTP 413)'],
]);

// --- Video --------------------------------------------------------------------------

it('uploads a video in parts with its thumbnail, waits for AVAILABLE, then creates the post', function () {
    liVideoFakes(['video_status_processing', 'video_status_waiting', 'video_status_available']);

    $video = liVideo(Kit::file(3000, 'jpg'), 'A demo');
    $post = Kit::post([$video], title: 'My video');
    $driver = Kit::driver();

    $pending = $driver->publish($post);

    expect($pending)->toBeInstanceOf(Pending::class)
        ->and($pending->commits)->toBeFalse()
        ->and($pending->recheckAfter)->toBe(10)
        ->and($pending->deadline)->toBeGreaterThan(time() + 3000)
        ->and($pending->state['phase'])->toBe('await_video')
        ->and($pending->state['video'])->toBe(Kit::VIDEO)
        ->and($pending->state)->not->toHaveKey('instructions');

    expect(liBody('videos?action=initializeUpload'))->toBe(['initializeUploadRequest' => [
        'owner' => Kit::PERSON,
        'fileSizeBytes' => 200000,
        'uploadCaptions' => false,
        'uploadThumbnail' => true,
    ]]);

    $bytes = file_get_contents($video->source);
    $parts = Http::recorded(fn (Request $r) => str_contains($r->url(), 'uploadedVideo'))->map(fn ($pair) => $pair[0]->body())->values()->all();
    expect($parts)->toBe([substr($bytes, 0, 80000), substr($bytes, 80000, 80000), substr($bytes, 160000)]);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'uploadedThumbnail')
        && $r->method() === 'PUT'
        && $r->hasHeader('media-type-family', 'STILLIMAGE'));

    $order = Http::recorded()->map(fn ($pair) => $pair[0]->url())->values()->all();
    $thumbnailAt = array_key_first(array_filter($order, fn ($url) => str_contains($url, 'uploadedThumbnail')));
    $finalizeAt = array_key_first(array_filter($order, fn ($url) => str_contains($url, 'finalizeUpload')));
    expect($thumbnailAt)->toBeLessThan($finalizeAt);

    expect(liBody('videos?action=finalizeUpload'))->toBe(['finalizeUploadRequest' => [
        'video' => Kit::VIDEO,
        'uploadToken' => 'upload-token-abc',
        'uploadedPartIds' => ['/ambry-video/etag-0', '/ambry-video/etag-1', '/ambry-video/etag-2'],
    ]]);

    $processing = $driver->resume($post, $pending->state);
    expect($processing)->toBeInstanceOf(Pending::class)
        ->and($processing->recheckAfter)->toBe(15)
        ->and($processing->deadline)->toBe($pending->state['deadline']);

    $waiting = $driver->resume($post, $processing->state);
    expect($waiting->state['waiting_since'])->toBeGreaterThanOrEqual(time() - 1);

    $create = $driver->resume($post, $waiting->state);
    expect($create->state['content'])->toBe(['media' => ['id' => Kit::VIDEO, 'title' => 'My video', 'altText' => 'A demo']])
        ->and($create->state['commentary'])->toBe('Hello LinkedIn');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.linkedin.com/rest/videos/'.rawurlencode(Kit::VIDEO) && $r->method() === 'GET');

    $published = liCreate($create, $post);
    expect($published->result->platformPostId)->toBe(Kit::SHARE);
});

it('stages a long video upload across runs, keeping ETags and the next part in state', function () {
    liVideoFakes();

    $post = Kit::post([liVideo()]);
    $driver = Kit::driver();

    $driver->partBudget = 1;
    $first = $driver->publish($post);

    expect($first->state['phase'])->toBe('upload_video')
        ->and($first->recheckAfter)->toBe(0)
        ->and($first->state['next'])->toBe(1)
        ->and($first->state['etags'])->toBe(['/ambry-video/etag-0'])
        ->and($first->state['size'])->toBe(200000)
        ->and($first->state['instructions'])->toHaveCount(3)
        ->and($first->state['upload_token'])->toBe('upload-token-abc');

    $driver->partBudget = 1;
    $second = $driver->resume($post, $first->state);
    expect($second->state['next'])->toBe(2);

    $driver->partBudget = 1;
    $third = $driver->resume($post, $second->state);
    expect($third->state['phase'])->toBe('await_video');

    expect(Kit::sent('uploadedVideo', 'PUT'))->toBe(3)
        ->and(liBody('finalizeUpload')['finalizeUploadRequest']['uploadedPartIds'])->toHaveCount(3);
});

it('refuses to continue a staged upload when the file changed size', function () {
    Http::fake();

    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post([liVideo()]), [
        'phase' => 'upload_video', 'video' => Kit::VIDEO, 'size' => 999999, 'instructions' => [], 'etags' => [], 'next' => 0,
    ]));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain('changed during the LinkedIn upload');
});

it('keeps the parts already sent when a later part fails, and throws when nothing progressed', function () {
    $fail = true;

    liVideoFakes(overrides: [
        'www.linkedin.com/dms-uploads/*' => function (Request $r) use (&$fail) {
            $part = substr($r->url(), -1);

            return $part === '1' && $fail
                ? Http::response('busy', 503)
                : Http::response('', 200, ['ETag' => 'etag-'.$part]);
        },
    ]);

    $post = Kit::post([liVideo()]);
    $driver = Kit::driver();
    $pending = $driver->publish($post);

    expect($pending->state['phase'])->toBe('upload_video')
        ->and($pending->recheckAfter)->toBe(15)
        ->and($pending->state['next'])->toBe(1)
        ->and($pending->state['etags'])->toBe(['etag-0']);

    $e = Kit::catch(fn () => $driver->resume($post, $pending->state));
    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->getMessage())->toContain('part 1, HTTP 503')
        ->and($e->context['part'])->toBe(1);

    $fail = false;
    $done = $driver->resume($post, $pending->state);
    expect($done->state['phase'])->toBe('await_video')
        ->and(liBody('finalizeUpload')['finalizeUploadRequest']['uploadedPartIds'])->toBe(['etag-0', 'etag-1', 'etag-2']);
});

it('treats a part without an ETag as temporary', function () {
    liVideoFakes(overrides: ['www.linkedin.com/dms-uploads/*' => Http::response('', 200)]);

    $e = Kit::catch(fn () => Kit::driver()->publish(Kit::post([liVideo()])));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->getMessage())->toContain('returned no ETag');
});

it('restarts the upload when the part URLs expired, and retries a 401 otherwise', function (int $expiresAt, bool $restart) {
    Http::fake(['www.linkedin.com/dms-uploads/*' => Http::response('', 401)]);

    $video = liVideo();
    $instructions = Kit::json('video_init')['value']['uploadInstructions'];

    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post([$video]), [
        'phase' => 'upload_video', 'video' => Kit::VIDEO, 'size' => 200000, 'instructions' => $instructions,
        'etags' => [], 'next' => 0, 'upload_expires_at' => $expiresAt, 'upload_token' => '',
    ]));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and(($e->context['restart'] ?? false) === true)->toBe($restart);

    if ($restart) {
        expect($e->retryAfter)->toBe(0);
    }
})->with([
    'expired (ms)' => [1_000_000_000_000, true],
    'still valid' => [4_102_444_800_000, false],
]);

it('uploads parts concurrently with upload_concurrency, keeping their order', function () {
    config(['social.api.linkedin.upload_concurrency' => 3]);
    liVideoFakes();

    $pending = Kit::driver()->publish(Kit::post([liVideo()]));

    expect($pending->state['phase'])->toBe('await_video')
        ->and(Kit::sent('uploadedVideo', 'PUT'))->toBe(3)
        ->and(liBody('finalizeUpload')['finalizeUploadRequest']['uploadedPartIds'])
        ->toBe(['/ambry-video/etag-0', '/ambry-video/etag-1', '/ambry-video/etag-2']);
});

it('reports a failed concurrent part as temporary', function () {
    config(['social.api.linkedin.upload_concurrency' => 2]);
    liVideoFakes(overrides: [
        'www.linkedin.com/dms-uploads/*' => fn (Request $r) => str_ends_with($r->url(), '1')
            ? Http::response('', 500)
            : Http::response('', 200, ['ETag' => 'e']),
    ]);

    $e = Kit::catch(fn () => Kit::driver()->publish(Kit::post([liVideo()])));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->context['part'])->toBe(1);
});

it('keeps the uploaded parts when the finalize answer is lost, then checks the status first', function () {
    liVideoFakes(['video_status_processing'], [
        'api.linkedin.com/rest/videos?action=finalizeUpload' => Kit::connectionFailure('cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received'),
    ]);

    $post = Kit::post([liVideo()]);
    $driver = Kit::driver();
    $pending = $driver->publish($post);

    expect($pending->state['phase'])->toBe('finalize_video')
        ->and($pending->recheckAfter)->toBe(15)
        ->and($pending->state['etags'])->toHaveCount(3);

    $await = $driver->resume($post, $pending->state);

    expect($await->state['phase'])->toBe('await_video')
        ->and(Kit::sent('finalizeUpload'))->toBe(1)
        ->and(Kit::sent('uploadedVideo'))->toBe(3);
});

it('finalizes again when the status shows the first finalize never landed', function () {
    Http::fake([
        'api.linkedin.com/rest/videos?action=finalizeUpload' => Http::response('', 200),
        'api.linkedin.com/rest/videos/*' => Kit::fixture('video_status_waiting'),
    ]);

    $await = Kit::driver()->resume(Kit::post([liVideo()]), [
        'phase' => 'finalize_video', 'video' => Kit::VIDEO, 'upload_token' => 't', 'etags' => ['a', 'b'], 'commentary' => 'Hi',
    ]);

    expect($await->state['phase'])->toBe('await_video')
        ->and($await->state['commentary'])->toBe('Hi')
        ->and(liBody('finalizeUpload'))->toBe(['finalizeUploadRequest' => ['video' => Kit::VIDEO, 'uploadToken' => 't', 'uploadedPartIds' => ['a', 'b']]]);
});

it('fails the finalize phase when the video processing failed', function () {
    Http::fake(['api.linkedin.com/rest/videos/*' => Kit::fixture('video_status_failed')]);

    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post([liVideo()]), ['phase' => 'finalize_video', 'video' => Kit::VIDEO]));

    expect($e->reason)->toBe(FailureReason::MediaRejected);
});

it('retries a finalize that never left', function () {
    liVideoFakes(['video_status_processing'], [
        'api.linkedin.com/rest/videos?action=finalizeUpload' => Kit::connectionFailure('cURL error 7: Failed to connect to api.linkedin.com port 443'),
    ]);

    $neverSent = Kit::catch(fn () => Kit::driver()->publish(Kit::post([liVideo()])));

    expect($neverSent)->toBeInstanceOf(TemporaryException::class)
        ->and($neverSent->context['request_sent'])->toBeFalse();
});

it('settles a refused finalize with the video status', function (string $status, ?string $error) {
    liVideoFakes([$status], [
        'api.linkedin.com/rest/videos?action=finalizeUpload' => Http::response(Kit::json('error_400_bad_request'), 400),
    ]);

    $run = fn () => Kit::driver()->publish(Kit::post([liVideo()]));

    if ($error === null) {
        expect($run()->state['phase'])->toBe('await_video');

        return;
    }

    $e = Kit::catch($run);
    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->getMessage())->toContain($error);
})->with([
    'already finalized (available)' => ['video_status_available', null],
    'already finalized (processing)' => ['video_status_processing', null],
    'really refused' => ['video_status_waiting', 'Invalid visibility for author type'],
]);

it('keeps going when the video thumbnail upload fails', function () {
    liVideoFakes(overrides: ['www.linkedin.com/dms-uploads/sp/C5505AQH/uploadedThumbnail*' => Http::response('', 500)]);

    $pending = Kit::driver()->publish(Kit::post([liVideo(Kit::file(100, 'jpg'))]));

    expect($pending->state['phase'])->toBe('await_video');
});

it('skips the thumbnail when upload_thumbnail is off', function () {
    config(['social.api.linkedin.upload_thumbnail' => false]);
    liVideoFakes();

    Kit::driver()->publish(Kit::post([liVideo(Kit::file(100, 'jpg'))]));

    expect(liBody('videos?action=initializeUpload')['initializeUploadRequest']['uploadThumbnail'])->toBeFalse()
        ->and(Kit::sent('uploadedThumbnail'))->toBe(0);
});

it('refuses videos outside 75 KB to 500 MB before initializing an upload', function () {
    Http::fake();

    $e = Kit::catch(fn () => Kit::driver()->publish(Kit::post([Media::video(Kit::file(70000, 'mp4'))])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->context['size'])->toBe(70000);

    Http::assertNothingSent();
});

it('treats a broken video init as temporary', function (array $value, string $message) {
    Http::fake(['api.linkedin.com/rest/videos?action=initializeUpload' => Http::response(['value' => $value])]);

    $e = Kit::catch(fn () => Kit::driver()->publish(Kit::post([liVideo()])));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->getMessage())->toContain($message);
})->with([
    'no video urn' => [['uploadInstructions' => [['uploadUrl' => 'https://x', 'firstByte' => 0, 'lastByte' => 199999]]], 'video upload'],
    'no instructions' => [['video' => 'urn:li:video:X'], 'no usable upload instructions'],
    'instruction past the file' => [['video' => 'urn:li:video:X', 'uploadInstructions' => [['uploadUrl' => 'https://x', 'firstByte' => 0, 'lastByte' => 300000]]], 'no usable upload instructions'],
]);

it('maps a refused video init with a permission hint for page authors', function () {
    Http::fake(['api.linkedin.com/rest/videos?action=initializeUpload' => Http::response(Kit::json('error_403_access_denied'), 403)]);

    $e = Kit::catch(fn () => Kit::driver()->publish(Kit::post([liVideo()], credentials: Kit::credentials(['author' => Kit::ORG]))));

    expect($e->reason)->toBe(FailureReason::InsufficientPermissions)
        ->and($e->getMessage())->toContain('w_organization_social')
        ->and($e->getMessage())->toContain('CONTENT_ADMIN')
        ->and($e->context['operation'])->toBe('upload');
});

it('handles every video processing status', function (array|string $status, array $state, string $expect) {
    Http::fake(['api.linkedin.com/rest/videos/*' => is_string($status) ? Kit::fixture($status) : Http::response($status)]);

    $run = fn () => Kit::driver()->resume(Kit::post([liVideo()]), $state + ['phase' => 'await_video', 'video' => Kit::VIDEO]);

    match ($expect) {
        'pending' => expect($run())->toBeInstanceOf(Pending::class),
        'rejected' => expect(Kit::catch($run))->toBeInstanceOf(PermanentException::class)
            ->and(Kit::catch($run)->reason)->toBe(FailureReason::MediaRejected),
        'timeout' => expect(Kit::catch($run)->reason)->toBe(FailureReason::ProcessingTimeout),
        'temporary' => expect(Kit::catch($run))->toBeInstanceOf(TemporaryException::class)
            ->and(Kit::catch($run)->context['missing_field'])->toBe('status'),
    };
})->with([
    'processing' => ['video_status_processing', [], 'pending'],
    'unknown status keeps waiting' => [['status' => 'QUEUED'], [], 'pending'],
    'waiting briefly' => ['video_status_waiting', ['waiting_since' => time() - 60], 'pending'],
    'waiting too long' => ['video_status_waiting', ['waiting_since' => time() - 400], 'rejected'],
    'processing failed' => ['video_status_failed', [], 'rejected'],
    'no status' => [['id' => 'urn:li:video:C5505AQH-oV1qvnFtKA'], [], 'temporary'],
    'deadline passed' => ['video_status_processing', ['deadline' => time() - 5], 'timeout'],
]);

it('names the processing failure reason', function () {
    Http::fake(['api.linkedin.com/rest/videos/*' => Kit::fixture('video_status_failed')]);

    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post([liVideo()]), ['phase' => 'await_video', 'video' => Kit::VIDEO]));

    expect($e->getMessage())->toBe('LinkedIn could not process the video (Video encoding failed: unsupported codec).')
        ->and($e->context['processingFailureReason'])->toBe('Video encoding failed: unsupported codec');
});

it('maps a failed status read', function () {
    Http::fake(['api.linkedin.com/rest/videos/*' => Http::response(Kit::json('error_404_video'), 404)]);

    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post([liVideo()]), ['phase' => 'await_video', 'video' => Kit::VIDEO]));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::NotFound);
});

it('resumes a v1.0 await_video continuation', function () {
    Http::fake([
        'api.linkedin.com/rest/videos/*' => Kit::fixture('video_status_available'),
        'api.linkedin.com/rest/posts' => Kit::created(),
    ]);

    $post = Kit::post([liVideo()], caption: 'Old (queued) video');
    $create = Kit::driver()->resume($post, ['phase' => 'await_video', 'video' => Kit::VIDEO]);

    expect($create->commits)->toBeTrue()
        ->and($create->state['commentary'])->toBe('Old \\(queued\\) video')
        ->and($create->state['content'])->toBe(['media' => ['id' => Kit::VIDEO]]);

    liCreate($create, $post);
});

it('resumes the v1.0 phases for a pending deadline when none was stored', function () {
    Http::fake(['api.linkedin.com/rest/videos/*' => Kit::fixture('video_status_processing')]);

    $pending = Kit::driver()->resume(Kit::post([liVideo()]), ['phase' => 'await_video', 'video' => Kit::VIDEO]);

    expect($pending->deadline)->toBeGreaterThan(time() + 3000)
        ->and($pending->state['deadline'])->toBe($pending->deadline);
});

// --- Documents ------------------------------------------------------------------------

it('uploads a document, waits for AVAILABLE and creates the post titled after the file', function () {
    Http::fake([
        'api.linkedin.com/rest/documents?action=initializeUpload' => Kit::fixture('document_init'),
        'www.linkedin.com/dms-uploads/*' => Http::response('', 201),
        'api.linkedin.com/rest/documents/*' => Http::sequence()
            ->push(Kit::json('document_status_processing'))
            ->push(Kit::json('document_status_available')),
        'api.linkedin.com/rest/posts' => Kit::created(),
    ]);

    $dir = sys_get_temp_dir().'/sp-li-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $path = $dir.'/Quarterly Report.pdf';
    file_put_contents($path, str_repeat('%PDF', 1000));

    $post = Kit::post([Media::document($path)]);
    $driver = Kit::driver();
    $pending = $driver->publish($post);

    expect($pending->recheckAfter)->toBe(20)
        ->and($pending->deadline)->toBeGreaterThan(time())
        ->and($pending->state)->toMatchArray(['phase' => 'await_document', 'document' => Kit::DOCUMENT, 'title' => 'Quarterly Report']);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'dms-uploads') && $r->method() === 'PUT' && $r->body() === str_repeat('%PDF', 1000));

    $processing = $driver->resume($post, $pending->state);
    expect($processing)->toBeInstanceOf(Pending::class)->and($processing->recheckAfter)->toBe(20);

    $create = $driver->resume($post, $processing->state);
    expect($create->state['content'])->toBe(['media' => ['title' => 'Quarterly Report', 'id' => Kit::DOCUMENT]]);

    liCreate($create, $post);

    @unlink($path);
    @rmdir($dir);
});

it('titles a document after the post when it has a title', function () {
    Http::fake([
        'api.linkedin.com/rest/documents?action=initializeUpload' => Kit::fixture('document_init'),
        'www.linkedin.com/dms-uploads/*' => Http::response('', 201),
    ]);

    $pending = Kit::driver()->publish(Kit::post([Media::document(Kit::file(500, 'pdf'))], title: 'Our deck'));

    expect($pending->state['title'])->toBe('Our deck');
});

it('resumes a v1.0 await_document continuation', function () {
    Http::fake([
        'api.linkedin.com/rest/documents/*' => Kit::fixture('document_status_available'),
        'api.linkedin.com/rest/posts' => Kit::created(),
    ]);

    $post = Kit::post([Media::document(Kit::file(500, 'pdf'))]);
    $create = Kit::driver()->resume($post, ['phase' => 'await_document', 'document' => Kit::DOCUMENT, 'title' => 'Document']);

    expect($create->state['content'])->toBe(['media' => ['title' => 'Document', 'id' => Kit::DOCUMENT]]);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.linkedin.com/rest/documents/'.rawurlencode(Kit::DOCUMENT));

    liCreate($create, $post);
});

it('fails documents LinkedIn cannot process or never receives', function (string $fixture, array $state, FailureReason $reason) {
    Http::fake(['api.linkedin.com/rest/documents/*' => Kit::fixture($fixture)]);

    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post([Media::document(Kit::file(500, 'pdf'))]), $state + ['phase' => 'await_document', 'document' => Kit::DOCUMENT, 'title' => 'D']));

    expect($e->reason)->toBe($reason);
})->with([
    'processing failed' => ['document_status_failed', [], FailureReason::MediaRejected],
    'waiting too long' => ['document_status_waiting', ['waiting_since' => time() - 301], FailureReason::MediaRejected],
    'deadline' => ['document_status_processing', ['deadline' => time() - 1], FailureReason::ProcessingTimeout],
]);

it('keeps polling a document waiting for its upload briefly', function () {
    Http::fake(['api.linkedin.com/rest/documents/*' => Kit::fixture('document_status_waiting')]);

    $pending = Kit::driver()->resume(Kit::post([Media::document(Kit::file(500, 'pdf'))]), ['phase' => 'await_document', 'document' => Kit::DOCUMENT]);

    expect($pending)->toBeInstanceOf(Pending::class)
        ->and($pending->state['waiting_since'])->toBeGreaterThanOrEqual(time() - 1);
});

it('maps document init and status failures', function () {
    Http::fake([
        'api.linkedin.com/rest/documents?action=initializeUpload' => Http::response(Kit::json('error_401_expired'), 401),
        'api.linkedin.com/rest/documents/*' => Http::response(Kit::json('error_500'), 500),
    ]);

    $init = Kit::catch(fn () => Kit::driver()->publish(Kit::post([Media::document(Kit::file(500, 'pdf'))])));
    $status = Kit::catch(fn () => Kit::driver()->resume(Kit::post(), ['phase' => 'await_document', 'document' => Kit::DOCUMENT]));

    expect($init->reason)->toBe(FailureReason::InvalidToken)
        ->and($init->context['token_expired'])->toBeTrue()
        ->and($status)->toBeInstanceOf(TemporaryException::class);
});

// --- The create step: ambiguity and recovery -----------------------------------------

it('never re-creates a post after an ambiguous run when posts cannot be read', function () {
    Http::fake();

    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post(), [
        'phase' => 'create_post', 'commentary' => 'Hello LinkedIn', 'content' => null,
        Pending::RECOVERING => true, Pending::COMMITTING_SINCE => time() - 30,
    ]));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->possiblyPublished())->toBeTrue();

    Http::assertNothingSent();
});

it('finds the post an interrupted run created when the token can read posts', function () {
    Http::fake(['api.linkedin.com/rest/posts*' => Http::response(['elements' => [
        ['id' => 'urn:li:share:1', 'commentary' => 'Older post', 'createdAt' => (time() - 50) * 1000],
        ['id' => 'urn:li:share:2', 'commentary' => 'Launch \\(today\\)', 'createdAt' => (time() - 5000) * 1000],
        ['id' => 'urn:li:share:3', 'commentary' => 'Launch \\(today\\)', 'createdAt' => (time() - 20) * 1000, 'content' => ['media' => ['id' => Kit::IMAGE]]],
    ]])]);

    $post = Kit::post(caption: 'Launch (today)', credentials: Kit::credentials(['scopes' => 'w_member_social r_member_social']));

    $outcome = Kit::driver()->resume($post, [
        'phase' => 'create_post', 'commentary' => 'Launch \\(today\\)', 'content' => ['media' => ['id' => Kit::IMAGE]],
        Pending::RECOVERING => true, Pending::COMMITTING_SINCE => time() - 60,
    ]);

    expect($outcome)->toBeInstanceOf(Published::class)
        ->and($outcome->result->platformPostId)->toBe('urn:li:share:3')
        ->and($outcome->result->payload['recovered'])->toBeTrue();

    expect(Kit::sent('rest/posts', 'POST'))->toBe(0);
    Http::assertSent(fn (Request $r) => $r->method() === 'GET'
        && $r['q'] === 'author'
        && $r['author'] === Kit::PERSON
        && $r->hasHeader('X-RestLi-Method', 'FINDER'));
});

it('creates the post after a recovery check that finds nothing', function () {
    config(['social.api.linkedin.verify_reads' => true]);
    Http::fake(['api.linkedin.com/rest/posts*' => fn (Request $r) => $r->method() === 'GET'
        ? Http::response(['elements' => [['id' => 'urn:li:share:9', 'commentary' => 'Something else', 'createdAt' => time() * 1000]]])
        : Kit::created()]);

    $outcome = Kit::driver()->resume(Kit::post(), [
        'phase' => 'create_post', 'commentary' => 'Hello LinkedIn', 'content' => null, Pending::RECOVERING => true,
    ]);

    expect($outcome->result->platformPostId)->toBe(Kit::SHARE)
        ->and(Kit::sent('rest/posts', 'POST'))->toBe(1);
});

it('reports an unknown outcome when the recovery read is refused', function () {
    config(['social.api.linkedin.verify_reads' => true]);
    Http::fake(['api.linkedin.com/rest/posts*' => Http::response(Kit::json('error_403_access_denied'), 403)]);

    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post(), [
        'phase' => 'create_post', 'commentary' => 'Hello LinkedIn', 'content' => null, Pending::RECOVERING => true,
    ]));

    expect($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->context['verify_status'])->toBe(403)
        ->and(Kit::sent('rest/posts', 'POST'))->toBe(0);
});

it('retries a create whose request never left', function () {
    Http::fake(['api.linkedin.com/rest/posts' => Kit::connectionFailure('cURL error 6: Could not resolve host: api.linkedin.com', 6)]);

    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post(), ['phase' => 'create_post', 'commentary' => 'Hi', 'content' => null]));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::Network)
        ->and($e->context['request_sent'])->toBeFalse()
        ->and($e->possiblyPublished())->toBeFalse();
});

it('fails as possibly published when the create response is lost', function () {
    Http::fake(['api.linkedin.com/rest/posts' => Kit::connectionFailure('cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received')]);

    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post(), ['phase' => 'create_post', 'commentary' => 'Hi', 'content' => null]));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->possiblyPublished())->toBeTrue()
        ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
});

it('verifies instead of failing a lost create when posts can be read', function () {
    config(['social.api.linkedin.verify_reads' => true]);

    Http::fake(['api.linkedin.com/rest/posts' => Kit::connectionFailure('cURL error 56: Recv failure: Connection reset by peer')]);

    $post = Kit::post();
    $verify = Kit::driver()->resume($post, ['phase' => 'create_post', 'commentary' => 'Hello LinkedIn', 'content' => null, 'since' => time() - 5, Pending::ATTEMPT => 1]);

    expect($verify)->toBeInstanceOf(Pending::class)
        ->and($verify->commits)->toBeTrue()
        ->and($verify->recheckAfter)->toBe(30)
        ->and($verify->state['verify'])->toBeTrue()
        ->and($verify->state)->not->toHaveKey(Pending::ATTEMPT);

    Http::fake(['api.linkedin.com/rest/posts*' => Http::response(['elements' => [['id' => Kit::SHARE, 'commentary' => 'Hello LinkedIn', 'createdAt' => time() * 1000]]])]);

    $published = Kit::driver()->resume($post, $verify->state);
    expect($published->result->platformPostId)->toBe(Kit::SHARE);
});

it('refuses an unknown phase', function () {
    $e = Kit::catch(fn () => Kit::driver()->resume(Kit::post(), ['phase' => 'publish_retry']));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->getMessage())->toBe("Unknown LinkedIn publish phase 'publish_retry'.");
});

// --- Credentials ----------------------------------------------------------------------

it('fails fast without the credentials it needs', function (array $credentials, string $message) {
    Http::fake();

    $e = Kit::catch(fn () => Kit::driver()->publish(Kit::post(credentials: $credentials)));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Misconfigured)
        ->and($e->getMessage())->toContain($message);

    Http::assertNothingSent();
})->with([
    'no token' => [['author' => Kit::PERSON], 'access_token'],
    'no author' => [['access_token' => 't'], 'author'],
    'malformed author' => [['access_token' => 't', 'author' => 'urn:li:person:abc) x'], 'must be urn:li:person'],
    'company urn' => [['access_token' => 't', 'author' => 'urn:li:company:1'], 'must be urn:li:person'],
]);

it('checks credentials when resuming and commenting too', function () {
    $post = Kit::post(credentials: ['access_token' => 't']);

    expect(Kit::catch(fn () => Kit::driver()->resume($post, ['phase' => 'create_post']))->reason)->toBe(FailureReason::Misconfigured)
        ->and(Kit::catch(fn () => Kit::driver()->addFirstComment($post, PostResult::success(Platform::LinkedIn, Kit::SHARE), 'x'))->reason)->toBe(FailureReason::Misconfigured);
});

it('refuses to create with a token about to expire', function () {
    $e = Kit::catch(fn () => Kit::driver()->publish(Kit::post(credentials: Kit::credentials(['expires_at' => time() + 10]))));

    expect($e->reason)->toBe(FailureReason::InvalidToken)
        ->and($e->context['token_expired'])->toBeTrue();
});

it('identifies the account by its author urn', function () {
    $driver = Kit::driver();

    expect($driver->accountKey(new Credentials(Kit::credentials())))->toBe(Kit::PERSON)
        ->and($driver->accountKey(new Credentials(['access_token' => 'abc'])))->toBe('');
});
