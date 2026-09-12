<?php

/**
 * This file is part of milpa/app-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @internal */
final class BoundedProgressProbeTest extends TestCase
{
    /** @return iterable<string, array{bool, string, bool}> */
    public static function nonAdvancingCalls(): iterable
    {
        yield 'dispatch failure' => [false, '{"ok":true}', false];
        yield 'inner failure' => [true, '{"ok":false}', false];
        yield 'confirmation only' => [true, '{"ok":true}', true];
    }

    /** Failed or unexecuted mutations cannot reset either window. */
    #[DataProvider('nonAdvancingCalls')]
    public function testAnotherWindowWithoutProgressExhaustsRecovery(bool $ok, string $result, bool $confirmation): void
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('s', 'Build UI', AutonomyMode::Auto);
        $probe = new SessionProgressProbe($events, 's');
        for ($step = 1; $step <= 8; ++$step) {
            $events->append(new Event(SessionStore::PREFIX . 's', 'session.model_called', [], $events->nextSeq()));
            $sessions->setPlan('s', 'Prepare UI ' . $step);
            $sessions->recordToolCall('s', 'implement', [], $result, $ok, true, awaitingConfirmation: $confirmation);
            $answer = $probe->afterStep($step - 1);
            self::assertSame($step < 4 ? null : ($step === 8 ? 'exhausted' : 'pending'), $answer['recovery'] ?? null);
        }
        self::assertSame(4, $answer['receipt']['calls']);
        self::assertSame('stalled', $answer['receipt']['progress']);
        self::assertCount(2, array_filter($sessions->stream('s'), static fn ($e) => $e->type === SessionProgressProbe::EVENT));
    }

    /** Progress at the last permitted step wins over expiry and arms a fresh window. */
    public function testProgressAtTheRecoveryBoundaryAndANewStall(): void
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('s', 'Build UI', AutonomyMode::Auto);
        $probe = new SessionProgressProbe($events, 's');
        for ($step = 1; $step <= 16; ++$step) {
            $events->append(new Event(SessionStore::PREFIX . 's', 'session.model_called', [], $events->nextSeq()));
            if ($step === 8) {
                $sessions->recordToolCall('s', 'implement', [], '{"ok":true}', true, true);
            }
            $answer = $probe->afterStep($step - 1);
            $expected = match (true) {
                $step < 4, $step > 8 && $step < 12 => null,
                $step === 8 => 'recovered',
                $step === 16 => 'exhausted',
                default => 'pending',
            };
            self::assertSame($expected, $answer['recovery'] ?? null, 'step ' . $step);
            if ($step === 8) {
                self::assertFalse($answer['stalled']);
                self::assertSame('advancing', $answer['receipt']['progress']);
            }
        }
        self::assertCount(3, array_filter($sessions->stream('s'), static fn ($e) => $e->type === SessionProgressProbe::EVENT));
    }
}
