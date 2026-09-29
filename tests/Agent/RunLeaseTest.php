<?php

/**
 * This file is part of milpa/app-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\RunLease;
use PHPUnit\Framework\TestCase;

/**
 * Greenhouse decisions/0513 §3: whether a run is alive is asked of the process that runs it, never inferred.
 */
final class RunLeaseTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-run-lease-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/' . RunLease::DIRECTORY . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->root . '/' . RunLease::DIRECTORY);
        @rmdir($this->root . '/var');
        @rmdir($this->root);
    }

    public function testASessionNobodyEverRanIsNotRunning(): void
    {
        self::assertFalse(RunLease::held($this->root, 's1'));
    }

    public function testATakenLeaseIsHeldUntilReleased(): void
    {
        $lease = RunLease::take($this->root, 's1');

        self::assertNotNull($lease);
        self::assertTrue(RunLease::held($this->root, 's1'), 'the run is alive while it holds its lease');
        self::assertFalse(RunLease::held($this->root, 's2'), 'another session is not');

        $lease->release();
        $lease->release();

        self::assertFalse(RunLease::held($this->root, 's1'), 'a released lease reads as not running');
        self::assertFileExists(RunLease::path($this->root, 's1'), 'the file stays: only the lock means anything');
    }

    public function testAskingNeverTakesTheRunsPlace(): void
    {
        $lease = RunLease::take($this->root, 's1');
        self::assertNotNull($lease);

        self::assertTrue(RunLease::held($this->root, 's1'));
        self::assertTrue(RunLease::held($this->root, 's1'), 'a reader drops what it asked with');
        self::assertNull(RunLease::take($this->root, 's1'), 'a second run of the same session cannot take it');

        $lease->release();
        self::assertNotNull(RunLease::take($this->root, 's1'), 'and can once the first is over');
    }

    public function testALeaseDroppedWithoutReleaseIsReleased(): void
    {
        (static function (string $root): void {
            self::assertNotNull(RunLease::take($root, 's1'));
        })($this->root);

        self::assertFalse(RunLease::held($this->root, 's1'));
    }

    public function testTheSessionIdNeverBecomesAPathOfItsOwn(): void
    {
        $path = RunLease::path($this->root . '/', '../../etc/passwd');

        self::assertSame($this->root . '/var/agent-runs/' . sha1('../../etc/passwd') . '.lock', $path);
    }

    public function testAHouseWhoseLeaseDirectoryCannotBeMadeRunsWithoutOne(): void
    {
        file_put_contents($this->root . '/var', 'a file where the directory would go');
        try {
            self::assertNull(RunLease::take($this->root, 's1'));
            self::assertFalse(RunLease::held($this->root, 's1'));
        } finally {
            unlink($this->root . '/var');
        }
    }

    /**
     * THE ONE THAT MATTERS: a process that dies — even by SIGKILL, which runs no `finally` and no destructor —
     * stops holding its lease, so the panel can tell a dead run from a live one.
     */
    public function testAProcessKilledMidRunStopsHoldingItsLease(): void
    {
        if (!\function_exists('proc_open') || !\function_exists('posix_kill')) {
            self::markTestSkipped('needs proc_open and posix');
        }
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . '; $l = Milpa\AppRuntime\Agent\RunLease::take('
            . var_export($this->root, true) . ', "s1"); fwrite(STDOUT, $l === null ? "none\n" : "held\n"); fflush(STDOUT); sleep(30);';
        $process = proc_open([\PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        self::assertSame("held\n", fgets($pipes[1]));

        self::assertTrue(RunLease::held($this->root, 's1'), 'another process running the session is seen');

        $pid = proc_get_status($process)['pid'];
        posix_kill($pid, 9);
        proc_close($process);

        self::assertFalse(RunLease::held($this->root, 's1'), 'and its death is seen too');
    }
}
