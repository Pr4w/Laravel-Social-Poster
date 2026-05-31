<?php

namespace SocialPoster\Platforms;

use Illuminate\Support\MessageBag;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Concerns\ValidatesAgainstCapabilities;
use SocialPoster\Contracts\MediaGateway;
use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Contracts\SocialPlatform;
use SocialPoster\Enums\Ingestion;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\Pending;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\Published;
use SocialPoster\ValueObjects\PublishOutcome;

/**
 * Base class for every concrete driver. Slots a driver can fill:
 *
 *   - rulesFor()              the Capabilities that apply to THIS post.
 *   - validatePlatformRules() conditional / cross-field validation.
 *   - prepare()               content transformation into the payload.
 *   - ingestion()             how the platform takes media (pull URL vs upload).
 *   - publish() / resume()    the publish flow; resume() only for async platforms.
 */
abstract class AbstractPlatform implements SocialPlatform
{
    use ValidatesAgainstCapabilities;

    public function __construct(
        protected ?MediaInspector $inspector = null,
        protected ?MediaGateway $gateway = null,
    ) {}

    abstract public function platform(): Platform;

    abstract public function capabilities(): Capabilities;

    public function validate(PreparedPost $post): MessageBag
    {
        $errors = new MessageBag();
        $caps = $this->rulesFor($post);

        $this->validateAgainstCapabilities($post, $caps, $errors);
        $this->validatePlatformRules($post, $errors);
        $this->validateMediaTransfer($post, $errors);

        $options = $post->options();

        if ($options !== null && $options->platform() === $this->platform()) {
            $errors->merge($options->validate());
        }

        return $errors;
    }

    abstract public function publish(PreparedPost $post): PublishOutcome;

    /** Synchronous platforms never pend, so this is never called for them. */
    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        throw new PermanentException(
            "{$this->platform()->value} does not support resumable publishing.",
            $this->platform(),
        );
    }

    protected function rulesFor(PreparedPost $post): Capabilities
    {
        return $this->capabilities();
    }

    protected function validatePlatformRules(PreparedPost $post, MessageBag $errors): void
    {
        //
    }

    protected function ingestion(): Ingestion
    {
        return Ingestion::Upload;
    }

    protected function validateMediaTransfer(PreparedPost $post, MessageBag $errors): void
    {
        if ($this->ingestion() !== Ingestion::PullUrl || $this->gateway === null) {
            return;
        }

        foreach ($post->media() as $index => $media) {
            if (! $this->gateway->canProvideUrl($media)) {
                $errors->add(
                    "media.{$index}",
                    "{$this->platform()->value} fetches media from a public URL, but this is a local file. Host it publicly or bind a MediaGateway that can publish local files.",
                );
            }
        }
    }

    /** Optional content transformation into the platform payload. */
    protected function prepare(PreparedPost $post): array
    {
        return [];
    }

    protected function published(PostResult $result): Published
    {
        return PublishOutcome::published($result);
    }

    /** @param array<string, mixed> $state */
    protected function pending(array $state, int $recheckAfter = 30): Pending
    {
        return PublishOutcome::pending($state, $recheckAfter);
    }

    protected function inspector(): ?MediaInspector
    {
        return $this->inspector;
    }

    protected function gateway(): ?MediaGateway
    {
        return $this->gateway;
    }

    protected function mediaUrl(Media $media): string
    {
        return $this->gateway?->url($media) ?? $media->source;
    }

    protected function mediaPath(Media $media): string
    {
        return $this->gateway?->path($media) ?? $media->source;
    }
}
