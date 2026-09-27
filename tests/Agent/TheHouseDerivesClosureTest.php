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

use Milpa\Agent\Evidence;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The house derives closure (greenhouse decisions/0487).
 *
 * Measured (evidence/1017): 20–21 of 24 resident sessions never open a todo, so the house never verified
 * their closure — even when it had itself observed the promoted screen served in the house. For a session
 * with no todos the house now derives the closure from its own receipts: an observation of the house
 * serving, at or after the last change that landed there, still fresh. The epilogue (0477) opens on it.
 *
 * @guards the derived verdict, its scope and what it cites; the epilogue opening on it; reopening on a change
 *
 * @refuses a rehearsal's own receipt, a change after the observation, a stale observation, and any session
 *          with todos (its own record stays the authority)
 *
 * @subject-in milpa/app-runtime
 */
final class TheHouseDerivesClosureTest extends TestCase
{
    private InMemoryEventStore $events;

    private SessionStore $store;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s', 'Build the page a reader reads');
    }

    public function testAPromotedRehearsalObservedInTheHouseIsAClosureTheHouseDerives(): void
    {
        $this->rehearse();
        $this->promote();
        $observed = $this->observe();

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame('house_observation', $closure['scope']);
        self::assertSame(['subject' => 'blog', 'seq' => $observed], $closure['derivedFrom']['observation'] ?? null);
    }

    public function testTheRehearsalServingItselfIsNotTheHouse(): void
    {
        $this->rehearse();

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertSame(['no positive verification evidence recorded'], $closure['reasons']);
    }

    public function testAPromotionNobodyObservedIsNamed(): void
    {
        $this->rehearse();
        $this->promote();

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertContains('nothing observed served in the house', $closure['reasons']);
    }

    public function testAChangeAfterTheObservationUnverifiesIt(): void
    {
        $this->rehearse();
        $this->promote();
        $observed = $this->observe();
        $changed = $this->promote();

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertContains("the house changed at seq {$changed} after its last observation (seq {$observed})", $closure['reasons']);
    }

    public function testANewRehearsalAfterTheObservationLeavesTheHouseAsObserved(): void
    {
        $this->rehearse();
        $this->promote();
        $this->observe();
        $this->rehearse();

        self::assertTrue($this->verdict()['verified']);
    }

    public function testAForgottenScreenIsAStaleObservation(): void
    {
        $this->rehearse();
        $this->promote();
        $this->observe();
        $this->store->recordToolCall('s', 'screen_observe', ['name' => 'blog'], (string) json_encode(['ok' => true,
            'evidence' => ['predicate' => 'served', 'subject' => 'blog', 'invalidates' => true, 'environment' => ['kind' => 'house']]]));

        self::assertFalse($this->verdict()['verified']);
    }

    public function testASessionWithTodosKeepsItsOwnRecordAsTheAuthority(): void
    {
        $this->store->setTodo('s', new Todo('t1', 'Declare the page', TodoStatus::Pending));
        $this->rehearse();
        $this->promote();
        $this->observe();

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertContains('1 todo open', $closure['reasons']);
        self::assertSame('recorded_work', $closure['scope']);
    }

    public function testWithoutTheStreamTheVerdictJudgesTheRecordedWorkAlone(): void
    {
        $this->rehearse();
        $this->promote();
        $this->observe();

        $session = $this->store->load('s');
        self::assertNotNull($session);
        self::assertFalse(ClosureVerdict::derive($session, $this->store->facts('s'))['verified']);
    }

    public function testTheEpilogueOpensOnTheDerivedClosureAndAChangeReopensIt(): void
    {
        $probe = new SessionProgressProbe($this->events, 's');
        $this->rehearse();
        self::assertArrayNotHasKey('epilogue', $this->step($probe, 0) ?? []);
        $this->promote();
        self::assertArrayNotHasKey('epilogue', $this->step($probe, 1) ?? []);
        $observed = $this->observe();

        $opened = $this->step($probe, 2);
        self::assertSame(SessionProgressProbe::EPILOGUE_CALLS, $opened['epilogue'] ?? null);
        self::assertStringContainsString('the house observed «blog» served in the house', $opened['notice']);
        self::assertSame(['subject' => 'blog', 'seq' => $observed], $this->facts(SessionProgressProbe::EPILOGUE_OPENED)[0]['derivedFrom']['observation'] ?? null);

        $this->promote();
        self::assertArrayNotHasKey('epilogue', $this->step($probe, 3) ?? []);
        self::assertSame([['atStep' => 3]], $this->facts(SessionProgressProbe::EPILOGUE_REOPENED));
    }

    public function testATodoSessionStillOpensOnItsOwnRecordWithTheOriginalNotice(): void
    {
        $probe = new SessionProgressProbe($this->events, 's');
        $this->store->setTodo('s', new Todo('t1', 'Declare the page', TodoStatus::Pending));
        $this->store->completeTodo('s', 't1', Evidence::testPassed('e1', 'blog-served', 't1'));

        $opened = $this->step($probe, 0);
        self::assertStringContainsString('every todo of this session is closed', $opened['notice'] ?? '');
        self::assertSame([['atStep' => 0, 'budget' => SessionProgressProbe::EPILOGUE_CALLS]], $this->facts(SessionProgressProbe::EPILOGUE_OPENED));
    }

    /** A screen declared in a rehearsal: mutating, served — in the trial. */
    private function rehearse(): int
    {
        return $this->store->recordToolCall(
            's',
            'screen_declare',
            ['name' => 'blog'],
            (string) json_encode(['ok' => true,
            'evidence' => ['predicate' => 'served', 'subject' => 'blog', 'environment' => ['kind' => 'trial', 'workspace' => 'wabc'], 'promoted' => false]]),
            mutating: true
        );
    }

    private function promote(): int
    {
        return $this->store->recordToolCall(
            's',
            'sandbox_promote',
            ['workspace' => 'wabc'],
            (string) json_encode(['ok' => true,
            'evidence' => ['predicate' => 'promoted', 'subject' => 'wabc', 'environment' => ['kind' => 'house'], 'paths' => ['config/screens.json']]]),
            mutating: true
        );
    }

    private function observe(): int
    {
        return $this->store->recordToolCall('s', 'screen_observe', ['name' => 'blog'], (string) json_encode(['ok' => true,
            'evidence' => ['predicate' => 'served', 'subject' => 'blog', 'servedAt' => '/live/page?component=blog', 'environment' => ['kind' => 'house']]]));
    }

    /** @return array<string, mixed> */
    private function verdict(): array
    {
        $session = $this->store->load('s');
        self::assertNotNull($session);

        return ClosureVerdict::derive($session, $this->store->facts('s'), $this->store->stream('s'));
    }

    /** @return array<string, mixed>|null */
    private function step(SessionProgressProbe $probe, int $step): ?array
    {
        $this->events->append(new Event(SessionStore::PREFIX . 's', 'session.model_called', [], $this->events->nextSeq()));

        return $probe->afterStep($step);
    }

    /** @return list<array<string, mixed>> */
    private function facts(string $type): array
    {
        return array_values(array_map(
            static fn (Event $e): array => $e->payload,
            array_filter($this->events->replay(SessionStore::PREFIX . 's'), static fn (Event $e): bool => $e->type === $type),
        ));
    }
}
