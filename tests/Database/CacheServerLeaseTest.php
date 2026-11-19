<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use SocialPoster\Enums\Platform;
use SocialPoster\Idempotency\CacheIdempotencyStore;
use SocialPoster\Tests\Database\LeaseStores;
use SocialPoster\ValueObjects\PostResult;

/*
 * CacheIdempotencyStore leases on the stores production uses. A lease is a
 * cache lock owned by the chain token: renewing it goes through
 * restoreLock()->release(), which each driver implements on its own (a Lua
 * compare-and-delete on redis, get-then-delete on memcached, an owner-checked
 * DELETE on the database store). The unit tests cover array, file and database
 * on SQLite with frozen time; these run on the servers too, where a lease only
 * expires on the server's clock. Every key is unique to the run, so a shared
 * server is never flushed.
 */

dataset('lease stores', fn () => LeaseStores::available());

afterEach(function () {
    if (is_string($this->leaseDir ?? null)) {
        File::deleteDirectory($this->leaseDir);
    }
});

it('keeps a lease for its owner until it is released, and never past a final record', function (string $driver) {
    $this->leaseDir = LeaseStores::configure($driver);
    $store = new CacheIdempotencyStore;
    $run = bin2hex(random_bytes(6));
    $key = 'lease-'.$run;

    expect($store->claim($key, 'owner-a', 300))->toBeTrue()
        ->and($store->claim($key, 'owner-b', 300))->toBeFalse()
        // The holder renews through restoreLock()->release() and a new lock.
        ->and($store->claim($key, 'owner-a', 300))->toBeTrue()
        ->and($store->claim($key, 'owner-b', 300))->toBeFalse();

    // Only the holder can release.
    $store->release($key, 'owner-b');
    expect($store->claim($key, 'owner-b', 300))->toBeFalse();

    $store->release($key, 'owner-a');
    expect($store->claim($key, 'owner-b', 300))->toBeTrue();

    // A published record is replayed, never claimed again.
    $store->markPublished($key, PostResult::success(Platform::X, '1850000000000000000'));
    expect($store->claim($key, 'owner-c', 300))->toBeFalse()
        ->and($store->completed($key)?->platformPostId)->toBe('1850000000000000000');

    // A failed record frees its lease, refuses claims, and forget() reopens the key.
    $failed = 'failed-'.$run;
    expect($store->claim($failed, 'owner-a', 300))->toBeTrue();
    $store->markFailed($failed, ['phase' => 'tweet']);
    expect($store->claim($failed, 'owner-b', 300))->toBeFalse();

    $store->forget($failed);
    expect($store->claim($failed, 'owner-b', 300))->toBeTrue();
    $store->forget($failed);

    if ($driver === 'redis') {
        // Records expire (social.idempotency_ttl) instead of piling up on the server.
        $redis = Cache::store('redis')->getStore();
        $ttl = $redis->connection()->ttl($redis->getPrefix().'social-poster:idempotency:'.hash('sha256', $key));

        expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(120);
    }
})->with('lease stores');

it("ends a dead worker's lease when it expires", function (string $driver) {
    $this->leaseDir = LeaseStores::configure($driver);
    $store = new CacheIdempotencyStore;
    $key = 'expiring-'.bin2hex(random_bytes(6));

    expect($store->claim($key, 'dead-worker', 1))->toBeTrue();

    if (LeaseStores::serverClock($driver)) {
        // Memcached counts expiry in whole seconds: allow for its rounding.
        usleep(2_500_000);
    } else {
        $this->travel(2)->seconds();
    }

    expect($store->claim($key, 'owner-b', 300))->toBeTrue()
        ->and($store->claim($key, 'dead-worker', 300))->toBeFalse();

    $store->forget($key);
})->with('lease stores');
