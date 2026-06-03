<?php

namespace SocialPoster\Platforms\Threads;

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
 * Threads uses the same Meta container model as Instagram: create a container,
 * wait for it to process, then publish. Text and image containers just need a
 * short settle before publishing; video containers are polled for status; a
 * carousel builds child containers, waits on the video children, assembles the
 * parent, then publishes. All of that rides the publish()/resume() loop.
 */
class ThreadsDriver extends AbstractPlatform
{
    protected string $graph = 'https://graph.threads.net/v1.0';

    public function platform(): Platform
    {
        return Platform::Threads;
    }

    protected function ingestion(): Ingestion
    {
        return Ingestion::PullUrl;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            caption: new CaptionRules(max: 500),
            media: new MediaRules(minCount: 0, maxCount: 20, image: $this->imageRules(), video: $this->videoRules()),
            supportsCarousel: true,
        );
    }

    protected function rulesFor(PreparedPost $post): Capabilities
    {
        $caption = new CaptionRules(max: 500);

        if (count($post->media()) > 1) {
            return new Capabilities(
                caption: $caption,
                media: new MediaRules(minCount: 2, maxCount: 20, image: $this->imageRules(), video: $this->videoRules()),
                supportsCarousel: true,
            );
        }

        return new Capabilities(
            caption: $caption,
            media: new MediaRules(minCount: 0, maxCount: 1, image: $this->imageRules(), video: $this->videoRules()),
        );
    }

    protected function validatePlatformRules(PreparedPost $post, MessageBag $errors): void
    {
        if (trim((string) $post->caption()) === '' && $post->media() === []) {
            $errors->add('caption', 'Threads posts without media should at least contain text.');
        }
    }

    public function publish(PreparedPost $post): PublishOutcome
    {
        $media = $post->media();

        if (count($media) > 1) {
            return $this->startCarousel($post);
        }

        return $this->startSingle($post, $media[0] ?? null);
    }

    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        return match ($state['phase'] ?? null) {
            'await' => $this->awaitContainer($post, $state),
            'await_children' => $this->awaitChildren($post, $state),
            'await_publish' => $this->publishContainer($post, $state['container']),
            default => throw new PermanentException('Unknown Threads publish phase.', Platform::Threads),
        };
    }

    // --- Single (text, image, video) -------------------------------------------

    protected function startSingle(PreparedPost $post, ?Media $item): PublishOutcome
    {
        $caption = trim((string) $post->caption());
        $params = [];

        if ($item === null) {
            $params['media_type'] = 'TEXT';
            $params['text'] = $caption;
        } elseif ($item->type === MediaType::Image) {
            $params['media_type'] = 'IMAGE';
            $params['image_url'] = $this->mediaUrl($item);
        } else {
            $params['media_type'] = 'VIDEO';
            $params['video_url'] = $this->mediaUrl($item);
        }

        if ($item !== null && $caption !== '') {
            $params['text'] = $caption;
        }

        $response = $this->http($post)->post($this->edge($post, '/threads'), $this->mergeExtra($post, $params));

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        $container = $response->json('id');

        // Video containers process asynchronously; text and image only need a
        // short settle, so they go straight to the publish phase after a delay.
        return $item?->type === MediaType::Video
            ? $this->pending(['phase' => 'await', 'container' => $container], recheckAfter: 30)
            : $this->pending(['phase' => 'await_publish', 'container' => $container], recheckAfter: 30);
    }

    protected function awaitContainer(PreparedPost $post, array $state): PublishOutcome
    {
        return match ($this->containerStatus($post, $state['container'])) {
            'FINISHED' => $this->publishContainer($post, $state['container']),
            'ERROR' => throw new PermanentException('Threads failed to process the media.', Platform::Threads, FailureReason::MediaRejected),
            default => $this->pending($state, recheckAfter: 30),
        };
    }

    // --- Carousel --------------------------------------------------------------

    protected function startCarousel(PreparedPost $post): PublishOutcome
    {
        $children = [];
        $pendingVideos = [];

        foreach ($post->media() as $item) {
            $params = ['is_carousel_item' => true];

            if ($item->type === MediaType::Image) {
                $params['media_type'] = 'IMAGE';
                $params['image_url'] = $this->mediaUrl($item);
            } else {
                $params['media_type'] = 'VIDEO';
                $params['video_url'] = $this->mediaUrl($item);
            }

            $response = $this->http($post)->post($this->edge($post, '/threads'), $params);

            if (! $this->ok($response)) {
                throw $this->mapError($response);
            }

            $id = $response->json('id');
            $children[] = $id;

            if ($item->type === MediaType::Video) {
                $pendingVideos[] = $id;
            }
        }

        return $this->pending([
            'phase' => 'await_children',
            'children' => $children,
            'pending_videos' => $pendingVideos,
            'text' => trim((string) $post->caption()),
        ], recheckAfter: $pendingVideos === [] ? 10 : 60);
    }

    protected function awaitChildren(PreparedPost $post, array $state): PublishOutcome
    {
        foreach ($state['pending_videos'] as $videoId) {
            $status = $this->containerStatus($post, $videoId);

            if ($status === 'ERROR') {
                throw new PermanentException('Threads failed to process a carousel video.', Platform::Threads, FailureReason::MediaRejected);
            }

            if ($status !== 'FINISHED') {
                return $this->pending($state, recheckAfter: 30);
            }
        }

        $params = [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $state['children']),
        ];

        if (($state['text'] ?? '') !== '') {
            $params['text'] = $state['text'];
        }

        $response = $this->http($post)->post($this->edge($post, '/threads'), $this->mergeExtra($post, $params));

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        return $this->pending(['phase' => 'await_publish', 'container' => $response->json('id')], recheckAfter: 30);
    }

    // --- Publish + status ------------------------------------------------------

    protected function publishContainer(PreparedPost $post, string $containerId): PublishOutcome
    {
        $response = $this->http($post)->post($this->edge($post, '/threads_publish'), [
            'creation_id' => $containerId,
        ]);

        if (! $this->ok($response)) {
            // A "media not ready" / "API slow" error here is temporary; releasing
            // re-runs this same await_publish payload and retries the publish.
            throw $this->mapError($response);
        }

        return $this->published(PostResult::success(Platform::Threads, $response->json('id')));
    }

    protected function containerStatus(PreparedPost $post, string $containerId): string
    {
        $response = $this->http($post)->get($this->graph.'/'.$containerId, ['fields' => 'status']);

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        return (string) $response->json('status', 'IN_PROGRESS');
    }

    // --- Helpers ---------------------------------------------------------------

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

    protected function classifyError($response): TemporaryException|PermanentException
    {
        $error = $response->json('error') ?? [];
        $subcode = isset($error['error_subcode']) ? (int) $error['error_subcode'] : null;
        $message = (string) ($error['message'] ?? 'Threads request failed.');
        $context = ['status' => $response->status(), 'error' => $error];

        // Media still downloading / carousel child not ready / generic slowness.
        if (in_array($subcode, [2207003, 4279004], true)) {
            return new TemporaryException('Threads is still processing the media; retrying shortly.', Platform::Threads, FailureReason::ServerError, 60, $context);
        }

        if (str_contains($message, 'unexpected error has occurred')) {
            return new TemporaryException('Threads API is busy; retrying shortly.', Platform::Threads, FailureReason::ServerError, 90, $context);
        }

        if ($response->serverError()) {
            return new TemporaryException($message, Platform::Threads, FailureReason::ServerError, null, $context);
        }

        return new PermanentException($message, Platform::Threads, FailureReason::Unknown, $context);
    }

    // --- Rule sets -------------------------------------------------------------

    protected function imageRules(): ImageRules
    {
        return new ImageRules(extensions: ['jpg', 'jpeg', 'png'], maxSizeBytes: 8388608);
    }

    protected function videoRules(): VideoRules
    {
        return new VideoRules(extensions: ['mp4', 'mov'], maxSizeBytes: 1073741824, minDuration: 1, maxDuration: 300, maxHeight: 1920);
    }
}
