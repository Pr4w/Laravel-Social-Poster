<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use SocialPoster\Console\PruneIdempotencyCommand;
use SocialPoster\Contracts\AtomicIdempotencyStore;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Idempotency\DatabaseIdempotencyStore;
use SocialPoster\Idempotency\KeyCollisionException;
use SocialPoster\ValueObjects\PostResult;

function idempotencyMigration(string $name): object
{
    return require __DIR__.'/../../../database/migrations/'.$name.'.php';
}

/** @return array<string, mixed> */
function idempotencyRow(string $key, string $table = 'social_idempotency'): array
{
    return (array) DB::table($table)->where('key', $key)->first();
}

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    $this->store = new DatabaseIdempotencyStore;
});

it('implements the atomic contract', function () {
    expect($this->store)->toBeInstanceOf(AtomicIdempotencyStore::class);
});

it('replays a published result with its state, id kind, url and payload', function () {
    $result = PostResult::success(
        Platform::Facebook,
        '123_456',
        'https://www.facebook.com/123_456',
        ['id' => '123_456', 'scheduled_publish_time' => 1800000000],
        PostResult::STATE_SCHEDULED,
        'post_id',
    )->withMetadata(['scheduled_post_id' => 9]);

    $this->store->markPublished('post-1', $result);
    $replayed = $this->store->completed('post-1');

    expect($replayed)->toBeInstanceOf(PostResult::class)
        ->and($replayed->platform)->toBe(Platform::Facebook)
        ->and($replayed->success)->toBeTrue()
        ->and($replayed->platformPostId)->toBe('123_456')
        ->and($replayed->url)->toBe('https://www.facebook.com/123_456')
        ->and($replayed->payload)->toBe(['id' => '123_456', 'scheduled_publish_time' => 1800000000])
        ->and($replayed->state)->toBe(PostResult::STATE_SCHEDULED)
        ->and($replayed->isScheduled())->toBeTrue()
        ->and($replayed->idKind)->toBe('post_id')
        ->and($replayed->metadata)->toBe([])
        ->and($this->store->status('post-1'))->toBe('published');
});

it('returns nothing for unknown or unfinished keys', function () {
    $this->store->markPending('post-1', ['phase' => 'poll']);

    expect($this->store->completed('post-1'))->toBeNull()
        ->and($this->store->completed('missing'))->toBeNull()
        ->and($this->store->status('missing'))->toBeNull()
        ->and($this->store->pendingState('missing'))->toBeNull()
        ->and($this->store->committing('missing'))->toBeNull();
});

it('lets exactly one of two owners claim a key, and renews the holder', function () {
    expect($this->store->claim('post-1', 'owner-a', 300))->toBeTrue()
        ->and($this->store->claim('post-1', 'owner-b', 300))->toBeFalse()
        ->and($this->store->claim('post-1', 'owner-a', 300))->toBeTrue();

    $row = idempotencyRow('post-1');

    expect($row['owner'])->toBe('owner-a')
        ->and((int) $row['attempts'])->toBe(2)
        ->and($row['status'])->toBe('pending')
        ->and($row['key_hash'])->toBe(hash('sha256', 'post-1'));
});

it('decides the race on an existing row with one conditional update', function () {
    // The row exists before either owner arrives (bound, or left by an earlier run).
    $this->store->markPending('post-1', ['phase' => 'poll', 'publish_id' => 'v_pub_1']);

    expect($this->store->claim('post-1', 'owner-a', 300))->toBeTrue()
        ->and($this->store->claim('post-1', 'owner-b', 300))->toBeFalse()
        ->and($this->store->pendingState('post-1'))->toBe(['phase' => 'poll', 'publish_id' => 'v_pub_1']);
});

it('hands the key to another owner once the lease expires', function () {
    expect($this->store->claim('post-1', 'owner-a', 60))->toBeTrue();

    $this->travel(30)->seconds();
    expect($this->store->claim('post-1', 'owner-b', 60))->toBeFalse();

    $this->travel(31)->seconds();
    expect($this->store->claim('post-1', 'owner-b', 60))->toBeTrue()
        ->and($this->store->claim('post-1', 'owner-a', 60))->toBeFalse();
});

it('lets a new owner take over a committing record whose lease expired', function () {
    $this->store->claim('post-1', 'owner-a', 60);
    $this->store->markCommitting('post-1', ['phase' => 'publish', 'container_id' => '17']);

    $this->travel(61)->seconds();

    expect($this->store->claim('post-1', 'owner-b', 60))->toBeTrue()
        ->and($this->store->committing('post-1')['state'])->toBe(['phase' => 'publish', 'container_id' => '17']);
});

it('releases a lease only for its owner', function () {
    $this->store->claim('post-1', 'owner-a', 300);

    $this->store->release('post-1', 'owner-b');
    expect($this->store->claim('post-1', 'owner-b', 300))->toBeFalse();

    $this->store->release('post-1', 'owner-a');
    expect($this->store->claim('post-1', 'owner-b', 300))->toBeTrue();
});

it('refuses claims on published and failed records until they are reopened or forgotten', function () {
    $this->store->markPublished('published', PostResult::success(Platform::X, '1'));
    expect($this->store->claim('published', 'owner-a', 300))->toBeFalse();

    $this->store->claim('failed', 'owner-a', 300);
    $this->store->markFailed('failed', ['phase' => 'tweet']);

    expect($this->store->status('failed'))->toBe('failed')
        ->and(idempotencyRow('failed')['owner'])->toBeNull()
        ->and($this->store->claim('failed', 'owner-b', 300))->toBeFalse();

    // A reconcile reopens it as committing, keeping the state to verify against.
    $this->store->markCommitting('failed', ['phase' => 'tweet']);
    expect($this->store->claim('failed', 'owner-b', 300))->toBeTrue();

    $this->store->markFailed('failed');
    $this->store->forget('failed');
    expect($this->store->claim('failed', 'owner-c', 300))->toBeTrue();
});

it('keeps the stored state when markFailed gets none', function () {
    $this->store->markCommitting('post-1', ['phase' => 'publish', 'creation_id' => '42']);
    $this->store->markFailed('post-1');

    expect(json_decode(idempotencyRow('post-1')['state'], true))->toBe(['phase' => 'publish', 'creation_id' => '42']);
});

it('never lets markCommitting, markPending or markFailed overwrite a published record', function () {
    $this->store->markPublished('post-1', PostResult::success(Platform::Instagram, '1789', 'https://www.instagram.com/p/abc/'));

    $this->store->markCommitting('post-1', ['phase' => 'publish']);
    $this->store->markPending('post-1', ['phase' => 'poll']);
    $this->store->markFailed('post-1', ['phase' => 'publish']);

    expect($this->store->status('post-1'))->toBe('published')
        ->and($this->store->completed('post-1')->platformPostId)->toBe('1789')
        ->and($this->store->committing('post-1'))->toBeNull()
        ->and($this->store->pendingState('post-1'))->toBeNull();
});

it('keeps the time a record first became committing', function () {
    $this->travelTo(Carbon::parse('2026-11-18 10:00:00'));
    $since = now()->getTimestamp();

    $this->store->markCommitting('post-1', ['phase' => 'publish', 'container_id' => '1']);
    $this->travel(5)->minutes();
    $this->store->markCommitting('post-1', ['phase' => 'publish', 'container_id' => '2']);

    expect($this->store->committing('post-1'))->toBe([
        'state' => ['phase' => 'publish', 'container_id' => '2'],
        'since' => $since,
    ])->and($this->store->status('post-1'))->toBe('committing')
        ->and($this->store->pendingState('post-1'))->toBeNull();

    // Leaving 'committing' resets the clock for the next commit phase.
    $this->store->markPending('post-1', ['phase' => 'await']);
    expect($this->store->committing('post-1'))->toBeNull()
        ->and($this->store->pendingState('post-1'))->toBe(['phase' => 'await']);

    $this->travel(1)->minutes();
    $this->store->markCommitting('post-1', ['phase' => 'publish']);
    expect($this->store->committing('post-1')['since'])->toBe(now()->getTimestamp());
});

it('keeps the first committing time when a failed record is reopened', function (bool $legacy) {
    if ($legacy) {
        // The table exactly as the v1.0 create migration built it (no committing_since column).
        Schema::create('v1_reopen', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('status')->index();
            $table->string('platform')->nullable();
            $table->string('platform_post_id')->nullable();
            $table->text('url')->nullable();
            $table->json('payload')->nullable();
            $table->json('state')->nullable();
            $table->timestamps();
        });
    }

    $store = $legacy ? new DatabaseIdempotencyStore('v1_reopen') : $this->store;
    $this->travelTo(Carbon::parse('2026-11-18 10:00:00'));
    $since = now()->getTimestamp();

    $store->markCommitting('post-1', ['phase' => 'create', '_commits' => true]);
    $this->travel(5)->minutes();
    $store->markFailed('post-1', ['phase' => 'create', '_commits' => true]);

    $this->travel(3)->hours();
    $store->markCommitting('post-1', ['phase' => 'create', '_commits' => true]);

    expect($store->committing('post-1'))->toBe(['state' => ['phase' => 'create', '_commits' => true], 'since' => $since]);

    // A failed record that never was committing starts its clock when reopened.
    $store->markPending('post-2', ['phase' => 'poll']);
    $store->markFailed('post-2', ['phase' => 'poll']);
    $this->travel(1)->minutes();
    $store->markCommitting('post-2', ['phase' => 'create']);

    expect($store->committing('post-2')['since'])->toBe(now()->getTimestamp());
})->with(['upgraded table' => [false], 'v1.0 table' => [true]]);

it('works on databases without insert-or-ignore, such as SQL Server', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Simulates SQL Server by swapping in an SQLite grammar.');
    }

    // A grammar like SqlServerGrammar: compiling insertOrIgnore throws.
    $connection = DB::connection();
    $grammar = new class($connection) extends SQLiteGrammar
    {
        public function compileInsertOrIgnore(QueryBuilder $query, array $values)
        {
            throw new RuntimeException('This database engine does not support inserting while ignoring errors.');
        }
    };

    if (method_exists($grammar, 'setConnection')) {
        $grammar->setConnection($connection); // Laravel 11
    }

    $connection->setQueryGrammar($grammar);
    $store = new DatabaseIdempotencyStore;

    expect($store->claim('post-1', 'owner-a', 300))->toBeTrue()
        ->and($store->claim('post-1', 'owner-b', 300))->toBeFalse()
        ->and($store->claim('post-1', 'owner-a', 300))->toBeTrue();

    $store->bindAccount('post-2', Platform::X, 'x-user-1');
    $store->bindAccount('post-2', Platform::X, 'x-user-1');
    expect(fn () => $store->bindAccount('post-2', Platform::X, 'x-user-2'))->toThrow(KeyCollisionException::class);

    $store->markCommitting('post-3', ['phase' => 'tweet']);
    $store->markPending('post-4', ['phase' => 'poll']);
    $store->markPublished('post-5', PostResult::success(Platform::X, '1850000000000000000'));
    $store->markFailed('post-6');

    expect($store->committing('post-3')['state'])->toBe(['phase' => 'tweet'])
        ->and($store->pendingState('post-4'))->toBe(['phase' => 'poll'])
        ->and($store->completed('post-5')->platformPostId)->toBe('1850000000000000000')
        ->and($store->status('post-6'))->toBe('failed')
        ->and(DB::table('social_idempotency')->count())->toBe(6);
});

it('forgets in-flight records but keeps published ones', function () {
    $this->store->markPending('pending', ['phase' => 'poll']);
    $this->store->markCommitting('committing', ['phase' => 'publish']);
    $this->store->markFailed('failed');
    $this->store->markPublished('published', PostResult::success(Platform::LinkedIn, 'urn:li:share:1'));

    foreach (['pending', 'committing', 'failed', 'published'] as $key) {
        $this->store->forget($key);
    }

    expect($this->store->status('pending'))->toBeNull()
        ->and($this->store->status('committing'))->toBeNull()
        ->and($this->store->status('failed'))->toBeNull()
        ->and($this->store->status('published'))->toBe('published')
        ->and($this->store->completed('published')->platformPostId)->toBe('urn:li:share:1');
});

it('stores keys longer than 255 characters under their hash', function () {
    $key = str_repeat('schedule:2026-11-18:', 20);
    $other = substr($key, 0, -1).'X';

    expect(strlen($key))->toBeGreaterThan(255)
        ->and($this->store->claim($key, 'owner-a', 300))->toBeTrue()
        ->and($this->store->claim($other, 'owner-b', 300))->toBeTrue();

    $this->store->markPublished($key, PostResult::success(Platform::Threads, '1801'));

    $row = (array) DB::table('social_idempotency')->where('key_hash', hash('sha256', $key))->first();

    expect($row['key'])->toBe('sha256:'.hash('sha256', $key))
        ->and(strlen($row['key']))->toBeLessThanOrEqual(255)
        ->and($this->store->completed($key)->platformPostId)->toBe('1801')
        ->and($this->store->completed($other))->toBeNull()
        ->and($this->store->status($other))->toBe('pending');
});

it('keeps created_at when a record is updated', function () {
    $this->travelTo(Carbon::parse('2026-11-18 09:00:00'));
    $this->store->claim('post-1', 'owner-a', 300);

    $this->travel(10)->minutes();
    $this->store->markPending('post-1', ['phase' => 'poll']);
    $this->store->markPublished('post-1', PostResult::success(Platform::TikTok, 'v_pub_1'));

    $row = idempotencyRow('post-1');

    expect(Carbon::parse($row['created_at'])->format('H:i'))->toBe('09:00')
        ->and(Carbon::parse($row['updated_at'])->format('H:i'))->toBe('09:10')
        ->and($row['owner'])->toBeNull()
        ->and($row['lease_until'])->toBeNull();
});

it('binds a key to one account and reports another account as a collision', function () {
    $this->store->bindAccount('post-1', Platform::Instagram, '17841400000000001');
    $this->store->bindAccount('post-1', 'instagram', '17841400000000001');

    expect(idempotencyRow('post-1'))->toMatchArray(['account' => '17841400000000001', 'platform' => 'instagram']);

    try {
        $this->store->bindAccount('post-1', Platform::Instagram, '17841400000000002');
        $this->fail('Expected a key collision.');
    } catch (KeyCollisionException $e) {
        expect($e->reason)->toBe(FailureReason::Misconfigured)
            ->and($e->platform)->toBe(Platform::Instagram)
            ->and($e->context['key_collision'])->toBeTrue()
            ->and($e->context['bound_platform'])->toBe('instagram')
            ->and($e->getMessage())->not->toContain('17841400000000001');
    }

    expect(fn () => $this->store->bindAccount('post-1', Platform::Facebook, '17841400000000001'))
        ->toThrow(KeyCollisionException::class);

    // The binding survives the publish: a replay for another account is refused.
    $this->store->claim('post-1', 'owner-a', 300);
    $this->store->markPublished('post-1', PostResult::success(Platform::Instagram, '1789'));

    expect(fn () => $this->store->bindAccount('post-1', Platform::Instagram, '17841400000000002'))
        ->toThrow(KeyCollisionException::class)
        ->and($this->store->completed('post-1')->platformPostId)->toBe('1789');
});

it('binds records that were written without an account', function () {
    $this->store->markPublished('post-1', PostResult::success(Platform::X, '1850000000000000000'));

    $this->store->bindAccount('post-1', Platform::X, 'x-user-1');

    expect(idempotencyRow('post-1')['account'])->toBe('x-user-1')
        ->and(fn () => $this->store->bindAccount('post-1', Platform::X, 'x-user-2'))->toThrow(KeyCollisionException::class);
});

it('writes to social.idempotency_connection', function () {
    config([
        'database.connections.idempotency' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'social.idempotency_connection' => 'idempotency',
    ]);

    idempotencyMigration('2026_01_01_000000_create_social_idempotency_table')->up();
    idempotencyMigration('2026_11_20_000000_add_claim_columns_to_social_idempotency_table')->up();

    $store = new DatabaseIdempotencyStore;
    $store->claim('post-1', 'owner-a', 300);
    $store->markPublished('post-1', PostResult::success(Platform::YouTube, 'dQw4w9WgXcQ'));

    expect(DB::connection('idempotency')->table('social_idempotency')->count())->toBe(1)
        ->and(DB::table('social_idempotency')->count())->toBe(0)
        ->and($store->completed('post-1')->platformPostId)->toBe('dQw4w9WgXcQ');

    config(['social.idempotency_connection' => null]);
});

it('keeps a v1.0 table working, then backfills key_hash when the 1.1 migration runs', function () {
    Log::spy();
    config(['social.idempotency_table' => 'v1_idempotency']);

    // The table exactly as the v1.0 create migration built it.
    Schema::create('v1_idempotency', function (Blueprint $table) {
        $table->id();
        $table->string('key')->unique();
        $table->string('status')->index();
        $table->string('platform')->nullable();
        $table->string('platform_post_id')->nullable();
        $table->text('url')->nullable();
        $table->json('payload')->nullable();
        $table->json('state')->nullable();
        $table->timestamps();
    });

    DB::table('v1_idempotency')->insert([
        [
            'key' => 'post-1:instagram', 'status' => 'published', 'platform' => 'instagram',
            'platform_post_id' => '17900000000000001', 'url' => 'https://www.instagram.com/p/abc/',
            'payload' => '{"id":"17900000000000001"}', 'state' => null, 'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'key' => 'post-2:tiktok', 'status' => 'pending', 'platform' => null,
            'platform_post_id' => null, 'url' => null, 'payload' => null,
            'state' => '{"phase":"poll","publish_id":"v_pub_url~v2.1"}', 'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    // Before the migration: v1.0 behaviour, with the 1.1 guards.
    $legacy = new DatabaseIdempotencyStore('v1_idempotency');

    expect($legacy->completed('post-1:instagram')->platformPostId)->toBe('17900000000000001')
        ->and($legacy->pendingState('post-2:tiktok'))->toBe(['phase' => 'poll', 'publish_id' => 'v_pub_url~v2.1']);

    $legacy->markPending('post-1:instagram', ['phase' => 'poll']);
    $legacy->forget('post-1:instagram');
    expect($legacy->status('post-1:instagram'))->toBe('published');

    // Claims fall back to cache locks.
    expect($legacy->claim('post-2:tiktok', 'owner-a', 300))->toBeTrue()
        ->and($legacy->claim('post-2:tiktok', 'owner-b', 300))->toBeFalse();
    $legacy->release('post-2:tiktok', 'owner-a');
    expect($legacy->claim('post-2:tiktok', 'owner-b', 300))->toBeTrue()
        ->and($legacy->claim('post-1:instagram', 'owner-b', 300))->toBeFalse();

    // State, id kind and committing time ride inside the JSON columns.
    $legacy->markPublished('post-3:facebook', PostResult::success(Platform::Facebook, '9_1', null, ['id' => '9_1'], PostResult::STATE_SCHEDULED, 'post_id'));
    $replayed = $legacy->completed('post-3:facebook');
    expect($replayed->state)->toBe('scheduled')
        ->and($replayed->idKind)->toBe('post_id')
        ->and($replayed->payload)->toBe(['id' => '9_1']);

    $legacy->markCommitting('post-4:x', ['phase' => 'tweet']);
    $since = $legacy->committing('post-4:x')['since'];
    expect($legacy->committing('post-4:x')['state'])->toBe(['phase' => 'tweet']);

    $long = str_repeat('k', 300);
    $legacy->markPending($long, ['phase' => 'poll']);
    expect($legacy->pendingState($long))->toBe(['phase' => 'poll']);

    // No account column yet: the binding is skipped, not an error.
    $legacy->bindAccount('post-1:instagram', Platform::Instagram, 'ig-2');

    Log::shouldHaveReceived('warning')->once();

    $migration = idempotencyMigration('2026_11_20_000000_add_claim_columns_to_social_idempotency_table');
    $migration->up();

    expect(Schema::hasColumns('v1_idempotency', [
        'key_hash', 'owner', 'lease_until', 'account', 'attempts', 'result_state', 'id_kind', 'committing_since',
    ]))->toBeTrue();

    foreach (['post-1:instagram', 'post-2:tiktok', 'post-3:facebook', 'post-4:x'] as $key) {
        expect(idempotencyRow($key, 'v1_idempotency')['key_hash'])->toBe(hash('sha256', $key));
    }

    expect(idempotencyRow('sha256:'.hash('sha256', $long), 'v1_idempotency')['key_hash'])->toBe(hash('sha256', $long));

    $unique = collect(Schema::getIndexes('v1_idempotency'))
        ->first(fn (array $index) => $index['unique'] && $index['columns'] === ['key_hash']);
    expect($unique)->not->toBeNull();

    // After the migration: full mode on the same rows.
    $upgraded = new DatabaseIdempotencyStore('v1_idempotency');

    expect($upgraded->completed('post-1:instagram')->platformPostId)->toBe('17900000000000001')
        ->and($upgraded->completed('post-3:facebook')->state)->toBe('scheduled')
        ->and($upgraded->committing('post-4:x'))->toBe(['state' => ['phase' => 'tweet'], 'since' => $since])
        ->and($upgraded->pendingState($long))->toBe(['phase' => 'poll'])
        ->and($upgraded->claim('post-4:x', 'owner-c', 300))->toBeTrue()
        ->and($upgraded->claim('post-4:x', 'owner-d', 300))->toBeFalse();

    $upgraded->bindAccount('post-1:instagram', Platform::Instagram, 'ig-1');
    expect(fn () => $upgraded->bindAccount('post-1:instagram', Platform::Instagram, 'ig-2'))
        ->toThrow(KeyCollisionException::class);

    // Re-running is a no-op; rolling back drops only what it added.
    $migration->up();
    $migration->down();

    expect(Schema::hasColumn('v1_idempotency', 'key_hash'))->toBeFalse()
        ->and(Schema::hasColumn('v1_idempotency', 'owner'))->toBeFalse()
        ->and(DB::table('v1_idempotency')->count())->toBe(5);

    config(['social.idempotency_table' => 'social_idempotency']);
});

it('creates the full schema on a fresh install and the upgrade migration leaves it alone', function () {
    expect(Schema::hasColumns('social_idempotency', [
        'key', 'key_hash', 'status', 'platform', 'account', 'platform_post_id', 'url', 'result_state', 'id_kind',
        'payload', 'state', 'owner', 'lease_until', 'attempts', 'committing_since', 'created_at', 'updated_at',
    ]))->toBeTrue();

    $uniqueOnHash = collect(Schema::getIndexes('social_idempotency'))
        ->filter(fn (array $index) => $index['unique'] && $index['columns'] === ['key_hash']);

    expect($uniqueOnHash)->toHaveCount(1);
});

it('prunes old rows and keeps live leases', function () {
    app(Kernel::class)->registerCommand(app(PruneIdempotencyCommand::class));

    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $this->store->markPublished('old-published', PostResult::success(Platform::Facebook, '1'));
    $this->store->markPending('old-pending', ['phase' => 'poll']);
    $this->store->markFailed('old-failed');
    $this->store->claim('old-leased', 'owner-a', 86400 * 60);

    $this->travelTo(Carbon::parse('2026-11-18 12:00:00'));
    $this->store->markPublished('fresh', PostResult::success(Platform::Facebook, '2'));

    $this->artisan('social-poster:prune-idempotency', ['--dry-run' => true])
        ->expectsOutputToContain('Would delete 3')
        ->assertSuccessful();
    expect(DB::table('social_idempotency')->count())->toBe(5);

    $this->artisan('social-poster:prune-idempotency', ['--status' => ['pending']])
        ->expectsOutputToContain('Deleted 1')
        ->assertSuccessful();
    expect($this->store->status('old-pending'))->toBeNull()
        ->and($this->store->status('old-published'))->toBe('published');

    $this->artisan('social-poster:prune-idempotency', ['--days' => 30])->assertSuccessful();

    expect($this->store->status('old-published'))->toBeNull()
        ->and($this->store->status('old-failed'))->toBeNull()
        ->and($this->store->status('old-leased'))->toBe('pending')
        ->and($this->store->status('fresh'))->toBe('published');
});

it('rejects bad prune options and tolerates a missing table', function () {
    app(Kernel::class)->registerCommand(app(PruneIdempotencyCommand::class));

    $this->artisan('social-poster:prune-idempotency', ['--status' => ['done']])->assertExitCode(2);
    $this->artisan('social-poster:prune-idempotency', ['--days' => '-1'])->assertExitCode(2);

    config(['social.idempotency_table' => 'no_such_table']);
    $this->artisan('social-poster:prune-idempotency')
        ->expectsOutputToContain('nothing to prune')
        ->assertSuccessful();
    config(['social.idempotency_table' => 'social_idempotency']);
});

it('exposes the whole record for reconcile, without the internal envelope', function () {
    Carbon::setTestNow('2026-11-18 12:00:00');

    expect($this->store->record('post-1'))->toBeNull();

    $this->store->bindAccount('post-1', Platform::Instagram, 'ig-1');
    $this->store->markCommitting('post-1', ['phase' => 'publish', 'container_id' => 'c-1']);

    expect($this->store->record('post-1'))->toBe([
        'status' => 'committing',
        'state' => ['phase' => 'publish', 'container_id' => 'c-1'],
        'platform' => 'instagram',
        'account' => 'ig-1',
        'since' => Carbon::now()->getTimestamp(),
        'updated_at' => Carbon::now()->getTimestamp(),
    ]);

    $this->store->markFailed('post-1', ['phase' => 'publish', '_committed' => true]);

    expect($this->store->record('post-1'))->toMatchArray([
        'status' => 'failed',
        'state' => ['phase' => 'publish', '_committed' => true],
        'since' => null,
    ]);

    $this->store->markPublished('post-1', PostResult::success(Platform::Instagram, '1790'));

    expect($this->store->record('post-1'))->toMatchArray(['status' => 'published', 'state' => null]);

    Carbon::setTestNow();
});
