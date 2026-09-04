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
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\McpClientService;
use Milpa\AiGateway\OptionTable;
use Milpa\AiGateway\PlanBoard;
use Milpa\AiGateway\ToolCallGate;
use Milpa\AiGateway\ToolCallRecorder;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The goal and the mode travel in the system prompt a RUN hands the model — proven by driving
 * `run()` itself, not by calling `systemPrompt()` (greenhouse decisions/0202, review).
 *
 * `GoalInPromptTest` proves what the prompt says for a given session. This battery proves that the
 * session it says it for is the right one, folded at the right moment: the goal set mid-session
 * (not the opening prompt), the mode as it stands AFTER a `--mode` on the same call, the session the
 * call names and not another, and — for a sub-agent — the child's own session, never the parent's.
 *
 * The provider is never reached: the orchestrator seam `ask()` builds through is replaced with one
 * that records the system prompt it was handed and answers «done».
 */
final class GoalTravelsWithTheRunTest extends TestCase
{
    private const GOAL = 'write the todo-app plugin';

    private const BRIEF = 'inspect the child session';

    private InMemoryEventStore $events;

    private SessionStore $store;

    private string|false $previousOpenAi = false;

    private string|false $previousAnthropic = false;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->previousOpenAi = getenv('OPENAI_API_KEY');
        $this->previousAnthropic = getenv('ANTHROPIC_API_KEY');
        putenv('OPENAI_API_KEY=test-key');
        putenv('ANTHROPIC_API_KEY');
    }

    protected function tearDown(): void
    {
        $this->previousOpenAi === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $this->previousOpenAi);
        $this->previousAnthropic === false ? putenv('ANTHROPIC_API_KEY') : putenv('ANTHROPIC_API_KEY=' . $this->previousAnthropic);
    }

    /**
     * 1 · the goal set mid-session travels — the FOLDED one, not the opening prompt — and a
     * `--mode auto` on the SAME call travels with it: the prompt speaks for the mode the gate will
     * judge by, which is why `run()` folds the session after the mode has landed.
     */
    public function testTheFoldedGoalAndTheModeOfThisCallTravelInThePrompt(): void
    {
        $this->store->start('s1', 'build a todo plugin');
        $this->store->setGoal('s1', self::GOAL);

        $ops = $this->runAgentOn('s1', ['mode' => 'auto']);

        self::assertCount(1, $ops->systemPrompts, 'one leg, one prompt');
        $prompt = $ops->systemPrompts[0];
        self::assertStringContainsString('The standing goal of this session, declared by the human: ' . self::GOAL, $prompt);
        self::assertStringNotContainsString('build a todo plugin', $prompt, 'the opening prompt was superseded and does not travel as the goal');
        self::assertStringContainsString('chose the automatic mode', $prompt, 'the mode of THIS call, not the one the session woke up with');
        self::assertStringContainsString('nothing pre-consents them', $prompt);
        self::assertSame(AutonomyMode::Auto, $this->store->load('s1')?->mode, 'and the mode is the session\'s fact, not a label');
    }

    /** 2 · THE CONTROL: the same session, no `--mode` on the call — the goal travels, the auto instruction does not. */
    public function testWithoutAModeOnTheCallThePromptKeepsTheSessionsMode(): void
    {
        $this->store->start('s1', 'build a todo plugin');
        $this->store->setGoal('s1', self::GOAL);

        $ops = $this->runAgentOn('s1');

        self::assertCount(1, $ops->systemPrompts);
        self::assertStringContainsString(self::GOAL, $ops->systemPrompts[0]);
        self::assertStringNotContainsString('automatic mode', $ops->systemPrompts[0]);
        self::assertSame(AutonomyMode::Ask, $this->store->load('s1')?->mode);
    }

    /** 3 · the RIGHT session: with two in the store, the prompt speaks for the one the call names. */
    public function testThePromptSpeaksForTheSessionTheCallNames(): void
    {
        $this->store->start('s1', 'a', AutonomyMode::Auto);
        $this->store->setGoal('s1', 'paint the fence');
        $this->store->start('s2', 'b');
        $this->store->setGoal('s2', 'mow the lawn');

        $ops = $this->runAgentOn('s2');

        self::assertCount(1, $ops->systemPrompts);
        self::assertStringContainsString('mow the lawn', $ops->systemPrompts[0]);
        self::assertStringNotContainsString('paint the fence', $ops->systemPrompts[0]);
        self::assertStringNotContainsString('automatic mode', $ops->systemPrompts[0], 's1 is in auto; s2 is not, and s2 is the one that ran');
    }

    /**
     * 4 · the sub-agent branch: the child's leg speaks for the CHILD's session — its brief is its
     * goal and its mode is its own (a child is born in `auto`) — and the parent's leg for the
     * parent's, in `ask` with the parent's goal. Neither leaks into the other.
     */
    public function testTheChildsLegSpeaksForTheChildsSession(): void
    {
        $this->store->start('s1', 'build a todo plugin');
        $this->store->setGoal('s1', self::GOAL);

        $ops = $this->runAgentOn('s1', [], spawnChild: true);

        self::assertCount(2, $ops->systemPrompts, 'the parent leg and the child leg');
        [$parent, $child] = $ops->systemPrompts;

        self::assertStringContainsString(self::GOAL, $parent);
        self::assertStringNotContainsString(self::BRIEF, $parent);
        self::assertStringNotContainsString('automatic mode', $parent, 'the parent is in ask');

        self::assertStringContainsString(self::BRIEF, $child, 'the child\'s brief is its goal');
        self::assertStringNotContainsString(self::GOAL, $child, 'the parent\'s goal does not leak into the child');
        self::assertStringContainsString('chose the automatic mode', $child, 'the child\'s own mode, not the parent\'s');

        $children = array_values(array_filter(
            $this->store->ids(),
            static fn (string $id): bool => str_starts_with($id, 's1.sub-'),
        ));
        self::assertCount(1, $children);
        self::assertSame(AutonomyMode::Auto, $this->store->load($children[0])?->mode);
    }

    /**
     * Drives the `agent` operation on a session, through the real `run()` and the real `ask()`.
     *
     * @param array<string, mixed> $extra
     */
    private function runAgentOn(string $sessionId, array $extra = [], bool $spawnChild = false): PromptCapturingAgentOperations
    {
        $container = new DIContainer();
        $container->registerService(EventStoreInterface::class, $this->events);
        $container->registerService(SessionStore::class, $this->store);
        $kernel = Kernel::boot([
            'root' => \dirname(__DIR__, 2),
            'container' => $container,
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [],
        ]);
        $container->registerService(Kernel::class, $kernel);
        $ops = new PromptCapturingAgentOperations($container);
        $ops->spawnChild = $spawnChild;

        $result = null;
        foreach ($ops->operations() as $operation) {
            if ($operation->name === 'agent') {
                $result = ($operation->handler)(['prompt' => 'go ahead', 'session' => $sessionId, ...$extra]);
            }
        }

        self::assertIsArray($result);
        self::assertTrue($result['ok'] ?? false, (string) ($result['error'] ?? 'agent run failed'));

        return $ops;
    }
}

/**
 * The real `ask()`, with the orchestrator seam replaced: what reaches the model is recorded, and
 * nothing reaches a provider. Remembers the registry each leg received so the parent leg can
 * delegate through the `agent_spawn` the run projected, the way the intake-recording test does.
 */
final class PromptCapturingAgentOperations extends AgentOperations
{
    /** @var list<string> the system prompt of each leg, in the order the legs ran */
    public array $systemPrompts = [];

    public bool $spawnChild = false;

    private bool $spawned = false;

    private ?ToolRegistry $registry = null;

    protected function ask(
        string $prompt,
        int $pasos,
        ToolRegistry $registry,
        string $proveedor,
        string $llave,
        string $modelo,
        callable $onStep,
        array $history = [],
        ?ToolCallGate $gate = null,
        ?OptionTable $mesa = null,
        ?ToolCallRecorder $recorder = null,
        ?PlanBoard $tablero = null,
    ): string {
        $this->registry = $registry;

        return parent::ask($prompt, $pasos, $registry, $proveedor, $llave, $modelo, $onStep, $history, $gate, $mesa, $recorder, $tablero);
    }

    protected function orchestrator(
        LlmService $modeloRemoto,
        McpClientService $cliente,
        int $pasos,
        ?PlanBoard $tablero,
        bool $lazyTools,
        ?SessionProgressProbe $sonda,
    ): AgentOrchestrator {
        return new PromptCapturingOrchestrator($modeloRemoto, $cliente, $pasos, $this);
    }

    /** Delegates once, from the leg whose registry carries `agent_spawn` — the parent's. */
    public function maybeSpawn(): void
    {
        if (!$this->spawnChild || $this->spawned || $this->registry === null) {
            return;
        }
        $spawn = $this->registry->getDefinition('agent_spawn');
        if ($spawn === null || !\is_callable($spawn->callback)) {
            return;
        }
        $this->spawned = true;
        ($spawn->callback)(['brief' => 'inspect the child session']);
    }
}

/** An orchestrator that records the system prompt it was handed and answers without a model. */
final class PromptCapturingOrchestrator extends AgentOrchestrator
{
    public function __construct(
        LlmService $llm,
        McpClientService $client,
        int $steps,
        private readonly PromptCapturingAgentOperations $ops,
    ) {
        parent::__construct($llm, $client, $steps);
    }

    public function run(string $prompt, string $systemPrompt = 'You are a helpful assistant.', array $history = [], ?callable $onStep = null): string
    {
        $this->ops->systemPrompts[] = $systemPrompt;
        $this->ops->maybeSpawn();
        if ($onStep !== null) {
            $onStep();
        }

        return 'done';
    }
}
