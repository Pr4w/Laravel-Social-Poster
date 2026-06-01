<?php

namespace SocialPoster\Platforms\YouTube;

use Illuminate\Support\MessageBag;
use SocialPoster\Concerns\CarriesExtraPayload;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\Media;

/**
 * YouTube upload options.
 *
 * The video title comes from the builder's ->title() and the description from
 * ->caption(), since YouTube has both fields. The typed knobs here are the
 * common video-resource settings; anything else on the snippet or status object
 * (publishAt, embeddable, license, defaultLanguage, ...) rides extra(), which
 * the driver deep-merges into the matching sub-object:
 *
 *   extra: ['status' => ['publishAt' => '2026-07-01T09:00:00Z']]
 */
final class YouTubeOptions implements PlatformOptions
{
    use CarriesExtraPayload;

    /**
     * @param string[] $tags
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly YouTubePrivacy $privacyStatus = YouTubePrivacy::Public,
        public readonly array $tags = [],
        public readonly ?string $categoryId = '22', // People & Blogs
        public readonly bool $madeForKids = false,
        public readonly ?Media $thumbnail = null,
        public readonly array $extra = [],
    ) {}

    public function platform(): Platform
    {
        return Platform::YouTube;
    }

    public function validate(): MessageBag
    {
        return new MessageBag();
    }
}
