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
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * The regression greenhouse decisions/0059 and evidence/0259 asked for: the one place a gate is
 * built wires the composed-ceiling producers, so no gate can be born without them.
 *
 * The bug the pin's method caught was invisible to every unit test that built the gate by hand —
 * because the omission lived in the WIRING, not the gate. So this test reaches through the single
 * builder and reads what it produced: a gate whose policy and identity are present when the app
 * declares a policy. If a future edit drops either from `nuevaCompuerta`, or adds a third gate site
 * that bypasses it, an owned session's authority would silently stop descending — and this fails
 * before it can.
 */
final class GateBuilderWiresProducersTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/gate-builder-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o775, true);
        // An app that DECLARES a policy: the builder must then carry a real identity admission.
        file_put_contents($this->root . '/config/policy.php', '<?php return new class implements \Milpa\AppRuntime\Policy\PolicyProvider {
            public function authorityPolicy(): ?\Milpa\Command\Effect\AuthorityPolicy { return null; }
            public function scopesForSigner(string $fingerprint): ?array { return null; }
        };');
    }

    /** The builder gives the gate BOTH producers when the app declares a policy. */
    public function testTheBuilderWiresBothProducers(): void
    {
        $gate = $this->buildGate();

        $ref = new \ReflectionObject($gate);
        self::assertNotNull($this->prop($ref, $gate, 'policyProvider'), 'the gate was built without its policy');
        self::assertNotNull($this->prop($ref, $gate, 'identity'), 'the gate was built without its identity admission');
    }

    /** And when the app declares NO policy, the gate is honestly built without one — not by accident. */
    public function testTheBuilderCarriesNoPolicyWhenTheAppDeclaresNone(): void
    {
        unlink($this->root . '/config/policy.php');

        $gate = $this->buildGate();

        $ref = new \ReflectionObject($gate);
        self::assertNull($this->prop($ref, $gate, 'policyProvider'));
        self::assertNull($this->prop($ref, $gate, 'identity'));
    }

    private function prop(\ReflectionObject $ref, SessionToolGate $gate, string $name): mixed
    {
        $p = $ref->getProperty($name);
        $p->setAccessible(true);

        return $p->getValue($gate);
    }

    /** Reaches through the ONE builder the runtime uses, with a minimal kernel rooted at a policy app. */
    private function buildGate(): SessionToolGate
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s-1', 'goal', AutonomyMode::Ask);
        $sesion = $store->load('s-1');
        self::assertInstanceOf(Session::class, $sesion);

        $container = new DIContainer();
        $container->registerService(SessionStore::class, $store);
        $operations = new AgentOperations($container);

        $kernel = $this->kernelAt($this->root);

        $m = new \ReflectionMethod($operations, 'nuevaCompuerta');
        $m->setAccessible(true);

        /** @var SessionToolGate */
        return $m->invoke($operations, $store, $kernel, $sesion, 'la petición del humano', []);
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
