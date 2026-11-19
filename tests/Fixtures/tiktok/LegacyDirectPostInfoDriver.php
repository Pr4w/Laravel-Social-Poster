<?php

namespace SocialPoster\Tests\Fixtures\TikTok;

use SocialPoster\Platforms\TikTok\TikTokDriver;
use SocialPoster\Platforms\TikTok\TikTokOptions;
use SocialPoster\ValueObjects\PreparedPost;

/**
 * A consumer driver written against v1.0: it overrides directPostInfo() with
 * the 1.0 signature to add a post_info field. Loading it must not be a fatal
 * "must be compatible" error, and its override must still shape the init.
 */
class LegacyDirectPostInfoDriver extends TikTokDriver
{
    protected function directPostInfo(PreparedPost $post, TikTokOptions $options, ?array $creator, bool $video): array
    {
        return parent::directPostInfo($post, $options, $creator, $video) + ['legacy_marker' => true];
    }
}
