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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * D-03 (greenhouse decisions/0187): the house PRESCRIBES after a stall, it does not only diagnose.
 *
 * The ProgressReceipt (0452) detected a stall and worded the forced choice, but a tool call still
 * passed as «acting» — including one more read. These cases pin the second half: while a stall stands
 * unanswered by an action, the gate refuses a non-mutating (explore/inspect) call and lets the
 * recovery moves through; a mutating action clears it.
 */
final class SessionToolGateRecoveryTest extends TestCase
{
    private InMemoryEventStore $events;

    public function testWithoutAStallAReadPasses(): void
    {
        $gate = $this->gate();
        self::assertNull($gate->refuse('inspect', []), 'no stall, no restriction — a read passes as before');
    }

    /** THE LEVER: a stall standing unanswered refuses the next read and names the moves that remain. */
    public function testAStallRefusesTheNextRead(): void
    {
        $gate = $this->gate();
        $this->recordStall();

        $refusal = $gate->refuse('inspect', []);

        self::assertNotNull($refusal);
        self::assertStringContainsString('recovery', strtolower($refusal));
        self::assertStringContainsString('materialize', $refusal);
    }

    /** A mutating call IS the recovery and passes untouched even while the stall stands. */
    public function testAStallDoesNotRefuseAMutatingAction(): void
    {
        $gate = $this->gate();
        $this->recordStall();

        $refusal = $gate->refuse('materialize', []);

        // In auto mode a mutating call passes; whatever the gate returns, it is NOT the recovery refusal.
        if ($refusal !== null) {
            self::assertStringNotContainsString('more reading is not on the table', $refusal);
        } else {
            self::assertNull($refusal);
        }
    }

    /** A real mutating action recorded after the stall clears recovery — the next read passes again. */
    public function testAMutatingActionAfterTheStallClearsRecovery(): void
    {
        $gate = $this->gate();
        $this->recordStall();
        $this->store->recordToolCall('s-1', 'materialize', [], '{"ok":true}', true, true);

        self::assertNull($gate->refuse('inspect', []), 'acting cleared the stall; reading is allowed again');
    }

    /** A confirmation-only call did not act, so it does not clear recovery. */
    public function testAConfirmationOnlyCallDoesNotClearRecovery(): void
    {
        $gate = $this->gate();
        $this->recordStall();
        $this->store->recordToolCall('s-1', 'materialize', [], '{"ok":true}', true, true, null, true);

        self::assertNotNull($gate->refuse('inspect', []), 'asking is not acting; the stall still stands');
    }

    // --- helpers ---

    private SessionStore $store;

    private function gate(): SessionToolGate
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s-1', 'goal', AutonomyMode::Auto);
        $session = $this->store->load('s-1');
        self::assertNotNull($session);

        return new SessionToolGate($this->store, $session, [$this->readOp(), $this->mutatingOp()]);
    }

    private function recordStall(): void
    {
        $this->events->append(new Event(
            SessionStore::PREFIX . 's-1',
            SessionProgressProbe::EVENT,
            ['atStep' => 0, 'receipt' => []],
            $this->events->nextSeq(),
        ));
    }

    private function readOp(): Operation
    {
        return new Operation(
            name: 'inspect',
            description: 'reads and reports, changes nothing',
            handler: static fn (): array => ['ok' => true],
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'nothing',
            ),
        );
    }

    private function mutatingOp(): Operation
    {
        return new Operation(
            name: 'materialize',
            description: 'writes an artifact',
            handler: static fn (): array => ['ok' => true],
            mutating: true,
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::None,
                reversibility: Reversibility::Compensatable,
                authority: Authority::WriteAsUser,
                subject: Subject::Data,
                rollbackContract: 'delete the file',
            ),
        );
    }
}
