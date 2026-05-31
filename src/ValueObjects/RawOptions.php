<?php

namespace SocialPoster\ValueObjects;

use Illuminate\Support\MessageBag;
use SocialPoster\Concerns\CarriesExtraPayload;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;

/**
 * Options carrier for platforms that have no typed options of their own (or when
 * you only need the raw passthrough). Bind it to one platform so the engine can
 * scope it during a multi-platform fan-out and never leak the payload elsewhere:
 *
 *   ->withOptions(new RawOptions(Platform::X, ['reply_settings' => 'following']))
 */
final class RawOptions implements PlatformOptions
{
    use CarriesExtraPayload;

    /** @param array<string, mixed> $extra */
    public function __construct(
        public readonly Platform $platform,
        public readonly array $extra = [],
    ) {}

    public function platform(): Platform
    {
        return $this->platform;
    }

    public function validate(): MessageBag
    {
        return new MessageBag();
    }
}
