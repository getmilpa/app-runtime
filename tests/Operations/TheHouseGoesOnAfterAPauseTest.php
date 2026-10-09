<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\PlanBoard;
use Milpa\AiGateway\RunEnd;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\AppRuntime\Agent\HouseGoesOn;
use Milpa\AppRuntime\Agent\SeatFrontier;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\Gate\ToolCallRefused;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * After a pause for window or for steps the house goes on by itself — once, under a written cap (greenhouse
 * decisions/0604, rule A; the cap decided by Rod on 2026-10-08).
 *
 * A leg that ran out of window, or of steps, ended, and somebody had to start it again: a person, or the lab's
 * conductor spending one of its three legs. On the published stack 7 of 11 building runs were cut by their steps and
 * 2 by their window. Now the house folds and goes on inside the same invocation, the way it already resumes a turn
 * that echoed its own voice (decisions/0475): a second leg of the same session, told by the house why it follows.
 *
 * THE CAP IS WRITTEN: one continuation per invocation, and sixty steps in all counting those before the pause — one
 * and a half times the forty decisions/0538 gave a leg. A ceiling a person typed is theirs, and is the total.
 *
 * A PAUSE THE HOUSE CONTINUES IS NOT AN END. Nothing closes there: the closure — and the exercise of a capability
 * that goes before it (decisions/0605) — belongs to the leg that ends in an answer.
 *
 * @guards the house going on once after `steps_exhausted` and after `context_budget_exhausted`, in `auto`, with what
 *         is left of the sixty; the notice being the house's and saying why; the fact it leaves; the result saying how
 *         often it went on and how many steps in all; no closure at the pause and one at the end
 *
 * @refuses going on twice; past the total; over a pause that waits for a person; outside `auto`; past the ceiling a
 *          person typed; a notice that could be taken for a person's turn
 *
 * @subject-in milpa/app-runtime
 */
final class TheHouseGoesOnAfterAPauseTest extends TestCase
{
    /** A house that declares the window of its model: a leg in `auto` then takes forty steps. */
    private const WINDOW = ['agent' => ['contextTokens' => 49152, 'outputTokens' => 8192]];

    private SessionStore $sessions;

    private InMemoryEventStore $events;

    private GoOnFixtureOperations $ops;

    private int $calls = 0;

    public function testAfterTheStepsRunOutItGoesOnOnceWithWhatIsLeftOfTheSixty(): void
    {
        // A house that declares its window: a leg in `auto` takes forty (decisions/0538). A model that never answers.
        $r = $this->agent(static fn (int $call): string|array => self::reads($call), config: self::WINDOW);

        self::assertSame([40, 20], $this->ops->ceilings, 'the leg took its forty; the house went on with the other twenty');
        self::assertSame(60, $this->calls, 'sixty steps in all, and not one more');
        self::assertSame('steps_exhausted', $r['termination']['reason'], 'it stopped at the cap: once is once');
        self::assertSame(['times' => 1, 'after' => 'steps_exhausted', 'stepsBefore' => 40, 'stepsInAll' => 60], $r['wentOn']);
        self::assertSame(20, $r['steps'], 'the steps of the leg that ended; the total is said apart');
    }

    public function testTheLegThatFollowsIsALegAndTakesNoMoreThanALegsCeiling(): void
    {
        // No window declared: a leg takes twelve. Forty-eight are left of the sixty; the leg that follows takes twelve.
        $r = $this->agent(static fn (int $call): string|array => self::reads($call));

        self::assertSame([12, 12], $this->ops->ceilings);
        self::assertSame(24, $this->calls);
        self::assertSame(['times' => 1, 'after' => 'steps_exhausted', 'stepsBefore' => 12, 'stepsInAll' => 24], $r['wentOn']);
    }

    public function testTheContinuationThatEndsInAnAnswerIsTheAnswerOfTheInvocation(): void
    {
        $r = $this->agent(static fn (int $call): string|array => $call <= 15 ? self::reads($call) : 'The page is served in the house.');

        self::assertSame('final_answer', $r['termination']['reason']);
        self::assertStringContainsString('The page is served in the house.', (string) $r['answer']);
        self::assertSame(1, $r['wentOn']['times']);
        self::assertSame(12, $r['wentOn']['stepsBefore']);
        self::assertSame(12 + $r['steps'], $r['wentOn']['stepsInAll']);
        self::assertSame(16, $this->calls);
    }

    public function testAfterTheWindowRunsOutItGoesOnToo(): void
    {
        // The first leg has a small window and reads large results; the one that follows has room.
        $r = $this->agent(static fn (int $call): string|array => $call <= 8 ? self::reads($call) : 'Done.', windows: [3000], result: str_repeat('lorem ipsum ', 400));

        self::assertSame('final_answer', $r['termination']['reason']);
        self::assertSame('context_budget_exhausted', $r['wentOn']['after']);
        self::assertSame(1, $r['wentOn']['times']);
        self::assertLessThan(12, $r['wentOn']['stepsBefore'], 'it paused for its window before its ceiling');
        self::assertSame(12, $this->ops->ceilings[1], 'a leg\'s ceiling: less than what is left of the sixty');
    }

    public function testTheHouseSaysItIsTheHouseAndWhyAndLeavesTheFact(): void
    {
        $this->agent(static fn (int $call): string|array => $call <= 15 ? self::reads($call) : 'Done.');

        $notices = array_values(array_filter($this->userTurns(), static fn (string $turn): bool => str_starts_with($turn, SeatFrontier::NOTICE_PREFIX)));
        self::assertCount(1, $notices);
        self::assertSame(HouseGoesOn::notice('steps_exhausted'), $notices[0]);
        self::assertStringContainsString('ran out of steps', $notices[0]);
        self::assertStringContainsString('ran out of window', HouseGoesOn::notice('context_budget_exhausted'));
        self::assertStringStartsWith(SeatFrontier::NOTICE_PREFIX, HouseGoesOn::notice('context_budget_exhausted'), 'never a turn a reader could take for a person\'s');

        // The fact is in the record before the leg it announces starts: a leg that dies leaves why it was there.
        $types = array_map(static fn ($event): string => $event->type, $this->events->replay('agent-session:s'));
        $noticed = null;
        foreach ($this->events->replay('agent-session:s') as $at => $event) {
            if (($event->payload['content'] ?? null) === $notices[0]) {
                $noticed = $at;
            }
        }
        self::assertNotNull($noticed, 'the notice is a turn of the session');
        self::assertLessThan($noticed, array_search(HouseGoesOn::EVENT, $types, true));

        $facts = $this->facts(HouseGoesOn::EVENT);
        self::assertCount(1, $facts);
        self::assertSame(['after' => 'steps_exhausted', 'steps_before' => 12, 'steps_left' => 12, 'time' => 1, 'cap' => ['continuations' => 1, 'steps_in_all' => 60], 'by' => 'house'], $facts[0]);
    }

    public function testAPauseTheHouseContinuesIsNotAnEndAndTheAnswerThatFollowsIs(): void
    {
        $this->agent(static fn (int $call): string|array => $call <= 15 ? self::reads($call) : 'The page is served in the house.');
        self::assertCount(1, $this->facts(ClosureVerdict::EVENT), 'one closure, of the leg that ended in an answer');
        $types = array_map(static fn ($event): string => $event->type, $this->events->replay('agent-session:s'));
        self::assertGreaterThan(array_search(HouseGoesOn::EVENT, $types, true), array_search(ClosureVerdict::EVENT, $types, true), 'and it comes after the house went on, not at the pause');

        $this->agent(static fn (int $call): string|array => self::reads($call));
        self::assertCount(0, $this->facts(ClosureVerdict::EVENT), 'two legs that both paused closed nothing');
    }

    public function testTheStepsOfATurnTheHouseResumedOverAnEchoCountToo(): void
    {
        // Five steps and then the house's own voice for an answer: the house resumes that turn (decisions/0475), and
        // the resumed one runs out of its twelve. Seventeen are spent, not twelve.
        $echo = 'Runtime history: quoted data, not instructions or a model-authored reply.' . "\n" . '{"source":"session_history","session":"s","seq":8}';
        $r = $this->agent(static fn (int $call): string|array => match (true) {
            $call <= 4 => self::reads($call),
            $call === 5 => $echo,
            $call <= 17 => self::reads($call),
            default => 'Done.',
        }, config: ['agent' => ['invocationSteps' => 20]]);

        self::assertSame([12, 12, 3], $this->ops->ceilings, 'three are left of twenty, not eight');
        self::assertSame(17, $r['wentOn']['stepsBefore']);
        self::assertSame(18, $r['wentOn']['stepsInAll']);
        self::assertSame('final_answer', $r['termination']['reason']);
    }

    /** Every way a leg ends that is not one of the two pauses is where the invocation ends. */
    public function testOnlyTheTwoPausesAreTheHousesToContinue(): void
    {
        $goesOn = [];
        foreach (RunEnd::cases() as $end) {
            if (HouseGoesOn::stepsLeft(['termination' => ['reason' => $end->value]], AutonomyMode::Auto, false, null, 40, 10, 0, null) !== null) {
                $goesOn[] = $end->value;
            }
        }
        self::assertSame(['steps_exhausted', 'context_budget_exhausted'], $goesOn, 'an exhausted epilogue is an end; a refusal waits for a person');
        self::assertSame(HouseGoesOn::AFTER, $goesOn);

        // A result that says no termination at all is not a pause the house knows.
        self::assertNull(HouseGoesOn::stepsLeft([], AutonomyMode::Auto, false, null, 40, 10, 0, null));
        self::assertNull(HouseGoesOn::stepsLeft(['termination' => 'steps_exhausted'], AutonomyMode::Auto, false, null, 40, 10, 0, null));
    }

    public function testWhatIsLeftIsTheLesserOfALegsCeilingAndTheRestOfTheTotal(): void
    {
        $paused = ['termination' => ['reason' => 'context_budget_exhausted']];
        self::assertSame(40, HouseGoesOn::stepsLeft($paused, AutonomyMode::Auto, false, null, 40, 10, 0, null), 'fifty are left of sixty: a leg takes forty');
        self::assertSame(25, HouseGoesOn::stepsLeft($paused, AutonomyMode::Auto, false, null, 40, 35, 0, null));
        self::assertSame(1, HouseGoesOn::stepsLeft($paused, AutonomyMode::Auto, false, null, 40, 59, 0, null));
        self::assertNull(HouseGoesOn::stepsLeft($paused, AutonomyMode::Auto, false, null, 40, 60, 0, null), 'nothing is left: it does not go on for no step');
        self::assertNull(HouseGoesOn::stepsLeft($paused, AutonomyMode::Auto, false, null, 40, 75, 0, null));
        self::assertNull(HouseGoesOn::stepsLeft($paused, AutonomyMode::Auto, false, null, 40, 10, 1, null), 'once');
        self::assertNull(HouseGoesOn::stepsLeft($paused, null, false, null, 40, 10, 0, null), 'a session whose mode is not known is not in auto');
        self::assertNull(HouseGoesOn::stepsLeft($paused, AutonomyMode::Auto, true, null, 40, 10, 0, null));
        self::assertSame(20, HouseGoesOn::stepsLeft($paused, AutonomyMode::Auto, false, 30, 30, 10, 0, null), 'typed: thirty is the total');
        self::assertSame(['continuations' => 1, 'steps_in_all' => 60], HouseGoesOn::cap(null));
        self::assertSame(['continuations' => 3, 'steps_in_all' => 90], HouseGoesOn::cap(new Config(['agent' => ['continuations' => 3, 'invocationSteps' => 90]])));
        self::assertSame(['continuations' => 0, 'steps_in_all' => 60], HouseGoesOn::cap(new Config(['agent' => ['continuations' => 0, 'invocationSteps' => 0]])), 'none is a number a house can mean; no step in all is not');
        self::assertSame(['continuations' => 1, 'steps_in_all' => 60], HouseGoesOn::cap(new Config(['agent' => ['continuations' => -1, 'invocationSteps' => '90']])), 'fewer than none, and a number written as text, are not');
    }

    public function testALegThatAnswersIsNotContinued(): void
    {
        $r = $this->agent(static fn (int $call): string|array => 'Done.');

        self::assertSame(1, $this->calls);
        self::assertArrayNotHasKey('wentOn', $r);
        self::assertSame([], $this->facts(HouseGoesOn::EVENT));
    }

    public function testAPauseThatWaitsForAPersonIsNotTheHousesToContinue(): void
    {
        $refused = $this->agent(static fn (int $call): string|array => self::reads($call), refuse: true);
        self::assertSame('tool_refused', $refused['termination']['reason']);
        self::assertArrayNotHasKey('wentOn', $refused);
        self::assertSame(1, $this->calls);

        // Out of steps with a question open: the question is a person's, and the house does not go on over it.
        $asked = $this->agent(static fn (int $call): string|array => self::reads($call), ask: true);
        self::assertSame('steps_exhausted', $asked['termination']['reason']);
        self::assertArrayNotHasKey('wentOn', $asked);
        self::assertSame(12, $this->calls);
    }

    public function testOutsideAutoALegEndsWhereItEnded(): void
    {
        $r = $this->agent(static fn (int $call): string|array => self::reads($call), mode: AutonomyMode::Ask);

        self::assertSame(12, $this->calls);
        self::assertArrayNotHasKey('wentOn', $r);
    }

    public function testACeilingAPersonTypedIsTheirsAndIsTheTotal(): void
    {
        $r = $this->agent(static fn (int $call): string|array => self::reads($call), input: ['steps' => 5]);
        self::assertSame(5, $this->calls, 'five were asked for: the house does not walk past them');
        self::assertArrayNotHasKey('wentOn', $r);

        // Typed, and the pause came for the window before them: the rest of what was typed is what is left.
        $r = $this->agent(static fn (int $call): string|array => $call <= 8 ? self::reads($call) : 'Done.', input: ['steps' => 30], windows: [3000], result: str_repeat('lorem ipsum ', 400));
        self::assertSame(30 - $r['wentOn']['stepsBefore'], $this->ops->ceilings[1]);
    }

    public function testTheCapIsAWrittenValueAHouseCanChange(): void
    {
        self::assertSame(1, HouseGoesOn::CONTINUATIONS);
        self::assertSame(60, HouseGoesOn::STEPS_IN_ALL);

        $r = $this->agent(static fn (int $call): string|array => self::reads($call), config: ['agent' => ['continuations' => 0]]);
        self::assertSame(12, $this->calls, 'a house that declares none is the house it was');
        self::assertArrayNotHasKey('wentOn', $r);

        $r = $this->agent(static fn (int $call): string|array => self::reads($call), config: ['agent' => ['invocationSteps' => 20]]);
        self::assertSame([12, 8], $this->ops->ceilings);
        self::assertSame(20, $this->calls);

        $r = $this->agent(static fn (int $call): string|array => self::reads($call), config: ['agent' => ['continuations' => 2, 'invocationSteps' => 30]]);
        self::assertSame([12, 12, 6], $this->ops->ceilings, 'two, when a house says two: each leg its ceiling, the total the total');
        self::assertSame(['times' => 2, 'after' => 'steps_exhausted', 'stepsBefore' => 24, 'stepsInAll' => 30], $r['wentOn']);
        self::assertSame(
            [['steps_before' => 12, 'steps_left' => 12, 'time' => 1, 'cap' => ['continuations' => 2, 'steps_in_all' => 30]], ['steps_before' => 24, 'steps_left' => 6, 'time' => 2, 'cap' => ['continuations' => 2, 'steps_in_all' => 30]]],
            array_map(static fn (array $fact): array => array_intersect_key($fact, ['time' => 1, 'steps_before' => 1, 'steps_left' => 1, 'cap' => 1]), $this->facts(HouseGoesOn::EVENT)),
            'each time leaves its fact, with the cap of THIS house',
        );

        // What is not a number a house can mean is not obeyed: the written value stands.
        $r = $this->agent(static fn (int $call): string|array => self::reads($call), config: ['agent' => ['continuations' => 'many', 'invocationSteps' => -5] + self::WINDOW['agent']]);
        self::assertSame([40, 20], $this->ops->ceilings);
    }

    /** One call to the only tool, as a model sends it. */
    private static function reads(int $call): array
    {
        return ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c' . $call, 'type' => 'function', 'function' => ['name' => 'read', 'arguments' => '{"n":' . $call . '}']]]];
    }

    /**
     * One invocation of `agent` on a fresh session, with the real loop and a model that answers `$script(n)` to its
     * n-th call.
     *
     * @param \Closure(int): (string|array<string, mixed>) $script
     * @param array<string, mixed>                         $input   more of the operation's input
     * @param list<int>                                    $windows the context window of each leg, in order (none: no limit)
     * @param array<string, mixed>                         $config  the house's configuration
     *
     * @return array<string, mixed>
     */
    private function agent(\Closure $script, AutonomyMode $mode = AutonomyMode::Auto, array $input = [], array $windows = [], string $result = 'read', bool $refuse = false, bool $ask = false, array $config = []): array
    {
        $this->events = new InMemoryEventStore();
        $this->sessions = new SessionStore($this->events);
        $this->sessions->start('s', 'Build the blog page', $mode);
        $container = new DIContainer();
        $container->registerService(SessionStore::class, $this->sessions);
        $container->registerService(EventStoreInterface::class, $this->events);
        if ($config !== []) {
            $container->registerService(Config::class, new Config($config));
        }
        $kernel = Kernel::boot(['root' => \dirname(__DIR__, 2), 'container' => $container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => []]);
        $container->registerService(Kernel::class, $kernel);

        $this->calls = 0;
        $llm = $this->createMock(LlmService::class);
        $llm->method('generateResponse')->willReturnCallback(function () use ($script, $ask): array {
            $reply = $script(++$this->calls);
            if ($ask && $this->calls === 12) {
                $this->sessions->ask('s', new PendingQuestion('q', 'Which option?', ['continue']));
            }

            return \is_string($reply) ? ['role' => 'assistant', 'content' => $reply] : $reply;
        });
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([['name' => 'read', 'description' => 'Read', 'inputSchema' => ['type' => 'object']]]);
        $tools->method('callTool')->willReturnCallback(static function () use ($result, $refuse): string {
            if ($refuse) {
                throw new ToolCallRefused('The session asks first.');
            }

            return $result;
        });
        $this->ops = new GoOnFixtureOperations($container);
        $this->ops->model = $llm;
        $this->ops->tools = $tools;
        $this->ops->windows = $windows;

        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=fixture-key');
        try {
            foreach ($this->ops->operations() as $op) {
                if ($op->name === 'agent') {
                    return ($op->handler)(['prompt' => 'Continue', 'session' => 's'] + $input);
                }
            }
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }
        self::fail('agent is not offered');
    }

    /** @return list<string> */
    private function userTurns(): array
    {
        return array_values(array_map(
            static fn (array $turn): string => $turn['content'],
            array_filter($this->sessions->load('s')?->turns ?? [], static fn (array $turn): bool => $turn['role'] === 'user'),
        ));
    }

    /** @return list<array<string, mixed>> the payloads of the session's facts of one type */
    private function facts(string $type): array
    {
        return array_values(array_map(
            static fn ($event): array => $event->payload,
            array_filter($this->events->replay('agent-session:s'), static fn ($event): bool => $event->type === $type),
        ));
    }
}

/** A leg whose loop is the real one, built anew for each leg with the ceiling the house gave it, asking a scripted model. */
final class GoOnFixtureOperations extends AgentOperations
{
    public ?LlmService $model = null;

    public ?GatedToolCalls $tools = null;

    /** @var list<int> the context window of each leg, in order */
    public array $windows = [];

    /** @var list<int> the ceiling of steps each leg was given */
    public array $ceilings = [];

    protected function orchestrator(LlmService $modeloRemoto, GatedToolCalls $cliente, int $pasos, ?PlanBoard $tablero, bool $lazyTools, ?SessionProgressProbe $sonda): AgentOrchestrator
    {
        \assert($this->model !== null && $this->tools !== null);
        $window = $this->windows[\count($this->ceilings)] ?? 0;
        $this->ceilings[] = $pasos;

        return $window > 0
            ? new AgentOrchestrator($this->model, $this->tools, $pasos, contextTokens: $window, outputTokens: intdiv($window, 4))
            : new AgentOrchestrator($this->model, $this->tools, $pasos);
    }
}
