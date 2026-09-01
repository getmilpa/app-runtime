<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
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
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\DebtSignal;
use Milpa\AppRuntime\Agent\SessionToolGate;
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
 * The gate's two debt signals (greenhouse decisions/0183 primitive #5, the ruling of 0184/0445).
 *
 * kind `admitted_intent_skip`: when an exact confirmed intent claim admits and the perm: ceremony
 * is skipped, the HOUSE emits the observation — the named residue of evidence/0445: the
 * zero-authority skip becomes visible as a SIGNAL, never as authority.
 *
 * kind `high_tier_double_ceremony`: when the perm: question IS asked although an exact confirmed
 * intent claim exists and IntentAdmissibility ruled the tier NEVER — deliberate policy COUNTED as
 * institutional friction (the plugins.register case of evidence/0444), never prevented.
 *
 * And the falsifier that keeps both honest: with the seam absent, every path decides byte-identical
 * and the stream differs by NOTHING; with it present, only the extra signals differ.
 *
 * @internal
 */
final class GateSignalsDebtTest extends TestCase
{
    /** The mid-tier fixture: persistent, as-user, nothing leaves — IntentAdmissibility EXACT_SCOPE. */
    private function archiveOp(): Operation
    {
        return new Operation(
            'notes.archive',
            'Archives a note',
            static fn (array $i): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => []],
            mutating: true,
            effects: new EffectProfile(
                Mutation::Persistent,
                Externality::None,
                Reversibility::Compensatable,
                Authority::WriteAsUser,
                subject: Subject::Data,
            ),
        );
    }

    /** The ephemeral fixture: dies with the run — IntentAdmissibility SUFFICIENT. */
    private function scratchOp(): Operation
    {
        return new Operation(
            'scratch.note',
            'Writes an ephemeral scratch note',
            static fn (array $i): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => []],
            mutating: true,
            effects: new EffectProfile(
                Mutation::Ephemeral,
                Externality::None,
                Reversibility::Compensatable,
                Authority::WriteAsUser,
                subject: Subject::Data,
            ),
        );
    }

    /** The high-tier fixture, plugins.register-shaped: Privileged — IntentAdmissibility NEVER. */
    private function registerOp(): Operation
    {
        return new Operation(
            'plugins.register',
            'Wires a plugin into the app',
            static fn (array $i): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => []],
            mutating: true,
            requiresConfirmation: true,
            effects: new EffectProfile(
                Mutation::Persistent,
                Externality::None,
                Reversibility::Compensatable,
                Authority::Privileged,
                subject: Subject::Executable,
            ),
        );
    }

    /**
     * Leaves in the stream the exact fact the intent ceremony leaves — the same shape
     * GateAdmitsConfirmedIntentTest seeds.
     *
     * @param array<string, mixed> $arguments
     */
    private function confirmIntent(SessionStore $store, string $sessionId, string $operation, array $arguments, string $answer = 'sí'): void
    {
        $store->ask($sessionId, new PendingQuestion(
            id: 'intent-' . substr(sha1($operation), 0, 12),
            question: "La petición no nombra al objetivo. ¿Confirmas {$operation}?",
            options: ['sí', 'no'],
            why: json_encode(
                ['operation' => $operation, 'arguments' => $arguments],
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            ) ?: null,
            reason: 'target_not_named',
        ));
        $store->answer($sessionId, 'intent-' . substr(sha1($operation), 0, 12), $answer);
    }

    private function gate(SessionStore $store, string $sessionId, Operation $op, ?DebtSignal $signals): SessionToolGate
    {
        $session = $store->load($sessionId);
        self::assertNotNull($session);

        return new SessionToolGate($store, $session, [$op], debtSignals: $signals);
    }

    /** @return list<object> */
    private function signalsIn(InMemoryEventStore $eventos, string $sessionId = 's1'): array
    {
        return array_values(array_filter(
            $eventos->replay(SessionStore::PREFIX . $sessionId),
            static fn (object $evento): bool => $evento->type === DebtSignal::EVENT,
        ));
    }

    /**
     * The stream as [type, payload] pairs — what an A/B comparison may look at: `seq` shifts by
     * construction when an extra event lands, and `recordedAt` is wall clock.
     *
     * @return list<array{string, array<string, mixed>}>
     */
    private function shapeOf(InMemoryEventStore $eventos, string $sessionId = 's1'): array
    {
        return array_map(
            static fn (object $evento): array => [$evento->type, $evento->payload],
            $eventos->replay(SessionStore::PREFIX . $sessionId),
        );
    }

    // ── kind 1 · admitted_intent_skip ───────────────────────────────────────────────────────────

    public function testAnAdmittedSkipEmitsTheSignalWithTierAndDigestExactlyOnce(): void
    {
        $eventos = new InMemoryEventStore();
        $store = new SessionStore($eventos);
        $store->start('s1', 'x', AutonomyMode::Ask);
        $this->confirmIntent($store, 's1', 'notes.archive', ['name' => 'Ledger2024']);

        $gate = $this->gate($store, 's1', $this->archiveOp(), new DebtSignal($eventos, 's1'));
        self::assertNull($gate->refuse('notes_archive', ['name' => 'Ledger2024']), 'the claim admits: no perm: ceremony');

        $señales = $this->signalsIn($eventos);
        self::assertCount(1, $señales, 'one skip, one signal');
        self::assertSame([
            'signal' => 'admitted_intent_skip',
            'context' => [
                'operation' => 'notes.archive',
                'tier' => 'exact_scope',
                // The ConsentBridge digest — never raw arguments (decisions/0183: digests over raw values).
                'argumentsDigest' => ConsentBridge::digest(['name' => 'Ledger2024']),
            ],
        ], $señales[0]->payload);

        // ORDER: the signal sits where the skipped ceremony would have been — right after the
        // confirmed claim it observes, last in the stream until the admitted call lands.
        $stream = $eventos->replay(SessionStore::PREFIX . 's1');
        self::assertSame(DebtSignal::EVENT, end($stream)->type);
    }

    public function testASufficientTierSkipNamesItsTier(): void
    {
        $eventos = new InMemoryEventStore();
        $store = new SessionStore($eventos);
        $store->start('s1', 'x', AutonomyMode::Ask);
        $this->confirmIntent($store, 's1', 'scratch.note', ['text' => 'hola']);

        $gate = $this->gate($store, 's1', $this->scratchOp(), new DebtSignal($eventos, 's1'));
        self::assertNull($gate->refuse('scratch_note', ['text' => 'hola']));

        $señales = $this->signalsIn($eventos);
        self::assertCount(1, $señales);
        self::assertSame('sufficient', $señales[0]->payload['context']['tier']);
    }

    public function testAnOrdinaryAskWithNoClaimEmitsNothing(): void
    {
        $eventos = new InMemoryEventStore();
        $store = new SessionStore($eventos);
        $store->start('s1', 'x', AutonomyMode::Ask);

        $gate = $this->gate($store, 's1', $this->archiveOp(), new DebtSignal($eventos, 's1'));

        self::assertNotNull($gate->refuse('notes_archive', ['name' => 'Ledger2024']), 'no claim: the perm: question is owed');
        self::assertSame([], $this->signalsIn($eventos), 'an unpaid ceremony is not debt — nothing was skipped, nothing doubled');
    }

    // ── kind 2 · high_tier_double_ceremony ──────────────────────────────────────────────────────

    public function testAHighTierAskOverAnExactConfirmedClaimEmitsTheDoubleCeremonySignal(): void
    {
        $eventos = new InMemoryEventStore();
        $store = new SessionStore($eventos);
        $store->start('s1', 'x', AutonomyMode::Ask);
        $this->confirmIntent($store, 's1', 'plugins.register', ['plugin' => 'TareasPlugin']);

        $gate = $this->gate($store, 's1', $this->registerOp(), new DebtSignal($eventos, 's1'));

        self::assertNotNull(
            $gate->refuse('plugins_register', ['plugin' => 'TareasPlugin']),
            'the double ceremony stays DELIBERATE policy — counted, never prevented',
        );
        self::assertSame('perm:plugins.register', $store->load('s1')?->question?->id, 'the perm: question was really asked');

        $señales = $this->signalsIn($eventos);
        self::assertCount(1, $señales, 'one double ceremony, one signal');
        self::assertSame([
            'signal' => 'high_tier_double_ceremony',
            'context' => ['operation' => 'plugins.register', 'tier' => 'never'],
        ], $señales[0]->payload);

        // ORDER: the signal is adjacent to the fact it observes — immediately after the perm:
        // question it counts as friction.
        $stream = $eventos->replay(SessionStore::PREFIX . 's1');
        $tipos = array_map(static fn (object $evento): string => $evento->type, $stream);
        $pregunta = array_search('session.question_asked', \array_slice($tipos, -2, 2, true), true);
        self::assertIsInt($pregunta, 'the perm: question is one of the last two events');
        self::assertSame(DebtSignal::EVENT, $tipos[$pregunta + 1], 'and the signal follows it immediately');
    }

    public function testAHighTierAskWithoutAnExactClaimEmitsNothing(): void
    {
        // A claim for ANOTHER target: the perm: question is a single ceremony, not a doubled one.
        $eventos = new InMemoryEventStore();
        $store = new SessionStore($eventos);
        $store->start('s1', 'x', AutonomyMode::Ask);
        $this->confirmIntent($store, 's1', 'plugins.register', ['plugin' => 'OtroPlugin']);

        $gate = $this->gate($store, 's1', $this->registerOp(), new DebtSignal($eventos, 's1'));

        self::assertNotNull($gate->refuse('plugins_register', ['plugin' => 'TareasPlugin']));
        self::assertSame([], $this->signalsIn($eventos));
    }

    public function testAClaimAnsweredNoNeverCountsAsADoubledCeremony(): void
    {
        $eventos = new InMemoryEventStore();
        $store = new SessionStore($eventos);
        $store->start('s1', 'x', AutonomyMode::Ask);
        $this->confirmIntent($store, 's1', 'plugins.register', ['plugin' => 'TareasPlugin'], answer: 'no');

        $gate = $this->gate($store, 's1', $this->registerOp(), new DebtSignal($eventos, 's1'));

        self::assertNotNull($gate->refuse('plugins_register', ['plugin' => 'TareasPlugin']));
        self::assertSame([], $this->signalsIn($eventos), 'a no is not a confirmed claim; asking is the only ceremony there is');
    }

    public function testAnUnclassifiedOperationWithAnExactClaimCountsAsNeverTier(): void
    {
        // No declared EffectProfile carries the ceiling of every dimension (GOV-05): the tier is
        // NEVER, so the ask over an exact claim is the same counted friction.
        $bare = new Operation(
            'config.set',
            'Sets a config key, effects undeclared',
            static fn (array $i): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => []],
            mutating: true,
        );
        $eventos = new InMemoryEventStore();
        $store = new SessionStore($eventos);
        $store->start('s1', 'x', AutonomyMode::Ask);
        $this->confirmIntent($store, 's1', 'config.set', ['key' => 'debug', 'value' => true]);

        $gate = $this->gate($store, 's1', $bare, new DebtSignal($eventos, 's1'));

        self::assertNotNull($gate->refuse('config_set', ['key' => 'debug', 'value' => true]));
        $señales = $this->signalsIn($eventos);
        self::assertCount(1, $señales);
        self::assertSame('never', $señales[0]->payload['context']['tier']);
    }

    // ── NO BEHAVIOR CHANGE: the A/B falsifier ───────────────────────────────────────────────────

    public function testWithTheSeamAbsentTheSkipDecisionAndTheStreamAreIdentical(): void
    {
        $mundo = function (?bool $conSeñal): array {
            $eventos = new InMemoryEventStore();
            $store = new SessionStore($eventos);
            $store->start('s1', 'x', AutonomyMode::Ask);
            $this->confirmIntent($store, 's1', 'notes.archive', ['name' => 'Ledger2024']);
            $gate = $this->gate($store, 's1', $this->archiveOp(), $conSeñal === true ? new DebtSignal($eventos, 's1') : null);

            return [$gate->refuse('notes_archive', ['name' => 'Ledger2024']), $eventos];
        };

        [$decisionA, $eventosA] = $mundo(false);
        [$decisionB, $eventosB] = $mundo(true);

        self::assertSame($decisionA, $decisionB, 'the gate decides identically in both worlds');
        self::assertSame([], $this->signalsIn($eventosA), 'the silent world carries no signal');

        $sinSeñales = array_values(array_filter(
            $this->shapeOf($eventosB),
            static fn (array $par): bool => $par[0] !== DebtSignal::EVENT,
        ));
        self::assertSame($this->shapeOf($eventosA), $sinSeñales, 'only the extra debt signal differs');
    }

    public function testWithTheSeamAbsentTheDoubleCeremonyPauseAndTheStreamAreIdentical(): void
    {
        $mundo = function (?bool $conSeñal): array {
            $eventos = new InMemoryEventStore();
            $store = new SessionStore($eventos);
            $store->start('s1', 'x', AutonomyMode::Ask);
            $this->confirmIntent($store, 's1', 'plugins.register', ['plugin' => 'TareasPlugin']);
            $gate = $this->gate($store, 's1', $this->registerOp(), $conSeñal === true ? new DebtSignal($eventos, 's1') : null);

            return [$gate->refuse('plugins_register', ['plugin' => 'TareasPlugin']), $eventos];
        };

        [$pausaA, $eventosA] = $mundo(false);
        [$pausaB, $eventosB] = $mundo(true);

        self::assertSame($pausaA, $pausaB, 'the pause text is byte-identical in both worlds');
        self::assertSame([], $this->signalsIn($eventosA));

        $sinSeñales = array_values(array_filter(
            $this->shapeOf($eventosB),
            static fn (array $par): bool => $par[0] !== DebtSignal::EVENT,
        ));
        self::assertSame($this->shapeOf($eventosA), $sinSeñales, 'only the extra debt signal differs');
    }
}
