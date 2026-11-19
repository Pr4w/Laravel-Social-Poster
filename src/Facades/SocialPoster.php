<?php

namespace SocialPoster\Facades;

use Illuminate\Support\Facades\Facade;
use SocialPoster\Poster;
use SocialPoster\Testing\SocialPosterFake;

/**
 * @method static \SocialPoster\PostBuilder on(string|\SocialPoster\Enums\Platform ...$platforms)
 * @method static \SocialPoster\PostBuilder using(\SocialPoster\ValueObjects\Credentials|array<string, mixed> $credentials, string|\SocialPoster\Enums\Platform|null $for = null)
 * @method static \SocialPoster\PostBuilder newBuilder()
 * @method static list<list<string>> requiredScopes(\SocialPoster\Enums\Platform|string $platform, \SocialPoster\Enums\Feature $feature, \SocialPoster\ValueObjects\Credentials|array<string, mixed>|null $credentials = null)
 * @method static \SocialPoster\ValueObjects\PostResult|null outcome(string $key, \SocialPoster\Enums\Platform|string|null $platform = null, \SocialPoster\ValueObjects\Credentials|array<string, mixed>|null $credentials = null)
 * @method static string|null status(string $key, \SocialPoster\Enums\Platform|string|null $platform = null, \SocialPoster\ValueObjects\Credentials|array<string, mixed>|null $credentials = null)
 * @method static string reconcile(string $key, \SocialPoster\Enums\Platform|string|null $platform = null, \SocialPoster\ValueObjects\Credentials|array<string, mixed>|null $credentials = null, \SocialPoster\ValueObjects\SocialPost|null $post = null)
 * @method static \SocialPoster\Testing\SocialPosterFake succeed(\SocialPoster\Enums\Platform|string $platform, string|null $id = null, string|null $url = null, array<string, mixed> $payload = [], string|null $state = null)
 * @method static \SocialPoster\Testing\SocialPosterFake fail(\SocialPoster\Enums\Platform|string $platform, \SocialPoster\Enums\FailureReason $reason = \SocialPoster\Enums\FailureReason::Unknown, string|null $message = null, bool $temporary = false, array<string, mixed> $context = [])
 * @method static \SocialPoster\Testing\SocialPosterFake pending(\SocialPoster\Enums\Platform|string $platform)
 * @method static \SocialPoster\Testing\SocialPosterFake rejectValidation(\SocialPoster\Enums\Platform|string $platform, array<string, string|list<string>> $errors = [])
 * @method static \SocialPoster\Testing\SocialPosterFake dispatchEvents(bool $dispatch = true)
 * @method static list<\SocialPoster\ValueObjects\PreparedPost> posted(\SocialPoster\Enums\Platform|string|null $platform = null, callable|null $callback = null)
 * @method static list<\SocialPoster\ValueObjects\PreparedPost> queued(\SocialPoster\Enums\Platform|string|null $platform = null, callable|null $callback = null)
 * @method static list<\SocialPoster\ValueObjects\PreparedPost> postedNow(\SocialPoster\Enums\Platform|string|null $platform = null, callable|null $callback = null)
 * @method static list<\SocialPoster\ValueObjects\PreparedPost> validated(\SocialPoster\Enums\Platform|string|null $platform = null, callable|null $callback = null)
 * @method static void assertPosted(\SocialPoster\Enums\Platform|string $platform, callable|null $callback = null)
 * @method static void assertPostedTimes(\SocialPoster\Enums\Platform|string $platform, int $times = 1)
 * @method static void assertNotPosted(\SocialPoster\Enums\Platform|string $platform, callable|null $callback = null)
 * @method static void assertNothingPosted()
 * @method static void assertQueued(\SocialPoster\Enums\Platform|string $platform, callable|null $callback = null)
 * @method static void assertPostedNow(\SocialPoster\Enums\Platform|string $platform, callable|null $callback = null)
 * @method static void assertValidated(\SocialPoster\Enums\Platform|string|null $platform = null, callable|null $callback = null)
 * @method static void assertCommented(\SocialPoster\Enums\Platform|string $platform, string|callable|null $comment = null)
 * @method static void assertReconciled(string|null $key = null)
 *
 * The scripting and assert* methods exist only after SocialPoster::fake().
 *
 * @see Poster
 * @see SocialPosterFake
 */
class SocialPoster extends Facade
{
    /**
     * Swap the poster for a recording fake: nothing is published or queued,
     * every terminal call is recorded for the assert* methods, and outcomes
     * are scripted per platform (succeed(), fail(), pending()).
     */
    public static function fake(): SocialPosterFake
    {
        $fake = new SocialPosterFake;

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Poster::class;
    }
}
