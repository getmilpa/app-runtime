<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\EventStore\{Event,InMemoryEventStore};
use PHPUnit\Framework\TestCase;

/** A context pause must not buy another window of preparation. @internal */
final class ContextPauseProgressTest extends TestCase
{
    public function testRepeatedContextPausesPreserveBothThePartialWindowAndPendingRecovery(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s', 'Build');
        $probe = new SessionProgressProbe($events, 's');
        for ($step = 1; $step <= 8; ++$step) {
            $events->append(new Event(SessionStore::PREFIX . 's', 'session.model_called', [], $events->nextSeq()));
            $result = $probe->afterStep($step - 1);
            self::assertSame($step < 4 ? null : ($step === 8 ? 'exhausted' : 'pending'), $result['recovery'] ?? null);
            if (in_array($step, [2,4,6], true)) {
                $probe->recordContextPause();
                $events->append(new Event(SessionStore::PREFIX . 's', 'session.run_terminated', ['reason' => 'context_budget_exhausted'], $events->nextSeq()));
                $probe = new SessionProgressProbe($events, 's');
            }
        }
        self::assertSame(4, $result['receipt']['calls']);
    }

    public function testAnUnpairedContextPauseCannotSilentlyResetTheAllowance(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s', 'Build');
        $events->append(new Event(SessionStore::PREFIX . 's', 'session.run_terminated', ['reason' => 'context_budget_exhausted'], $events->nextSeq()));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('progress checkpoint');
        new SessionProgressProbe($events, 's');
    }

    public function testAnotherTerminationKeepsTheExistingNewRunBehavior(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s', 'Build');
        $probe = new SessionProgressProbe($events, 's');
        for ($i = 0; $i < 4; ++$i) {
            $events->append(new Event(SessionStore::PREFIX . 's', 'session.model_called', [], $events->nextSeq()));
            $probe->afterStep($i);
        }
        $probe->recordContextPause();
        $events->append(new Event(SessionStore::PREFIX . 's', 'session.run_terminated', ['reason' => 'steps_exhausted'], $events->nextSeq()));
        $probe = new SessionProgressProbe($events, 's');
        $events->append(new Event(SessionStore::PREFIX . 's', 'session.model_called', [], $events->nextSeq()));
        self::assertNull($probe->afterStep(0));
    }

    public function testMalformedCheckpointsCannotRenewTheWindow(): void
    {
        foreach ([['checkpointSeq' => -1, 'recovering' => false], ['checkpointSeq' => 999, 'recovering' => false],
            ['checkpointSeq' => 0, 'recovering' => 'false'], ['checkpointSeq' => null, 'recovering' => true]] as $payload) {
            $events = new InMemoryEventStore();
            (new SessionStore($events))->start('s', 'Build');
            $events->append(new Event(SessionStore::PREFIX . 's', 'session.progress_context_checkpoint', $payload, $events->nextSeq()));
            $events->append(new Event(SessionStore::PREFIX . 's', 'session.run_terminated', ['reason' => 'context_budget_exhausted'], $events->nextSeq()));
            try {
                new SessionProgressProbe($events, 's');
                self::fail('An invalid checkpoint must not reset the progress quota.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('progress checkpoint', $error->getMessage());
            }
        }
    }

    public function testAnUnavailableStoreCannotClaimADurablePause(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SessionProgressProbe(null, 's'))->recordContextPause();
    }
}
