<?php

namespace SocialPoster\Platforms\YouTube;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\MessageBag;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\MediaRules;
use SocialPoster\Capabilities\VideoRules;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Ingestion;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\Platforms\AbstractPlatform;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\PostResult;
use SocialPoster\ValueObjects\PreparedPost;
use SocialPoster\ValueObjects\PublishOutcome;

/**
 * YouTube (Data API v3). Uploads one video via the resumable protocol: a
 * session is opened with the video metadata, then the bytes are streamed to the
 * returned session URL, and YouTube responds with the video resource. The id
 * exists as soon as that PUT returns (transcoding happens on YouTube's side),
 * so this is a synchronous publish with no resume step.
 *
 * The builder's ->title() becomes the video title (required, max 100) and
 * ->caption() becomes the description (max 5000). An optional thumbnail in the
 * options is set after the upload and never fails the post.
 *
 * Community posts are intentionally not supported: the Data API exposes no
 * endpoint to create them.
 */
class YouTubeDriver extends AbstractPlatform
{
    protected string $resumable = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status';

    protected string $thumbnailEndpoint = 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set';

    protected int $titleLimit = 100;

    public function platform(): Platform
    {
        return Platform::YouTube;
    }

    protected function ingestion(): Ingestion
    {
        return Ingestion::Upload;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            caption: new CaptionRules(max: 5000), // description
            media: new MediaRules(minCount: 1, maxCount: 1, video: $this->videoRules()),
            supportsTitle: true,
        );
    }

    protected function validatePlatformRules(PreparedPost $post, MessageBag $errors): void
    {
        $title = trim((string) $post->title());

        if ($title === '') {
            $errors->add('title', "YouTube uploads need a title. Set it with ->title().");
        } elseif (mb_strlen($title) > $this->titleLimit) {
            $errors->add('title', "YouTube titles can't be longer than {$this->titleLimit} characters.");
        }

        $thumbnail = $this->options($post)?->thumbnail;

        if ($thumbnail !== null) {
            if (! in_array($thumbnail->extension(), ['jpg', 'jpeg', 'png'], true)) {
                $errors->add('thumbnail', 'A YouTube thumbnail must be a JPG or PNG image.');
            }

            $size = $this->inspectMedia($thumbnail)?->sizeBytes;

            if ($size !== null && $size > 2097152) {
                $errors->add('thumbnail', "A YouTube thumbnail can't be larger than 2MB.");
            }
        }
    }

    public function publish(PreparedPost $post): PublishOutcome
    {
        $video = $post->media()[0];

        try {
            $path = $this->mediaPath($video);
            $size = (int) (@filesize($path) ?: 0);
            $mime = $this->videoMime($video);

            // 1) Open the resumable session with the metadata.
            $session = Http::withToken($this->token($post))
                ->withHeaders([
                    'X-Upload-Content-Length' => $size,
                    'X-Upload-Content-Type' => $mime,
                ])
                ->post($this->resumable, $this->buildBody($post));

            if (! $session->successful()) {
                throw $this->mapError($session);
            }

            $uploadUrl = $session->header('Location');

            if ($uploadUrl === '' || $uploadUrl === null) {
                throw new PermanentException('YouTube did not return a resumable upload URL.', Platform::YouTube);
            }

            // 2) Stream the bytes to the session URL.
            $upload = Http::withToken($this->token($post))
                ->withHeaders(['Content-Length' => $size])
                ->withBody(Utils::streamFor(fopen($path, 'rb')), $mime)
                ->put($uploadUrl);

            if (! $upload->successful()) {
                throw $this->mapError($upload);
            }

            $videoId = (string) $upload->json('id');

            // 3) Optional thumbnail. Never fails the post, since the video is live.
            $payload = [];
            $thumbnail = $this->options($post)?->thumbnail;

            if ($thumbnail !== null) {
                $warning = $this->setThumbnail($post, $videoId, $thumbnail);

                if ($warning !== null) {
                    $payload['thumbnail_warning'] = $warning;
                }
            }

            return $this->published(PostResult::success(
                Platform::YouTube,
                $videoId,
                'https://www.youtube.com/watch?v='.$videoId,
                payload: $payload,
            ));
        } finally {
            $this->gateway()?->cleanup();
        }
    }

    // --- Metadata --------------------------------------------------------------

    protected function buildBody(PreparedPost $post): array
    {
        $options = $this->options($post);

        $snippet = array_filter([
            'title' => trim((string) $post->title()),
            'description' => (string) $post->caption(),
            'tags' => $options?->tags ?: null,
            'categoryId' => $options?->categoryId,
        ], static fn ($value) => $value !== null && $value !== '');

        $status = [
            'privacyStatus' => ($options?->privacyStatus ?? YouTubePrivacy::Public)->value,
            'selfDeclaredMadeForKids' => $options?->madeForKids ?? false,
        ];

        // Fold the escape hatch in per sub-object so callers can add snippet or
        // status fields (publishAt, embeddable, ...) without clobbering the rest.
        // Driver-computed keys win on collision.
        $extra = $this->extraPayload($post);

        $body = [
            'snippet' => array_merge((array) ($extra['snippet'] ?? []), $snippet),
            'status' => array_merge((array) ($extra['status'] ?? []), $status),
        ];

        unset($extra['snippet'], $extra['status']);

        return array_merge($extra, $body);
    }

    // --- Thumbnail -------------------------------------------------------------

    protected function setThumbnail(PreparedPost $post, string $videoId, Media $thumbnail): ?string
    {
        try {
            $bytes = (string) file_get_contents($this->mediaPath($thumbnail));

            $response = Http::withToken($this->token($post))
                ->withBody($bytes, $this->thumbnailMime($thumbnail))
                ->post($this->thumbnailEndpoint.'?videoId='.$videoId);

            if (! $response->successful()) {
                // Common case: the channel is not eligible for custom thumbnails.
                return (string) ($response->json('error.message') ?: 'Thumbnail could not be set.');
            }

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    // --- Helpers ---------------------------------------------------------------

    protected function token(PreparedPost $post): string
    {
        return (string) $post->credentials->get('access_token');
    }

    protected function options(PreparedPost $post): ?YouTubeOptions
    {
        $options = $post->options();

        return $options instanceof YouTubeOptions ? $options : null;
    }

    protected function videoMime(Media $video): string
    {
        return match ($video->extension()) {
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'wmv' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
            'webm' => 'video/webm',
            'mpg', 'mpeg' => 'video/mpeg',
            '3gp' => 'video/3gpp',
            default => 'video/mp4',
        };
    }

    protected function thumbnailMime(Media $image): string
    {
        return $image->extension() === 'png' ? 'image/png' : 'image/jpeg';
    }

    protected function mapError($response): TemporaryException|PermanentException
    {
        $error = $response->json('error') ?? [];
        $status = $response->status();
        $reason = $error['errors'][0]['reason'] ?? '';
        $message = (string) ($error['message'] ?? 'YouTube request failed.');
        $context = ['status' => $status, 'error' => $error];

        if ($status === 401 || $reason === 'authError') {
            return new PermanentException($message, Platform::YouTube, FailureReason::InvalidToken, $context);
        }

        if ($status === 403) {
            // Quota exhaustion is transient; other 403s are permission problems.
            if (in_array($reason, ['quotaExceeded', 'rateLimitExceeded', 'userRateLimitExceeded'], true)) {
                return new TemporaryException($message, Platform::YouTube, FailureReason::RateLimited, 3600, $context);
            }

            return new PermanentException($message, Platform::YouTube, FailureReason::InsufficientPermissions, $context);
        }

        if ($status === 429) {
            return new TemporaryException($message, Platform::YouTube, FailureReason::RateLimited, 900, $context);
        }

        if ($response->serverError()) {
            return new TemporaryException($message, Platform::YouTube, FailureReason::ServerError, null, $context);
        }

        return new PermanentException($message, Platform::YouTube, FailureReason::Unknown, $context);
    }

    // --- Rule sets -------------------------------------------------------------

    protected function videoRules(): VideoRules
    {
        return new VideoRules(
            extensions: ['mov', 'mp4', 'm4v', 'avi', 'wmv', 'flv', 'webm', 'mpg', 'mpeg', '3gp'],
            maxSizeBytes: 274877906944, // 256 GB
        );
    }
}