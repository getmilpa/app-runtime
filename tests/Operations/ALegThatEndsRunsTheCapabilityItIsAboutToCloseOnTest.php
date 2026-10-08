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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\PlanBoard;
use Milpa\AppRuntime\Agent\CapabilityExercise;
use Milpa\AppRuntime\Agent\ClosedSessionDoor;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Tests\Fixtures\ExercisedTaller;
use Milpa\Container\DIContainer;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A LEG THAT ENDS RUNS THE CAPABILITY IT IS ABOUT TO CLOSE ON (greenhouse decisions/0605, R1) — the whole, as a leg
 * does it: a house with a capability built in its own tree, a session whose last promotion declared it whole, and a
 * model that gives its final answer. The house then runs it in a trial of its own, confined by the trial runner, and
 * records what it found before it records its verdict.
 *
 * The leg's own process booted without that capability — as a real one does, which built it after booting. What the
 * house runs, it reads from the promotion's receipt and from a process started in the copy.
 */
final class ALegThatEndsRunsTheCapabilityItIsAboutToCloseOnTest extends TestCase
{
    private const SESSION = 's';

    private SessionStore $sessions;

    private InMemoryEventStore $events;

    private DIContainer $container;

    private string $root;

    private int $modelCalls = 0;

    protected function setUp(): void
    {
        if (! (new TrialRunner())->available()) {
            self::markTestSkipped('no unprivileged user namespace here: the sandbox cannot be exercised');
        }
        $this->root = (string) realpath(sys_get_temp_dir()) . '/milpa-leg-exercise-' . bin2hex(random_bytes(6));
        ExercisedTaller::in($this->root);
        $this->boot([]);
    }

    /**
     * The process of a leg: a kernel over the house, booted WITHOUT the capability — as the leg that built it was.
     *
     * @param array<string, mixed> $config the house's configuration
     */
    private function boot(array $config): void
    {
        $this->events = new InMemoryEventStore();
        $this->sessions = new SessionStore($this->events);
        $this->container = new DIContainer();
        $this->container->registerService(SessionStore::class, $this->sessions);
        $this->container->registerService(EventStoreInterface::class, $this->events);
        $kernel = Kernel::boot(['root' => $this->root, 'container' => $this->container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => [], 'config' => $config]);
        $this->container->registerService(Kernel::class, $kernel);
        $this->sessions->start(self::SESSION, 'Build a plugin named Taller to keep the tools of a workshop.', AutonomyMode::Auto);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testWhatRunsClosesAndTheVerdictSaysItRan(): void
    {
        $seq = $this->declared(ExercisedTaller::RUNS);
        $house = $this->digest();

        $r = $this->leg(['prompt' => 'Build it', 'session' => self::SESSION]);

        self::assertTrue($r['closure']['verified'] ?? false, implode('; ', $r['closure']['reasons'] ?? []));
        self::assertSame(
            ['subject' => 'Taller', 'seq' => $seq, 'capability' => ['operations' => 5, 'exercised' => 'ran', 'calls' => 10, 'answered' => 4, 'refused' => 1, 'behavior' => 'unjudged']],
            $r['closure']['derivedFrom']['observation'],
        );
        $receipts = $this->ofType(CapabilityExercise::EVENT);
        self::assertCount(1, $receipts);
        self::assertSame(TrialWorkspace::BOUNDS, $receipts[0]->payload['bounds'], 'it went through the house\'s own confinement');
        self::assertLessThan($this->ofType(ClosureVerdict::EVENT)[0]->seq, $receipts[0]->seq, 'what it found is recorded before the verdict that reads it');
        self::assertSame($house, $this->digest(), 'and the house is byte for byte what it was');
        self::assertSame([], TrialWorkspace::ids($this->root));
    }

    public function testWhatThrowsDoesNotCloseAndTheSessionIsToldWhich(): void
    {
        $seq = $this->declared([...ExercisedTaller::RUNS, 'taller:rota', 'taller:segunda']);

        $r = $this->leg(['prompt' => 'Build it', 'session' => self::SESSION]);

        self::assertFalse($r['closure']['verified'] ?? true);
        self::assertContains(
            "the house ran «Taller» in a trial before closing on it (seq {$seq}) and 2 of its 7 operations threw: "
            . '«taller:rota» threw Error: Call to undefined method MilpaTest\Exercised\Almacen::guardar(); '
            . '«taller:segunda» threw TypeError: array_merge(): Argument #2 must be of type array, int given'
            . ' — an operation that throws is not whole: fix it with implement',
            $r['closure']['reasons'],
        );
        self::assertSame('threw', $this->ofType(CapabilityExercise::EVENT)[0]->payload['exercised']);
    }

    public function testASessionAlreadyClosedIsNotRunAgain(): void
    {
        $this->declared(ExercisedTaller::RUNS);
        $this->leg(['prompt' => 'Build it', 'session' => self::SESSION]);
        self::assertSame(1, $this->modelCalls);

        $r = $this->leg(['prompt' => 'continue', 'session' => self::SESSION]);

        self::assertTrue($r['answeredWithoutModel'] ?? false, 'the control: the house answers a leg that asks nothing new');
        self::assertSame(1, $this->modelCalls);
        self::assertCount(1, $this->ofType(CapabilityExercise::EVENT), 'the door reads the stream: it runs nothing');
        self::assertCount(1, $this->ofType(ClosedSessionDoor::EVENT));
    }

    /**
     * The door of a closed session reads; it never runs. A session a house closed before it ran anything — recorded
     * `verified` with no receipt — is answered as closed when a leg asks nothing new of it. The next leg that really
     * ends is the one that runs it.
     */
    public function testTheDoorOfASessionClosedBeforeTheHouseRanAnythingRunsNothing(): void
    {
        $this->declared([...ExercisedTaller::RUNS, 'taller:rota']);
        $session = $this->sessions->load(self::SESSION);
        self::assertNotNull($session);
        $recorded = ClosureVerdict::derive($session, $this->sessions->facts(self::SESSION), $this->sessions->stream(self::SESSION));
        self::assertTrue($recorded['verified'], 'the control: what a house that ran nothing recorded');
        ClosureVerdict::record($this->events, self::SESSION, $recorded);

        $r = $this->leg(['prompt' => 'continue', 'session' => self::SESSION]);

        self::assertTrue($r['answeredWithoutModel'] ?? false);
        self::assertSame(0, $this->modelCalls);
        self::assertSame([], $this->ofType(CapabilityExercise::EVENT));
        self::assertDirectoryDoesNotExist($this->root . '/var/exercises');
    }

    public function testAHouseThatSwitchedTrialsOffRunsNothingAndSaysSo(): void
    {
        $this->boot(['agent' => ['trialWorkspace' => false]]);
        self::assertFalse($this->container->get(Config::class)->get('agent.trialWorkspace'), 'the control: the switch is off');
        $seq = $this->declared([...ExercisedTaller::RUNS, 'taller:rota']);

        $r = $this->leg(['prompt' => 'Build it', 'session' => self::SESSION]);

        self::assertTrue($r['closure']['verified'] ?? false, implode('; ', $r['closure']['reasons'] ?? []));
        self::assertSame(
            ['subject' => 'Taller', 'seq' => $seq, 'capability' => ['operations' => 6, 'exercised' => 'unjudged', 'why' => 'this house runs no trial: they are switched off, or it cannot confine a process']],
            $r['closure']['derivedFrom']['observation'],
            'it closes as it did, and says what it did not do and why',
        );
        self::assertDirectoryDoesNotExist($this->root . '/var/exercises');
    }

    /**
     * The last promotion of the session, as the house records it: what landed, and what the capability built there
     * declares. Its seq.
     *
     * @param list<string> $operations
     */
    private function declared(array $operations): int
    {
        $this->sessions->recordToolCall(self::SESSION, 'implement', ['plugin' => 'Taller', 'class' => 'Taller'], (string) json_encode([
            'ran_in_trial' => true, 'applied' => false, 'workspace' => 'w1', 'changed' => ['src/Plugins/Taller/Taller.php' => 'modified'], 'output' => ['ok' => true],
        ]), mutating: true);

        return $this->sessions->recordToolCall(self::SESSION, 'sandbox_promote', ['workspace' => 'w1'], (string) json_encode([
            'ok' => true,
            'promoted' => ['src/Plugins/Taller/Taller.php'],
            'evidence' => ['predicate' => 'promoted', 'subject' => 'w1', 'environment' => ['kind' => 'house'], 'from' => ['kind' => 'trial', 'workspace' => 'w1'], 'paths' => ['src/Plugins/Taller/Taller.php']],
            'capabilities' => [['predicate' => 'declared', 'subject' => 'Taller', 'environment' => ['kind' => 'house'], 'operations' => array_map(
                static fn (string $name): array => ['name' => $name, 'file' => 'src/Plugins/Taller/Taller.php', 'mutating' => false, 'effects' => true, 'scoped' => true],
                $operations,
            )]],
        ]), mutating: true);
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

            return ['role' => 'assistant', 'content' => 'The capability is built.'];
        });
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([['name' => 'read', 'description' => 'Read', 'inputSchema' => ['type' => 'object']]]);
        $ops = new ExerciseFixtureOperations($this->container);
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

    /**
     * Every file of the house and what it holds, and every directory — but the lease a leg takes on its own run
     * (`var/agent-runs/`), which is the leg's and was there before any of this.
     */
    private function digest(): string
    {
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $path = substr($entry->getPathname(), \strlen($this->root));
            if ($path === '/var/agent-runs' || str_starts_with($path, '/var/agent-runs/')) {
                continue;
            }
            $entries[] = $path . ($entry->isDir() ? '/' : ':' . hash_file('sha256', $entry->getPathname()));
        }
        sort($entries);

        return hash('sha256', implode("\n", $entries));
    }
}

/** The agent operation with its orchestrator handed in, so the model is a counter. */
final class ExerciseFixtureOperations extends AgentOperations
{
    public AgentOrchestrator $loop;

    protected function orchestrator(LlmService $modeloRemoto, GatedToolCalls $cliente, int $pasos, ?PlanBoard $tablero, bool $lazyTools, ?SessionProgressProbe $sonda): AgentOrchestrator
    {
        return $this->loop;
    }
}
