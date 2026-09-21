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
use Milpa\Agent\ProgressReceipt;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\ExplorationReceipt;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @internal */
final class ExplorationReceiptTest extends TestCase
{
    private InMemoryEventStore $events;
    private SessionStore $sessions;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->sessions = new SessionStore($this->events);
        $this->sessions->start('s', 'Build a screen', AutonomyMode::Auto);
    }

    /** @param array<string, mixed> $overrides */
    private function round(string $content, array $overrides = []): void
    {
        $this->events->append(new Event(SessionStore::PREFIX . 's', 'session.model_called', [], $this->events->nextSeq()));
        $this->events->append(new Event(SessionStore::PREFIX . 's', 'session.tool_called', array_replace([
            'tool' => 'source_read', 'arguments' => ['path' => 'example.php'], 'ok' => true,
            'mutating' => false, 'awaitingConfirmation' => false,
            'result' => json_encode(['ok' => true, 'content' => $content], \JSON_THROW_ON_ERROR),
        ], $overrides), $this->events->nextSeq()));
    }

    public function testNovelReadsHaveAFiniteAllowanceWithoutBecomingDelivery(): void
    {
        $probe = new SessionProgressProbe($this->events, 's');
        for ($step = 1; $step <= ExplorationReceipt::CALL_LIMIT; ++$step) {
            $this->round('contract ' . $step);
            $answer = $probe->afterStep($step);
            if ($step < ExplorationReceipt::CALL_LIMIT) {
                self::assertNull($answer);
            } else {
                self::assertSame('pending', $answer['recovery']);
                self::assertSame('stalled', $answer['receipt']['progress']);
            }
            $stream = $this->sessions->stream('s');
            self::assertSame('stalled', ProgressReceipt::of($stream, 0, PHP_INT_MAX)->progress);
        }
        self::assertCount(8, array_filter($this->sessions->stream('s'), static fn ($e) => $e->type === 'session.exploration_observed'));
        for ($step = 13; $step <= 16; ++$step) {
            $this->round('another ' . $step);
            $answer = $probe->afterStep($step);
        }
        self::assertSame('exhausted', $answer['recovery']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function refusedNovelty(): iterable
    {
        yield 'dispatch error' => [['ok' => false]];
        yield 'confirmation' => [['awaitingConfirmation' => true]];
        yield 'inner error' => [['result' => '{"ok":false,"content":"new"}']];
        yield 'empty' => [['result' => '{"ok":true,"content":"  "}']];
        yield 'broken envelope' => [['result' => '{"ok":true,"content":']];
        yield 'unrecognized tool' => [['tool' => 'observe']];
        yield 'model assertion' => [['result' => '{"ok":true,"newFacts":100}']];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('refusedNovelty')]
    public function testUnprovenReadsCannotPostponeTheOriginalStall(array $overrides): void
    {
        $probe = new SessionProgressProbe($this->events, 's');
        for ($step = 1; $step <= 4; ++$step) {
            $this->round('new ' . $step, $overrides);
            $answer = $probe->afterStep($step);
        }
        self::assertTrue($answer['stalled']);
        self::assertSame(4, $answer['receipt']['calls']);
    }

    public function testMetadataAndArgumentChangesCannotRenewRepeatedContent(): void
    {
        $probe = new SessionProgressProbe($this->events, 's');
        for ($step = 1; $step <= 4; ++$step) {
            $this->round('same contract', ['arguments' => ['path' => 'alias-' . $step],
                'result' => json_encode(['ok' => true, 'content' => 'same contract', 'cursor' => $step], \JSON_THROW_ON_ERROR)]);
            $answer = $probe->afterStep($step);
        }
        self::assertTrue($answer['stalled']);
    }

    public function testTheRealGateAllowsDiscoveryThenRefusesReadsAtTheCeiling(): void
    {
        $session = $this->sessions->load('s');
        self::assertNotNull($session);
        $gate = new SessionToolGate($this->sessions, $session, [
            new Operation('source_read', 'Read source', static fn (): array => ['ok' => true], effects: EffectProfile::readOnly()),
        ]);
        $probe = new SessionProgressProbe($this->events, 's');
        for ($step = 1; $step <= 12; ++$step) {
            self::assertNull($gate->refuse('source_read', []));
            $this->round('contract ' . $step);
            $probe->afterStep($step);
        }
        self::assertStringContainsString('Progress recovery:', $gate->refuse('source_read', []));
    }

    public function testSkillsAndSourcePagesShareContentNoveltyAcrossTools(): void
    {
        $this->round('page', ['tool' => 'source_page']);
        self::assertTrue(ExplorationReceipt::of($this->sessions->stream('s'))->permitsExploration());
        $this->round('page');
        self::assertFalse(ExplorationReceipt::of($this->sessions->stream('s'))->permitsExploration());
        $this->round('', ['tool' => 'skill_load', 'result' => '{"ok":true,"body":"a skill"}']);
        self::assertTrue(ExplorationReceipt::of($this->sessions->stream('s'))->permitsExploration());
        $this->round('', ['tool' => 'skill_load', 'result' => '{"ok":true,"body":"a skill","name":"another name"}']);
        self::assertFalse(ExplorationReceipt::of($this->sessions->stream('s'))->permitsExploration());
        $this->round('changed content', ['mutating' => true]);
        self::assertFalse(ExplorationReceipt::of($this->sessions->stream('s'))->permitsExploration());
    }

    public function testContinuationPreservesAllowanceAndObservationIsIdempotent(): void
    {
        $probe = new SessionProgressProbe($this->events, 's');
        for ($step = 1; $step <= 11; ++$step) {
            $this->round('contract ' . $step);
            self::assertNull($probe->afterStep($step));
        }
        self::assertNull($probe->afterStep(11));
        self::assertCount(8, array_filter($this->sessions->stream('s'), static fn ($e) => $e->type === 'session.exploration_observed'));
        $probe->recordContextPause();
        $this->events->append(new Event(
            SessionStore::PREFIX . 's',
            'session.run_terminated',
            ['reason' => 'context_budget_exhausted'],
            $this->events->nextSeq()
        ));
        $resumed = new SessionProgressProbe($this->events, 's');
        $this->round('contract twelve');
        self::assertSame('pending', $resumed->afterStep(1)['recovery']);
        self::assertFalse(ExplorationReceipt::of($this->sessions->stream('s'))->permitsExploration());
    }

    public function testDiscoveryCannotReopenRecoveryButAMaterialChangeCanClearIt(): void
    {
        $probe = new SessionProgressProbe($this->events, 's');
        for ($step = 1; $step <= 4; ++$step) {
            $this->round('same');
            $answer = $probe->afterStep($step);
        }
        self::assertSame('pending', $answer['recovery']);
        $this->round('different');
        self::assertFalse(ExplorationReceipt::of($this->sessions->stream('s'))->permitsExploration());
        self::assertSame('pending', $probe->afterStep(5)['recovery']);
        $this->sessions->recordToolCall('s', 'implement', [], '{"ok":true}', true, true);
        self::assertSame('recovered', $probe->afterStep(6)['recovery']);
    }
}
