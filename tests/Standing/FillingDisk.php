<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Standing;

/**
 * A disk that fills: a stream that takes what there is room for and then no more — the way a full disk does, which
 * says so only in HOW MANY bytes of a write it took. Registered as `filling://`, so a ledger can be kept on it.
 */
final class FillingDisk
{
    /** @var array<string, string> what each file holds */
    public static array $files = [];

    /** How many bytes this disk still takes. */
    public static int $room = \PHP_INT_MAX;

    /** A file whose name holds this cannot be opened here at all. */
    public static ?string $closed = null;

    /** Whether a file can be given another name here. */
    public static bool $renames = true;

    /** @var resource|null */
    public $context;

    private string $path = '';

    private int $at = 0;

    public static function mount(): void
    {
        self::$files = [];
        self::$room = \PHP_INT_MAX;
        self::$closed = null;
        self::$renames = true;
        if (!\in_array('filling', stream_get_wrappers(), true)) {
            stream_wrapper_register('filling', self::class);
        }
    }

    /** @return array<string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        $blank = array_fill_keys(['dev', 'ino', 'nlink', 'uid', 'gid', 'rdev', 'atime', 'mtime', 'ctime', 'blksize', 'blocks'], 0);
        if (isset(self::$files[$path])) {
            return ['mode' => 0o100600, 'size' => \strlen(self::$files[$path])] + $blank;
        }

        // Every folder is there; a file is there once it was written.
        return str_contains(basename($path), '.') ? false : ['mode' => 0o040700, 'size' => 0] + $blank;
    }

    public function stream_open(string $path, string $mode): bool
    {
        if (self::$closed !== null && str_contains($path, self::$closed)) {
            return false;
        }
        $this->path = $path;
        if (!isset(self::$files[$path]) || str_starts_with($mode, 'w')) {
            self::$files[$path] = '';
        }
        $this->at = 0;

        return true;
    }

    public function stream_lock(int $operation): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        $read = substr(self::$files[$this->path], $this->at, $count);
        $this->at += \strlen($read);

        return $read;
    }

    public function stream_eof(): bool
    {
        return $this->at >= \strlen(self::$files[$this->path]);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        $this->at = $whence === \SEEK_END ? \strlen(self::$files[$this->path]) + $offset : $offset;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->at;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['size' => \strlen(self::$files[$this->path])];
    }

    public function stream_write(string $data): int
    {
        $taken = min(\strlen($data), self::$room);
        self::$room -= $taken;
        self::$files[$this->path] = substr(self::$files[$this->path], 0, $this->at) . substr($data, 0, $taken);
        $this->at += $taken;

        return $taken;
    }

    public function stream_truncate(int $size): bool
    {
        self::$files[$this->path] = substr(self::$files[$this->path], 0, $size);

        return true;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return true;
    }

    public function rename(string $from, string $to): bool
    {
        if (!self::$renames) {
            return false;
        }
        self::$files[$to] = self::$files[$from];
        unset(self::$files[$from]);

        return true;
    }

    public function unlink(string $path): bool
    {
        unset(self::$files[$path]);

        return true;
    }
}
