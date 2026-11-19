<?php

namespace SocialPoster\Platforms\TikTok;

use SocialPoster\Enums\FailureReason;

/**
 * One TikTok webhook delivery, as returned by TikTokWebhook::parse(). The
 * nested JSON 'content' string TikTok sends is already decoded into $content,
 * and its usual fields are lifted into typed properties:
 *
 * - authorization.removed: $userOpenId (store the creator's open_id to match
 *   it), $reason (TikTok's numeric removal reason, as a string);
 * - post.publish.inbox_delivered / post.publish.complete: $publishId;
 * - post.publish.publicly_available / no_longer_publicaly_available: $publishId
 *   and $postId (the public video id);
 * - post.publish.failed: $publishId and $reason (a fail_reason, mapped by
 *   failureReason() through the same table as the driver's status poll).
 *
 * TikTok documents the removal event as no_longer_publicaly_available (the
 * same misspelling as its publicaly_available_post_id field). $event keeps
 * what TikTok sent; is() treats that spelling and the corrected one
 * (NO_LONGER_PUBLICALY_AVAILABLE, NO_LONGER_PUBLICLY_AVAILABLE) as the same
 * event, so test events with is() rather than comparing $event.
 *
 * The package ships no route or controller: verify the signature with
 * TikTokWebhook::verify() in your own endpoint, then parse.
 */
final class TikTokWebhookEvent
{
    public const AUTHORIZATION_REMOVED = 'authorization.removed';

    public const PUBLISH_INBOX_DELIVERED = 'post.publish.inbox_delivered';

    public const PUBLISH_COMPLETE = 'post.publish.complete';

    public const PUBLICLY_AVAILABLE = 'post.publish.publicly_available';

    /** The removal event as TikTok documents it (spelled "publicaly"). */
    public const NO_LONGER_PUBLICALY_AVAILABLE = 'post.publish.no_longer_publicaly_available';

    /** The corrected spelling; is() matches either. */
    public const NO_LONGER_PUBLICLY_AVAILABLE = 'post.publish.no_longer_publicly_available';

    public const PUBLISH_FAILED = 'post.publish.failed';

    /** Spellings of one event, mapped to a single name for is(). */
    private const ALIASES = [
        self::NO_LONGER_PUBLICLY_AVAILABLE => self::NO_LONGER_PUBLICALY_AVAILABLE,
    ];

    /**
     * @param  array<string, mixed>  $content  The decoded 'content' field.
     * @param  array<string, mixed>  $raw  The whole decoded body.
     */
    public function __construct(
        public readonly string $event,
        public readonly ?string $userOpenId = null,
        public readonly ?string $publishId = null,
        public readonly ?string $postId = null,
        public readonly ?string $reason = null,
        public readonly ?string $clientKey = null,
        public readonly ?int $createTime = null,
        public readonly ?string $publishType = null,
        public readonly array $content = [],
        public readonly array $raw = [],
    ) {}

    /** True when the event is one of $events (either spelling of an event TikTok misspells). */
    public function is(string ...$events): bool
    {
        $event = self::ALIASES[$this->event] ?? $this->event;

        foreach ($events as $candidate) {
            if ((self::ALIASES[$candidate] ?? $candidate) === $event) {
                return true;
            }
        }

        return false;
    }

    /** True for the post.publish.* family. */
    public function isPublishEvent(): bool
    {
        return str_starts_with($this->event, 'post.publish.');
    }

    /** True when the creator revoked the app or a publish failed. */
    public function isFailure(): bool
    {
        return $this->is(self::PUBLISH_FAILED, self::AUTHORIZATION_REMOVED);
    }

    /**
     * The package's reason for a failure event: the fail_reason table for
     * post.publish.failed, InvalidToken for authorization.removed, else null.
     */
    public function failureReason(): ?FailureReason
    {
        return match ($this->event) {
            self::PUBLISH_FAILED => TikTokFailReason::reason((string) $this->reason),
            self::AUTHORIZATION_REMOVED => FailureReason::InvalidToken,
            default => null,
        };
    }

    /** A readable message for a failure event, else null. */
    public function failureMessage(): ?string
    {
        return match ($this->event) {
            self::PUBLISH_FAILED => TikTokFailReason::message((string) $this->reason),
            self::AUTHORIZATION_REMOVED => TikTokFailReason::message('auth_removed'),
            default => null,
        };
    }

    /** True for a post.publish.failed whose reason a fresh publish may fix (internal, *_pull_failed). */
    public function isRestartableFailure(): bool
    {
        return $this->event === self::PUBLISH_FAILED && TikTokFailReason::restartable((string) $this->reason);
    }

    /**
     * The public permalink, when the event carries a numeric post id and the
     * creator's username (from creator_info, or your own records) is valid.
     */
    public function postUrl(?string $username): ?string
    {
        return TikTokDriver::permalink($username, $this->postId);
    }
}
