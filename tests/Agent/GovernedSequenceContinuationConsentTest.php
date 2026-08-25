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
use Milpa\Command\Consent\ConsentGrant;
use Milpa\Command\Consent\OperationId;
use Milpa\Command\Operation;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Properties 2 and 5 of greenhouse decisions/0075 (H-CONTINUITY-1), proven against the REAL
 * `ConsentBridge` / `ConsentGrant` / `SessionToolGate` — never a fake standing in for the domain's
 * own exactness rule. A resume authorizes EXACTLY the pending step; it never authorizes the tail.
 *
 * TWO GRANT SURFACES EXIST IN THE REAL HOUSE, and this suite exercises both, because a resume can
 * be handed either one and each answers a different question:
 *
 *   Session-level  `SessionStore::grant($id, $operation)` — what a bare "sí" to a permission
 *                  question produces in production (`SessionOperations::answer()`). Judged by
 *                  `Session::allows()`, which is PER OPERATION NAME for the rest of the session —
 *                  it never compares arguments at all. Two DIFFERENT operations are isolated by
 *                  name alone; this is the primary arm, `testResumeAuthorizesExactlyThePending...`.
 *
 *   ConsentBridge  the `$grants` constructor argument — a list of `ConsentGrant` consulted ONLY at
 *                  the OTHER consent frontier (a `requires_confirmation`/`confirm_token` sentinel,
 *                  never a throw). `ConsentGrant::covers()` DOES compare arguments when the grant
 *                  named them (`greenhouse decisions/0030`, the frozen battery) — this is where the
 *                  SAME operation, called twice with different arguments, is actually stressed.
 *
 * THE VERDICT: property 5 holds against both real mechanisms — a resume never authorizes a later
 * step under an earlier step's grant, whether the two steps are different operations (isolated by
 * name) or the same operation called with different arguments (isolated by `ConsentGrant::covers`'s
 * exact-argument comparison) — PROVIDED the grant the resume is handed is scoped to the pending
 * call. `GovernedSequenceRunner` mints nothing; the exactness lives entirely in the grant the
 * caller constructs, and the final test is the positive control proving a BARE grant would NOT
 * have held it — the danger the argument-specific shape exists to avoid.
 *
 * @internal
 */
final class GovernedSequenceContinuationConsentTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(McpClientService::class)) {
            self::markTestSkipped('sin milpa/ai-gateway no hay puente que construir');
        }
    }

    /** A registry with a read and TWO DISTINCT mutating operations, both gated by SessionToolGate. */
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
            'demo_mutate_b',
            'mutates B',
            ['type' => 'object', 'properties' => []],
            static fn (array $args): array => ['ok' => true, 'mutated' => 'b'],
            new ToolOptions(mutating: true),
        );
        $registry->register(
            'demo_mutate_c',
            'mutates C',
            ['type' => 'object', 'properties' => []],
            static fn (array $args): array => ['ok' => true, 'mutated' => 'c'],
            new ToolOptions(mutating: true),
        );

        return $registry;
    }

    /** @return list<Operation> the same three tools, declared as this app's operations — what SessionToolGate judges */
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
                'demo_mutate_b',
                'Mutates B',
                static fn (array $i): array => ['ok' => true],
                inputSchema: ['type' => 'object', 'properties' => []],
                mutating: true,
            ),
            new Operation(
                'demo_mutate_c',
                'Mutates C',
                static fn (array $i): array => ['ok' => true],
                inputSchema: ['type' => 'object', 'properties' => []],
                mutating: true,
            ),
        ];
    }

    /**
     * A registry with a read and ONE mutating operation whose effect depends on `id` — the tool
     * this file calls twice with DIFFERENT arguments to stress `ConsentGrant::covers()`'s
     * argument-subset comparison. No `SessionToolGate` judges this one: these tests drive
     * `ConsentBridge`'s OWN `$grants` list directly, at the OTHER consent frontier
     * (`requires_confirmation`/`confirm_token`), which is where `ConsentGrant` actually compares
     * arguments (`SessionToolGate`'s session-level grant never does — see the class docblock).
     */
    private function registryWithMutate(): ToolRegistry
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
            'mutates the thing named by "id"',
            ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            static fn (array $args): array => ['ok' => true, 'mutated' => $args['id'] ?? null],
            new ToolOptions(mutating: true),
        );

        return $registry;
    }

    /**
     * PROPERTIES 2 AND 5, real bridge, two DISTINCT mutating operations.
     *
     * `[read, mutate_b, mutate_c]` runs with NO grants in `AutonomyMode::Ask`: the read executes
     * freely, `mutate_b` is the real `SessionToolGate` consent frontier (a thrown
     * `ToolCallRefusedException`, exactly as `GovernedSequenceRunnerConsentTest` established), and
     * `mutate_c` never starts.
     *
     * The human's "sí" to `mutate_b` is then applied the way production actually applies it —
     * `SessionStore::grant($id, 'demo_mutate_b')`, a BARE per-operation grant for the rest of the
     * session (`SessionOperations::answer()`'s own mechanism) — and the run is resumed from the
     * cursor through a FRESH `SessionToolGate`/`ConsentBridge` built over the now-updated session.
     *
     * `mutate_b` runs (property 2: it ran under the grant that names it). `mutate_c` pauses AGAIN,
     * on its own frontier, exactly as a first pass would (property 5: `mutate_b`'s grant did not
     * authorize it) — proven against the real `Session::allows()`, not asserted from the design.
     */
    public function testResumeAuthorizesExactlyThePendingStepAcrossDistinctOperations(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'run the demo sequence', AutonomyMode::Ask);
        $session = $store->load('s1');
        self::assertNotNull($session);

        $gate = new SessionToolGate($store, $session, $this->operations());
        $bridge = new ConsentBridge($this->registry(), grants: [], gate: $gate, recorder: $gate, executions: $gate);

        $steps = [
            new SequenceStep('demo_read', []),
            new SequenceStep('demo_mutate_b', []),
            new SequenceStep('demo_mutate_c', []),
        ];
        $runner = new GovernedSequenceRunner();
        $first = $runner->run($steps, $bridge);

        self::assertSame(StepStatus::Executed, $first->outcomes[0]->status, 'the read never needed a grant');
        self::assertSame(StepStatus::Paused, $first->outcomes[1]->status, 'the ungranted mutate_b is the frontier');
        self::assertSame(StepStatus::NotStarted, $first->outcomes[2]->status, 'mutate_c never started behind the pause');

        $cursor = $first->pausedCursor($steps);
        self::assertNotNull($cursor);
        self::assertSame(1, $cursor->nextIndex);

        // THE HUMAN SAID YES TO mutate_b, ONLY. This is the exact call `SessionOperations::answer()`
        // makes after an affirmative answer to a bare permission question — by operation name, for
        // the rest of the session, no envelope.
        $store->grant('s1', 'demo_mutate_b');

        // A FRESH GATE OVER THE UPDATED SESSION. `Session` is immutable; the grant just appended is
        // only visible through a fresh `load()`, exactly as a real resuming process would reload it.
        $resumedSession = $store->load('s1');
        self::assertNotNull($resumedSession);
        $resumedGate = new SessionToolGate($store, $resumedSession, $this->operations());
        $resumedBridge = new ConsentBridge(
            $this->registry(),
            grants: [],
            gate: $resumedGate,
            recorder: $resumedGate,
            executions: $resumedGate,
        );

        $final = $runner->resume($steps, $cursor, $resumedBridge);

        self::assertSame(StepStatus::Executed, $final->outcomes[0]->status, 'the prefix is carried, not re-run');
        self::assertSame(StepStatus::Executed, $final->outcomes[1]->status, 'property 2: mutate_b ran under its own grant');
        self::assertSame(['ok' => true, 'mutated' => 'b'], $final->outcomes[1]->result);
        self::assertSame(
            StepStatus::Paused,
            $final->outcomes[2]->status,
            'property 5: mutate_b\'s grant did not authorize mutate_c — it pauses again, on its own frontier',
        );
        self::assertFalse($final->completed());
        self::assertSame(2, $final->executedCount());

        // AND THE SESSION ITSELF KNOWS IT — the same fact `GovernedSequenceRunnerConsentTest`
        // verified for a first pass holds identically for a resume: it is left waiting on a human.
        self::assertFalse($store->load('s1')?->isRunnable());
    }

    /**
     * PROPERTY 5, the arg-subset stress case: the SAME operation, called twice with DIFFERENT
     * arguments — the shape `ConsentGrant::covers()` was frozen around (greenhouse decisions/0030,
     * "case 2 of the frozen battery").
     *
     * `[read, mutate(id=b), mutate(id=c)]` runs with NO grants, over `ConsentBridge` alone (no
     * `SessionToolGate` — this is the OTHER consent frontier, the `requires_confirmation` sentinel
     * a `ToolRegistry` mutating-plus-confirmation-channel tool returns). It pauses at `mutate(id=b)`.
     *
     * Resuming with a `ConsentGrant` scoped to `mutate`'s EXACT pending arguments (`id: 'b'`) — the
     * shape `AgentOperations::grantsDeLaSesion()` actually mints, reading the arguments the pause
     * recorded — lets `mutate(id=b)` complete and `mutate(id=c)` pause again: the grant does not
     * widen across arguments it was never shown.
     */
    public function testResumeWithAnArgumentSpecificGrantDoesNotAuthorizeTheSameOperationCalledWithDifferentArguments(): void
    {
        $registry = $this->registryWithMutate();
        $bridge = new ConsentBridge($registry, grants: [], channel: 'telegram');

        $steps = [
            new SequenceStep('demo_read', []),
            new SequenceStep('demo_mutate', ['id' => 'b']),
            new SequenceStep('demo_mutate', ['id' => 'c']),
        ];
        $runner = new GovernedSequenceRunner();
        $first = $runner->run($steps, $bridge);

        self::assertSame(StepStatus::Executed, $first->outcomes[0]->status);
        self::assertSame(StepStatus::Paused, $first->outcomes[1]->status, 'mutate(id=b) is the frontier: no grant covers it yet');
        self::assertSame(StepStatus::NotStarted, $first->outcomes[2]->status);

        $cursor = $first->pausedCursor($steps);
        self::assertNotNull($cursor);
        self::assertSame(1, $cursor->nextIndex);

        // THE EXACT SHAPE `grantsDeLaSesion()` MINTS: the operation the pause named, and the
        // ARGUMENTS the pause recorded — never a wider set, never omitted.
        $grantForB = new ConsentGrant(
            operation: new OperationId('demo_mutate'),
            principal: null,
            session: null,
            grantedAt: new \DateTimeImmutable(),
            provenance: 'session.question_answered',
            arguments: ['id' => 'b'],
        );
        $resumedBridge = new ConsentBridge($registry, grants: [$grantForB], channel: 'telegram');

        $final = $runner->resume($steps, $cursor, $resumedBridge);

        self::assertSame(StepStatus::Executed, $final->outcomes[0]->status);
        self::assertSame(StepStatus::Executed, $final->outcomes[1]->status, 'the grant covers exactly the call it named');
        self::assertSame(['ok' => true, 'mutated' => 'b'], $final->outcomes[1]->result);
        self::assertSame(
            StepStatus::Paused,
            $final->outcomes[2]->status,
            'property 5: a grant for mutate(id=b) does not cover mutate(id=c) — ConsentGrant::covers() refuses it',
        );
        self::assertFalse($final->completed());
        self::assertSame(2, $final->executedCount());
    }

    /**
     * THE POSITIVE CONTROL for the test above: a BARE grant — the operation named, no arguments —
     * covers ANY call to that operation, `ConsentGrant::covers()` says so by design (an empty
     * `arguments` means "for this operation", which is what a plain session question produces).
     *
     * This is what proves the previous test is measuring something real: if this control also
     * paused at `mutate(id=c)`, the argument-specific test above would prove nothing, because
     * NOTHING could have widened it. Here, the same two-call sequence under a bare grant completes
     * BOTH calls — the danger an argument-specific grant exists to avoid, not a defect in the
     * runner (which mints no grant at all; the caller minted this one, badly, on purpose).
     */
    public function testABareSameOperationGrantWouldHaveWidenedAcrossDifferentArguments(): void
    {
        $registry = $this->registryWithMutate();
        $bridge = new ConsentBridge($registry, grants: [], channel: 'telegram');

        $steps = [
            new SequenceStep('demo_read', []),
            new SequenceStep('demo_mutate', ['id' => 'b']),
            new SequenceStep('demo_mutate', ['id' => 'c']),
        ];
        $runner = new GovernedSequenceRunner();
        $first = $runner->run($steps, $bridge);
        $cursor = $first->pausedCursor($steps);
        self::assertNotNull($cursor);

        // A BARE GRANT: "for this operation", no arguments named — exactly what `covers()`
        // documents as covering anything the operation name matches.
        $bareGrant = new ConsentGrant(
            operation: new OperationId('demo_mutate'),
            principal: null,
            session: null,
            grantedAt: new \DateTimeImmutable(),
            provenance: 'session.question_answered',
        );
        $resumedBridge = new ConsentBridge($registry, grants: [$bareGrant], channel: 'telegram');

        $final = $runner->resume($steps, $cursor, $resumedBridge);

        self::assertTrue(
            $final->completed(),
            'the control: a bare grant widens across arguments it never saw — this is why the ' .
            'grant a resume is handed must name the exact pending arguments, not just the operation',
        );
        self::assertSame(StepStatus::Executed, $final->outcomes[2]->status);
        self::assertSame(['ok' => true, 'mutated' => 'c'], $final->outcomes[2]->result);
    }
}
