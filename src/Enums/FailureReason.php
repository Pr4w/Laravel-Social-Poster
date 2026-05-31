<?php

namespace SocialPoster\Enums;

/**
 * Granularity for failures without a separate exception subclass per case.
 * Read it off the exception when you need to branch on the specific cause.
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
}
