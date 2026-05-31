<?php

namespace SocialPoster\Platforms\TikTok;

/**
 * Where a TikTok post lands. These map to different scopes, endpoints, and
 * requirements, which is why this is a first-class typed option rather than a
 * raw flag:
 *
 *  - DirectPost: publishes straight to the creator's profile. Needs the
 *    video.publish scope and an audited client, requires a creator-info query
 *    first, and requires a privacy level. Unaudited clients are forced private.
 *  - Drafts: uploads to the creator's TikTok inbox for them to finish and post
 *    in-app. Needs only video.upload, no creator-info query, no privacy level.
 *    This is the default because it works without going through TikTok's audit.
 */
enum TikTokTarget: string
{
    case DirectPost = 'direct_post';
    case Drafts = 'drafts';
}
