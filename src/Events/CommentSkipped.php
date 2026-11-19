<?php

namespace SocialPoster\Events;

use SocialPoster\Enums\Platform;

/**
 * A first comment was requested but deliberately not attempted. Before 1.1 these
 * cases were silent; now an app can record why (for example "posted without the
 * comment: stories cannot take comments").
 *
 * $reason is one of the REASON_* constants. More may be added in minor releases.
 */
class CommentSkipped
{
    use SerializesForQueue;

    /** The driver has no comment support. */
    public const REASON_UNSUPPORTED = 'unsupported';

    /** The post variant cannot take comments (a story). */
    public const REASON_STORY = 'story';

    /** The credentials list their scopes and the comment scope is not among them. */
    public const REASON_MISSING_SCOPE = 'missing_scope';

    /** The comment text was blank. */
    public const REASON_EMPTY = 'empty';

    /** The publish returned no usable post id to comment on. */
    public const REASON_NO_POST_ID = 'no_post_id';

    /** The post is scheduled, a draft or otherwise not live yet. */
    public const REASON_SCHEDULED = 'scheduled';

    /** The comment was already added by an earlier run (replay). */
    public const REASON_ALREADY_DONE = 'already_done';

    /**
     * A reconcile replayed the published record with credentials of another
     * account than the one the post was published with (a ref, the app's
     * default account), so the comment is not sent with them.
     */
    public const REASON_ACCOUNT_MISMATCH = 'account_mismatch';

    /**
     * @param  ?string  $postId  The parent post id, or null when there is none (REASON_NO_POST_ID).
     * @param  string  $reason  One of the REASON_* constants.
     * @param  array<string, mixed>  $metadata  Caller correlation data from ->withMetadata().
     */
    public function __construct(
        public readonly Platform $platform,
        public readonly ?string $postId,
        public readonly string $reason,
        public readonly array $metadata = [],
    ) {}

    /** @return list<string> */
    public static function reasons(): array
    {
        return [
            self::REASON_UNSUPPORTED,
            self::REASON_STORY,
            self::REASON_MISSING_SCOPE,
            self::REASON_EMPTY,
            self::REASON_NO_POST_ID,
            self::REASON_SCHEDULED,
            self::REASON_ALREADY_DONE,
            self::REASON_ACCOUNT_MISMATCH,
        ];
    }
}
