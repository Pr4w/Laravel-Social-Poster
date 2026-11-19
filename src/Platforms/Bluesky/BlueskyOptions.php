<?php

namespace SocialPoster\Platforms\Bluesky;

use Illuminate\Support\MessageBag;
use SocialPoster\Concerns\CarriesExtraPayload;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;
use SocialPoster\Support\Text\Text;

/**
 * Bluesky post options. A post with no options behaves like the production
 * app: links, #hashtags and @mentions become facets, and a text post with a
 * link gets a link card.
 *
 * Record fields:
 * - $langs: up to 3 BCP-47 tags (record.langs), so language feeds pick it up.
 * - $tags: up to 8 extra hashtags not shown in the text (record.tags).
 * - $selfLabels: content warnings the author applies (sexual, nudity, porn,
 *   graphic-media; '!no-unauthenticated' hides it from logged-out viewers).
 * - $replyTo: post as a reply, {uri, cid, root?: {uri, cid}}. Without root,
 *   the driver reads the parent (getRecord) and uses its thread root, so a
 *   thread can be posted one replyTo at a time.
 * - $quote: quote a post, {uri, cid}; combined with media it becomes a
 *   record-with-media embed.
 *
 * Gates (written with the post in one com.atproto.repo.applyWrites call):
 * - $replyControl: null = anyone can reply; [] = nobody; otherwise up to 5 of
 *   'mention', 'following', 'followers' and list AT URIs (at://…/app.bsky.graph.list/…).
 * - $disableQuotes: nobody can quote the post (a postgate).
 *
 * Rich text:
 * - $detectFacets: detect links, #hashtags and @mentions (on by default).
 * - $mentions: handle => DID, skipping the handle lookup for those handles.
 * - $facets: raw app.bsky.richtext.facet entries appended as given (advanced;
 *   byte offsets into the text as sent, i.e. after $shortenLinks).
 * - $shortenLinks: show links as "host/path…" like the Bluesky app, keeping the
 *   full URL in the link facet. Length limits are checked on the shortened text.
 *
 * Embeds:
 * - $linkPreview: build a link card from the first https link when the post
 *   has no media and no quote (best effort, never fails the post).
 * - $linkCard: an explicit card {uri, title, description?, thumb?: url|path};
 *   nothing is fetched except the thumbnail.
 * - $videoCaptions: lang => WebVTT file (path or URL), up to 20, 20,000 bytes each.
 * - $videoPresentation: 'default' or 'gif' (loops silently like a GIF).
 *
 * $extra is merged into the post record; the driver's own keys ($type, text,
 * createdAt, facets, embed, reply, and langs/labels/tags when set here) win.
 */
final class BlueskyOptions implements PlatformOptions
{
    use CarriesExtraPayload;

    public const SELF_LABELS = ['sexual', 'nudity', 'porn', 'graphic-media', '!no-unauthenticated'];

    public const REPLY_RULES = ['mention', 'following', 'followers'];

    public const MAX_LANGS = 3;

    public const MAX_TAGS = 8;

    public const MAX_GATE_RULES = 5;

    public const MAX_CAPTIONS = 20;

    public const MAX_CAPTION_BYTES = 20000;

    /** at://did/app.bsky.feed.post/rkey */
    public const POST_URI_PATTERN = '#^at://did:[a-z0-9]+:[A-Za-z0-9._:%-]+/app\.bsky\.feed\.post/[A-Za-z0-9._:~-]{1,512}$#';

    /** at://did/app.bsky.graph.list/rkey */
    public const LIST_URI_PATTERN = '#^at://did:[a-z0-9]+:[A-Za-z0-9._:%-]+/app\.bsky\.graph\.list/[A-Za-z0-9._:~-]{1,512}$#';

    /**
     * @param  list<string>|null  $langs
     * @param  array{uri?: string, title?: string, description?: string, thumb?: string}|null  $linkCard
     * @param  array<string, string>  $mentions  handle => did
     * @param  list<array<string, mixed>>  $facets
     * @param  list<string>  $tags
     * @param  list<string>  $selfLabels
     * @param  list<string>|null  $replyControl
     * @param  array{uri?: string, cid?: string, root?: array{uri?: string, cid?: string}}|null  $replyTo
     * @param  array{uri?: string, cid?: string}|null  $quote
     * @param  array<string, string>  $videoCaptions  lang => .vtt path or URL
     * @param  array<string, mixed>  $extra  Raw fields merged into the post record.
     */
    public function __construct(
        public readonly ?array $langs = null,
        public readonly bool $linkPreview = true,
        public readonly ?array $linkCard = null,
        public readonly bool $detectFacets = true,
        public readonly array $mentions = [],
        public readonly array $facets = [],
        public readonly bool $shortenLinks = false,
        public readonly array $tags = [],
        public readonly array $selfLabels = [],
        public readonly ?array $replyControl = null,
        public readonly bool $disableQuotes = false,
        public readonly ?array $replyTo = null,
        public readonly ?array $quote = null,
        public readonly array $videoCaptions = [],
        public readonly ?string $videoPresentation = null,
        public readonly array $extra = [],
    ) {}

    public function platform(): Platform
    {
        return Platform::Bluesky;
    }

    /** True when the post must be written with gates (threadgate / postgate). */
    public function hasGates(): bool
    {
        return $this->replyControl !== null || $this->disableQuotes;
    }

    /**
     * The handle => DID map with handles normalised (lowercase, no '@').
     *
     * @return array<string, string>
     */
    public function mentionMap(): array
    {
        $map = [];

        foreach ($this->mentions as $handle => $did) {
            if (is_string($handle) && is_string($did) && str_starts_with($did, 'did:')) {
                $map[strtolower(ltrim(trim($handle), '@'))] = $did;
            }
        }

        return $map;
    }

    /**
     * The extra hashtags without their '#', blanks dropped.
     *
     * @return list<string>
     */
    public function normalisedTags(): array
    {
        $tags = [];

        foreach ($this->tags as $tag) {
            $tag = is_string($tag) ? ltrim(trim($tag), '#＃') : '';

            if ($tag !== '' && ! in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    public function validate(): MessageBag
    {
        $errors = new MessageBag;

        $this->validateLangs($errors);
        $this->validateTags($errors);
        $this->validateLabels($errors);
        $this->validateMentions($errors);
        $this->validateFacets($errors);
        $this->validateReplyControl($errors);
        $this->validateRef('replyTo', 'reply target', $this->replyTo, $errors);
        $this->validateRef('quote', 'quoted post', $this->quote, $errors);

        if (is_array($this->replyTo['root'] ?? null)) {
            $this->validateRef('replyTo.root', 'thread root', $this->replyTo['root'], $errors);
        }

        $this->validateLinkCard($errors);
        $this->validateCaptions($errors);

        if ($this->videoPresentation !== null && ! in_array($this->videoPresentation, ['default', 'gif'], true)) {
            $errors->add('videoPresentation', "Bluesky video presentation must be 'default' or 'gif'.");
        }

        return $errors;
    }

    private function validateLangs(MessageBag $errors): void
    {
        if ($this->langs === null) {
            return;
        }

        if (count($this->langs) > self::MAX_LANGS) {
            $errors->add('langs', 'A Bluesky post can declare at most '.self::MAX_LANGS.' languages.');
        }

        foreach ($this->langs as $lang) {
            if (! is_string($lang) || preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{1,8})*$/', $lang) !== 1) {
                $errors->add('langs', 'Bluesky post languages must be BCP-47 tags such as "en" or "pt-BR".');
                break;
            }
        }
    }

    private function validateTags(MessageBag $errors): void
    {
        if (count($this->tags) > self::MAX_TAGS) {
            $errors->add('tags', 'A Bluesky post can carry at most '.self::MAX_TAGS.' extra tags.');
        }

        foreach ($this->tags as $tag) {
            $tag = is_string($tag) ? ltrim(trim($tag), '#＃') : '';

            if ($tag === '' || preg_match('/\s/u', $tag) === 1) {
                $errors->add('tags', 'Bluesky tags must be non-empty and contain no spaces.');
                break;
            }

            if (Text::graphemeCount($tag) > 64 || strlen($tag) > 640) {
                $errors->add('tags', 'Bluesky tags are limited to 64 characters (640 bytes).');
                break;
            }
        }
    }

    private function validateLabels(MessageBag $errors): void
    {
        foreach ($this->selfLabels as $label) {
            if (! in_array($label, self::SELF_LABELS, true)) {
                $errors->add('selfLabels', 'Bluesky self-labels must be among: '.implode(', ', self::SELF_LABELS).'.');
                break;
            }
        }
    }

    private function validateMentions(MessageBag $errors): void
    {
        foreach ($this->mentions as $handle => $did) {
            if (! is_string($handle) || trim($handle) === '' || ! is_string($did) || ! str_starts_with($did, 'did:')) {
                $errors->add('mentions', 'Bluesky mentions map handles to DIDs, e.g. ["alice.bsky.social" => "did:plc:…"].');
                break;
            }
        }
    }

    private function validateFacets(MessageBag $errors): void
    {
        foreach ($this->facets as $index => $facet) {
            $start = $facet['index']['byteStart'] ?? null;
            $end = $facet['index']['byteEnd'] ?? null;
            $features = $facet['features'] ?? null;

            if (! is_int($start) || ! is_int($end) || $start < 0 || $end <= $start || ! is_array($features) || $features === []) {
                $errors->add("facets.{$index}", 'A Bluesky facet needs index.byteStart < index.byteEnd (integers) and at least one feature.');
            }
        }
    }

    private function validateReplyControl(MessageBag $errors): void
    {
        if ($this->replyControl === null) {
            return;
        }

        if (count($this->replyControl) > self::MAX_GATE_RULES) {
            $errors->add('replyControl', 'Bluesky reply controls take at most '.self::MAX_GATE_RULES.' rules.');
        }

        foreach ($this->replyControl as $rule) {
            $valid = is_string($rule) && (
                in_array(self::normaliseRule($rule), self::REPLY_RULES, true)
                || preg_match(self::LIST_URI_PATTERN, $rule) === 1
            );

            if (! $valid) {
                $errors->add('replyControl', "Bluesky reply rules are 'mention', 'following', 'followers' or a list AT URI.");
                break;
            }
        }
    }

    /** @param  array<string, mixed>|null  $ref */
    private function validateRef(string $field, string $label, ?array $ref, MessageBag $errors): void
    {
        if ($ref === null) {
            return;
        }

        $uri = $ref['uri'] ?? null;
        $cid = $ref['cid'] ?? null;

        if (! is_string($uri) || preg_match(self::POST_URI_PATTERN, $uri) !== 1) {
            $errors->add($field, "The {$label} must be a post AT URI (at://did:…/app.bsky.feed.post/…).");
        }

        if (! is_string($cid) || trim($cid) === '') {
            $errors->add($field, "The {$label} needs its cid.");
        }
    }

    private function validateLinkCard(MessageBag $errors): void
    {
        if ($this->linkCard === null) {
            return;
        }

        $uri = $this->linkCard['uri'] ?? null;

        if (! is_string($uri) || ! str_starts_with(strtolower($uri), 'https://') || filter_var($uri, FILTER_VALIDATE_URL) === false) {
            $errors->add('linkCard', 'A Bluesky link card needs an https:// uri.');
        }

        foreach (['title', 'description', 'thumb'] as $key) {
            if (isset($this->linkCard[$key]) && ! is_string($this->linkCard[$key])) {
                $errors->add('linkCard', "The link card {$key} must be a string.");
            }
        }
    }

    private function validateCaptions(MessageBag $errors): void
    {
        if (count($this->videoCaptions) > self::MAX_CAPTIONS) {
            $errors->add('videoCaptions', 'A Bluesky video takes at most '.self::MAX_CAPTIONS.' caption files.');
        }

        foreach ($this->videoCaptions as $lang => $source) {
            if (! is_string($lang) || preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{1,8})*$/', $lang) !== 1 || ! is_string($source) || trim($source) === '') {
                $errors->add('videoCaptions', 'Bluesky video captions map a language tag to a .vtt file, e.g. ["en" => "/path/en.vtt"].');
                break;
            }
        }
    }

    /** 'mentions' / 'follower' spellings to the canonical rule name. */
    public static function normaliseRule(string $rule): string
    {
        return match (strtolower(trim($rule))) {
            'mention', 'mentions', 'mentioned' => 'mention',
            'following' => 'following',
            'follower', 'followers' => 'followers',
            default => trim($rule),
        };
    }
}
