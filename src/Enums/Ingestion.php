<?php

namespace SocialPoster\Enums;

/**
 * How a platform takes delivery of media.
 *   PullUrl - the platform fetches from a public URL (Facebook, Instagram).
 *   Upload  - the platform needs the raw bytes (X, LinkedIn, Mastodon, ...).
 *   Both    - either works (TikTok).
 */
enum Ingestion: string
{
    case PullUrl = 'pull_url';
    case Upload = 'upload';
    case Both = 'both';
}
