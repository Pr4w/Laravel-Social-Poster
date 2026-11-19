<?php

namespace SocialPoster\Platforms\YouTube;

/**
 * status.privacyStatus. A scheduled video (YouTubeOptions::$publishAt) must be
 * Private until its publish time. YouTube may still apply Private to a Public
 * or Unlisted request (channel settings, unaudited API projects): the result
 * payload then carries privacy_status and privacy_warning.
 */
enum YouTubePrivacy: string
{
    case Public = 'public';
    case Unlisted = 'unlisted';
    case Private = 'private';
}
