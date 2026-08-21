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

use Milpa\AppRuntime\Agent\TrialWorkspace;
use PHPUnit\Framework\TestCase;

/**
 * The workspace is a DISPOSABLE COPY of the app root, and the host computes what changed in it
 * (greenhouse decisions/0068, 0069): no `var/`, no `.env`, its own empty `var/`, and a diff that
 * the copy never gets to write itself.
 */
final class TrialWorkspaceTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::rmrf($root);
        }
    }

    public function testMaterializeCopiesTheRootWithoutVarOrDotEnvAndWithAnEmptyVar(): void
    {
        $root = $this->root();

        $ws = TrialWorkspace::materialize($root, 'w1', $this->runner());

        self::assertSame('w1', $ws->id);
        self::assertFileExists($ws->copy . '/src/A.php');
        self::assertFileExists($ws->copy . '/config/x.php');
        self::assertFileExists($ws->copy . '/vendor/autoload.php');
        self::assertFileDoesNotExist($ws->copy . '/.env', 'secrets do not travel into the trial');
        self::assertDirectoryExists($ws->copy . '/var');
        self::assertSame([], array_diff(scandir($ws->copy . '/var') ?: [], ['.', '..']), 'the copy starts with an EMPTY var/: no second session stream');
        self::assertFileExists($ws->runnerPath());
        self::assertSame($ws->copy . '/trial-run.php', $ws->runnerPath());
        self::assertStringStartsWith($root . '/var/trials/w1', $ws->copy);
    }

    public function testTheDiffIsComputedByTheHostAndSeesAddedModifiedAndDeleted(): void
    {
        $root = $this->root();
        $ws = TrialWorkspace::materialize($root, 'w2', $this->runner());
        $hostConfig = sha1_file($root . '/config/x.php');

        file_put_contents($ws->copy . '/config/x.php', "<?php return ['x' => 2];\n");
        file_put_contents($ws->copy . '/src/New.php', "<?php // new\n");
        unlink($ws->copy . '/src/A.php');

        $diff = $ws->diff();
        ksort($diff);

        self::assertSame(['config/x.php', 'src/A.php', 'src/New.php'], array_keys($diff));
        self::assertSame('modified', $diff['config/x.php']['status']);
        self::assertSame('added', $diff['src/New.php']['status']);
        self::assertSame('deleted', $diff['src/A.php']['status']);
        self::assertSame(hash('sha256', "<?php return ['x' => 2];\n"), $diff['config/x.php']['sha256']);
        self::assertNull($diff['src/A.php']['sha256']);
        self::assertSame($hostConfig, sha1_file($root . '/config/x.php'), 'computing the diff changes nothing on the host');
        self::assertFileExists($root . '/src/A.php');
    }

    public function testTheDiffIgnoresTheCopysVarAndTheRunnerItself(): void
    {
        $root = $this->root();
        $ws = TrialWorkspace::materialize($root, 'w3', $this->runner());

        file_put_contents($ws->copy . '/var/agent-sessions.jsonl', "{}\n");
        file_put_contents($ws->runnerPath(), "<?php // touched\n");

        self::assertSame([], $ws->diff());
    }

    public function testStaleNamesThePathsWhoseHostContentMovedSinceTheCopy(): void
    {
        $root = $this->root();
        $ws = TrialWorkspace::materialize($root, 'w4', $this->runner());

        file_put_contents($ws->copy . '/config/x.php', "<?php return ['x' => 2];\n");
        file_put_contents($ws->copy . '/src/A.php', "<?php // a2\n");
        file_put_contents($root . '/config/x.php', "<?php return ['x' => 3];\n");

        self::assertSame(['config/x.php'], $ws->stale(), 'the target moved under this path; the other one is still what the copy saw');
    }

    public function testOpenListAndDiscard(): void
    {
        $root = $this->root();
        self::assertSame([], TrialWorkspace::ids($root));
        self::assertNull(TrialWorkspace::open($root, 'nope'));

        $ws = TrialWorkspace::materialize($root, 'w5', $this->runner());
        TrialWorkspace::materialize($root, 'w6', $this->runner());

        self::assertSame(['w5', 'w6'], TrialWorkspace::ids($root));
        $again = TrialWorkspace::open($root, 'w5');
        self::assertNotNull($again);
        self::assertSame($ws->copy, $again->copy);

        $ws->discard();

        self::assertDirectoryDoesNotExist($root . '/var/trials/w5');
        self::assertSame(['w6'], TrialWorkspace::ids($root));
    }

    public function testAnIdThatCouldEscapeTheTrialsDirectoryIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TrialWorkspace::materialize($this->root(), '../escape', $this->runner());
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/milpa-trial-' . bin2hex(random_bytes(4));
        mkdir($root . '/src', 0o777, true);
        mkdir($root . '/config');
        mkdir($root . '/var');
        mkdir($root . '/vendor');
        file_put_contents($root . '/src/A.php', "<?php // a\n");
        file_put_contents($root . '/config/x.php', "<?php return ['x' => 1];\n");
        file_put_contents($root . '/.env', "SECRET=1\n");
        file_put_contents($root . '/var/agent-sessions.jsonl', "{}\n");
        file_put_contents($root . '/vendor/autoload.php', "<?php\n");
        $this->roots[] = $root;

        return $root;
    }

    private function runner(): string
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
