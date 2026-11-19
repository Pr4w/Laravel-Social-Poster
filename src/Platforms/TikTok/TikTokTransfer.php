<?php

namespace SocialPoster\Platforms\TikTok;

/**
 * How a TikTok VIDEO reaches TikTok. Photos are always pulled from a URL,
 * because TikTok only accepts PULL_FROM_URL for photo posts.
 *
 *  - Auto (default): a remote https URL is pulled (PULL_FROM_URL). A local
 *    file, or a URL TikTok cannot pull (http), is uploaded in chunks
 *    (FILE_UPLOAD). When TikTok refuses a pull with url_ownership_unverified
 *    (the host is not a verified domain or URL prefix of your TikTok app), the
 *    driver retries once with FILE_UPLOAD.
 *  - PullFromUrl: always PULL_FROM_URL. The URL must be https, must not
 *    redirect, and must sit on a domain or URL prefix verified in the TikTok
 *    developer portal. Local files need a gateway that re-hosts them
 *    (FilesystemMediaGateway).
 *  - FileUpload: always FILE_UPLOAD. Remote media is downloaded through the
 *    MediaGateway first. Needs no domain verification.
 */
enum TikTokTransfer: string
{
    case Auto = 'auto';
    case PullFromUrl = 'pull_from_url';
    case FileUpload = 'file_upload';
}
