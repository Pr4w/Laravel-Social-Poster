<?php

namespace SocialPoster\ValueObjects;

use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\SocialPosterException;

/**
 * The outcome of a publish for one platform. $payload carries what was actually
 * sent and/or the raw platform response, which is what PostPublished hands back.
 * $metadata is the caller's own correlation data from ->withMetadata(); the
 * package never reads it, it just carries it back so you can tie a result to
 * whatever you track internally (a scheduled_post_id, a job uuid, and so on).
 */
final class PostResult
{
    public function __construct(
        public readonly Platform $platform,
        public readonly bool $success,
        public readonly ?string $platformPostId = null,
        public readonly ?string $url = null,
        public readonly array $payload = [],
        public readonly ?SocialPosterException $error = null,
        public readonly array $metadata = [],
    ) {}

    public static function success(
        Platform $platform,
        ?string $platformPostId = null,
        ?string $url = null,
        array $payload = [],
    ): self {
        return new self($platform, true, $platformPostId, $url, $payload);
    }

    public static function failed(Platform $platform, SocialPosterException $error): self
    {
        return new self($platform, false, error: $error);
    }

    /** Return a copy carrying the caller's correlation metadata. */
    public function withMetadata(array $metadata): self
    {
        return new self(
            $this->platform,
            $this->success,
            $this->platformPostId,
            $this->url,
            $this->payload,
            $this->error,
            $metadata,
        );
    }
}
