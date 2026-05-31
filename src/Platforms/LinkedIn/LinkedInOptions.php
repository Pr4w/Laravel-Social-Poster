<?php

namespace SocialPoster\Platforms\LinkedIn;

use Illuminate\Support\MessageBag;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;

final class LinkedInOptions implements PlatformOptions
{
    /**
     * @param bool $escapeText Escape LinkedIn's reserved characters in the caption.
     *                         Set false when you supply pre-formatted "little text"
     *                         (e.g. hand-built @mentions), which escaping would break.
     */
    public function __construct(
        public readonly bool $escapeText = true,
    ) {}

    public function platform(): Platform
    {
        return Platform::LinkedIn;
    }

    public function toPayload(): array
    {
        return ['escape_text' => $this->escapeText];
    }

    public function validate(): MessageBag
    {
        return new MessageBag();
    }
}
