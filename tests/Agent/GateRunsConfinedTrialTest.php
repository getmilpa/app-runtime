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
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The falsifiers of greenhouse decisions/0068 at the gate (0069 §A1, A2, C1, D): a confined
 * mutation that fits the trial ceiling runs without a pause; anything wider, or anything without a
 * real confinement, pauses exactly as before.
 */
final class GateRunsConfinedTrialTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::rmrf($root);
        }
    }

    public function testAConfinedMutationThatFitsTheTrialCeilingDoesNotPause(): void
    {
        [$gate, $eventos] = $this->gate($this->op('config:set'), withRouter: true);

        self::assertNull($gate->refuse('config_set', ['key' => 'a', 'value' => 1]), 'A2: ask mode, and still no pause — the trial is confined');

        $recibos = $this->composiciones($eventos);
        self::assertCount(1, $recibos);
        self::assertSame('config:set', $recibos[0]['operation']);
        self::assertCount(1, $recibos[0]['reductions'], 'D: the confinement lowers ONE axis');
        self::assertSame('mutation', $recibos[0]['reductions'][0]['axis']);
        self::assertSame('persistent', $recibos[0]['reductions'][0]['from']);
        self::assertSame('ephemeral', $recibos[0]['reductions'][0]['to']);
        self::assertSame('trial-workspace', $recibos[0]['reductions'][0]['producer']);
        self::assertStringStartsWith('trial:', $recibos[0]['reductions'][0]['provenance']);
    }

    public function testTheSameOperationWithoutARouterPausesAsBefore(): void
    {
        [$gate, $eventos] = $this->gate($this->op('config:set'), withRouter: false);

        self::assertNotNull($gate->refuse('config_set', ['key' => 'a', 'value' => 1]));
        self::assertSame([], $this->composiciones($eventos));
    }

    public function testAThirdPartyMutationIsNeverConfinedAndPauses(): void
    {
        [$gate, $eventos] = $this->gate($this->op('mail:send', externality: Externality::ThirdParty), withRouter: true);

        self::assertNotNull($gate->refuse('mail_send', ['to' => 'x@y']), 'A1: a disposable copy does not make an email disposable');
        self::assertSame([], $this->composiciones($eventos), 'nothing was lowered, so nothing is claimed');
    }

    public function testAPrivilegedMutationIsWiderThanTheTrialCeilingAndPauses(): void
    {
        [$gate] = $this->gate($this->op('disk:format', authority: Authority::Privileged), withRouter: true);

        self::assertNotNull($gate->refuse('disk_format', []), 'ephemeral is not enough: authority must fit the ceiling too');
    }

    public function testAnOperationThatRequiresASignatureIsNeverPreEmptedByATrial(): void
    {
        [$gate] = $this->gate($this->op('config:set', requiresConfirmation: true), withRouter: true);

        self::assertNotNull($gate->refuse('config_set', ['key' => 'a']));
    }

    public function testWithoutASandboxNothingIsLoweredAndTheGatePausesAsToday(): void
    {
        [$gate, $eventos] = $this->gate($this->op('config:set'), withRouter: true, sandbox: false);

        self::assertNotNull($gate->refuse('config_set', ['key' => 'a']), 'C1: no userns, no confinement, no claim');
        self::assertSame([], $this->composiciones($eventos));
    }

    /** @return array{0: SessionToolGate, 1: InMemoryEventStore} */
    private function gate(Operation $op, bool $withRouter, bool $sandbox = true): array
    {
        $eventos = new InMemoryEventStore();
        $almacen = new SessionStore($eventos);
        $almacen->start('s-1', 'goal', AutonomyMode::Ask);
        $sesion = $almacen->load('s-1');
        self::assertNotNull($sesion);

        $router = null;
        if ($withRouter) {
            $root = $this->root();
            $bwrap = $sandbox ? $this->fakeBwrap($root) : '/nonexistent/bwrap';
            $router = new TrialRouter($root, new TrialRunner(bwrap: $bwrap), \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        }

        return [new SessionToolGate($almacen, $sesion, [$op], trialRouter: $router), $eventos];
    }

    /** @return list<array<string, mixed>> */
    private function composiciones(InMemoryEventStore $eventos): array
    {
        $out = [];
        foreach ($eventos->replay('agent-session:s-1') as $e) {
            if ($e->type === 'session.ceiling_composed') {
                $out[] = $e->payload['composition'];
            }
        }

        return $out;
    }

    private function op(
        string $name,
        bool $requiresConfirmation = false,
        Externality $externality = Externality::None,
        Authority $authority = Authority::WriteAsUser,
    ): Operation {
        return new Operation(
            name: $name,
            description: 'a probe',
            handler: static fn (array $i): array => ['ok' => true],
            mutating: true,
            requiresConfirmation: $requiresConfirmation,
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: $externality,
                reversibility: Reversibility::Compensatable,
                authority: $authority,
                subject: Subject::Configuration,
            ),
        );
    }

    private function fakeBwrap(string $root): string
    {
        $path = $root . '/fake-bwrap';
        file_put_contents($path, "#!/bin/sh\nexit 0\n");
        chmod($path, 0o755);

        return $path;
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/milpa-trial-' . bin2hex(random_bytes(4));
        mkdir($root . '/src', 0o777, true);
        file_put_contents($root . '/src/A.php', "<?php // a\n");
        $this->roots[] = $root;

        return $root;
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
