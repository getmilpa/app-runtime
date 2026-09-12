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

    public function testPluginWriteSetConfinesTheVerifierAndItsChildAndDoesNotExportCache(): void
    {
        $runner = $this->realRunner();
        $root = $this->root();
        mkdir($root . '/src/Plugins/Owned', 0o700, true);
        mkdir($root . '/src/Plugins/Other', 0o700, true);
        $stub = $root . '/plugin-runner.php';
        file_put_contents($stub, <<<'PHP'
<?php
chdir(__DIR__);
$own = file_put_contents('src/Plugins/Owned/ok.txt', 'owned');
$other = @file_put_contents('src/Plugins/Other/no.txt', 'other');
$child = 'exit(@file_put_contents("src/Plugins/Other/child.txt", "other") === false ? 0 : 1);';
exec(PHP_BINARY . ' -r ' . escapeshellarg($child), $lines, $childExit);
$cache = file_put_contents('.phpunit.cache/result', 'cache');
echo json_encode(['own' => $own, 'other' => $other, 'child' => $childExit, 'cache' => $cache]);
PHP);
        $workspace = TrialWorkspace::materialize($root, 'plugin', $stub);
        $run = $runner->run($workspace, 'test', [], ['src/Plugins/Owned', 'tests/Plugins/Owned']);
        self::assertTrue($run->ok(), $run->stdout . $run->stderr);
        self::assertSame(['own' => 5, 'other' => false, 'child' => 0, 'cache' => 5], $run->output);
        self::assertSame(['src/Plugins/Owned/ok.txt'], array_keys($run->report));
        self::assertFileDoesNotExist($root . '/src/Plugins/Owned/ok.txt');
        self::assertFileDoesNotExist($workspace->copy . '/src/Plugins/Other/no.txt');
        self::assertFileDoesNotExist($workspace->copy . '/src/Plugins/Other/child.txt');
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

    public function testVerificationAndItsChildProcessCanUsePrivateTemporaryFiles(): void
    {
        $runner = $this->realRunner();
        $root = $this->root();
        $stub = $root . '/temporary-runner.php';
        file_put_contents($stub, <<<'PHP'
<?php
$path = @tempnam(sys_get_temp_dir(), 'verification-');
$child = 'echo json_encode(["path" => @tempnam(sys_get_temp_dir(), "child-")]);';
exec(PHP_BINARY . ' -r ' . escapeshellarg($child), $lines, $exit);
echo json_encode(['ok' => $path !== false && $exit === 0, 'path' => $path, 'child' => json_decode(implode('', $lines), true)]);
PHP);
        $ws = TrialWorkspace::materialize($root, 'temporary', $stub);
        $run = $runner->run($ws, 'verify', []);

        self::assertTrue($run->ok(), $run->stdout . $run->stderr);
        foreach ([$run->output['path'], $run->output['child']['path']] as $path) {
            self::assertIsString($path);
            self::assertStringStartsWith($ws->copy . '/', $path);
            self::assertFileExists($path);
        }
        self::assertSame([], $run->report, 'temporary verification files must not become promoted artifacts');
    }

    public function testTheShippedRunnerResolvesInstanceHandlersAndPreservesClosures(): void
    {
        $runner = $this->realRunner();
        $root = $this->root();
        mkdir($root . '/vendor');
        mkdir($root . '/config');
        file_put_contents($root . '/vendor/autoload.php', '<?php require ' . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        file_put_contents($root . '/config/boot.php', '<?php return ["container" => new \\Milpa\\Container\\DIContainer(), "plugins" => []];');
        file_put_contents($root . '/config/app.php', '<?php return [];');
        file_put_contents($root . '/config/operations.php', '<?php return [\\Milpa\\AppRuntime\\Tests\\Fixtures\\TrialOperations::class];');
        $ws = TrialWorkspace::materialize($root, 'shipped', \dirname(__DIR__, 2) . '/resources/trial-run.php');

        foreach (['trial:instance', 'trial.instance', 'trial_instance'] as $spelling) {
            $run = $runner->run($ws, $spelling, ['path' => 'built.txt']);
            self::assertSame(0, $run->exit, $run->stdout . $run->stderr);
            self::assertSame(['written' => true], $run->output);
            self::assertFileExists($ws->copy . '/built.txt');
            self::assertFileDoesNotExist($root . '/built.txt');
        }
        $run = $runner->run($ws, 'trial:closure', ['value' => 'control']);
        self::assertSame(0, $run->exit, $run->stdout . $run->stderr);
        self::assertSame(['value' => 'control'], $run->output);

        $missing = $runner->run($ws, 'missing', []);
        self::assertSame(1, $missing->exit);
        self::assertSame('no operation «missing» in this app', $missing->output['error'] ?? null);
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

    public function testTheRunBindsHostVendorReadOnlyIntoTheCopy(): void
    {
        $root = $this->root();
        mkdir($root . '/vendor', 0o777, true);
        file_put_contents($root . '/vendor/autoload.php', "<?php\n");
        $log = $root . '/bwrap-argv.txt';
        // a spy bwrap: record its argv, then exec the command after `--` (no real sandbox needed)
        $spy = $root . '/spy-bwrap';
        file_put_contents($spy, "#!/bin/sh\nprintf '%s\\n' \"$*\" >> " . escapeshellarg($log) . "\nwhile [ \"$1\" != \"--\" ] && [ $# -gt 0 ]; do shift; done\nshift\nexec \"$@\"\n");
        chmod($spy, 0o755);

        $runner = new TrialRunner(bwrap: $spy);
        self::assertTrue($runner->available(), 'the spy bwrap execs, so the probe passes');
        $ws = TrialWorkspace::materialize($root, 'rv', $this->stub());

        $runner->run($ws, 'anything', []);

        $argv = (string) file_get_contents($log);
        self::assertStringContainsString('--ro-bind ' . $root . '/vendor ' . $ws->copy . '/vendor', $argv, 'the host vendor is bound READ-ONLY at the copy path — the trial boots from it, cannot write it');
        self::assertStringContainsString('--unshare-net', $argv);
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
