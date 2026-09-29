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
use Milpa\AppRuntime\Agent\DeliveryExpectation;
use Milpa\AppRuntime\Agent\DeliveryScope;
use Milpa\AppRuntime\Agent\LegClosure;
use Milpa\AppRuntime\Agent\ObservedExecutor;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The epilogue opens on the verdict the final answer records — the same function, not a second reading (greenhouse
 * decisions/0517).
 *
 * Before: a session with todos opened its epilogue when every todo was done with evidence, while the final answer
 * recorded {@see \Milpa\AppRuntime\Agent\ClosureVerdict}, which also asks the artifacts, the judges, the last test run
 * and what the house observed (decisions/0509). The epilogue could announce «the work phase is closed» on a verdict
 * that then said `verified: false`.
 *
 * @guards the epilogue opens exactly when the final answer's verdict is verified, and names what it closed on: the
 *         house's observation when the house observed what landed, the session's own record otherwise
 *
 * @refuses to open on done todos beside a rehearsed artifact nobody verified, a judge that recorded red, a test whose
 *          last run is red, or a declared delivery whose evidence only the natural end reads
 *
 * @subject-in milpa/app-runtime
 */
final class TheEpilogueReadsTheVerdictTest extends TestCase
{
    private InMemoryEventStore $events;

    private SessionStore $store;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s', 'Build the blog a visitor reads at /blog');
    }

    public function testDoneTodosBesideARehearsalNobodyVerifiedOpenNoEpilogue(): void
    {
        $this->plan(['t1' => 'Scaffold the Blog plugin']);
        $this->rehearse('BlogController');
        $this->done('t1', Evidence::operationOk('e1', 'implement'));

        self::assertNotContains('epilogue', array_keys($this->step(0) ?? []));
        self::assertFalse($this->finalVerdict()['verified']);
        self::assertContains('artifact BlogController has no current verification', $this->finalVerdict()['reasons']);
    }

    public function testDoneTodosBesideARedJudgeOpenNoEpilogue(): void
    {
        $this->plan(['t1' => 'Scaffold the Blog plugin']);
        $this->store->recordToolCall('s', 'make', ['what' => 'controller', 'plugin' => 'Blog', 'name' => 'BlogController'], (string) json_encode([
            'ran_in_trial' => true, 'applied' => false, 'workspace' => 'wabc', 'changed' => [],
            'ok' => true, 'verify' => ['ok' => false, 'error' => 'php -l failed'],
        ]), mutating: true);
        $this->promote();
        $this->done('t1', Evidence::operationOk('e1', 'sandbox_promote'));

        self::assertNotContains('epilogue', array_keys($this->step(0) ?? []));
        self::assertContains('judge make recorded red for BlogController', $this->finalVerdict()['reasons']);
    }

    public function testATodoOnATestWhoseLastRunIsRedOpensNoEpilogue(): void
    {
        $this->plan(['t1' => 'Serve GET /blog']);
        $this->rehearse('BlogController');
        $this->promote();
        $this->test('tests/Plugins/Blog', green: true);
        $this->done('t1', Evidence::testPassed('e1', 'tests/Plugins/Blog'));
        $this->test('tests/Plugins/Blog', green: false);

        self::assertNotContains('epilogue', array_keys($this->step(0) ?? []));
        self::assertFalse($this->finalVerdict()['verified']);
    }

    public function testTheHouseObservingWhatLandedOpensTheEpilogueOnItsObservation(): void
    {
        $this->plan(['t1' => 'Scaffold the Blog plugin', 't2' => 'Serve GET /blog']);
        $this->rehearse('BlogController');
        $promoted = $this->promote();
        $this->done('t1', Evidence::operationOk('e1', 'sandbox_promote'));
        $this->done('t2', Evidence::testPassed('e2', 'tests/Plugins/Blog'));
        $this->test('tests/Plugins/Blog', green: true);

        $step = $this->step(0);

        self::assertSame(SessionProgressProbe::EPILOGUE_CALLS, $step['epilogue'] ?? null);
        self::assertStringContainsString('the house observed «/blog» served', (string) ($step['notice'] ?? ''));
        $verdict = $this->finalVerdict();
        self::assertTrue($verdict['verified'], implode('; ', $verdict['reasons']));
        self::assertSame([['atStep' => 0, 'budget' => SessionProgressProbe::EPILOGUE_CALLS, 'derivedFrom' => $verdict['derivedFrom']]], $this->facts(SessionProgressProbe::EPILOGUE_OPENED));
        self::assertSame($promoted, $verdict['derivedFrom']['lastChangeSeq']);
    }

    public function testTodosClosedOnTheirOwnRecordOpenTheEpilogueAsTheyAlwaysDid(): void
    {
        $this->plan(['t1' => 'Answer the question']);
        $this->done('t1', Evidence::testPassed('e1', 'blog-served'));

        $step = $this->step(0);

        self::assertSame(SessionProgressProbe::EPILOGUE_CALLS, $step['epilogue'] ?? null);
        self::assertStringContainsString('every todo of this session is closed with verifiable evidence', (string) ($step['notice'] ?? ''));
        self::assertSame([['atStep' => 0, 'budget' => SessionProgressProbe::EPILOGUE_CALLS]], $this->facts(SessionProgressProbe::EPILOGUE_OPENED));
        self::assertTrue($this->finalVerdict()['verified']);
    }

    public function testADeclaredDeliveryOpensNoEpilogueBetweenSteps(): void
    {
        DeliveryScope::record($this->events, 's', DeliveryScopeTest::scope(), ObservedExecutor::unknown());
        $this->plan(['t1' => 'Answer the question']);
        $this->done('t1', Evidence::testPassed('e1', 'blog-served'));

        $session = $this->store->load('s');
        self::assertNotNull($session);
        self::assertNull(LegClosure::betweenSteps($session, $this->store->stream('s')), 'only the natural end reads its evidence');
        self::assertNotContains('epilogue', array_keys($this->step(0) ?? []));
        self::assertArrayHasKey('delivery', $this->finalVerdict(), 'the final answer judges it as a delivery');
    }

    public function testADeliveryExpectationIsJudgedTheSameBetweenStepsAndAtTheEnd(): void
    {
        DeliveryExpectation::record($this->events, 's', DeliveryExpectationTest::target(), ObservedExecutor::unknown());
        $this->plan(['t1' => 'Answer the question']);
        $this->done('t1', Evidence::testPassed('e1', 'blog-served'));
        $session = $this->store->load('s');
        self::assertNotNull($session);

        $between = LegClosure::betweenSteps($session, $this->store->stream('s'));

        self::assertSame('awaiting_candidate', $between['bindingState'] ?? null, 'it binds no candidate yet: its verdict needs no fresh read');
        self::assertEquals($this->finalVerdict(), $between);
        self::assertFalse($between['verified']);
        self::assertNotContains('epilogue', array_keys($this->step(0) ?? []));
    }

    public function testAVerdictThatCannotBeReadIsNotVerifiedAndOpensNothing(): void
    {
        DeliveryScope::record($this->events, 's', DeliveryScopeTest::scope(), ObservedExecutor::unknown());
        $this->plan(['t1' => 'Answer the question']);
        $this->done('t1', Evidence::testPassed('e1', 'blog-served'));
        $session = $this->store->load('s');
        self::assertNotNull($session);

        $verdict = LegClosure::atTheEnd($session, $this->store->stream('s'), static function (array $contract): array {
            throw new \RuntimeException('the house could not be read');
        });

        self::assertFalse($verdict['verified'], 'the todos alone would have said yes');
        self::assertArrayNotHasKey('delivery', $verdict);
    }

    /**
     * The construction, stated as a property: at every step of every fixture above, the epilogue is open exactly when
     * the verdict the final answer would record right there is verified.
     */
    public function testAtEveryStepTheEpilogueIsOpenExactlyWhenTheFinalVerdictIsVerified(): void
    {
        $fixtures = [
            'rehearsal' => fn () => [$this->plan(['t1' => 'x']), $this->rehearse('A'), $this->done('t1', Evidence::operationOk('e1', 'implement'))],
            'landed' => fn () => [$this->plan(['t1' => 'x']), $this->rehearse('A'), $this->promote(), $this->done('t1', Evidence::operationOk('e1', 'sandbox_promote'))],
            'record' => fn () => [$this->plan(['t1' => 'x']), $this->done('t1', Evidence::testPassed('e1', 'r'))],
            'reopened' => fn () => [$this->plan(['t1' => 'x']), $this->done('t1', Evidence::testPassed('e1', 'r')), $this->plan(['t2' => 'y'])],
            'red' => fn () => [$this->plan(['t1' => 'x']), $this->test('p', green: true), $this->done('t1', Evidence::testPassed('e1', 'p')), $this->test('p', green: false)],
        ];
        $opened = 0;
        foreach ($fixtures as $name => $write) {
            $this->setUp();
            $probe = new SessionProgressProbe($this->events, 's');
            $write();
            $step = $this->stepWith($probe, 0);
            $verified = $this->finalVerdict()['verified'];
            self::assertSame($verified, isset($step['epilogue']), "{$name}: epilogue " . (isset($step['epilogue']) ? 'open' : 'closed') . ', verdict ' . var_export($verified, true));
            $opened += (int) $verified;
        }
        self::assertSame(2, $opened, 'the fixtures hold both answers');
    }

    // --- helpers ---

    /** @param array<string, string> $todos */
    private function plan(array $todos): int
    {
        foreach ($todos as $id => $text) {
            $this->store->setTodo('s', new Todo($id, $text, TodoStatus::Pending));
        }

        return 0;
    }

    private function done(string $todo, Evidence $evidence): int
    {
        $this->store->completeTodo('s', $todo, $evidence);

        return 0;
    }

    private function rehearse(string $class): int
    {
        return $this->store->recordToolCall('s', 'implement', ['plugin' => 'Blog', 'class' => $class], (string) json_encode([
            'ran_in_trial' => true, 'applied' => false, 'workspace' => 'wabc', 'changed' => ["src/Plugins/Blog/{$class}.php" => 'added'],
            'output' => ['ok' => true],
        ]), mutating: true);
    }

    private function promote(): int
    {
        return $this->store->recordToolCall('s', 'sandbox_promote', ['workspace' => 'wabc'], (string) json_encode([
            'ok' => true,
            'promoted' => ['src/Plugins/Blog/BlogController.php'],
            'evidence' => ['predicate' => 'promoted', 'subject' => 'wabc', 'environment' => ['kind' => 'house']],
            'observed' => [['predicate' => 'served', 'route' => 'GET /blog', 'subject' => '/blog', 'status' => 200,
                'environment' => ['kind' => 'house'], 'bytes' => 42, 'sha256' => str_repeat('a', 64)]],
        ]), mutating: true);
    }

    private function test(string $path, bool $green): int
    {
        $result = $green
            ? ['ran_in_trial' => true, 'applied' => false, 'workspace' => 'wt', 'changed' => [],
                'output' => ['ok' => true, 'ran' => true, 'tests' => 3, 'assertions' => 6, 'failures' => 0, 'errors' => 0]]
            : ['schema' => 'milpa.trial-test-failure/v1', 'ok' => false, 'ran_in_trial' => true, 'applied' => false,
                'summary' => ['counts' => ['ran' => true, 'tests' => 3, 'assertions' => 5, 'failures' => 1, 'errors' => 0]]];

        return $this->store->recordToolCall('s', 'test', ['path' => $path], (string) json_encode($result), ok: $green, mutating: true);
    }

    /** @return array<string, mixed>|null */
    private function step(int $step): ?array
    {
        return $this->stepWith(new SessionProgressProbe($this->events, 's'), $step);
    }

    /** @return array<string, mixed>|null */
    private function stepWith(SessionProgressProbe $probe, int $step): ?array
    {
        $this->events->append(new Event(SessionStore::PREFIX . 's', 'session.model_called', [], $this->events->nextSeq()));

        return $probe->afterStep($step);
    }

    /**
     * What the final answer records at this point: the leg's closure, with a delivery's evidence read as absent.
     *
     * @return array<string, mixed>
     */
    private function finalVerdict(): array
    {
        $session = $this->store->load('s');
        self::assertNotNull($session);

        return LegClosure::atTheEnd($session, $this->store->stream('s'), static fn (array $contract): array => []);
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
