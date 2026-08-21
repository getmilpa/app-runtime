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

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The pin's graduation criterion for the trial (greenhouse decisions/0069): the human PROMOTES from
 * the screen SEEING what enters. `sandbox:promote` pauses like any mutation; this makes its pause
 * carry the trial's diff INSIDE the structured `why`, so the surface paints it at the decision point —
 * the house guarantees the diff is on screen, not the model narrating it.
 */
final class GatePaintsPromotionDiffTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::rmrf($root);
        }
    }

    public function testAPromotionPauseCarriesTheTrialsDiffInsideItsWhy(): void
    {
        $root = $this->root();
        $ws = TrialWorkspace::materialize($root, 'w1', $this->stub());
        file_put_contents($ws->copy . '/config/x.php', "<?php return ['x' => 2];\n");
        file_put_contents($ws->copy . '/src/New.php', "<?php // new\n");

        [$gate, $store] = $this->gate($root);

        $motivo = $gate->refuse('sandbox_promote', ['workspace' => 'w1']);
        self::assertNotNull($motivo, 'promote is a mutation and pauses');

        $q = $store->load('s-1')?->question;
        self::assertNotNull($q);
        $hecho = json_decode((string) $q->why, true);
        self::assertIsArray($hecho);
        self::assertSame('sandbox:promote', $hecho['operation'], 'the grant still reads the operation');
        self::assertSame(['workspace' => 'w1'], $hecho['arguments'], 'the arguments are untouched — the grant reads these');
        self::assertArrayHasKey('cambios', $hecho, 'the diff rides in the why so the surface paints what enters');
        self::assertSame('modified', $hecho['cambios']['config/x.php'] ?? null);
        self::assertSame('added', $hecho['cambios']['src/New.php'] ?? null);
    }

    public function testPromotingAnUnknownTrialCarriesNoDiffButStillPauses(): void
    {
        [$gate, $store] = $this->gate($this->root());

        self::assertNotNull($gate->refuse('sandbox_promote', ['workspace' => 'nope']));
        $hecho = json_decode((string) $store->load('s-1')?->question?->why, true);
        self::assertIsArray($hecho);
        self::assertArrayNotHasKey('cambios', $hecho, 'no workspace, nothing to paint — but the pause still happens');
    }

    public function testAPlainMutationsPauseCarriesNoDiff(): void
    {
        [$gate, $store] = $this->gate($this->root(), extra: $this->plainMutation());

        self::assertNotNull($gate->refuse('mail_send', ['to' => 'x@y']));
        $hecho = json_decode((string) $store->load('s-1')?->question?->why, true);
        self::assertIsArray($hecho);
        self::assertArrayNotHasKey('cambios', $hecho, 'only a promotion paints a trial diff');
    }

    /** @return array{0: SessionToolGate, 1: SessionStore} */
    private function gate(string $root, ?Operation $extra = null): array
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s-1', 'goal', AutonomyMode::Ask);
        $session = $store->load('s-1');
        self::assertNotNull($session);

        $bwrap = $root . '/fake-bwrap';
        file_put_contents($bwrap, "#!/bin/sh\nexit 0\n");
        chmod($bwrap, 0o755);
        $router = new TrialRouter($root, new TrialRunner(bwrap: $bwrap), $this->stub());

        $ops = (new TrialOperations(new DIContainer(), $store, $root))->operations();
        if ($extra !== null) {
            $ops[] = $extra;
        }

        return [new SessionToolGate($store, $session, $ops, trialRouter: $router), $store];
    }

    private function plainMutation(): Operation
    {
        return new Operation(
            name: 'mail:send',
            description: 'sends mail — third party, so it pauses and never goes to trial',
            handler: static fn (array $i): array => ['ok' => true],
            mutating: true,
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::ThirdParty,
                reversibility: Reversibility::ManualRecovery,
                authority: Authority::WriteAsUser,
                subject: Subject::Data,
            ),
        );
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/milpa-promdiff-' . bin2hex(random_bytes(4));
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
