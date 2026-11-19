<?php

namespace SocialPoster\Platforms\Pinterest;

use Illuminate\Support\MessageBag;
use SocialPoster\Concerns\CarriesExtraPayload;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\MediaType;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\Media;

/**
 * Pinterest-specific options for one Pin. Everything is optional: without
 * options a Pin links to the caption's first URL, takes its alt text from the
 * first image, and goes to the board (and section) in the credentials.
 *
 * - $link: the Pin's destination (http/https, at most 2048 characters). Wins
 *   over the link taken from the caption.
 * - $linkFromCaption: use the caption's first http(s) URL as the link when no
 *   $link is set (the production behaviour). The caption is still sent as is.
 * - $altText: at most 500 characters; else the first image's Media::$altText.
 * - $boardSectionId: a numeric board section id; overrides the credential
 *   board_section_id for this Pin.
 * - $thumbnail: a video Pin's cover (JPEG or PNG), sent by URL when the
 *   gateway can provide one, else as base64. Without it the video's own
 *   Media::video(thumbnail:) is used, else the frame at $coverKeyFrameTime.
 * - $coverKeyFrameTime: the cover frame in whole seconds (default 0) when there
 *   is no cover image. Not combinable with $thumbnail.
 * - $imageTransfer: URL or base64 for images (see PinterestImageTransfer);
 *   null reads config api.pinterest.image_transfer (default auto).
 * - $carouselItems: per-image overrides for a carousel, keyed by the image's
 *   position (0-4): ['title' => ..., 'description' => ..., 'link' => ...].
 * - $adAccountId: Business Access. Sent as the ?ad_account_id= query
 *   parameter so the Pin is created as the owner of that ad account.
 * - $extra: raw PinCreate fields merged into the create (dominant_color,
 *   parent_pin_id, note, sponsor_id...). Driver-computed keys win, except that
 *   extra['media_source'] is deep-merged into the computed media source (e.g.
 *   ['media_source' => ['index' => 2]] picks a carousel's cover image).
 */
final class PinterestOptions implements PlatformOptions
{
    use CarriesExtraPayload;

    public const MAX_ALT_TEXT = 500;

    public const MAX_LINK = 2048;

    public const MAX_TITLE = 100;

    public const MAX_DESCRIPTION = 800;

    /** Keys a carousel item override may set. */
    public const CAROUSEL_ITEM_KEYS = ['title', 'description', 'link'];

    /**
     * @param  array<int, array{title?: string, description?: string, link?: string}>  $carouselItems
     * @param  array<string, mixed>  $extra  Raw PinCreate fields merged into the create.
     */
    public function __construct(
        public readonly ?string $link = null,
        public readonly bool $linkFromCaption = true,
        public readonly ?string $altText = null,
        public readonly ?string $boardSectionId = null,
        public readonly ?Media $thumbnail = null,
        public readonly ?int $coverKeyFrameTime = null,
        public readonly ?PinterestImageTransfer $imageTransfer = null,
        public readonly array $carouselItems = [],
        public readonly ?string $adAccountId = null,
        public readonly array $extra = [],
    ) {}

    public function platform(): Platform
    {
        return Platform::Pinterest;
    }

    public function validate(): MessageBag
    {
        $errors = new MessageBag;

        if ($this->link !== null && trim($this->link) !== '') {
            foreach (self::linkErrors(trim($this->link), 'The Pinterest link') as $message) {
                $errors->add('link', $message);
            }
        }

        if ($this->altText !== null && mb_strlen(trim($this->altText)) > self::MAX_ALT_TEXT) {
            $errors->add('alt_text', 'Pinterest alt text cannot be longer than '.self::MAX_ALT_TEXT.' characters.');
        }

        if ($this->boardSectionId !== null && trim($this->boardSectionId) !== '' && preg_match('/^\d+$/', trim($this->boardSectionId)) !== 1) {
            $errors->add('board_section_id', 'The Pinterest board section id must be numeric.');
        }

        if ($this->adAccountId !== null && trim($this->adAccountId) !== '' && preg_match('/^\d+$/', trim($this->adAccountId)) !== 1) {
            $errors->add('ad_account_id', 'The Pinterest ad account id must be numeric.');
        }

        if ($this->coverKeyFrameTime !== null) {
            if ($this->coverKeyFrameTime < 0) {
                $errors->add('cover_key_frame_time', 'The Pinterest cover key frame time cannot be negative.');
            }

            if ($this->thumbnail !== null) {
                $errors->add('cover_key_frame_time', 'Set either a Pinterest cover image (thumbnail) or coverKeyFrameTime, not both.');
            }
        }

        if ($this->thumbnail !== null) {
            if ($this->thumbnail->type !== MediaType::Image) {
                $errors->add('thumbnail', 'The Pinterest video cover must be an image.');
            } elseif (! in_array($this->thumbnail->extension(), ['jpg', 'jpeg', 'png', ''], true)) {
                $errors->add('thumbnail', 'The Pinterest video cover must be a JPEG or PNG.');
            }
        }

        $this->validateCarouselItems($errors);

        return $errors;
    }

    /**
     * Problems with a Pin link: http(s) only, at most 2048 characters.
     *
     * @return list<string>
     */
    public static function linkErrors(string $link, string $label): array
    {
        $errors = [];

        if (preg_match('#^https?://[^\s/]+#i', $link) !== 1) {
            $errors[] = "{$label} must be an http:// or https:// URL.";
        }

        if (strlen($link) > self::MAX_LINK) {
            $errors[] = "{$label} cannot be longer than ".self::MAX_LINK.' characters.';
        }

        return $errors;
    }

    private function validateCarouselItems(MessageBag $errors): void
    {
        foreach ($this->carouselItems as $index => $item) {
            if (! is_int($index) || $index < 0 || $index > 4) {
                $errors->add('carousel_items', 'Pinterest carousel item overrides are keyed by the image position, 0 to 4.');

                continue;
            }

            if (! is_array($item)) {
                $errors->add("carousel_items.{$index}", 'A Pinterest carousel item override must be an array of title, description and link.');

                continue;
            }

            $unknown = array_diff(array_keys($item), self::CAROUSEL_ITEM_KEYS);

            if ($unknown !== []) {
                $errors->add("carousel_items.{$index}", 'Unknown Pinterest carousel item keys: '.implode(', ', $unknown).'; use title, description or link.');
            }

            foreach (['title' => self::MAX_TITLE, 'description' => self::MAX_DESCRIPTION] as $key => $max) {
                $value = $item[$key] ?? null;

                if ($value !== null && (! is_string($value) || mb_strlen(trim($value)) > $max)) {
                    $errors->add("carousel_items.{$index}.{$key}", "A Pinterest carousel item {$key} must be text of at most {$max} characters.");
                }
            }

            $link = $item['link'] ?? null;

            if ($link !== null) {
                $messages = is_string($link) ? self::linkErrors(trim($link), 'A Pinterest carousel item link') : ['A Pinterest carousel item link must be text.'];

                foreach ($messages as $message) {
                    $errors->add("carousel_items.{$index}.link", $message);
                }
            }
        }
    }
}
