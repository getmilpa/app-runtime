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
use Milpa\Agent\Session;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * The glue greenhouse decisions/0069 §11 asks for: the ONE builder wires the trial router into the
 * gate, and only when the app declares `agent.trialWorkspace` true. The unit tests prove the router,
 * the gate and the registry each behave; this proves the wiring joins them, so no confined call can
 * be judged by a gate the executor does not share a router with.
 */
final class TrialWiringTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/trial-wiring-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    public function testTheGateHasARouterByDefaultWhenTheLeafIsAbsent(): void
    {
        // ON BY DEFAULT (decisions/0072): nobody touched the leaf, so the gate is wired to a router.
        if (! (new TrialRunner())->available()) {
            self::markTestSkipped('this host offers no unprivileged user namespace for bwrap');
        }

        $gate = $this->buildGate(trialWorkspace: null);

        self::assertInstanceOf(TrialRouter::class, $this->routerOf($gate), 'absent means on: the gate carries a router');
    }

    public function testAnExplicitFalseIsTheEscapeHatch(): void
    {
        $gate = $this->buildGate(trialWorkspace: false);

        self::assertNull($this->routerOf($gate), 'only an explicit false turns the trial off');
    }

    public function testWithTheLeafOnAndASandboxTheGateCarriesARouter(): void
    {
        if (! (new TrialRunner())->available()) {
            self::markTestSkipped('this host offers no unprivileged user namespace for bwrap');
        }

        $gate = $this->buildGate(trialWorkspace: true);

        self::assertInstanceOf(TrialRouter::class, $this->routerOf($gate), 'on, with a sandbox: the gate is wired to a router');
    }

    public function testTheRouterIsResolvedOnceAndShared(): void
    {
        $operations = $this->operations(trialWorkspace: true);
        if (! (new TrialRunner())->available()) {
            self::markTestSkipped('this host offers no unprivileged user namespace for bwrap');
        }
        $kernel = $this->kernelAt($this->root);

        $first = $this->callRouter($operations, $kernel);
        $second = $this->callRouter($operations, $kernel);

        self::assertNotNull($first);
        self::assertSame($first, $second, 'the gate and the executor must share ONE router, or they plan different workspaces');
    }

    private function routerOf(SessionToolGate $gate): ?TrialRouter
    {
        $p = (new \ReflectionObject($gate))->getProperty('trialRouter');
        $p->setAccessible(true);
        $value = $p->getValue($gate);

        return $value instanceof TrialRouter ? $value : null;
    }

    private function callRouter(AgentOperations $operations, Kernel $kernel): ?TrialRouter
    {
        $m = new \ReflectionMethod($operations, 'trialRouter');
        $m->setAccessible(true);

        $value = $m->invoke($operations, $kernel);

        return $value instanceof TrialRouter ? $value : null;
    }

    private function buildGate(?bool $trialWorkspace): SessionToolGate
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s-1', 'goal', AutonomyMode::Ask);
        $sesion = $store->load('s-1');
        self::assertInstanceOf(Session::class, $sesion);

        $operations = $this->operations($trialWorkspace);
        $kernel = $this->kernelAt($this->root);

        $m = new \ReflectionMethod($operations, 'nuevaCompuerta');
        $m->setAccessible(true);

        /** @var SessionToolGate */
        return $m->invoke($operations, $store, $kernel, $sesion, 'la petición', []);
    }

    private function operations(?bool $trialWorkspace): AgentOperations
    {
        $container = new class ($trialWorkspace) implements DIContainerInterface {
            public function __construct(private readonly ?bool $trialWorkspace)
            {
            }

            public function getContainer(): \Psr\Container\ContainerInterface
            {
                throw new \LogicException('the double answers only what the builder asks');
            }

            public function registerService(string $id, string|object $classOrInstance): void
            {
            }

            public function compileContainer(): void
            {
            }

            public function resolve(string $className, bool $singleton = true): mixed
            {
                return null;
            }

            public function tryGet(string $id): mixed
            {
                return $this->has($id) ? $this->get($id) : null;
            }

            public function has(string $id): bool
            {
                return $id === Config::class;
            }

            public function get(string $id): mixed
            {
                return new Config($this->trialWorkspace === null ? [] : ['agent' => ['trialWorkspace' => $this->trialWorkspace]]);
            }
        };

        $operations = (new \ReflectionClass(AgentOperations::class))->newInstanceWithoutConstructor();
        $p = (new \ReflectionClass(AgentOperations::class))->getProperty('container');
        $p->setAccessible(true);
        $p->setValue($operations, $container);

        return $operations;
    }

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
