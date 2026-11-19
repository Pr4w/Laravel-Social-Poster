<?php

namespace SocialPoster\Contracts;

use SocialPoster\ValueObjects\Media;

/**
 * Turns a Media into the form a platform needs: a public URL it can pull, or a
 * local path to the bytes it must be given. This is where the local-vs-remote
 * distinction is resolved, isolated from every driver.
 *
 * Shipped implementations: LocalMediaGateway (default: passes URLs through,
 * downloads remote media with SSRF, timeout and size guards) and
 * FilesystemMediaGateway (also re-hosts local files on a public disk so
 * pull-only platforms can fetch them). Both understand Media::fromDisk().
 */
interface MediaGateway
{
    /** True when url() can return a publicly fetchable address for this media. */
    public function canProvideUrl(Media $media): bool;

    /** A URL the platform can fetch from. Throws when none can be produced. */
    public function url(Media $media): string;

    /** A local filesystem path to the bytes, downloading remote media if needed. */
    public function path(Media $media): string;

    /** Release any temporary files created by path() during this publish. */
    public function cleanup(): void;
}
