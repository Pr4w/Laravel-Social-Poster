<?php

namespace SocialPoster\Tests\Database;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Throwable;

/**
 * Runs a closure in several forked processes released at the same instant,
 * for what must hold under real concurrency (one claim winner per key) and
 * that a single process can never show: sequential calls never race.
 *
 * Each worker opens its own database and cache connections. The parent's are
 * closed before forking: a connection shared by two processes would mix their
 * traffic, and a child closing an inherited MySQL or Redis socket would also
 * close the parent's session. Workers report back through files and then kill
 * themselves, so the copy of the test runner inside them never runs shutdown
 * handlers, destructors or prints anything.
 */
final class ForkedWorkers
{
    public static function supported(): bool
    {
        return function_exists('pcntl_fork') && function_exists('posix_kill') && function_exists('pcntl_waitpid');
    }

    /**
     * @template TContext
     *
     * @param  Closure(string $owner, TContext $context): array<mixed>  $work  Runs in each worker once all are released; its result must be JSON-encodable.
     * @param  (Closure(string $owner): TContext)|null  $prepare  Runs in each worker before the release (open connections here, so connecting does not stagger the start).
     * @param  list<string>  $connections  Database connections to close before forking.
     * @param  list<string>  $cacheStores  Cache stores to drop before forking.
     * @return array<string, array<mixed>> What each worker returned, keyed by its owner name (worker-1, worker-2...).
     */
    public static function run(int $workers, Closure $work, ?Closure $prepare = null, array $connections = [], array $cacheStores = []): array
    {
        foreach ($connections as $name) {
            DB::purge($name);
        }

        foreach ($cacheStores as $store) {
            Cache::forgetDriver($store);
        }

        if (app()->resolved('redis')) {
            foreach (array_keys(app('redis')->connections() ?? []) as $name) {
                app('redis')->purge($name);
            }
        }

        $dir = sys_get_temp_dir().'/sp-workers-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($dir);

        // Long enough for every fork and its connection to be ready.
        $releaseAt = microtime(true) + 0.5 + 0.1 * $workers;
        $pids = [];

        try {
            for ($i = 1; $i <= $workers; $i++) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('Could not fork a worker process.');
                }

                if ($pid === 0) {
                    self::worker($dir, 'worker-'.$i, $releaseAt, $work, $prepare);
                }

                $pids[] = $pid;
            }
        } finally {
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
            }
        }

        $results = [];
        $errors = [];

        for ($i = 1; $i <= $workers; $i++) {
            $owner = 'worker-'.$i;

            if (is_file($dir.'/'.$owner.'.error')) {
                $errors[$owner] = (string) file_get_contents($dir.'/'.$owner.'.error');

                continue;
            }

            $result = is_file($dir.'/'.$owner.'.json') ? json_decode((string) file_get_contents($dir.'/'.$owner.'.json'), true) : null;

            if (! is_array($result)) {
                $errors[$owner] = 'The worker exited without a result.';

                continue;
            }

            $results[$owner] = $result;
        }

        File::deleteDirectory($dir);

        Assert::assertSame([], $errors, 'A worker process failed.');

        return $results;
    }

    /**
     * @param  Closure(string, mixed): array<mixed>  $work
     * @param  (Closure(string): mixed)|null  $prepare
     */
    private static function worker(string $dir, string $owner, float $releaseAt, Closure $work, ?Closure $prepare): never
    {
        try {
            $context = $prepare === null ? null : $prepare($owner);

            while (microtime(true) < $releaseAt) {
                usleep(100);
            }

            file_put_contents($dir.'/'.$owner.'.json', json_encode($work($owner, $context), JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            file_put_contents($dir.'/'.$owner.'.error', $e::class.': '.$e->getMessage());
        }

        posix_kill(getmypid(), SIGKILL);

        exit(1);
    }
}
