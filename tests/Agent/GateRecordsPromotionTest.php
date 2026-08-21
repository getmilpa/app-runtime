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
