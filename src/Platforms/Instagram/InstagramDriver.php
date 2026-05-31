<?php

namespace SocialPoster\Platforms\Instagram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\MessageBag;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\ImageRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Capabilities\VideoRules;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Ingestion;
use SocialPoster\Enums\MediaType;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\PublishOutcome;

/**
 * Reference driver for the container model. Publishing is: create container(s),
 * wait for Meta to process (a Pending the job re-polls via resume), then publish.
 *
 * Photos are ready immediately and publish in one pass. Videos, reels and
 * carousels create a container and hand off to resume(). The "container not
 * ready" subcode (2207027) becomes a long Pending rather than a separate job,
 * which is what the original UploadMediaInstagramDelayedPublication handled.
 */
class InstagramDriver extends AbstractPlatform
{
    protected string $graph = 'https://graph.facebook.com/v23.0';

    public function platform(): Platform
    {
        return Platform::Instagram;
    }

    protected function ingestion(): Ingestion
    {
        return Ingestion::PullUrl;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            caption: new CaptionRules(max: 2200),
            media: new MediaRules(minCount: 1, maxCount: 10, image: $this->imageRules(), video: $this->reelRules()),
            supportsCarousel: true,
        );
    }

    protected function rulesFor(PreparedPost $post): Capabilities
    {
        $caption = new CaptionRules(max: 2200);
        $media = $post->media();
        $first = $media[0] ?? null;
        $isVideo = $first?->type === MediaType::Video;

        if ($this->options($post)?->isStory) {
            return new Capabilities(
                caption: $caption,
                media: new MediaRules(
                    minCount: 1,
                    maxCount: 1,
                    image: $isVideo ? null : $this->storyPhotoRules(),
                    video: $isVideo ? $this->storyVideoRules() : null,
                ),
            );
        }

        // Carousel: images and videos both allowed, up to 10.
        if (count($media) > 1) {
            return new Capabilities(
                caption: $caption,
                media: new MediaRules(minCount: 2, maxCount: 10, image: $this->imageRules(), video: $this->reelRules()),
                supportsCarousel: true,
            );
        }

        // Single item (image or reel). minCount 1 enforces "at least one media".
        return new Capabilities(
            caption: $caption,
            media: new MediaRules(
                minCount: 1,
                maxCount: 1,
                image: $isVideo ? null : $this->imageRules(),
                video: $isVideo ? $this->reelRules() : null,
            ),
        );
    }

    public function publish(PreparedPost $post): PublishOutcome
    {
        $media = $post->media();

        if (count($media) > 1) {
            return $this->startCarousel($post);
        }

        $item = $media[0];

        if ($item->type === MediaType::Image) {
            return $this->publishPhoto($post, $item);
        }

        return $this->startVideo($post, $item);
    }

    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        return match ($state['phase'] ?? null) {
            'await_container' => $this->awaitContainer($post, $state),
            'await_children' => $this->awaitChildren($post, $state),
            'await_carousel' => $this->awaitCarousel($post, $state),
            'publish_retry' => $this->publishContainer($post, $state['container_id']),
            default => throw new PermanentException('Unknown Instagram publish phase.', Platform::Instagram),
        };
    }

    // --- Single photo: container then publish, no processing wait --------------

    protected function publishPhoto(PreparedPost $post, Media $photo): PublishOutcome
    {
        $params = [
            'image_url' => $this->mediaUrl($photo),
            'caption' => (string) $post->caption(),
        ];

        if ($this->options($post)?->isStory) {
            $params['media_type'] = 'STORIES';
        }

        $container = $this->http($post)->post($this->edge($post, '/media'), $this->mergeExtra($post, $params));

        if (! $this->ok($container)) {
            throw $this->mapError($container);
        }

        return $this->publishContainer($post, $container->json('id'));
    }

    // --- Single video / reel: container, then poll, then publish ---------------

    protected function startVideo(PreparedPost $post, Media $video): PublishOutcome
    {
        $params = ['video_url' => $this->mediaUrl($video)];

        if ($this->options($post)?->isStory) {
            $params['media_type'] = 'STORIES';
        } else {
            $params['media_type'] = 'REELS';
            $params['caption'] = (string) $post->caption();

            if (($cover = $this->options($post)?->thumbnail) !== null) {
                $params['cover_url'] = $this->mediaUrl($cover);
            }
        }

        $container = $this->http($post)->post($this->edge($post, '/media'), $this->mergeExtra($post, $params));

        if (! $this->ok($container)) {
            throw $this->mapError($container);
        }

        return $this->pending([
            'phase' => 'await_container',
            'container_id' => $container->json('id'),
        ], recheckAfter: 20);
    }

    protected function awaitContainer(PreparedPost $post, array $state): PublishOutcome
    {
        $status = $this->containerStatus($post, $state['container_id']);

        return match ($status) {
            'FINISHED' => $this->publishContainer($post, $state['container_id']),
            'ERROR' => throw new PermanentException('Instagram failed to process the media.', Platform::Instagram, FailureReason::MediaRejected),
            default => $this->pending($state, recheckAfter: 30),
        };
    }

    // --- Carousel: child containers, poll videos, parent container, publish ----

    protected function startCarousel(PreparedPost $post): PublishOutcome
    {
        $children = [];
        $pendingVideos = [];

        foreach ($post->media() as $item) {
            $params = ['is_carousel_item' => true];

            if ($item->type === MediaType::Image) {
                $params['image_url'] = $this->mediaUrl($item);
            } else {
                $params['video_url'] = $this->mediaUrl($item);
                $params['media_type'] = 'REELS';
            }

            $child = $this->http($post)->post($this->edge($post, '/media'), $params);

            if (! $this->ok($child)) {
                throw $this->mapError($child);
            }

            $id = $child->json('id');
            $children[] = $id;

            if ($item->type === MediaType::Video) {
                $pendingVideos[] = $id;
            }
        }

        return $this->pending([
            'phase' => 'await_children',
            'children' => $children,
            'pending_videos' => $pendingVideos,
            'caption' => (string) $post->caption(),
        ], recheckAfter: $pendingVideos === [] ? 5 : 60);
    }

    protected function awaitChildren(PreparedPost $post, array $state): PublishOutcome
    {
        foreach ($state['pending_videos'] as $videoId) {
            $status = $this->containerStatus($post, $videoId);

            if ($status === 'ERROR') {
                throw new PermanentException('Instagram failed to process a carousel video.', Platform::Instagram, FailureReason::MediaRejected);
            }

            if ($status !== 'FINISHED') {
                return $this->pending($state, recheckAfter: 30);
            }
        }

        $parent = $this->http($post)->post($this->edge($post, '/media'), $this->mergeExtra($post, [
            'media_type' => 'CAROUSEL',
            'caption' => $state['caption'] ?? '',
            'children' => implode(',', $state['children']),
        ]));

        if (! $this->ok($parent)) {
            throw $this->mapError($parent);
        }

        return $this->pending([
            'phase' => 'await_carousel',
            'container_id' => $parent->json('id'),
        ], recheckAfter: 30);
    }

    protected function awaitCarousel(PreparedPost $post, array $state): PublishOutcome
    {
        $status = $this->containerStatus($post, $state['container_id']);

        return match ($status) {
            'FINISHED' => $this->publishContainer($post, $state['container_id']),
            'ERROR' => throw new PermanentException('Instagram failed to assemble the carousel.', Platform::Instagram, FailureReason::MediaRejected),
            default => $this->pending($state, recheckAfter: 30),
        };
    }

    // --- Publish + status helpers ----------------------------------------------

    protected function publishContainer(PreparedPost $post, string $containerId): PublishOutcome
    {
        $response = $this->http($post)->post($this->edge($post, '/media_publish'), [
            'creation_id' => $containerId,
        ]);

        if ($this->ok($response)) {
            return $this->published(PostResult::success(Platform::Instagram, $response->json('id')));
        }

        // The container is processed but the publish itself is not ready yet:
        // back off for a long while and retry the publish (the old delayed job).
        if ($this->subcode($response) === 2207027) {
            return $this->pending(['phase' => 'publish_retry', 'container_id' => $containerId], recheckAfter: 1800);
        }

        throw $this->mapError($response);
    }

    protected function containerStatus(PreparedPost $post, string $containerId): string
    {
        $response = $this->http($post)->get($this->graph.'/'.$containerId, ['fields' => 'status_code']);

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        return (string) $response->json('status_code', 'IN_PROGRESS');
    }

    // --- Helpers ---------------------------------------------------------------

    protected function options(PreparedPost $post): ?InstagramOptions
    {
        $options = $post->options();

        return $options instanceof InstagramOptions ? $options : null;
    }

    protected function token(PreparedPost $post): string
    {
        return (string) $post->credentials->get('access_token');
    }

    protected function edge(PreparedPost $post, string $path): string
    {
        return $this->graph.'/'.$post->credentials->get('account_id').$path;
    }

    protected function http(PreparedPost $post)
    {
        return Http::withToken($this->token($post));
    }

    protected function ok($response): bool
    {
        return $response->successful();
    }

    protected function subcode($response): ?int
    {
        $subcode = $response->json('error.error_subcode');

        return $subcode !== null ? (int) $subcode : null;
    }

    /**
     * Map a Graph error into the three-exception model, from the original
     * subcode handling. 2207027 is handled by the caller as a Pending, not here.
     */
    protected function mapError($response): TemporaryException|PermanentException
    {
        $message = (string) $response->json('error.message', 'Instagram request failed.');
        $subcode = $this->subcode($response);
        $context = ['status' => $response->status(), 'error' => $response->json('error') ?? []];

        if (str_contains($message, 'not an Instagram Business')) {
            return new PermanentException('The account is not an Instagram Business or Creator account.', Platform::Instagram, FailureReason::AccountRestricted, $context);
        }

        if (str_contains($message, 'unexpected error has occurred')) {
            return new TemporaryException("Meta's API is having issues; retrying shortly.", Platform::Instagram, FailureReason::ServerError, 60, $context);
        }

        return match ($subcode) {
            460 => new PermanentException('The Instagram password was changed; the account must be reconnected.', Platform::Instagram, FailureReason::InvalidToken, $context),
            2207050 => new PermanentException('Meta has temporarily restricted this Instagram account.', Platform::Instagram, FailureReason::AccountRestricted, $context),
            2207003, 2207032 => new TemporaryException($message, Platform::Instagram, FailureReason::ServerError, 60, $context),
            2207069 => new TemporaryException($message, Platform::Instagram, FailureReason::RateLimited, 90, $context),
            2207051 => new TemporaryException("There seems to be an outage with Meta's API.", Platform::Instagram, FailureReason::RateLimited, 120, $context),
            default => new PermanentException($message, Platform::Instagram, FailureReason::Unknown, $context),
        };
    }

    // --- Rule sets -------------------------------------------------------------

    protected function imageRules(): ImageRules
    {
        return new ImageRules(extensions: ['jpg', 'jpeg', 'png'], maxSizeBytes: 8388608);
    }

    protected function reelRules(): VideoRules
    {
        return new VideoRules(extensions: ['mp4', 'mov'], maxSizeBytes: 1073741824, maxHeight: 1920, minDuration: 3, maxDuration: 900);
    }

    protected function storyPhotoRules(): ImageRules
    {
        return new ImageRules(extensions: ['jpg', 'jpeg', 'png'], maxSizeBytes: 8388608);
    }

    protected function storyVideoRules(): VideoRules
    {
        return new VideoRules(extensions: ['mp4', 'mov'], maxSizeBytes: 104857600, maxHeight: 1920, minDuration: 3, maxDuration: 60);
    }
}
