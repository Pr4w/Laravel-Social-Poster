<?php

namespace SocialPoster\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SocialPoster\Contracts\SupportsComments;
use SocialPoster\Enums\Platform;
use SocialPoster\Events\CommentFailed;
use SocialPoster\Events\CommentPublished;
use SocialPoster\Exceptions\SocialPosterException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\SocialManager;
use SocialPoster\ValueObjects\Credentials;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\SocialPost;

/**
 * Adds a first comment to an already-published post. Dispatched by the publish
 * job once a post is confirmed live, so the post id exists and the media is
 * available to comment on. Kept separate on purpose: the post has already
 * succeeded, so a comment failure here must never retry or undo it.
 *
 * Credentials are serialised into the queue payload; use an encrypted or trusted
 * queue store if your tokens are sensitive.
 */
class AddFirstCommentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly Platform $platform,
        public readonly string $postId,
        public readonly string $comment,
        public readonly Credentials $credentials,
        public readonly array $metadata = [],
    ) {}

    public function handle(SocialManager $platforms, Dispatcher $events): void
    {
        $driver = $platforms->driver($this->platform->value);

        // Safety net: only supporting drivers are ever dispatched here.
        if (! $driver instanceof SupportsComments) {
            return;
        }

        $context = new PreparedPost($this->platform, new SocialPost(), $this->credentials);

        try {
            $commentId = $driver->comment($context, $this->postId, $this->comment);

            $events->dispatch(new CommentPublished($this->platform, $this->postId, $commentId, $this->metadata));
        } catch (TemporaryException $e) {
            if ($this->attempts() >= $this->tries) {
                $events->dispatch(new CommentFailed($this->platform, $this->postId, $e, $this->metadata));
                $this->fail($e);

                return;
            }

            $this->release($e->retryAfter ?? 60);
        } catch (SocialPosterException $e) {
            $events->dispatch(new CommentFailed($this->platform, $this->postId, $e, $this->metadata));
            $this->fail($e);
        }
    }
}
