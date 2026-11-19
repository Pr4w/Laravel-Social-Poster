<?php

namespace SocialPoster\Platforms\Threads;

use Illuminate\Support\MessageBag;
use SocialPoster\Concerns\CarriesExtraPayload;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;

/**
 * Typed Threads post options. Every field is a post-level setting: the driver
 * sends it on the single container or on the carousel parent, never on carousel
 * items (Threads refuses is_made_with_ai on an item, for one).
 *
 * - $replyControl: who may reply (reply_control).
 * - $topicTag: the post's topic (topic_tag), 1-50 characters without '.' or
 *   '&'; a leading '#' is dropped.
 * - $linkAttachment: the URL shown as a link preview (link_attachment). Text
 *   posts only. It counts toward the 5-link limit unless the text already
 *   contains it.
 * - $quotePostId: quote another Threads post (quote_post_id).
 * - $replyToId: publish this post as a reply (reply_to_id), e.g. to build a
 *   multi-post thread.
 * - $locationId: tag a place (location_id). Needs the threads_location_tagging
 *   permission (App Review); when the credentials list their scopes, a missing
 *   one is a validation error under credentials.scopes.
 * - $allowlistedCountryCodes: only show the post in these countries
 *   (allowlisted_country_codes, ISO 3166-1 alpha-2, e.g. ['US', 'CA']).
 * - $madeWithAi: label the post as AI-generated (is_made_with_ai).
 * - $spoilerMedia: blur the media as a spoiler (is_spoiler_media). Media
 *   posts only.
 * - $pollAttachment: a 2-4 option poll (poll_attachment), 1-25 characters
 *   per option. Text posts only.
 * - $extra: raw params for anything not modelled here (gif_attachment,
 *   text_entities, enable_reply_approvals...), merged into the single container
 *   or carousel parent. Driver-computed keys win, and auto_publish_text is
 *   always dropped (the driver publishes through threads_publish).
 */
final class ThreadsOptions implements PlatformOptions
{
    use CarriesExtraPayload;

    /**
     * @param  string[]  $allowlistedCountryCodes  ISO 3166-1 alpha-2 codes.
     * @param  array<string, mixed>  $extra  Raw params merged into the post-level container create.
     */
    public function __construct(
        public readonly ?ThreadsReplyControl $replyControl = null,
        public readonly ?string $topicTag = null,
        public readonly ?string $linkAttachment = null,
        public readonly ?string $quotePostId = null,
        public readonly ?string $replyToId = null,
        public readonly ?string $locationId = null,
        public readonly array $allowlistedCountryCodes = [],
        public readonly bool $madeWithAi = false,
        public readonly bool $spoilerMedia = false,
        public readonly ?ThreadsPoll $pollAttachment = null,
        public readonly array $extra = [],
    ) {}

    public function platform(): Platform
    {
        return Platform::Threads;
    }

    /** The topic tag as sent: trimmed, without a leading '#', or null when blank. */
    public function normalizedTopicTag(): ?string
    {
        $tag = ltrim(trim((string) $this->topicTag), '#');

        return $tag !== '' ? $tag : null;
    }

    public function validate(): MessageBag
    {
        $errors = new MessageBag;

        if ($this->topicTag !== null) {
            $tag = $this->normalizedTopicTag();
            $length = $tag === null ? 0 : mb_strlen($tag);

            if ($length < 1 || $length > 50) {
                $errors->add('options.topic_tag', "A Threads topic tag must be 1 to 50 characters long (got {$length}).");
            }

            if ($tag !== null && (str_contains($tag, '.') || str_contains($tag, '&'))) {
                $errors->add('options.topic_tag', "A Threads topic tag cannot contain '.' or '&'.");
            }
        }

        if ($this->linkAttachment !== null && ! self::isHttpUrl($this->linkAttachment)) {
            $errors->add('options.link_attachment', 'The Threads link attachment must be an http(s) URL.');
        }

        foreach ($this->allowlistedCountryCodes as $code) {
            if (! is_string($code) || preg_match('/^[A-Z]{2}$/', trim($code)) !== 1) {
                $errors->add('options.allowlisted_country_codes', 'Threads country codes must be two uppercase letters (ISO 3166-1 alpha-2), e.g. US.');
                break;
            }
        }

        if ($this->pollAttachment !== null) {
            $errors->merge($this->pollAttachment->validate());
        }

        return $errors;
    }

    private static function isHttpUrl(string $url): bool
    {
        $url = trim($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
