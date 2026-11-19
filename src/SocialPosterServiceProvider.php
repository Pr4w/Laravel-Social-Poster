<?php

namespace SocialPoster;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use SocialPoster\Console\PruneIdempotencyCommand;
use SocialPoster\Console\PruneTempMediaCommand;
use SocialPoster\Console\ReconcileCommand;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Contracts\IdempotencyStore;
use SocialPoster\Contracts\MediaGateway;
use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Contracts\MediaTransformer;
use SocialPoster\Enums\Platform;
use SocialPoster\Gateways\LocalMediaGateway;
use SocialPoster\Idempotency\CacheIdempotencyStore;
use SocialPoster\Idempotency\DatabaseIdempotencyStore;
use SocialPoster\Idempotency\NullIdempotencyStore;
use SocialPoster\Inspectors\FFProbeInspector;
use SocialPoster\Jobs\AddFirstCommentJob;
use SocialPoster\Jobs\PublishToConnectionJob;
use SocialPoster\Resolvers\IdentityCredentialsResolver;
use SocialPoster\Transformers\NullMediaTransformer;
use Throwable;

/**
 * Wires the package into the container. Everything config-driven is read when
 * a binding is first resolved, not when the provider registers, so a test or
 * an Octane worker that changes config('social.*') before using the package
 * gets what it configured.
 */
class SocialPosterServiceProvider extends ServiceProvider
{
    /** Graph API version the Meta drivers are tested against; older ones get a boot warning. */
    public const MIN_GRAPH_VERSION = 'v25.0';

    /** Config subtrees deep-merged with the package defaults (maps only; lists are replaced). */
    protected const DEEP_MERGED = ['api', 'async', 'sync', 'media'];

    /** Boot warnings already logged by this process. */
    protected static bool $warned = false;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/social.php', 'social');
        $this->mergeNestedDefaults();

        $this->app->singleton(MediaInspector::class, function ($app) {
            $class = $app['config']->get('social.inspector', FFProbeInspector::class);

            return $app->make($class);
        });

        $this->app->singleton(MediaGateway::class, function ($app) {
            $class = $app['config']->get('social.gateway', LocalMediaGateway::class);

            return $app->make($class);
        });

        $this->app->singleton(MediaTransformer::class, function ($app) {
            $class = $app['config']->get('social.transformer') ?: NullMediaTransformer::class;

            return $app->make($class);
        });

        // Not shared: resolved per job run from the current config, so a config
        // change (tests, Octane) applies at once. Bind your own singleton to
        // keep one instance.
        $this->app->bind(CredentialsResolver::class, function ($app) {
            $class = $app['config']->get('social.credentials_resolver') ?: IdentityCredentialsResolver::class;

            return is_object($class) ? $class : $app->make($class);
        });

        $this->app->singleton(SocialManager::class, function ($app) {
            $manager = new SocialManager($app);

            // Lazy: only the class name is captured; the driver is built the
            // first time its platform is requested.
            foreach (SocialManager::driverMap((array) $app['config']->get('social.drivers', [])) as $name => $class) {
                $manager->extend($name, fn ($app) => $app->make($class));
            }

            return $manager;
        });

        $this->app->singleton(IdempotencyStore::class, function ($app) {
            $config = $app['config'];
            $class = $config->get('social.idempotency') ?: CacheIdempotencyStore::class;

            if (is_object($class)) {
                return $class;
            }

            if ($class === DatabaseIdempotencyStore::class) {
                return new DatabaseIdempotencyStore(
                    (string) $config->get('social.idempotency_table', 'social_idempotency'),
                    $config->get('social.idempotency_connection'),
                );
            }

            // CacheIdempotencyStore reads its store, TTL and lock store lazily.
            return $app->make($class);
        });

        // Config is read per builder (lazily), so runtime config changes and
        // Octane workers see the current values.
        $this->app->singleton(Poster::class, fn ($app) => new Poster(
            $app->make(SocialManager::class),
            $app->make(Dispatcher::class),
            [],
            true,
        ));
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/social.php' => $this->app->configPath('social.php'),
        ], 'social-poster-config');

        // The whole directory, so the 1.1 upgrade migration ships too. The
        // create migration no-ops when the table exists, so re-publishing is safe.
        $migrations = [__DIR__.'/../database/migrations' => $this->app->databasePath('migrations')];

        $this->publishesMigrations($migrations, 'social-poster-migrations');

        $this->commands([
            PruneTempMediaCommand::class,
            PruneIdempotencyCommand::class,
            ReconcileCommand::class,
        ]);

        if (! static::$warned && ! $this->app->runningUnitTests()) {
            static::$warned = true;
            $this->logConfigurationWarnings();
        }
    }

    /**
     * Configuration problems worth a warning: settings that lose posts or post
     * twice without any error. Logged once per console process (queue workers,
     * artisan) at boot; tests and health checks can call this directly.
     *
     * @return list<string>
     */
    public function configurationWarnings(): array
    {
        $config = $this->app['config'];
        $warnings = [];

        try {
            $connection = $this->queueConnection($config);
            $driver = $config->get("queue.connections.{$connection}.driver");

            $store = $config->get('social.idempotency') ?: CacheIdempotencyStore::class;
            $storeClass = is_object($store) ? $store::class : (string) $store;

            if (is_a($storeClass, NullIdempotencyStore::class, true) && $driver !== null && $driver !== 'sync') {
                $warnings[] = "social.idempotency is NullIdempotencyStore on the asynchronous '{$connection}' queue connection: "
                    .'a redelivered or retried publish posts again. Use CacheIdempotencyStore (the default) or DatabaseIdempotencyStore.';
            }

            if ($driver !== null && $driver !== 'sync') {
                foreach ($this->perProcessCacheStores($config, is_a($storeClass, CacheIdempotencyStore::class, true)) as $name => [$cacheDriver, $uses, $settings]) {
                    $warnings[] = "The '{$name}' cache store uses the '{$cacheDriver}' driver, which "
                        .($cacheDriver === 'null' ? 'keeps nothing and grants every lock' : 'keeps its entries in one process')
                        .', yet it holds the '.Arr::join($uses, ', the ', ' and the ')." for the asynchronous '{$connection}' queue connection: "
                        .'a redelivered, retried or re-dispatched publish on another worker (or after a restart) sees none of them '
                        .'and can post twice. Point '.Arr::join($settings, ', ', ' and ')
                        .($name === $config->get('cache.default') ? ' (or cache.default)' : '')
                        .' at a store every worker shares (redis, database, memcached).';
                }
            }

            $retryAfter = $config->get("queue.connections.{$connection}.retry_after");
            $timeout = $this->longestJobTimeout();

            if (is_numeric($retryAfter) && $driver !== 'sync' && (int) $retryAfter <= $timeout) {
                $warnings[] = "The '{$connection}' queue connection has retry_after = {$retryAfter}s, which is not longer than the "
                    ."social-poster job timeout ({$timeout}s, the longest publish step or first-comment run): the queue redelivers a "
                    ."still-running publish step or comment to a second worker, which then waits up to the job timeout for the step's "
                    .'lock before it runs (or is dropped once the first run handed the step over), so publishes and comments are '
                    .'delayed and can run twice. Raise retry_after above the timeout (or lower social.job_timeout and social.comment_timeout).';
            }

            foreach (['facebook', 'instagram'] as $platform) {
                $version = $config->get("social.api.{$platform}.graph_version");

                if (is_string($version) && preg_match('/^v\d+(\.\d+)?$/', $version) === 1
                    && version_compare(ltrim($version, 'v'), ltrim(static::MIN_GRAPH_VERSION, 'v'), '<')) {
                    $warnings[] = "social.api.{$platform}.graph_version is {$version}; the package is tested against "
                        .static::MIN_GRAPH_VERSION.' and older versions may be retired by Meta. Update the value (or SOCIAL_'
                        .strtoupper($platform).'_GRAPH_VERSION) in config/social.php.';
                }
            }

            $unusable = array_values(array_filter(
                array_map('strval', array_keys(SocialManager::driverMap((array) $config->get('social.drivers', [])))),
                static fn (string $name): bool => Platform::tryFrom($name) === null,
            ));

            if ($unusable !== []) {
                $warnings[] = "config('social.drivers') registers ".implode(', ', $unusable).', which '
                    .(count($unusable) === 1 ? 'is not a ' : 'are not ').Platform::class.' case'.(count($unusable) === 1 ? '' : 's')
                    .': 1.x posts only to Platform cases, so these drivers cannot be used yet (custom platform names arrive in 2.0). '
                    .'Register a reserved case such as mastodon, or override a first-party driver by its name.';
            }
        } catch (Throwable) {
            // Warnings are advisory: never break booting over them.
        }

        return $warnings;
    }

    protected function logConfigurationWarnings(): void
    {
        foreach ($this->configurationWarnings() as $warning) {
            try {
                Log::warning('[SocialPoster] '.$warning);
            } catch (Throwable) {
                return;
            }
        }
    }

    /**
     * mergeConfigFrom() is shallow: a config published under v1.0 keeps its own
     * whole 'api' array and never sees new per-platform defaults. Merge the
     * nested blocks with the package defaults, the app's values winning.
     * Skipped when the config is cached (the cache already holds the result).
     */
    protected function mergeNestedDefaults(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');
        $defaults = require __DIR__.'/../config/social.php';

        foreach (static::DEEP_MERGED as $key) {
            $current = $config->get("social.{$key}");

            if (! is_array($defaults[$key] ?? null) || ($current !== null && ! is_array($current))) {
                continue;
            }

            $config->set("social.{$key}", static::mergeDeep($defaults[$key], (array) $current));
        }

        // Expose the effective driver map (first-party drivers plus the app's
        // overrides), as v1.0's config did: code that lists config('social.drivers')
        // keeps seeing every platform. A null entry (a disabled driver) is kept so
        // it stays disabled. Class names only; nothing is resolved here.
        $config->set('social.drivers', array_replace(SocialManager::FIRST_PARTY, (array) $config->get('social.drivers', [])));
    }

    /**
     * Recursive merge for config maps: nested maps merge key by key, while
     * lists and scalars from $overrides replace the default wholesale (so a
     * shorter backoff list never inherits trailing defaults).
     *
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $overrides
     * @return array<array-key, mixed>
     */
    public static function mergeDeep(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $default = $defaults[$key] ?? null;

            $defaults[$key] = is_array($value) && is_array($default) && ! array_is_list($value) && ! array_is_list($default)
                ? static::mergeDeep($default, $value)
                : $value;
        }

        return $defaults;
    }

    /**
     * The cache stores the duplicate protection relies on that no other
     * process can see ('array': per process; 'null': stores nothing, every
     * lock granted), by store name: [driver, what it holds, the settings that
     * move it]. The job locks (unique dispatch, overlap guard, step hand-over
     * and comment markers) live in social.lock_store, else the default store;
     * CacheIdempotencyStore keeps its records in social.idempotency_cache_store
     * (else the default store) and its leases in social.lock_store (else the
     * record store).
     *
     * @return array<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    protected function perProcessCacheStores(Repository $config, bool $cacheIdempotency): array
    {
        $name = static fn (mixed $store): ?string => is_string($store) && $store !== '' ? $store : null;
        $default = $name($config->get('cache.default'));
        $lockStore = $name($config->get('social.lock_store'));
        $recordStore = $name($config->get('social.idempotency_cache_store')) ?? $default;

        $uses = [[$lockStore ?? $default, 'job locks', 'social.lock_store']];

        if ($cacheIdempotency) {
            $uses[] = [$recordStore, 'idempotency records', 'social.idempotency_cache_store'];
            $uses[] = [$lockStore ?? $recordStore, 'idempotency leases', 'social.lock_store'];
        }

        $stores = [];

        foreach ($uses as [$store, $use, $setting]) {
            $driver = $store !== null ? $config->get("cache.stores.{$store}.driver") : null;

            if ($store === null || ! in_array($driver, ['array', 'null'], true)) {
                continue;
            }

            $stores[$store] ??= [$driver, [], []];
            $stores[$store][1][] = $use;
            $stores[$store][2] = array_values(array_unique([...$stores[$store][2], $setting]));
        }

        return $stores;
    }

    protected function queueConnection(Repository $config): string
    {
        $connection = $config->get('social.connection') ?: $config->get('queue.default');

        return is_string($connection) && $connection !== '' ? $connection : 'sync';
    }

    /**
     * The longest job timeout among the registered platforms: a publish step
     * (PublishToConnectionJob) or a first-comment run (AddFirstCommentJob),
     * whichever runs longer. The comment run is derived on its own and can
     * outlast the publish step when social.job_timeout is set low.
     */
    protected function longestJobTimeout(): int
    {
        $longest = max(PublishToConnectionJob::configuredTimeout(), AddFirstCommentJob::configuredTimeout());

        foreach (array_keys(SocialManager::driverMap((array) $this->app['config']->get('social.drivers', []))) as $platform) {
            $longest = max($longest, PublishToConnectionJob::configuredTimeout($platform), AddFirstCommentJob::configuredTimeout($platform));
        }

        return $longest;
    }
}
