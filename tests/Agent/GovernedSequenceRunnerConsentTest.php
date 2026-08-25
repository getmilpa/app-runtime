<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\McpClientService;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\GovernedSequenceRunner;
use Milpa\AppRuntime\Agent\SequenceStep;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Agent\StepStatus;
use Milpa\Command\Operation;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Characterizes HOW the real consent frontier signals, against the wiring `AgentOperations::ask`
 * actually builds — a real `ConsentBridge` over a real `SessionToolGate`, not a fake standing in for
 * either. Task 4's fail-closed catch assumed `ToolCallRefusedException` is what a refused step looks
 * like; this either confirms that assumption against the product or refutes it.
 *
 * TRACED, NOT GUESSED. `SessionToolGate::refuse()` runs BEFORE `ToolRegistry::call()` ever sees the
 * call (`McpClientService::callTool` checks the gate first). For a mutating operation with no grant
 * and a session in the default `Ask` mode, `SessionPolicy::decide()` returns `AskPermission`, the
 * gate's `refuse()` returns the pause text (non-null), and `McpClientService::callTool` throws
 * `ToolCallRefusedException` — the call never reaches the OTHER confirmation mechanism
 * (`ToolRegistry`'s own `PolicyGate::requiresConfirmation`, which instead RETURNS a
 * `requires_confirmation`/`confirm_token` shape and is what `ConsentBridge`'s token-consuming branch
 * exists for). The two are separate layers; the session gate is the one the real app wires first,
 * and it is a throw.
 *
 * THE VERDICT: the throw case is real. Task 4's catch of `Milpa\AiGateway\ToolCallRefusedException`
 * already stops the sequence at exactly this frontier — no new predicate is needed in
 * `GovernedSequenceRunner`.
 *
 * @internal
 */
final class GovernedSequenceRunnerConsentTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(McpClientService::class)) {
            self::markTestSkipped('sin milpa/ai-gateway no hay puente que construir');
        }
    }

    /** A registry holding a read (no gate opinion) and a mutation the session policy will gate. */
    private function registry(): ToolRegistry
    {
        $registry = new ToolRegistry(new NullLogger());
        $registry->register(
            'demo_read',
            'reads something, changes nothing',
            ['type' => 'object', 'properties' => []],
            static fn (array $args): array => ['ok' => true, 'read' => true],
            new ToolOptions(mutating: false),
        );
        $registry->register(
            'demo_mutate',
            'mutates something',
            ['type' => 'object', 'properties' => []],
            static fn (array $args): array => ['ok' => true, 'mutated' => true],
            new ToolOptions(mutating: true),
        );

        return $registry;
    }

    /** @return list<Operation> the same two tools, declared as this app's operations — what SessionToolGate judges */
    private function operations(): array
    {
        return [
            new Operation(
                'demo_read',
                'Reads',
                static fn (array $i): array => ['ok' => true],
                inputSchema: ['type' => 'object', 'properties' => []],
            ),
            new Operation(
                'demo_mutate',
                'Mutates',
                static fn (array $i): array => ['ok' => true],
                inputSchema: ['type' => 'object', 'properties' => []],
                mutating: true,
            ),
        ];
    }

    public function testTheSequenceStopsAtTheRealConsentBridgeFrontier(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        // AutonomyMode::Ask is the default — a session that pauses before any mutation until a
        // human grants it (SessionPolicy::decide() → AskPermission).
        $store->start('s1', 'run the demo sequence', AutonomyMode::Ask);
        $session = $store->load('s1');
        self::assertNotNull($session);

        $gate = new SessionToolGate($store, $session, $this->operations());

        // THE REAL BRIDGE, mirroring how AgentOperations::ask builds it: a ToolCallGate that is ALSO
        // the ToolCallRecorder and the ExecutionRecorder, and NO grants for the mutation.
        $bridge = new ConsentBridge(
            $this->registry(),
            grants: [],
            gate: $gate,
            recorder: $gate,
            executions: $gate,
        );

        $result = (new GovernedSequenceRunner())->run([
            new SequenceStep('demo_read', []),
            new SequenceStep('demo_mutate', []),
        ], $bridge);

        self::assertSame(StepStatus::Executed, $result->outcomes[0]->status, 'the read never needed a grant');
        self::assertSame(['ok' => true, 'read' => true], $result->outcomes[0]->result);

        self::assertSame(StepStatus::Paused, $result->outcomes[1]->status, 'the ungranted mutation is the frontier');
        self::assertNotNull($result->outcomes[1]->reason);
        self::assertStringContainsString('demo_mutate', (string) $result->outcomes[1]->reason);

        // AND THE SESSION ITSELF KNOWS IT: SessionToolGate::refuse() does not just say no, it APPENDS
        // the question — the human-facing half of the same frontier the runner recorded.
        self::assertFalse($store->load('s1')?->isRunnable(), 'the session was left waiting for a human answer');
    }
}
