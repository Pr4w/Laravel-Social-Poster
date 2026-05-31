<?php

namespace SocialPoster\Concerns;

use Illuminate\Support\MessageBag;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\DocumentRules;
use SocialPoster\Capabilities\ImageRules;
use SocialPoster\Capabilities\VideoRules;
use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Enums\MediaType;
use SocialPoster\Enums\Platform;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\PreparedPost;

/**
 * The generic, rules-driven validation shared by every driver. It validates
 * against whatever Capabilities the driver resolved for this post (see
 * AbstractPlatform::rulesFor), so conditional platforms get the right limits.
 *
 * Deep media checks only run when an inspector can see the media, and each
 * media item is inspected at most once per post.
 */
trait ValidatesAgainstCapabilities
{
    /** @var array<int, MediaMetadata|null> */
    private array $inspectionCache = [];

    abstract public function platform(): Platform;

    abstract protected function inspector(): ?MediaInspector;

    protected function validateAgainstCapabilities(PreparedPost $post, Capabilities $caps, MessageBag $errors): void
    {
        $this->validateCaption($post->caption(), $caps, $errors);
        $this->validateMediaCount($post->media(), $caps, $errors);

        foreach ($post->media() as $index => $item) {
            $this->validateMediaItem($index, $item, $caps, $errors);
        }
    }

    protected function validateCaption(?string $caption, Capabilities $caps, MessageBag $errors): void
    {
        $caption ??= '';
        $length = mb_strlen($caption);

        if ($length > $caps->caption->max) {
            $errors->add('caption', "Caption exceeds the {$caps->caption->max} character limit (currently {$length}).");
        }

        if ($caption !== '' && $length < $caps->caption->min) {
            $errors->add('caption', "Caption must be at least {$caps->caption->min} characters.");
        }
    }

    /** @param Media[] $media */
    protected function validateMediaCount(array $media, Capabilities $caps, MessageBag $errors): void
    {
        $count = count($media);

        if ($count > $caps->media->maxCount) {
            $errors->add('media', "Too many media items: maximum is {$caps->media->maxCount}, got {$count}.");
        }

        if ($count < $caps->media->minCount) {
            $errors->add('media', "Not enough media items: minimum is {$caps->media->minCount}, got {$count}.");
        }
    }

    protected function validateMediaItem(int $index, Media $media, Capabilities $caps, MessageBag $errors): void
    {
        $rules = match ($media->type) {
            MediaType::Image => $caps->media->image,
            MediaType::Video => $caps->media->video,
            MediaType::Document => $caps->media->document,
        };

        if ($rules === null) {
            $errors->add("media.{$index}", "{$media->type->value} media is not supported here on {$this->platform()->value}.");

            return;
        }

        if ($rules->extensions !== null && ! in_array($media->extension(), $rules->extensions, true)) {
            $allowed = implode(', ', $rules->extensions);
            $errors->add("media.{$index}", "Unsupported file type .{$media->extension()}; allowed: {$allowed}.");
        }

        $meta = $this->inspectMedia($media);

        if ($meta === null) {
            return; // inspector could not reach this media; structural checks only
        }

        match ($media->type) {
            MediaType::Image => $this->assertImage($index, $rules, $meta, $errors),
            MediaType::Video => $this->assertVideo($index, $rules, $meta, $errors),
            MediaType::Document => $this->assertDocument($index, $rules, $meta, $errors),
        };
    }

    protected function assertDocument(int $index, DocumentRules $rules, MediaMetadata $meta, MessageBag $errors): void
    {
        if ($rules->maxSizeBytes && $meta->sizeBytes && $meta->sizeBytes > $rules->maxSizeBytes) {
            $errors->add("media.{$index}", 'Document exceeds the maximum file size.');
        }
    }

    protected function assertImage(int $index, ImageRules $rules, MediaMetadata $meta, MessageBag $errors): void
    {
        if ($rules->maxSizeBytes && $meta->sizeBytes && $meta->sizeBytes > $rules->maxSizeBytes) {
            $errors->add("media.{$index}", 'Image exceeds the maximum file size.');
        }

        if ($meta->mimeType && $rules->types && ! in_array($meta->mimeType, $rules->types, true)) {
            $errors->add("media.{$index}", "Unsupported image type {$meta->mimeType}.");
        }

        $this->assertAspectRatio($index, $rules->aspectRatioRange, $meta, $errors);
    }

    protected function assertVideo(int $index, VideoRules $rules, MediaMetadata $meta, MessageBag $errors): void
    {
        if ($rules->maxSizeBytes && $meta->sizeBytes && $meta->sizeBytes > $rules->maxSizeBytes) {
            $errors->add("media.{$index}", 'Video exceeds the maximum file size.');
        }

        if ($rules->maxDuration && $meta->durationSeconds && $meta->durationSeconds > $rules->maxDuration) {
            $errors->add("media.{$index}", "Video is too long: maximum is {$rules->maxDuration}s.");
        }

        if ($rules->minDuration && $meta->durationSeconds && $meta->durationSeconds < $rules->minDuration) {
            $errors->add("media.{$index}", "Video is too short: minimum is {$rules->minDuration}s.");
        }

        $this->assertDimensions($index, $rules, $meta, $errors);

        if ($rules->codecs && $meta->codec && ! in_array($meta->codec, $rules->codecs, true)) {
            $errors->add("media.{$index}", "Unsupported video codec {$meta->codec}.");
        }

        $this->assertAspectRatio($index, $rules->aspectRatioRange, $meta, $errors);
    }

    protected function assertDimensions(int $index, VideoRules $rules, MediaMetadata $meta, MessageBag $errors): void
    {
        if ($rules->minWidth && $meta->width && $meta->width < $rules->minWidth) {
            $errors->add("media.{$index}", "Video width is below the {$rules->minWidth}px minimum.");
        }

        if ($rules->maxWidth && $meta->width && $meta->width > $rules->maxWidth) {
            $errors->add("media.{$index}", "Video width is above the {$rules->maxWidth}px maximum.");
        }

        if ($rules->minHeight && $meta->height && $meta->height < $rules->minHeight) {
            $errors->add("media.{$index}", "Video height is below the {$rules->minHeight}px minimum.");
        }

        if ($rules->maxHeight && $meta->height && $meta->height > $rules->maxHeight) {
            $errors->add("media.{$index}", "Video height is above the {$rules->maxHeight}px maximum.");
        }
    }

    /** @param array{0: float, 1: float}|null $range */
    protected function assertAspectRatio(int $index, ?array $range, MediaMetadata $meta, MessageBag $errors): void
    {
        $ratio = $meta->aspectRatio();

        if (! $range || $ratio === null) {
            return;
        }

        [$min, $max] = $range;

        if ($ratio < $min || $ratio > $max) {
            $errors->add("media.{$index}", 'Media aspect ratio is outside the supported range.');
        }
    }

    /** Inspect a media item at most once per post. Available to rulesFor() too. */
    protected function inspectMedia(Media $media): ?MediaMetadata
    {
        $inspector = $this->inspector();

        if ($inspector === null || ! $inspector->supports($media)) {
            return null;
        }

        $key = spl_object_id($media);

        return $this->inspectionCache[$key] ??= $inspector->inspect($media);
    }
}
