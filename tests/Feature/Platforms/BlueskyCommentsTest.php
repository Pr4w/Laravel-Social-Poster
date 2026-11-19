<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SocialPoster\Capabilities\CommentRules;
use SocialPoster\Contracts\SupportsFirstComment;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\Bluesky\BlueskyOptions;
use SocialPoster\Platforms\Bluesky\Tid;
use SocialPoster\Support\Text\GraphemeCounter;
use SocialPoster\ValueObjects\CommentResult;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\PostResult;

require_once __DIR__.'/../../Fixtures/bluesky/helpers.php';

const BSKY_PARENT_RKEY = '3m5kqzw7yx22c';

const BSKY_PARENT_URI = 'at://'.BSKY_DID.'/app.bsky.feed.post/'.BSKY_PARENT_RKEY;

/** @param array<string, mixed> $payload */
function bskyParent(array $payload = ['uri' => BSKY_PARENT_URI, 'cid' => BSKY_CID], ?string $id = BSKY_PARENT_URI): PostResult
{
    return PostResult::success(Platform::Bluesky, $id, 'https://bsky.app/profile/'.BSKY_DID.'/post/'.BSKY_PARENT_RKEY, $payload, PostResult::STATE_PUBLISHED, 'at_uri');
}

beforeEach(function () {
    Cache::flush();
    config(['social.authorizers' => []]);
});

it('implements the first-comment contract with Bluesky limits', function () {
    $driver = bskyDriver();
    $rules = $driver->commentRules(bskyPost('x'), bskyParent());

    expect($driver)->toBeInstanceOf(SupportsFirstComment::class)
        ->and($rules)->toBeInstanceOf(CommentRules::class)
        ->and($rules->max)->toBe(300)
        ->and($rules->maxBytes)->toBe(3000)
        ->and($rules->counter)->toBeInstanceOf(GraphemeCounter::class)
        ->and($rules->minDelaySeconds)->toBe(0)
        ->and($rules->requiredScopes)->toBe([['atproto']])
        ->and($rules->unknownScopePolicy)->toBe(CommentRules::ATTEMPT)
        ->and($rules->skipReason(new Credentials(['scopes' => 'transition:generic'])))->toBe('missing_scope')
        ->and($rules->skipReason(new Credentials(bskyCredentials())))->toBeNull()
        ->and($rules->fits(str_repeat('🇫🇷', 300)))->toBeTrue()
        ->and($rules->fits(str_repeat('a', 301)))->toBeFalse();
});

it('replies to the post with strong refs from its payload, facets and a derived record key', function () {
    Http::fake([
        'public.api.bsky.app/*' => Http::response(['did' => 'did:plc:bob00000000000000000000']),
        '*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord(),
    ]);

    $result = bskyDriver()->addFirstComment(bskyPost('ignored', [], new BlueskyOptions(langs: ['en'])), bskyParent(), '  More at https://example.com/x with @bob.test #launch  ');

    $request = bskySent('createRecord')[0];
    $data = $request->data();
    $rkey = $data['rkey'];
    $ref = ['uri' => BSKY_PARENT_URI, 'cid' => BSKY_CID];

    expect(bskySent('getRecord'))->toBe([])
        ->and($data['repo'])->toBe(BSKY_DID)
        ->and($data['collection'])->toBe('app.bsky.feed.post')
        ->and($rkey)->toMatch(Tid::PATTERN)
        ->and($rkey)->toBe(Tid::derive(BSKY_PARENT_RKEY, 'first-comment|'.BSKY_PARENT_URI.'|More at https://example.com/x with @bob.test #launch'))
        ->and(strcmp($rkey, BSKY_PARENT_RKEY))->toBeGreaterThan(0)
        ->and($data['record']['$type'])->toBe('app.bsky.feed.post')
        ->and($data['record']['text'])->toBe('More at https://example.com/x with @bob.test #launch')
        ->and($data['record']['createdAt'])->toMatch('/Z$/')
        ->and($data['record']['reply'])->toBe(['root' => $ref, 'parent' => $ref])
        ->and($data['record']['langs'])->toBe(['en'])
        ->and(array_column(array_merge(...array_column($data['record']['facets'], 'features')), '$type'))->toBe([
            'app.bsky.richtext.facet#link',
            'app.bsky.richtext.facet#mention',
            'app.bsky.richtext.facet#tag',
        ])
        ->and($result)->toBeInstanceOf(CommentResult::class)
        ->and($result->id)->toBe('at://'.BSKY_DID.'/app.bsky.feed.post/'.$rkey)
        ->and($result->url)->toBe('https://bsky.app/profile/'.BSKY_DID.'/post/'.$rkey)
        ->and($result->payload)->toBe(['uri' => $result->id, 'cid' => BSKY_CID, 'did' => BSKY_DID, 'rkey' => $rkey]);
});

it('derives the same record key on every retry of the same comment', function () {
    Http::fake(['*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord()]);

    $driver = bskyDriver();
    $driver->addFirstComment(bskyPost('x'), bskyParent(), 'Same text');
    $driver->addFirstComment(bskyPost('x'), bskyParent(), 'Same text');
    $driver->addFirstComment(bskyPost('x'), bskyParent(), 'Other text');

    $keys = array_map(fn (Request $r) => $r->data()['rkey'], bskySent('createRecord'));

    expect($keys[0])->toBe($keys[1])->not->toBe($keys[2]);
});

it('keeps the thread root when the parent is itself a reply', function () {
    Http::fake(['*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord()]);
    $root = ['uri' => 'at://did:plc:other/app.bsky.feed.post/3aaaaaaaaaa2a', 'cid' => 'bafyroot'];

    bskyDriver()->addFirstComment(bskyPost('x'), bskyParent(['uri' => BSKY_PARENT_URI, 'cid' => BSKY_CID, 'reply_root' => $root]), 'Thread reply');

    expect(bskySent('createRecord')[0]->data()['record']['reply'])->toBe([
        'root' => $root,
        'parent' => ['uri' => BSKY_PARENT_URI, 'cid' => BSKY_CID],
    ]);
});

it('reads the cid and the root with getRecord when the payload lacks them', function () {
    $root = ['uri' => 'at://did:plc:other/app.bsky.feed.post/3aaaaaaaaaa2a', 'cid' => 'bafyroot'];

    Http::fake([
        '*/xrpc/com.atproto.repo.getRecord*' => Http::response([
            'uri' => BSKY_PARENT_URI,
            'cid' => 'bafyfromgetrecord',
            'value' => ['$type' => 'app.bsky.feed.post', 'text' => 'x', 'createdAt' => '2026-11-18T10:00:00.000Z', 'reply' => ['root' => $root, 'parent' => $root]],
        ]),
        '*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord(),
    ]);

    // A v1.0-style parent: only the platform id is known.
    bskyDriver()->addFirstComment(bskyPost('x'), bskyParent([], BSKY_PARENT_URI), 'Reply');

    $lookup = bskySent('getRecord')[0];

    expect($lookup->url())->toBe(BSKY_PDS.'/xrpc/com.atproto.repo.getRecord?repo='.urlencode(BSKY_DID).'&collection=app.bsky.feed.post&rkey='.BSKY_PARENT_RKEY)
        ->and(bskySent('createRecord')[0]->data()['record']['reply'])->toBe([
            'root' => $root,
            'parent' => ['uri' => BSKY_PARENT_URI, 'cid' => 'bafyfromgetrecord'],
        ]);
});

it('reads a parent in another repo from that repo\'s PDS', function () {
    Http::fake([
        'plc.directory/*' => Http::response(['id' => 'did:plc:other', 'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://other.host.bsky.network']]]),
        'other.host.bsky.network/xrpc/com.atproto.repo.getRecord*' => Http::response(['uri' => 'at://did:plc:other/app.bsky.feed.post/3aaaaaaaaaa2a', 'cid' => 'bafyother', 'value' => []]),
        '*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord(),
    ]);

    bskyDriver()->addFirstComment(bskyPost('x'), bskyParent([], 'at://did:plc:other/app.bsky.feed.post/3aaaaaaaaaa2a'), 'Reply');

    expect(bskySent('createRecord')[0]->data()['record']['reply']['parent']['cid'])->toBe('bafyother');
});

it('fails when the parent post is gone', function () {
    Http::fake(['*/xrpc/com.atproto.repo.getRecord*' => Http::response(bskyFixture('record_not_found.json'), 400)]);

    $e = bskyCatch(fn () => bskyDriver()->addFirstComment(bskyPost('x'), bskyParent([]), 'Reply'));

    expect($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::NotFound)
        ->and(bskySent('createRecord'))->toBe([]);
});

it('refuses a parent that is not a post AT URI, and a comment that is too long', function () {
    Http::fake();

    $badParent = bskyCatch(fn () => bskyDriver()->addFirstComment(bskyPost('x'), bskyParent([], 'https://bsky.app/profile/x/post/y'), 'Reply'));
    $profile = bskyCatch(fn () => bskyDriver()->addFirstComment(bskyPost('x'), bskyParent([], 'at://'.BSKY_DID.'/app.bsky.actor.profile/self'), 'Reply'));
    $long = bskyCatch(fn () => bskyDriver()->addFirstComment(bskyPost('x'), bskyParent(), str_repeat('a', 301)));
    $bytes = bskyCatch(fn () => bskyDriver()->addFirstComment(bskyPost('x'), bskyParent(), str_repeat('👨‍👩‍👧‍👦', 121)));
    $empty = bskyCatch(fn () => bskyDriver()->addFirstComment(bskyPost('x'), bskyParent(), '   '));

    expect($badParent->reason)->toBe(FailureReason::InvalidContent)
        ->and($profile->reason)->toBe(FailureReason::InvalidContent)
        ->and($long->reason)->toBe(FailureReason::InvalidContent)
        ->and($long->getMessage())->toContain('the limit is 300')
        ->and($bytes->getMessage())->toContain('3000 bytes')
        ->and($empty->getMessage())->toContain('empty');

    Http::assertNothingSent();
});

it('needs credentials before anything else', function () {
    Http::fake();

    $e = bskyCatch(fn () => bskyDriver()->addFirstComment(bskyPost('x', [], null, ['did' => null]), bskyParent(), 'Reply'));

    expect($e->reason)->toBe(FailureReason::Misconfigured);
    Http::assertNothingSent();
});

it('looks the reply up after a lost response instead of posting it twice', function () {
    Http::fake([
        '*/xrpc/com.atproto.repo.createRecord' => bskyConnectionFailure(),
        '*/xrpc/com.atproto.repo.getRecord*' => fn (Request $r) => Http::response(['uri' => 'at://'.BSKY_DID.'/app.bsky.feed.post/'.$r->data()['rkey'], 'cid' => 'bafyreply', 'value' => []]),
    ]);

    $result = bskyDriver()->addFirstComment(bskyPost('x'), bskyParent(), 'Reply');

    expect(bskySent('createRecord'))->toHaveCount(1)
        ->and($result->payload['recovered'])->toBeTrue()
        ->and($result->payload['cid'])->toBe('bafyreply')
        ->and(bskySent('getRecord')[0]->data()['rkey'])->toBe(bskySent('createRecord')[0]->data()['rkey']);
});

it('sends once more with the same key when the lost reply does not exist', function () {
    $calls = 0;

    Http::fake([
        '*/xrpc/com.atproto.repo.createRecord' => function (Request $request) use (&$calls) {
            return ++$calls === 1
                ? bskyConnectionFailure()($request)
                : Http::response(['uri' => 'at://'.BSKY_DID.'/app.bsky.feed.post/'.$request->data()['rkey'], 'cid' => 'bafyreply']);
        },
        '*/xrpc/com.atproto.repo.getRecord*' => Http::response(bskyFixture('record_not_found.json'), 400),
    ]);

    $result = bskyDriver()->addFirstComment(bskyPost('x'), bskyParent(), 'Reply');
    $keys = array_map(fn (Request $r) => $r->data()['rkey'], bskySent('createRecord'));

    expect($calls)->toBe(2)
        ->and($keys)->toHaveCount(2)
        ->and($keys[0])->toBe($keys[1])
        ->and(bskySent('getRecord'))->toHaveCount(1)
        ->and($result->id)->toBe('at://'.BSKY_DID.'/app.bsky.feed.post/'.$keys[0])
        ->and($result->payload)->not->toHaveKey('recovered');
});

it('rethrows a second connection failure for the job to retry', function () {
    Http::fake([
        '*/xrpc/com.atproto.repo.createRecord' => bskyConnectionFailure(),
        '*/xrpc/com.atproto.repo.getRecord*' => Http::response(bskyFixture('record_not_found.json'), 400),
    ]);

    $e = bskyCatch(fn () => bskyDriver()->addFirstComment(bskyPost('x'), bskyParent(), 'Reply'));

    expect($e)->toBeInstanceOf(ConnectionException::class)
        ->and(bskySent('getRecord'))->toHaveCount(1);
});

it('verifies then retries once after a server error', function () {
    Http::fake([
        '*/xrpc/com.atproto.repo.createRecord' => Http::sequence()
            ->push(['error' => 'InternalServerError', 'message' => 'Internal Server Error'], 500)
            ->push(['uri' => 'at://'.BSKY_DID.'/app.bsky.feed.post/3zzzzzzzzzz2z', 'cid' => 'bafyreply']),
        '*/xrpc/com.atproto.repo.getRecord*' => Http::response(bskyFixture('record_not_found.json'), 400),
    ]);

    $result = bskyDriver()->addFirstComment(bskyPost('x'), bskyParent(), 'Reply');
    $keys = array_map(fn (Request $r) => $r->data()['rkey'], bskySent('createRecord'));

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->toBe($keys[1])
        ->and($result->id)->toBe('at://'.BSKY_DID.'/app.bsky.feed.post/3zzzzzzzzzz2z');
});

it('reports a temporary failure when the server keeps failing', function () {
    Http::fake([
        '*/xrpc/com.atproto.repo.createRecord' => Http::response(['error' => 'InternalServerError', 'message' => 'Internal Server Error'], 500),
        '*/xrpc/com.atproto.repo.getRecord*' => Http::response(bskyFixture('record_not_found.json'), 400),
    ]);

    $e = bskyCatch(fn () => bskyDriver()->addFirstComment(bskyPost('x'), bskyParent(), 'Reply'));

    expect($e)->toBeInstanceOf(TemporaryException::class)
        ->and(bskySent('createRecord'))->toHaveCount(2)
        ->and(bskySent('getRecord'))->toHaveCount(2);
});

it('treats a refused repeat as success when the reply exists', function () {
    Http::fake([
        '*/xrpc/com.atproto.repo.createRecord' => Http::response(['error' => 'InvalidRequest', 'message' => 'Record already exists'], 400),
        '*/xrpc/com.atproto.repo.getRecord*' => Http::response(['uri' => 'at://'.BSKY_DID.'/app.bsky.feed.post/3zzzzzzzzzz2z', 'cid' => 'bafyreply', 'value' => []]),
    ]);

    $result = bskyDriver()->addFirstComment(bskyPost('x'), bskyParent(), 'Reply');

    expect($result->payload['recovered'])->toBeTrue()
        ->and(bskySent('createRecord'))->toHaveCount(1);
});

it('maps auth and rate-limit refusals without a lookup', function (int $status, array $body, string $class, FailureReason $reason) {
    Http::fake(['*/xrpc/com.atproto.repo.createRecord' => Http::response($body, $status)]);

    $e = bskyCatch(fn () => bskyDriver()->addFirstComment(bskyPost('x'), bskyParent(), 'Reply'));

    expect($e)->toBeInstanceOf($class)
        ->and($e->reason)->toBe($reason)
        ->and(bskySent('getRecord'))->toBe([]);
})->with([
    'expired' => [400, ['error' => 'ExpiredToken', 'message' => 'Token has expired'], PermanentException::class, FailureReason::InvalidToken],
    'rate limited' => [429, ['error' => 'RateLimitExceeded', 'message' => 'Rate Limit Exceeded'], TemporaryException::class, FailureReason::RateLimited],
    'thread gated' => [400, ['error' => 'InvalidRecord', 'message' => 'Reply is not allowed by the thread gate'], PermanentException::class, FailureReason::InvalidContent],
]);

it('looks a 2xx without a uri up, else reports an unknown outcome', function () {
    Http::fake([
        '*/xrpc/com.atproto.repo.createRecord' => Http::response(['cid' => 'bafyreply']),
        '*/xrpc/com.atproto.repo.getRecord*' => Http::sequence()
            ->push(['uri' => 'at://'.BSKY_DID.'/app.bsky.feed.post/3zzzzzzzzzz2z', 'cid' => 'bafyreply', 'value' => []])
            ->push(bskyFixture('record_not_found.json'), 400),
    ]);

    $found = bskyDriver()->addFirstComment(bskyPost('x'), bskyParent(), 'Reply');
    $e = bskyCatch(fn () => bskyDriver()->addFirstComment(bskyPost('x'), bskyParent(), 'Reply again'));

    expect($found->payload['recovered'])->toBeTrue()
        ->and($e)->toBeInstanceOf(PermanentException::class)
        ->and($e->reason)->toBe(FailureReason::OutcomeUnknown);
});

it('falls back to a fresh key when the parent key is not a TID', function () {
    Http::fake(['*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord()]);

    bskyDriver()->addFirstComment(bskyPost('x'), bskyParent(['uri' => 'at://'.BSKY_DID.'/app.bsky.feed.post/custom-key', 'cid' => BSKY_CID]), 'Reply');

    expect(bskySent('createRecord')[0]->data()['rkey'])->toMatch(Tid::PATTERN);
});

it('falls back to the AppView for a parent whose PDS cannot be found', function () {
    Http::fake([
        'plc.directory/*' => Http::response(['id' => 'did:plc:other', 'service' => []]),
        'public.api.bsky.app/xrpc/com.atproto.repo.getRecord*' => Http::response(['uri' => 'at://did:plc:other/app.bsky.feed.post/3aaaaaaaaaa2a', 'cid' => 'bafyappview', 'value' => []]),
        '*/xrpc/com.atproto.repo.createRecord' => bskyCreateRecord(),
    ]);

    bskyDriver()->addFirstComment(bskyPost('x'), bskyParent([], 'at://did:plc:other/app.bsky.feed.post/3aaaaaaaaaa2a'), 'Reply');

    expect(bskySent('createRecord')[0]->data()['record']['reply']['parent']['cid'])->toBe('bafyappview');
});
