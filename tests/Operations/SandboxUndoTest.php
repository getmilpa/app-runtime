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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * `sandbox:undo` reads the pre-image a promotion keeps and reverses it — the operation that makes the
 * ManualRecovery reversibility a promotion declares (0069) TRUE, not merely stated. Modified paths go
 * back to what the house held, deleted paths return, added paths are removed; and it refuses when the
 * target moved since the promotion (Rule 1, 0065) — undoing over a moved target is a new proposal.
 */
final class SandboxUndoTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $r) {
            self::rmrf($r);
        }
    }

    public function testUndoReversesAModifyAnAddAndADelete(): void
    {
        $root = $this->root();
        $ws = TrialWorkspace::materialize($root, 'w1', $this->stub());
        file_put_contents($ws->copy . '/config/x.php', "<?php return ['x' => 2];\n");   // modify
        file_put_contents($ws->copy . '/src/New.php', "<?php // new\n");                 // add
        unlink($ws->copy . '/src/A.php');                                                // delete

        $promote = $this->fire($root, 'sandbox:promote', ['workspace' => 'w1']);
        self::assertTrue($promote['ok'], json_encode($promote) ?: '');
        // the promotion landed on the host
        self::assertSame("<?php return ['x' => 2];\n", file_get_contents($root . '/config/x.php'));
        self::assertFileExists($root . '/src/New.php');
        self::assertFileDoesNotExist($root . '/src/A.php');

        $undo = $this->fire($root, 'sandbox:undo', ['workspace' => 'w1', 'session' => 's-1']);

        self::assertTrue($undo['ok'], json_encode($undo) ?: '');
        self::assertSame(['config/x.php', 'src/A.php', 'src/New.php'], $undo['undone']);
        // the house is back to before the promotion
        self::assertSame("<?php return ['x' => 1];\n", file_get_contents($root . '/config/x.php'), 'a modify is restored');
        self::assertSame("<?php // a\n", file_get_contents($root . '/src/A.php'), 'a delete returns');
        self::assertFileDoesNotExist($root . '/src/New.php', 'an add is removed');
        self::assertDirectoryDoesNotExist($root . '/var/trials/w1', 'the reversed trial is gone entirely');
    }

    public function testUndoRefusesWhenTheTargetMovedSinceThePromotion(): void
    {
        $root = $this->root();
        $ws = TrialWorkspace::materialize($root, 'w1', $this->stub());
        file_put_contents($ws->copy . '/config/x.php', "<?php return ['x' => 2];\n");
        $this->fire($root, 'sandbox:promote', ['workspace' => 'w1']);
        // someone edited the promoted file AFTER the promotion
        file_put_contents($root . '/config/x.php', "<?php return ['x' => 9];\n");

        $undo = $this->fire($root, 'sandbox:undo', ['workspace' => 'w1']);

        self::assertFalse($undo['ok']);
        self::assertSame(['config/x.php'], $undo['stale']);
        self::assertSame("<?php return ['x' => 9];\n", file_get_contents($root . '/config/x.php'), 'a moved target is left alone');
    }

    public function testUndoOfSomethingNeverPromotedIsAnError(): void
    {
        $root = $this->root();
        self::assertFalse($this->fire($root, 'sandbox:undo', ['workspace' => 'nope'])['ok']);
        self::assertFalse($this->fire($root, 'sandbox:undo', ['workspace' => ''])['ok'], 'undo with no workspace named is an error');
        // a trial that ran but was never promoted has no pre-image to undo
        TrialWorkspace::materialize($root, 'w1', $this->stub());
        self::assertFalse($this->fire($root, 'sandbox:undo', ['workspace' => 'w1'])['ok']);
    }

    public function testPromoteIsReversibleForRealThroughUndo(): void
    {
        $root = $this->root();
        $es = new InMemoryEventStore();
        $store = new SessionStore($es);
        $store->start('s-1', 'goal', AutonomyMode::Ask);
        $ws = TrialWorkspace::materialize($root, 'w1', $this->stub());
        file_put_contents($ws->copy . '/config/x.php', "<?php return ['x' => 2];\n");

        $this->fire($root, 'sandbox:promote', ['workspace' => 'w1', 'session' => 's-1'], $store);
        $undo = $this->fire($root, 'sandbox:undo', ['workspace' => 'w1', 'session' => 's-1'], $store);

        self::assertTrue($undo['ok']);
        // the ManualRecovery a promotion declares is now real: one operation returns the house.
        self::assertSame("<?php return ['x' => 1];\n", file_get_contents($root . '/config/x.php'));
    }

    public function testUndoDeclaresAConservativeMutatingCeiling(): void
    {
        $op = $this->op($this->root(), 'sandbox:undo');
        self::assertTrue($op->mutating, 'undo writes host files, so it is a mutation that pauses');
        self::assertSame(Mutation::Persistent, $op->effectCeiling()->mutation);
        self::assertSame(Externality::None, $op->effectCeiling()->externality);
        self::assertSame(Reversibility::ManualRecovery, $op->effectCeiling()->reversibility);
        self::assertSame(Authority::WriteAsUser, $op->effectCeiling()->authority);
        self::assertSame(Subject::Executable, $op->effectCeiling()->subject);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function fire(string $root, string $name, array $input, ?SessionStore $store = null): array
    {
        return ($this->op($root, $name, $store)->handler)($input);
    }

    private function op(string $root, string $name, ?SessionStore $store = null): Operation
    {
        foreach ((new TrialOperations(new DIContainer(), $store, $root))->operations() as $op) {
            if ($op->name === $name) {
                return $op;
            }
        }
        self::fail("no operation {$name}");
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/milpa-undo-' . bin2hex(random_bytes(4));
        mkdir($root . '/src', 0o777, true);
        mkdir($root . '/config');
        file_put_contents($root . '/src/A.php', "<?php // a\n");
        file_put_contents($root . '/config/x.php', "<?php return ['x' => 1];\n");
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
