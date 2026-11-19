<?php

namespace SocialPoster\Platforms\TikTok;

use Illuminate\Support\MessageBag;
use SocialPoster\Concerns\CarriesExtraPayload;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;

/**
 * TikTok post options. The typed knobs are the ones that are structural
 * (target, transfer), required by the API (privacyLevel for direct posts),
 * interact with the creator-info gate (the disable_* interaction toggles,
 * privacy fallbacks), or are disclosures TikTok's guidelines care about
 * (branded content, AI-generated content). Anything else TikTok accepts in
 * post_info rides the extra() escape hatch and is merged into post_info.
 *
 * Disclosure (the brand toggles are DIRECT_POST only; TikTok ignores them for
 * inbox drafts, where the creator discloses in-app):
 *  - brandContent: a paid partnership promoting a third party
 *    (brand_content_toggle). TikTok forbids it on SELF_ONLY posts.
 *  - brandOrganic: the creator promotes their own business
 *    (brand_organic_toggle).
 *  - isAigc: the content is AI-generated (is_aigc). Sent in post_info for a
 *    direct video, and at the top level of content/init for photos (direct
 *    and draft). A draft video carries no post_info, so it is not sent there.
 *  Both brand toggles are sent as false when left null, as the production app
 *  always did (config social.api.tiktok.send_brand_defaults, default true).
 *
 * privacyFallbacks: privacy levels to try, in order, when the creator's
 * account no longer allows privacyLevel (e.g. it went private after the post
 * was scheduled). Opt-in: TikTok's guidelines want the user to choose, so
 * nothing is picked unless you list it. The level actually used is reported
 * in the result payload (privacy_fallback_used).
 *
 * The photo cover is chosen with photoCoverIndex (put the cover image in the
 * media list and point at it); the video cover frame with coverTimestampMs.
 * TikTok has no cover-image upload, so Media::video()'s thumbnail is ignored.
 */
final class TikTokOptions implements PlatformOptions
{
    use CarriesExtraPayload;

    /**
     * @param  array<string, mixed>  $extra  Raw passthrough merged into post_info.
     * @param  TikTokPrivacy[]  $privacyFallbacks  Ordered fallbacks when privacyLevel is not allowed.
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
        public readonly TikTokTransfer $transfer = TikTokTransfer::Auto,
        public readonly ?bool $brandContent = null,
        public readonly ?bool $brandOrganic = null,
        public readonly ?bool $isAigc = null,
        public readonly array $privacyFallbacks = [],
    ) {}

    public function platform(): Platform
    {
        return Platform::TikTok;
    }

    public function validate(): MessageBag
    {
        $errors = new MessageBag;

        if ($this->photoCoverIndex < 0) {
            $errors->add('photo_cover_index', 'The TikTok photo cover index must be 0 or more (it is zero-based).');
        }

        if ($this->coverTimestampMs !== null && $this->coverTimestampMs < 0) {
            $errors->add('cover_timestamp_ms', 'The TikTok video cover timestamp must be 0 or more milliseconds.');
        }

        foreach ($this->privacyFallbacks as $index => $fallback) {
            if (! $fallback instanceof TikTokPrivacy) {
                $errors->add('privacy_fallbacks', "TikTok privacy fallback #{$index} must be a TikTokPrivacy case.");
            }
        }

        if ($this->brandContent === true) {
            if ($this->privacyLevel === TikTokPrivacy::SelfOnly) {
                $errors->add('brand_content', 'TikTok branded content cannot be private: choose a privacy level other than SELF_ONLY.');
            }

            if (in_array(TikTokPrivacy::SelfOnly, $this->privacyFallbacks, true)) {
                $errors->add('privacy_fallbacks', 'TikTok branded content cannot be private: remove SELF_ONLY from the privacy fallbacks.');
            }
        }

        return $errors;
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return get_object_vars($this);
    }

    /**
     * Restores options serialised by 1.0 (queued jobs in flight across the
     * upgrade), which have none of the 1.1 properties: readonly properties a
     * payload lacks would otherwise stay uninitialised and throw when read.
     *
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        $target = $data['target'] ?? null;
        $privacy = $data['privacyLevel'] ?? null;
        $transfer = $data['transfer'] ?? null;

        $this->target = $target instanceof TikTokTarget ? $target : TikTokTarget::Drafts;
        $this->privacyLevel = $privacy instanceof TikTokPrivacy ? $privacy : null;
        $this->disableComment = self::boolOrNull($data['disableComment'] ?? null);
        $this->disableDuet = self::boolOrNull($data['disableDuet'] ?? null);
        $this->disableStitch = self::boolOrNull($data['disableStitch'] ?? null);
        $this->coverTimestampMs = is_int($data['coverTimestampMs'] ?? null) ? $data['coverTimestampMs'] : null;
        $this->photoCoverIndex = is_int($data['photoCoverIndex'] ?? null) ? $data['photoCoverIndex'] : 0;
        $this->autoAddMusic = self::boolOrNull($data['autoAddMusic'] ?? null);
        $this->extra = is_array($data['extra'] ?? null) ? $data['extra'] : [];
        $this->transfer = $transfer instanceof TikTokTransfer ? $transfer : TikTokTransfer::Auto;
        $this->brandContent = self::boolOrNull($data['brandContent'] ?? null);
        $this->brandOrganic = self::boolOrNull($data['brandOrganic'] ?? null);
        $this->isAigc = self::boolOrNull($data['isAigc'] ?? null);
        $this->privacyFallbacks = is_array($data['privacyFallbacks'] ?? null) ? $data['privacyFallbacks'] : [];
    }

    private static function boolOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }
}
