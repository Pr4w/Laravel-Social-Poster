<?php

namespace SocialPoster\Platforms\Facebook;

use DateTimeInterface;
use Illuminate\Support\MessageBag;
use SocialPoster\Concerns\CarriesExtraPayload;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;
use SocialPoster\Support\Text\Urls;
use SocialPoster\ValueObjects\Media;

final class FacebookOptions implements PlatformOptions
{
    use CarriesExtraPayload;

    /** Facebook refuses a scheduled publish time less than 10 minutes ahead. */
    public const MIN_SCHEDULE_AHEAD = 600;

    /** Reels accept at most 29 days ahead (Page posts allow more; 29 days is safe for every variant). */
    public const MAX_SCHEDULE_AHEAD = 29 * 86400;

    /**
     * Every constructor argument is optional and new ones are only ever added
     * at the end, so positional v1.0 calls keep working.
     *
     * @param  bool  $isStory  Publish as a Page story (one photo or one 9:16 video) instead of a feed post.
     * @param  ?Media  $thumbnail  Custom thumbnail for a regular (non-Reel) video: bmp, gif, jpeg,
     *                             jpg, png or tiff, at most 10MB (validated, and required to be
     *                             readable at publish time). Facebook takes none for Reels,
     *                             stories, photos or text posts: there it is ignored, as in v1.0,
     *                             and a warning is logged. Without it, the Media::video()
     *                             thumbnail is sent when Facebook can take it (best-effort).
     * @param  array<string, mixed>  $extra  Raw params merged into the post-level create: /feed,
     *                                       /videos, the Reel or video-story finish, or
     *                                       /photo_stories. Driver keys win. extra['photo'] (an
     *                                       array) is merged into each /photos upload instead
     *                                       (alt_text_custom, place, no_story...).
     * @param  ?bool  $asReel  Route a single feed video: true = Reel, false = regular video, null =
     *                         decide from its shape (9:16 => Reel) using the inspector or the
     *                         width/height given in Media::$meta.
     * @param  string|false|null  $link  Link card for a text post: a URL, false for no link card,
     *                                   or null to use the first link in the caption.
     * @param  ?DateTimeInterface  $scheduledAt  Publish later as a Facebook scheduled post or Reel,
     *                                           10 minutes to 29 days ahead (a Reel: 15 minutes, as
     *                                           its finish call goes out later). Not for stories.
     */
    public function __construct(
        public readonly bool $isStory = false,
        public readonly ?Media $thumbnail = null,
        public readonly array $extra = [],
        public readonly ?bool $asReel = null,
        public readonly string|false|null $link = null,
        public readonly ?DateTimeInterface $scheduledAt = null,
    ) {}

    public function platform(): Platform
    {
        return Platform::Facebook;
    }

    /**
     * Self-contained rules. Rules that need the post (the thumbnail of a
     * regular video, story media, a scheduled Reel's lead time) live in
     * FacebookDriver::validatePlatformRules().
     */
    public function validate(): MessageBag
    {
        $errors = new MessageBag;

        if ($this->scheduledAt !== null) {
            $ahead = $this->scheduledAt->getTimestamp() - time();

            if ($this->isStory) {
                $errors->add('scheduledAt', "Facebook stories can't be scheduled; publish the story when it should go live.");
            } elseif ($ahead < self::MIN_SCHEDULE_AHEAD) {
                $errors->add('scheduledAt', 'Facebook needs a scheduled post to be at least 10 minutes in the future.');
            } elseif ($ahead > self::MAX_SCHEDULE_AHEAD) {
                $errors->add('scheduledAt', 'Facebook can schedule a post at most 29 days ahead.');
            }
        }

        if (is_string($this->link)) {
            $link = trim($this->link);

            if ($link === '' || filter_var(Urls::absolute($link), FILTER_VALIDATE_URL) === false) {
                $errors->add('link', 'The Facebook link must be a URL. Pass false for no link card, or null to use the first link in the caption.');
            }
        }

        return $errors;
    }

    /**
     * Options serialized by v1.0 (inside queued jobs) carry only isStory,
     * thumbnail and extra; give the newer properties their defaults so a job
     * queued before the upgrade still runs after it.
     *
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        $this->isStory = (bool) ($data['isStory'] ?? false);
        $this->thumbnail = ($data['thumbnail'] ?? null) instanceof Media ? $data['thumbnail'] : null;
        $this->extra = is_array($data['extra'] ?? null) ? $data['extra'] : [];
        $this->asReel = is_bool($data['asReel'] ?? null) ? $data['asReel'] : null;
        $this->link = is_string($data['link'] ?? null) || ($data['link'] ?? null) === false ? $data['link'] : null;
        $this->scheduledAt = ($data['scheduledAt'] ?? null) instanceof DateTimeInterface ? $data['scheduledAt'] : null;
    }
}
