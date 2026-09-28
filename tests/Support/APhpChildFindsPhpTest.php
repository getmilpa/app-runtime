<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Agent\HouseRouteObserver;
use Milpa\AppRuntime\Support\PhpBinary;
use PHPUnit\Framework\TestCase;

/**
 * A child process of the house finds PHP even when `PHP_BINARY` is empty (greenhouse decisions/0505 §5).
 *
 * Under FrankenPHP `PHP_BINARY` is `""`: the route observer of decisions/0494 and the trial runner
 * launched an empty binary, so a promotion served by FrankenPHP observed nothing.
 */
final class APhpChildFindsPhpTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/php-binary-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bindir', 0o777, true);
        mkdir($this->dir . '/path', 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** An executable PHP_BINARY is the answer — the CLI and `php -S` keep what they had. */
    public function testAnExecutablePhpBinaryWins(): void
    {
        self::assertSame(\PHP_BINARY, PhpBinary::path(\PHP_BINARY, $this->dir . '/bindir', $this->dir . '/path'));
    }

    /** Empty — FrankenPHP — falls to the php beside the build, then to PATH, then to nothing. */
    public function testAnEmptyPhpBinaryFallsToTheBuildThenThePath(): void
    {
        self::assertSame('', PhpBinary::path('', $this->dir . '/bindir', $this->dir . '/path'), 'none anywhere: empty, and callers say they could not ask');

        $this->executable($this->dir . '/path/php');
        self::assertSame($this->dir . '/path/php', PhpBinary::path('', $this->dir . '/bindir', '/nonexistent' . \PATH_SEPARATOR . $this->dir . '/path'));

        $this->executable($this->dir . '/bindir/php');
        self::assertSame($this->dir . '/bindir/php', PhpBinary::path('', $this->dir . '/bindir', $this->dir . '/path'), 'the php of this build before any other');
    }

    /** A file named php that cannot run is not an answer. */
    public function testANonExecutableIsNotPhp(): void
    {
        file_put_contents($this->dir . '/path/php', '');
        chmod($this->dir . '/path/php', 0o644);

        self::assertSame('', PhpBinary::path('', $this->dir . '/bindir', $this->dir . '/path'));
    }

    /** The observer, built with no binary, runs a PHP it found — not `PHP_BINARY` taken on faith. */
    public function testTheObserverResolvesItsPhp(): void
    {
        $php = (new \ReflectionProperty(HouseRouteObserver::class, 'php'))->getValue(new HouseRouteObserver());

        self::assertSame(PhpBinary::path(), $php);
        self::assertNotSame('', $php);
    }

    private function executable(string $file): void
    {
        file_put_contents($file, "#!/bin/sh\n");
        chmod($file, 0o755);
    }
}
