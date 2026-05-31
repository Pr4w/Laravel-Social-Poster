<?php

namespace SocialPoster\Gateways;

use SocialPoster\Contracts\MediaGateway;
use SocialPoster\Enums\FailureReason;
use SocialPoster\Exceptions\PermanentException;
use SocialPoster\Exceptions\TemporaryException;
use SocialPoster\ValueObjects\Media;

/**
 * Default gateway. Remote media is passed through as a URL; bytes are produced
 * for anything (downloading remote sources to a temp file). It cannot invent a
 * public URL for a local file, so url() on local media fails loudly. Bind your
 * own MediaGateway (e.g. one that uploads to S3 and returns a signed URL) to
 * support local files on pull-only platforms.
 */
class LocalMediaGateway implements MediaGateway
{
    /** @var string[] */
    private array $tempFiles = [];

    public function __construct(
        protected ?string $tempDir = null,
    ) {}

    public function canProvideUrl(Media $media): bool
    {
        return $media->isRemote();
    }

    public function url(Media $media): string
    {
        if (! $media->isRemote()) {
            throw new PermanentException(
                "No public URL is available for the local media '{$media->source}'. Host it publicly, pass a URL, or bind a MediaGateway that can publish local files.",
                reason: FailureReason::MediaRejected,
            );
        }

        return $media->source;
    }

    public function path(Media $media): string
    {
        if (! $media->isRemote()) {
            if (! is_file($media->source)) {
                throw new PermanentException("Local media not found: {$media->source}", reason: FailureReason::MediaRejected);
            }

            return $media->source;
        }

        $dir = $this->tempDir ?? sys_get_temp_dir();
        $ext = $media->extension() !== '' ? '.'.$media->extension() : '';
        $tmp = $dir.'/sp_'.bin2hex(random_bytes(8)).$ext;

        if (! @copy($media->source, $tmp)) {
            throw new TemporaryException("Could not download media: {$media->source}", reason: FailureReason::Timeout);
        }

        $this->tempFiles[] = $tmp;

        return $tmp;
    }

    public function cleanup(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        $this->tempFiles = [];
    }
}
