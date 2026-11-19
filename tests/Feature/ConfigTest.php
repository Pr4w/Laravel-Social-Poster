<?php

namespace SocialPoster\Tests\Feature\Config;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use ReflectionProperty;
use SocialPoster\Contracts\CredentialsResolver;
use SocialPoster\Contracts\IdempotencyStore;
use SocialPoster\Contracts\MediaTransformer;
use SocialPoster\Enums\Feature;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\UnsupportedPlatformException;
use SocialPoster\Idempotency\CacheIdempotencyStore;
use SocialPoster\Idempotency\DatabaseIdempotencyStore;
use SocialPoster\Idempotency\NullIdempotencyStore;
use SocialPoster\Jobs\AddFirstCommentJob;
use SocialPoster\Platforms\Bluesky\BlueskyDriver;
use SocialPoster\Platforms\Facebook\FacebookDriver;
use SocialPoster\Platforms\Instagram\InstagramDriver;
use SocialPoster\Platforms\LinkedIn\LinkedInDriver;
use SocialPoster\Platforms\Pinterest\PinterestDriver;
use SocialPoster\Platforms\Threads\ThreadsDriver;
use SocialPoster\Platforms\TikTok\TikTokDriver;
use SocialPoster\Platforms\X\XDriver;
use SocialPoster\Platforms\YouTube\YouTubeDriver;
use SocialPoster\PostBuilder;
use SocialPoster\Poster;
use SocialPoster\Resolvers\IdentityCredentialsResolver;
use SocialPoster\SocialManager;
use SocialPoster\SocialPosterServiceProvider;
use SocialPoster\Transformers\ImageConvertingTransformer;
use SocialPoster\Transformers\NullMediaTransformer;
use SocialPoster\ValueObjects\Credentials;

class AppResolver implements CredentialsResolver
{
    public function resolve(Platform $platform, Credentials $dispatched, array $metadata): Credentials
    {
        return $dispatched;
    }
}

/** Re-run the provider's register() as a fresh boot would, against the current config. */
function reRegister(): SocialPosterServiceProvider
{
    $provider = new SocialPosterServiceProvider(app());
    $provider->register();

    return $provider;
}

/** The config('social') array v1.0 published (the parts that matter here). */
function v10PublishedConfig(): array
{
    return [
        'default' => null,
        'queue' => null,
        'comment_delay' => 10,
        'upload_timeout' => 300,
        'idempotency' => NullIdempotencyStore::class,
        'idempotency_table' => 'social_idempotency',
        'api' => [
            'facebook' => ['graph_version' => 'v23.0'],
            'instagram' => ['graph_version' => 'v23.0'],
            'threads' => ['graph_version' => 'v1.0'],
            'linkedin' => ['version' => '202605'],
        ],
        'drivers' => [
            'facebook' => FacebookDriver::class,
            'instagram' => InstagramDriver::class,
            'linkedin' => LinkedInDriver::class,
            'threads' => ThreadsDriver::class,
            'x' => XDriver::class,
            'tiktok' => TikTokDriver::class,
            'youtube' => YouTubeDriver::class,
        ],
        'platforms' => [],
    ];
}

// --- Drivers ------------------------------------------------------------------

it('registers all nine first-party drivers in code, lazily', function () {
    $manager = app(SocialManager::class);

    expect(SocialManager::FIRST_PARTY)->toHaveCount(9)
        ->and(SocialManager::FIRST_PARTY[Platform::Bluesky->value])->toBe(BlueskyDriver::class)
        ->and(SocialManager::FIRST_PARTY[Platform::Pinterest->value])->toBe(PinterestDriver::class)
        ->and($manager->registered())->toEqualCanonicalizing(array_keys(SocialManager::FIRST_PARTY))
        ->and($manager->getDrivers())->toBe([])
        ->and($manager->supports(Platform::Bluesky))->toBeTrue()
        ->and($manager->supports(Platform::Mastodon))->toBeFalse();
});

it('gives a v1.0 published drivers map the drivers added since', function () {
    config(['social' => v10PublishedConfig()]);
    reRegister();

    expect(app(SocialManager::class)->registered())->toContain('bluesky', 'pinterest', 'facebook')
        ->and(array_keys(config('social.drivers')))->toContain('bluesky', 'pinterest');
});

it('lets the config add, override and disable drivers', function () {
    config(['social.drivers' => ['facebook' => null, 'mastodon' => FacebookDriver::class]]);
    reRegister();
    $manager = app(SocialManager::class);

    expect($manager->supports('facebook'))->toBeFalse()
        ->and($manager->supports('mastodon'))->toBeTrue()
        ->and(fn () => $manager->driver('facebook'))->toThrow(UnsupportedPlatformException::class);
});

it('throws UnsupportedPlatformException naming the registered drivers', function () {
    try {
        app(SocialManager::class)->driver(Platform::Mastodon);
        $this->fail('Expected an UnsupportedPlatformException.');
    } catch (UnsupportedPlatformException $e) {
        expect($e->requested)->toBe('mastodon')
            ->and($e->registered)->toContain('facebook', 'bluesky')
            ->and($e->getMessage())->toContain('mastodon');
    }

    expect(fn () => app(SocialManager::class)->platform('myspace'))->toThrow(UnsupportedPlatformException::class);
});

it('resolves a driver from a Platform case and requires a default otherwise', function () {
    expect(app(SocialManager::class)->driver(Platform::Facebook))->toBeInstanceOf(FacebookDriver::class)
        ->and(fn () => app(SocialManager::class)->driver())->toThrow(PermanentException::class);

    config(['social.default' => 'facebook']);

    expect(app(SocialManager::class)->driver())->toBeInstanceOf(FacebookDriver::class);
});

// --- Defaults and merging -----------------------------------------------------

it('ships the 1.1 defaults', function () {
    expect(config('social.idempotency'))->toBe(CacheIdempotencyStore::class)
        ->and(config('social.comment_delay'))->toBe(60)
        ->and(config('social.api.facebook.graph_version'))->toBe('v25.0')
        ->and(config('social.api.instagram.graph_version'))->toBe('v25.0')
        ->and(config('social.async.max_wait'))->toBe(3600)
        ->and(config('social.async.max_wait_per_platform.tiktok'))->toBe(3900)
        ->and(config('social.async.max_wait_per_platform.instagram'))->toBe(86400)
        ->and(config('social.sync.max_wait'))->toBe(120)
        ->and(config('social.tries'))->toBe(5)
        ->and(config('social.backoff'))->toBe([30, 120, 300, 900])
        ->and(config('social.max_recheck_after'))->toBe(900)
        ->and(config('social.encrypt_jobs'))->toBeTrue()
        ->and(config('social.credentials_resolver'))->toBe(IdentityCredentialsResolver::class)
        ->and(config('social.transformer'))->toBe(NullMediaTransformer::class)
        ->and(config('social.authorizers'))->toBe([])
        ->and(config('social.api.bluesky.entryway'))->toBe('https://bsky.social')
        ->and(config('social.api.pinterest.upload_timeout'))->toBe(600)
        ->and(config('social.media.rehost'))->toBe('local_only')
        ->and(config('social.media.allow_http'))->toBeTrue();
});

it('lists every 1.1 key the drivers read, with the inline default', function () {
    expect(config('social.comment_processing_delay'))->toBe(300)
        ->and(config('social.inspector_video_range_bytes'))->toBe(4194304)
        ->and(config('social.media.max_convert_pixels'))->toBe(50000000)
        ->and(config('social.api.facebook.verify_max_wait'))->toBe(3600)
        ->and(config('social.api.facebook.reel_schedule_margin'))->toBe(240)
        ->and(config('social.api.instagram.min_video_frame_rate'))->toBe(23)
        ->and(config('social.api.instagram.max_video_frame_rate'))->toBe(60)
        ->and(config('social.api.threads.recovery_lookups'))->toBe(3)
        ->and(config('social.api.bluesky.max_rate_limit_wait'))->toBe(3600)
        ->and(config('social.api.x.allow_quote_posts'))->toBeTrue()
        // X's post limits (not its DM limits of 140 s / 512 MB): the merged
        // config must not pin apps to stricter values than the driver's.
        ->and(config('social.api.x.max_video_bytes'))->toBe(8589934592)
        ->and(config('social.api.x.max_video_duration'))->toBe(1200);
});

it('deep-merges the nested blocks of a config published under v1.0', function () {
    config(['social' => v10PublishedConfig()]);
    reRegister();

    expect(config('social.api.facebook.graph_version'))->toBe('v23.0')
        ->and(config('social.api.bluesky.video_max_bytes'))->toBe(100000000)
        ->and(config('social.api.pinterest.base_url'))->toBe('https://api.pinterest.com/v5')
        ->and(config('social.async.max_wait'))->toBe(3600)
        ->and(config('social.media.url_ttl'))->toBe(86400)
        // Top-level keys v1.0 had keep the app's values; new ones get defaults.
        ->and(config('social.comment_delay'))->toBe(10)
        ->and(config('social.http_timeout'))->toBe(60);
});

it('replaces lists instead of merging them index by index', function () {
    $merged = SocialPosterServiceProvider::mergeDeep(
        ['backoff' => [30, 120, 300, 900], 'api' => ['x' => ['a' => 1, 'b' => 2]]],
        ['backoff' => [10], 'api' => ['x' => ['b' => 3]]],
    );

    expect($merged)->toBe(['backoff' => [10], 'api' => ['x' => ['a' => 1, 'b' => 3]]]);
});

it('warns about a Graph version older than the tested one', function () {
    config(['social.api.facebook.graph_version' => 'v23.0']);

    $warnings = (new SocialPosterServiceProvider(app()))->configurationWarnings();

    expect(implode("\n", $warnings))->toContain('social.api.facebook.graph_version is v23.0')
        ->not->toContain('social.api.instagram');
});

it('warns about the null store on an asynchronous queue', function () {
    config([
        // A store every worker shares, so only the null store is at fault.
        'cache.default' => 'file',
        'social.idempotency' => NullIdempotencyStore::class,
        'queue.default' => 'redis',
        'queue.connections.redis' => ['driver' => 'redis', 'retry_after' => 5000],
    ]);

    $warnings = (new SocialPosterServiceProvider(app()))->configurationWarnings();

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('NullIdempotencyStore');

    config(['queue.default' => 'sync']);

    expect((new SocialPosterServiceProvider(app()))->configurationWarnings())->toBe([]);
});

it('warns when the queue retry_after does not exceed the job timeout', function () {
    config([
        // A store every worker shares, so only retry_after is at fault.
        'cache.default' => 'file',
        'social.connection' => 'database',
        'queue.connections.database' => ['driver' => 'database', 'retry_after' => 90],
    ]);

    $warnings = (new SocialPosterServiceProvider(app()))->configurationWarnings();

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('retry_after = 90s')
        ->and($warnings[0])->toContain("waits up to the job timeout for the step's lock");

    config(['queue.connections.database.retry_after' => 1200]);

    expect((new SocialPosterServiceProvider(app()))->configurationWarnings())->toBe([]);
});

it('warns when the queue retry_after does not exceed the first-comment run timeout', function () {
    config([
        'cache.default' => 'file',
        'social.connection' => 'database',
        // Publish runs capped at 200 s; a comment run is derived (about 390 s).
        'social.job_timeout' => 200,
        'queue.connections.database' => ['driver' => 'database', 'retry_after' => 300],
    ]);

    $comment = AddFirstCommentJob::configuredTimeout('threads');
    $warnings = (new SocialPosterServiceProvider(app()))->configurationWarnings();

    expect($comment)->toBeGreaterThan(300)
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('retry_after = 300s')
        ->and($warnings[0])->toContain("job timeout ({$comment}s,")
        ->and($warnings[0])->toContain('social.comment_timeout');

    config(['social.comment_timeout' => 250]);

    expect((new SocialPosterServiceProvider(app()))->configurationWarnings())->toBe([]);
});

it('lists the comment run timeout, derived when unset', function () {
    expect(config('social.comment_timeout'))->toBeNull()
        ->and(AddFirstCommentJob::configuredTimeout('youtube'))->toBe(390);

    config(['social.comment_timeout' => 150]);

    expect(AddFirstCommentJob::configuredTimeout('youtube'))->toBe(150);
});

it('warns when the locks or the idempotency records live in a per-process cache store on an asynchronous queue', function () {
    config([
        'queue.default' => 'redis',
        'queue.connections.redis' => ['driver' => 'redis', 'retry_after' => 5000],
        'cache.default' => 'array',
        'cache.stores.array' => ['driver' => 'array'],
        'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'cache'],
        'cache.stores.none' => ['driver' => 'null'],
    ]);
    $warnings = fn () => (new SocialPosterServiceProvider(app()))->configurationWarnings();

    // The default store (array) holds everything: one warning naming every use.
    expect($warnings())->toHaveCount(1)
        ->and($warnings()[0])->toContain("'array' cache store")
        ->and($warnings()[0])->toContain('job locks')
        ->and($warnings()[0])->toContain('idempotency records')
        ->and($warnings()[0])->toContain("asynchronous 'redis' queue connection");

    // Shared locks, per-process records.
    config(['social.lock_store' => 'redis']);

    expect($warnings())->toHaveCount(1)
        ->and($warnings()[0])->toContain('idempotency records')
        ->and($warnings()[0])->not->toContain('job locks')
        ->and($warnings()[0])->toContain('social.idempotency_cache_store');

    // The null driver keeps nothing and grants every lock.
    config(['social.lock_store' => 'none', 'social.idempotency_cache_store' => 'redis']);

    expect($warnings())->toHaveCount(1)
        ->and($warnings()[0])->toContain("'none' cache store")
        ->and($warnings()[0])->toContain('job locks')
        ->and($warnings()[0])->toContain('social.lock_store');

    // The database store keeps its records in a table; only its locks use the cache.
    config(['social.idempotency' => DatabaseIdempotencyStore::class, 'social.lock_store' => null, 'social.idempotency_cache_store' => null]);

    expect($warnings())->toHaveCount(1)
        ->and($warnings()[0])->toContain('job locks')
        ->and($warnings()[0])->not->toContain('idempotency records');

    // Shared stores, or the sync queue (one process): nothing to say.
    config(['social.lock_store' => 'redis']);

    expect($warnings())->toBe([]);

    config(['social.idempotency' => CacheIdempotencyStore::class, 'social.lock_store' => null, 'queue.default' => 'sync']);

    expect($warnings())->toBe([]);
});

it('warns about a configured driver name that is not a Platform case, but not about a reserved case', function () {
    config(['social.drivers' => ['reddit' => FacebookDriver::class, 'mastodon' => FacebookDriver::class]]);

    $warnings = (new SocialPosterServiceProvider(app()))->configurationWarnings();

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain("config('social.drivers') registers reddit, which is not a ".Platform::class.' case');
});

// --- Bindings -----------------------------------------------------------------

it('binds the resolver, transformer and default cache idempotency store', function () {
    expect(app(CredentialsResolver::class))->toBeInstanceOf(IdentityCredentialsResolver::class)
        ->and(app(MediaTransformer::class))->toBeInstanceOf(NullMediaTransformer::class)
        ->and(app(IdempotencyStore::class))->toBeInstanceOf(CacheIdempotencyStore::class)
        ->and(IdentityCredentialsResolver::customResolverBound())->toBeFalse();
});

it('binds the configured resolver and transformer', function () {
    config([
        'social.credentials_resolver' => AppResolver::class,
        'social.transformer' => ImageConvertingTransformer::class,
    ]);

    expect(app(CredentialsResolver::class))->toBeInstanceOf(AppResolver::class)
        ->and(app(MediaTransformer::class))->toBeInstanceOf(ImageConvertingTransformer::class)
        ->and(IdentityCredentialsResolver::customResolverBound())->toBeTrue();
});

it('builds the database and null stores from config', function (string $class, string $expected) {
    config(['social.idempotency' => $class, 'social.idempotency_connection' => 'testing']);

    expect(app(IdempotencyStore::class))->toBeInstanceOf($expected);
})->with([
    'database' => [DatabaseIdempotencyStore::class, DatabaseIdempotencyStore::class],
    'null' => [NullIdempotencyStore::class, NullIdempotencyStore::class],
]);

it('registers the prune commands and publishes every migration', function () {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('social-poster:prune-temp', 'social-poster:prune-idempotency');

    $paths = ServiceProvider::pathsToPublish(SocialPosterServiceProvider::class, 'social-poster-migrations');

    expect($paths)->toHaveCount(1)
        ->and(realpath((string) array_key_first($paths)))->toBe(realpath(__DIR__.'/../../database/migrations'))
        ->and(ServiceProvider::pathsToPublish(SocialPosterServiceProvider::class, 'social-poster-config'))->toHaveCount(1);
});

// --- Poster -------------------------------------------------------------------

it('reads config per builder, so runtime changes reach new posts', function () {
    $poster = app(Poster::class);
    $config = new ReflectionProperty(PostBuilder::class, 'config');

    expect($config->getValue($poster->newBuilder())['queue'] ?? null)->toBeNull();

    config(['social.queue' => 'social']);

    expect($config->getValue($poster->newBuilder())['queue'])->toBe('social');
});

it('exposes the scopes a platform needs for a feature', function () {
    expect(app(Poster::class)->requiredScopes('bluesky', Feature::Publish))->toBe([['atproto']])
        ->and(app(Poster::class)->requiredScopes(Platform::Bluesky, Feature::Video))->toBe([]);
});
