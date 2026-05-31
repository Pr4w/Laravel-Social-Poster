<?php

namespace SocialPoster\Platforms\TikTok;

/**
 * Privacy levels for a direct post. The set actually permitted for a given
 * creator is returned by the creator-info query at publish time and is enforced
 * against this choice; an unaudited client typically only allows SelfOnly.
 */
enum TikTokPrivacy: string
{
    case PublicToEveryone = 'PUBLIC_TO_EVERYONE';
    case MutualFollowFriends = 'MUTUAL_FOLLOW_FRIENDS';
    case FollowerOfCreator = 'FOLLOWER_OF_CREATOR';
    case SelfOnly = 'SELF_ONLY';
}
