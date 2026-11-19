<?php

namespace SocialPoster\Tests\Unit\Media\Support;

/**
 * A stream wrapper ("sp-spy://") that serves a 3-byte file for every path and
 * records each access. A media source on a foreign scheme (ftp://, phar://, a
 * registered s3:// wrapper...) must be refused before PHP touches it, so a test
 * asserts that $calls stays empty.
 */
final class SpyStreamWrapper
{
    public const PROTOCOL = 'sp-spy';

    /** @var list<string> */
    public static array $calls = [];

    /** @var resource|null */
    public $context;

    private int $position = 0;

    public static function register(): void
    {
        self::unregister();
        stream_wrapper_register(self::PROTOCOL, self::class);
        self::$calls = [];
    }

    public static function unregister(): void
    {
        if (in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::PROTOCOL);
        }
    }

    /** @return array<string, int> */
    public function url_stat(string $path, int $flags): array
    {
        self::$calls[] = 'url_stat '.$path;

        return ['mode' => 0100644, 'size' => 3];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$calls[] = 'open '.$path;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr('SPY', $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= 3;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['mode' => 0100644, 'size' => 3];
    }

    public function stream_close(): void {}
}
