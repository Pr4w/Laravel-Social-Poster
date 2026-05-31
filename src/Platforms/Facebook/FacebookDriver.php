<?php

namespace SocialPoster\Platforms\Facebook;

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
 * Reference driver for a pull-from-URL platform with conditional rules.
 *
 * rulesFor() resolves the variant (feed image, feed video, Reel, carousel, photo
 * story, video story); the generic engine then does the per-item checks. Reels
 * and video stories upload, then hand off to resume() for the finish step rather
 * than blocking the worker.
 */
class FacebookDriver extends AbstractPlatform
{
    protected string $graph = 'https://graph.facebook.com/v23.0';

    public function platform(): Platform
    {
        return Platform::Facebook;
    }

    protected function ingestion(): Ingestion
    {
        return Ingestion::PullUrl;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            caption: new CaptionRules(max: 63206),
            media: new MediaRules(minCount: 0, maxCount: 10, image: $this->feedImageRules(), video: $this->videoRules()),
            supportsTitle: false,
            supportsCarousel: true,
        );
    }

    protected function rulesFor(PreparedPost $post): Capabilities
    {
        $caption = new CaptionRules(max: 63206);
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

        if (count($media) > 1) {
            return new Capabilities(
                caption: $caption,
                media: new MediaRules(minCount: 1, maxCount: 10, image: $this->feedImageRules(), video: null),
                supportsCarousel: true,
            );
        }

        if ($isVideo) {
            return new Capabilities(
                caption: $caption,
                media: new MediaRules(
                    minCount: 0,
                    maxCount: 1,
                    video: $this->isReel($first) ? $this->reelRules() : $this->videoRules(),
                ),
            );
        }

        return new Capabilities(
            caption: $caption,
            media: new MediaRules(minCount: 0, maxCount: 1, image: $this->feedImageRules()),
        );
    }

    protected function validatePlatformRules(PreparedPost $post, MessageBag $errors): void
    {
        $options = $this->options($post);
        $media = $post->media();
        $isStory = $options?->isStory ?? false;

        if (! $isStory && $media === [] && trim((string) $post->caption()) === '') {
            $errors->add('caption', "Facebook posts can't have an empty caption.");
        }

        if ($isStory && $media === []) {
            $errors->add('media', "You don't have any media attached for this Facebook story.");
        }

        if ($options?->thumbnail !== null) {
            $allowed = ['bmp', 'gif', 'jpeg', 'jpg', 'png', 'tiff'];

            if (! in_array($options->thumbnail->extension(), $allowed, true)) {
                $errors->add('thumbnail', 'Facebook video thumbnails must be one of: '.implode(', ', $allowed).'.');
            }
        }
    }

    protected function prepare(PreparedPost $post): array
    {
        $params = ['message' => trim((string) $post->caption())];

        if ($post->media() === [] && preg_match('/https?:\/\/\S+/', (string) $post->caption(), $m)) {
            $params['link'] = $m[0];
        }

        return $params;
    }

    public function publish(PreparedPost $post): PublishOutcome
    {
        $media = $post->media();
        $isStory = $this->options($post)?->isStory ?? false;

        if ($media === []) {
            return $this->publishText($post);
        }

        $first = $media[0];

        if ($isStory) {
            return $first->type === MediaType::Video
                ? $this->startVideoStory($post, $first)
                : $this->publishPhotoStory($post, $first);
        }

        if (count($media) > 1) {
            return $this->publishCarousel($post);
        }

        if ($first->type === MediaType::Video) {
            return $this->isReel($first)
                ? $this->startReel($post, $first)
                : $this->publishVideo($post, $first);
        }

        return $this->publishCarousel($post);
    }

    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        return match ($state['phase'] ?? null) {
            'reel_finish' => $this->finishReel($post, $state),
            'story_finish' => $this->finishVideoStory($post, $state),
            default => throw new PermanentException('Unknown Facebook publish phase.', Platform::Facebook),
        };
    }

    // --- Synchronous flows -----------------------------------------------------

    protected function publishText(PreparedPost $post): PublishOutcome
    {
        $response = $this->http($post)->post($this->edge($post, '/feed'), $this->mergeExtra($post, $this->prepare($post)));

        return $this->ok($response)
            ? $this->published(PostResult::success(Platform::Facebook, $response->json('id')))
            : throw $this->mapError($response);
    }

    protected function publishCarousel(PreparedPost $post): PublishOutcome
    {
        $attached = [];

        foreach ($post->media() as $item) {
            $response = $this->http($post)->post($this->edge($post, '/photos'), [
                'url' => $this->mediaUrl($item),
                'published' => false,
            ]);

            if (! $this->ok($response)) {
                throw $this->mapError($response);
            }

            $attached[] = ['media_fbid' => $response->json('id')];
        }

        $params = $this->prepare($post) + ['attached_media' => $attached];
        $response = $this->http($post)->post($this->edge($post, '/feed'), $this->mergeExtra($post, $params));

        return $this->ok($response)
            ? $this->published(PostResult::success(Platform::Facebook, $response->json('id')))
            : throw $this->mapError($response);
    }

    protected function publishVideo(PreparedPost $post, Media $video): PublishOutcome
    {
        $params = ['file_url' => $this->mediaUrl($video)];

        if (trim((string) $post->caption()) !== '') {
            $params['description'] = trim((string) $post->caption());
        }

        $params = $this->mergeExtra($post, $params);

        $thumb = $this->options($post)?->thumbnail;
        $request = $this->http($post)->timeout(120);

        try {
            // Facebook wants the thumbnail as uploaded image bytes, not a URL.
            // Attaching it makes the request multipart, with file_url and
            // description riding along as form fields.
            if ($thumb !== null) {
                $extension = $thumb->extension() !== '' ? $thumb->extension() : 'jpg';
                $request = $request->attach('thumb', $this->bytes($thumb), "thumbnail.{$extension}");
            } else {
                $request = $request->asForm();
            }

            $response = $request->post($this->edge($post, '/videos'), $params);
        } finally {
            $this->gateway()?->cleanup();
        }

        return $this->ok($response)
            ? $this->published(PostResult::success(Platform::Facebook, $response->json('id')))
            : throw $this->mapError($response);
    }

    protected function publishPhotoStory(PreparedPost $post, Media $photo): PublishOutcome
    {
        $upload = $this->http($post)->post($this->edge($post, '/photos'), [
            'url' => $this->mediaUrl($photo),
            'published' => false,
        ]);

        if (! $this->ok($upload)) {
            throw $this->mapError($upload);
        }

        $publish = $this->http($post)->post($this->edge($post, '/photo_stories'), [
            'photo_id' => $upload->json('id'),
        ]);

        if (! $this->ok($publish) || $publish->json('success') === false) {
            throw $this->mapError($publish);
        }

        return $this->published(PostResult::success(Platform::Facebook, $publish->json('post_id')));
    }

    // --- Async flows: upload now, finish on resume -----------------------------

    protected function startReel(PreparedPost $post, Media $video): PublishOutcome
    {
        $start = $this->http($post)->post($this->edge($post, '/video_reels'), ['upload_phase' => 'start']);

        if (! $this->ok($start)) {
            throw $this->mapError($start);
        }

        $this->uploadByUrl($post, $start->json('upload_url'), $video);

        return $this->pending([
            'phase' => 'reel_finish',
            'video_id' => $start->json('video_id'),
            'caption' => trim((string) $post->caption()),
        ], recheckAfter: 60);
    }

    protected function finishReel(PreparedPost $post, array $state): PublishOutcome
    {
        $finish = $this->http($post)->post($this->edge($post, '/video_reels'), [
            'upload_phase' => 'finish',
            'video_id' => $state['video_id'],
            'video_state' => 'PUBLISHED',
            'description' => $state['caption'] ?? '',
        ]);

        if (! $this->ok($finish) || $finish->json('success') !== true) {
            throw $this->mapError($finish);
        }

        return $this->published(PostResult::success(Platform::Facebook, $finish->json('post_id')));
    }

    protected function startVideoStory(PreparedPost $post, Media $video): PublishOutcome
    {
        $start = $this->http($post)->post($this->edge($post, '/video_stories'), ['upload_phase' => 'start']);

        if (! $this->ok($start)) {
            throw $this->mapError($start);
        }

        $this->uploadByUrl($post, $start->json('upload_url'), $video);

        return $this->pending([
            'phase' => 'story_finish',
            'video_id' => $start->json('video_id'),
        ], recheckAfter: 60);
    }

    protected function finishVideoStory(PreparedPost $post, array $state): PublishOutcome
    {
        $finish = $this->http($post)->post($this->edge($post, '/video_stories'), [
            'upload_phase' => 'finish',
            'video_id' => $state['video_id'],
        ]);

        if (! $this->ok($finish) || $finish->json('success') !== true) {
            throw $this->mapError($finish);
        }

        return $this->published(PostResult::success(Platform::Facebook, $finish->json('post_id')));
    }

    protected function uploadByUrl(PreparedPost $post, string $uploadUrl, Media $video): void
    {
        $upload = Http::timeout(60)->withHeaders([
            'Authorization' => 'OAuth '.$this->token($post),
            'file_url' => $this->mediaUrl($video),
        ])->post($uploadUrl);

        if (! $this->ok($upload)) {
            throw $this->mapError($upload);
        }
    }

    // --- Helpers ---------------------------------------------------------------

    protected function options(PreparedPost $post): ?FacebookOptions
    {
        $options = $post->options();

        return $options instanceof FacebookOptions ? $options : null;
    }

    protected function isReel(Media $video): bool
    {
        $ratio = $this->inspectMedia($video)?->aspectRatio();

        return $ratio !== null && abs($ratio - 9 / 16) < 0.01;
    }

    protected function token(PreparedPost $post): string
    {
        return (string) $post->credentials->get('page_access_token');
    }

    protected function bytes(Media $media): string
    {
        return (string) file_get_contents($this->mediaPath($media));
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

    protected function mapError($response): TemporaryException|PermanentException
    {
        $error = $response->json('error') ?? [];
        $code = (int) ($error['code'] ?? 0);
        $message = $error['message'] ?? 'Facebook request failed.';
        $context = ['status' => $response->status(), 'error' => $error];

        if (($error['is_transient'] ?? false) || $response->serverError() || in_array($code, [1, 2, 4, 17, 32, 341, 613], true)) {
            $retryAfter = in_array($code, [4, 17, 613], true) ? 900 : null;

            return new TemporaryException($message, Platform::Facebook, FailureReason::RateLimited, $retryAfter, $context);
        }

        if (in_array($code, [190, 102, 463, 467], true)) {
            return new PermanentException($message, Platform::Facebook, FailureReason::InvalidToken, $context);
        }

        if (in_array($code, [10, 200, 203, 3], true)) {
            return new PermanentException($message, Platform::Facebook, FailureReason::InsufficientPermissions, $context);
        }

        return new PermanentException($message, Platform::Facebook, FailureReason::Unknown, $context);
    }

    // --- Rule sets -------------------------------------------------------------

    protected function videoRules(): VideoRules
    {
        return new VideoRules(extensions: ['mp4', 'mov'], maxSizeBytes: 1073741824, minDuration: 3, maxDuration: 1200);
    }

    protected function reelRules(): VideoRules
    {
        return new VideoRules(
            extensions: ['mp4', 'mov'],
            minDuration: 3,
            maxDuration: 90,
            minWidth: 540,
            minHeight: 960,
            maxWidth: 1080,
            maxHeight: 1920,
        );
    }

    protected function feedImageRules(): ImageRules
    {
        return new ImageRules(extensions: ['jpeg', 'jpg', 'png', 'gif', 'tiff'], maxSizeBytes: 4194304);
    }

    protected function storyPhotoRules(): ImageRules
    {
        return new ImageRules(extensions: ['jpeg', 'jpg', 'png', 'gif', 'tiff'], maxSizeBytes: 4194304);
    }

    protected function storyVideoRules(): VideoRules
    {
        return new VideoRules(
            extensions: ['mp4', 'mov'],
            minDuration: 3,
            maxDuration: 60,
            minWidth: 540,
            minHeight: 960,
            maxWidth: 1080,
            maxHeight: 1920,
        );
    }
}
