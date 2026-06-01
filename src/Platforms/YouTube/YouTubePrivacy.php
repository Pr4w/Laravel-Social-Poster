<?php

namespace SocialPoster\Platforms\YouTube;

enum YouTubePrivacy: string
{
    case Public = 'public';
    case Unlisted = 'unlisted';
    case Private = 'private';
}
