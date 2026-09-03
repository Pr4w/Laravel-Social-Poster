<?php

namespace SocialPoster\Platforms\LinkedIn;

use Illuminate\Support\MessageBag;
use SocialPoster\Concerns\CarriesExtraPayload;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;

final class LinkedInOptions implements PlatformOptions
{
    use CarriesExtraPayload;

    /** @var list<LinkedInMention> */
    public readonly array $mentions;

    /**
     * @param bool $escapeText Escape LinkedIn's reserved characters in the caption.
     *                         Set false when you supply pre-formatted "little text"
     *                         by hand, which escaping would break. Ignored when
     *                         $mentions is non-empty: the driver then owns escaping.
     * @param array<string, mixed> $extra Raw params merged into the /posts create.
     * @param list<LinkedInMention|array{urn: string, name: string}> $mentions Entity
     *        mentions referenced from the caption by {{mention:N}} markers, where N
     *        is the mention's index here. Plain arrays are accepted and normalised.
     */
    public function __construct(
        public readonly bool $escapeText = true,
        public readonly array $extra = [],
        array $mentions = [],
    ) {
        $this->mentions = array_map(
            static fn ($mention) => $mention instanceof LinkedInMention
                ? $mention
                : new LinkedInMention((string) ($mention['urn'] ?? ''), (string) ($mention['name'] ?? '')),
            array_values($mentions),
        );
    }

    public function platform(): Platform
    {
        return Platform::LinkedIn;
    }

    public function validate(): MessageBag
    {
        $errors = new MessageBag();

        foreach ($this->mentions as $index => $mention) {
            if (! $mention->hasValidUrn()) {
                $errors->add("mentions.{$index}", "Mention #{$index} has an invalid URN; expected urn:li:person:… or urn:li:organization:….");
            }

            if (trim($mention->name) === '') {
                $errors->add("mentions.{$index}", "Mention #{$index} is missing a display name.");
            }
        }

        return $errors;
    }
}
