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
 * Finishing is a way out (greenhouse decisions/0476).
 *
 * Measured: 29% of all output spent after the work was done, and with every todo closed the forced choice
 * left the model one way to obey — invent more work. With the recorded work complete, the stall notice asks
 * for the final answer and travels marked `complete`; with anything open, the choice stands as it was.
 *
 * @guards every todo done with verifiable evidence → a complete notice, marked
 *
 * @refuses to mark complete a session with a todo pending or blocked, or with no todos (a done without
 *          evidence cannot exist: the store refuses it)
 *
 * @subject-in milpa/app-runtime
 */
final class FinishingIsAWayOutTest extends TestCase
{
    public function testWithEveryTodoVerifiedTheStallAsksForTheFinalAnswer(): void
    {
        $stall = $this->stallWith(static function (SessionStore $store): void {
            $store->setTodo('s', new Todo('t1', 'Declare the page', TodoStatus::Pending));
            $store->completeTodo('s', 't1', Evidence::testPassed('e1', 'blog-served', 't1'));
            $store->setTodo('s', new Todo('t2', 'Observe it', TodoStatus::Pending));
            $store->completeTodo('s', 't2', Evidence::testPassed('e2', 'blog-observed', 't2'));
        });

        self::assertTrue($stall['complete'] ?? false);
        self::assertStringContainsString('give your final answer NOW', $stall['notice']);
        self::assertStringNotContainsString('HOUSE_DEBT', $stall['notice'], 'the forced choice is not what a finished session needs');
    }

    public function testAnythingOpenKeepsTheForcedChoice(): void
    {
        $cases = [
            'a todo pending' => static function (SessionStore $store): void {
                $store->setTodo('s', new Todo('t1', 'Declare the page', TodoStatus::Pending));
                $store->completeTodo('s', 't1', Evidence::testPassed('e1', 'blog-served', 't1'));
                $store->setTodo('s', new Todo('t2', 'Observe it', TodoStatus::Pending));
            },
            'a todo blocked' => static function (SessionStore $store): void {
                $store->setTodo('s', new Todo('t1', 'Declare the page', TodoStatus::Pending));
                $store->completeTodo('s', 't1', Evidence::testPassed('e1', 'blog-served', 't1'));
                $store->setTodo('s', new Todo('t2', 'Seed a post', TodoStatus::Blocked));
            },
            'no todos' => static function (SessionStore $store): void {
            },
        ];
        foreach ($cases as $why => $ledger) {
            $stall = $this->stallWith($ledger);
            self::assertArrayNotHasKey('complete', $stall, $why);
            self::assertStringContainsString('HOUSE_DEBT', $stall['notice'], $why);
        }
    }

    /**
     * @param callable(SessionStore): void $ledger
     *
     * @return array<string, mixed> the probe's answer at the first stall
     */
    private function stallWith(callable $ledger): array
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s', 'Build the page');
        $ledger($store);
        $probe = new SessionProgressProbe($events, 's');
        for ($step = 1; $step <= SessionProgressProbe::STALL_AFTER_CALLS; ++$step) {
            $events->append(new Event(SessionStore::PREFIX . 's', 'session.model_called', [], $events->nextSeq()));
            $answer = $probe->afterStep($step - 1);
        }
        self::assertTrue($answer['stalled'] ?? false, 'four calls without growth stall');

        return $answer;
    }
}
