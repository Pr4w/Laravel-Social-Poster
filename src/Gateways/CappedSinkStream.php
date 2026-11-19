<?php

namespace SocialPoster\Gateways;

/**
 * The stream wrapper behind CappedSink: writes go to the sink's file until the
 * cap, then report a short count.
 *
 * @internal
 */
final class CappedSinkStream
{
    /** @var resource|null set by PHP */
    public $context;

    private ?CappedSink $sink = null;

    /** @var resource|null */
    private $file = null;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $sink = is_resource($this->context)
            ? (stream_context_get_options($this->context)[CappedSink::PROTOCOL]['sink'] ?? null)
            : null;

        if (! $sink instanceof CappedSink) {
            return false;
        }

        $file = @fopen($sink->path, 'w+b');

        if ($file === false) {
            return false;
        }

        $this->sink = $sink;
        $this->file = $file;

        return true;
    }

    public function stream_write(string $data): int
    {
        if ($this->sink === null || $this->file === null) {
            return 0;
        }

        $allowed = $this->sink->admit(strlen($data));

        if ($allowed === 0) {
            return 0;
        }

        $written = @fwrite($this->file, $allowed === strlen($data) ? $data : substr($data, 0, $allowed));

        if ($written === false) {
            return 0;
        }

        $this->sink->written += $written;

        return $written;
    }

    public function stream_read(int $count): string|false
    {
        return $this->file === null ? false : fread($this->file, max(1, $count));
    }

    public function stream_eof(): bool
    {
        return $this->file === null || feof($this->file);
    }

    public function stream_tell(): int
    {
        return $this->file === null ? 0 : (int) ftell($this->file);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return $this->file !== null && fseek($this->file, $offset, $whence) === 0;
    }

    public function stream_flush(): bool
    {
        return $this->file === null || fflush($this->file);
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return $this->file === null ? false : fstat($this->file);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        if ($this->file !== null) {
            fclose($this->file);
        }

        $this->file = null;
    }
}
