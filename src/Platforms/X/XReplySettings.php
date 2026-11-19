<?php

namespace SocialPoster\Platforms\X;

/**
 * Who may reply to a post (POST /2/tweets reply_settings). Leaving it unset
 * means everyone can reply, which X has no explicit value for.
 */
enum XReplySettings: string
{
    /** Only accounts the author follows. */
    case Following = 'following';

    /** Only accounts mentioned in the post. */
    case MentionedUsers = 'mentionedUsers';

    /** Only the author's subscribers. */
    case Subscribers = 'subscribers';

    /** Only verified accounts. */
    case Verified = 'verified';
}
