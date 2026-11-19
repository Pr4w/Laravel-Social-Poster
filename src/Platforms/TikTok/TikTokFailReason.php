<?php

namespace SocialPoster\Platforms\TikTok;

use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;

/**
 * TikTok's fail_reason values (status/fetch data.fail_reason, and the
 * post.publish.failed webhook's content.reason), mapped once for the driver's
 * status poll and for TikTokWebhookEvent:
 *
 * - *_check_failed: the media breaks a TikTok spec (MediaRejected);
 * - internal, video_pull_failed, photo_pull_failed: worth a fresh publish
 *   (restartable(); the driver restarts at most social.api.tiktok.max_restarts
 *   times). A pull also fails when TikTok's download runs past one hour;
 * - auth_removed: the creator revoked the app (InvalidToken, terminal);
 * - spam_risk_*: TikTok's anti-spam decisions, never retried;
 * - publish_cancelled: cancelled on TikTok's side (Unknown).
 *
 * A reason TikTok adds later maps to MediaRejected, as every FAILED status did
 * in 1.0, so code branching on that reason keeps working.
 */
final class TikTokFailReason
{
    /** Reasons a fresh publish can fix. */
    public const RESTARTABLE = ['internal', 'video_pull_failed', 'photo_pull_failed'];

    /**
     * Seconds until a TikTok daily cap is assumed to reset: the per-creator
     * post cap (spam_risk_too_many_posts) and the client's quota of active
     * publishing users (reached_active_user_cap).
     */
    public const DAILY_CAP_SECONDS = 86400;

    public static function reason(string $failReason): FailureReason
    {
        return match (strtolower(trim($failReason))) {
            'file_format_check_failed', 'duration_check_failed', 'frame_rate_check_failed',
            'picture_size_check_failed', 'video_pull_failed', 'photo_pull_failed' => FailureReason::MediaRejected,
            'internal' => FailureReason::ServerError,
            'auth_removed' => FailureReason::InvalidToken,
            'spam_risk_too_many_posts' => FailureReason::RateLimited,
            'spam_risk_user_banned_from_posting' => FailureReason::AccountRestricted,
            'spam_risk_text', 'spam_risk' => FailureReason::InvalidContent,
            'publish_cancelled' => FailureReason::Unknown,
            default => FailureReason::MediaRejected,
        };
    }

    /** A readable explanation for the consumer (TikTok's codes are terse). */
    public static function message(string $failReason): string
    {
        $failReason = strtolower(trim($failReason));

        return match ($failReason) {
            'file_format_check_failed' => 'TikTok rejected the media format: videos must be MP4, MOV or WebM (H.264, H.265, VP8 or VP9), photos JPEG or WebP.',
            'duration_check_failed' => "TikTok rejected the video duration: it is outside what this creator may post (see creator_info's max_video_post_duration_sec).",
            'frame_rate_check_failed' => 'TikTok rejected the video frame rate: it must be between 23 and 60 fps.',
            'picture_size_check_failed' => 'TikTok rejected the photo size: photos may be at most 1080p and 20MB.',
            'internal' => 'TikTok failed to process the post (internal error).',
            'video_pull_failed' => 'TikTok could not download the video: the URL must be public https, must not redirect, must sit on a verified domain or URL prefix, and the download must finish within one hour.',
            'photo_pull_failed' => 'TikTok could not download a photo: the URL must be public https, must not redirect and must sit on a verified domain or URL prefix.',
            'publish_cancelled' => 'The TikTok publish was cancelled.',
            'auth_removed' => 'The creator removed this app\'s access to their TikTok account; it must be reconnected.',
            'spam_risk_too_many_posts' => 'TikTok daily post limit reached for this creator; try again tomorrow.',
            'spam_risk_user_banned_from_posting' => 'This TikTok creator is banned from making new posts.',
            'spam_risk_text' => 'TikTok flagged the post text as spam.',
            'spam_risk' => 'TikTok flagged the post as spam.',
            '' => 'TikTok failed to process the post.',
            default => "TikTok failed to process the post: {$failReason}.",
        };
    }

    /** True when a fresh publish (new init) may succeed. */
    public static function restartable(string $failReason): bool
    {
        return in_array(strtolower(trim($failReason)), self::RESTARTABLE, true);
    }

    /**
     * Extra exception context for a reason: the retry hint of the daily cap,
     * the terminal flag of a revoked authorization.
     *
     * @return array<string, mixed>
     */
    public static function context(string $failReason, ?int $now = null): array
    {
        return match (strtolower(trim($failReason))) {
            'spam_risk_too_many_posts' => self::dailyCapContext($now),
            'auth_removed' => ['terminal' => true],
            default => [],
        };
    }

    /**
     * The permanent failure for a fail_reason (restartable reasons included,
     * once the driver has given up restarting).
     *
     * @param  array<string, mixed>  $context
     */
    public static function exception(string $failReason, array $context = []): PermanentException
    {
        return new PermanentException(
            self::message($failReason),
            Platform::TikTok,
            self::reason($failReason),
            $context + ['fail_reason' => $failReason] + self::context($failReason),
        );
    }

    /**
     * Context for TikTok's daily caps (owner decision: a permanent RateLimited
     * the consumer reschedules, never a wait inside the queue).
     *
     * @return array{retry_after_hint: int, retry_at: string}
     */
    public static function dailyCapContext(?int $now = null): array
    {
        $now ??= time();

        return [
            'retry_after_hint' => self::DAILY_CAP_SECONDS,
            'retry_at' => gmdate('Y-m-d\TH:i:s\Z', $now + self::DAILY_CAP_SECONDS),
        ];
    }
}
