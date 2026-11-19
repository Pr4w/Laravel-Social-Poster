<?php

namespace SocialPoster\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;
use SocialPoster\Facades\SocialPoster;
use SocialPoster\SocialPosterServiceProvider;

abstract class TestCase extends Orchestra
{
    /** Drivers DB_CONNECTION may name to run the suite on a database server instead of in-memory SQLite. */
    private const SERVER_DATABASES = ['mysql', 'mariadb', 'pgsql', 'sqlsrv'];

    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach a real API: any request without a matching fake fails.
        Http::preventStrayRequests();

        if (static::serverDatabase() !== null) {
            $this->resetServerDatabase();
        }
    }

    protected function getPackageProviders($app): array
    {
        return [SocialPosterServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['SocialPoster' => SocialPoster::class];
    }

    protected function defineEnvironment($app): void
    {
        // In-memory SQLite unless DB_CONNECTION names a server (the CI database
        // jobs run the idempotency tests on MySQL, MariaDB and PostgreSQL).
        $app['config']->set('database.default', static::serverDatabase() ?? 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('social.error_logging', false);
    }

    /** Absolute path to a file under tests/Fixtures. */
    protected function fixture(string $path): string
    {
        return __DIR__.'/Fixtures/'.ltrim($path, '/');
    }

    /**
     * The database server connection DB_CONNECTION selects (mysql, mariadb,
     * pgsql, sqlsrv), or null for the default in-memory SQLite.
     */
    protected static function serverDatabase(): ?string
    {
        $connection = getenv('DB_CONNECTION');
        $connection = is_string($connection) && $connection !== '' ? $connection : ($_ENV['DB_CONNECTION'] ?? null);

        return in_array($connection, self::SERVER_DATABASES, true) ? $connection : null;
    }

    /**
     * A server database outlives the test, unlike :memory:: drop every table
     * so each test starts from an empty schema, as it does on SQLite. Only a
     * database whose name says it is for tests is ever wiped.
     */
    private function resetServerDatabase(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_contains(strtolower($database), 'test')) {
            throw new RuntimeException('Refusing to run the tests on the "'.$database.'" database: every table in it is '
                .'dropped before each test. Point DB_DATABASE at a database whose name contains "test".');
        }

        Schema::dropAllTables();
    }
}
