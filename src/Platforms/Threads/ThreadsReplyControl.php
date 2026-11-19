<?php

namespace SocialPoster\Platforms\Threads;

/**
 * Who may reply to a Threads post (the container's reply_control field).
 * Threads defaults to Everyone when it is not sent.
 */
enum ThreadsReplyControl: string
{
    case Everyone = 'everyone';
    case AccountsYouFollow = 'accounts_you_follow';
    case MentionedOnly = 'mentioned_only';
    case ParentPostAuthorOnly = 'parent_post_author_only';
    case FollowersOnly = 'followers_only';
}
