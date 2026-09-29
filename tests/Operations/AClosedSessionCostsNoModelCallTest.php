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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\PlanBoard;
use Milpa\AppRuntime\Agent\ClosedSessionDoor;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A leg that asks nothing new of a session the house already verified is answered by the house, without a model
 * call (greenhouse decisions/0529) — and every real new request still reaches the model.
 *
 * Measured (evidence/1050): nine «continue» legs after the blog was built cost 422,073 tokens saying «the goal is met».
 */
final class AClosedSessionCostsNoModelCallTest extends TestCase
{
    private const SESSION = 's';

    private SessionStore $sessions;

    private InMemoryEventStore $events;

    private DIContainer $container;

    private int $modelCalls = 0;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->sessions = new SessionStore($this->events);
        $this->container = new DIContainer();
        $this->container->registerService(SessionStore::class, $this->sessions);
        $this->container->registerService(EventStoreInterface::class, $this->events);
        $kernel = Kernel::boot(['root' => \dirname(__DIR__, 2), 'container' => $this->container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => []]);
        $this->container->registerService(Kernel::class, $kernel);
    }

    public function testAContinueOnAVerifiedSessionCallsNoModel(): void
    {
        $this->aVerifiedSession();
        $before = $this->modelCalls;
        $turns = \count($this->sessions->load(self::SESSION)?->turns ?? []);
        $ended = \count($this->ofType('session.run_terminated'));

        $r = $this->leg(['prompt' => 'continue', 'session' => self::SESSION]);

        self::assertSame($before, $this->modelCalls, 'no model call');
        self::assertTrue($r['ok']);
        self::assertTrue($r['answeredWithoutModel'] ?? false);
        self::assertSame(0, $r['steps']);
        self::assertTrue($r['closure']['verified']);
        self::assertStringContainsString('no model was called', (string) $r['answer']);
        self::assertStringContainsString('blog', (string) $r['answer']);
        self::assertCount($turns, $this->sessions->load(self::SESSION)?->turns ?? [], 'no turn recorded: nothing reaches the next window');
        $door = $this->ofType(ClosedSessionDoor::EVENT);
        self::assertCount(1, $door);
        self::assertSame($r['closureSeq'], $door[0]->payload['closureSeq']);
        self::assertSame($this->ofType(ClosureVerdict::EVENT)[0]->seq, $r['closureSeq']);
        self::assertCount($ended, $this->ofType('session.run_terminated'), 'no run happened, so none ended');
        self::assertCount(1, $this->ofType(ClosureVerdict::EVENT), 'and the door records no verdict of its own');

        // And again: the door's own fact does not reopen what it answered.
        $again = $this->leg(['prompt' => 'Continue.', 'session' => self::SESSION, 'mode' => 'auto', 'steps' => 4]);
        self::assertTrue($again['answeredWithoutModel'] ?? false);
        self::assertSame($before, $this->modelCalls);
    }

    public function testANewHumanTurnReachesTheModel(): void
    {
        $this->aVerifiedSession();
        $before = $this->modelCalls;

        $r = $this->leg(['prompt' => 'now add a comments section to each post', 'session' => self::SESSION]);

        self::assertSame($before + 1, $this->modelCalls);
        self::assertArrayNotHasKey('answeredWithoutModel', $r);
        self::assertSame([], $this->ofType(ClosedSessionDoor::EVENT));
    }

    public function testAContinueAfterAnUnfinishedNewAskReachesTheModel(): void
    {
        $this->aVerifiedSession();
        // A new ask whose leg never reached a verdict (it paused, ran out, died): the ask is still standing.
        $this->sessions->recordTurn(self::SESSION, 'user', 'now add tags');
        $before = $this->modelCalls;

        $this->leg(['prompt' => 'continue', 'session' => self::SESSION]);

        self::assertSame($before + 1, $this->modelCalls);
    }

    public function testANewGoalReachesTheModel(): void
    {
        $this->aVerifiedSession();
        $this->sessions->setGoal(self::SESSION, 'Build the shop');
        $before = $this->modelCalls;

        $this->leg(['prompt' => 'continue', 'session' => self::SESSION]);

        self::assertSame($before + 1, $this->modelCalls);
    }

    public function testAnAnsweredQuestionReachesTheModel(): void
    {
        $this->aVerifiedSession();
        $this->sessions->ask(self::SESSION, new PendingQuestion('q1', 'Publish it?', ['yes', 'no']));
        $this->sessions->answer(self::SESSION, 'q1', 'yes');
        $before = $this->modelCalls;

        $this->leg(['prompt' => 'continue', 'session' => self::SESSION]);

        self::assertSame($before + 1, $this->modelCalls);
    }

    public function testAGrantReachesTheModel(): void
    {
        $this->aVerifiedSession();
        $this->sessions->grant(self::SESSION, 'plugins.register');
        $before = $this->modelCalls;

        $this->leg(['prompt' => 'continue', 'session' => self::SESSION]);

        self::assertSame($before + 1, $this->modelCalls);
    }

    public function testALegThatCarriesSomethingReachesTheModel(): void
    {
        $this->aVerifiedSession();
        $before = $this->modelCalls;

        $this->leg(['prompt' => 'continue', 'session' => self::SESSION, 'first' => 'plan']);
        $this->leg(['prompt' => 'continue', 'session' => self::SESSION, 'mode' => 'ask']);

        self::assertGreaterThanOrEqual($before + 2, $this->modelCalls);
    }

    public function testAnUnverifiedSessionRunsAsAlways(): void
    {
        $this->sessions->start(self::SESSION, 'Review the fixture', AutonomyMode::Auto);
        $this->sessions->setTodo(self::SESSION, new Todo('t', 'Fixture ledger', TodoStatus::Pending));
        $this->leg(['prompt' => 'Build it', 'session' => self::SESSION]);
        self::assertFalse($this->ofType(ClosureVerdict::EVENT)[0]->payload['verified']);
        $before = $this->modelCalls;

        $r = $this->leg(['prompt' => 'continue', 'session' => self::SESSION]);

        self::assertSame($before + 1, $this->modelCalls);
        self::assertArrayNotHasKey('answeredWithoutModel', $r);
    }

    public function testARecordedVerdictTheHouseNoLongerHoldsReachesTheModel(): void
    {
        // Recorded verified, but the session's own facts say otherwise now: an open todo nobody closed.
        $this->sessions->start(self::SESSION, 'Build the blog page a reader reads', AutonomyMode::Auto);
        $this->sessions->setTodo(self::SESSION, new Todo('t', 'Still open', TodoStatus::Pending));
        ClosureVerdict::record($this->events, self::SESSION, ['verified' => true, 'reasons' => []]);
        $before = $this->modelCalls;

        $this->leg(['prompt' => 'continue', 'session' => self::SESSION]);

        self::assertSame($before + 1, $this->modelCalls);
    }

    public function testAnEndedOrWaitingSessionKeepsItsOwnAnswer(): void
    {
        $this->aVerifiedSession();
        $this->sessions->ask(self::SESSION, new PendingQuestion('q1', 'Publish it?', ['yes', 'no']));

        $waiting = $this->leg(['prompt' => 'continue', 'session' => self::SESSION]);

        self::assertFalse($waiting['ok']);
        self::assertStringContainsString('is waiting for an answer', (string) $waiting['error']);

        $this->sessions->answer(self::SESSION, 'q1', 'no');
        $this->sessions->end(self::SESSION, 'closed by hand');
        $ended = $this->leg(['prompt' => 'continue', 'session' => self::SESSION]);

        self::assertFalse($ended['ok']);
        self::assertStringContainsString('already ended', (string) $ended['error']);
        self::assertSame([], $this->ofType(ClosedSessionDoor::EVENT));
        self::assertSame(1, $this->modelCalls);
    }

    public function testASessionThatDoesNotExistStartsAsAlways(): void
    {
        $r = $this->leg(['prompt' => 'continue', 'session' => 'fresh']);

        self::assertSame(1, $this->modelCalls);
        self::assertArrayNotHasKey('answeredWithoutModel', $r);
    }

    /**
     * The fixture of the house-derived closure (greenhouse decisions/0487, 0522): the goal names the blog, it was
     * promoted and the house observed it served after. One leg ends on a final answer and records the verdict.
     */
    private function aVerifiedSession(): void
    {
        $this->sessions->start(self::SESSION, 'Build the blog page a reader reads', AutonomyMode::Auto);
        $this->sessions->recordToolCall(self::SESSION, 'sandbox_promote', ['workspace' => 'wabc'], (string) json_encode(['ok' => true,
            'evidence' => ['predicate' => 'promoted', 'subject' => 'wabc', 'environment' => ['kind' => 'house']]]), mutating: true);
        $this->sessions->recordToolCall(self::SESSION, 'screen_observe', ['name' => 'blog'], (string) json_encode(['ok' => true,
            'evidence' => ['predicate' => 'served', 'subject' => 'blog', 'environment' => ['kind' => 'house']]]));

        $r = $this->leg(['prompt' => 'Build it', 'session' => self::SESSION]);

        self::assertTrue($r['closure']['verified'] ?? false, implode('; ', $r['closure']['reasons'] ?? []));
        self::assertSame(1, $this->modelCalls);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function leg(array $input): array
    {
        $llm = $this->createMock(LlmService::class);
        $llm->method('generateResponse')->willReturnCallback(function (): array {
            ++$this->modelCalls;

            return ['role' => 'assistant', 'content' => 'The goal is met.'];
        });
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([['name' => 'read', 'description' => 'Read', 'inputSchema' => ['type' => 'object']]]);
        $ops = new DoorFixtureOperations($this->container);
        $ops->loop = new AgentOrchestrator($llm, $tools);

        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=fixture-key');
        try {
            foreach ($ops->operations() as $op) {
                if ($op->name === 'agent') {
                    /** @var array<string, mixed> */
                    return ($op->handler)($input);
                }
            }
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }
        self::fail('no agent operation');
    }

    /** @return list<Event> */
    private function ofType(string $type): array
    {
        return array_values(array_filter($this->sessions->stream(self::SESSION), static fn (Event $e): bool => $e->type === $type));
    }
}

/** The agent operation with its orchestrator handed in, so the model is a counter. */
final class DoorFixtureOperations extends AgentOperations
{
    public AgentOrchestrator $loop;

    protected function orchestrator(LlmService $modeloRemoto, GatedToolCalls $cliente, int $pasos, ?PlanBoard $tablero, bool $lazyTools, ?SessionProgressProbe $sonda): AgentOrchestrator
    {
        return $this->loop;
    }
}
