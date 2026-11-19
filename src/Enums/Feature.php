<?php

namespace SocialPoster\Enums;

/**
 * What a token is being asked to do. Platforms grant permissions per feature
 * (publishing, commenting, video upload, documents), and the scopes differ by
 * login type and account kind, so drivers declare them per feature through
 * AbstractPlatform::requiredScopes().
 */
enum Feature: string
{
    /** Create a post (any media). */
    case Publish = 'publish';

    /** Add the first comment / reply to a published post. */
    case Comment = 'comment';

    /** Upload a video (some platforms need an extra scope for it). */
    case Video = 'video';

    /** Upload a document (LinkedIn PDF carousels). */
    case Document = 'document';

    /** The feature a piece of media needs on top of Publish, if any. */
    public static function forMedia(MediaType $type): ?self
    {
        return match ($type) {
            MediaType::Video => self::Video,
            MediaType::Document => self::Document,
            default => null,
        };
    }
}
