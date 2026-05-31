<?php

namespace SocialPoster\Platforms\LinkedIn;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\MessageBag;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\CaptionRules;
use SocialPoster\Capabilities\DocumentRules;
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
 * Reference driver for byte-upload ingestion. Images and documents are uploaded
 * to a per-asset URL, video is uploaded in chunks (the parts the init step hands
 * back) then finalized. Video and document processing waits use resume(); images
 * and text are synchronous.
 *
 * prepare() escapes LinkedIn's reserved characters. Mention resolution and link
 * cards are intentionally out of scope: supply structured "little text" yourself
 * and set LinkedInOptions(escapeText: false) when you do.
 */
class LinkedInDriver extends AbstractPlatform
{
    protected string $rest = 'https://api.linkedin.com/rest';

    protected string $version = '202605';

    public function platform(): Platform
    {
        return Platform::LinkedIn;
    }

    protected function ingestion(): Ingestion
    {
        return Ingestion::Upload;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            caption: new CaptionRules(max: 3000),
            media: new MediaRules(
                minCount: 0,
                maxCount: 20,
                image: $this->imageRules(),
                video: $this->videoRules(),
                document: $this->documentRules(),
            ),
            supportsTitle: true,
            supportsCarousel: true,
        );
    }

    protected function rulesFor(PreparedPost $post): Capabilities
    {
        $caption = new CaptionRules(max: 3000);

        // Multi-image carousel: images only, up to 20.
        if (count($post->media()) > 1) {
            return new Capabilities(
                caption: $caption,
                media: new MediaRules(minCount: 2, maxCount: 20, image: $this->imageRules()),
                supportsCarousel: true,
            );
        }

        // Text, or a single image / video / document.
        return new Capabilities(
            caption: $caption,
            media: new MediaRules(
                minCount: 0,
                maxCount: 1,
                image: $this->imageRules(),
                video: $this->videoRules(),
                document: $this->documentRules(),
            ),
            supportsTitle: true,
        );
    }

    protected function validatePlatformRules(PreparedPost $post, MessageBag $errors): void
    {
        if (trim((string) $post->caption()) === '') {
            $errors->add('caption', "LinkedIn posts can't have an empty caption.");
        }
    }

    protected function prepare(PreparedPost $post): array
    {
        $caption = (string) $post->caption();
        $escape = $this->options($post)?->escapeText ?? true;

        // NOTE: validation checks the raw caption length. Escaping happens here and
        // can push the transmitted length past the 3000 limit on caption-heavy posts
        // with many reserved characters; revisit if that becomes a real problem.
        return ['commentary' => $escape ? $this->escape($caption) : $caption];
    }

    public function publish(PreparedPost $post): PublishOutcome
    {
        $media = $post->media();

        if ($media === []) {
            return $this->publishText($post);
        }

        if (count($media) > 1) {
            return $this->publishImages($post);
        }

        return match ($media[0]->type) {
            MediaType::Image => $this->publishImages($post),
            MediaType::Video => $this->startVideo($post, $media[0]),
            MediaType::Document => $this->startDocument($post, $media[0]),
        };
    }

    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        return match ($state['phase'] ?? null) {
            'await_video' => $this->finishVideo($post, $state),
            'await_document' => $this->finishDocument($post, $state),
            default => throw new PermanentException('Unknown LinkedIn publish phase.', Platform::LinkedIn),
        };
    }

    // --- Text -----------------------------------------------------------------

    protected function publishText(PreparedPost $post): PublishOutcome
    {
        $response = $this->rest($post)->post('posts', $this->buildPost($post, null));

        return $response->successful()
            ? $this->published(PostResult::success(Platform::LinkedIn, $this->foreignId($response)))
            : throw $this->mapError($response);
    }

    // --- Images (synchronous) --------------------------------------------------

    protected function publishImages(PreparedPost $post): PublishOutcome
    {
        $ids = [];

        try {
            foreach ($post->media() as $image) {
                $ids[] = $this->uploadImage($post, $image);
            }
        } finally {
            $this->gateway()?->cleanup();
        }

        $content = count($ids) > 1
            ? ['multiImage' => ['images' => array_map(fn ($id) => ['id' => $id], $ids)]]
            : ['media' => ['id' => $ids[0]]];

        $response = $this->rest($post)->post('posts', $this->buildPost($post, $content));

        return $response->successful()
            ? $this->published(PostResult::success(Platform::LinkedIn, $this->foreignId($response)))
            : throw $this->mapError($response);
    }

    protected function uploadImage(PreparedPost $post, Media $image): string
    {
        $init = $this->rest($post)->post('images?action=initializeUpload', [
            'initializeUploadRequest' => ['owner' => $this->author($post)],
        ]);

        if (! $init->successful()) {
            throw $this->mapError($init);
        }

        $put = Http::withToken($this->token($post))
            ->withBody($this->bytes($image), 'application/octet-stream')
            ->put($init->json('value.uploadUrl'));

        if (! $put->successful()) {
            throw new TemporaryException('Failed to upload an image to LinkedIn.', Platform::LinkedIn, FailureReason::ServerError);
        }

        return $init->json('value.image');
    }

    // --- Video (chunked upload, then processing wait) --------------------------

    protected function startVideo(PreparedPost $post, Media $video): PublishOutcome
    {
        try {
            $path = $this->mediaPath($video);
            $size = @filesize($path) ?: 0;

            $init = $this->rest($post)->post('videos?action=initializeUpload', [
                'initializeUploadRequest' => [
                    'owner' => $this->author($post),
                    'uploadCaptions' => false,
                    'uploadThumbnail' => false,
                    'fileSizeBytes' => $size,
                ],
            ]);

            if (! $init->successful()) {
                throw $this->mapError($init);
            }

            $videoUrn = $init->json('value.video');
            $partIds = $this->uploadVideoChunks($post, $path, $init->json('value.uploadInstructions') ?? []);

            $finalize = $this->rest($post)->post('videos?action=finalizeUpload', [
                'finalizeUploadRequest' => [
                    'video' => $videoUrn,
                    'uploadToken' => '',
                    'uploadedPartIds' => $partIds,
                ],
            ]);

            if (! $finalize->successful()) {
                throw $this->mapError($finalize);
            }

            return $this->pending(['phase' => 'await_video', 'video' => $videoUrn], recheckAfter: 10);
        } finally {
            $this->gateway()?->cleanup();
        }
    }

    /**
     * @param array<int, array<string, mixed>> $instructions
     * @return string[]
     */
    protected function uploadVideoChunks(PreparedPost $post, string $path, array $instructions): array
    {
        $partIds = [];
        $handle = fopen($path, 'rb');

        try {
            foreach ($instructions as $instruction) {
                $firstByte = (int) $instruction['firstByte'];
                $lastByte = (int) $instruction['lastByte'];

                fseek($handle, $firstByte);
                $chunk = fread($handle, $lastByte - $firstByte + 1);

                $put = Http::withToken($this->token($post))
                    ->withBody($chunk, 'application/octet-stream')
                    ->put($instruction['uploadUrl']);

                if (! $put->successful()) {
                    throw new TemporaryException('Failed to upload a video chunk to LinkedIn.', Platform::LinkedIn, FailureReason::ServerError);
                }

                $partIds[] = $put->header('ETag');
            }
        } finally {
            fclose($handle);
        }

        return $partIds;
    }

    protected function finishVideo(PreparedPost $post, array $state): PublishOutcome
    {
        $status = $this->assetStatus($post, $state['video']);

        if ($status === 'PROCESSING') {
            return $this->pending($state, recheckAfter: 15);
        }

        if ($status === 'CLIENT_ERROR') {
            throw new PermanentException('LinkedIn could not process the video.', Platform::LinkedIn, FailureReason::MediaRejected);
        }

        $response = $this->rest($post)->post('posts', $this->buildPost($post, ['media' => ['id' => $state['video']]]));

        return $response->successful()
            ? $this->published(PostResult::success(Platform::LinkedIn, $this->foreignId($response)))
            : throw $this->mapError($response);
    }

    // --- Document (upload, then processing wait) -------------------------------

    protected function startDocument(PreparedPost $post, Media $document): PublishOutcome
    {
        try {
            $init = $this->rest($post)->post('documents?action=initializeUpload', [
                'initializeUploadRequest' => ['owner' => $this->author($post)],
            ]);

            if (! $init->successful()) {
                throw $this->mapError($init);
            }

            $put = Http::withToken($this->token($post))
                ->withBody($this->bytes($document), 'application/octet-stream')
                ->put($init->json('value.uploadUrl'));

            if (! $put->successful()) {
                throw new TemporaryException('Failed to upload a document to LinkedIn.', Platform::LinkedIn, FailureReason::ServerError);
            }

            return $this->pending([
                'phase' => 'await_document',
                'document' => $init->json('value.document'),
                'title' => $post->title() ?? 'Document',
            ], recheckAfter: 20);
        } finally {
            $this->gateway()?->cleanup();
        }
    }

    protected function finishDocument(PreparedPost $post, array $state): PublishOutcome
    {
        $response = $this->rest($post)->get('documents/'.urlencode($state['document']));

        if (! $response->successful()) {
            throw $this->mapError($response);
        }

        $status = (string) $response->json('status');

        if ($status === 'PROCESSING_FAILED') {
            throw new PermanentException('LinkedIn failed to process the document.', Platform::LinkedIn, FailureReason::MediaRejected);
        }

        if ($status === 'WAITING_UPLOAD') {
            return $this->pending($state, recheckAfter: 20);
        }

        $content = ['media' => ['title' => $state['title'], 'id' => $state['document']]];
        $post_response = $this->rest($post)->post('posts', $this->buildPost($post, $content));

        return $post_response->successful()
            ? $this->published(PostResult::success(Platform::LinkedIn, $this->foreignId($post_response)))
            : throw $this->mapError($post_response);
    }

    // --- Helpers ---------------------------------------------------------------

    protected function buildPost(PreparedPost $post, ?array $content): array
    {
        $payload = [
            'author' => $this->author($post),
            'commentary' => $this->prepare($post)['commentary'],
            'visibility' => 'PUBLIC',
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        if ($content !== null) {
            $payload['content'] = $content;
        }

        return $this->mergeExtra($post, $payload);
    }

    protected function assetStatus(PreparedPost $post, string $videoUrn): string
    {
        $response = Http::withToken($this->token($post))
            ->get('https://api.linkedin.com/v2/assets/'.$this->urnId($videoUrn));

        if (! $response->successful()) {
            throw $this->mapError($response);
        }

        return (string) $response->json('recipes.0.status', 'PROCESSING');
    }

    protected function options(PreparedPost $post): ?LinkedInOptions
    {
        $options = $post->options();

        return $options instanceof LinkedInOptions ? $options : null;
    }

    protected function escape(string $text): string
    {
        return preg_replace('/[\\\\()\[\]{}@*<>_~]/', '\\\\$0', $text) ?? $text;
    }

    protected function bytes(Media $media): string
    {
        return (string) file_get_contents($this->mediaPath($media));
    }

    protected function author(PreparedPost $post): string
    {
        return (string) $post->credentials->get('author');
    }

    protected function token(PreparedPost $post): string
    {
        return (string) $post->credentials->get('access_token');
    }

    protected function rest(PreparedPost $post)
    {
        return Http::withToken($this->token($post))
            ->withHeaders([
                'X-Restli-Protocol-Version' => '2.0.0',
                'LinkedIn-Version' => $this->version,
            ])
            ->baseUrl($this->rest);
    }

    protected function foreignId($response): ?string
    {
        return $response->header('x-restli-id') ?: ($response->header('x-linkedin-id') ?: null);
    }

    protected function urnId(string $urn): string
    {
        $parts = explode(':', $urn);

        return (string) end($parts);
    }

    protected function mapError($response): TemporaryException|PermanentException
    {
        $json = $response->json() ?? [];
        $context = ['status' => $response->status(), 'error' => $json];

        if (isset($json['errorDetails']['inputErrors'])) {
            $message = '';

            foreach ($json['errorDetails']['inputErrors'] as $inputError) {
                $message .= $inputError['description'] ?? '';
            }

            return new PermanentException($message !== '' ? $message : 'LinkedIn rejected the post.', Platform::LinkedIn, FailureReason::Unknown, $context);
        }

        $message = (string) ($json['message'] ?? 'LinkedIn request failed.');

        if (str_contains($message, 'Resource level throttle')) {
            return new TemporaryException('LinkedIn is throttling this resource; try again later.', Platform::LinkedIn, FailureReason::RateLimited, 86400, $context);
        }

        if ($response->serverError()) {
            return new TemporaryException($message, Platform::LinkedIn, FailureReason::ServerError, null, $context);
        }

        return new PermanentException($message, Platform::LinkedIn, FailureReason::Unknown, $context);
    }

    // --- Rule sets -------------------------------------------------------------

    protected function imageRules(): ImageRules
    {
        return new ImageRules(extensions: ['jpg', 'jpeg', 'gif', 'png']);
    }

    protected function videoRules(): VideoRules
    {
        return new VideoRules(extensions: ['mp4'], maxSizeBytes: 209715200, minDuration: 3, maxDuration: 1800);
    }

    protected function documentRules(): DocumentRules
    {
        return new DocumentRules(extensions: ['pdf', 'doc', 'docx', 'ppt', 'pptx'], maxSizeBytes: 104857600);
    }
}
