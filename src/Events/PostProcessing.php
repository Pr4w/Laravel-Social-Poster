<?php

namespace SocialPoster\Events;

use SocialPoster\Enums\Platform;

/**
 * The platform is still processing the upload (video transcoding, container
 * status, publish job). Fired on each continuation poll, so an app can show
 * progress, and alert when $elapsedSeconds grows unusually large.
 */
class PostProcessing
{
    use SerializesForQueue;

    /**
     * @param  int  $poll  Continuation number (1 for the first re-check).
     * @param  int  $elapsedSeconds  Seconds since the first publish() call of this post.
     * @param  array<string, mixed>  $metadata  Caller correlation data from ->withMetadata().
     * @param  ?string  $correlationId  The id returned by post(), shared by a fan-out.
     */
    public function __construct(
        public readonly Platform $platform,
        public readonly int $poll,
        public readonly int $elapsedSeconds,
        public readonly array $metadata = [],
        public readonly ?string $correlationId = null,
    ) {}
}
