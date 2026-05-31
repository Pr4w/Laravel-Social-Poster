<?php

namespace SocialPoster\Concerns;

/**
 * Supplies the extra() accessor for PlatformOptions implementations. The using
 * class declares a readonly promoted `array $extra` constructor property; this
 * trait just exposes it through the contract method.
 */
trait CarriesExtraPayload
{
    /** @return array<string, mixed> */
    public function extra(): array
    {
        return $this->extra;
    }
}
