<?php

namespace SocialPoster\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \SocialPoster\PostBuilder on(string|\SocialPoster\Enums\Platform ...$platforms)
 * @method static \SocialPoster\PostBuilder using(\SocialPoster\ValueObjects\Credentials|array $credentials)
 * @method static \SocialPoster\PostBuilder newBuilder()
 *
 * @see \SocialPoster\Poster
 */
class SocialPoster extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \SocialPoster\Poster::class;
    }
}
