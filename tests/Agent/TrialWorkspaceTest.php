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
use Milpa\Command\Effect\Subject;
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
        self::assertDirectoryDoesNotExist($ws->copy . '/vendor', 'vendor is bound READ-ONLY into the trial at run time, never copied (decisions/0070)');
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

    public function testTheDiffIgnoresVendorEntirely(): void
    {
        // vendor is bound read-only into the trial and NEVER copied, so it can only differ as a
        // measurement artifact; a scoped diff must not report it — and the ro-bind makes a real
        // in-trial vendor write impossible (EPERM), the guard of decisions/0070.
        $root = $this->root();   // root() already creates vendor/autoload.php
        $ws = TrialWorkspace::materialize($root, 'wv', $this->runner());

        // simulate a stray vendor file appearing in the copy AND a host vendor change
        mkdir($ws->copy . '/vendor', 0o777, true);
        file_put_contents($ws->copy . '/vendor/autoload.php', "<?php // copy differs\n");
        file_put_contents($ws->copy . '/vendor/New.php', "<?php // stray\n");
        file_put_contents($root . '/vendor/Other.php', "<?php // host only\n");

        self::assertSame([], $ws->diff(), 'vendor is out of the diff entirely — never added, modified or deleted');
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

    public function testCollapseRemovesTheCopyButKeepsThePreImage(): void
    {
        // A promoted trial is spent — its consequence already crossed the door (0068) — so the 656 KB
        // copy goes, but the pre-image (the manual-undo material, 0069) stays (decisions/0071).
        $root = $this->root();
        $ws = TrialWorkspace::materialize($root, 'c1', $this->runner());
        mkdir($ws->baseDirectory() . '/pre/config', 0o777, true);
        file_put_contents($ws->baseDirectory() . '/pre/config/x.php', "<?php // the undo\n");

        $ws->collapse();

        self::assertDirectoryDoesNotExist($ws->copy, 'the copy — the disk cost — is gone');
        self::assertFileExists($ws->baseDirectory() . '/pre/config/x.php', 'the pre-image survives for manual undo');
        self::assertSame([], TrialWorkspace::ids($root), 'a collapsed trial is decided: it no longer lists as open');
        self::assertNull(TrialWorkspace::open($root, 'c1'), 'and it cannot be reopened or re-promoted');
    }

    public function testCapUndecidedDiscardsTheOldestCopiesBeyondTheKeep(): void
    {
        // The count cap bounds var/trials/ — app-life AND within-session (decisions/0071): keep the
        // newest N undecided copies, full-discard the older ones (abandoned/rejected, no pre-image).
        $root = $this->root();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ws = TrialWorkspace::materialize($root, 'k' . $i, $this->runner());
            touch($ws->baseDirectory(), 1_700_000_000 + $i * 10); // oldest first, deterministic order
            $ids[] = 'k' . $i;
        }
        // a promoted (collapsed) trial does not count toward the cap — its copy is already gone
        TrialWorkspace::open($root, 'k2')->collapse();

        TrialWorkspace::capUndecided($root, 2);

        $remaining = TrialWorkspace::ids($root);
        sort($remaining);
        self::assertSame(['k3', 'k4'], $remaining, 'the two newest undecided survive; the oldest undecided are discarded');
        self::assertDirectoryDoesNotExist($root . '/var/trials/k0', 'the oldest undecided trial is gone entirely');
    }

    public function testCapUndecidedIsANoOpWhenUnderTheKeep(): void
    {
        $root = $this->root();
        TrialWorkspace::materialize($root, 'u0', $this->runner());
        TrialWorkspace::materialize($root, 'u1', $this->runner());

        TrialWorkspace::capUndecided($root, 5);

        self::assertSame(['u0', 'u1'], TrialWorkspace::ids($root));
    }

    public function testAnIdThatCouldEscapeTheTrialsDirectoryIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TrialWorkspace::materialize($this->root(), '../escape', $this->runner());
    }

    /**
     * THE PRODUCER OF A PROMOTION'S SUBJECT (greenhouse decisions/0080): the workspace owns the diff, so
     * it may attest what the change is made of — by ALLOWLIST, and only downwards. A diff made only
     * of non-code files in data/config locations is Configuration; anything it cannot vouch for — a
     * .php anywhere, a code directory, an unknown extension, an empty diff — attests nothing, and the
     * declared ceiling (Executable) holds.
     *
     * @dataProvider diffs
     *
     * @param array<string, string> $edits path => content written into the COPY
     */
    public function testItAttestsConfigurationOnlyForAnAllowlistedDiff(array $edits, ?Subject $expected, string $why): void
    {
        $root = $this->root();
        mkdir($root . '/storage');
        file_put_contents($root . '/storage/plugins.json', "{\"probe\": 1}\n");
        $ws = TrialWorkspace::materialize($root, 'w-subject', $this->runner());
        foreach ($edits as $rel => $content) {
            @mkdir(\dirname($ws->copy . '/' . $rel), 0o777, true);
            file_put_contents($ws->copy . '/' . $rel, $content);
        }

        self::assertSame($expected, $ws->attestedSubject(), $why);
    }

    /** @return iterable<string, array{array<string, string>, ?Subject, string}> */
    public static function diffs(): iterable
    {
        yield 'configuration-only' => [['storage/plugins.json' => "{\"probe\": 2}\n"], Subject::Configuration, 'a json under storage/ is configuration'];
        yield 'foundation' => [['.milpa/foundation.json' => "{}\n"], Subject::Configuration, 'founding is configuration (Subject docblock)'];
        yield 'a note under .milpa' => [['.milpa/notes/x.md' => "# x\n"], Subject::Configuration, 'markdown under .milpa/ is not code'];
        yield 'code' => [['src/Plugins/Probe/ProbePlugin.php' => "<?php\n"], null, 'a .php under src/ keeps the ceiling'];
        yield 'mixed' => [['storage/plugins.json' => "{}\n", 'src/Plugins/Probe/ProbePlugin.php' => "<?php\n"], null, 'one code path and the whole diff keeps the ceiling'];
        yield 'code in a data dir' => [['storage/evil.php' => "<?php\n"], null, 'the extension beats the directory'];
        yield 'config dir is code' => [['config/x.php' => "<?php return [];\n"], null, 'config/*.php lists what boots — executable'];
        yield 'composer' => [['composer.json' => "{}\n"], null, 'composer.json changes what installs — not allowlisted'];
        yield 'unknown extension' => [['storage/blob.bin' => "\x00"], null, 'an extension the allowlist does not know keeps the ceiling'];
        yield 'empty diff' => [[], null, 'nothing changed: nothing to attest'];
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
