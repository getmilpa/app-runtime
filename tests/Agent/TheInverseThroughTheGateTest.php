<?php

/**
 * This file is part of Milpa App Runtime — the governed runtime of a founded Milpa app.
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
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\Command\Consent\ConsentGrant;
use Milpa\Command\Consent\OperationId;
use Milpa\Command\Operation;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Plugin\Contracts\ActivationSafetyInterface;
use Milpa\Plugin\Contracts\PluginRecord;
use Milpa\Plugin\Operations\PluginOperations;
use Milpa\Plugin\Registry\InMemoryPluginRegistry;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * THE REVERSAL FALSIFIER, THROUGH THE GATE — greenhouse `.milpa/promises/reversal-contract.yaml`.
 *
 * The promise asks that the named inverse «survive the falsifier PASSING THROUGH THE NORMAL GATE». The
 * falsifier in `milpa/plugin` calls the handler directly, which proves the inverse UNDOES and proves
 * nothing about consent — and an inverse that only runs with the gate bypassed is a side door.
 *
 * It lives here and not beside its operations because `milpa/plugin` requires only core, command, events
 * and resolver: giving it the gate would mean depending on app-runtime, which already depends on IT.
 * The dependency graph decides where this test can exist.
 *
 * ── WHAT THIS MEASURES THAT THE OTHER ONE CANNOT ────────────────────────────────────────────────────
 *
 * Grants are keyed by OPERATION NAME, so a human's yes to `plugins.enable` does not cover
 * `plugins.disable`. «The inverse survives the gate» is therefore TWO ceremonies, not one: the inverse
 * faces its own pause, its own question and its own grant. That is the claim nobody had run.
 */
final class TheInverseThroughTheGateTest extends TestCase
{
    private const PLUGIN = 'Acme';

    private InMemoryPluginRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new InMemoryPluginRegistry();
        $this->registry->register(new PluginRecord(
            name: self::PLUGIN,
            version: '1.0.0',
            author: 'Acme',
            site: 'https://example.com',
            type: 'Service',
            installed: true,
            enabled: false,
            source: 'local',
        ));
    }

    /**
     * F — the forward operation and its declared inverse each pass the WHOLE ceremony, and the declared
     * consequence is gone at the end.
     */
    public function testTheInverseFacesItsOwnCeremonyAndUndoesTheForwardOperation(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        // The petition carries the target because the intent contract (ADR-0044) fires BEFORE the policy:
        // an operation with a namedTarget refuses a target the human never named.
        $store->start('s1', 'turn the ' . self::PLUGIN . ' plugin on and then off again', AutonomyMode::Ask);
        $session = $store->load('s1');
        self::assertNotNull($session);

        // THE YES ENTERS THROUGH THE LEDGER, which is where the gate reads it — the bridge's own grants
        // answer the tool-runtime confirmation token, a second and independent door. TWO permissions, and
        // that is the finding: grants are keyed by OPERATION NAME, so a yes to one does not cover the
        // other. «The inverse survives the gate» is two ceremonies, not one.
        $store->grant('s1', 'plugins.enable');
        $store->grant('s1', 'plugins.disable');
        $session = $store->load('s1');
        self::assertNotNull($session);

        $gate = new SessionToolGate($store, $session, $this->operations());
        $bridge = new ConsentBridge(
            $this->registry(),
            grants: [$this->grant('plugins.enable'), $this->grant('plugins.disable')],
            gate: $gate,
            recorder: $gate,
            executions: $gate,
        );

        self::assertFalse($this->pluginIsEnabled(), 'the postcondition does not hold yet');

        $bridge->callTool('plugins_enable', ['name' => self::PLUGIN]);
        self::assertTrue($this->pluginIsEnabled(), 'the forward operation ran THROUGH the gate');

        // The inverse is taken from the DECLARATION, never from what this test knows.
        $inverse = $this->declaredInverseOf('plugins.enable');
        self::assertNotNull($inverse, 'plugins.enable names what undoes it');

        $bridge->callTool($this->toolName($inverse), ['name' => self::PLUGIN]);

        self::assertFalse($this->pluginIsEnabled(), 'and the declared consequence is gone');
        self::assertNotNull($this->registry->find(self::PLUGIN), 'the precondition holds again');
        self::assertTrue(
            $store->load('s1')?->isRunnable(),
            'and the session never stopped: both ceremonies were satisfied, neither was skipped',
        );
    }

    /**
     * THE POSITIVE CONTROL, and the reason the test above proves anything.
     *
     * Without a grant for the INVERSE — with the forward one granted — the ceremony must stop it. If the
     * inverse ran on the strength of the forward operation's yes, «through the gate» would mean nothing:
     * the human would have authorised one act and got two.
     */
    public function testAYesToTheForwardOperationDoesNotCoverItsInverse(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s2', 'turn the ' . self::PLUGIN . ' plugin on and then off again', AutonomyMode::Ask);
        $session = $store->load('s2');
        self::assertNotNull($session);

        // Only the FORWARD operation is granted. If the inverse runs anyway, «through the gate» means
        // nothing: the human authorised one act and got two.
        $store->grant('s2', 'plugins.enable');
        $session = $store->load('s2');
        self::assertNotNull($session);

        $gate = new SessionToolGate($store, $session, $this->operations());
        $bridge = new ConsentBridge(
            $this->registry(),
            grants: [$this->grant('plugins.enable')],
            gate: $gate,
            recorder: $gate,
            executions: $gate,
        );

        $bridge->callTool('plugins_enable', ['name' => self::PLUGIN]);
        self::assertTrue($this->pluginIsEnabled());

        try {
            $bridge->callTool('plugins_disable', ['name' => self::PLUGIN]);
            self::fail('the inverse ran on the strength of the forward operation\'s yes');
        } catch (\Throwable $refused) {
            self::assertStringContainsString('plugins', $refused->getMessage());
        }

        self::assertTrue($this->pluginIsEnabled(), 'and nothing was undone: the ceremony held');
        self::assertFalse($store->load('s2')?->isRunnable(), 'the session is waiting for a human answer');
    }

    /** The state the forward operation's postcondition declares, read through its declared evidence. */
    private function pluginIsEnabled(): bool
    {
        return $this->registry->find(self::PLUGIN)?->enabled === true;
    }

    /** The inverse an operation DECLARED, as an identity — never as a string this test wrote down. */
    private function declaredInverseOf(string $name): ?OperationId
    {
        foreach ($this->operations() as $operation) {
            if ((new OperationId($operation->name))->is($name)) {
                return $operation->effects?->rollbackOperation();
            }
        }

        return null;
    }

    /** How a tool catalogue writes an act — the spelling the bridge answers to. */
    private function toolName(OperationId $id): string
    {
        return $id->forTool();
    }

    private function grant(string $operation): ConsentGrant
    {
        return new ConsentGrant(
            operation: new OperationId($operation),
            principal: 'cli:rod@casa',
            session: 's1',
            grantedAt: new \DateTimeImmutable('2026-09-07 12:00:00'),
            provenance: 'session.question_answered',
            arguments: ['name' => self::PLUGIN],
        );
    }

    /** The two operations, wired the way a real host wires them — safety included, or the inverse refuses. */
    private function pluginOperations(): PluginOperations
    {
        return new PluginOperations($this->registry, null, [], new class () implements ActivationSafetyInterface {
            public function blockingReasonWithout(string $pluginName): ?string
            {
                return null;
            }

            public function blockingReasonWith(string $newPluginClass): ?string
            {
                return null;
            }
        });
    }

    /** @return list<Operation> what the gate judges */
    private function operations(): array
    {
        $out = [];
        foreach ($this->pluginOperations()->operations() as $operation) {
            if (\in_array($operation->name, ['plugins.enable', 'plugins.disable'], true)) {
                $out[] = $operation;
            }
        }

        return $out;
    }

    /** The same two, as tools — what the bridge actually calls. */
    private function registry(): ToolRegistry
    {
        $registry = new ToolRegistry(new NullLogger());
        foreach ($this->operations() as $operation) {
            $handler = $operation->handler;
            $registry->register(
                (new OperationId($operation->name))->forTool(),
                $operation->description,
                $operation->inputSchema,
                static fn (array $args): mixed => $handler($args),
                new ToolOptions(mutating: $operation->mutating),
            );
        }

        return $registry;
    }
}
