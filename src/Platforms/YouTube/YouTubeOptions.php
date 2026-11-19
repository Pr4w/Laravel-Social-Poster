<?php

namespace SocialPoster\Platforms\YouTube;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\MessageBag;
use SocialPoster\Concerns\CarriesExtraPayload;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\Media;

/**
 * YouTube upload options.
 *
 * The video title comes from the builder's ->title() and the description from
 * ->caption(), since YouTube has both fields. The typed knobs here cover the
 * common video-resource settings; anything else on the snippet or status object
 * (publicStatsViewable, recordingDetails, localizations, ...) rides extra(),
 * which the driver deep-merges into the matching sub-object (driver keys win).
 * extra['query'] is lifted into the videos.insert query string
 * (onBehalfOfContentOwner + onBehalfOfContentOwnerChannel).
 *
 * Scheduling: set $publishAt. The video is then uploaded as private (YouTube only
 * accepts publishAt on private videos) and goes public at that time:
 *
 *   new YouTubeOptions(publishAt: now()->addDay())
 *
 * The 1.0 escape hatch still works and now also forces a private upload when no
 * privacy is given:
 *
 *   new YouTubeOptions(privacyStatus: YouTubePrivacy::Private, extra: ['status' => ['publishAt' => '2026-07-01T09:00:00Z']])
 *
 * $privacyStatus is null by default: public, or private when the video is
 * scheduled. An explicit Public or Unlisted together with a publish time is a
 * validation error.
 */
final class YouTubeOptions implements PlatformOptions
{
    use CarriesExtraPayload;

    /** Licences YouTube accepts for status.license. */
    public const LICENSES = ['youtube', 'creativeCommon'];

    /** snippet.tags may total 500 characters (commas count, a tag with a space counts its quotes). */
    public const TAGS_MAX_LENGTH = 500;

    /**
     * @param  ?YouTubePrivacy  $privacyStatus  Null: public, or private when scheduled.
     * @param  string[]  $tags
     * @param  ?string  $categoryId  Numeric category id (e.g. '22' People & Blogs); null lets YouTube pick.
     * @param  array<string, mixed>  $extra
     * @param  ?DateTimeInterface  $publishAt  Scheduled publish time (forces a private upload).
     * @param  bool  $notifySubscribers  False sends notifySubscribers=false (no subscriber notification).
     * @param  ?bool  $containsSyntheticMedia  The "altered or synthetic content" disclosure.
     * @param  ?string  $license  'youtube' or 'creativeCommon'.
     * @param  ?string  $defaultLanguage  Language of the title and description (BCP-47, e.g. 'fr').
     * @param  ?string  $defaultAudioLanguage  Language spoken in the video.
     * @param  string[]  $playlistIds  Playlists the video is added to after the upload (needs the youtube or youtube.force-ssl scope; failures are warnings).
     * @param  bool  $waitForProcessing  Report Published only once YouTube finished processing (or rejected) the video.
     */
    public function __construct(
        public readonly ?YouTubePrivacy $privacyStatus = null,
        public readonly array $tags = [],
        public readonly ?string $categoryId = null,
        public readonly bool $madeForKids = false,
        public readonly ?Media $thumbnail = null,
        public readonly array $extra = [],
        public readonly ?DateTimeInterface $publishAt = null,
        public readonly bool $notifySubscribers = true,
        public readonly ?bool $containsSyntheticMedia = null,
        public readonly ?bool $embeddable = null,
        public readonly ?string $license = null,
        public readonly ?string $defaultLanguage = null,
        public readonly ?string $defaultAudioLanguage = null,
        public readonly array $playlistIds = [],
        public readonly bool $waitForProcessing = false,
    ) {}

    public function platform(): Platform
    {
        return Platform::YouTube;
    }

    /**
     * The scheduled publish time as YouTube expects it (UTC, ISO-8601 Zulu):
     * the typed $publishAt, else extra['status']['publishAt'] (1.0 style).
     */
    public function scheduledAt(): ?string
    {
        if ($this->publishAt !== null) {
            return CarbonImmutable::instance($this->publishAt)->utc()->format('Y-m-d\TH:i:s\Z');
        }

        $legacy = $this->extra['status']['publishAt'] ?? null;

        return is_string($legacy) && trim($legacy) !== '' ? trim($legacy) : null;
    }

    public function isScheduled(): bool
    {
        return $this->scheduledAt() !== null;
    }

    /** The privacy sent to YouTube: the explicit one, else private when scheduled, else public. */
    public function resolvedPrivacy(): YouTubePrivacy
    {
        return $this->privacyStatus ?? ($this->isScheduled() ? YouTubePrivacy::Private : YouTubePrivacy::Public);
    }

    /**
     * The length YouTube counts for the tag list: every tag, plus the commas
     * between them, plus 2 for each tag containing a space (it is quoted).
     */
    public function tagsLength(): int
    {
        $tags = array_values(array_filter($this->tags, static fn ($tag) => is_scalar($tag) && (string) $tag !== ''));
        $length = max(0, count($tags) - 1);

        foreach ($tags as $tag) {
            $length += mb_strlen((string) $tag) + (str_contains((string) $tag, ' ') ? 2 : 0);
        }

        return $length;
    }

    public function validate(): MessageBag
    {
        $errors = new MessageBag;

        $this->validateTags($errors);

        if ($this->categoryId !== null && ! ctype_digit($this->categoryId)) {
            $errors->add('categoryId', "YouTube category ids are numeric (e.g. '22' for People & Blogs), got '{$this->categoryId}'.");
        }

        if ($this->license !== null && ! in_array($this->license, self::LICENSES, true)) {
            $errors->add('license', "YouTube licences are 'youtube' or 'creativeCommon', got '{$this->license}'.");
        }

        if ($this->isScheduled()) {
            if ($this->privacyStatus !== null && $this->privacyStatus !== YouTubePrivacy::Private) {
                $errors->add('publishAt', "A scheduled YouTube video must be private until its publish time; drop privacyStatus or set it to Private (got {$this->privacyStatus->value}).");
            }

            if ($this->publishAt !== null && $this->publishAt->getTimestamp() <= time()) {
                $errors->add('publishAt', 'The YouTube publish time must be in the future.');
            }
        }

        foreach ($this->playlistIds as $index => $playlistId) {
            if (! is_string($playlistId) || trim($playlistId) === '') {
                $errors->add('playlistIds', "Playlist id #{$index} is empty.");
            }
        }

        return $errors;
    }

    private function validateTags(MessageBag $errors): void
    {
        foreach ($this->tags as $index => $tag) {
            if (! is_scalar($tag) || trim((string) $tag) === '') {
                $errors->add('tags', "Tag #{$index} is empty.");

                continue;
            }

            if (preg_match('/[<>]/', (string) $tag) === 1) {
                $errors->add('tags', "YouTube tags can't contain < or > (tag #{$index}).");
            }
        }

        $length = $this->tagsLength();

        if ($length > self::TAGS_MAX_LENGTH) {
            $errors->add('tags', 'YouTube tags total '.self::TAGS_MAX_LENGTH." characters at most, counting the commas between tags and 2 extra for each tag with a space (yours total {$length}).");
        }
    }
}
