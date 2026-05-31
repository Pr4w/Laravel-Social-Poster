<?php

namespace SocialPoster;

use Illuminate\Contracts\Events\Dispatcher;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\Credentials;

/**
 * Entry point behind the SocialPoster facade. Each call starts a fresh,
 * immutable-by-convention builder.
 */
class Poster
{
    public function __construct(
        protected SocialManager $manager,
        protected Dispatcher $events,
        protected array $config = [],
    ) {}

    public function on(string|Platform ...$platforms): PostBuilder
    {
        return $this->newBuilder()->on(...$platforms);
    }

    public function using(Credentials|array $credentials): PostBuilder
    {
        return $this->newBuilder()->using($credentials);
    }

    public function newBuilder(): PostBuilder
    {
        return new PostBuilder($this->manager, $this->events, $this->config);
    }
}
