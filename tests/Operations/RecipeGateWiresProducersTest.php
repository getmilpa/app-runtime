<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
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
use Milpa\Agent\Session;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\McpClientService;
use Milpa\AppRuntime\Agent\SessionBookkeeping;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Operations\RecipeOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * Parity regression with `GateBuilderWiresProducersTest`: the recipe path's gate builder
 * (`RecipeOperations::governedExecutor`) must carry the SAME `contractProducers` the agent gate's
 * builder does (greenhouse decisions/0078, `AgentOperations::nuevaCompuerta`) — not build a
 * `SessionToolGate` that resolves the session's own notebook and delegation to «no Operation and no
 * producer», the genuinely-UNJUDGEABLE case the gate fails closed on.
 *
 * `RecipeOperations` is coverage-excluded (phpunit.xml: its handler needs a booted app), but
 * `governedExecutor()` itself does not touch the container — only `Kernel::root()` and
 * `Kernel::commands()` — so it can be reached the SAME way `GateBuilderWiresProducersTest` reaches
 * `AgentOperations::nuevaCompuerta`: a real private-method invocation against a minimal kernel
 * rooted at an empty app, never a booted one. This is the smallest seam that actually exercises the
 * WIRING (as opposed to the producer list alone, which needs no kernel at all) — a future edit that
 * drops it, or adds a second recipe gate site that bypasses it, fails here before it ships silently.
 */
final class RecipeGateWiresProducersTest extends TestCase
{
    public function testTheRecipeGateCarriesContractProducersLikeTheAgentGateDoes(): void
    {
        $bridge = $this->buildBridge();

        // `gate` is declared PRIVATE on `McpClientService` (the parent `ConsentBridge` extends),
        // so it must be reflected from ITS declaring class — `ReflectionObject($bridge)` alone
        // cannot see a private property it did not declare.
        $gate = $this->prop(McpClientService::class, $bridge, 'gate');
        self::assertInstanceOf(SessionToolGate::class, $gate, 'the bridge must carry the built SessionToolGate');

        $producers = $this->prop(SessionToolGate::class, $gate, 'contractProducers');
        self::assertIsArray($producers);
        self::assertNotEmpty(
            $producers,
            'recipe:apply builds its gate with NO contractProducers — parity with the agent gate is missing',
        );

        $hasNotebook = false;
        foreach ($producers as $producer) {
            if ($producer instanceof SessionBookkeeping) {
                $hasNotebook = true;
            }
        }
        self::assertTrue($hasNotebook, 'the session\'s own notebook must be one of the wired producers');
    }

    /** Reaches through the real private builder, exactly as `GateBuilderWiresProducersTest` does. */
    private function buildBridge(): object
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('recipe:demo', 'apply recipe demo', AutonomyMode::Ask);
        $session = $store->load('recipe:demo');
        self::assertInstanceOf(Session::class, $session);

        $root = sys_get_temp_dir() . '/recipe-gate-' . bin2hex(random_bytes(4));
        mkdir($root, 0o775, true);

        $operations = new RecipeOperations(new DIContainer());
        $kernel = $this->kernelAt($root);

        $m = new \ReflectionMethod($operations, 'governedExecutor');
        $m->setAccessible(true);

        /** @var object */
        return $m->invoke($operations, $kernel, $root, $store, $session, 'apply recipe demo');
    }

    /** Reads a private property from the class that DECLARES it — never from a subclass's own reflection. */
    private function prop(string $declaringClass, object $target, string $name): mixed
    {
        $p = new \ReflectionProperty($declaringClass, $name);
        $p->setAccessible(true);

        return $p->getValue($target);
    }

    /** A kernel that answers only what the gate builder asks of it: its root, and an empty command table. */
    private function kernelAt(string $root): Kernel
    {
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'commands' => []] as $name => $value) {
            $prop = new \ReflectionProperty(Kernel::class, $name);
            $prop->setAccessible(true);
            $prop->setValue($kernel, $value);
        }

        return $kernel;
    }
}
