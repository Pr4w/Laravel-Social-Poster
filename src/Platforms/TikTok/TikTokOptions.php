<?php

namespace SocialPoster\Platforms\TikTok;

use Illuminate\Support\MessageBag;
use SocialPoster\Concerns\CarriesExtraPayload;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;

/**
 * TikTok post options. The typed knobs are the ones that are structural
 * (target), required by the API (privacyLevel for direct posts), or interact
 * with the creator-info gate (the disable_* interaction toggles). Everything
 * else TikTok accepts in post_info (auto_add_music, branded-content disclosure,
 * is_aigc, future fields) rides the extra() escape hatch and is merged into
 * post_info by the driver.
 */
final class TikTokOptions implements PlatformOptions
{
    use CarriesExtraPayload;

    /**
     * @param array<string, mixed> $extra Raw passthrough merged into post_info.
     */
    public function __construct(
        public readonly TikTokTarget $target = TikTokTarget::Drafts,
        public readonly ?TikTokPrivacy $privacyLevel = null,
        public readonly ?bool $disableComment = null,
        public readonly ?bool $disableDuet = null,
        public readonly ?bool $disableStitch = null,
        public readonly ?int $coverTimestampMs = null, // video cover frame
        public readonly int $photoCoverIndex = 0,      // which image is the cover
        public readonly ?bool $autoAddMusic = null,    // photo posts
        public readonly array $extra = [],
    ) {}

    public function platform(): Platform
    {
        return Platform::TikTok;
    }

    public function validate(): MessageBag
    {
        return new MessageBag();
    }
}
