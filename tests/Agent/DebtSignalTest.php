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
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\DebtSignal;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The emitter seam of the DebtSignal arc (greenhouse decisions/0183 primitive #5): an observation
 * channel that appends to the session's own stream and NEVER breaks the observed run.
 *
 * @internal
 */
final class DebtSignalTest extends TestCase
{
    public function testEmitAppendsTheDocumentedShapeToTheSessionsOwnStream(): void
    {
        $eventos = new InMemoryEventStore();
        $store = new SessionStore($eventos);
        $store->start('s', 'observe the house', AutonomyMode::Auto);

        (new DebtSignal($eventos, 's'))->emit(DebtSignal::ADMITTED_INTENT_SKIP, [
            'operation' => 'notes.archive',
            'tier' => 'exact_scope',
            'argumentsDigest' => 'sha256:abc',
        ]);

        $señales = array_values(array_filter(
            $eventos->replay(SessionStore::PREFIX . 's'),
            static fn (object $evento): bool => $evento->type === DebtSignal::EVENT,
        ));
        self::assertCount(1, $señales, 'one occurrence, one signal');
        self::assertSame([
            'signal' => 'admitted_intent_skip',
            'context' => [
                'operation' => 'notes.archive',
                'tier' => 'exact_scope',
                'argumentsDigest' => 'sha256:abc',
            ],
        ], $señales[0]->payload);
    }

    public function testWithNoReachableStoreEmittingDegradesToSilence(): void
    {
        $this->expectNotToPerformAssertions();

        // An observation channel must never break the observed run: no store, no signal, no error.
        (new DebtSignal(null, 's'))->emit(DebtSignal::SCOPE_FRAGILITY, ['operation' => 'config.set']);
    }

    public function testAStoreThatFailsMidAppendDoesNotBreakTheObservedRun(): void
    {
        $this->expectNotToPerformAssertions();

        $roto = new class () implements EventStoreInterface {
            public function append(Event $event): void
            {
                throw new \RuntimeException('disk full');
            }

            public function replay(string $streamId): array
            {
                return [];
            }

            public function nextSeq(): int
            {
                return 1;
            }

            public function streams(): array
            {
                return [];
            }

            public function replayAll(): array
            {
                return [];
            }
        };

        (new DebtSignal($roto, 's'))->emit(DebtSignal::HIGH_TIER_DOUBLE_CEREMONY, ['operation' => 'plugins.register']);
    }

    public function testEveryContextFieldIsBoundedNeverDumped(): void
    {
        $eventos = new InMemoryEventStore();

        (new DebtSignal($eventos, 's'))->emit(DebtSignal::SCOPE_FRAGILITY, [
            'operation' => str_repeat('x', 4000),
        ]);

        $señales = $eventos->replay(SessionStore::PREFIX . 's');
        self::assertCount(1, $señales);
        $contexto = $señales[0]->payload['context'];
        self::assertIsArray($contexto);
        self::assertSame(256, mb_strlen((string) $contexto['operation']), 'a signal names a fact, it never dumps one');
    }

    public function testTheReducerFoldsAStreamCarryingDebtSignalsWithoutError(): void
    {
        $eventos = new InMemoryEventStore();
        $store = new SessionStore($eventos);
        $store->start('s', 'build the Tareas plugin', AutonomyMode::Auto);

        $señales = new DebtSignal($eventos, 's');
        $señales->emit(DebtSignal::ADMITTED_INTENT_SKIP, ['operation' => 'notes.archive', 'tier' => 'exact_scope', 'argumentsDigest' => 'sha256:a']);
        $señales->emit(DebtSignal::HIGH_TIER_DOUBLE_CEREMONY, ['operation' => 'plugins.register', 'tier' => 'never']);
        $señales->emit(DebtSignal::SCOPE_FRAGILITY, ['operation' => 'config.set', 'grantedArgumentsDigest' => 'sha256:b', 'requestedArgumentsDigest' => 'sha256:c']);
        $señales->emit(DebtSignal::FRAMEWORK_GAP, ['summary' => 'the judge cannot verify a target that boots the judge']);

        // Unknown-type tolerance, pinned: the session keeps folding untouched — the same tolerance
        // ClosureVerdict already rides (an old reader skips what it does not know).
        $sesion = $store->load('s');
        self::assertNotNull($sesion);
        self::assertSame('build the Tareas plugin', $sesion->goal);
        self::assertSame([], $sesion->pendingTodos(), 'the fold read past four unknown-type events');
        self::assertIsArray($store->facts('s')->workState(), 'the facts projection folds past them too');
    }

    /**
     * FRAMEWORK_GAP is an admitted kind of the same channel (greenhouse decisions/0185): a model
     * that declared `HOUSE_DEBT:` under the forced choice leaves a signal shaped exactly like its
     * three siblings — a digest, never the prose.
     */
    public function testFrameworkGapIsAnAdmittedKindWithTheDocumentedShape(): void
    {
        $eventos = new InMemoryEventStore();
        $store = new SessionStore($eventos);
        $store->start('s', 'close the deadlock', AutonomyMode::Auto);

        (new DebtSignal($eventos, 's'))->emit(DebtSignal::FRAMEWORK_GAP, [
            'summary' => 'the judge cannot verify a target that boots the judge',
        ]);

        $señales = array_values(array_filter(
            $eventos->replay(SessionStore::PREFIX . 's'),
            static fn (object $evento): bool => $evento->type === DebtSignal::EVENT,
        ));
        self::assertCount(1, $señales);
        self::assertSame('framework_gap', $señales[0]->payload['signal']);
        self::assertSame(
            ['summary' => 'the judge cannot verify a target that boots the judge'],
            $señales[0]->payload['context'],
        );
    }
}
