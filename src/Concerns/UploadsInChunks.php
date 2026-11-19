<?php

namespace SocialPoster\Concerns;

use SocialPoster\Enums\FailureReason;
use SocialPoster\Enums\Platform;
use SocialPoster\Exceptions\PermanentException;

/**
 * Staged uploads that survive the queue's job timeout. A large file (X APPEND
 * up to 8GB, LinkedIn parts, TikTok FILE_UPLOAD up to 4GB, YouTube resumable)
 * can take longer than one job run is allowed to live; a worker killed
 * mid-upload fails the post and, on TikTok, leaves an orphaned init behind.
 *
 * The pattern: keep the upload position in the Pending state (the next offset,
 * segment index, collected ETags...), send chunks while budgetLeft() says the
 * run has time, then return Pending(state, 0) so the next run continues where
 * this one stopped:
 *
 *     $started = microtime(true);
 *     while ($offset < $size && $this->budgetLeft($started)) {
 *         $chunk = $this->readChunk($path, $offset, $chunkSize);
 *         // send $chunk...
 *         $offset += strlen($chunk);
 *     }
 *     if ($offset < $size) {
 *         return $this->pending([...$state, 'offset' => $offset], 0);
 *     }
 *
 * Only one chunk is held in memory at a time. The per-run budget is
 * apiConfig('upload_run_seconds', 240), which stays below the job timeout.
 */
trait UploadsInChunks
{
    abstract public function platform(): Platform;

    abstract protected function apiConfig(string $key, mixed $default = null): mixed;

    /**
     * Read up to $length bytes of $path starting at $offset. Returns '' at or
     * past the end of the file. Never loads more than one chunk.
     *
     * @throws PermanentException When the file cannot be opened or read (MediaRejected).
     */
    protected function readChunk(string $path, int $offset, int $length): string
    {
        if ($offset < 0 || $length < 0) {
            throw new PermanentException(
                "Invalid upload chunk (offset {$offset}, length {$length}).",
                $this->platform(),
                FailureReason::MediaRejected,
            );
        }

        if ($length === 0) {
            return '';
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new PermanentException(
                'The media file could not be opened for upload.',
                $this->platform(),
                FailureReason::MediaRejected,
                ['path' => basename($path)],
            );
        }

        try {
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                throw new PermanentException(
                    "The media file could not be read at offset {$offset}.",
                    $this->platform(),
                    FailureReason::MediaRejected,
                    ['path' => basename($path), 'offset' => $offset],
                );
            }

            $chunk = '';

            // fread() may return fewer bytes than asked (stream wrappers, network
            // filesystems), so keep reading until the chunk is full or EOF.
            while (strlen($chunk) < $length && ! feof($handle)) {
                $read = fread($handle, max(1, $length - strlen($chunk)));

                if ($read === false) {
                    throw new PermanentException(
                        "The media file could not be read at offset {$offset}.",
                        $this->platform(),
                        FailureReason::MediaRejected,
                        ['path' => basename($path), 'offset' => $offset],
                    );
                }

                if ($read === '') {
                    break;
                }

                $chunk .= $read;
            }

            return $chunk;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Size of a local file in bytes.
     *
     * @throws PermanentException When the file is missing, unreadable or empty (MediaRejected).
     */
    protected function uploadSize(string $path): int
    {
        clearstatcache(true, $path);
        $size = @filesize($path);

        if (! is_int($size) || $size <= 0) {
            throw new PermanentException(
                'The media file is empty or unreadable.',
                $this->platform(),
                FailureReason::MediaRejected,
                ['path' => basename($path)],
            );
        }

        return $size;
    }

    /** Seconds one job run may spend uploading before it hands over to the next run. */
    protected function runBudgetSeconds(): int
    {
        return max(1, (int) $this->apiConfig('upload_run_seconds', 240));
    }

    /** Whether the run that started at $startedAt (microtime(true)) may send another chunk. */
    protected function budgetLeft(float $startedAt): bool
    {
        return (microtime(true) - $startedAt) < $this->runBudgetSeconds();
    }
}
