<?php

namespace SocialPoster\Enums;

/**
 * Granularity for failures without a separate exception subclass per case.
 * Read it off the exception when you need to branch on the specific cause.
 *
 * New cases may be added in minor releases: give exhaustive match() blocks a
 * default arm, or branch on category() / requiresReconnect() instead.
 */
enum FailureReason: string
{
    case RateLimited = 'rate_limited';
    case ServerError = 'server_error';
    case Timeout = 'timeout';
    case InvalidToken = 'invalid_token';
    case InsufficientPermissions = 'insufficient_permissions';
    case AccountRestricted = 'account_restricted';
    case MediaRejected = 'media_rejected';
    case DuplicateContent = 'duplicate_content';
    case Unknown = 'unknown';

    /** A field or parameter the platform refused (title too long, bad topic tag, too many links). */
    case InvalidContent = 'invalid_content';

    /** Pre-flight validation failed; nothing was sent to the platform. */
    case Validation = 'validation';

    /** A create call was ambiguous (timeout after sending): the post may be live. */
    case OutcomeUnknown = 'outcome_unknown';

    /** The platform kept processing past the allowed wait. */
    case ProcessingTimeout = 'processing_timeout';

    /** Connection-level failure (DNS, refused, TLS, timeout) with no HTTP response. */
    case Network = 'network';

    /** The target no longer exists (deleted board, parent post, upload session). */
    case NotFound = 'not_found';

    /** Operator-side billing or credits exhausted (e.g. X 402). */
    case QuotaExceeded = 'quota_exceeded';

    /** The publish window (notAfter) passed before the post could go out. */
    case Expired = 'expired';

    /** Missing credential key or bad package/app configuration; reconnecting will not help. */
    case Misconfigured = 'misconfigured';

    /** The account must be reconnected (new token or new scopes) before it can post again. */
    public function requiresReconnect(): bool
    {
        return $this === self::InvalidToken || $this === self::InsufficientPermissions;
    }

    /**
     * Coarse bucket, aligned with pr4w/social-metrics ErrorCategory where the
     * reasons overlap:
     *
     * - retry: transient; posting again is safe.
     * - verify: the post may already be live (an ambiguous create). Check the
     *   platform before posting again; never re-post automatically.
     * - reconnect: token or scopes; the account must be reconnected.
     * - user_fix: content or account the user must change.
     * - operator: package, app or billing setup.
     * - not_found: the target no longer exists.
     *
     * ProcessingTimeout stays under retry because most of them leave nothing
     * behind (an expired container, an upload that never became a post). When
     * a specific failure may have published, its exception carries the
     * possibly_published flag, and SocialPosterException::errorSummary() then
     * reports 'verify' whatever the reason: read the category from there.
     */
    public function category(): string
    {
        return match ($this) {
            self::RateLimited, self::ServerError, self::Timeout, self::Network,
            self::ProcessingTimeout => 'retry',
            self::OutcomeUnknown => 'verify',
            self::InvalidToken, self::InsufficientPermissions => 'reconnect',
            self::AccountRestricted, self::MediaRejected, self::DuplicateContent,
            self::InvalidContent, self::Validation, self::Expired => 'user_fix',
            self::Misconfigured, self::QuotaExceeded, self::Unknown => 'operator',
            self::NotFound => 'not_found',
        };
    }
}
