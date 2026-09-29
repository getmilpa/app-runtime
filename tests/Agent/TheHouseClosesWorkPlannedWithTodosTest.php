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
use Milpa\AppRuntime\Agent\SessionBookkeeping;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The house closes work planned with todos (greenhouse decisions/0509).
 *
 * Measured (evidence/1036): the resident planned 8 todos, closed every one with evidence the claim judge
 * accepted, and the house observed /blog served after its last promotion — and the verdict stayed open on
 * artifacts written in trials and on a refused call, because the house's observation was only read for
 * sessions that never opened a todo. Now the house's observation of what landed stands beside the session's
 * own record; neither replaces the other.
 *
 * @guards todos closed with evidence + a change that landed + the house observing it served after → verified,
 *         and what never landed (rehearsals, a refused call) does not bind; the claim reads the stream once
 *
 * @refuses nothing landed; a todo open or done without evidence; a judge that recorded red; a todo resting on a
 *          test whose last run is red — in the verdict and at the claim door
 *
 * @subject-in milpa/app-runtime
 */
final class TheHouseClosesWorkPlannedWithTodosTest extends TestCase
{
    private InMemoryEventStore $events;

    private SessionStore $store;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s', 'Build the blog a visitor reads at /blog');
    }

    public function testTodosClosedAndTheHouseObservingWhatLandedIsVerified(): void
    {
        $this->plan(['t1' => 'Scaffold the Blog plugin', 't2' => 'Serve GET /blog']);
        $this->rehearse('BlogController');
        $promoted = $this->promote();
        $this->done('t1', Evidence::operationOk('e1', 'sandbox_promote'));
        $this->done('t2', Evidence::testPassed('e2', 'tests/Plugins/Blog'));
        $this->test('tests/Plugins/Blog', green: true);

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame('recorded_work_and_house_observation', $closure['scope']);
        self::assertSame(['subject' => '/blog', 'seq' => $promoted], $closure['derivedFrom']['observation'] ?? null);
        self::assertSame($promoted, $closure['derivedFrom']['lastChangeSeq'] ?? null);
    }

    public function testTheSameWorkWithoutTheHouseObservationStaysOpenOnWhatWasOnlyRehearsed(): void
    {
        // CONTROL: the rule of 0487 §1 as it always was — the record alone, and a rehearsed artifact binds.
        $this->plan(['t1' => 'Scaffold the Blog plugin']);
        $this->rehearse('BlogController');
        $this->done('t1', Evidence::operationOk('e1', 'implement'));

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertSame('recorded_work', $closure['scope']);
        self::assertContains('artifact BlogController has no current verification', $closure['reasons']);
    }

    public function testTodosThatSayDoneWhileNothingLandedAreNotVerified(): void
    {
        // The house observed something served, but nothing this session did ever landed in it.
        $this->plan(['t1' => 'Build the blog']);
        $this->rehearse('BlogController');
        $this->store->recordToolCall('s', 'screen_observe', ['name' => 'home'], (string) json_encode(['ok' => true,
            'evidence' => ['predicate' => 'served', 'subject' => 'home', 'environment' => ['kind' => 'house']]]));
        $this->done('t1', Evidence::operationOk('e1', 'implement'));

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertSame('recorded_work', $closure['scope'], 'the house observed nothing that LANDED: it does not speak');
        self::assertContains('artifact BlogController has no current verification', $closure['reasons']);
    }

    public function testAnOpenTodoStillKeepsItOpenBesideTheHouse(): void
    {
        // The house never closes a todo for the session (decisions/0509 §1). A done WITHOUT evidence cannot be
        // written through the store any more (decisions/0183); its reason is unchanged code, guarded elsewhere.
        $this->plan(['t1' => 'Scaffold', 't2' => 'Serve']);
        $this->rehearse('BlogController');
        $this->promote();
        $this->done('t1', Evidence::operationOk('e1', 'sandbox_promote'));

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertSame(['1 todo open'], $closure['reasons']);
        self::assertSame('recorded_work_and_house_observation', $closure['scope']);
    }

    public function testAJudgeThatRecordedRedStillBlocksBesideTheHouse(): void
    {
        $this->plan(['t1' => 'Scaffold the Blog plugin']);
        $this->store->recordToolCall('s', 'make', ['what' => 'controller', 'plugin' => 'Blog', 'name' => 'BlogController'], (string) json_encode([
            'ran_in_trial' => true, 'applied' => false, 'workspace' => 'wabc', 'changed' => [],
            'ok' => true, 'verify' => ['ok' => false, 'error' => 'php -l failed'],
        ]), mutating: true);
        $this->promote();
        $this->done('t1', Evidence::operationOk('e1', 'sandbox_promote'));

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertContains('judge make recorded red for BlogController', $closure['reasons']);
    }

    public function testATodoRestingOnATestWhoseLastRunIsRedIsNotDone(): void
    {
        $this->plan(['t1' => 'Serve GET /blog']);
        $this->rehearse('BlogController');
        $this->promote();
        $this->test('tests/Plugins/Blog', green: true);
        $this->done('t1', Evidence::testPassed('e1', 'tests/Plugins/Blog'));
        $red = $this->test('tests/Plugins/Blog', green: false);

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertContains("todo t1 rests on «tests/Plugins/Blog», whose last test run is red (seq {$red})", $closure['reasons']);
    }

    public function testARedRunFixedGreenAfterwardsIsNotHeldAgainstTheTodo(): void
    {
        $this->plan(['t1' => 'Serve GET /blog']);
        $this->rehearse('BlogController');
        $this->promote();
        $this->test('tests/Plugins/Blog', green: false);
        $this->test('tests/Plugins/Blog', green: true);
        $this->done('t1', Evidence::testPassed('e1', 'tests/Plugins/Blog'));

        self::assertTrue($this->verdict()['verified']);
    }

    public function testATestCallThatNeverRanIsNotAJudge(): void
    {
        $this->plan(['t1' => 'Serve GET /blog']);
        $this->rehearse('BlogController');
        $this->promote();
        $this->test('tests/Plugins/Blog', green: true);
        $this->done('t1', Evidence::testPassed('e1', 'tests/Plugins/Blog'));
        $this->store->recordToolCall('s', 'test', ['path' => 'tests/Plugins/Blog'], 'Scoped test requires a path under tests/Plugins/<Plugin>.', ok: false, mutating: true);
        $this->store->recordToolCall('s', 'test', ['path' => 'tests/Plugins/Blog'], (string) json_encode(
            ['ok' => false, 'ran' => false, 'failures' => 0, 'errors' => 1, 'error' => 'phpunit is not installed'],
        ), ok: false, mutating: true);

        self::assertTrue($this->verdict()['verified'], 'a refused test call, or one whose suite never ran, says nothing about the suite');
    }

    public function testARefusedCallThatNeverLandedDoesNotBindTheClosure(): void
    {
        // 1036, seq 49: implement HelloPlugin mode=reset was refused by scope; nothing ran, nothing landed.
        $this->plan(['t1' => 'Build the blog']);
        $this->store->recordToolCall(
            's',
            'implement',
            ['plugin' => 'HelloPlugin', 'class' => 'HelloPlugin', 'content' => '', 'mode' => 'reset'],
            "Missing required permission 'plugins.HelloPlugin:write' for plugin 'HelloPlugin'.",
            ok: false,
            mutating: true
        );
        $this->rehearse('BlogController');
        $this->promote();
        $this->done('t1', Evidence::operationOk('e1', 'sandbox_promote'));

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertNotContains('artifact HelloPlugin has no current verification', $closure['reasons']);
    }

    public function testWithoutTheHouseTheRefusedCallStillBindsAsItAlwaysDid(): void
    {
        // CONTROL for the one above: the refusal is dropped by the house's observation, not by a new reading of it.
        $this->plan(['t1' => 'Build the blog']);
        $this->store->recordToolCall(
            's',
            'implement',
            ['plugin' => 'HelloPlugin', 'class' => 'HelloPlugin', 'content' => '', 'mode' => 'reset'],
            "Missing required permission 'plugins.HelloPlugin:write' for plugin 'HelloPlugin'.",
            ok: false,
            mutating: true
        );
        $this->done('t1', Evidence::operationOk('e1', 'implement'));

        self::assertContains('artifact HelloPlugin has no current verification', $this->verdict()['reasons']);
    }

    public function testCodeWrittenStraightIntoTheHouseStillNeedsItsVerification(): void
    {
        $this->plan(['t1' => 'Build the blog']);
        $this->store->recordToolCall('s', 'implement', ['plugin' => 'Blog', 'class' => 'BlogController'], (string) json_encode(['ok' => true]), mutating: true);
        $this->promote();
        $this->done('t1', Evidence::operationOk('e1', 'sandbox_promote'));

        self::assertContains('artifact BlogController has no current verification', $this->verdict()['reasons']);
    }

    public function testAChangeAfterTheObservationTakesTheHouseAwayFromTheVerdict(): void
    {
        $this->plan(['t1' => 'Build the blog']);
        $this->rehearse('BlogController');
        $this->promote();
        $this->store->recordToolCall('s', 'implement', ['plugin' => 'Blog', 'class' => 'BlogSeeder'], (string) json_encode(['ok' => true]), mutating: true);
        $this->done('t1', Evidence::operationOk('e1', 'sandbox_promote'));

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertSame('recorded_work', $closure['scope'], 'the house did not observe the last change: it does not speak');
    }

    public function testTheClaimDoorRefusesATestWhoseLastRunIsRed(): void
    {
        $this->plan(['t1' => 'Serve GET /blog']);
        $this->test('tests/Plugins/Blog', green: true);
        $red = $this->test('tests/Plugins/Blog', green: false);

        $claim = $this->claim('t1', 'test-passed', 'tests/Plugins/Blog');

        self::assertFalse($claim['ok']);
        self::assertSame("the claim is refused: the last test run declaring «tests/Plugins/Blog» is RED (seq {$red}). Fix it and run it green before claiming", $claim['error']);
    }

    public function testTheClaimDoorTakesTheGreenRunThatCameLast(): void
    {
        $this->plan(['t1' => 'Serve GET /blog']);
        $this->test('tests/Plugins/Blog', green: false);
        $green = $this->test('tests/Plugins/Blog', green: true);

        $claim = $this->claim('t1', 'test-passed', 'tests/Plugins/Blog');

        self::assertTrue($claim['ok'], (string) ($claim['error'] ?? ''));
        self::assertSame(['fact' => 'call', 'operation' => 'test', 'seq' => $green], $claim['evidence']['coveredBy']);
    }

    public function testAClaimReadsTheSessionStreamOnce(): void
    {
        // 1036, legs 9–12: a prose reference sent the refusal through every candidate, and each one read the
        // whole event file again. Counted here: the store's own load, and the claim's ONE read.
        $counting = new CountingEventStore($this->events);
        $store = new SessionStore($counting);
        $this->plan(['t1' => 'Serve GET /blog']);
        for ($i = 0; $i < 6; ++$i) {
            $this->test("tests/Plugins/Blog/T{$i}Test.php", green: true);
        }
        $this->promote();

        $counting->replays = 0;
        $claim = $this->claimWith($store, $counting, 't1', 'test-passed', 'TheBlogServesOnlyPublishedPostsTest 3/3 green');

        self::assertFalse($claim['ok']);
        self::assertStringContainsString('«tests/Plugins/Blog/T5Test.php»', $claim['error'], 'the refusal still teaches its candidates');
        self::assertSame(2, $counting->replays, 'SessionStore::load reads once, and the claim reads once');
    }

    /** @param array<string, string> $todos */
    private function plan(array $todos): void
    {
        foreach ($todos as $id => $text) {
            $this->store->setTodo('s', new Todo($id, $text, TodoStatus::Pending));
        }
    }

    private function done(string $todo, Evidence $evidence): void
    {
        $this->store->completeTodo('s', $todo, $evidence);
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

    /** A test run in a trial, as 1036 recorded them: green in `output`, red as a trial-test-failure summary. */
    private function test(string $path, bool $green): int
    {
        $result = $green
            ? ['ran_in_trial' => true, 'applied' => false, 'workspace' => 'wt', 'changed' => [],
                'output' => ['ok' => true, 'ran' => true, 'tests' => 3, 'assertions' => 6, 'failures' => 0, 'errors' => 0]]
            : ['schema' => 'milpa.trial-test-failure/v1', 'ok' => false, 'ran_in_trial' => true, 'applied' => false,
                'summary' => ['counts' => ['ran' => true, 'tests' => 3, 'assertions' => 5, 'failures' => 1, 'errors' => 0]]];

        return $this->store->recordToolCall('s', 'test', ['path' => $path], (string) json_encode($result), ok: $green, mutating: true);
    }

    /** @return array<string, mixed> */
    private function verdict(): array
    {
        $session = $this->store->load('s');
        self::assertNotNull($session);

        return ClosureVerdict::derive($session, $this->store->facts('s'), $this->store->stream('s'));
    }

    /** @return array<string, mixed> */
    private function claim(string $todo, string $kind, string $reference): array
    {
        return $this->claimWith($this->store, $this->events, $todo, $kind, $reference);
    }

    /** @return array<string, mixed> */
    private function claimWith(SessionStore $store, EventStoreInterface $events, string $todo, string $kind, string $reference): array
    {
        foreach ((new SessionBookkeeping($store, 's', $events))->operations() as $operation) {
            if ($operation->name === 'work:claim-verified') {
                /** @var array<string, mixed> */
                return ($operation->handler)(['todo' => $todo, 'kind' => $kind, 'reference' => $reference]);
            }
        }
        self::fail('work:claim-verified is not offered');
    }
}

/** An event store that counts how many times a stream is read whole. */
final class CountingEventStore implements EventStoreInterface
{
    public int $replays = 0;

    public function __construct(private readonly EventStoreInterface $inner)
    {
    }

    public function append(Event $event): void
    {
        $this->inner->append($event);
    }

    public function replay(string $streamId): array
    {
        ++$this->replays;

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
        ++$this->replays;

        return $this->inner->replayAll();
    }
}
