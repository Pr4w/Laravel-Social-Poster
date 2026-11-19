<?php

namespace SocialPoster\Events;

use SocialPoster\Enums\Platform;

class CommentPublished
{
    use SerializesForQueue;

    /**
     * @param  array<string, mixed>  $metadata  Caller correlation data from ->withMetadata().
     * @param  ?string  $url  Permalink of the comment or reply, when the platform gives one.
     * @param  array<string, mixed>  $payload  Extra platform data (e.g. the Bluesky reply cid and uri).
     */
    public function __construct(
        public readonly Platform $platform,
        public readonly string $postId,
        public readonly ?string $commentId,
        public readonly array $metadata = [],
        public readonly ?string $url = null,
        public readonly array $payload = [],
    ) {}
}
