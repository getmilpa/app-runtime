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

use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use PHPUnit\Framework\TestCase;

/**
 * ONE source decides whether a call goes to trial (greenhouse decisions/0069 §10): the gate and the
 * executor both ask this router, and it answers the same thing to both because it remembers.
 */
final class TrialRouterTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::rmrf($root);
        }
    }

    public function testOnlyAConfinableMutationIsEligible(): void
    {
        $router = $this->router($this->root());

        self::assertTrue($router->eligible($this->op('config:set')));
        self::assertFalse($router->eligible($this->op('config:get', mutating: false)), 'a read has nothing to confine');
        self::assertFalse($router->eligible($this->op('mail:send', externality: Externality::ThirdParty)), 'a third party is not inside the copy');
        self::assertFalse($router->eligible($this->op('config:set', requiresConfirmation: true)), 'a signed consent is never pre-empted by a trial');
        self::assertFalse($router->eligible($this->op('plugins:disable', externality: Externality::Unknown)), 'unclassified externality is not None');
    }

    public function testTheHousesOwnOperationsNeverGoToTrial(): void
    {
        $router = $this->router($this->root());

        foreach (['agent:run', 'session:grant', 'capabilities:adopt', 'sandbox:promote', 'foundation:found'] as $name) {
            self::assertFalse($router->eligible($this->op($name)), $name);
        }
    }

    public function testAPlanIsRememberedByArgumentsAndCarriesItsConfinement(): void
    {
        $root = $this->root();
        $router = $this->router($root);
        $op = $this->op('config:set');

        $plan = $router->planFor($op, ['key' => 'a', 'value' => 1]);
        self::assertNotNull($plan);
        self::assertSame($plan, $router->planFor($op, ['value' => 1, 'key' => 'a']), 'same call, same plan — key order is not identity');
        self::assertNotSame($plan, $router->planFor($op, ['key' => 'a', 'value' => 2]), 'a different call is a different trial');

        self::assertSame($plan->workspace->id, $plan->confinement->workspaceId);
        self::assertSame(TrialWorkspace::BOUNDS, $plan->confinement->bounds);
        self::assertStringStartsWith('trial:' . $plan->workspace->id, $plan->confinement->provenance());
        self::assertDirectoryExists($plan->workspace->copy);
        self::assertContains($plan->workspace->id, TrialWorkspace::ids($root));
    }

    public function testAnIneligibleOperationHasNoPlanAndMaterialisesNothing(): void
    {
        $root = $this->root();
        $router = $this->router($root);

        self::assertNull($router->planFor($this->op('mail:send', externality: Externality::ThirdParty), []));
        self::assertNull($router->planFor($this->op('agent:run'), []));
        self::assertSame([], TrialWorkspace::ids($root));
    }

    public function testWithoutASandboxThereIsNoTrialAtAll(): void
    {
        $root = $this->root();
        $router = new TrialRouter($root, new TrialRunner(bwrap: '/nonexistent/bwrap'), $this->stub());

        self::assertTrue($router->eligible($this->op('config:set')), 'eligibility is about the operation');
        self::assertNull($router->planFor($this->op('config:set'), ['key' => 'a']), 'but a plan needs a real confinement: fail closed');
        self::assertSame([], TrialWorkspace::ids($root));
    }

    private function router(string $root): TrialRouter
    {
        return new TrialRouter($root, new TrialRunner(bwrap: $this->fakeBwrap($root)), $this->stub());
    }

    private function op(
        string $name,
        bool $mutating = true,
        bool $requiresConfirmation = false,
        Externality $externality = Externality::None,
    ): Operation {
        return new Operation(
            name: $name,
            description: 'a probe',
            handler: static fn (array $i): array => ['ok' => true],
            mutating: $mutating,
            requiresConfirmation: $requiresConfirmation,
            effects: new EffectProfile(
                mutation: $mutating ? Mutation::Persistent : Mutation::None,
                externality: $externality,
                reversibility: Reversibility::Compensatable,
                authority: Authority::WriteAsUser,
                subject: $mutating ? Subject::Configuration : Subject::None,
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
