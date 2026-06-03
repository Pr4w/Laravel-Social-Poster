<?php

namespace SocialPoster\Platforms\X;

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
 * X (Twitter) on the v2 API. Media is uploaded as bytes (Ingestion::Upload):
 * static images use the one-shot POST /2/media/upload, while GIFs and video use
 * the chunked INIT / APPEND / FINALIZE flow and then process asynchronously,
 * which rides the publish()/resume() loop via the STATUS command. A post takes
 * up to 4 images, or one GIF, or one video.
 *
 * Token refresh is out of scope: pass a current bearer token as the credential.
 */
class XDriver extends AbstractPlatform
{
    protected string $api = 'https://api.x.com/2';

    protected int $chunkSize = 4194304; // 4 MB

    public function platform(): Platform
    {
        return Platform::X;
    }

    protected function ingestion(): Ingestion
    {
        return Ingestion::Upload;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            caption: new CaptionRules(max: 280),
            media: new MediaRules(minCount: 0, maxCount: 4, image: $this->photoRules(), video: $this->videoRules()),
        );
    }

    protected function rulesFor(PreparedPost $post): Capabilities
    {
        $caption = new CaptionRules(max: 280);
        $media = $post->media();

        // 2 to 4 attachments must all be static photos (no GIF, no video).
        if (count($media) > 1) {
            return new Capabilities(
                caption: $caption,
                media: new MediaRules(minCount: 2, maxCount: 4, image: $this->photoRules()),
            );
        }

        // Single attachment: a photo, a GIF (its own size limit), or a video.
        $first = $media[0] ?? null;

        return new Capabilities(
            caption: $caption,
            media: new MediaRules(
                minCount: 0,
                maxCount: 1,
                image: $this->isGif($first) ? $this->gifRules() : $this->photoRules(),
                video: $this->videoRules(),
            ),
        );
    }

    protected function validatePlatformRules(PreparedPost $post, MessageBag $errors): void
    {
        // X allows media-only posts, but the original product required text;
        // relax this if you want to permit empty captions with media.
        if (trim((string) $post->caption()) === '') {
            $errors->add('caption', "X posts can't have an empty caption.");
        }
    }

    public function publish(PreparedPost $post): PublishOutcome
    {
        $media = $post->media();

        if ($media === []) {
            return $this->createTweet($post, []);
        }

        $first = $media[0];

        if (count($media) === 1 && ($first->type === MediaType::Video || $this->isGif($first))) {
            return $this->startChunked($post, $first);
        }

        // One or more static images: upload each in one shot, then post.
        $ids = [];

        try {
            foreach ($media as $image) {
                $ids[] = $this->uploadImage($post, $image);
            }
        } finally {
            $this->gateway()?->cleanup();
        }

        return $this->createTweet($post, $ids);
    }

    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        if (($state['phase'] ?? null) !== 'await_media') {
            throw new PermanentException('Unknown X publish phase.', Platform::X);
        }

        [$result, $checkAfter] = $this->mediaStatus($post, $state['media_id']);

        return match ($result) {
            'succeeded' => $this->createTweet($post, [$state['media_id']]),
            'failed' => throw new PermanentException('X failed to process the media.', Platform::X, FailureReason::MediaRejected),
            default => $this->pending($state, recheckAfter: $checkAfter),
        };
    }

    // --- Image (one-shot) ------------------------------------------------------

    protected function uploadImage(PreparedPost $post, Media $image): string
    {
        $response = $this->authed($post)
            ->attach('media', $this->bytes($image), 'image.'.($image->extension() ?: 'jpg'))
            ->post($this->api.'/media/upload', [
                'media_category' => 'tweet_image',
                'media_type' => $this->mime($image),
            ]);

        if (! $response->successful()) {
            throw $this->mapError($response);
        }

        return (string) $response->json('data.id');
    }

    // --- GIF / video (chunked, then async processing) --------------------------

    protected function startChunked(PreparedPost $post, Media $item): PublishOutcome
    {
        try {
            $path = $this->mediaPath($item);
            $size = @filesize($path) ?: 0;

            $init = $this->authed($post)->post($this->api.'/media/upload/initialize', [
                'media_category' => $this->category($item),
                'media_type' => $this->mime($item),
                'total_bytes' => $size,
            ]);

            if (! $init->successful()) {
                throw $this->mapError($init);
            }

            $mediaId = (string) $init->json('data.id');
            $this->appendChunks($post, $mediaId, $path);

            $finalize = $this->authed($post)->post($this->api.'/media/upload/'.$mediaId.'/finalize');

            if (! $finalize->successful()) {
                throw $this->mapError($finalize);
            }

            $state = $finalize->json('data.processing_info.state');
            $checkAfter = (int) ($finalize->json('data.processing_info.check_after_secs') ?? 5);

            // No processing_info, or already done: post straight away.
            if ($state === null || $state === 'succeeded') {
                return $this->createTweet($post, [$mediaId]);
            }

            if ($state === 'failed') {
                throw new PermanentException('X failed to process the media.', Platform::X, FailureReason::MediaRejected);
            }

            return $this->pending(['phase' => 'await_media', 'media_id' => $mediaId], recheckAfter: $checkAfter);
        } finally {
            $this->gateway()?->cleanup();
        }
    }

    protected function appendChunks(PreparedPost $post, string $mediaId, string $path): void
    {
        $handle = fopen($path, 'rb');
        $segment = 0;

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, $this->chunkSize);

                if ($chunk === '' || $chunk === false) {
                    break;
                }

                $response = $this->authed($post)
                    ->attach('media', $chunk, 'chunk')
                    ->post($this->api.'/media/upload/'.$mediaId.'/append', [
                        'segment_index' => $segment,
                    ]);

                if (! $response->successful()) {
                    throw $this->mapError($response);
                }

                $segment++;
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array{0: string, 1: int} [state, check_after_secs] */
    protected function mediaStatus(PreparedPost $post, string $mediaId): array
    {
        $response = $this->authed($post)->get($this->api.'/media/upload', [
            'command' => 'STATUS',
            'media_id' => $mediaId,
        ]);

        if (! $response->successful()) {
            throw $this->mapError($response);
        }

        return [
            (string) $response->json('data.processing_info.state', 'in_progress'),
            (int) ($response->json('data.processing_info.check_after_secs') ?? 5),
        ];
    }

    // --- Tweet -----------------------------------------------------------------

    /** @param string[] $mediaIds */
    protected function createTweet(PreparedPost $post, array $mediaIds): PublishOutcome
    {
        $payload = ['text' => (string) $post->caption()];

        if ($mediaIds !== []) {
            $payload['media'] = ['media_ids' => $mediaIds];
        }

        $response = $this->authed($post)->post($this->api.'/tweets', $this->mergeExtra($post, $payload));

        if (! $response->successful()) {
            throw $this->mapError($response);
        }

        $id = (string) $response->json('data.id');

        return $this->published(PostResult::success(Platform::X, $id, 'https://x.com/i/web/status/'.$id));
    }

    // --- Helpers ---------------------------------------------------------------

    protected function authed(PreparedPost $post)
    {
        return Http::withToken($this->token($post));
    }

    protected function token(PreparedPost $post): string
    {
        return (string) $post->credentials->get('access_token');
    }

    protected function bytes(Media $media): string
    {
        return (string) file_get_contents($this->mediaPath($media));
    }

    protected function isGif(?Media $media): bool
    {
        return $media !== null && $media->type === MediaType::Image && $media->extension() === 'gif';
    }

    protected function category(Media $media): string
    {
        return $media->type === MediaType::Video ? 'tweet_video' : 'tweet_gif';
    }

    protected function mime(Media $media): string
    {
        return match ($media->extension()) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            default => 'application/octet-stream',
        };
    }

    protected function classifyError($response): TemporaryException|PermanentException
    {
        $json = $response->json() ?? [];
        $status = $response->status();
        $message = $json['detail']
            ?? $json['title']
            ?? ($json['errors'][0]['detail'] ?? $json['errors'][0]['message'] ?? 'X request failed.');
        $context = ['status' => $status, 'error' => $json];

        if ($status === 429) {
            return new TemporaryException('X rate limit reached.', Platform::X, FailureReason::RateLimited, 900, $context);
        }

        if ($response->serverError()) {
            return new TemporaryException($message, Platform::X, FailureReason::ServerError, null, $context);
        }

        if ($status === 401) {
            return new PermanentException($message, Platform::X, FailureReason::InvalidToken, $context);
        }

        if ($status === 403) {
            return new PermanentException($message, Platform::X, FailureReason::InsufficientPermissions, $context);
        }

        return new PermanentException($message, Platform::X, FailureReason::Unknown, $context);
    }

    // --- Rule sets -------------------------------------------------------------

    protected function photoRules(): ImageRules
    {
        return new ImageRules(extensions: ['jpg', 'jpeg', 'png', 'webp'], maxSizeBytes: 5242880);
    }

    protected function gifRules(): ImageRules
    {
        return new ImageRules(extensions: ['gif'], maxSizeBytes: 15728640);
    }

    protected function videoRules(): VideoRules
    {
        return new VideoRules(
            extensions: ['mp4'],
            maxSizeBytes: 536870912,
            minDuration: 1,
            maxDuration: 140,
            maxWidth: 1280,
            maxHeight: 1280,
        );
    }
}
