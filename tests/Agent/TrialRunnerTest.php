<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use PHPUnit\Framework\TestCase;

/**
 * The runner is what makes the confinement TRUE rather than claimed: the trial process writes only
 * in its copy, reaches no network, and the host reads the result (greenhouse evidence/0271, 0272).
 * Tests that need a real sandbox skip where the kernel refuses unprivileged namespaces; the
 * fail-closed half never skips.
 */
final class TrialRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::rmrf($root);
        }
    }

    public function testWithoutBwrapTheRunnerIsNotAvailableAndSaysSo(): void
    {
        $runner = new TrialRunner(bwrap: '/nonexistent/bwrap');

        self::assertFalse($runner->available());
        self::assertFalse($runner->available(), 'the probe is memoised, and stays false');
    }

    public function testTheBoundsAreTheOnesTheContractNames(): void
    {
        self::assertSame(['fs' => 'ro-root+rw-copy', 'net' => 'unshared', 'pid' => 'unshared'], (new TrialRunner())->bounds());
        self::assertSame(TrialWorkspace::BOUNDS, (new TrialRunner())->bounds());
    }

    public function testARunWritesInTheCopyAndNotInTheHostAndReachesNoNetwork(): void
    {
        $runner = $this->realRunner();
        $root = $this->root();
        $ws = TrialWorkspace::materialize($root, 'r1', $this->stub());

        $run = $runner->run($ws, 'anything', ['host' => $root]);

        self::assertSame(0, $run->exit, $run->stderr);
        self::assertTrue($run->ok());
        self::assertIsArray($run->output);
        self::assertSame('anything', $run->output['operation']);
        self::assertFalse($run->output['net'], 'the network is unshared');
        self::assertFileExists($ws->copy . '/touched.txt', 'the copy is writable');
        self::assertFileDoesNotExist($root . '/host-touched.txt', 'the host is not');
        self::assertSame(TrialWorkspace::BOUNDS, $run->bounds);
        self::assertArrayHasKey('touched.txt', $run->report);
        self::assertSame('added', $run->report['touched.txt']['status']);
    }

    public function testANonZeroExitIsReportedNotHidden(): void
    {
        $runner = $this->realRunner();
        $ws = TrialWorkspace::materialize($this->root(), 'r2', $this->stub());

        $run = $runner->run($ws, 'fail', []);

        self::assertSame(1, $run->exit);
        self::assertFalse($run->ok());
        self::assertSame('asked to fail', $run->output['error'] ?? null);
    }

    public function testARunThatOverstaysItsTimeoutIsKilled(): void
    {
        $runner = $this->realRunner(timeoutSeconds: 1);
        $ws = TrialWorkspace::materialize($this->root(), 'r3', $this->stub());

        $run = $runner->run($ws, 'sleep', []);

        self::assertNotSame(0, $run->exit);
        self::assertFalse($run->ok());
        self::assertStringContainsString('timeout', $run->stderr);
    }

    private function realRunner(int $timeoutSeconds = 60): TrialRunner
    {
        $runner = new TrialRunner(timeoutSeconds: $timeoutSeconds);
        if (! $runner->available()) {
            self::markTestSkipped('this host offers no unprivileged user namespace for bwrap');
        }

        return $runner;
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/milpa-trial-' . bin2hex(random_bytes(4));
        mkdir($root . '/src', 0o777, true);
        file_put_contents($root . '/src/A.php', "<?php // a\n");
        $this->roots[] = $root;

        return $root;
    }

    private function stub(): string
    {
        return \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php';
    }

    private static function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($path);
    }
}
