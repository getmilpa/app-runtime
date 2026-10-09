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
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\PlanBoard;
use Milpa\AppRuntime\Agent\CapabilityExercise;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\AppRuntime\Agent\HouseGoesOn;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Agent\StandingAsk;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Tests\Fixtures\ExercisedTaller;
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
 * A PAUSE THE HOUSE CONTINUES IS NOT AN END — the invariant between two rules that meet at the end of a leg.
 *
 * One (greenhouse decisions/0605, R1): at the natural end of a leg the house runs the capability it is about to close
 * on, once, and records what it found before its verdict. The other (decisions/0604, rule A): after a pause for window
 * or for steps the house goes on by itself, inside the same invocation. Where they meet: a leg that paused and that
 * the house is about to continue has not ended. Nothing is run there and no verdict is recorded — even when the
 * session's record, at that very moment, WOULD close. The leg that follows is the one that ends: at its answer the
 * capability runs, once, and the verdict is recorded after it.
 *
 * The whole, as a leg does it: a house with the capability built in its own tree, a session whose promotion declared it
 * whole, the real loop, and the trial runner confining what the house runs.
 *
 * @guards nothing run and nothing closed at a pause the house continues, for steps and for window, over a record that
 *         would close; one receipt and one verdict, both after the house went on, when the leg that follows answers;
 *         nothing at all when it pauses too; an exhausted epilogue being an end the house does not go on after
 *
 * @refuses a verdict or an exercise recorded at the pause; a second exercise; a notice of the house that a session's
 *          standing ask would read as a route its goal wrote
 *
 * @subject-in milpa/app-runtime
 */
final class APauseTheHouseContinuesIsNotAnEndTest extends TestCase
{
    private const SESSION = 's';

    private SessionStore $sessions;

    private InMemoryEventStore $events;

    private DIContainer $container;

    private string $root;

    private int $modelCalls = 0;

    private int $toolCalls = 0;

    /** The first call the model got after the house went on. */
    private ?int $firstCallOfTheLegThatFollowed = null;

    /** @var list<list<array<string, mixed>>> the messages of each request the model got, in order */
    private array $sent = [];

    /** @var list<bool> whether the record would close, and had nothing run or closed yet, at each call the model got */
    private array $wouldCloseUnrun = [];

    protected function setUp(): void
    {
        if (! (new TrialRunner())->available()) {
            self::markTestSkipped('no unprivileged user namespace here: the sandbox cannot be exercised');
        }
        $this->root = (string) realpath(sys_get_temp_dir()) . '/milpa-pause-not-end-' . bin2hex(random_bytes(6));
        ExercisedTaller::in($this->root);
        $this->events = new InMemoryEventStore();
        $this->sessions = new SessionStore($this->events);
        $this->container = new DIContainer();
        $this->container->registerService(SessionStore::class, $this->sessions);
        $this->container->registerService(EventStoreInterface::class, $this->events);
        $kernel = Kernel::boot(['root' => $this->root, 'container' => $this->container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => [], 'config' => []]);
        $this->container->registerService(Kernel::class, $kernel);
        $this->sessions->start(self::SESSION, 'Build a plugin named Taller to keep the tools of a workshop.', AutonomyMode::Auto);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testAfterAStepPauseTheCapabilityRunsOnceAtTheAnswerOfTheLegThatFollows(): void
    {
        // The capability is declared whole by the last call of the leg: its steps run out over a record that closes.
        $r = $this->invocation(answersOnceTheHouseWentOn: true, declaredByToolCall: 12);

        self::assertSame('steps_exhausted', $r['wentOn']['after']);
        self::assertSame(12, $r['wentOn']['stepsBefore']);
        $this->assertItRanOnceAtTheAnswerOfTheLegThatFollowed($r);
    }

    public function testAfterAWindowPauseTheCapabilityRunsOnceAtTheAnswerOfTheLegThatFollows(): void
    {
        // Declared whole before the leg; the leg's window runs out on what it reads, before its epilogue does.
        ExercisedTaller::promoted($this->sessions, self::SESSION, ExercisedTaller::RUNS);
        $r = $this->invocation(answersOnceTheHouseWentOn: true, windows: [self::SMALL]);

        self::assertSame('context_budget_exhausted', $r['wentOn']['after']);
        $this->assertItRanOnceAtTheAnswerOfTheLegThatFollowed($r);
    }

    public function testTwoStepPausesRunNothingAndCloseNothing(): void
    {
        // Declared whole by the last call of the leg that FOLLOWS: the cap stops the house over a record that closes.
        $r = $this->invocation(answersOnceTheHouseWentOn: false, declaredByToolCall: 24);

        self::assertSame('steps_exhausted', $r['termination']['reason']);
        self::assertSame(['times' => 1, 'after' => 'steps_exhausted', 'stepsBefore' => 12, 'stepsInAll' => 24], $r['wentOn']);
        $this->assertNothingRanAndNothingClosed($r);
    }

    public function testTwoWindowPausesRunNothingAndCloseNothing(): void
    {
        ExercisedTaller::promoted($this->sessions, self::SESSION, ExercisedTaller::RUNS);
        $r = $this->invocation(answersOnceTheHouseWentOn: false, windows: [self::SMALL, self::SMALL]);

        self::assertSame('context_budget_exhausted', $r['termination']['reason']);
        self::assertSame(1, $r['wentOn']['times']);
        self::assertSame('context_budget_exhausted', $r['wentOn']['after']);
        self::assertContains(true, $this->wouldCloseUnrun, 'the control: the record would close, and the house paused over it');
        $this->assertNothingRanAndNothingClosed($r);
    }

    public function testAnExhaustedEpilogueIsAnEndTheHouseDoesNotGoOnAfter(): void
    {
        // Declared whole before the leg, room to spare, and a model that never answers: the house opens the epilogue,
        // its budget runs out, and that IS an end — the capability runs, the verdict is recorded, nothing follows.
        ExercisedTaller::promoted($this->sessions, self::SESSION, ExercisedTaller::RUNS);
        $r = $this->invocation(answersOnceTheHouseWentOn: false);

        self::assertSame('epilogue_exhausted', $r['termination']['reason']);
        self::assertArrayNotHasKey('wentOn', $r);
        self::assertSame([], $this->ofType(HouseGoesOn::EVENT));
        self::assertCount(1, $this->ofType(CapabilityExercise::EVENT));
        self::assertCount(1, $this->ofType(ClosureVerdict::EVENT));
        self::assertTrue($r['closure']['verified'] ?? false, implode('; ', $r['closure']['reasons'] ?? []));
    }

    /**
     * THE NOTICE AND WHAT THE HOUSE RAN AND THREW, IN THE SAME REQUEST (greenhouse decisions/0605: what threw is said to
     * the session that wrote it). An invocation ended on its answer, the house ran the capability and one operation
     * threw: nothing closed. The next invocation runs out of its steps and the house goes on. The request that opens
     * the leg that follows carries both, each in its own message and neither inside the other: the house's notice as
     * the turn that says why the leg follows, and after the conversation the section that names what threw.
     */
    public function testTheLegThatFollowsIsToldWhyItFollowsAndWhatTheHouseRanAndThrew(): void
    {
        ExercisedTaller::promoted($this->sessions, self::SESSION, [...ExercisedTaller::RUNS, 'taller:rota']);
        $ended = $this->invocation(answersOnceTheHouseWentOn: false, answersAtOnce: true);
        self::assertSame('final_answer', $ended['termination']['reason']);
        self::assertFalse($ended['closure']['verified'] ?? true, 'the control: what threw did not close');
        self::assertSame('threw', $this->ofType(CapabilityExercise::EVENT)[0]->payload['exercised']);
        self::assertSame([], $this->ofType(HouseGoesOn::EVENT));

        $r = $this->invocation(answersOnceTheHouseWentOn: true);

        self::assertSame('steps_exhausted', $r['wentOn']['after']);
        self::assertSame('final_answer', $r['termination']['reason']);
        $opened = $this->sent[$this->firstCallOfTheLegThatFollowed - 1];
        $notice = HouseGoesOn::notice('steps_exhausted');
        $noticed = array_keys(array_filter($opened, static fn (array $m): bool => ($m['content'] ?? null) === $notice));
        $told = array_keys(array_filter($opened, static fn (array $m): bool => str_contains((string) ($m['content'] ?? ''), '<half-done>')));
        self::assertCount(1, $noticed, 'the notice is one message, and it is the notice and nothing more');
        self::assertSame('user', $opened[$noticed[0]]['role']);
        self::assertSame([\count($opened) - 1], $told, 'the section is said once, after the conversation');
        self::assertGreaterThan($noticed[0], $told[0]);
        self::assertSame(1, preg_match('~<half-done>\n(.*)\n</half-done>~s', (string) $opened[$told[0]]['content'], $found));
        $data = json_decode($found[1], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['taller:rota'], array_column($data['ran_and_threw'], 'operation'));
        self::assertSame([], $data['scaffolded_not_written']);
        self::assertStringNotContainsString('[house]', (string) $opened[$told[0]]['content'], 'the section does not carry the notice');

        // The leg that paused was told the same from its first call, and opened with a person's turn, not the house's.
        $before = $this->sent[1];
        self::assertStringContainsString('"ran_and_threw"', (string) end($before)['content']);
        self::assertSame([], array_filter($before, static fn (array $m): bool => ($m['content'] ?? null) === $notice));

        // What threw still stands when the leg that follows answers: nothing landed, so the house does not run it again
        // and does not close.
        self::assertCount(1, $this->ofType(CapabilityExercise::EVENT));
        self::assertFalse($r['closure']['verified'] ?? true);
    }

    /**
     * What the house writes when it goes on is a turn of the session, and a session's standing ask reads every turn.
     * A notice that wrote a route as a goal writes one would make it the only thing that closes the session.
     */
    public function testTheNoticeOfTheHouseAsksForNoRoute(): void
    {
        foreach (HouseGoesOn::AFTER as $why) {
            self::assertSame([], StandingAsk::ofText(HouseGoesOn::notice($why))->explicitRoutes(), $why);
        }
    }

    /** @param array<string, mixed> $r */
    private function assertItRanOnceAtTheAnswerOfTheLegThatFollowed(array $r): void
    {
        self::assertSame('final_answer', $r['termination']['reason']);
        self::assertSame(1, $r['wentOn']['times']);
        self::assertSame($this->firstCallOfTheLegThatFollowed, $this->modelCalls, 'the leg that follows answered on its first call');
        self::assertTrue($this->wouldCloseUnrun[$this->modelCalls - 1], 'the control: when the leg that follows started, the record would close and nothing had run or closed');

        $went = $this->ofType(HouseGoesOn::EVENT);
        $ran = $this->ofType(CapabilityExercise::EVENT);
        $closed = $this->ofType(ClosureVerdict::EVENT);
        self::assertCount(1, $went);
        self::assertCount(1, $ran, 'one receipt');
        self::assertCount(1, $closed, 'one verdict');
        self::assertGreaterThan($went[0]->seq, $ran[0]->seq, 'the capability ran after the house went on, not at the pause');
        self::assertGreaterThan($ran[0]->seq, $closed[0]->seq, 'and the verdict was recorded after it ran');
        self::assertSame('ran', $ran[0]->payload['exercised']);
        self::assertTrue($r['closure']['verified'] ?? false, implode('; ', $r['closure']['reasons'] ?? []));
        self::assertSame('ran', $r['closure']['derivedFrom']['observation']['capability']['exercised']);
    }

    /** @param array<string, mixed> $r */
    private function assertNothingRanAndNothingClosed(array $r): void
    {
        self::assertArrayNotHasKey('closure', $r);
        self::assertCount(1, $this->ofType(HouseGoesOn::EVENT));
        self::assertSame([], $this->ofType(CapabilityExercise::EVENT), 'nothing was run');
        self::assertSame([], $this->ofType(ClosureVerdict::EVENT), 'and no verdict was recorded');
        self::assertDirectoryDoesNotExist($this->root . '/var/exercises');
        $session = $this->sessions->load(self::SESSION);
        self::assertNotNull($session);
        self::assertTrue(
            ClosureVerdict::derive($session, $this->sessions->facts(self::SESSION), $this->sessions->stream(self::SESSION))['verified'],
            'the control: the record the house stopped over would close',
        );
    }

    /** A window so small that what a leg reads fills it within two calls: before the epilogue's budget runs out. */
    private const SMALL = 3000;

    /**
     * One invocation of `agent`, with the real loop built anew for each leg. Its model calls a tool that changes
     * nothing; with `$answersOnceTheHouseWentOn` it gives its final answer on its first call after the house went on,
     * with `$answersAtOnce` on its first call of this invocation, and otherwise it never answers.
     * `$declaredByToolCall`: the tool call during which the promotion that declares the capability whole is recorded.
     *
     * @param list<int> $windows the context window of each leg, in order (none: no limit)
     *
     * @return array<string, mixed>
     */
    private function invocation(bool $answersOnceTheHouseWentOn, ?int $declaredByToolCall = null, array $windows = [], bool $answersAtOnce = false): array
    {
        $llm = $this->createMock(LlmService::class);
        $llm->method('generateResponse')->willReturnCallback(function (string $prompt, array $tools = [], array $messages = []) use ($answersOnceTheHouseWentOn, $answersAtOnce): array {
            ++$this->modelCalls;
            $this->sent[] = array_values($messages);
            $session = $this->sessions->load(self::SESSION);
            $this->wouldCloseUnrun[] = $session !== null
                && ClosureVerdict::derive($session, $this->sessions->facts(self::SESSION), $this->sessions->stream(self::SESSION))['verified']
                && $this->ofType(CapabilityExercise::EVENT) === [] && $this->ofType(ClosureVerdict::EVENT) === [];

            if ($this->ofType(HouseGoesOn::EVENT) !== []) {
                $this->firstCallOfTheLegThatFollowed ??= $this->modelCalls;
            }

            return $answersAtOnce || ($answersOnceTheHouseWentOn && $this->firstCallOfTheLegThatFollowed !== null)
                ? ['role' => 'assistant', 'content' => 'The capability is built.']
                : ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c' . $this->modelCalls, 'type' => 'function', 'function' => ['name' => 'read', 'arguments' => '{"n":' . $this->modelCalls . '}']]]];
        });
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([['name' => 'read', 'description' => 'Read', 'inputSchema' => ['type' => 'object']]]);
        $tools->method('callTool')->willReturnCallback(function () use ($declaredByToolCall, $windows): string {
            if (++$this->toolCalls === $declaredByToolCall) {
                ExercisedTaller::promoted($this->sessions, self::SESSION, ExercisedTaller::RUNS);
            }

            return $windows === [] ? 'Recorded result' : str_repeat('lorem ipsum ', 400);
        });
        $ops = new PauseFixtureOperations($this->container);
        $ops->model = $llm;
        $ops->tools = $tools;
        $ops->windows = $windows;

        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=fixture-key');
        try {
            foreach ($ops->operations() as $op) {
                if ($op->name === 'agent') {
                    /** @var array<string, mixed> */
                    return ($op->handler)(['prompt' => 'Build it', 'session' => self::SESSION]);
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

/** The agent operation whose loop is the real one, built anew for each leg with the ceiling the house gave it. */
final class PauseFixtureOperations extends AgentOperations
{
    public ?LlmService $model = null;

    public ?GatedToolCalls $tools = null;

    /** @var list<int> the context window of each leg, in order */
    public array $windows = [];

    private int $legs = 0;

    protected function orchestrator(LlmService $modeloRemoto, GatedToolCalls $cliente, int $pasos, ?PlanBoard $tablero, bool $lazyTools, ?SessionProgressProbe $sonda): AgentOrchestrator
    {
        \assert($this->model !== null && $this->tools !== null);
        $window = $this->windows[$this->legs++] ?? 0;

        return $window > 0
            ? new AgentOrchestrator($this->model, $this->tools, $pasos, progressProbe: $sonda, contextTokens: $window, outputTokens: intdiv($window, 4))
            : new AgentOrchestrator($this->model, $this->tools, $pasos, progressProbe: $sonda);
    }
}
