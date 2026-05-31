<?php

namespace SocialPoster;

use Illuminate\Support\Manager;
use SocialPoster\Contracts\SocialPlatform;
use SocialPoster\Exceptions\PermanentException;

/**
 * Resolves platform drivers by name, exactly like Laravel's own Mail/Queue/
 * Filesystem managers. Consumers register custom platforms with extend(),
 * or via the config 'drivers' map wired up in the service provider.
 */
class SocialManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $default = $this->config->get('social.default');

        if (! $default) {
            throw new PermanentException('No default platform configured; specify a platform explicitly.');
        }

        return $default;
    }

    public function platform(string $name): SocialPlatform
    {
        return $this->driver($name);
    }
}
