<?php

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Contracts\MediaTransformer;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\Bluesky\BlueskyDriver;
use SocialPoster\Platforms\Bluesky\BlueskyOptions;
use SocialPoster\Platforms\Bluesky\DefaultBlueskyAuthorizer;
use SocialPoster\Platforms\Bluesky\Tid;
use SocialPoster\Support\DPoP;
use SocialPoster\Support\PublicUrl;
use SocialPoster\Support\Text\GraphemeCounter;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\RawOptions;

require_once __DIR__.'/../../Fixtures/bluesky/helpers.php';

/** Exposes protected methods to the tests. */
class BskyDriverProbe extends BlueskyDriver
{
    public function call(string $method, mixed ...$args): mixed
    {
        return $this->{$method}(...$args);
    }
}

function bskyResponse(int $status, mixed $body = null, array $headers = []): Response
{
    if (is_array($body)) {
        $body = json_encode($body);
        $headers += ['Content-Type' => 'application/json'];
    }

    return new Response(new PsrResponse($status, $headers, (string) $body));
}

beforeEach(function () {
    Cache::flush();
    config(['social.authorizers' => []]);
});

afterEach(function () {
    bskyCleanup();
});

// --- Capabilities and validation --------------------------------------------------

it('declares graphemes, 3000 bytes, 4 images or one mp4 video', function () {
    $caps = bskyDriver()->capabilities();

    expect($caps->caption->max)->toBe(300)
        ->and($caps->caption->maxBytes)->toBe(3000)
        ->and($caps->caption->counter)->toBeInstanceOf(GraphemeCounter::class)
        ->and($caps->media->maxCount)->toBe(4)
        ->and($caps->media->image->extensions)->toBe(['jpg', 'jpeg', 'png', 'webp'])
        ->and($caps->media->image->maxSizeBytes)->toBe(1_000_000)
        ->and($caps->media->image->convert)->toHaveKey('heic')
        ->and($caps->media->video->extensions)->toBe(['mp4'])
        ->and($caps->media->video->maxSizeBytes)->toBe(100_000_000)
        ->and($caps->media->video->maxDuration)->toBe(180.0)
        ->and($caps->media->document)->toBeNull()
        ->and($caps->supportsCarousel)->toBeFalse();
});

it('reads its media limits from config', function () {
    config(['social.api.bluesky' => ['image_max_bytes' => 2_000_000, 'video_max_bytes' => 300_000_000, 'video_max_duration' => 600]]);

    $caps = bskyDriver()->capabilities();

    expect($caps->media->image->maxSizeBytes)->toBe(2_000_000)
        ->and($caps->media->video->maxSizeBytes)->toBe(300_000_000)
        ->and($caps->media->video->maxDuration)->toBe(600.0);
});

it('counts graphemes and bytes on the trimmed text', function (string $caption, bool $valid) {
    $errors = bskyDriver()->validate(bskyPost($caption));

    expect($errors->has('caption'))->toBe(! $valid);
})->with([
    '300 accented letters' => [str_repeat('é', 300), true],
    '301 accented letters' => [str_repeat('é', 301), false],
    '120 family emoji = 3000 bytes' => [str_repeat('👨‍👩‍👧‍👦', 120), true],
    '121 family emoji = 3025 bytes' => [str_repeat('👨‍👩‍👧‍👦', 121), false],
    '300 flags (600 code points, 2400 bytes)' => [str_repeat('🇫🇷', 300), true],
    '301 flags' => [str_repeat('🇫🇷', 301), false],
    'surrounding whitespace ignored' => ['  '.str_repeat('a', 300).'  ', true],
]);

it('names both limits in its messages', function () {
    $graphemes = bskyDriver()->validate(bskyPost(str_repeat('a', 301)))->get('caption');
    $bytes = bskyDriver()->validate(bskyPost(str_repeat('👨‍👩‍👧‍👦', 121)))->get('caption');

    expect($graphemes[0])->toContain('300 character limit')->toContain('graphemes')
        ->and($bytes[0])->toContain('3000-byte limit');
});

it('measures the shortened text when shortenLinks is on', function () {
    $caption = str_repeat('a', 250).' https://example.com/'.str_repeat('p', 80);

    expect(bskyDriver()->validate(bskyPost($caption))->has('caption'))->toBeTrue()
        ->and(bskyDriver()->validate(bskyPost($caption, [], new BlueskyOptions(shortenLinks: true)))->has('caption'))->toBeFalse();
});

it('requires text or media', function () {
    $image = bskyImage();

    expect(bskyDriver()->validate(bskyPost('   '))->first('caption'))->toBe('Bluesky posts without media should at least contain text.')
        ->and(bskyDriver()->validate(bskyPost(null, [Media::image($image)]))->isEmpty())->toBeTrue()
        ->and(bskyDriver()->validate(bskyPost('', [], new BlueskyOptions(quote: ['uri' => 'at://'.BSKY_DID.'/app.bsky.feed.post/3m5kqzw7yx22c', 'cid' => BSKY_CID])))->isEmpty())->toBeTrue();
});

it('takes one video or up to four images, never both', function () {
    $image = bskyImage();
    $video = bskyFile('mp4', 'mp4');

    $mixed = bskyDriver()->validate(bskyPost('x', [Media::video($video), Media::image($image)]));
    $five = bskyDriver()->validate(bskyPost('x', array_fill(0, 5, Media::image($image))));
    $four = bskyDriver()->validate(bskyPost('x', array_fill(0, 4, Media::image($image))));

    expect($mixed->get('media'))->toContain('A Bluesky post can have one video, or up to 4 images, not both.')
        ->and($five->first('media'))->toContain('maximum is 4')
        ->and($four->isEmpty())->toBeTrue();
});

it('gives GIFs one clear message and refuses documents', function () {
    $errors = bskyDriver()->validate(bskyPost('x', [Media::image(bskyImage('gif'))]));
    $document = bskyDriver()->validate(bskyPost('x', [Media::document(bskyFile('%PDF', 'pdf'))]));

    expect($errors->get('media.0'))->toHaveCount(1)
        ->and($errors->first('media.0'))->toContain("doesn't animate GIF")
        ->and($document->first('media.0'))->toContain('not supported');
});

it('checks image size, alt text and the video rules', function () {
    $image = bskyImage();
    $video = bskyFile('mp4', 'mp4');
    $driver = bskyDriver([
        $image => new MediaMetadata(sizeBytes: 1_200_000, width: 4, height: 3, mimeType: 'image/png'),
        $video => new MediaMetadata(sizeBytes: 10_000, width: 1080, height: 1920, durationSeconds: 240, mimeType: 'video/mp4'),
    ]);

    $big = $driver->validate(bskyPost('x', [Media::image($image, str_repeat('a', 2001))]));
    $long = $driver->validate(bskyPost('x', [Media::video($video)]));

    expect($big->get('media.0'))->toContain('Image exceeds the maximum file size.')
        ->and(implode(' ', $big->get('media.0')))->toContain('alt text is limited to 2000')
        ->and($long->first('media.0'))->toContain('maximum is 180s');
});

it('refuses a link card next to media and captions without a video', function () {
    $errors = bskyDriver()->validate(bskyPost('x', [Media::image(bskyImage())], new BlueskyOptions(
        linkCard: ['uri' => 'https://example.com', 'title' => 'T'],
        videoCaptions: ['en' => '/tmp/en.vtt'],
    )));

    expect($errors->first('linkCard'))->toBe("A link card can't be combined with images or a video.")
        ->and($errors->first('videoCaptions'))->toBe('Video captions need a video in the post.');
});

it('refuses an explicit link card next to a quoted post instead of dropping it', function () {
    $errors = bskyDriver()->validate(bskyPost('x', [], new BlueskyOptions(
        linkCard: ['uri' => 'https://example.com', 'title' => 'T'],
        quote: ['uri' => 'at://did:plc:abc/app.bsky.feed.post/3k', 'cid' => 'bafyreiabc'],
    )));

    expect($errors->first('linkCard'))->toBe("A link card can't be combined with a quoted post.");
});

it('merges the options validation and ignores options for another platform', function () {
    $errors = bskyDriver()->validate(bskyPost('x', [], new BlueskyOptions(langs: ['en', 'fr', 'de', 'es'])));
    $foreign = bskyDriver()->validate(bskyPost('x', [], new RawOptions(Platform::X, ['text' => 'nope'])));

    expect($errors->has('langs'))->toBeTrue()
        ->and($foreign->isEmpty())->toBeTrue();
});

it('flags a DPoP-bound token sent without its key', function () {
    $errors = bskyDriver()->validate(bskyPost('x', [], null, ['access_jwt' => null, 'access_token' => 'tok', 'token_type' => 'DPoP']));

    expect($errors->first('credentials.dpop_private_key'))->toContain('DPoP-bound');
});

it('declares the atproto scope and the did identity', function () {
    $driver = bskyDriver();
    $missing = $driver->validate(bskyPost('x', [], null, ['scopes' => 'transition:generic']));

    expect($driver->scopesFor(Feature::Publish))->toBe([['atproto']])
        ->and($driver->scopesFor(Feature::Comment))->toBe([['atproto']])
        ->and($driver->scopesFor(Feature::Video))->toBe([])
        ->and($missing->has('credentials.scopes'))->toBeTrue()
        ->and($driver->validate(bskyPost('x', [], null, ['scopes' => 'atproto transition:generic']))->isEmpty())->toBeTrue()
        ->and($driver->accountKey(new Credentials(bskyCredentials())))->toBe(BSKY_DID)
        ->and($driver->accountKey(new Credentials(bskyCredentials(['access_jwt' => 'other']))))->toBe(BSKY_DID);
});

// --- Credentials --------------------------------------------------------------------

it('fails fast on missing or malformed credentials', function (array $credentials, string $missing) {
    Http::fake();

    $e = bskyCatch(fn () => bskyDriver()->publish(bskyPost('x', [], null, $credentials)));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Misconfigured)
        ->and($e->context['missing_credentials'])->toBe([$missing]);

    Http::assertNothingSent();
})->with([
    'no did' => [['did' => null], 'did'],
    'a handle instead of a did' => [['did' => 'alice.bsky.social'], 'did'],
    'no token' => [['access_jwt' => null], 'access_token|access_jwt|app_password'],
]);

it('needs only the did when the app binds its own authorizer', function () {
    config(['social.authorizers.bluesky' => BskyTestAuthorizer::class]);
    Http::fake(['*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord()]);

    $result = bskyRun(bskyDriver(), bskyPost('Delegated', [], null, ['access_jwt' => null, 'connection_id' => 42]));

    expect($result->platformPostId)->toStartWith('at://'.BSKY_DID.'/app.bsky.feed.post/');
    Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Delegated 42'] && $r->header('Accept') === ['application/json']);
});

it('signs PDS requests with DPoP when the credentials carry a key, without any config', function () {
    $key = DPoP::generateKey();
    Http::fake(['*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord()]);

    bskyRun(bskyDriver(), bskyPost('DPoP post', [], null, ['access_jwt' => null, 'access_token' => 'oauth-access', 'dpop_private_key' => $key]));

    [$request] = bskySent('createRecord');
    $proof = $request->header('DPoP')[0];
    $claims = json_decode(DPoP::base64urlDecode(explode('.', $proof)[1]), true);

    expect($request->header('Authorization'))->toBe(['DPoP oauth-access'])
        ->and(DPoP::verify($proof))->toBeTrue()
        ->and($claims['htm'])->toBe('POST')
        ->and($claims['htu'])->toBe(BSKY_PDS.'/xrpc/com.atproto.repo.createRecord')
        ->and($claims['ath'])->toBe(DPoP::accessTokenHash('oauth-access'));
});

it('uses the configured default authorizer too', function () {
    config(['social.authorizers.bluesky' => DefaultBlueskyAuthorizer::class]);
    Http::fake(['*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord()]);

    bskyRun(bskyDriver(), bskyPost('x'));

    Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Bearer session-jwt']);
});

// --- Text posts ---------------------------------------------------------------------

it('prepares a text post as a commit phase and creates it with a fixed record key', function () {
    $this->travelTo(Carbon::parse('2026-11-18 10:00:00.250', 'UTC'));
    Http::fake(['*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord()]);
    $driver = bskyDriver();
    $post = bskyPost('  Hello Bluesky  ');

    $pending = $driver->publish($post);

    Http::assertNothingSent();

    expect($pending)->toBeInstanceOf(Pending::class)
        ->and($pending->commits)->toBeTrue()
        ->and($pending->committed)->toBeFalse()
        ->and($pending->recheckAfter)->toBe(0)
        ->and($pending->state['phase'])->toBe('create')
        ->and($pending->state['rkey'])->toMatch(Tid::PATTERN)
        // createdAt is stamped by the create step itself, not kept from here.
        ->and($pending->state)->not->toHaveKey('created_at')
        ->and($pending->state['text'])->toBe('Hello Bluesky')
        ->and($pending->state['facets'])->toBe([])
        ->and(json_decode(json_encode($pending->state), true))->toBe($pending->state);

    $result = bskyPublished($driver->resume($post, $pending->state));
    $rkey = $pending->state['rkey'];

    [$request] = bskySent('createRecord');

    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe(BSKY_PDS.'/xrpc/com.atproto.repo.createRecord')
        ->and($request->header('Authorization'))->toBe(['Bearer session-jwt'])
        ->and($request->data())->toBe([
            'repo' => BSKY_DID,
            'collection' => 'app.bsky.feed.post',
            'rkey' => $rkey,
            'record' => [
                '$type' => 'app.bsky.feed.post',
                'createdAt' => '2026-11-18T10:00:00.250Z',
                'text' => 'Hello Bluesky',
            ],
        ])
        ->and($result->platform)->toBe(Platform::Bluesky)
        ->and($result->platformPostId)->toBe('at://'.BSKY_DID.'/app.bsky.feed.post/'.$rkey)
        ->and($result->url)->toBe('https://bsky.app/profile/'.BSKY_DID.'/post/'.$rkey)
        ->and($result->state)->toBe(PostResult::STATE_PUBLISHED)
        ->and($result->idKind)->toBe('at_uri')
        ->and($result->payload)->toBe([
            'uri' => 'at://'.BSKY_DID.'/app.bsky.feed.post/'.$rkey,
            'cid' => BSKY_CID,
            'did' => BSKY_DID,
            'rkey' => $rkey,
            'validation_status' => 'valid',
        ]);
});

it('stamps createdAt when the record is written, not when the post was prepared', function () {
    Http::fake(['*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord()]);
    $driver = bskyDriver();
    $post = bskyPost('Later');

    $this->travelTo(Carbon::parse('2026-11-18 10:00:00', 'UTC'));
    $pending = $driver->publish($post);

    // Video processing, a queue backlog or create retries can sit in between:
    // feeds sort by createdAt, so an old stamp would bury the post.
    $this->travelTo(Carbon::parse('2026-11-18 10:25:30.125', 'UTC'));
    bskyPublished($driver->resume($post, $pending->state));

    expect(bskySent('createRecord')[0]->data()['record']['createdAt'])->toBe('2026-11-18T10:25:30.125Z');
});

it('uses the configured permalink host', function () {
    config(['social.api.bluesky.permalink_host' => 'https://staging.bsky.app/']);
    Http::fake(['*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord()]);

    $result = bskyRun(bskyDriver(), bskyPost('x'));

    expect($result->url)->toStartWith('https://staging.bsky.app/profile/'.BSKY_DID.'/post/');
});

it('sends facets for links, tags and resolved mentions', function () {
    Http::fake([
        'public.api.bsky.app/*' => Http::response(['did' => 'did:plc:alice000000000000000000']),
        '*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord(),
    ]);

    bskyRun(bskyDriver(), bskyPost('Read https://example.com/a #news by @alice.bsky.social', [], new BlueskyOptions(linkPreview: false)));

    $facets = bskySent('createRecord')[0]->data()['record']['facets'];

    expect($facets)->toBe([
        ['index' => ['byteStart' => 5, 'byteEnd' => 26], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com/a']]],
        ['index' => ['byteStart' => 27, 'byteEnd' => 32], 'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'news']]],
        ['index' => ['byteStart' => 36, 'byteEnd' => 54], 'features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did:plc:alice000000000000000000']]],
    ]);
});

it('maps langs, tags, labels, reply and merges extra without letting it override the record', function () {
    Http::fake(['*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord()]);
    $parent = ['uri' => 'at://did:plc:other/app.bsky.feed.post/3m5kqzw7yx22c', 'cid' => 'bafyparent'];
    $root = ['uri' => 'at://did:plc:other/app.bsky.feed.post/3m5kqaaaaaa2c', 'cid' => 'bafyroot'];

    $result = bskyRun(bskyDriver(), bskyPost('Reply text', [], new BlueskyOptions(
        langs: ['en', 'fr'],
        tags: ['#launch', 'tools', 'launch'],
        selfLabels: ['graphic-media'],
        replyTo: $parent + ['root' => $root],
        extra: [
            'text' => 'overridden?',
            '$type' => 'app.bsky.feed.like',
            'facets' => [['bad']],
            'embed' => ['$type' => 'x'],
            'reply' => ['nope'],
            'createdAt' => '2000-01-01T00:00:00.000Z',
            'langs' => ['de'],
            'via' => 'social-poster',
        ],
    )));

    $record = bskySent('createRecord')[0]->data()['record'];

    expect($record['text'])->toBe('Reply text')
        ->and($record['$type'])->toBe('app.bsky.feed.post')
        ->and($record['createdAt'])->not->toBe('2000-01-01T00:00:00.000Z')
        ->and($record)->not->toHaveKeys(['facets', 'embed'])
        ->and($record['langs'])->toBe(['en', 'fr'])
        ->and($record['tags'])->toBe(['launch', 'tools'])
        ->and($record['labels'])->toBe(['$type' => 'com.atproto.label.defs#selfLabels', 'values' => [['val' => 'graphic-media']]])
        ->and($record['reply'])->toBe(['root' => $root, 'parent' => $parent])
        ->and($record['via'])->toBe('social-poster')
        ->and($result->payload['reply_root'])->toBe($root)
        // An explicit root needs no lookup.
        ->and(bskySent('getRecord'))->toBe([]);
});

it('lets extra fill fields the options leave unset', function () {
    Http::fake(['*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord()]);

    bskyRun(bskyDriver(), bskyPost('x', [], new BlueskyOptions(extra: ['langs' => ['ja']])));

    expect(bskySent('createRecord')[0]->data()['record']['langs'])->toBe(['ja']);
});

it('takes the thread root from the parent when it replies to a reply without a root', function () {
    // A scheduled thread: post 3 replies to post 2, which replies to post 1.
    $root = ['uri' => 'at://'.BSKY_DID.'/app.bsky.feed.post/3m5kqaaaaaa2c', 'cid' => 'bafyroot'];
    $parent = ['uri' => 'at://'.BSKY_DID.'/app.bsky.feed.post/3m5kqzw7yx22c', 'cid' => 'bafyparent'];

    Http::fake([
        '*/xrpc/com.atproto.repo.getRecord*' => Http::response([
            'uri' => $parent['uri'],
            'cid' => $parent['cid'],
            'value' => ['$type' => 'app.bsky.feed.post', 'createdAt' => '2026-11-18T10:00:00.000Z', 'text' => 'Post 2', 'reply' => ['root' => $root, 'parent' => $root]],
        ]),
        '*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord(),
    ]);

    $result = bskyRun(bskyDriver(), bskyPost('Post 3', [], new BlueskyOptions(replyTo: $parent)));
    $lookups = bskySent('getRecord');

    expect(bskySent('createRecord')[0]->data()['record']['reply'])->toBe(['root' => $root, 'parent' => $parent])
        ->and($lookups)->toHaveCount(1)
        ->and($lookups[0]->url())->toBe(BSKY_PDS.'/xrpc/com.atproto.repo.getRecord?repo='.urlencode(BSKY_DID).'&collection=app.bsky.feed.post&rkey=3m5kqzw7yx22c')
        ->and($result->payload['reply_root'])->toBe($root);
});

it('uses the parent as root when the parent is a top-level post', function () {
    $parent = ['uri' => 'at://'.BSKY_DID.'/app.bsky.feed.post/3m5kqzw7yx22c', 'cid' => BSKY_CID];

    Http::fake([
        '*/xrpc/com.atproto.repo.getRecord*' => Http::response(bskyFixture('get_record.json')),
        '*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord(),
    ]);

    bskyRun(bskyDriver(), bskyPost('x', [], new BlueskyOptions(replyTo: $parent)));

    expect(bskySent('createRecord')[0]->data()['record']['reply'])->toBe(['root' => $parent, 'parent' => $parent]);
});

it('refuses to reply to a post that no longer exists', function () {
    Http::fake(['*/xrpc/com.atproto.repo.getRecord*' => Http::response(bskyFixture('record_not_found.json'), 400)]);
    $parent = ['uri' => 'at://'.BSKY_DID.'/app.bsky.feed.post/3m5kqzw7yx22c', 'cid' => BSKY_CID];

    $e = bskyCatch(fn () => bskyDriver()->publish(bskyPost('x', [], new BlueskyOptions(replyTo: $parent))));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::NotFound)
        ->and(bskySent('createRecord'))->toBe([]);
});

it('embeds a quote, without a link card', function () {
    Http::fake(['*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord()]);
    $quote = ['uri' => 'at://did:plc:other/app.bsky.feed.post/3m5kqzw7yx22c', 'cid' => 'bafyquoted'];

    bskyRun(bskyDriver(), bskyPost('Look https://example.com', [], new BlueskyOptions(quote: $quote)));

    expect(bskySent('createRecord')[0]->data()['record']['embed'])->toBe(['$type' => 'app.bsky.embed.record', 'record' => $quote])
        ->and(bskySent('example.com'))->toBe([]);
});

it('writes reply and quote gates atomically with applyWrites on the same record key', function () {
    Http::fake(['*/xrpc/com.atproto.repo.applyWrites' => function (Request $request) {
        $writes = $request->data()['writes'];

        return Http::response([
            'commit' => ['cid' => 'bafycommit', 'rev' => '3m5kqzw7zdc2a'],
            'results' => array_map(fn ($w) => [
                '$type' => 'com.atproto.repo.applyWrites#createResult',
                'uri' => 'at://'.BSKY_DID.'/'.$w['collection'].'/'.$w['rkey'],
                'cid' => 'bafy-'.$w['collection'],
                'validationStatus' => 'valid',
            ], $writes),
        ]);
    }]);

    $driver = bskyDriver();
    $post = bskyPost('Gated', [], new BlueskyOptions(replyControl: ['mentions', 'following', 'followers', 'at://did:plc:list/app.bsky.graph.list/3abc'], disableQuotes: true));
    $pending = $driver->publish($post);
    $result = bskyPublished($driver->resume($post, $pending->state));

    $rkey = $pending->state['rkey'];
    $uri = 'at://'.BSKY_DID.'/app.bsky.feed.post/'.$rkey;
    $data = bskySent('applyWrites')[0]->data();
    $createdAt = $data['writes'][0]['value']['createdAt'];

    expect(bskySent('createRecord'))->toBe([])
        ->and($createdAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/')
        ->and($data['repo'])->toBe(BSKY_DID)
        ->and($data['writes'])->toHaveCount(3)
        ->and($data['writes'][0])->toMatchArray(['$type' => 'com.atproto.repo.applyWrites#create', 'collection' => 'app.bsky.feed.post', 'rkey' => $rkey])
        ->and($data['writes'][1]['collection'])->toBe('app.bsky.feed.threadgate')
        ->and($data['writes'][1]['rkey'])->toBe($rkey)
        ->and($data['writes'][1]['value'])->toBe([
            '$type' => 'app.bsky.feed.threadgate',
            'post' => $uri,
            'allow' => [
                ['$type' => 'app.bsky.feed.threadgate#mentionRule'],
                ['$type' => 'app.bsky.feed.threadgate#followingRule'],
                ['$type' => 'app.bsky.feed.threadgate#followerRule'],
                ['$type' => 'app.bsky.feed.threadgate#listRule', 'list' => 'at://did:plc:list/app.bsky.graph.list/3abc'],
            ],
            'createdAt' => $createdAt,
        ])
        ->and($data['writes'][2]['value'])->toBe([
            '$type' => 'app.bsky.feed.postgate',
            'post' => $uri,
            'embeddingRules' => [['$type' => 'app.bsky.feed.postgate#disableRule']],
            'createdAt' => $createdAt,
        ])
        ->and($result->platformPostId)->toBe($uri)
        ->and($result->payload['cid'])->toBe('bafy-app.bsky.feed.post');
});

it('writes an empty threadgate when nobody may reply', function () {
    Http::fake(['*/xrpc/com.atproto.repo.applyWrites' => Http::response(['results' => [['uri' => 'at://'.BSKY_DID.'/app.bsky.feed.post/x', 'cid' => 'c']]])]);

    bskyRun(bskyDriver(), bskyPost('Closed', [], new BlueskyOptions(replyControl: [])));

    $writes = bskySent('applyWrites')[0]->data()['writes'];

    expect($writes)->toHaveCount(2)
        ->and($writes[1]['value']['allow'])->toBe([]);
});

// --- Images -------------------------------------------------------------------------

it('uploads each image as a blob in the create step, with alt text and aspect ratio', function () {
    $png = bskyImage('png', 40, 30);
    $jpg = bskyImage('jpg', 30, 40);

    Http::fake([
        '*/xrpc/com.atproto.repo.uploadBlob' => function (Request $request) {
            return Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafk-'.md5($request->body())], 'mimeType' => $request->header('Content-Type')[0], 'size' => strlen($request->body())]]);
        },
        '*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord(),
    ]);

    $driver = bskyDriver([$png => new MediaMetadata(width: 1600, height: 1200)]);
    $post = bskyPost('Two images', [Media::image($png, '  A blue square  '), Media::image($jpg)]);
    $pending = $driver->publish($post);

    Http::assertNothingSent();

    bskyPublished($driver->resume($post, $pending->state));

    $uploads = bskySent('uploadBlob');
    $embed = bskySent('createRecord')[0]->data()['record']['embed'];

    expect($uploads)->toHaveCount(2)
        ->and($uploads[0]->header('Content-Type'))->toBe(['image/png'])
        ->and($uploads[0]->body())->toBe(file_get_contents($png))
        ->and($uploads[1]->header('Content-Type'))->toBe(['image/jpeg'])
        ->and($embed['$type'])->toBe('app.bsky.embed.images')
        ->and($embed['images'][0])->toBe([
            'alt' => 'A blue square',
            'image' => ['$type' => 'blob', 'ref' => ['$link' => 'bafk-'.md5((string) file_get_contents($png))], 'mimeType' => 'image/png', 'size' => filesize($png)],
            'aspectRatio' => ['width' => 1600, 'height' => 1200],
        ])
        ->and($embed['images'][1]['alt'])->toBe('')
        ->and($embed['images'][1])->not->toHaveKey('aspectRatio');
});

it('quotes a post with images as recordWithMedia', function () {
    Http::fake([
        '*/xrpc/com.atproto.repo.uploadBlob' => Http::response(bskyFixture('upload_blob.json')),
        '*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord(),
    ]);
    $quote = ['uri' => 'at://did:plc:other/app.bsky.feed.post/3m5kqzw7yx22c', 'cid' => 'bafyquoted'];

    bskyRun(bskyDriver(), bskyPost('x', [Media::image(bskyImage())], new BlueskyOptions(quote: $quote)));

    $embed = bskySent('createRecord')[0]->data()['record']['embed'];

    expect($embed['$type'])->toBe('app.bsky.embed.recordWithMedia')
        ->and($embed['record'])->toBe(['$type' => 'app.bsky.embed.record', 'record' => $quote])
        ->and($embed['media']['$type'])->toBe('app.bsky.embed.images');
});

it('re-checks the image byte cap right before uploading', function () {
    config(['social.api.bluesky.image_max_bytes' => 50]);
    Http::fake();

    $driver = bskyDriver();
    $post = bskyPost('x', [Media::image(bskyImage('png', 40, 40))]);
    $e = bskyCatch(fn () => $driver->resume($post, $driver->publish($post)->state));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and($e->getMessage())->toContain('at most 50 bytes');

    Http::assertNothingSent();
});

it('maps a failed blob upload', function () {
    Http::fake(['*/xrpc/com.atproto.repo.uploadBlob' => Http::response(['error' => 'BlobTooLarge', 'message' => 'This file is too large. It is 2.1MB but the maximum size is 1MB.'], 400)]);

    $driver = bskyDriver();
    $post = bskyPost('x', [Media::image(bskyImage())]);
    $e = bskyCatch(fn () => $driver->resume($post, $driver->publish($post)->state));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::MediaRejected)
        ->and(bskySent('createRecord'))->toBe([]);
});

it('converts HEIC images through the bound transformer and cleans up', function () {
    $heic = bskyFile('fake-heic', 'heic');
    $converted = bskyImage('jpg');

    $transformer = new class($converted) implements MediaTransformer
    {
        public int $cleaned = 0;

        public function __construct(private string $out) {}

        public function transform(Media $media, Platform $platform, Capabilities $caps): Media
        {
            return $caps->media->image->conversionTarget($media->extension()) === 'jpg' ? Media::image($this->out, $media->altText) : $media;
        }

        public function cleanup(): void
        {
            $this->cleaned++;
        }
    };

    app()->instance(MediaTransformer::class, $transformer);
    Http::fake([
        '*/xrpc/com.atproto.repo.uploadBlob' => Http::response(bskyFixture('upload_blob.json')),
        '*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord(),
    ]);

    $driver = bskyDriver();
    $post = bskyPost('x', [Media::image($heic, 'alt')]);

    expect($driver->validate($post)->isEmpty())->toBeTrue();

    bskyRun($driver, $post);

    expect(bskySent('uploadBlob')[0]->body())->toBe(file_get_contents($converted))
        ->and(bskySent('uploadBlob')[0]->header('Content-Type'))->toBe(['image/jpeg'])
        ->and($transformer->cleaned)->toBe(1);
});

// --- Commit protocol and recovery ---------------------------------------------------------

it('verifies on a recovering run and does not create twice', function () {
    Http::fake([
        '*/xrpc/com.atproto.repo.getRecord*' => Http::response(bskyFixture('get_record.json')),
        '*' => Http::response(['error' => 'unexpected'], 500),
    ]);

    $driver = bskyDriver();
    $post = bskyPost('Hello Bluesky', [Media::image(bskyImage())]);
    $state = $driver->publish($post)->state;

    $result = bskyPublished($driver->resume($post, $state + [Pending::RECOVERING => true, Pending::ATTEMPT => 2]));
    [$lookup] = bskySent('getRecord');

    expect($result->platformPostId)->toBe('at://did:plc:4vx5zk2wrb7qmaz3rdc6yjfo/app.bsky.feed.post/3m5kqzw7yx22c')
        ->and($result->payload['recovered'])->toBeTrue()
        ->and($result->payload['cid'])->toBe(BSKY_CID)
        ->and($lookup->url())->toBe(BSKY_PDS.'/xrpc/com.atproto.repo.getRecord?repo='.urlencode(BSKY_DID).'&collection=app.bsky.feed.post&rkey='.$state['rkey'])
        ->and($lookup->hasHeader('Authorization'))->toBeFalse()
        ->and(bskySent('uploadBlob'))->toBe([])
        ->and(bskySent('createRecord'))->toBe([]);
});

it('creates with the same record key when a recovering run finds nothing', function () {
    Http::fake([
        '*/xrpc/com.atproto.repo.getRecord*' => Http::response(bskyFixture('record_not_found.json'), 400),
        '*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord(),
    ]);

    $driver = bskyDriver();
    $post = bskyPost('x');
    $state = $driver->publish($post)->state;
    $result = bskyPublished($driver->resume($post, $state + [Pending::RECOVERING => true]));

    expect(bskySent('createRecord')[0]->data()['rkey'])->toBe($state['rkey'])
        ->and($result->payload)->not->toHaveKey('recovered');
});

it('accepts the spec verify_post phase as a recovering create', function () {
    Http::fake(['*/xrpc/com.atproto.repo.getRecord*' => Http::response(bskyFixture('get_record.json'))]);

    $driver = bskyDriver();
    $post = bskyPost('x');
    $state = ['phase' => 'verify_post'] + $driver->publish($post)->state;

    expect(bskyPublished($driver->resume($post, $state))->payload['recovered'])->toBeTrue()
        ->and(bskySent('createRecord'))->toBe([]);
});

it('looks a failed create up before reporting it', function (int $status, array $body) {
    Http::fake([
        '*/xrpc/com.atproto.repo.createRecord' => Http::response($body, $status),
        '*/xrpc/com.atproto.repo.getRecord*' => Http::response(bskyFixture('get_record.json')),
    ]);

    $result = bskyRun(bskyDriver(), bskyPost('x'));

    expect($result->payload['recovered'])->toBeTrue()
        ->and(bskySent('getRecord'))->toHaveCount(1);
})->with([
    '502 from the proxy' => [502, ['error' => 'UpstreamFailure', 'message' => 'Upstream Failure']],
    'key already taken' => [400, ['error' => 'InvalidRequest', 'message' => 'Record already exists']],
    'swap race' => [400, ['error' => 'InvalidSwap', 'message' => 'Commit was at 3m5k...']],
]);

it('reports the failure when the record is not there', function () {
    Http::fake([
        '*/xrpc/com.atproto.repo.createRecord' => Http::response(['error' => 'InternalServerError', 'message' => 'Internal Server Error'], 500),
        '*/xrpc/com.atproto.repo.getRecord*' => Http::response(bskyFixture('record_not_found.json'), 400),
    ]);

    $e = bskyCatch(fn () => bskyRun(bskyDriver(), bskyPost('x')));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::ServerError)
        ->and($e->retryAfter)->toBe(60)
        // The create was sent: the job decides whether it may have landed.
        ->and($e->context)->not->toHaveKeys(['possibly_published', 'request_sent']);
});

it('flags a failure before the create request as never sent', function (string $case, string $class, FailureReason $reason) {
    $fakes = match ($case) {
        'blob 502' => ['*/xrpc/com.atproto.repo.uploadBlob' => Http::response(['error' => 'UpstreamFailure', 'message' => 'Upstream Failure'], 502)],
        'blob read timeout' => ['*/xrpc/com.atproto.repo.uploadBlob' => bskyConnectionFailure()],
        'blob refused' => ['*/xrpc/com.atproto.repo.uploadBlob' => Http::response(['error' => 'BlobTooLarge', 'message' => 'This file is too large.'], 400)],
        'login 502' => ['*/xrpc/com.atproto.server.createSession' => Http::response(['error' => 'UpstreamFailure'], 502)],
    };

    Http::fake($fakes + ['*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord()]);

    if ($case === 'login 502') {
        config(['social.api.bluesky.app_password_mode' => true]);
        $post = bskyPost('A text post', [], null, ['access_jwt' => null, 'identifier' => 'posterbot.bsky.social', 'app_password' => 'abcd-efgh-ijkl-mnop']);
    } else {
        $post = bskyPost('x', [Media::image(bskyImage())]);
    }

    $driver = bskyDriver();
    $e = bskyCatch(fn () => $driver->resume($post, $driver->publish($post)->state));

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason)
        ->and($e->context['possibly_published'])->toBeFalse()
        ->and($e->context['request_sent'])->toBeFalse()
        ->and($e->possiblyPublished())->toBeFalse()
        ->and(bskySent('createRecord'))->toBe([]);
})->with([
    'blob upload answers 502' => ['blob 502', TemporaryException::class, FailureReason::ServerError],
    'blob upload times out after sending' => ['blob read timeout', TemporaryException::class, FailureReason::Network],
    'blob refused' => ['blob refused', PermanentException::class, FailureReason::MediaRejected],
    'app-password login answers 502' => ['login 502', TemporaryException::class, FailureReason::ServerError],
]);

it('flags a failure before the create once a recovering run found no post', function () {
    Http::fake([
        '*/xrpc/com.atproto.repo.getRecord*' => Http::response(bskyFixture('record_not_found.json'), 400),
        '*/xrpc/com.atproto.repo.uploadBlob' => Http::response(['error' => 'UpstreamFailure', 'message' => 'Upstream Failure'], 502),
    ]);

    $driver = bskyDriver();
    $post = bskyPost('x', [Media::image(bskyImage())]);
    $e = bskyCatch(fn () => $driver->resume($post, $driver->publish($post)->state + [Pending::RECOVERING => true]));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->context['possibly_published'])->toBeFalse()
        ->and(bskySent('getRecord'))->toHaveCount(1)
        ->and(bskySent('createRecord'))->toBe([]);
});

it('reports a clear refusal of the create as not published once a recovering run found no post', function () {
    Http::fake([
        '*/xrpc/com.atproto.repo.getRecord*' => Http::response(bskyFixture('record_not_found.json'), 400),
        '*/xrpc/com.atproto.repo.createRecord' => Http::response(['error' => 'InvalidRecord', 'message' => 'Invalid app.bsky.feed.post record'], 400),
    ]);

    $driver = bskyDriver();
    $post = bskyPost('x');
    $e = bskyCatch(fn () => $driver->resume($post, $driver->publish($post)->state + [Pending::RECOVERING => true]));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->context['possibly_published'])->toBeFalse()
        ->and(bskySent('createRecord'))->toHaveCount(1);
});

it('keeps a 5xx on the create of a recovering run ambiguous', function () {
    Http::fake([
        '*/xrpc/com.atproto.repo.getRecord*' => Http::response(bskyFixture('record_not_found.json'), 400),
        '*/xrpc/com.atproto.repo.createRecord' => Http::response(['error' => 'InternalServerError', 'message' => 'Internal Server Error'], 500),
    ]);

    $driver = bskyDriver();
    $post = bskyPost('x');
    $e = bskyCatch(fn () => $driver->resume($post, $driver->publish($post)->state + [Pending::RECOVERING => true]));

    expect($e->context)->not->toHaveKey('possibly_published');
});

it('leaves a failed verification of a recovering run ambiguous', function () {
    Http::fake([
        '*/xrpc/com.atproto.repo.getRecord*' => Http::response(['error' => 'InternalServerError', 'message' => 'Internal Server Error'], 500),
        '*' => Http::response(['error' => 'unexpected'], 500),
    ]);

    $driver = bskyDriver();
    $post = bskyPost('x', [Media::image(bskyImage())]);
    $e = bskyCatch(fn () => $driver->resume($post, $driver->publish($post)->state + [Pending::RECOVERING => true]));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->context)->not->toHaveKeys(['possibly_published', 'request_sent'])
        ->and(bskySent('uploadBlob'))->toBe([]);
});

it('does not look up refusals that cannot hide a record', function (int $status, array $body) {
    Http::fake(['*/xrpc/com.atproto.repo.createRecord' => Http::response($body, $status)]);

    bskyCatch(fn () => bskyRun(bskyDriver(), bskyPost('x')));

    expect(bskySent('getRecord'))->toBe([]);
})->with([
    'expired token' => [400, ['error' => 'ExpiredToken', 'message' => 'Token has expired']],
    'rate limit' => [429, ['error' => 'RateLimitExceeded', 'message' => 'Rate Limit Exceeded']],
    'invalid record' => [400, ['error' => 'InvalidRecord', 'message' => 'Record/text must not be longer than 300 graphemes']],
    'takedown' => [400, ['error' => 'AccountTakedown', 'message' => 'Account has been taken down']],
]);

it('lets a connection failure on create bubble to the job', function () {
    Http::fake(['*/xrpc/com.atproto.repo.createRecord' => bskyConnectionFailure()]);

    $e = bskyCatch(fn () => bskyRun(bskyDriver(), bskyPost('x')));

    expect($e)->toBeInstanceOf(ConnectionException::class)
        ->and(bskySent('getRecord'))->toBe([]);
});

it('looks a 2xx without a uri up, else reports an unknown outcome', function () {
    Http::fake([
        '*/xrpc/com.atproto.repo.createRecord' => Http::response(['cid' => BSKY_CID]),
        '*/xrpc/com.atproto.repo.getRecord*' => Http::sequence()
            ->push(bskyFixture('get_record.json'))
            ->push(bskyFixture('record_not_found.json'), 400),
    ]);

    $recovered = bskyRun(bskyDriver(), bskyPost('x'));
    $e = bskyCatch(fn () => bskyRun(bskyDriver(), bskyPost('y')));

    expect($recovered->payload['recovered'])->toBeTrue()
        ->and($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown)
        ->and($e->possiblyPublished())->toBeTrue();
});

it('restarts the publish when a blob expired, then gives up', function () {
    Http::fake([
        '*/xrpc/com.atproto.repo.uploadBlob' => Http::response(bskyFixture('upload_blob.json')),
        '*/xrpc/com.atproto.repo.createRecord' => Http::response(['error' => 'BlobNotFound', 'message' => 'Could not find blob: bafkreibjfgx2gprinfvicegelk5kosd6y2frmqpqzwqkg7usac74l3t2v4'], 400),
    ]);

    $driver = bskyDriver();
    $post = bskyPost('x', [Media::image(bskyImage())]);
    $state = $driver->publish($post)->state;

    $first = bskyCatch(fn () => $driver->resume($post, $state));
    $last = bskyCatch(fn () => $driver->resume($post, $state + [Pending::RESTARTS => 1]));

    expect($first)->toBeInstanceOf(TemporaryException::class)
        ->and($first->context['restart'])->toBeTrue()
        ->and($first->retryAfter)->toBe(0)
        ->and($last)->toBeInstanceOf(PermanentException::class)
        ->and($last->reason)->toBe(FailureReason::MediaRejected)
        ->and(bskySent('getRecord'))->toBe([]);
});

it('detects the "could not find blob" spelling of BlobNotFound', function () {
    $driver = new BskyDriverProbe;

    expect($driver->call('isBlobNotFound', bskyResponse(400, ['error' => 'InvalidRequest', 'message' => 'Could not find blob: bafk...'])))->toBeTrue()
        ->and($driver->call('isBlobNotFound', bskyResponse(400, ['error' => 'InvalidRequest', 'message' => 'Invalid record'])))->toBeFalse();
});

it('restarts from the spec restart phase, and refuses unknown phases and broken state', function () {
    Http::fake();
    $driver = bskyDriver();
    $post = bskyPost('x');

    $restart = $driver->resume($post, ['phase' => 'restart', 'rkey' => '3m5kqzw7yx22c']);
    $unknown = bskyCatch(fn () => $driver->resume($post, ['phase' => 'publish_retry']));
    $broken = bskyCatch(fn () => $driver->resume($post, ['phase' => 'create', 'rkey' => 'not-a-tid']));

    expect($restart)->toBeInstanceOf(Pending::class)
        ->and($restart->state['phase'])->toBe('create')
        ->and($unknown)->toBeInstanceOf(PermanentException::class)
        ->and($unknown->getMessage())->toContain("Unknown Bluesky publish phase 'publish_retry'")
        ->and($broken)->toBeInstanceOf(PermanentException::class);

    Http::assertNothingSent();
});

it('re-resolves a rejected session once when the app binds a credentials resolver', function () {
    config(['social.credentials_resolver' => BskyTestResolver::class]);
    app()->bind(CredentialsResolver::class, BskyTestResolver::class);
    Http::fake(['*/xrpc/com.atproto.repo.createRecord' => Http::response(['error' => 'ExpiredToken', 'message' => 'Token has expired'], 400)]);

    $e = bskyCatch(fn () => bskyRun(bskyDriver(), bskyPost('x')));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and($e->reason)->toBe(FailureReason::InvalidToken)
        ->and($e->context['reresolve'])->toBeTrue()
        ->and($e->getPrevious())->toBeInstanceOf(PermanentException::class);
});

// --- PDS resolution -----------------------------------------------------------------------

it('resolves the PDS from the PLC directory when none is given, and caches it', function () {
    Http::fake([
        'plc.directory/*' => Http::response(bskyFixture('did_plc.json')),
        '*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord(),
    ]);

    bskyRun(bskyDriver(), bskyPost('one', [], null, ['pds' => null]));
    bskyRun(bskyDriver(), bskyPost('two', [], null, ['pds' => 'https://bsky.social']));

    expect(bskySent('plc.directory'))->toHaveCount(1)
        ->and(bskySent('plc.directory')[0]->url())->toBe('https://plc.directory/'.BSKY_DID)
        ->and(bskySent('createRecord'))->toHaveCount(2)
        ->and(bskySent('createRecord')[1]->url())->toBe(BSKY_PDS.'/xrpc/com.atproto.repo.createRecord');
});

it('accepts pds_url and strips a trailing /xrpc', function () {
    Http::fake(['*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord()]);

    bskyRun(bskyDriver(), bskyPost('x', [], null, ['pds' => null, 'pds_url' => 'https://pds.example.com:2583/xrpc/']));

    expect(bskySent('createRecord')[0]->url())->toBe('https://pds.example.com:2583/xrpc/com.atproto.repo.createRecord');
});

it('resolves did:web documents', function () {
    Http::fake([
        'example.com/.well-known/did.json' => Http::response(['id' => 'did:web:example.com', 'service' => [['id' => 'did:web:example.com#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example.com/']]]),
        '*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord(),
    ]);

    bskyRun(bskyDriver(), bskyPost('x', [], null, ['did' => 'did:web:example.com', 'pds' => null]));

    expect(bskySent('createRecord')[0]->url())->toBe('https://pds.example.com/xrpc/com.atproto.repo.createRecord');

    $probe = new BskyDriverProbe;

    expect($probe->call('didDocumentUrl', 'did:web:example.com%3A8443'))->toBe('https://example.com:8443/.well-known/did.json')
        ->and($probe->call('didDocumentUrl', 'did:web:example.com:users:alice'))->toBe('https://example.com/users/alice/did.json')
        ->and($probe->call('didDocumentUrl', 'did:key:z6Mk'))->toBeNull();
});

it('fails clearly when the PDS cannot be found', function () {
    Http::fake(['plc.directory/*' => Http::response(['id' => BSKY_DID, 'service' => []])]);

    $e = bskyCatch(fn () => bskyRun(bskyDriver(), bskyPost('x', [], null, ['pds' => null])));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Misconfigured)
        ->and($e->context['missing_credentials'])->toBe(['pds']);
});

it('retries when the PLC directory is down, unless a fallback is configured', function () {
    Http::fake([
        'plc.directory/*' => Http::response('upstream error', 503),
        'bsky.social/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord(),
    ]);

    $down = bskyCatch(fn () => bskyRun(bskyDriver(), bskyPost('x', [], null, ['pds' => null])));
    $fallback = bskyRun(bskyDriver(), bskyPost('x', [], null, ['pds' => 'https://bsky.social']));

    expect($down)->toBeInstanceOf(TemporaryException::class)
        ->and($down->context['pds_resolution'])->toBeTrue()
        ->and($fallback->platformPostId)->toStartWith('at://')
        ->and(bskySent('createRecord')[0]->url())->toBe('https://bsky.social/xrpc/com.atproto.repo.createRecord');
});

it('ignores a DID document that points at a private network', function () {
    config(['social.media.block_private_networks' => true]);
    PublicUrl::resolveUsing(fn (string $host) => $host === 'plc.directory' ? ['93.184.216.34'] : ['10.0.0.5']);
    Http::fake(['plc.directory/*' => Http::response(['id' => BSKY_DID, 'service' => [['id' => '#atproto_pds', 'serviceEndpoint' => 'https://internal.example.com']]])]);

    try {
        $e = bskyCatch(fn () => bskyRun(bskyDriver(), bskyPost('x', [], null, ['pds' => null])));
    } finally {
        PublicUrl::resolveUsing(null);
    }

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::Misconfigured);
});

// --- Error classification ---------------------------------------------------------------------

it('classifies XRPC errors', function (int $status, array $body, array $headers, string $class, FailureReason $reason, ?int $retryAfter, array $flags = []) {
    $e = (new BskyDriverProbe)->call('classifyError', bskyResponse($status, $body, $headers));

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason)
        ->and($e->platform)->toBe(Platform::Bluesky);

    if ($retryAfter !== null) {
        expect($e->retryAfter)->toBe($retryAfter);
    }

    foreach ($flags as $flag => $value) {
        expect($e->context[$flag] ?? null)->toBe($value);
    }
})->with([
    'nonce still missing' => [401, ['error' => 'use_dpop_nonce', 'message' => 'Resource server requires nonce in DPoP proof'], ['WWW-Authenticate' => 'DPoP error="use_dpop_nonce"'], TemporaryException::class, FailureReason::ServerError, 5, ['dpop_nonce' => true]],
    'bad proof' => [401, ['error' => 'invalid_dpop_proof', 'message' => 'DPoP proof is too old'], ['WWW-Authenticate' => 'DPoP error="invalid_dpop_proof"'], PermanentException::class, FailureReason::Misconfigured, null],
    'expired token' => [400, ['error' => 'ExpiredToken', 'message' => 'Token has expired'], [], PermanentException::class, FailureReason::InvalidToken, null, ['token_expired' => true, 'reresolvable' => true]],
    'oauth invalid token' => [401, ['error' => 'InvalidToken', 'message' => '"exp" claim timestamp check failed'], ['WWW-Authenticate' => 'DPoP error="invalid_token", error_description="\"exp\" claim timestamp check failed"'], PermanentException::class, FailureReason::InvalidToken, null],
    'auth required' => [401, ['error' => 'AuthenticationRequired', 'message' => 'Authentication Required'], [], PermanentException::class, FailureReason::InvalidToken, null],
    'two factor' => [401, ['error' => 'AuthFactorTokenRequired', 'message' => 'A sign in code has been sent to your email address'], [], PermanentException::class, FailureReason::InvalidToken, null, ['terminal' => true]],
    'takedown' => [400, ['error' => 'AccountTakedown', 'message' => 'Account has been taken down'], [], PermanentException::class, FailureReason::AccountRestricted, null],
    // What the PDS really sends: AuthRequiredError, HTTP 401 (auth-verifier.ts).
    'takedown as a 401' => [401, ['error' => 'AccountTakedown', 'message' => 'Account has been taken down'], [], PermanentException::class, FailureReason::AccountRestricted, null, ['reresolvable' => null, 'terminal' => null, 'token_expired' => null]],
    'deactivated as a 401' => [401, ['error' => 'AccountDeactivated', 'message' => 'Account is deactivated'], [], PermanentException::class, FailureReason::AccountRestricted, null, ['reresolvable' => null, 'terminal' => null]],
    'deactivated repo as 403' => [403, ['error' => 'RepoDeactivated', 'message' => 'Repo has been deactivated'], [], PermanentException::class, FailureReason::AccountRestricted, null],
    'forbidden' => [403, ['error' => 'Forbidden', 'message' => 'Forbidden'], [], PermanentException::class, FailureReason::InsufficientPermissions, null],
    'missing scope' => [400, ['error' => 'InsufficientScope', 'message' => 'Missing required scope "transition:generic"'], [], PermanentException::class, FailureReason::InsufficientPermissions, null],
    '429 without headers' => [429, ['error' => 'RateLimitExceeded', 'message' => 'Rate Limit Exceeded'], [], TemporaryException::class, FailureReason::RateLimited, 300],
    // A reset beyond max_rate_limit_wait (3600) is never waited out in the queue.
    '429 with a far reset' => [429, ['error' => 'RateLimitExceeded', 'message' => 'Rate Limit Exceeded'], ['ratelimit-reset' => (string) (time() + 86400)], PermanentException::class, FailureReason::RateLimited, null, ['daily_limit' => true]],
    '500' => [500, ['error' => 'InternalServerError', 'message' => 'Internal Server Error'], [], TemporaryException::class, FailureReason::ServerError, 60],
    'upstream failure as 400' => [400, ['error' => 'UpstreamFailure', 'message' => 'Upstream Failure'], [], TemporaryException::class, FailureReason::ServerError, 60],
    'blob too large' => [400, ['error' => 'BlobTooLarge', 'message' => 'This file is too large. It is 1.2MB but the maximum size is 976.56KB.'], [], PermanentException::class, FailureReason::MediaRejected, null],
    '413 from the proxy' => [413, [], [], PermanentException::class, FailureReason::MediaRejected, null],
    'mime' => [400, ['error' => 'InvalidMimeType', 'message' => 'Wrong type of file. It is image/gif but it must match image/*.'], [], PermanentException::class, FailureReason::MediaRejected, null],
    'invalid record' => [400, ['error' => 'InvalidRecord', 'message' => 'Record/text must not be longer than 300 graphemes'], [], PermanentException::class, FailureReason::InvalidContent, null],
    'repo not found' => [400, ['error' => 'RepoNotFound', 'message' => 'Could not find repo: did:plc:x'], [], PermanentException::class, FailureReason::Misconfigured, null],
    'invalid request' => [400, ['error' => 'InvalidRequest', 'message' => 'Input/repo must be a valid did or a handle'], [], PermanentException::class, FailureReason::Unknown, null],
    '404 via the default table' => [404, ['error' => 'XRPCNotSupported', 'message' => 'XRPCNotSupported'], [], PermanentException::class, FailureReason::NotFound, null],
    'teapot via the default table' => [418, ['message' => 'nope'], [], PermanentException::class, FailureReason::Unknown, null],
]);

it('reads the 429 wait from ratelimit-reset', function () {
    $e = (new BskyDriverProbe)->call('classifyError', bskyResponse(429, ['error' => 'RateLimitExceeded'], [
        'ratelimit-limit' => '5000', 'ratelimit-remaining' => '0', 'ratelimit-reset' => (string) (time() + 120), 'ratelimit-policy' => '5000;w=3600',
    ]));

    expect($e->retryAfter)->toBeGreaterThanOrEqual(118)->toBeLessThanOrEqual(120);
});

it('reports the daily write budget as permanent with a retry hint, never as a queue wait', function () {
    $e = (new BskyDriverProbe)->call('classifyError', bskyResponse(429, ['error' => 'RateLimitExceeded', 'message' => 'Rate Limit Exceeded'], [
        'ratelimit-limit' => '35000', 'ratelimit-remaining' => '0', 'ratelimit-reset' => (string) (time() + 20 * 3600), 'ratelimit-policy' => '35000;w=86400',
    ]));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::RateLimited)
        ->and($e->context['daily_limit'])->toBeTrue()
        ->and($e->context['rate_limit_window'])->toBe(86400)
        ->and($e->context['retry_after_hint'])->toBeGreaterThanOrEqual(71998)->toBeLessThanOrEqual(72000)
        ->and($e->context['retry_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($e->getMessage())->toContain($e->context['retry_at']);
});

it('waits out a reset within max_rate_limit_wait, which is configurable', function () {
    $response = bskyResponse(429, ['error' => 'RateLimitExceeded'], ['ratelimit-reset' => (string) (time() + 1800), 'ratelimit-policy' => '5000;w=3600']);

    $waited = (new BskyDriverProbe)->call('classifyError', $response);
    config(['social.api.bluesky.max_rate_limit_wait' => 600]);
    $refused = (new BskyDriverProbe)->call('classifyError', $response);

    expect($waited)->toBeInstanceOf(TemporaryException::class)
        ->and($waited->retryAfter)->toBeGreaterThanOrEqual(1798)->toBeLessThanOrEqual(1800)
        ->and($refused)->toBeInstanceOf(PermanentException::class)
        ->and($refused->reason)->toBe(FailureReason::RateLimited)
        ->and($refused->context['daily_limit'])->toBeFalse()
        ->and($refused->context['retry_after_hint'])->toBeGreaterThanOrEqual(1798);
});

it('neither re-resolves nor asks for a reconnect when the account is taken down', function () {
    config(['social.credentials_resolver' => BskyTestResolver::class]);
    app()->bind(CredentialsResolver::class, BskyTestResolver::class);
    Http::fake(['*/xrpc/com.atproto.repo.createRecord' => Http::response(['error' => 'AccountTakedown', 'message' => 'Account has been taken down'], 401)]);

    $e = bskyCatch(fn () => bskyRun(bskyDriver(), bskyPost('x')));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::AccountRestricted)
        ->and($e->reason->requiresReconnect())->toBeFalse()
        ->and($e->getMessage())->not->toContain('reconnect')
        ->and($e->context)->not->toHaveKey('reresolve');
});

it('writes the refusal message the app showed', function () {
    $e = (new BskyDriverProbe)->call('classifyError', bskyResponse(400, ['error' => 'InvalidRequest', 'message' => 'Invalid app.bsky.feed.post record.']));

    expect($e->getMessage())->toBe('Bluesky refused this post (Invalid app.bsky.feed.post record).')
        ->and($e->context['error'])->toBe('InvalidRequest')
        ->and($e->context['surface'])->toBe('pds');
});

it('classifies video-service refusals by message before the status rows', function (int $status, array $body, string $class, FailureReason $reason) {
    $e = (new BskyDriverProbe)->call('classifyError', bskyResponse($status, $body), 'video');

    expect($e)->toBeInstanceOf($class)->and($e->reason)->toBe($reason);
})->with([
    'unverified email as 403' => [403, ['error' => 'Forbidden', 'message' => 'You need to verify your email before uploading videos'], PermanentException::class, FailureReason::AccountRestricted],
    'daily limit as 429' => [429, ['error' => 'RateLimitExceeded', 'message' => 'You have exceeded the daily video upload limit'], PermanentException::class, FailureReason::RateLimited],
    'bytes quota without the word video' => [400, ['error' => 'InvalidRequest', 'message' => 'Upload limit reached: bytes exceeded'], PermanentException::class, FailureReason::RateLimited],
    'file too large is not a quota' => [400, ['error' => 'InvalidRequest', 'message' => 'Video exceeds size limit: file is too large'], PermanentException::class, FailureReason::MediaRejected],
    '5xx first' => [503, ['error' => 'ServiceUnavailable', 'message' => 'daily maintenance'], TemporaryException::class, FailureReason::ServerError],
    'plain 403' => [403, ['error' => 'Forbidden', 'message' => 'Forbidden'], PermanentException::class, FailureReason::InsufficientPermissions],
    'ordinary throttle stays temporary' => [429, ['error' => 'RateLimitExceeded', 'message' => 'Rate Limit Exceeded'], TemporaryException::class, FailureReason::RateLimited],
    'auth still wins' => [401, ['error' => 'AuthMissing', 'message' => 'Authentication Required (check email)'], PermanentException::class, FailureReason::InvalidToken],
]);

it('keeps email and limit messages out of PDS classification', function () {
    $e = (new BskyDriverProbe)->call('classifyError', bskyResponse(400, ['error' => 'InvalidRequest', 'message' => 'Your email has reached the daily limit']));

    expect($e->reason)->toBe(FailureReason::Unknown);
});

it('gives the daily video limit a retry hint instead of a queue wait', function () {
    $e = (new BskyDriverProbe)->call('classifyError', bskyResponse(429, ['message' => 'Daily video limit reached'], ['ratelimit-reset' => (string) (time() + 7200)]), 'video');

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->context['retry_after_hint'])->toBeGreaterThanOrEqual(7198)->toBeLessThanOrEqual(7200)
        ->and($e->context['retry_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($e->context['daily_limit'])->toBeTrue();
});

it('handles the other PDS resolution outcomes', function () {
    Http::fake([
        'plc.directory/did:plc:offline*' => bskyConnectionFailure('cURL error 7: Failed to connect to plc.directory port 443'),
        'plc.directory/did:plc:unknown*' => Http::response(['message' => 'DID not registered: did:plc:unknown'], 404),
        'plc.directory/did:plc:nopds*' => Http::response(['id' => 'did:plc:nopds', 'service' => [['id' => '#bsky_fg', 'serviceEndpoint' => 'https://feed.example.com']]]),
        'bsky.social/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord(),
    ]);

    $offline = bskyCatch(fn () => bskyRun(bskyDriver(), bskyPost('x', [], null, ['did' => 'did:plc:offline', 'pds' => null])));
    $unknown = bskyCatch(fn () => bskyRun(bskyDriver(), bskyPost('x', [], null, ['did' => 'did:plc:unknown', 'pds' => null])));
    $entryway = bskyRun(bskyDriver(), bskyPost('x', [], null, ['did' => 'did:plc:nopds', 'pds' => 'https://bsky.social/']));

    expect($offline)->toBeInstanceOf(TemporaryException::class)
        ->and($offline->reason)->toBe(FailureReason::Network)
        ->and($offline->context['pds_resolution'])->toBeTrue()
        ->and($unknown)->toBeInstanceOf(PermanentException::class)
        ->and($unknown->reason)->toBe(FailureReason::Misconfigured)
        // A DID document without a PDS: the configured entryway is used as is.
        ->and($entryway->platformPostId)->toStartWith('at://did:plc:nopds/');
});

it('refuses a PDS lookup for DID methods it cannot resolve', function () {
    Http::fake();

    $e = bskyCatch(fn () => bskyRun(bskyDriver(), bskyPost('x', [], null, ['did' => 'did:key:z6MkhaXgBZDvotDkL5257faiztiGiC2QtKLGpbnnEGta2doK', 'pds' => null])));

    expect($e->reason)->toBe(FailureReason::Misconfigured);
    Http::assertNothingSent();
});
