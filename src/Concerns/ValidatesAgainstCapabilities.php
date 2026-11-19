<?php

namespace SocialPoster\Concerns;

use Illuminate\Support\MessageBag;
use SocialPoster\Capabilities\Capabilities;
use SocialPoster\Capabilities\DocumentRules;
use SocialPoster\Capabilities\ImageRules;
use SocialPoster\Capabilities\VideoRules;
use SocialPoster\Contracts\MediaInspector;
use SocialPoster\Contracts\MediaTransformer;
use SocialPoster\Enums\MediaType;
use SocialPoster\Enums\Platform;
use SocialPoster\Support\Text\CaptionCounter;
use SocialPoster\Support\Text\MbStrlenCounter;
use SocialPoster\Transformers\ImageConvertingTransformer;
use SocialPoster\Transformers\NullMediaTransformer;
use SocialPoster\ValueObjects\Media;
use SocialPoster\ValueObjects\MediaMetadata;
use SocialPoster\ValueObjects\PreparedPost;
use Throwable;
use WeakMap;

/**
 * The generic, rules-driven validation shared by every driver. It validates
 * against whatever Capabilities the driver resolved for this post (see
 * AbstractPlatform::rulesFor), so conditional platforms get the right limits.
 *
 * Text is measured with the rules' CaptionCounter (X weighting, graphemes,
 * UTF-16, Threads emoji bytes) and the message names the counting rule.
 *
 * Deep media checks run on the inspector's metadata, completed by what the
 * caller supplied in Media::$meta (or on that alone when the inspector cannot
 * reach the file). Each Media object is inspected at most once per driver
 * instance: the cache is a WeakMap, so entries die with their Media and a
 * long-lived worker can never hand one file's metadata to another. Inspection
 * never throws.
 */
trait ValidatesAgainstCapabilities
{
    /** @var WeakMap<Media, MediaMetadata|false>|null false = nothing known */
    private ?WeakMap $inspectionCache = null;

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
        $rules = $caps->caption;

        $this->validateText('caption', 'Caption', $caption, $rules->max, $rules->counter, $errors, $rules->min, $rules->maxBytes);
    }

    /**
     * Length checks for any text field (caption, first comment, title...),
     * measured on the trimmed text with $counter. Reusable by drivers and the
     * comment validation: $this->validateText('comment', 'Comment', $text, $rules->max, $rules->counter, $errors).
     */
    protected function validateText(
        string $field,
        string $label,
        ?string $text,
        int $max,
        ?CaptionCounter $counter,
        MessageBag $errors,
        int $min = 0,
        ?int $maxBytes = null,
    ): void {
        $text = trim($text ?? '');
        $counter ??= new MbStrlenCounter;
        $length = $counter->count($text);
        $rule = $counter instanceof MbStrlenCounter ? '' : '; '.$counter->describe();

        if ($length > $max) {
            $errors->add($field, "{$label} exceeds the {$max} character limit (currently {$length}{$rule}).");
        }

        if ($maxBytes !== null && strlen($text) > $maxBytes) {
            $errors->add($field, "{$label} exceeds the {$maxBytes}-byte limit (currently ".strlen($text).' bytes of UTF-8).');
        }

        if ($text !== '' && $length < $min) {
            $errors->add($field, "{$label} must be at least {$min} characters{$rule}.");
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

        $converting = false;

        if ($rules->extensions !== null && ! in_array($media->extension(), $rules->extensions, true)) {
            $target = $rules instanceof ImageRules ? $rules->conversionTarget($media->extension()) : null;
            $converting = $target !== null && $this->canTransform($media, $target);

            if (! $converting) {
                $allowed = implode(', ', $rules->extensions);
                $hint = $target !== null
                    ? " Convert it to .{$target} first, or set social.transformer to ImageConvertingTransformer (needs ext-gd or ext-imagick) to convert it automatically."
                    : '';

                $errors->add("media.{$index}", "Unsupported file type .{$media->extension()}; allowed: {$allowed}.{$hint}");
            }
        }

        $meta = $this->inspectMedia($media);

        if ($meta === null) {
            return; // nothing known about this media; structural checks only
        }

        if ($converting) {
            // Size and MIME describe the file before conversion; only pixel rules still apply.
            $meta = new MediaMetadata(width: $meta->width, height: $meta->height, rotation: $meta->rotation);
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

        if ($rules->minSizeBytes && $meta->sizeBytes !== null && $meta->sizeBytes < $rules->minSizeBytes) {
            $errors->add("media.{$index}", "Image is smaller than the {$rules->minSizeBytes}-byte minimum file size.");
        }

        if ($meta->mimeType && $rules->types && ! in_array($meta->mimeType, $rules->types, true)) {
            $errors->add("media.{$index}", "Unsupported image type {$meta->mimeType}.");
        }

        $this->assertImageDimensions($index, $rules, $meta, $errors);
        $this->assertAspectRatio($index, $rules->aspectRatioRange, $meta, $errors);
    }

    protected function assertImageDimensions(int $index, ImageRules $rules, MediaMetadata $meta, MessageBag $errors): void
    {
        $pixels = $meta->pixels();

        if ($rules->maxPixels && $pixels !== null && $pixels > $rules->maxPixels) {
            $errors->add("media.{$index}", "Image has {$pixels} pixels; the maximum is {$rules->maxPixels}.");
        }

        if ($rules->minWidth && $meta->width && $meta->width < $rules->minWidth) {
            $errors->add("media.{$index}", "Image width is below the {$rules->minWidth}px minimum.");
        }

        if ($rules->maxWidth && $meta->width && $meta->width > $rules->maxWidth) {
            $errors->add("media.{$index}", "Image width is above the {$rules->maxWidth}px maximum.");
        }

        if ($rules->minHeight && $meta->height && $meta->height < $rules->minHeight) {
            $errors->add("media.{$index}", "Image height is below the {$rules->minHeight}px minimum.");
        }

        if ($rules->maxHeight && $meta->height && $meta->height > $rules->maxHeight) {
            $errors->add("media.{$index}", "Image height is above the {$rules->maxHeight}px maximum.");
        }
    }

    protected function assertVideo(int $index, VideoRules $rules, MediaMetadata $meta, MessageBag $errors): void
    {
        if ($rules->maxSizeBytes && $meta->sizeBytes && $meta->sizeBytes > $rules->maxSizeBytes) {
            $errors->add("media.{$index}", 'Video exceeds the maximum file size.');
        }

        if ($rules->minSizeBytes && $meta->sizeBytes !== null && $meta->sizeBytes < $rules->minSizeBytes) {
            $errors->add("media.{$index}", "Video is smaller than the {$rules->minSizeBytes}-byte minimum file size.");
        }

        if ($rules->maxDuration && $meta->durationSeconds && $meta->durationSeconds > $rules->maxDuration) {
            $errors->add("media.{$index}", "Video is too long: maximum is {$rules->maxDuration}s.");
        }

        if ($rules->minDuration && $meta->durationSeconds && $meta->durationSeconds < $rules->minDuration) {
            $errors->add("media.{$index}", "Video is too short: minimum is {$rules->minDuration}s.");
        }

        $this->assertDimensions($index, $rules, $meta, $errors);
        $this->assertFrameRate($index, $rules, $meta, $errors);

        if ($rules->codecs && $meta->codec && ! in_array($meta->codec, $rules->codecs, true)) {
            $errors->add("media.{$index}", "Unsupported video codec {$meta->codec}.");
        }

        if ($meta->mimeType && $rules->types && ! $this->videoMimeAccepted($meta->mimeType, $rules)) {
            $errors->add("media.{$index}", "Unsupported video format {$meta->mimeType}.");
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

    protected function assertFrameRate(int $index, VideoRules $rules, MediaMetadata $meta, MessageBag $errors): void
    {
        if ($meta->frameRate === null) {
            return;
        }

        $fps = round($meta->frameRate, 2);

        // Half a frame of tolerance: 23.976 counts as 24, 59.94 as 60.
        if ($rules->minFrameRate && $meta->frameRate < $rules->minFrameRate - 0.5) {
            $errors->add("media.{$index}", "Video frame rate {$fps} fps is below the {$rules->minFrameRate} fps minimum.");
        }

        if ($rules->maxFrameRate && $meta->frameRate > $rules->maxFrameRate + 0.5) {
            $errors->add("media.{$index}", "Video frame rate {$fps} fps is above the {$rules->maxFrameRate} fps maximum.");
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

    /**
     * A reported video MIME passes when it is listed in $types, when it is the
     * MIME of an allowed extension (.mov → video/quicktime), or when both are
     * ISO-BMFF flavours (mp4/mov/m4v/3gp share a container and platforms take
     * them alike). This catches a .mp4 that is really WebM or AVI without
     * rejecting the many phone files whose brand says QuickTime.
     */
    protected function videoMimeAccepted(string $mime, VideoRules $rules): bool
    {
        $mime = strtolower($mime);
        $accepted = array_map('strtolower', $rules->types);

        foreach ((array) $rules->extensions as $extension) {
            $accepted = array_merge($accepted, $this->videoMimesFor((string) $extension));
        }

        if (in_array($mime, $accepted, true)) {
            return true;
        }

        $isoFamily = ['video/mp4', 'video/quicktime', 'video/x-m4v', 'video/3gpp'];

        return in_array($mime, $isoFamily, true) && array_intersect($accepted, $isoFamily) !== [];
    }

    /**
     * Inspect a media item at most once per Media object (also used by rulesFor()
     * and publish-time routing such as Facebook's reel detection).
     *
     * Inspector values win; Media::$meta fills the gaps (and stands alone when
     * the inspector cannot reach the file). Null when nothing is known.
     */
    protected function inspectMedia(Media $media): ?MediaMetadata
    {
        $this->inspectionCache ??= new WeakMap;

        if (! isset($this->inspectionCache[$media])) {
            $known = $media->knownMetadata();
            $measured = $this->runInspector($media);
            $meta = $measured !== null ? $measured->withFallback($known) : $known;

            $this->inspectionCache[$media] = $meta->isEmpty() ? false : $meta;
        }

        $cached = $this->inspectionCache[$media];

        return $cached === false ? null : $cached;
    }

    /** The inspector's metadata, or null when it cannot see the media or fails. */
    private function runInspector(Media $media): ?MediaMetadata
    {
        $inspector = $this->inspector();

        if ($inspector === null) {
            return null;
        }

        try {
            return $inspector->supports($media) ? $inspector->inspect($media) : null;
        } catch (Throwable) {
            return null; // third-party inspectors may throw; validation must not
        }
    }

    /** The bound MediaTransformer, or null when none is bound. */
    protected function mediaTransformer(): ?MediaTransformer
    {
        try {
            return app()->bound(MediaTransformer::class) ? app(MediaTransformer::class) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether the bound transformer will turn $media into a $target file, so a
     * convertible extension (ImageRules::$convert) can pass validation. A custom
     * transformer is trusted to honour the rules it is given.
     */
    protected function canTransform(Media $media, string $target): bool
    {
        $transformer = $this->mediaTransformer();

        if ($transformer === null || $transformer instanceof NullMediaTransformer) {
            return false;
        }

        if ($transformer instanceof ImageConvertingTransformer) {
            return $transformer->canConvert($media->extension(), $target);
        }

        return true;
    }

    /**
     * MIME types per video extension, for videoMimeAccepted().
     *
     * @return string[]
     */
    protected function videoMimesFor(string $extension): array
    {
        return match (strtolower($extension)) {
            'mp4' => ['video/mp4'],
            'm4v' => ['video/x-m4v', 'video/mp4'],
            'mov', 'qt' => ['video/quicktime'],
            '3gp' => ['video/3gpp'],
            'webm' => ['video/webm'],
            'mkv' => ['video/x-matroska'],
            'avi' => ['video/x-msvideo', 'video/avi'],
            'wmv' => ['video/x-ms-wmv', 'video/x-ms-asf'],
            'flv' => ['video/x-flv'],
            'mpg', 'mpeg' => ['video/mpeg'],
            'ts' => ['video/mp2t'],
            'gif' => ['image/gif'],
            default => [],
        };
    }
}
