<?php

namespace SocialPoster\Gateways;

use RuntimeException;

/**
 * A download sink that refuses bytes past a cap, so the transfer really stops.
 *
 * Throwing from Guzzle's "progress" callback does not stop a cURL download:
 * PHP's cURL bindings swallow exceptions raised in callbacks, and cURL keeps
 * reading the body until it ends or the timeout fires. A write callback that
 * reports fewer bytes than it was given does stop it (CURLE_WRITE_ERROR), and
 * Guzzle returns the sink's write() result to cURL. So the cap is enforced
 * where the bytes are written: past it, writes report a short count.
 *
 * The sink is a plain PHP stream resource (a user stream wrapper over the temp
 * file), so it also works with Http::fake(), which writes fake bodies to
 * resource sinks with fwrite().
 *
 * @internal
 */
final class CappedSink
{
    public const PROTOCOL = 'social-poster-capped';

    /** True once a write was refused because of the cap. */
    public bool $exceeded = false;

    /** Bytes written to the file. */
    public int $written = 0;

    /** @var resource|null */
    private $resource = null;

    private function __construct(
        public readonly string $path,
        public readonly int $cap,
    ) {}

    /**
     * Open $path for writing with a $cap-byte limit (null: no limit).
     *
     * @throws RuntimeException when the file cannot be opened
     */
    public static function open(string $path, ?int $cap): self
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, CappedSinkStream::class);
        }

        $sink = new self($path, $cap ?? PHP_INT_MAX);
        $context = stream_context_create([self::PROTOCOL => ['sink' => $sink]]);
        $resource = @fopen(self::PROTOCOL.'://sink', 'w+b', false, $context);

        if (! is_resource($resource)) {
            throw new RuntimeException("Cannot write the temp file {$path}.");
        }

        $sink->resource = $resource;

        return $sink;
    }

    /** @return resource The resource to hand to Guzzle as the "sink" option. */
    public function resource()
    {
        if (! is_resource($this->resource)) {
            throw new RuntimeException('The download sink is closed.');
        }

        return $this->resource;
    }

    /** Flush and close the file (Guzzle may already have closed it). */
    public function close(): void
    {
        if (is_resource($this->resource)) {
            @fclose($this->resource);
        }

        $this->resource = null;
    }

    /** How many of $length bytes may still be written; marks the sink exceeded when not all. */
    public function admit(int $length): int
    {
        $room = max(0, $this->cap - $this->written);

        if ($length > $room) {
            $this->exceeded = true;
        }

        return min($length, $room);
    }
}
