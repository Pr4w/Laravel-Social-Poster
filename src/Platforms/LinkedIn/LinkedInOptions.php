<?php

namespace SocialPoster\Platforms\LinkedIn;

use Illuminate\Support\MessageBag;
use SocialPoster\Concerns\CarriesExtraPayload;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;

final class LinkedInOptions implements PlatformOptions
{
    use CarriesExtraPayload;

    /**
     * @param bool $escapeText Escape LinkedIn's reserved characters in the caption.
     *                         Set false when you supply pre-formatted "little text"
     *                         (e.g. hand-built @mentions), which escaping would break.
     * @param array<string, mixed> $extra Raw params merged into the /posts create.
     */
    public function __construct(
        public readonly bool $escapeText = true,
        public readonly array $extra = [],
    ) {}

    public function platform(): Platform
    {
        return Platform::LinkedIn;
    }

    public function validate(): MessageBag
    {
        return new MessageBag();
    }
}
