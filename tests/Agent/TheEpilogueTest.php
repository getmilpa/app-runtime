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

use Milpa\Agent\Evidence;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The epilogue (greenhouse decisions/0477; it supersedes the complete stall notice of 0476 in this probe).
 *
 * When every todo of the session is done with verifiable evidence the house verified the work phase closed:
 * the epilogue opens, announced once, with {@see SessionProgressProbe::EPILOGUE_CALLS} model calls to write
 * the output, and the count runs down to 0. An open todo reopens the work phase. Each change is a fact.
 *
 * @guards opening on verified closure, the countdown, the recorded facts, and reopening
 *
 * @refuses to open with a todo pending or blocked, or with no todos
 *
 * @subject-in milpa/app-runtime
 */
final class TheEpilogueTest extends TestCase
{
    private InMemoryEventStore $events;

    private SessionStore $store;

    public function testAVerifiedClosureOpensTheEpilogueAndItsBudgetRunsDown(): void
    {
        $probe = $this->probe(closed: true);

        $first = $this->step($probe, 0);
        self::assertSame(SessionProgressProbe::EPILOGUE_CALLS, $first['epilogue'] ?? null);
        self::assertStringContainsString('You are writing the output now', $first['notice']);
        self::assertFalse($first['stalled']);
        self::assertSame(1, $this->step($probe, 1)['epilogue'] ?? null);
        self::assertSame('', $this->step($probe, 1)['notice'] ?? null, 'announced once');
        self::assertSame(0, $this->step($probe, 2)['epilogue'] ?? null);
        self::assertSame([['atStep' => 0, 'budget' => SessionProgressProbe::EPILOGUE_CALLS]], $this->facts(SessionProgressProbe::EPILOGUE_OPENED));
    }

    public function testAnOpenTodoReopensTheWorkPhase(): void
    {
        $probe = $this->probe(closed: true);
        $this->step($probe, 0);
        $this->store->setTodo('s', new Todo('t3', 'Seed a post the human asked for', TodoStatus::Pending));

        self::assertArrayNotHasKey('epilogue', $this->step($probe, 1) ?? []);
        self::assertSame([['atStep' => 1]], $this->facts(SessionProgressProbe::EPILOGUE_REOPENED));
    }

    public function testNothingOpensWithoutAVerifiedClosure(): void
    {
        foreach (['pending' => TodoStatus::Pending, 'blocked' => TodoStatus::Blocked, 'no todos' => null] as $why => $status) {
            $probe = $this->probe(closed: false, open: $status);
            for ($step = 0; $step < SessionProgressProbe::STALL_AFTER_CALLS; ++$step) {
                $answer = $this->step($probe, $step);
                self::assertArrayNotHasKey('epilogue', $answer ?? [], $why);
            }
            self::assertSame([], $this->facts(SessionProgressProbe::EPILOGUE_OPENED), $why);
        }
    }

    private function probe(bool $closed, ?TodoStatus $open = null): SessionProgressProbe
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s', 'Build the page');
        if ($closed || $open !== null) {
            $this->store->setTodo('s', new Todo('t1', 'Declare the page', TodoStatus::Pending));
            $this->store->completeTodo('s', 't1', Evidence::testPassed('e1', 'blog-served', 't1'));
        }
        if ($open !== null) {
            $this->store->setTodo('s', new Todo('t2', 'Seed a post', $open));
        }

        return new SessionProgressProbe($this->events, 's');
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
