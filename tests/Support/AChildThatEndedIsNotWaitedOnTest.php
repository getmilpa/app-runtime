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
 * A child that has ENDED is answered then — not when whoever it left behind lets go of its pipes.
 *
 * ── THE DEFECT, MEASURED (greenhouse: the probe that waited, t-0104, 2026-10-10) ────────────────
 *
 * In app-runtime 0.219.0 every trial inside the image's container was killed at 60 s (greenhouse evidence/1186).
 * What waited was read from `/proc`: the runner's confinement probe, asked from inside a trial. bubblewrap's
 * parent process died at once — `open /proc/3/ns/ns failed`, because inside a trial `/proc` is the outer one and a
 * pid of the trial names nothing there —, and the child it had already cloned stayed blocked for ever in a `read()`
 * only that parent would have answered, HOLDING THE PROBE'S OUTPUT PIPES. `ChildProcess::run` read those pipes until
 * their end and never looked at whether the process it ran had ended: PHP waited on a child of a dead process.
 * It is not the container: with a trial's pids moved to where a host's `/proc` has no process, a host waits the same.
 *
 * A deadline around the probe does not end it (tried: `timeout` ends with its child, the one that already died).
 *
 * @guards the answer comes when the process that was run ends; everything it wrote before ending is read; a process
 *         whose pipes simply end is answered as before
 *
 * @refuses waiting for the end of a pipe that a process left behind still holds; losing what the process said
 *          because someone else kept the pipe open
 */
final class AChildThatEndedIsNotWaitedOnTest extends TestCase
{
    /** How long whoever is left behind keeps the pipes: longer than any patience this test has. */
    private const LEFT_BEHIND_SECONDS = 8;

    public function testItIsAnsweredWhenItEndsThoughSomeoneItLeftBehindHoldsItsPipes(): void
    {
        $started = microtime(true);
        $run = ChildProcess::run(['sh', '-c', '(sleep ' . self::LEFT_BEHIND_SECONDS . ' &); echo said; echo complained >&2; exit 3']);
        $took = microtime(true) - $started;

        self::assertNotNull($run);
        self::assertLessThan(self::LEFT_BEHIND_SECONDS / 2, $took, 'answered when the process ended, not when the one it left behind let go');
        self::assertSame(3, $run['exit'], 'with the exit of the process that was run');
        self::assertSame("said\n", $run['stdout']);
        self::assertSame("complained\n", $run['stderr']);
    }

    public function testEverythingItWroteBeforeEndingIsReadOnBothPipes(): void
    {
        $bytes = 300_000;
        $write = 'head -c ' . $bytes . ' /dev/zero | tr "\\0" x';
        $run = ChildProcess::run(['sh', '-c', '(sleep ' . self::LEFT_BEHIND_SECONDS . ' &); ' . $write . '; ' . $write . ' >&2; exit 0']);

        self::assertNotNull($run);
        self::assertSame(0, $run['exit']);
        self::assertSame($bytes, \strlen($run['stdout']), 'more than a pipe holds, and none of it lost');
        self::assertSame($bytes, \strlen($run['stderr']), 'on the other pipe too');
        self::assertSame('', trim($run['stdout'] . $run['stderr'], 'x'));
    }

    /** …and when nobody is left behind: a process that ends with output still in the pipe loses none of it. */
    public function testAProcessThatEndsWithOutputStillInThePipeLosesNoneOfIt(): void
    {
        $bytes = 300_000;
        $write = 'head -c ' . $bytes . ' /dev/zero | tr "\\0" x';
        for ($i = 0; $i < 5; ++$i) {
            $run = ChildProcess::run(['sh', '-c', $write . '; ' . $write . ' >&2; exit 5']);

            self::assertNotNull($run);
            self::assertSame(5, $run['exit']);
            self::assertSame([$bytes, $bytes], [\strlen($run['stdout']), \strlen($run['stderr'])]);
        }
    }

    public function testAProcessWhosePipesSimplyEndIsAnsweredAsBefore(): void
    {
        $run = ChildProcess::run(['sh', '-c', 'echo out; echo err >&2; exit 2']);

        self::assertSame(['exit' => 2, 'stdout' => "out\n", 'stderr' => "err\n"], $run);
    }

    /** A process that closes its output and goes on is still waited for: its end is the answer, not its silence. */
    public function testAProcessThatClosesItsOutputAndGoesOnIsWaitedForUntilItEnds(): void
    {
        $started = microtime(true);
        $run = ChildProcess::run(['sh', '-c', 'echo early; exec >&- 2>&-; sleep 1; exit 4']);

        self::assertNotNull($run);
        self::assertGreaterThan(0.9, microtime(true) - $started);
        self::assertSame(4, $run['exit']);
        self::assertSame("early\n", $run['stdout']);
    }
}
