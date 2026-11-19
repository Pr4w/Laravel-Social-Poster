<?php

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use SocialPoster\Idempotency\CacheIdempotencyStore;
use SocialPoster\Idempotency\DatabaseIdempotencyStore;
use SocialPoster\Tests\Database\ForkedWorkers;
use SocialPoster\Tests\Database\LeaseStores;

/*
 * The atomic claim under real concurrency: forked workers, each on its own
 * connection, race for the same keys, and exactly one may win each key. A
 * second worker that also "wins" would publish the same post twice.
 *
 * DatabaseIdempotencyStore settles the race inside the database (an
 * insert-or-ignore on the unique key, or one conditional UPDATE whose
 * affected-row count decides), so the database tests run on the suite's own
 * database: the CI database jobs point DB_CONNECTION at MySQL, MariaDB and
 * PostgreSQL. On SQLite they use a temporary file, since an in-memory database
 * cannot be shared between processes. The cache-store race runs on every
 * store LeaseStores lists (file; redis, memcached and database in CI).
 */

beforeEach(function () {
    if (! ForkedWorkers::supported()) {
        $this->markTestSkipped('Needs the pcntl and posix extensions.');
    }
});

afterEach(function () {
    foreach (['raceFile', 'raceCacheDir'] as $property) {
        if (! is_string($this->{$property} ?? null)) {
            continue;
        }

        is_dir($this->{$property})
            ? File::deleteDirectory($this->{$property})
            : File::delete([$this->{$property}, $this->{$property}.'-wal', $this->{$property}.'-shm']);
    }
});

/**
 * Configure a 'race' connection that every process can open (the suite's
 * server database, or a temporary SQLite file), create the 1.1 table on it
 * with the package migrations, and return a store on it.
 */
function claimRaceDatabaseStore(): DatabaseIdempotencyStore
{
    $config = config('database.connections.'.config('database.default'));

    if (($config['driver'] ?? null) === 'sqlite') {
        $file = sys_get_temp_dir().'/sp-claim-race-'.bin2hex(random_bytes(6)).'.sqlite';
        touch($file);
        test()->raceFile = $file;

        $config = [
            'driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true,
            // Writers queue for the file lock instead of failing at once.
            'busy_timeout' => 10000, 'journal_mode' => 'wal',
        ];
    }

    config(['database.connections.race' => $config, 'social.idempotency_connection' => 'race']);

    foreach (['2026_01_01_000000_create_social_idempotency_table', '2026_11_20_000000_add_claim_columns_to_social_idempotency_table'] as $migration) {
        (require __DIR__.'/../../database/migrations/'.$migration.'.php')->up();
    }

    return new DatabaseIdempotencyStore('social_idempotency', 'race');
}

/**
 * The owners that claimed $key, from what each worker reported.
 *
 * @param  array<string, array<mixed>>  $claimed
 * @return list<string>
 */
function claimRaceWinners(array $claimed, string $key): array
{
    return array_keys(array_filter($claimed, fn (array $keys) => in_array($key, $keys, true)));
}

dataset('lease stores', fn () => LeaseStores::available());

it('lets exactly one of several racing workers claim each key in the database', function () {
    $store = claimRaceDatabaseStore();
    $range = range(1, 25);

    // No row yet: the insert decides.
    $fresh = array_map(fn (int $i) => 'fresh-'.$i, $range);
    // A row without an owner (bound, or left pending by an earlier run): the UPDATE decides.
    $unowned = array_map(fn (int $i) => 'unowned-'.$i, $range);
    // A dead worker's lease has run out: the UPDATE decides the takeover.
    $expired = array_map(fn (int $i) => 'expired-'.$i, $range);

    foreach ($unowned as $key) {
        $store->markPending($key, ['phase' => 'poll']);
    }

    foreach ($expired as $key) {
        $store->claim($key, 'dead-worker', 60);
    }

    DB::connection('race')->table('social_idempotency')->whereIn('key', $expired)->update(['lease_until' => now()->subMinute()]);

    $keys = [...$fresh, ...$unowned, ...$expired];

    $claimed = ForkedWorkers::run(6, function (string $owner, DatabaseIdempotencyStore $store) use ($keys): array {
        $won = [];

        foreach ($keys as $key) {
            if ($store->claim($key, $owner, 300)) {
                $won[] = $key;
            }
        }

        return $won;
    }, prepare: function (string $owner): DatabaseIdempotencyStore {
        $store = new DatabaseIdempotencyStore('social_idempotency', 'race');
        // Connect and read the schema before the release.
        $store->release('warm-up', $owner);

        return $store;
    }, connections: ['race']);

    $rows = DB::connection('race')->table('social_idempotency')->whereIn('key', $keys)->get()->keyBy('key');

    foreach ($keys as $key) {
        $winners = claimRaceWinners($claimed, $key);

        expect($winners)->toHaveCount(1, $key.' was claimed by '.count($winners).' workers: '.implode(', ', $winners))
            ->and($rows[$key]->owner)->toBe($winners[0])
            ->and((int) $rows[$key]->attempts)->toBe(str_starts_with($key, 'expired-') ? 2 : 1);
    }
});

it('catches a claim that reads and then writes, so the race above is a real one', function () {
    claimRaceDatabaseStore();
    $keys = array_map(fn (int $i) => 'contended-'.$i, range(1, 8));

    foreach ($keys as $key) {
        DB::connection('race')->table('social_idempotency')->insert(['key' => $key, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    }

    // The window DatabaseIdempotencyStore closes with one conditional UPDATE:
    // read the owner, decide, then write.
    $claimed = ForkedWorkers::run(6, function (string $owner, ConnectionInterface $db) use ($keys): array {
        $won = [];

        foreach ($keys as $key) {
            $free = $db->table('social_idempotency')->where('key', $key)->value('owner') === null;
            usleep(20000);

            if ($free) {
                $db->table('social_idempotency')->where('key', $key)->update(['owner' => $owner]);
                $won[] = $key;
            }
        }

        return $won;
    }, prepare: function (): ConnectionInterface {
        $db = DB::connection('race');
        $db->getPdo();

        return $db;
    }, connections: ['race']);

    $claimedTwice = array_filter($keys, fn (string $key) => count(claimRaceWinners($claimed, $key)) > 1);

    expect($claimedTwice)->not->toBeEmpty();
});

it('lets exactly one of several racing workers claim each key on the cache store', function (string $driver) {
    $this->raceCacheDir = LeaseStores::configure($driver);
    $run = bin2hex(random_bytes(6));
    $keys = array_map(fn (int $i) => 'race-'.$run.'-'.$i, range(1, 25));

    $claimed = ForkedWorkers::run(6, function (string $owner, CacheIdempotencyStore $store) use ($keys): array {
        $won = [];

        foreach ($keys as $key) {
            if ($store->claim($key, $owner, 300)) {
                $won[] = $key;
            }
        }

        return $won;
    }, prepare: function (string $owner) use ($driver, $run): CacheIdempotencyStore {
        $store = new CacheIdempotencyStore($driver);
        // Connect the record and lock connections before the release.
        $store->status('warm-up-'.$run);
        $store->release('warm-up-'.$run, $owner);

        return $store;
    }, connections: $driver === 'database' ? [(string) config('database.default')] : [], cacheStores: [$driver]);

    $store = new CacheIdempotencyStore($driver);

    foreach ($keys as $key) {
        $winners = claimRaceWinners($claimed, $key);

        expect($winners)->toHaveCount(1, $key.' was claimed by '.count($winners).' workers: '.implode(', ', $winners))
            ->and($store->claim($key, 'someone-else', 300))->toBeFalse()
            ->and($store->claim($key, $winners[0], 300))->toBeTrue()
            ->and($store->status($key))->toBe('pending');

        $store->forget($key);
    }
})->with('lease stores');
