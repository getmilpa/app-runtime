<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Support\ChildProcess;
use PHPUnit\Framework\TestCase;

/**
 * A child of the house reads end-of-file on stdin and is heard on both channels — without a device (greenhouse evidence/1060).
 *
 * @guards stdin is closed at once, so a child that reads it ends; stdout and stderr are drained together, so a
 *         child that fills stderr first does not block; the exit is the child's; an argument list reaches the
 *         child without a shell; `lines()` answers the way exec() does, and 127 when nothing started
 *
 * @subject-in milpa/app-runtime
 */
final class AChildNeedsNoDeviceTest extends TestCase
{
    public function testStdinReadsEndOfFileAndBothChannelsAreHeard(): void
    {
        $run = ChildProcess::run([\PHP_BINARY, '-r', 'echo strlen(stream_get_contents(STDIN)); fwrite(STDERR, "err"); exit(3);']);

        self::assertSame(['exit' => 3, 'stdout' => '0', 'stderr' => 'err'], $run);
    }

    public function testAChildThatFillsStderrFirstIsNotBlocked(): void
    {
        $run = ChildProcess::run([\PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("e", 300000)); echo str_repeat("o", 300000);']);

        self::assertNotNull($run);
        self::assertSame(0, $run['exit']);
        self::assertSame(300000, \strlen($run['stderr']));
        self::assertSame(300000, \strlen($run['stdout']));
    }

    public function testAnArgumentIsNeverReadByAShell(): void
    {
        [$exit, $lines] = ChildProcess::lines([\PHP_BINARY, '-r', 'echo $argv[1], "\n", "two  \n";', '$(echo no) 2>/dev/null']);

        self::assertSame(0, $exit);
        self::assertSame(['$(echo no) 2>/dev/null', 'two'], $lines);
    }

    public function testASilentChildHasNoLinesAndAWorkingDirectoryIsItsOwn(): void
    {
        self::assertSame([0, []], ChildProcess::lines([\PHP_BINARY, '-r', '']));
        self::assertSame([0, [sys_get_temp_dir()]], ChildProcess::lines([\PHP_BINARY, '-r', 'echo getcwd();'], sys_get_temp_dir()));
    }

    public function testAProgramThatCannotBeStartedIsNotAnAnswer(): void
    {
        [$exit, $lines] = ChildProcess::lines(['/nonexistent/program-' . bin2hex(random_bytes(4))]);

        self::assertNotSame(0, $exit);
        self::assertSame([], $lines);
    }
}
