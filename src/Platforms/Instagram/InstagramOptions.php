<?php

namespace SocialPoster\Platforms\Instagram;

use Illuminate\Support\MessageBag;
use SocialPoster\Concerns\CarriesExtraPayload;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\MediaType;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\Media;

/**
 * Instagram-specific options. Only the fields validation needs are typed; the
 * long tail of Graph params (location_id, user_tags, audio_name, trial_params,
 * product_tags...) goes through $extra, which is merged into the post's
 * container create (driver keys win; on a story the keys a STORIES container
 * refuses are dropped). Per-item carousel params go in Media::$meta['instagram'].
 *
 * - $thumbnail: the reel cover, a JPEG of at most 8 MB that Instagram fetches
 *   by URL. Without it the video's own Media::video(thumbnail:) is used when it
 *   is a JPEG.
 * - $shareToFeed: reels only (share_to_feed).
 * - $thumbOffsetMs: reels only, the cover frame in milliseconds (thumb_offset);
 *   an alternative to $thumbnail, not combinable with it.
 * - $collaborators: up to 3 usernames invited as collaborators (feed photos,
 *   reels and carousels; not stories).
 */
final class InstagramOptions implements PlatformOptions
{
    use CarriesExtraPayload;

    /**
     * @param  array<string, mixed>  $extra  Raw params merged into the /media container
     *                                       create (e.g. ['trial_params' => [...]]).
     * @param  list<string>  $collaborators  Instagram usernames (with or without "@").
     */
    public function __construct(
        public readonly bool $isStory = false,
        public readonly ?Media $thumbnail = null, // reel cover image
        public readonly array $extra = [],
        public readonly ?bool $shareToFeed = null,
        public readonly ?int $thumbOffsetMs = null,
        public readonly array $collaborators = [],
    ) {}

    public function platform(): Platform
    {
        return Platform::Instagram;
    }

    public function validate(): MessageBag
    {
        $errors = new MessageBag;

        if ($this->thumbnail !== null) {
            if ($this->isStory) {
                $errors->add('thumbnail', 'Instagram stories do not take a cover image.');
            }

            if ($this->thumbnail->type !== MediaType::Image) {
                $errors->add('thumbnail', 'The Instagram cover must be an image.');
            } elseif (! in_array($this->thumbnail->extension(), ['jpg', 'jpeg', ''], true)) {
                $errors->add('thumbnail', 'The Instagram cover must be a JPEG (.jpg or .jpeg).');
            }
        }

        if ($this->thumbOffsetMs !== null) {
            if ($this->thumbOffsetMs < 0) {
                $errors->add('thumb_offset', 'thumbOffsetMs cannot be negative.');
            }

            if ($this->thumbnail !== null) {
                $errors->add('thumb_offset', 'Set either an Instagram cover image or thumbOffsetMs, not both.');
            }

            if ($this->isStory) {
                $errors->add('thumb_offset', 'Instagram stories do not take a thumb offset.');
            }
        }

        if ($this->shareToFeed !== null && $this->isStory) {
            $errors->add('share_to_feed', 'shareToFeed applies to reels, not stories.');
        }

        if ($this->collaborators !== []) {
            if ($this->isStory) {
                $errors->add('collaborators', 'Instagram stories cannot have collaborators.');
            }

            if (count($this->collaborators) > 3) {
                $errors->add('collaborators', 'Instagram allows at most 3 collaborators.');
            }

            foreach ($this->collaborators as $name) {
                if (! is_string($name) || ltrim(trim($name), '@') === '') {
                    $errors->add('collaborators', 'Instagram collaborators must be usernames.');
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Options serialised by 1.0 (inside queued jobs) have no values for the
     * fields added in 1.1; give them their defaults instead of leaving the
     * readonly properties uninitialised.
     *
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        $thumbnail = $data['thumbnail'] ?? null;
        $extra = $data['extra'] ?? [];
        $shareToFeed = $data['shareToFeed'] ?? null;
        $thumbOffsetMs = $data['thumbOffsetMs'] ?? null;
        $collaborators = $data['collaborators'] ?? [];

        $this->isStory = (bool) ($data['isStory'] ?? false);
        $this->thumbnail = $thumbnail instanceof Media ? $thumbnail : null;
        $this->extra = is_array($extra) ? $extra : [];
        $this->shareToFeed = is_bool($shareToFeed) ? $shareToFeed : null;
        $this->thumbOffsetMs = is_int($thumbOffsetMs) ? $thumbOffsetMs : null;
        $this->collaborators = is_array($collaborators) ? array_values($collaborators) : [];
    }
}
