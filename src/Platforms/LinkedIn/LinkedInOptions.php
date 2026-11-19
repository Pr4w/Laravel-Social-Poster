<?php

namespace SocialPoster\Platforms\LinkedIn;

use Illuminate\Support\MessageBag;
use SocialPoster\Concerns\CarriesExtraPayload;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;

/**
 * LinkedIn post options. The first three constructor positions are v1.0's
 * (escapeText, extra, mentions); everything after them is new in 1.1 and best
 * passed by name.
 *
 * The typed fields cover what `extra` cannot: the driver sets visibility,
 * distribution and isReshareDisabledByAuthor itself, and driver keys win over
 * `extra`, so these are the only way to change them.
 */
final class LinkedInOptions implements PlatformOptions
{
    use CarriesExtraPayload;

    public const VISIBILITY_PUBLIC = 'PUBLIC';

    public const VISIBILITY_CONNECTIONS = 'CONNECTIONS';

    public const VISIBILITY_LOGGED_IN = 'LOGGED_IN';

    public const VISIBILITIES = [self::VISIBILITY_PUBLIC, self::VISIBILITY_CONNECTIONS, self::VISIBILITY_LOGGED_IN];

    public const FEED_MAIN = 'MAIN_FEED';

    public const FEED_NONE = 'NONE';

    public const FEED_DISTRIBUTIONS = [self::FEED_MAIN, self::FEED_NONE];

    /** A post that LinkedIn can reshare: share or ugcPost URN. */
    private const RESHARE_PATTERN = '/^urn:li:(share|ugcPost):\d+\z/';

    /** @var list<LinkedInMention> */
    public readonly array $mentions;

    public readonly string $visibility;

    public readonly string $feedDistribution;

    public readonly ?string $reshareOf;

    public readonly ?LinkedInPoll $poll;

    public readonly ?LinkedInArticle $article;

    /**
     * - $escapeText: escape LinkedIn's reserved characters in the caption. Set
     *   false when you supply pre-formatted "little text" by hand, which escaping
     *   would break. Ignored when $mentions is non-empty: the driver then owns
     *   escaping.
     * - $extra: raw params merged into the /posts create.
     * - $mentions: entity mentions referenced from the caption by {{mention:N}}
     *   markers, where N is the mention's index here. Plain arrays are accepted
     *   and normalised. A marker with no entry fails validation. The name must
     *   match LinkedIn's name exactly (see LinkedInMention).
     * - $visibility: PUBLIC (default), CONNECTIONS or LOGGED_IN.
     * - $disableReshare: stop other members from resharing the post.
     * - $feedDistribution: MAIN_FEED (default) or NONE, a "dark" post that never
     *   shows in the feed (organization authors only).
     * - $targetEntities: targeted organic post facets (geoLocations,
     *   seniorities, industries...); organization authors only, and LinkedIn
     *   needs a target audience above 300.
     * - $reshareOf: reshare this post (urn:li:share:… or urn:li:ugcPost:…); the
     *   caption becomes the reshare commentary and may be empty.
     * - $poll: an organic poll under the caption.
     * - $article: a link card for a text post.
     * - $autoLinkCard: build a link card from the Open Graph tags of the first
     *   non-LinkedIn link in the caption (text posts only). A card that cannot
     *   be built is skipped; the post still goes out.
     * - $autoMentionUrls: turn linkedin.com/in/…, /company/… and /school/… links
     *   in the caption into mentions, looking the exact name up with the posting
     *   token. A link that cannot be resolved stays a link. Person profiles
     *   resolve only for organization authors, or when the credentials carry an
     *   `organization` URN the member administers. Ignored with escapeText: false
     *   and no $mentions.
     *
     * @param  array<string, mixed>  $extra
     * @param  list<LinkedInMention|array{urn: string, name: string}>  $mentions
     * @param  array<int, array<string, mixed>>  $targetEntities
     * @param  LinkedInPoll|array<string, mixed>|null  $poll
     * @param  LinkedInArticle|array<string, mixed>|null  $article
     */
    public function __construct(
        public readonly bool $escapeText = true,
        public readonly array $extra = [],
        array $mentions = [],
        string $visibility = self::VISIBILITY_PUBLIC,
        public readonly bool $disableReshare = false,
        string $feedDistribution = self::FEED_MAIN,
        public readonly array $targetEntities = [],
        ?string $reshareOf = null,
        LinkedInPoll|array|null $poll = null,
        LinkedInArticle|array|null $article = null,
        public readonly bool $autoLinkCard = false,
        public readonly bool $autoMentionUrls = false,
    ) {
        $this->mentions = self::normaliseMentions($mentions);
        $this->visibility = strtoupper(trim($visibility));
        $this->feedDistribution = strtoupper(trim($feedDistribution));
        $this->reshareOf = $reshareOf !== null && trim($reshareOf) !== '' ? trim($reshareOf) : null;
        $this->poll = is_array($poll) ? LinkedInPoll::fromArray($poll) : $poll;
        $this->article = is_array($article) ? LinkedInArticle::fromArray($article) : $article;
    }

    public function platform(): Platform
    {
        return Platform::LinkedIn;
    }

    public function validate(): MessageBag
    {
        $errors = new MessageBag;

        foreach ($this->mentions as $index => $mention) {
            if (! $mention->hasValidUrn()) {
                $errors->add("mentions.{$index}", "Mention #{$index} has an invalid URN; expected urn:li:person:… or urn:li:organization:….");
            }

            if (trim($mention->name) === '') {
                $errors->add("mentions.{$index}", "Mention #{$index} is missing a display name.");
            }
        }

        if (! in_array($this->visibility, self::VISIBILITIES, true)) {
            $errors->add('visibility', 'LinkedIn visibility must be one of '.implode(', ', self::VISIBILITIES).'.');
        }

        if (! in_array($this->feedDistribution, self::FEED_DISTRIBUTIONS, true)) {
            $errors->add('feedDistribution', 'LinkedIn feedDistribution must be MAIN_FEED or NONE.');
        }

        foreach ($this->targetEntities as $index => $entity) {
            if (! is_array($entity) || $entity === []) {
                $errors->add('targetEntities', "Target entity #{$index} must be a non-empty array of targeting facets.");
            }
        }

        if ($this->reshareOf !== null && preg_match(self::RESHARE_PATTERN, $this->reshareOf) !== 1) {
            $errors->add('reshareOf', 'reshareOf must be a post URN (urn:li:share:… or urn:li:ugcPost:…).');
        }

        foreach ($this->poll?->errors() ?? [] as $message) {
            $errors->add('poll', $message);
        }

        foreach ($this->article?->errors() ?? [] as $message) {
            $errors->add('article', $message);
        }

        $kinds = array_keys(array_filter([
            'article' => $this->article !== null,
            'poll' => $this->poll !== null,
            'reshareOf' => $this->reshareOf !== null,
        ]));

        if (count($kinds) > 1) {
            $errors->add($kinds[1], 'A LinkedIn post takes only one of a link card (article), a poll or a reshare; got '.implode(' and ', $kinds).'.');
        }

        return $errors;
    }

    /**
     * Restores options queued by v1.0 (escapeText, extra and mentions only):
     * the 1.1 fields get their defaults instead of staying uninitialised.
     *
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        $this->escapeText = (bool) ($data['escapeText'] ?? true);
        $this->extra = is_array($data['extra'] ?? null) ? $data['extra'] : [];
        $this->mentions = self::normaliseMentions(is_array($data['mentions'] ?? null) ? $data['mentions'] : []);
        $this->visibility = is_string($data['visibility'] ?? null) ? $data['visibility'] : self::VISIBILITY_PUBLIC;
        $this->disableReshare = (bool) ($data['disableReshare'] ?? false);
        $this->feedDistribution = is_string($data['feedDistribution'] ?? null) ? $data['feedDistribution'] : self::FEED_MAIN;
        $this->targetEntities = is_array($data['targetEntities'] ?? null) ? $data['targetEntities'] : [];
        $this->reshareOf = is_string($data['reshareOf'] ?? null) ? $data['reshareOf'] : null;
        $this->poll = ($data['poll'] ?? null) instanceof LinkedInPoll ? $data['poll'] : null;
        $this->article = ($data['article'] ?? null) instanceof LinkedInArticle ? $data['article'] : null;
        $this->autoLinkCard = (bool) ($data['autoLinkCard'] ?? false);
        $this->autoMentionUrls = (bool) ($data['autoMentionUrls'] ?? false);
    }

    /**
     * @param  array<int|string, mixed>  $mentions
     * @return list<LinkedInMention>
     */
    private static function normaliseMentions(array $mentions): array
    {
        return array_map(
            static fn ($mention) => $mention instanceof LinkedInMention
                ? $mention
                : new LinkedInMention(
                    is_array($mention) ? (string) ($mention['urn'] ?? '') : '',
                    is_array($mention) ? (string) ($mention['name'] ?? '') : '',
                ),
            array_values($mentions),
        );
    }
}
