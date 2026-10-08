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

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The agent promotes with `{workspace}` and no session id — it does not know its own session. So the
 * gate, the one session-aware observer of execution, records `trial_promoted` (greenhouse
 * decisions/0069 §7) when a promotion executes, reading the promoted paths from the result. Guarded
 * so it never double-records with the handler's own session path.
 */
final class GateRecordsPromotionTest extends TestCase
{
    public function testTheGateRecordsTrialPromotedWhenAPromotionExecutes(): void
    {
        $eventos = new InMemoryEventStore();
        $gate = $this->gate($eventos);

        $gate->recorded('sandbox_promote', ['workspace' => 'w1'], '{"ok":true,"promoted":["storage/plugins.json","config/x.php"]}', true);

        $prom = $this->promotions($eventos);
        self::assertCount(1, $prom);
        self::assertSame('w1', $prom[0]['workspace']);
        self::assertSame(['storage/plugins.json', 'config/x.php'], $prom[0]['paths']);
        self::assertNotEmpty($prom[0]['diff_digest']);
    }

    public function testAFailedPromotionRecordsNothing(): void
    {
        $eventos = new InMemoryEventStore();
        $this->gate($eventos)->recorded('sandbox_promote', ['workspace' => 'w1'], '{"ok":false,"error":"stale"}', true);

        self::assertSame([], $this->promotions($eventos));
    }

    public function testAPromotionThatCarriesItsOwnSessionIsNotDoubleRecordedByTheGate(): void
    {
        $eventos = new InMemoryEventStore();
        // The handler already recorded it (session in args); the gate must not record a second one.
        $this->gate($eventos)->recorded('sandbox_promote', ['workspace' => 'w1', 'session' => 's-1'], '{"ok":true,"promoted":["a"]}', true);

        self::assertSame([], $this->promotions($eventos), 'when the caller passed a session, the handler owns the record');
    }

    public function testANonPromotionCallRecordsNoPromotion(): void
    {
        $eventos = new InMemoryEventStore();
        $this->gate($eventos)->recorded('config_set', ['key' => 'a'], '{"ok":true}', true);

        self::assertSame([], $this->promotions($eventos));
    }

    /**
     * A PROMOTION ASKED FOR AGAIN CHANGED NOTHING, AND IS NOT KEPT AS A CHANGE (greenhouse decisions/0586).
     *
     * The house answers «already promoted» to a promotion of a trial it has already applied: ok, and nothing written.
     * The gate still kept that call as a mutation, because the operation mutates. Measured by another thread walking a
     * house founded with the six admitted: the house applied each trial, the walk asked for the same promotion out of
     * habit, and the closure read «the house changed» after its last observation — the session never closed verified.
     * One promotion more than needed was enough, admissions or not.
     */
    public function testAPromotionAlreadyMadeIsKeptAsACallThatChangedNothing(): void
    {
        $eventos = new InMemoryEventStore();
        $gate = $this->gate($eventos);

        $gate->recorded('sandbox_promote', ['workspace' => 'w1'], '{"ok":true,"promoted":["src/Plugins/Blog/Blog.php"]}', true);
        $gate->recorded('sandbox_promote', ['workspace' => 'w1'], '{"ok":true,"already_promoted":true,"workspace":"w1","paths":["src/Plugins/Blog/Blog.php"],"note":"This trial was already promoted into the house; nothing was written again."}', true);

        $calls = $this->facts($eventos, 'session.tool_called');
        self::assertCount(2, $calls, 'the second call is kept: the session did ask');
        self::assertTrue($calls[0]->payload['mutating'], 'the promotion that landed is a change');
        self::assertTrue($calls[1]->payload['ok']);
        self::assertFalse($calls[1]->payload['mutating'], 'asking again for it changed nothing');
        self::assertCount(1, $this->promotions($eventos), 'one promotion landed');

        $store = new SessionStore($eventos);
        $house = \Milpa\AppRuntime\Agent\HouseObservedClosure::of($store->stream('s-1'), $store->facts('s-1'));
        self::assertSame($calls[0]->seq, $house['lastChangeSeq'], 'the house last changed when the promotion landed, not when it was asked for again');
    }

    public function testOnlyThePromotionsOwnAnswerSaysNothingChanged(): void
    {
        $eventos = new InMemoryEventStore();
        $gate = $this->gate($eventos);

        // Another operation of the trial that mutates, whose result happens to carry the same words.
        $gate->recorded('sandbox_undo', ['workspace' => 'w1'], '{"ok":true,"already_promoted":true,"restored":["src/Plugins/Blog/Blog.php"]}', true);
        // And a promotion whose answer says it is NOT so.
        $gate->recorded('sandbox_promote', ['workspace' => 'w2'], '{"ok":true,"already_promoted":false,"promoted":["a"]}', true);

        $calls = $this->facts($eventos, 'session.tool_called');
        self::assertTrue($calls[0]->payload['mutating'], 'an undo is a change, whatever its result carries');
        self::assertTrue($calls[1]->payload['mutating']);
    }

    /** @return list<Event> */
    private function facts(InMemoryEventStore $eventos, string $type): array
    {
        return array_values(array_filter($eventos->replay('agent-session:s-1'), static fn (Event $e): bool => $e->type === $type));
    }

    private function gate(InMemoryEventStore $eventos): SessionToolGate
    {
        $store = new SessionStore($eventos);
        $store->start('s-1', 'goal', AutonomyMode::Ask);
        $session = $store->load('s-1');
        self::assertNotNull($session);
        $ops = (new TrialOperations(new DIContainer(), $store, sys_get_temp_dir()))->operations();

        return new SessionToolGate($store, $session, $ops);
    }

    /** @return list<array<string, mixed>> */
    private function promotions(InMemoryEventStore $eventos): array
    {
        return array_map(
            static fn (Event $e): array => $e->payload,
            array_values(array_filter(
                $eventos->replay('agent-session:s-1'),
                static fn (Event $e): bool => $e->type === 'session.trial_promoted',
            )),
        );
    }
}
