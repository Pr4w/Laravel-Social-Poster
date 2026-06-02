<?php

namespace SocialPoster;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use SocialPoster\Contracts\MediaGateway;
use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Contracts\IdempotencyStore;
use SocialPoster\Gateways\LocalMediaGateway;
use SocialPoster\Idempotency\DatabaseIdempotencyStore;
use SocialPoster\Idempotency\NullIdempotencyStore;
use SocialPoster\Inspectors\FFProbeInspector;

class SocialPosterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/social.php', 'social');

        $this->app->singleton(MediaInspector::class, function ($app) {
            $class = $app['config']->get('social.inspector', FFProbeInspector::class);

            return $app->make($class);
        });

        $this->app->singleton(MediaGateway::class, function ($app) {
            $class = $app['config']->get('social.gateway', LocalMediaGateway::class);

            return $app->make($class);
        });

        $this->app->singleton(SocialManager::class, function ($app) {
            $manager = new SocialManager($app);

            foreach ((array) $app['config']->get('social.drivers', []) as $name => $class) {
                $manager->extend($name, fn () => $app->make($class));
            }

            return $manager;
        });

        $this->app->singleton(IdempotencyStore::class, function ($app) {
            $class = $app['config']->get('social.idempotency', NullIdempotencyStore::class);

            if ($class === DatabaseIdempotencyStore::class) {
                return new DatabaseIdempotencyStore($app['config']->get('social.idempotency_table', 'social_idempotency'));
            }

            return $app->make($class);
        });

        $this->app->singleton(Poster::class, fn ($app) => new Poster(
            $app->make(SocialManager::class),
            $app->make(Dispatcher::class),
            (array) $app['config']->get('social', []),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/social.php' => $this->app->configPath('social.php'),
            ], 'social-poster-config');

            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000000_create_social_idempotency_table.php' => $this->app->databasePath('migrations/2026_01_01_000000_create_social_idempotency_table.php'),
            ], 'social-poster-migrations');
        }
    }
}
