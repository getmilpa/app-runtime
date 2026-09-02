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
use Milpa\Agent\Evidence;
use Milpa\Agent\ProgressReceipt;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The probe over the LIVE session stream (greenhouse decisions/0185): after each step it derives a
 * {@see ProgressReceipt} since the last checkpoint, and only a window of
 * {@see SessionProgressProbe::STALL_AFTER_CALLS} zero-growth calls makes it speak. The fifth run's
 * measured pattern — philosophize for thousands of tokens per call — is what these falsifiers keep
 * detectable, and the false positive (a productive run flagged stalled) is what they keep
 * impossible.
 *
 * @internal
 */
final class SessionProgressProbeTest extends TestCase
{
    /** Appends one minimal `session.model_called` fact — the unit the receipt counts calls by. */
    private function modelCalled(InMemoryEventStore $events, string $session): void
    {
        $events->append(new Event(
            SessionStore::PREFIX . $session,
            'session.model_called',
            ['model' => 'qwen3.8-27b'],
            $events->nextSeq(),
        ));
    }

    /** One philosophize step: a model call plus a read-only tool call — a fact, never growth. */
    private function philosophize(InMemoryEventStore $events, SessionStore $store, string $session): void
    {
        $this->modelCalled($events, $session);
        $store->recordToolCall($session, 'observe', ['target' => 'Judge'], '{"ok":true}');
    }

    public function testFourPhilosophizeCallsTriggerTheNoticeAndTheRecordedStall(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s', 'close the deadlock', AutonomyMode::Auto);
        $probe = new SessionProgressProbe($events, 's');

        for ($step = 0; $step < 3; ++$step) {
            $this->philosophize($events, $store, 's');
            self::assertNull($probe->afterStep($step), 'below the window, the probe has no opinion');
        }

        $this->philosophize($events, $store, 's');
        $answer = $probe->afterStep(3);

        self::assertNotNull($answer, 'four zero-growth calls are the measured stall');
        self::assertTrue($answer['stalled']);
        self::assertSame(4, $answer['receipt']['calls']);
        self::assertSame('stalled', $answer['receipt']['progress']);

        // The notice words the forced choice: the receipt numbers and the EXACT markers.
        self::assertStringContainsString('4', $answer['notice']);
        self::assertStringContainsString('HOUSE_DEBT:', $answer['notice']);
        self::assertStringContainsString('ABANDON:', $answer['notice']);

        // And the stall is a recorded fact of the session's own stream, not a return value only.
        $stalls = array_values(array_filter(
            $events->replay(SessionStore::PREFIX . 's'),
            static fn (object $event): bool => $event->type === SessionProgressProbe::EVENT,
        ));
        self::assertCount(1, $stalls);
        self::assertSame(3, $stalls[0]->payload['atStep']);
        self::assertSame($answer['receipt'], $stalls[0]->payload['receipt']);
    }

    /**
     * ONE AUTHORITY: the receipt in the recorded event equals the derivation over the same stream
     * and the same window — never a second count with the same name.
     */
    public function testTheRecordedReceiptEqualsTheDerivationOverTheSameStream(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s', 'close the deadlock', AutonomyMode::Auto);
        $probe = new SessionProgressProbe($events, 's');

        for ($step = 0; $step < 4; ++$step) {
            $this->philosophize($events, $store, 's');
            $answer = $probe->afterStep($step);
        }

        self::assertNotNull($answer);
        $receipt = $answer['receipt'];
        $derived = ProgressReceipt::of(
            $events->replay(SessionStore::PREFIX . 's'),
            $receipt['fromSeq'],
            $receipt['toSeq'],
        );
        self::assertSame($derived->toArray(), $receipt);
    }

    /** A productive call inside the window moves the checkpoint: the count starts over. */
    public function testAProductiveCallInsideTheWindowResetsTheCount(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s', 'build the thing', AutonomyMode::Auto);
        $probe = new SessionProgressProbe($events, 's');

        for ($step = 0; $step < 3; ++$step) {
            $this->philosophize($events, $store, 's');
            self::assertNull($probe->afterStep($step));
        }

        // The fourth call MATERIALIZES: the window is advancing, not stalled.
        $this->modelCalled($events, 's');
        $store->recordToolCall(
            's',
            'implement',
            ['plugin' => 'Demo', 'class' => 'Receipt', 'content' => '<?php'],
            '{"ok":true,"file":"src/Plugins/Demo/Services/Receipt.php"}',
            true,
            true,
        );
        $store->recordEvidence('s', Evidence::artifact('e1', 'src/Plugins/Demo/Services/Receipt.php'));
        self::assertNull($probe->afterStep(3), 'growth resets the count instead of stalling on old calls');

        // Three MORE philosophize calls stay under the window — the count really did restart.
        for ($step = 4; $step < 7; ++$step) {
            $this->philosophize($events, $store, 's');
            self::assertNull($probe->afterStep($step));
        }

        // The fourth post-reset zero-growth call stalls again.
        $this->philosophize($events, $store, 's');
        $answer = $probe->afterStep(7);
        self::assertNotNull($answer);
        self::assertSame(4, $answer['receipt']['calls'], 'the stalled window is the post-reset one');
    }

    /** After speaking once, the probe demands a fresh window before speaking again — no notice spam. */
    public function testAFiredStallResetsTheWindowInsteadOfRefiringEveryStep(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s', 'close the deadlock', AutonomyMode::Auto);
        $probe = new SessionProgressProbe($events, 's');

        for ($step = 0; $step < 4; ++$step) {
            $this->philosophize($events, $store, 's');
            $probe->afterStep($step);
        }

        $this->philosophize($events, $store, 's');
        self::assertNull($probe->afterStep(4), 'one call into the fresh window is not a stall');
    }

    /** No reachable store: no measurement, no opinion — never an invented verdict. */
    public function testANullStoreIsSilence(): void
    {
        self::assertNull((new SessionProgressProbe(null, 's'))->afterStep(0));
    }

    /** A store that fails mid-append loses the recorded fact, never the notice or the run. */
    public function testAStoreThatFailsOnAppendStillReturnsTheStallNotice(): void
    {
        $inner = new InMemoryEventStore();
        $store = new SessionStore($inner);
        $store->start('s', 'close the deadlock', AutonomyMode::Auto);

        $appendRefused = new class ($inner) implements EventStoreInterface {
            public function __construct(private readonly InMemoryEventStore $inner)
            {
            }

            public function append(Event $event): void
            {
                throw new \RuntimeException('disk full');
            }

            public function replay(string $streamId): array
            {
                return $this->inner->replay($streamId);
            }

            public function nextSeq(): int
            {
                return $this->inner->nextSeq();
            }

            public function streams(): array
            {
                return $this->inner->streams();
            }

            public function replayAll(): array
            {
                return $this->inner->replayAll();
            }
        };

        $probe = new SessionProgressProbe($appendRefused, 's');
        for ($step = 0; $step < 4; ++$step) {
            $this->philosophize($inner, $store, 's');
            $answer = $probe->afterStep($step);
        }

        self::assertNotNull($answer, 'the observation of the observation degrades; the notice does not');
        self::assertTrue($answer['stalled']);
    }

    /** A store whose replay dies is silence too: the observed run has priority over observing it. */
    public function testAStoreThatFailsOnReplayIsSilence(): void
    {
        $broken = new class () implements EventStoreInterface {
            public function append(Event $event): void
            {
            }

            public function replay(string $streamId): array
            {
                throw new \RuntimeException('corrupt log');
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

        self::assertNull((new SessionProgressProbe($broken, 's'))->afterStep(0));
    }
}
