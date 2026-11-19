<?php

namespace SocialPoster\Platforms\LinkedIn;

/**
 * A link card ("article" content) for a LinkedIn text post.
 *
 * LinkedIn does not scrape URLs for API posts: without an article, a link in
 * the commentary stays a bare link with no preview. The partner must send the
 * card's fields itself, and the thumbnail must be an image uploaded through the
 * Images API, which the driver does for you from $thumbnail.
 *
 * - $source: the URL the card opens (required, http or https).
 * - $title: the card title (required by LinkedIn).
 * - $description: optional line under the title.
 * - $thumbnail: optional image as a local path, an http(s) URL, or an image URN
 *   (urn:li:image:…) already uploaded. A thumbnail that cannot be fetched or
 *   uploaded is dropped; the card still goes out.
 * - $thumbnailAltText: optional alt text for the thumbnail (up to 4,086 characters).
 *
 * A card cannot be combined with media, a poll or a reshare. To build cards
 * from the caption automatically (Open Graph tags of the first link), use
 * LinkedInOptions(autoLinkCard: true) instead.
 */
final class LinkedInArticle
{
    /** LinkedIn's documented alt-text limit. */
    public const MAX_ALT_TEXT = 4086;

    public readonly string $source;

    public function __construct(
        string $source,
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $thumbnail = null,
        public readonly ?string $thumbnailAltText = null,
    ) {
        $this->source = trim($source);
    }

    public static function make(
        string $source,
        ?string $title = null,
        ?string $description = null,
        ?string $thumbnail = null,
        ?string $thumbnailAltText = null,
    ): self {
        return new self($source, $title, $description, $thumbnail, $thumbnailAltText);
    }

    /**
     * Build from an array with the keys source, title, description, thumbnail
     * and thumbnailAltText (snake_case thumbnail_alt_text is accepted too).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $string = static fn (string ...$keys): ?string => self::firstString($data, $keys);

        return new self(
            (string) $string('source', 'url'),
            $string('title'),
            $string('description'),
            $string('thumbnail'),
            $string('thumbnailAltText', 'thumbnail_alt_text'),
        );
    }

    /**
     * Why LinkedIn would refuse this card (empty when it is valid).
     *
     * @return list<string>
     */
    public function errors(): array
    {
        $errors = [];

        if ($this->source === '') {
            $errors[] = 'The link card needs a source URL.';
        } elseif (preg_match('#^https?://[^\s/?\#]+#i', $this->source) !== 1) {
            $errors[] = 'The link card source must be an http(s) URL.';
        }

        if ($this->title === null || trim($this->title) === '') {
            $errors[] = 'The link card needs a title (LinkedIn does not read it from the page).';
        }

        if ($this->thumbnailAltText !== null && mb_strlen($this->thumbnailAltText) > self::MAX_ALT_TEXT) {
            $errors[] = 'The link card thumbnail alt text exceeds '.self::MAX_ALT_TEXT.' characters.';
        }

        return $errors;
    }

    /**
     * The Posts API content block. $thumbnailUrn is the uploaded image URN, or
     * null to send the card without a thumbnail.
     *
     * @return array{article: array<string, string>}
     */
    public function toContent(?string $thumbnailUrn = null): array
    {
        $article = ['source' => $this->source];

        foreach (['title' => $this->title, 'description' => $this->description] as $key => $value) {
            if ($value !== null && trim($value) !== '') {
                $article[$key] = trim($value);
            }
        }

        if ($thumbnailUrn !== null && $thumbnailUrn !== '') {
            $article['thumbnail'] = $thumbnailUrn;

            if ($this->thumbnailAltText !== null && trim($this->thumbnailAltText) !== '') {
                $article['thumbnailAltText'] = trim($this->thumbnailAltText);
            }
        }

        return ['article' => $article];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     */
    private static function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== '') {
                return (string) $data[$key];
            }
        }

        return null;
    }
}
