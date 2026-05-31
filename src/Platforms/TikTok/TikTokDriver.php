<?php

namespace SocialPoster\Platforms\TikTok;

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
 * TikTok Content Posting API (v2). Two posting targets, chosen via TikTokOptions:
 *
 *  - Drafts (default): uploads to the creator's inbox for them to finish in-app.
 *    Needs only the video.upload scope. No creator-info query, no privacy level.
 *    Video uses /post/publish/inbox/video/init/; photos use /content/init/ with
 *    post_mode=MEDIA_UPLOAD. No post_info is sent for inbox video.
 *
 *  - DirectPost: publishes straight to the profile. Needs video.publish and an
 *    audited client. Always queries creator-info first (required, and it tells
 *    us the permitted privacy levels and any forced comment/duet/stitch locks),
 *    requires a privacy level, and carries a full post_info. Video uses
 *    /post/publish/video/init/; photos use /content/init/ with DIRECT_POST.
 *
 * Media transfer is PULL_FROM_URL when the media is a public URL (required for
 * photos), or chunked FILE_UPLOAD for local video. Every flow returns a
 * publish_id and then processes asynchronously, so publish() hands off to the
 * status poll through resume().
 */
class TikTokDriver extends AbstractPlatform
{
    protected string $api = 'https://open.tiktokapis.com/v2';

    protected int $captionLimit = 2000; // UTF-16 code units

    protected int $uploadChunkSize = 10485760; // 10 MB, within TikTok's 5-64 MB

    public function platform(): Platform
    {
        return Platform::TikTok;
    }

    protected function ingestion(): Ingestion
    {
        return Ingestion::Both;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            caption: new CaptionRules(max: PHP_INT_MAX), // length is enforced in UTF-16 by validatePlatformRules()
            media: new MediaRules(minCount: 1, maxCount: 35, image: $this->photoRules(), video: $this->videoRules()),
        );
    }

    protected function rulesFor(PreparedPost $post): Capabilities
    {
        // More than one item is always a photo carousel: images only, no mixing.
        if (count($post->media()) > 1) {
            return new Capabilities(
                caption: new CaptionRules(max: PHP_INT_MAX), // length is enforced in UTF-16 by validatePlatformRules()
                media: new MediaRules(minCount: 2, maxCount: 35, image: $this->photoRules()),
            );
        }

        // A single item is one photo or one video.
        return new Capabilities(
            caption: new CaptionRules(max: PHP_INT_MAX), // length is enforced in UTF-16 by validatePlatformRules()
            media: new MediaRules(minCount: 1, maxCount: 1, image: $this->photoRules(), video: $this->videoRules()),
        );
    }

    protected function validatePlatformRules(PreparedPost $post, MessageBag $errors): void
    {
        if ($this->utf16Length((string) $post->caption()) > $this->captionLimit) {
            $errors->add('caption', "TikTok captions can't be longer than {$this->captionLimit} characters.");
        }

        $options = $this->options($post);

        // Direct posting needs a privacy level; surface it here rather than only
        // failing at publish after the creator-info round trip.
        if ($options?->target === TikTokTarget::DirectPost && $options->privacyLevel === null) {
            $errors->add('privacy_level', 'TikTok direct posts require a privacy level. Set it on TikTokOptions.');
        }

        // Photos can only be pulled from a public URL; local image files cannot
        // be uploaded. Video may use FILE_UPLOAD, so local video is fine.
        if ($this->gateway() !== null) {
            foreach ($post->media() as $index => $media) {
                if ($media->type === MediaType::Image && ! $this->gateway()->canProvideUrl($media)) {
                    $errors->add(
                        "media.{$index}",
                        'TikTok photos must be pulled from a public URL. Host the image publicly or bind a gateway that can publish local files.',
                    );
                }
            }
        }
    }

    public function publish(PreparedPost $post): PublishOutcome
    {
        $options = $this->options($post) ?? new TikTokOptions();
        $media = $post->media();
        $isPhoto = count($media) > 1 || $media[0]->type === MediaType::Image;

        if ($options->target === TikTokTarget::DirectPost) {
            $creator = $this->queryCreatorInfo($post);

            return $isPhoto
                ? $this->initPhoto($post, $options, $media, direct: true, creator: $creator)
                : $this->initVideo($post, $options, $media[0], direct: true, creator: $creator);
        }

        return $isPhoto
            ? $this->initPhoto($post, $options, $media, direct: false, creator: null)
            : $this->initVideo($post, $options, $media[0], direct: false, creator: null);
    }

    public function resume(PreparedPost $post, array $state): PublishOutcome
    {
        if (($state['phase'] ?? null) !== 'await_publish') {
            throw new PermanentException('Unknown TikTok publish phase.', Platform::TikTok);
        }

        $response = $this->tt($post)->post($this->api.'/post/publish/status/fetch/', [
            'publish_id' => $state['publish_id'],
        ]);

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        $status = (string) $response->json('data.status');

        // PUBLISH_COMPLETE ends a direct post; SEND_TO_USER_INBOX ends a draft.
        if ($status === 'PUBLISH_COMPLETE' || $status === 'SEND_TO_USER_INBOX') {
            $postId = $response->json('data.publicaly_available_post_id.0');

            return $this->published(PostResult::success(
                Platform::TikTok,
                $state['publish_id'],
                payload: ['status' => $status, 'post_id' => $postId],
            ));
        }

        if ($status === 'FAILED') {
            $reason = (string) ($response->json('data.fail_reason') ?: 'unknown');
            throw new PermanentException("TikTok failed to process the post: {$reason}.", Platform::TikTok, FailureReason::MediaRejected);
        }

        return $this->pending($state, recheckAfter: 15);
    }

    // --- Video -----------------------------------------------------------------

    protected function initVideo(PreparedPost $post, TikTokOptions $options, Media $video, bool $direct, ?array $creator): PublishOutcome
    {
        $remote = $this->gateway() === null || $this->gateway()->canProvideUrl($video);

        // Direct post -> /video/init/ with post_info. Draft -> /inbox/video/init/, no post_info.
        $endpoint = $direct ? '/post/publish/video/init/' : '/post/publish/inbox/video/init/';

        $body = [];

        if ($direct) {
            $body['post_info'] = $this->directPostInfo($post, $options, $creator, video: true);
        }

        if ($remote) {
            $body['source_info'] = ['source' => 'PULL_FROM_URL', 'video_url' => $this->mediaUrl($video)];

            $response = $this->tt($post)->post($this->api.$endpoint, $body);

            if (! $this->ok($response)) {
                throw $this->mapError($response, $body);
            }

            return $this->awaitPublish($response->json('data.publish_id'), $direct);
        }

        // Local file: FILE_UPLOAD, then PUT the bytes in chunks.
        try {
            $path = $this->mediaPath($video);
            $size = (int) (@filesize($path) ?: 0);
            [$chunkSize, $totalChunks] = $this->chunkPlan($size);

            $body['source_info'] = [
                'source' => 'FILE_UPLOAD',
                'video_size' => $size,
                'chunk_size' => $chunkSize,
                'total_chunk_count' => $totalChunks,
            ];

            $response = $this->tt($post)->post($this->api.$endpoint, $body);

            if (! $this->ok($response)) {
                throw $this->mapError($response, $body);
            }

            $this->putChunks($response->json('data.upload_url'), $path, $size, $chunkSize, $totalChunks, $this->videoMime($video));

            return $this->awaitPublish($response->json('data.publish_id'), $direct);
        } finally {
            $this->gateway()?->cleanup();
        }
    }

    // --- Photo -----------------------------------------------------------------

    /** @param Media[] $media */
    protected function initPhoto(PreparedPost $post, TikTokOptions $options, array $media, bool $direct, ?array $creator): PublishOutcome
    {
        $images = array_map(fn (Media $m) => $this->mediaUrl($m), $media);

        $postInfo = $direct
            ? $this->directPostInfo($post, $options, $creator, video: false)
            : $this->draftPhotoInfo($post);

        $body = [
            'post_info' => $postInfo,
            'source_info' => [
                'source' => 'PULL_FROM_URL',
                'photo_cover_index' => $options->photoCoverIndex,
                'photo_images' => $images,
            ],
            'post_mode' => $direct ? 'DIRECT_POST' : 'MEDIA_UPLOAD',
            'media_type' => 'PHOTO',
        ];

        $response = $this->tt($post)->post($this->api.'/post/publish/content/init/', $body);

        if (! $this->ok($response)) {
            throw $this->mapError($response, $body);
        }

        return $this->awaitPublish($response->json('data.publish_id'), $direct);
    }

    // --- post_info builders ----------------------------------------------------

    protected function directPostInfo(PreparedPost $post, TikTokOptions $options, ?array $creator, bool $video): array
    {
        $creator ??= [];
        $caption = (string) $post->caption();

        $privacy = $options->privacyLevel;

        if ($privacy === null) {
            throw new PermanentException('TikTok direct posts require a privacy level.', Platform::TikTok, FailureReason::Unknown);
        }

        $allowed = $creator['privacy_level_options'] ?? [];

        if ($allowed !== [] && ! in_array($privacy->value, $allowed, true)) {
            throw new PermanentException(
                "Privacy level {$privacy->value} is not available for this creator. Allowed: ".implode(', ', $allowed).'.',
                Platform::TikTok,
                FailureReason::InsufficientPermissions,
            );
        }

        // Honour any interaction the creator has force-disabled account-wide.
        $computed = [
            'title' => $caption,
            'privacy_level' => $privacy->value,
        ];

        // The disable_* toggles are optional. Only send one when the creator
        // forces it off account-wide, or when the caller explicitly set it.
        // Sending disable_duet=false on a SELF_ONLY post contradicts TikTok
        // (private posts can't be dueted or stitched) and is rejected as
        // invalid_params, so by default we omit them and let TikTok apply the
        // right value for the chosen privacy level.
        $resolve = static fn (bool $forced, ?bool $chosen): ?bool => $forced ? true : $chosen;

        $comment = $resolve((bool) ($creator['comment_disabled'] ?? false), $options->disableComment);
        if ($comment !== null) {
            $computed['disable_comment'] = $comment;
        }

        if ($video) {
            $duet = $resolve((bool) ($creator['duet_disabled'] ?? false), $options->disableDuet);
            if ($duet !== null) {
                $computed['disable_duet'] = $duet;
            }

            $stitch = $resolve((bool) ($creator['stitch_disabled'] ?? false), $options->disableStitch);
            if ($stitch !== null) {
                $computed['disable_stitch'] = $stitch;
            }

            if ($options->coverTimestampMs !== null) {
                $computed['video_cover_timestamp_ms'] = $options->coverTimestampMs;
            }
        } elseif ($options->autoAddMusic !== null) {
            $computed['auto_add_music'] = $options->autoAddMusic;
        }

        // Layering: branded-content defaults are overridable by the user's extra
        // bag (e.g. to disclose a paid partnership), but the driver's computed
        // keys (title, privacy, the honoured disables) always win.
        // TikTok's own working video/init example omits the branded-content
        // toggles, and sending them as false triggered invalid_params in
        // practice. They are opt-in: disclose by adding them through extra(),
        // e.g. extra: ['brand_content_toggle' => true].
        return array_merge($this->extraPayload($post), $computed);
    }

    protected function draftPhotoInfo(PreparedPost $post): array
    {
        $caption = (string) $post->caption();
        $computed = $caption === '' ? [] : ['title' => $caption];

        // Drafts let the user finish in-app, but extra still flows into post_info.
        return array_merge($this->extraPayload($post), $computed);
    }

    // --- Creator info ----------------------------------------------------------

    protected function queryCreatorInfo(PreparedPost $post): array
    {
        $response = $this->tt($post)->post($this->api.'/post/publish/creator_info/query/');

        if (! $this->ok($response)) {
            throw $this->mapError($response);
        }

        return (array) $response->json('data', []);
    }

    // --- FILE_UPLOAD chunking --------------------------------------------------

    /** @return array{0: int, 1: int} [chunk_size, total_chunk_count] */
    protected function chunkPlan(int $size): array
    {
        // A whole file at or under the max chunk goes in a single chunk.
        if ($size <= 67108864) {
            return [$size, 1];
        }

        // Equal chunks; the final chunk carries the remainder (TikTok allows the
        // last chunk to exceed chunk_size), so total = floor(size / chunk_size).
        $total = max(1, intdiv($size, $this->uploadChunkSize));

        return [$this->uploadChunkSize, $total];
    }

    protected function putChunks(string $uploadUrl, string $path, int $size, int $chunkSize, int $totalChunks, string $mime): void
    {
        $handle = fopen($path, 'rb');

        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $start = $i * $chunkSize;
                $isLast = $i === $totalChunks - 1;
                $length = $isLast ? $size - $start : $chunkSize;

                $chunk = fread($handle, $length);
                $end = $start + strlen((string) $chunk) - 1;

                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'Content-Range' => "bytes {$start}-{$end}/{$size}",
                    'Content-Type' => $mime,
                ])->withBody((string) $chunk, $mime)->put($uploadUrl);

                if (! $response->successful()) {
                    throw new TemporaryException('TikTok rejected a video chunk upload.', Platform::TikTok, FailureReason::ServerError, 30, ['status' => $response->status()]);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    // --- Helpers ---------------------------------------------------------------

    protected function awaitPublish(?string $publishId, bool $direct): PublishOutcome
    {
        return $this->pending([
            'phase' => 'await_publish',
            'publish_id' => (string) $publishId,
            'direct' => $direct,
        ], recheckAfter: 15);
    }

    protected function tt(PreparedPost $post)
    {
        return \Illuminate\Support\Facades\Http::withToken($this->token($post))
            ->withHeaders(['Content-Type' => 'application/json; charset=UTF-8']);
    }

    protected function token(PreparedPost $post): string
    {
        return (string) $post->credentials->get('access_token');
    }

    protected function options(PreparedPost $post): ?TikTokOptions
    {
        $options = $post->options();

        return $options instanceof TikTokOptions ? $options : null;
    }

    protected function ok($response): bool
    {
        return $response->successful() && in_array($response->json('error.code'), ['ok', null], true);
    }

    protected function utf16Length(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        $utf16 = mb_convert_encoding($text, 'UTF-16', 'UTF-8');

        return (int) (strlen($utf16) / 2);
    }

    protected function videoMime(Media $video): string
    {
        return match ($video->extension()) {
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            default => 'video/mp4',
        };
    }

    protected function mapError($response, array $sent = []): TemporaryException|PermanentException
    {
        $code = (string) $response->json('error.code');
        $message = (string) ($response->json('error.message') ?: 'TikTok request failed.');
        $context = ['status' => $response->status(), 'error' => $response->json('error')];

        if ($sent !== []) {
            $context['sent'] = $sent;
        }

        if ($response->status() === 429 || str_contains($code, 'rate_limit')) {
            return new TemporaryException('TikTok rate limit reached.', Platform::TikTok, FailureReason::RateLimited, 900, $context);
        }

        if ($response->serverError() || str_contains($code, 'internal')) {
            return new TemporaryException($message, Platform::TikTok, FailureReason::ServerError, null, $context);
        }

        if (str_contains($code, 'token')) {
            return new PermanentException($message, Platform::TikTok, FailureReason::InvalidToken, $context);
        }

        if (str_contains($code, 'scope') || str_contains($code, 'permission') || str_contains($code, 'unaudited')) {
            return new PermanentException($message, Platform::TikTok, FailureReason::InsufficientPermissions, $context);
        }

        if (str_contains($code, 'spam') || str_contains($code, 'frequency')) {
            return new TemporaryException($message, Platform::TikTok, FailureReason::RateLimited, 1800, $context);
        }

        return new PermanentException($message, Platform::TikTok, FailureReason::Unknown, $context);
    }

    // --- Rule sets -------------------------------------------------------------

    protected function photoRules(): ImageRules
    {
        return new ImageRules(extensions: ['jpg', 'jpeg', 'webp'], maxSizeBytes: 20971520);
    }

    protected function videoRules(): VideoRules
    {
        return new VideoRules(
            extensions: ['mp4', 'webm', 'mov'],
            maxSizeBytes: 4294967296,
            minDuration: 3,
            maxDuration: 600,
            minWidth: 360,
            minHeight: 360,
            maxWidth: 4096,
            maxHeight: 4096,
        );
    }
}
