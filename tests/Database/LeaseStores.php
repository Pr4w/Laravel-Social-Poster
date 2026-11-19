<?php

namespace SocialPoster\Tests\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lock-capable cache stores the lease tests run on: the file store
 * everywhere, redis and memcached when REDIS_HOST / MEMCACHED_HOST point at a
 * server (the CI database jobs), and the database cache store when the suite
 * runs on a database server (an in-memory SQLite database is not shared
 * between processes).
 */
final class LeaseStores
{
    /** @return list<string> */
    public static function available(): array
    {
        $stores = ['file'];

        if (self::env('REDIS_HOST') !== null) {
            $stores[] = 'redis';
        }

        if (self::env('MEMCACHED_HOST') !== null) {
            $stores[] = 'memcached';
        }

        if (in_array(self::env('DB_CONNECTION'), ['mysql', 'mariadb', 'pgsql', 'sqlsrv'], true)) {
            $stores[] = 'database';
        }

        return $stores;
    }

    /**
     * Point social.idempotency_cache_store (and the leases) at $store, with a
     * short record TTL so nothing lingers on a shared server. Returns the
     * directory the file store writes to, for the caller to delete.
     */
    public static function configure(string $store): ?string
    {
        $dir = null;

        if ($store === 'file') {
            $dir = sys_get_temp_dir().'/sp-lease-store-'.bin2hex(random_bytes(6));
            config(['cache.stores.file' => ['driver' => 'file', 'path' => $dir, 'lock_path' => $dir]]);
        }

        if ($store === 'database') {
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

        config(['social.idempotency_cache_store' => $store, 'social.lock_store' => null, 'social.idempotency_ttl' => 120]);

        return $dir;
    }

    /** Whether $store keeps time on its server, where only a real wait expires a lease. */
    public static function serverClock(string $store): bool
    {
        return in_array($store, ['redis', 'memcached'], true);
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
