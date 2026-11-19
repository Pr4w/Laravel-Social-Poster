<?php

namespace SocialPoster\Platforms\X;

use Illuminate\Support\MessageBag;
use SocialPoster\Concerns\CarriesExtraPayload;
use SocialPoster\Contracts\PlatformOptions;
use SocialPoster\Enums\Platform;

/**
 * X post options, validated and merged into POST /2/tweets.
 *
 * - $replySettings: who may reply (an XReplySettings case, or its string value).
 * - $quoteTweetId: quote another post. X takes quote_tweet_id on Enterprise
 *   API plans only (not on self-serve / pay-per-use plans), and refuses the
 *   post when it is created, after any media was uploaded. On such a plan set
 *   api.x.allow_quote_posts to false to refuse it at validation instead.
 * - $replyToTweetId: publish as a reply. Since February 2026 X refuses
 *   programmatic replies to other people's posts unless the author mentioned or
 *   engaged with you; replies to your own posts (threads) still work.
 * - $poll: a poll (no media, no quote).
 *
 * Anything else POST /2/tweets accepts (for_super_followers_only, geo,
 * community_id, nullcast, made_with_ai, media.tagged_user_ids,
 * reply.exclude_reply_user_ids...) rides $extra. The driver deep-merges the
 * 'media', 'reply' and 'poll' objects, and its own keys win on collision:
 *
 *   new XOptions(replyToTweetId: '1880...', extra: ['reply' => ['exclude_reply_user_ids' => ['123']]])
 */
final class XOptions implements PlatformOptions
{
    use CarriesExtraPayload;

    /**
     * @param  array<string, mixed>  $extra  Raw params merged into the POST /2/tweets body.
     */
    public function __construct(
        public readonly XReplySettings|string|null $replySettings = null,
        public readonly ?string $quoteTweetId = null,
        public readonly ?string $replyToTweetId = null,
        public readonly ?XPoll $poll = null,
        public readonly array $extra = [],
    ) {}

    public function platform(): Platform
    {
        return Platform::X;
    }

    /** The reply_settings value to send, or null to leave replies open. */
    public function replySettingsValue(): ?string
    {
        if ($this->replySettings instanceof XReplySettings) {
            return $this->replySettings->value;
        }

        $value = trim((string) $this->replySettings);

        return $value !== '' ? $value : null;
    }

    /**
     * The typed options as POST /2/tweets fields (no extra; the driver merges it).
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [];

        if (($settings = $this->replySettingsValue()) !== null) {
            $payload['reply_settings'] = $settings;
        }

        if (($quote = $this->id($this->quoteTweetId)) !== null) {
            $payload['quote_tweet_id'] = $quote;
        }

        if (($reply = $this->id($this->replyToTweetId)) !== null) {
            $payload['reply'] = ['in_reply_to_tweet_id' => $reply];
        }

        if ($this->poll !== null) {
            $payload['poll'] = $this->poll->toArray();
        }

        return $payload;
    }

    public function validate(): MessageBag
    {
        $errors = new MessageBag;

        $settings = $this->replySettingsValue();

        if ($settings !== null && ! $this->replySettings instanceof XReplySettings && XReplySettings::tryFrom($settings) === null) {
            $allowed = implode(', ', array_map(static fn (XReplySettings $case) => $case->value, XReplySettings::cases()));
            $errors->add('options.reply_settings', "Unknown X reply setting '{$settings}'; use one of: {$allowed} (or leave it unset so everyone can reply).");
        }

        foreach (['quote_tweet_id' => $this->quoteTweetId, 'reply_to_tweet_id' => $this->replyToTweetId] as $field => $value) {
            if ($value !== null && trim($value) !== '' && preg_match('/^\d{1,19}$/', trim($value)) !== 1) {
                $errors->add("options.{$field}", "The X {$field} must be a numeric post id, got '{$value}'.");
            }
        }

        if ($this->poll !== null) {
            foreach ($this->poll->errors() as $message) {
                $errors->add('options.poll', $message);
            }

            if ($this->id($this->quoteTweetId) !== null) {
                $errors->add('options.poll', "X polls can't be combined with a quote post.");
            }
        }

        return $errors;
    }

    private function id(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
