<?php

use SocialPoster\Contracts\SocialPlatform;
use SocialPoster\Enums\Platform;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\Platforms\Bluesky\BlueskyDriver;
use SocialPoster\Platforms\Facebook\FacebookDriver;
use SocialPoster\Platforms\Instagram\InstagramDriver;
use SocialPoster\Platforms\LinkedIn\LinkedInDriver;
use SocialPoster\Platforms\Pinterest\PinterestDriver;
use SocialPoster\Platforms\Threads\ThreadsDriver;
use SocialPoster\Platforms\TikTok\TikTokDriver;
use SocialPoster\Platforms\X\XDriver;
use SocialPoster\Platforms\YouTube\YouTubeDriver;
use SocialPoster\SocialManager;

it('boots the provider and resolves every registered first-party driver', function () {
    $registered = array_keys((array) config('social.drivers'));

    expect($registered)->not->toBeEmpty();

    foreach ($registered as $name) {
        $driver = app(SocialManager::class)->platform($name);

        expect($driver->platform())->toBe(Platform::from($name));
    }
});

it('resolves all nine first-party drivers from the container, with their capabilities', function (Platform $platform, string $class) {
    $driver = app(SocialManager::class)->driver($platform);

    expect($driver)->toBeInstanceOf($class)
        ->and($driver)->toBeInstanceOf(SocialPlatform::class)
        ->and($driver)->toBeInstanceOf(AbstractPlatform::class)
        ->and($driver->platform())->toBe($platform)
        ->and($driver->capabilities()->caption->max)->toBeGreaterThan(0)
        ->and(config('social.drivers.'.$platform->value))->toBe($class);
})->with([
    'facebook' => [Platform::Facebook, FacebookDriver::class],
    'instagram' => [Platform::Instagram, InstagramDriver::class],
    'threads' => [Platform::Threads, ThreadsDriver::class],
    'x' => [Platform::X, XDriver::class],
    'linkedin' => [Platform::LinkedIn, LinkedInDriver::class],
    'tiktok' => [Platform::TikTok, TikTokDriver::class],
    'youtube' => [Platform::YouTube, YouTubeDriver::class],
    'bluesky' => [Platform::Bluesky, BlueskyDriver::class],
    'pinterest' => [Platform::Pinterest, PinterestDriver::class],
]);

it('ships a documented api block for every first-party platform', function () {
    $defaults = require __DIR__.'/../../config/social.php';

    expect(array_keys($defaults['api']))->toEqualCanonicalizing(array_keys(SocialManager::FIRST_PARTY))
        ->and(array_keys(config('social.api')))->toContain(...array_keys(SocialManager::FIRST_PARTY));
});
