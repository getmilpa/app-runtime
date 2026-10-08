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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\PlanBoard;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Command\InvocationContext;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A leg is told what its session left half done — after the conversation, on every call (greenhouse decisions/0604,
 * rule D).
 *
 * A fold tells the next leg the plan and the todos; it did not say that an operation stood scaffolded with its body
 * to write. Now the leg the house assembles hands its loop that list with the locators of recorded results: what
 * the loop writes after the conversation on every model call. So it is read again from the record each time —
 * measured in a real run, a list said once at the start of a leg still said four when one had been written — and the
 * beginning of the request does not move when it changes.
 *
 * @guards the leg handing its loop the list when something stands; the list read again on every call, so a file
 *         leaves it the moment its writer lands, inside the same leg; not a byte said when nothing stands; the
 *         system prompt saying nothing of it
 *
 * @refuses a list said once and left to go stale; moving the beginning of the request to say it
 *
 * @subject-in milpa/app-runtime
 */
final class ALegIsToldWhatStandsHalfDoneTest extends TestCase
{
    use BuiltHouse;

    private const OPEN = 'src/Plugins/Ledger/Operations/OpenAccount.php';

    public function testTheLegSaysItAfterTheConversationAndReadsItAgainOnEveryCall(): void
    {
        [$operations, $loop, $sessions] = $this->house();
        self::land($sessions, 'make', ['what' => 'operation', 'plugin' => 'Ledger', 'name' => 'OpenAccount'], 'w1', 'added');
        // Inside the leg, between two calls to the model, the body is written and lands.
        $loop->between = static fn () => self::land($sessions, 'implement', ['plugin' => 'Ledger', 'class' => 'OpenAccount'], 'w2', 'modified');

        self::leg($operations);

        self::assertCount(2, $loop->said, 'the loop asked for what rides after the conversation on each of its two calls');
        self::assertStringContainsString('<half-done>', $loop->said[0]);
        self::assertStringContainsString(str_replace('/', '\\/', self::OPEN), $loop->said[0]);
        self::assertStringNotContainsString('<half-done>', $loop->said[1], 'its writer landed inside the leg: it is no longer said');
        self::assertStringNotContainsString('half-done', (string) $loop->system, 'and the system prompt, which does not move inside a leg, never said it');
    }

    public function testNothingIsAddedWhenNothingStands(): void
    {
        [$operations, $loop, $sessions] = $this->house();
        self::land($sessions, 'make', ['what' => 'operation', 'plugin' => 'Ledger', 'name' => 'OpenAccount'], 'w1', 'added');
        self::land($sessions, 'implement', ['plugin' => 'Ledger', 'class' => 'OpenAccount'], 'w2', 'modified');

        self::leg($operations);

        self::assertStringNotContainsString('half-done', implode('', $loop->said));
    }

    public function testAScaffoldThatNeverLandedIsNotSaidToTheLeg(): void
    {
        [$operations, $loop, $sessions] = $this->house();
        $sessions->recordToolCall('bv', 'make', ['what' => 'operation', 'plugin' => 'Ledger', 'name' => 'OpenAccount'], (string) json_encode([
            'ran_in_trial' => true, 'applied' => false, 'workspace' => 'w1', 'changed' => [self::OPEN => 'added'], 'output' => ['ok' => true],
        ]), mutating: true);

        self::leg($operations);

        self::assertStringNotContainsString('half-done', implode('', $loop->said));
    }

    /**
     * The leg reads it with the house's own declarations of what lasts, as its closure does (decisions/0523): a call
     * recorded as a mutation whose operation declares none today takes no scaffold down.
     */
    public function testTheLegReadsItWithTheHousesDeclarationsOfWhatLasts(): void
    {
        [$operations, $loop, $sessions] = $this->house();
        self::land($sessions, 'make', ['what' => 'operation', 'plugin' => 'Ledger', 'name' => 'OpenAccount'], 'w1', 'added');
        // `ledger:peek` only reads: this house declares so. A record that says it mutated and names the file changes nothing.
        $sessions->recordToolCall('bv', 'ledger_peek', [], (string) json_encode(['ok' => true, 'changed' => [self::OPEN => 'modified']]), mutating: true);

        self::leg($operations);

        self::assertStringContainsString('<half-done>', $loop->said[0], 'the scaffold still stands');
    }

    /** One writer rehearsed in a trial and that trial promoted, as the house records the two. */
    private static function land(SessionStore $sessions, string $tool, array $arguments, string $workspace, string $how): void
    {
        $sessions->recordToolCall('bv', $tool, $arguments, (string) json_encode([
            'ran_in_trial' => true, 'applied' => false, 'workspace' => $workspace, 'changed' => [self::OPEN => $how], 'output' => ['ok' => true],
        ]), mutating: true);
        $sessions->recordToolCall('bv', 'sandbox_promote', ['workspace' => $workspace], (string) json_encode([
            'ok' => true,
            'promoted' => [self::OPEN],
            'evidence' => ['predicate' => 'promoted', 'subject' => $workspace, 'environment' => ['kind' => 'house'], 'from' => ['kind' => 'trial', 'workspace' => $workspace], 'paths' => [self::OPEN]],
        ]), mutating: true);
    }

    /** @return array{0: HalfDoneFixtureOperations, 1: TrailingRecorder, 2: SessionStore} a fixture house with one session of a seat */
    private function house(): array
    {
        $root = $this->root();
        $events = new InMemoryEventStore();
        $sessions = new SessionStore($events);
        $sessions->start('bv', 'Build a plugin named Ledger to open an account.', AutonomyMode::Auto, by: new Principal('key:' . self::SEAT, true));
        $container = new DIContainer();
        // A house with one capability whose one verb only reads: its catalogue is what says which calls last.
        $kernel = $this->kernel($root, [$this->capability($root, 'Ledger', [$this->verb('ledger:peek', ['ledger:read'])])], [], $container);
        (new \ReflectionProperty(Kernel::class, 'toolRegistry'))->setValue($kernel, new ToolRegistry(new NullLogger()));
        $container->registerService(Kernel::class, $kernel);
        $container->registerService(SessionStore::class, $sessions);
        $container->registerService(EventStoreInterface::class, $events);
        $operations = new HalfDoneFixtureOperations($container);
        (new \ReflectionProperty(AgentOperations::class, 'trialRouterMemo'))->setValue($operations, null);
        $loop = new TrailingRecorder($this->createMock(LlmService::class), $this->createMock(GatedToolCalls::class));
        $operations->loop = $loop;

        return [$operations, $loop, $sessions];
    }

    private static function leg(AgentOperations $operations): void
    {
        $authority = new ToolContext(principal: 'key:' . self::SEAT, channel: 'cli', scopes: ['*']);
        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=fixture-key');
        try {
            foreach ($operations->operations() as $operation) {
                if ($operation->name === 'agent') {
                    ($operation->handler)(['prompt' => 'continue', 'session' => 'bv'], new InvocationContext($authority->principal, true), $authority);

                    return;
                }
            }
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }
        self::fail('the agent operation was not offered');
    }
}

/** A leg whose orchestrator is the test's. */
final class HalfDoneFixtureOperations extends AgentOperations
{
    public ?TrailingRecorder $loop = null;

    protected function orchestrator(LlmService $modeloRemoto, GatedToolCalls $cliente, int $pasos, ?PlanBoard $tablero, bool $lazyTools, ?SessionProgressProbe $sonda): AgentOrchestrator
    {
        \assert($this->loop !== null);

        return $this->loop;
    }
}

/**
 * A loop that makes two calls to its model and, like the real one, asks before each for what rides after the
 * conversation — remembering what it was handed, and letting the test do something between the two.
 */
final class TrailingRecorder extends AgentOrchestrator
{
    public ?string $system = null;

    /** @var list<string> */
    public array $said = [];

    public ?\Closure $between = null;

    private ?\Closure $trailing = null;

    public function setTrailingProjection(?callable $projection): self
    {
        $this->trailing = $projection === null ? null : \Closure::fromCallable($projection);

        return parent::setTrailingProjection($projection);
    }

    public function run(string $prompt, string $systemPrompt = 'You are a helpful assistant.', array $history = [], ?callable $onStep = null): string
    {
        $this->system = $systemPrompt;
        $this->said[] = $this->trailing === null ? '' : ($this->trailing)([]);
        if ($this->between !== null) {
            ($this->between)();
        }
        $this->said[] = $this->trailing === null ? '' : ($this->trailing)([]);

        return 'Done.';
    }
}
