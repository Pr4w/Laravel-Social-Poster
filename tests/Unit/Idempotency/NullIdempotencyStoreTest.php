<?php

use SocialPoster\Contracts\AtomicIdempotencyStore;
use SocialPoster\Contracts\IdempotencyStore;
use SocialPoster\Enums\Platform;
use SocialPoster\Idempotency\NullIdempotencyStore;
use SocialPoster\ValueObjects\PostResult;

it('implements both store contracts', function () {
    expect(new NullIdempotencyStore)
        ->toBeInstanceOf(IdempotencyStore::class)
        ->toBeInstanceOf(AtomicIdempotencyStore::class);
});

it('remembers nothing', function () {
    $store = new NullIdempotencyStore;

    $store->markPublished('post-1', PostResult::success(Platform::Facebook, '1_2'));
    $store->markPending('post-2', ['phase' => 'poll']);
    $store->markCommitting('post-3', ['phase' => 'publish']);
    $store->markFailed('post-4', ['phase' => 'publish']);
    $store->bindAccount('post-1', Platform::Facebook, 'page-1');
    $store->bindAccount('post-1', Platform::Facebook, 'page-2');
    $store->forget('post-1');

    expect($store->completed('post-1'))->toBeNull()
        ->and($store->pendingState('post-2'))->toBeNull()
        ->and($store->committing('post-3'))->toBeNull()
        ->and($store->status('post-4'))->toBeNull();
});

it('never refuses a claim', function () {
    $store = new NullIdempotencyStore;

    expect($store->claim('post-1', 'owner-a', 300))->toBeTrue()
        ->and($store->claim('post-1', 'owner-b', 300))->toBeTrue();

    $store->release('post-1', 'owner-a');
    expect($store->claim('post-1', 'owner-c', 300))->toBeTrue();
});
