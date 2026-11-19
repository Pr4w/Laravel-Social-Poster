<?php

namespace SocialPoster;

use Illuminate\Support\Manager;
use Illuminate\Support\Str;
use SocialPoster\Contracts\SocialPlatform;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\UnsupportedPlatformException;

/**
 * Resolves platform drivers by name, exactly like Laravel's own Mail/Queue/
 * Filesystem managers. Consumers register drivers with extend(), or through
 * config('social.drivers'): to replace a first-party driver (by its name), or
 * to give a reserved Platform case without one (such as 'mastodon') a driver.
 * In 1.x the builder only posts to SocialPoster\Enums\Platform cases, so a
 * driver registered under any other name cannot be used yet; custom platform
 * names arrive in 2.0.
 *
 * The first-party drivers are registered in code (FIRST_PARTY), not only in
 * the published config: config('social.drivers') is applied on top of this map
 * as overrides and additions. That way an app that published the v1.0 config
 * (whose 'drivers' array lists the seven original drivers) still receives the
 * drivers added in later releases. Set a platform to null/false in
 * config('social.drivers') to disable its first-party driver.
 *
 * Registration is lazy: the map holds class names only, and a driver class is
 * resolved from the container the first time that platform is requested.
 */
class SocialManager extends Manager
{
    /** The drivers shipped with the package, by platform value. */
    public const FIRST_PARTY = [
        Platform::Facebook->value => Platforms\Facebook\FacebookDriver::class,
        Platform::Instagram->value => Platforms\Instagram\InstagramDriver::class,
        Platform::Threads->value => Platforms\Threads\ThreadsDriver::class,
        Platform::LinkedIn->value => Platforms\LinkedIn\LinkedInDriver::class,
        Platform::TikTok->value => Platforms\TikTok\TikTokDriver::class,
        Platform::X->value => Platforms\X\XDriver::class,
        Platform::YouTube->value => Platforms\YouTube\YouTubeDriver::class,
        Platform::Bluesky->value => Platforms\Bluesky\BlueskyDriver::class,
        Platform::Pinterest->value => Platforms\Pinterest\PinterestDriver::class,
    ];

    /**
     * The driver map the provider registers: FIRST_PARTY with the configured
     * overrides applied. A null/false/'' entry removes a driver.
     *
     * @param  array<string, mixed>  $configured  config('social.drivers')
     * @return array<string, string>
     */
    public static function driverMap(array $configured = []): array
    {
        $map = [];

        foreach (array_replace(self::FIRST_PARTY, $configured) as $name => $class) {
            if (is_string($class) && $class !== '') {
                $map[(string) $name] = $class;
            }
        }

        return $map;
    }

    public function getDefaultDriver(): string
    {
        $default = $this->config->get('social.default');

        if ($default instanceof Platform) {
            return $default->value;
        }

        if (! is_string($default) || $default === '') {
            throw new PermanentException(
                'No default platform configured; specify a platform explicitly.',
                null,
                FailureReason::Misconfigured,
            );
        }

        return $default;
    }

    /**
     * Resolve a driver. Accepts a platform value or a Platform case. An
     * unregistered name (unknown, removed from config, or a reserved case such
     * as Mastodon) throws UnsupportedPlatformException, listing what is
     * registered, instead of the framework's InvalidArgumentException.
     *
     * @param  string|Platform|null  $driver
     * @return SocialPlatform
     *
     * @throws UnsupportedPlatformException
     */
    public function driver($driver = null)
    {
        if ($driver instanceof Platform) {
            $driver = $driver->value;
        }

        return parent::driver($driver);
    }

    public function platform(string $name): SocialPlatform
    {
        return $this->driver($name);
    }

    /** True when a driver is registered for $platform. */
    public function supports(string|Platform $platform): bool
    {
        $name = $platform instanceof Platform ? $platform->value : $platform;

        return isset($this->customCreators[$name]) || method_exists($this, 'create'.Str::studly($name).'Driver');
    }

    /**
     * The registered platform names.
     *
     * @return list<string>
     */
    public function registered(): array
    {
        return array_values(array_map('strval', array_keys($this->customCreators)));
    }

    /**
     * The error for a name the builder cannot post to. It lists only the
     * registered names that are Platform cases (the only ones 1.x can post
     * to), and a name registered under another name says so in its 'hint'
     * context instead of reading as both registered and missing.
     */
    public function unsupportedPlatform(string $name): UnsupportedPlatformException
    {
        $usable = array_values(array_filter($this->registered(), static fn (string $registered): bool => Platform::tryFrom($registered) !== null));
        $normalized = strtolower(trim($name));

        if ($normalized !== '' && Platform::tryFrom($normalized) === null && $this->supports($normalized)) {
            $hint = "A driver is registered for [{$normalized}], but it is not a ".Platform::class.' case: 1.x posts only to '
                .'Platform cases (a reserved case such as mastodon can take a custom driver). Custom platform names arrive in 2.0.';

            return (new UnsupportedPlatformException($name, $usable, $hint.' Usable drivers: '.($usable === [] ? 'none' : implode(', ', $usable)).'.'))
                ->addContext(['hint' => $hint]);
        }

        return new UnsupportedPlatformException($name, $usable);
    }

    /**
     * @param  string  $driver
     * @return mixed
     *
     * @throws UnsupportedPlatformException
     */
    protected function createDriver($driver)
    {
        if (! isset($this->customCreators[$driver]) && ! method_exists($this, 'create'.Str::studly((string) $driver).'Driver')) {
            throw new UnsupportedPlatformException((string) $driver, $this->registered());
        }

        return parent::createDriver($driver);
    }
}
