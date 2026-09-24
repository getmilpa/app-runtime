<?php

/** Current producer provenance governs closure, independently of pending questions.
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\{SessionStore,Todo,TodoStatus,Evidence,PendingQuestion};
use Milpa\AiGateway\{AgentOrchestrator,LlmService,PlanBoard,RunTermination,RunEnd};
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\{InMemoryEventStore,EventStoreInterface};
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Gate\{GatedToolCalls,ToolCallRefused};
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;

final class TerminationWiringTest extends TestCase
{
    private const ANSWER = 'The same answer can come from different exits.';
    private SessionStore $sessions;
    private InMemoryEventStore $events;
    private DIContainer $container;
    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->sessions = new SessionStore($this->events);
        $this->sessions->start('s', 'Review the fixture');
        $this->sessions->setTodo('s', new Todo('t', 'Fixture ledger', TodoStatus::Pending));
        $this->sessions->completeTodo('s', 't', Evidence::testPassed('e', 'fixture-test', 't'));
        $this->container = new DIContainer();
        $this->container->registerService(SessionStore::class, $this->sessions);
        $this->container->registerService(EventStoreInterface::class, $this->events);
        $kernel = Kernel::boot(['root' => dirname(__DIR__, 2),'container' => $this->container,'toolRegistry' => new ToolRegistry(new NullLogger()),'plugins' => []]);
        $this->container->registerService(Kernel::class, $kernel);
    }
    private function invoke(AgentOperations $ops, string $first = ''): array
    {
        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=fixture-key');
        try {
            foreach ($ops->operations() as $op) {
                if ($op->name === 'agent') {
                    return ($op->handler)(['prompt' => 'Continue','session' => 's','first' => $first]);
                }
            }
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }
        self::fail('No operation');
    }
    private function llm(array $message): LlmService
    {
        $llm = $this->createMock(LlmService::class);
        $llm->method('generateResponse')->willReturn($message);
        return $llm;
    }
    private function tools(bool $refused = false): GatedToolCalls
    {
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([[
            'name' => 'read', 'description' => 'Read the fixture', 'inputSchema' => ['type' => 'object'],
        ]]);
        if ($refused) {
            $tools->method('callTool')->willThrowException(new ToolCallRefused(self::ANSWER));
        }
        return $tools;
    }
    private function ops(AgentOrchestrator $loop): TerminationFixtureOperations
    {
        $ops = new TerminationFixtureOperations($this->container);
        $ops->loop = $loop;
        return $ops;
    }
    private function terminalEvents(): array
    {
        return array_values(array_filter($this->sessions->stream('s'), static fn ($e) => $e->type === 'session.run_terminated'));
    }
    /**
     * An interrupted run is REMEMBERED as a typed fact even where AgentOperations cannot reach the event
     * log — a host that registers its own SessionStore and no EventStoreInterface (greenhouse
     * decisions/0466). Measured first on the framework template's harness: the stream kept `started`
     * and the user's turn, and nothing else. And it is never put in the assistant's mouth.
     */
    public function testAnInterruptedRunIsATypedFactEvenWithoutTheEventLog(): void
    {
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('s', 'Build everything');
        $container = new DIContainer();
        $container->registerService(SessionStore::class, $sessions);
        $kernel = Kernel::boot(['root' => dirname(__DIR__, 2),'container' => $container,'toolRegistry' => new ToolRegistry(new NullLogger()),'plugins' => []]);
        $container->registerService(Kernel::class, $kernel);

        $llm = $this->createMock(LlmService::class);
        $llm->method('generateResponse')->willThrowException(\Milpa\AiGateway\RunInterrupted::porElHumano(0));
        $ops = new TerminationFixtureOperations($container);
        $ops->loop = new AgentOrchestrator($llm, $this->tools());

        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=fixture-key');
        try {
            $agent = array_values(array_filter($ops->operations(), static fn ($op) => $op->name === 'agent'))[0];
            $r = ($agent->handler)(['prompt' => 'Build everything','session' => 's']);
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }

        self::assertTrue($r['interrupted'] ?? false);
        self::assertSame('interrupted', $r['termination']['reason']);
        $terminal = array_values(array_filter($sessions->stream('s'), static fn ($e) => $e->type === 'session.run_terminated'));
        self::assertCount(1, $terminal, 'the stream remembers the interruption');
        self::assertSame('interrupted', $terminal[0]->payload['reason']);
        self::assertSame([], array_values(array_filter(
            $sessions->load('s')?->turns ?? [],
            static fn (array $turn): bool => $turn['role'] === 'assistant',
        )), 'as a fact of the run, never as something the model said');
        self::assertTrue($sessions->load('s')?->isRunnable(), 'and the session can go on');
    }

    public function testIdenticalFinalAndRefusalHaveDifferentClosureEligibility(): void
    {
        $final = $this->invoke($this->ops(new AgentOrchestrator($this->llm(['role' => 'assistant','content' => self::ANSWER]), $this->tools())));
        $call = ['role' => 'assistant','tool_calls' => [['id' => 'fixture','function' => ['name' => 'read','arguments' => '{}']]]];
        $refused = $this->invoke($this->ops(new AgentOrchestrator($this->llm($call), $this->tools(true))));
        self::assertSame($final['answer'], $refused['answer']);
        self::assertTrue($final['closure']['verified']);
        self::assertArrayNotHasKey('closure', $refused);
        self::assertSame('final_answer', $final['termination']['reason']);
        self::assertSame('tool_refused', $refused['termination']['reason']);
        $events = $this->terminalEvents();
        self::assertCount(2, $events);
        self::assertSame($final['termination'], $events[0]->payload);
        self::assertSame($refused['termination'], $events[1]->payload);
        self::assertCount(1, array_filter($this->sessions->stream('s'), static fn ($e) => $e->type === 'session.closure_derived'));
        self::assertSame([self::ANSWER], array_values(array_map(
            static fn ($e): string => $e->payload['content'],
            array_filter($this->sessions->stream('s'), static fn ($e): bool =>
                $e->type === 'session.turn' && $e->payload['role'] === 'assistant'),
        )), 'the gate refusal is a runtime outcome, not a previous model answer');
    }
    public function testPendingQuestionStillPausesAnActualFinalAnswer(): void
    {
        $llm = $this->createMock(LlmService::class);
        $llm->method('generateResponse')->willReturnCallback(function (): array {
            $this->sessions->ask('s', new PendingQuestion('q', 'Which option?', ['continue']));
            return ['role' => 'assistant','content' => self::ANSWER];
        });
        $r = $this->invoke($this->ops(new AgentOrchestrator($llm, $this->tools())));
        self::assertTrue($r['paused']);
        self::assertSame('final_answer', $r['termination']['reason']);
        self::assertArrayNotHasKey('closure', $r);
        self::assertSame($r['termination'], $this->terminalEvents()[0]->payload);
    }

    public function testFinalAnswerCannotCloseWhileRequiredFirstToolIsPending(): void
    {
        $r = $this->invoke($this->ops(new AgentOrchestrator(
            $this->llm(['role' => 'assistant', 'content' => self::ANSWER]),
            $this->tools(),
        )), 'skill_load');

        self::assertSame('final_answer', $r['termination']['reason'], 'preserve the producer cause');
        self::assertSame(['skill_load'], $r['prerequisitePending']);
        self::assertTrue($r['incomplete']);
        self::assertStringContainsString('skill_load', $r['answer']);
        self::assertSame(['skill_load'], $this->sessions->load('s')?->runFirst);
        self::assertArrayNotHasKey('closure', $r);
        self::assertCount(0, array_filter($this->sessions->stream('s'), static fn ($e) => $e->type === 'session.closure_derived'));
        self::assertSame($r['termination'], $this->terminalEvents()[0]->payload);
        self::assertContains(self::ANSWER, array_map(
            static fn ($e) => $e->payload['content'],
            array_filter($this->sessions->stream('s'), static fn ($e) => $e->type === 'session.turn'),
        ), 'the model text remains in the auditable journal');
    }
    public function testAReusedProducerCannotLendItsOldCauseToAnArgumentFailure(): void
    {
        $ops = $this->ops(new AgentOrchestrator($this->llm(['role' => 'assistant','content' => self::ANSWER]), $this->tools()));
        $first = $this->invoke($ops);
        self::assertSame('final_answer', $first['termination']['reason']);
        $ops->failPrompt = true;
        $second = $this->invoke($ops);
        self::assertFalse($second['ok']);
        self::assertSame('unknown', $second['termination']['reason']);
        self::assertArrayNotHasKey('closure', $second);
        self::assertSame($second['termination'], $this->terminalEvents()[1]->payload);
    }
    public function testAnOverriddenRunCannotLendItsEarlierBaseObservation(): void
    {
        $llm = $this->llm(['role' => 'assistant','content' => self::ANSWER]);
        $loop = new class ($llm, $this->tools()) extends AgentOrchestrator {
            public function seed(): void
            {
                parent::run('Seed');
            }
            public function run(string $prompt, string $systemPrompt = 'You are a helpful assistant.', array $history = [], ?callable $onStep = null): string
            {
                return 'Different execution';
            }
        };
        $loop->seed();
        self::assertSame(RunEnd::FinalAnswer, $loop->termination()->reason);
        $r = $this->invoke($this->ops($loop));
        self::assertSame('unknown', $r['termination']['reason']);
        self::assertArrayNotHasKey('closure', $r);
    }
    public function testAnOverriddenGetterCannotForgeAFinalCause(): void
    {
        $loop = new class ($this->llm(['role' => 'assistant','content' => self::ANSWER]), $this->tools()) extends AgentOrchestrator {
            public function termination(): ?RunTermination
            {
                return new RunTermination(RunEnd::FinalAnswer);
            }
        };
        $r = $this->invoke($this->ops($loop));
        self::assertSame('unknown', $r['termination']['reason']);
        self::assertArrayNotHasKey('closure', $r);
    }
    /** @return iterable<string,array{string}> */
    public static function collisions(): iterable
    {
        yield 'steps' => [AgentOrchestrator::STEPS_EXHAUSTED];
        yield 'context' => ['Error: Agent context budget exhausted.'];
        yield 'progress' => [AgentOrchestrator::PROGRESS_STALLED];
        yield 'debt' => ['HOUSE_DEBT: quoted'];
    }
    #[DataProvider('collisions')]
    public function testModelTextDoesNotOverrideTheProducerCause(string $answer): void
    {
        $r = $this->invoke($this->ops(new AgentOrchestrator($this->llm(['role' => 'assistant','content' => $answer]), $this->tools())));
        self::assertSame($answer, $r['answer']);
        self::assertSame('final_answer', $r['termination']['reason']);
        self::assertTrue($r['closure']['verified']);
        foreach (['exhausted','contextExhausted','stalled','houseDebt'] as $flag) {
            self::assertArrayNotHasKey($flag, $r);
        }
    }

    public function testContextBudgetPauseIsDurableAndIneligibleForClosure(): void
    {
        $llm = $this->llm(['role' => 'assistant', 'content' => '', 'reasoning_content' => str_repeat('reason ', 16000),
            'tool_calls' => [['id' => 'once', 'function' => ['name' => 'read', 'arguments' => '{}']]]]);
        $tools = $this->tools();
        $tools->expects(self::once())->method('callTool')->willReturn('Recorded result');
        $loop = new AgentOrchestrator($llm, $tools, contextTokens:32768, outputTokens:8192);
        $r = $this->invoke($this->ops($loop));
        self::assertTrue($r['ok']);
        self::assertTrue($r['contextExhausted']);
        self::assertSame('context_budget_exhausted', $r['termination']['reason']);
        self::assertSame(1, $r['termination']['receipt']['completedSteps']);
        self::assertArrayNotHasKey('closure', $r);
        self::assertArrayNotHasKey('paused', $r);
        self::assertSame($r['termination'], $this->terminalEvents()[0]->payload);
        self::assertCount(0, array_filter($this->sessions->stream('s'), static fn ($e) => $e->type === 'session.closure_derived'));
        self::assertSame([], array_values(array_filter(
            $this->sessions->load('s')?->turns ?? [],
            static fn (array $turn): bool => $turn['role'] === 'assistant',
        )), 'the runtime pause must not enter the next model window as assistant speech');
        $next = $this->invoke($this->ops(new AgentOrchestrator($this->llm(['role' => 'assistant', 'content' => self::ANSWER]), $this->tools())));
        self::assertSame('final_answer', $next['termination']['reason']);
        self::assertArrayNotHasKey('contextExhausted', $next);
        self::assertCount(2, $this->terminalEvents());
    }
}

class TerminationFixtureOperations extends AgentOperations
{
    public AgentOrchestrator $loop;
    public bool $failPrompt = false;
    protected function orchestrator(LlmService $modeloRemoto, GatedToolCalls $cliente, int $pasos, ?PlanBoard $tablero, bool $lazyTools, ?SessionProgressProbe $sonda): AgentOrchestrator
    {
        return $this->loop;
    }
    protected function systemPrompt(array $herramientas = [], ?\Milpa\Agent\Session $session = null): string
    {
        if ($this->failPrompt) {
            throw new \RuntimeException('Fixture prompt failure before run');
        }
        return parent::systemPrompt($herramientas, $session);
    }
}
