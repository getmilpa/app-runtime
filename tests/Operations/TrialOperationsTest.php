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
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
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
 * Promotion is the ONLY door from a trial into the house (greenhouse decisions/0068, 0069 §12): it
 * pauses under the profile of what ENTERS, refuses when the target moved, keeps a pre-image, and
 * leaves `session.trial_promoted`.
 */
final class TrialOperationsTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::rmrf($root);
        }
    }

    public function testPromoteDeclaresTheConservativeCeilingOfWhatEnters(): void
    {
        $op = $this->op($this->root(), new SessionStore(new InMemoryEventStore()), 'sandbox:promote');

        self::assertTrue($op->mutating);
        self::assertFalse($op->requiresConfirmation);
        $techo = $op->effectCeiling();
        self::assertSame(Mutation::Persistent, $techo->mutation);
        self::assertSame(Externality::None, $techo->externality);
        self::assertSame(Reversibility::ManualRecovery, $techo->reversibility);
        self::assertSame(Authority::WriteAsUser, $techo->authority);
        self::assertSame(Subject::Executable, $techo->subject);
    }

    public function testPromotePausesAtTheGateEvenWithTheRouterOn(): void
    {
        $root = $this->root();
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s-1', 'goal', AutonomyMode::Ask);
        $sesion = $almacen->load('s-1');
        self::assertNotNull($sesion);
        $bwrap = $root . '/fake-bwrap';
        file_put_contents($bwrap, "#!/bin/sh\nexit 0\n");
        chmod($bwrap, 0o755);
        $router = new TrialRouter($root, new TrialRunner(bwrap: $bwrap), $this->stub());
        $gate = new SessionToolGate($almacen, $sesion, (new TrialOperations(new DIContainer(), $almacen, $root))->operations(), trialRouter: $router);

        self::assertNotNull($gate->refuse('sandbox_promote', ['workspace' => 'w1']), 'A3: promoting composes from ITS ceiling, not the trial\'s');
        self::assertSame([], TrialWorkspace::ids($root), 'and it never goes to trial itself');
    }

    public function testPromoteAppliesTheDiffKeepsAPreImageAndRecordsIt(): void
    {
        $root = $this->root();
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s-1', 'goal', AutonomyMode::Ask);
        $ws = TrialWorkspace::materialize($root, 'w1', $this->stub());
        file_put_contents($ws->copy . '/config/x.php', "<?php return ['x' => 2];\n");
        file_put_contents($ws->copy . '/src/New.php', "<?php // new\n");
        unlink($ws->copy . '/src/A.php');

        $r = $this->invoke($root, $almacen, 'sandbox:promote', ['workspace' => 'w1', 'session' => 's-1']);

        self::assertTrue($r['ok'] ?? false, json_encode($r) ?: '');
        self::assertSame(['config/x.php', 'src/A.php', 'src/New.php'], $r['promoted']);
        self::assertSame("<?php return ['x' => 2];\n", file_get_contents($root . '/config/x.php'));
        self::assertFileExists($root . '/src/New.php');
        self::assertFileDoesNotExist($root . '/src/A.php');
        self::assertSame("<?php return ['x' => 1];\n", file_get_contents($root . '/var/trials/w1/pre/config/x.php'), 'the pre-image is what the house had');
        self::assertSame("<?php // a\n", file_get_contents($root . '/var/trials/w1/pre/src/A.php'));
        // discard-on-promote (decisions/0071): the spent copy is gone, the pre-image stays.
        self::assertDirectoryDoesNotExist($root . '/var/trials/w1/copy', 'a promoted trial collapses its copy — the disk cost is freed');
        self::assertSame([], TrialWorkspace::ids($root), 'and it no longer lists as an open trial');
        self::assertFileDoesNotExist($root . '/.env.promoted');

        $hechos = array_values(array_filter($eventos->replay('agent-session:s-1'), static fn ($e): bool => $e->type === 'session.trial_promoted'));
        self::assertCount(1, $hechos);
        self::assertSame('w1', $hechos[0]->payload['workspace']);
        self::assertSame(['config/x.php', 'src/A.php', 'src/New.php'], $hechos[0]->payload['paths']);
        self::assertNotEmpty($hechos[0]->payload['diff_digest']);
    }

    public function testPromoteRefusesWhenTheTargetMovedUnderTheTrial(): void
    {
        $root = $this->root();
        $almacen = new SessionStore(new InMemoryEventStore());
        $ws = TrialWorkspace::materialize($root, 'w1', $this->stub());
        file_put_contents($ws->copy . '/config/x.php', "<?php return ['x' => 2];\n");
        file_put_contents($root . '/config/x.php', "<?php return ['x' => 3];\n");

        $r = $this->invoke($root, $almacen, 'sandbox:promote', ['workspace' => 'w1']);

        self::assertFalse($r['ok']);
        self::assertSame(['config/x.php'], $r['stale']);
        self::assertSame("<?php return ['x' => 3];\n", file_get_contents($root . '/config/x.php'), 'a moved target is a NEW proposal, not a merge');
    }

    public function testPromoteOfAnUnknownOrEmptyTrialIsAnError(): void
    {
        $root = $this->root();
        $almacen = new SessionStore(new InMemoryEventStore());

        self::assertFalse($this->invoke($root, $almacen, 'sandbox:promote', ['workspace' => 'nope'])['ok']);
        self::assertFalse($this->invoke($root, $almacen, 'sandbox:promote', [])['ok']);

        TrialWorkspace::materialize($root, 'w1', $this->stub());
        $r = $this->invoke($root, $almacen, 'sandbox:promote', ['workspace' => 'w1']);
        self::assertFalse($r['ok'], 'nothing changed in the copy: nothing to promote');
    }

    public function testListShowsEachTrialWithItsChanges(): void
    {
        $root = $this->root();
        $almacen = new SessionStore(new InMemoryEventStore());
        $ws = TrialWorkspace::materialize($root, 'w1', $this->stub());
        file_put_contents($ws->copy . '/src/New.php', "<?php // new\n");
        TrialWorkspace::materialize($root, 'w2', $this->stub());

        $r = $this->invoke($root, $almacen, 'sandbox:list', []);

        self::assertTrue($r['ok']);
        self::assertSame(['w1', 'w2'], array_column($r['trials'], 'workspace'));
        self::assertSame(['src/New.php'], array_keys($r['trials'][0]['changes']));
        self::assertSame([], $r['trials'][1]['changes']);
        self::assertFalse($this->op($root, $almacen, 'sandbox:list')->mutating);
    }

    public function testDiscardRemovesTheTrialAndRecordsIt(): void
    {
        $root = $this->root();
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s-1', 'goal', AutonomyMode::Ask);
        TrialWorkspace::materialize($root, 'w1', $this->stub());

        $r = $this->invoke($root, $almacen, 'sandbox:discard', ['workspace' => 'w1', 'session' => 's-1']);

        self::assertTrue($r['ok']);
        self::assertSame([], TrialWorkspace::ids($root));
        $hechos = array_values(array_filter($eventos->replay('agent-session:s-1'), static fn ($e): bool => $e->type === 'session.trial_discarded'));
        self::assertCount(1, $hechos);
        self::assertSame('w1', $hechos[0]->payload['workspace']);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function invoke(string $root, SessionStore $almacen, string $name, array $input): array
    {
        return ($this->op($root, $almacen, $name)->handler)($input);
    }

    private function op(string $root, SessionStore $almacen, string $name): Operation
    {
        foreach ((new TrialOperations(new DIContainer(), $almacen, $root))->operations() as $op) {
            if ($op->name === $name) {
                return $op;
            }
        }
        self::fail("no operation {$name}");
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/milpa-trial-' . bin2hex(random_bytes(4));
        mkdir($root . '/src', 0o777, true);
        mkdir($root . '/config');
        file_put_contents($root . '/src/A.php', "<?php // a\n");
        file_put_contents($root . '/config/x.php', "<?php return ['x' => 1];\n");
        file_put_contents($root . '/.env', "SECRET=1\n");
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
