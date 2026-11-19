<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use SocialPoster\Contracts\AtomicIdempotencyStore;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Idempotency\CacheIdempotencyStore;
use SocialPoster\Idempotency\KeyCollisionException;
use SocialPoster\ValueObjects\PostResult;

/**
 * Point social.idempotency_cache_store at a real cache driver and return a
 * store that reads its configuration lazily, as the container builds it.
 */
function cacheIdempotencyStoreOn(string $driver): CacheIdempotencyStore
{
    if ($driver === 'file') {
        $dir = sys_get_temp_dir().'/sp-idempotency-'.bin2hex(random_bytes(6));
        test()->cacheDir = $dir;
        config(['cache.stores.file' => ['driver' => 'file', 'path' => $dir, 'lock_path' => $dir]]);
    }

    if ($driver === 'database') {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
        config(['cache.stores.database' => [
            'driver' => 'database', 'connection' => null, 'table' => 'cache',
            'lock_connection' => null, 'lock_table' => 'cache_locks',
        ]]);
    }

    config(['social.idempotency_cache_store' => $driver]);

    return new CacheIdempotencyStore;
}

afterEach(function () {
    if (isset($this->cacheDir)) {
        File::deleteDirectory($this->cacheDir);
    }
});

dataset('cache drivers', ['array', 'file', 'database']);

it('implements the atomic contract', function () {
    expect(new CacheIdempotencyStore)->toBeInstanceOf(AtomicIdempotencyStore::class);
});

it('replays a published result with its state, id kind, url and payload', function (string $driver) {
    $store = cacheIdempotencyStoreOn($driver);

    $store->markPublished('post-1', PostResult::success(
        Platform::TikTok,
        'v_pub_url~v2.123',
        null,
        ['publish_id' => 'v_pub_url~v2.123'],
        PostResult::STATE_DRAFT,
        'publish_id',
    )->withMetadata(['scheduled_post_id' => 7]));

    $replayed = $store->completed('post-1');

    expect($replayed)->toBeInstanceOf(PostResult::class)
        ->and($replayed->platform)->toBe(Platform::TikTok)
        ->and($replayed->platformPostId)->toBe('v_pub_url~v2.123')
        ->and($replayed->payload)->toBe(['publish_id' => 'v_pub_url~v2.123'])
        ->and($replayed->isDraft())->toBeTrue()
        ->and($replayed->idKind)->toBe('publish_id')
        ->and($replayed->metadata)->toBe([])
        ->and($store->status('post-1'))->toBe('published')
        ->and($store->completed('missing'))->toBeNull()
        ->and($store->status('missing'))->toBeNull();
})->with('cache drivers');

it('lets one owner claim a key, renews the holder and hands it over when the lease expires', function (string $driver) {
    $store = cacheIdempotencyStoreOn($driver);

    expect($store->claim('post-1', 'owner-a', 60))->toBeTrue()
        ->and($store->claim('post-1', 'owner-b', 60))->toBeFalse()
        ->and($store->claim('post-1', 'owner-a', 60))->toBeTrue();

    $this->travel(30)->seconds();
    expect($store->claim('post-1', 'owner-b', 60))->toBeFalse();

    $this->travel(31)->seconds();
    expect($store->claim('post-1', 'owner-b', 60))->toBeTrue()
        ->and($store->claim('post-1', 'owner-a', 60))->toBeFalse()
        ->and($store->status('post-1'))->toBe('pending');
})->with('cache drivers');

it('releases a lease only for its owner', function (string $driver) {
    $store = cacheIdempotencyStoreOn($driver);
    $store->claim('post-1', 'owner-a', 300);

    $store->release('post-1', 'owner-b');
    expect($store->claim('post-1', 'owner-b', 300))->toBeFalse();

    $store->release('post-1', 'owner-a');
    expect($store->claim('post-1', 'owner-b', 300))->toBeTrue();
})->with('cache drivers');

it('refuses claims on published and failed records until they are reopened or forgotten', function (string $driver) {
    $store = cacheIdempotencyStoreOn($driver);

    $store->claim('published', 'owner-a', 300);
    $store->markPublished('published', PostResult::success(Platform::X, '1850000000000000000'));
    expect($store->claim('published', 'owner-b', 300))->toBeFalse();

    $store->claim('failed', 'owner-a', 300);
    $store->markFailed('failed', ['phase' => 'tweet']);
    expect($store->status('failed'))->toBe('failed')
        ->and($store->claim('failed', 'owner-b', 300))->toBeFalse();

    $store->markCommitting('failed', ['phase' => 'tweet']);
    expect($store->claim('failed', 'owner-b', 300))->toBeTrue();

    // forget() also drops the lease, so a fresh post can start right away.
    $store->forget('failed');
    expect($store->status('failed'))->toBeNull()
        ->and($store->claim('failed', 'owner-c', 300))->toBeTrue();
})->with('cache drivers');

it('never lets markCommitting, markPending, markFailed or forget touch a published record', function (string $driver) {
    $store = cacheIdempotencyStoreOn($driver);
    $store->markPublished('post-1', PostResult::success(Platform::Instagram, '17900000000000001'));

    $store->markCommitting('post-1', ['phase' => 'publish']);
    $store->markPending('post-1', ['phase' => 'poll']);
    $store->markFailed('post-1');
    $store->forget('post-1');

    expect($store->status('post-1'))->toBe('published')
        ->and($store->completed('post-1')->platformPostId)->toBe('17900000000000001')
        ->and($store->pendingState('post-1'))->toBeNull()
        ->and($store->committing('post-1'))->toBeNull();
})->with('cache drivers');

it('tracks pending and committing state and keeps the first committing time', function (string $driver) {
    $store = cacheIdempotencyStoreOn($driver);
    $this->travelTo(Carbon::parse('2026-11-18 10:00:00'));
    $since = now()->getTimestamp();

    $store->markPending('post-1', ['phase' => 'container', 'container_id' => '1']);
    expect($store->pendingState('post-1'))->toBe(['phase' => 'container', 'container_id' => '1'])
        ->and($store->committing('post-1'))->toBeNull();

    $store->markCommitting('post-1', ['phase' => 'publish', 'container_id' => '1']);
    $this->travel(2)->minutes();
    $store->markCommitting('post-1', ['phase' => 'publish', 'container_id' => '1', 'tries' => 2]);

    expect($store->committing('post-1'))->toBe([
        'state' => ['phase' => 'publish', 'container_id' => '1', 'tries' => 2],
        'since' => $since,
    ])->and($store->pendingState('post-1'))->toBeNull();

    $store->markPending('post-1', ['phase' => 'await']);
    $this->travel(1)->minutes();
    $store->markCommitting('post-1', ['phase' => 'publish']);

    expect($store->committing('post-1')['since'])->toBe(now()->getTimestamp());
})->with('cache drivers');

it('keeps the first committing time when a failed record is reopened', function (string $driver) {
    $store = cacheIdempotencyStoreOn($driver);
    $this->travelTo(Carbon::parse('2026-11-18 10:00:00'));
    $since = now()->getTimestamp();

    // A create was sent, the outcome was lost, the publish ended possibly published.
    $store->markCommitting('post-1', ['phase' => 'create', '_commits' => true]);
    $this->travel(5)->minutes();
    $store->markFailed('post-1', ['phase' => 'create', '_commits' => true]);

    // Hours later a reconcile reopens it: the driver must search from the first create.
    $this->travel(3)->hours();
    $store->markCommitting('post-1', ['phase' => 'create', '_commits' => true]);

    expect($store->committing('post-1')['since'])->toBe($since)
        ->and($store->record('post-1')['since'])->toBe($since);

    // A failed record that never was committing starts its clock when reopened.
    $store->markPending('post-2', ['phase' => 'poll']);
    $store->markFailed('post-2');
    $this->travel(1)->minutes();
    $store->markCommitting('post-2', ['phase' => 'create']);

    expect($store->committing('post-2')['since'])->toBe(now()->getTimestamp());
})->with('cache drivers');

it('binds a key to one account and reports another account as a collision', function (string $driver) {
    $store = cacheIdempotencyStoreOn($driver);

    $store->bindAccount('post-1', Platform::LinkedIn, 'urn:li:organization:1');
    $store->bindAccount('post-1', 'linkedin', 'urn:li:organization:1');

    try {
        $store->bindAccount('post-1', Platform::LinkedIn, 'urn:li:organization:2');
        $this->fail('Expected a key collision.');
    } catch (KeyCollisionException $e) {
        expect($e->reason)->toBe(FailureReason::Misconfigured)
            ->and($e->context['key_collision'])->toBeTrue()
            ->and($e->context['bound_platform'])->toBe('linkedin');
    }

    expect(fn () => $store->bindAccount('post-1', Platform::Facebook, 'urn:li:organization:1'))
        ->toThrow(KeyCollisionException::class);

    // The binding survives the claim and the publish.
    $store->claim('post-1', 'owner-a', 300);
    $store->markPublished('post-1', PostResult::success(Platform::LinkedIn, 'urn:li:share:1'));

    expect(fn () => $store->bindAccount('post-1', Platform::LinkedIn, 'urn:li:organization:2'))
        ->toThrow(KeyCollisionException::class)
        ->and($store->completed('post-1')->platformPostId)->toBe('urn:li:share:1');

    // A record written without a binding takes the first account it sees.
    $store->markPublished('post-2', PostResult::success(Platform::LinkedIn, 'urn:li:share:2'));
    $store->bindAccount('post-2', Platform::LinkedIn, 'urn:li:organization:2');
    expect(fn () => $store->bindAccount('post-2', Platform::LinkedIn, 'urn:li:organization:1'))
        ->toThrow(KeyCollisionException::class);
})->with('cache drivers');

it('expires records after social.idempotency_ttl', function (string $driver) {
    config(['social.idempotency_ttl' => 3600]);
    $store = cacheIdempotencyStoreOn($driver);
    $store->markPublished('post-1', PostResult::success(Platform::Facebook, '1_2'));

    $this->travel(3599)->seconds();
    expect($store->completed('post-1'))->not->toBeNull();

    // Each write restarts the TTL.
    $store->markPublished('post-1', PostResult::success(Platform::Facebook, '1_2'));
    $this->travel(3599)->seconds();
    expect($store->completed('post-1'))->not->toBeNull();

    $this->travel(2)->seconds();
    expect($store->completed('post-1'))->toBeNull()
        ->and($store->status('post-1'))->toBeNull();
})->with('cache drivers');

it('defaults to a seven-day TTL', function () {
    $store = new CacheIdempotencyStore;
    $store->markPublished('post-1', PostResult::success(Platform::Facebook, '1_2'));

    $this->travel(604799)->seconds();
    expect($store->completed('post-1'))->not->toBeNull();

    $this->travel(2)->seconds();
    expect($store->completed('post-1'))->toBeNull();
});

it('keeps records on social.idempotency_cache_store and leases on social.lock_store', function () {
    $dir = sys_get_temp_dir().'/sp-idempotency-'.bin2hex(random_bytes(6));
    $this->cacheDir = $dir;
    config([
        'cache.stores.file' => ['driver' => 'file', 'path' => $dir, 'lock_path' => $dir],
        'social.idempotency_cache_store' => 'file',
        'social.lock_store' => 'array',
    ]);

    $store = new CacheIdempotencyStore;
    $store->claim('post-1', 'owner-a', 300);
    $store->markPublished('post-1', PostResult::success(Platform::Threads, '1801'));

    $recordKey = 'social-poster:idempotency:'.hash('sha256', 'post-1');
    $leaseName = 'social-poster:idempotency-lease:'.hash('sha256', 'post-1');

    // The record is a plain array (no objects), on the file store only.
    expect(Cache::store('file')->get($recordKey))->toBeArray()
        ->and(Cache::store('file')->get($recordKey)['result']['platform_post_id'])->toBe('1801')
        ->and(Cache::store('array')->get($recordKey))->toBeNull();

    // The lease lives on the array store.
    expect(Cache::store('array')->getStore()->lock($leaseName, 10, 'someone-else')->get())->toBeFalse()
        ->and(Cache::store('file')->getStore()->lock($leaseName, 10, 'someone-else')->get())->toBeTrue();
});

it('takes explicit constructor settings over the config', function () {
    config(['social.idempotency_cache_store' => 'file']);

    $store = new CacheIdempotencyStore('array', 120, 'array');
    $store->markPublished('post-1', PostResult::success(Platform::X, '1'));

    expect(Cache::store('array')->get('social-poster:idempotency:'.hash('sha256', 'post-1')))->toBeArray();

    $this->travel(121)->seconds();
    expect($store->completed('post-1'))->toBeNull();
});

it('exposes the whole record for reconcile, with the state a failed record keeps', function (string $driver) {
    $store = cacheIdempotencyStoreOn($driver);
    Carbon::setTestNow('2026-11-18 12:00:00');

    expect($store->record('post-1'))->toBeNull();

    $store->bindAccount('post-1', Platform::Instagram, 'ig-1');
    $store->markCommitting('post-1', ['phase' => 'publish', 'container_id' => 'c-1']);

    expect($store->record('post-1'))->toBe([
        'status' => 'committing',
        'state' => ['phase' => 'publish', 'container_id' => 'c-1'],
        'platform' => 'instagram',
        'account' => 'ig-1',
        'since' => Carbon::now()->getTimestamp(),
        'updated_at' => Carbon::now()->getTimestamp(),
    ]);

    $store->markFailed('post-1', ['phase' => 'publish', '_committed' => true]);

    expect($store->record('post-1'))->toMatchArray(['status' => 'failed', 'state' => ['phase' => 'publish', '_committed' => true]]);

    Carbon::setTestNow();
})->with('cache drivers');
