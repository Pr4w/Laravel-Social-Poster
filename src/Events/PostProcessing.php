<?php

namespace SocialPoster\Events;

use SocialPoster\Enums\Platform;

/**
 * The publish is waiting before its next step: the platform is still
 * processing the upload (video transcoding, container status, publish job),
 * or a create step waits (media not ready yet, an earlier attempt being
 * verified, a temporary refusal being retried). Fired once per wait, so an app
 * can show progress, and alert when $elapsedSeconds grows unusually large.
 * Not fired for the immediate hop into a create step.
 */
class PostProcessing
{
    use SerializesForQueue;

    /** The platform is processing the media (a container, a transcode, an upload job). */
    public const REASON_PROCESSING = 'processing';

    /** The platform refused to publish yet because the media is not ready (Instagram 2207027). */
    public const REASON_NOT_READY = 'not_ready';

    /** An earlier publish lost its answer: the next run checks the platform before publishing again. */
    public const REASON_VERIFYING = 'verifying';

    /** The platform refused the publish for now (a throttle, a transient error): it is retried. */
    public const REASON_RETRYING = 'retrying';

    /**
     * @param  int  $poll  Continuation number (1 for the first re-check).
     * @param  int  $elapsedSeconds  Seconds since the first publish() call of this post.
     * @param  array<string, mixed>  $metadata  Caller correlation data from ->withMetadata().
     * @param  ?string  $correlationId  The id returned by post(), shared by a fan-out.
     * @param  ?string  $reason  Why the publish waits (a REASON_* value), or null when the driver does not say.
     * @param  array<string, string>  $identifiers  Ids the platform already gave for this publish (TikTok:
     *                                              publish_id, the key of its webhooks); empty when none yet.
     */
    public function __construct(
        public readonly Platform $platform,
        public readonly int $poll,
        public readonly int $elapsedSeconds,
        public readonly array $metadata = [],
        public readonly ?string $correlationId = null,
        public readonly ?string $reason = null,
        public readonly array $identifiers = [],
    ) {}
}
