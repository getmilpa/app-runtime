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
use Milpa\AiGateway\PlanBoard;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Support\Capabilities;
use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\Gate\ToolCallRefused;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The model's door sees the app as it grows (greenhouse decisions/0226), like the sequence door does.
 *
 * Driven through the real `agent` operation and the real `ask()`: only the orchestrator seam is replaced
 * with a scripted turn that calls the SAME governed door `governedExecutor()` built. In one turn the model
 * switches a capability on (a provider lands in config/operations.php) and then names the operation it
 * just made possible. The control: before the app grew, that operation is UNJUDGEABLE — the door reads
 * the app, it never invents.
 */
final class TheModelDoorSeesTheAppAsItGrowsTest extends TestCase
{
    private string $root;

    private SessionStore $store;

    private InMemoryEventStore $events;

    private string|false $previousOpenAi = false;

    private string|false $previousAnthropic = false;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/model-door-grows-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o775, true);
        file_put_contents($this->root . '/config/operations.php', "<?php\n\nreturn [\n    \\" . GrowsTheAppProvider::class . "::class,\n];\n");
        GrowsTheAppProvider::$root = $this->root;

        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s1', 'grow then use', AutonomyMode::Auto);

        $this->previousOpenAi = getenv('OPENAI_API_KEY');
        $this->previousAnthropic = getenv('ANTHROPIC_API_KEY');
        putenv('OPENAI_API_KEY=test-key');
        putenv('ANTHROPIC_API_KEY');
    }

    protected function tearDown(): void
    {
        $this->previousOpenAi === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $this->previousOpenAi);
        $this->previousAnthropic === false ? putenv('ANTHROPIC_API_KEY') : putenv('ANTHROPIC_API_KEY=' . $this->previousAnthropic);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_turn_that_switches_a_capability_on_can_call_the_grown_operation_in_the_same_turn(): void
    {
        $container = new DIContainer();
        $container->registerService(EventStoreInterface::class, $this->events);
        $container->registerService(SessionStore::class, $this->store);
        $kernel = Kernel::boot(['root' => $this->root, 'container' => $container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => []]);
        $container->registerService(Kernel::class, $kernel);
        $ops = new ScriptedTurnAgentOperations($container);

        $result = null;
        foreach ($ops->operations() as $operation) {
            if ($operation->name === 'agent') {
                $result = ($operation->handler)(['prompt' => 'grow then use', 'session' => 's1']);
            }
        }
        self::assertIsArray($result);
        self::assertTrue($result['ok'] ?? false, (string) ($result['error'] ?? 'agent run failed'));

        // THE CONTROL: before the app grew, the door did not invent the operation.
        self::assertStringStartsWith('UNJUDGEABLE:', $ops->log['before'] ?? '', 'before growth the grown tool is unjudgeable');
        self::assertSame(['written' => [GrownMidTurnProvider::class]], $ops->log['grow'] ?? null, 'the step wrote the provider into config/operations.php');
        // THE SAME TURN, THE SAME DOOR: what the app just learned is judged and runs.
        self::assertSame(['ok' => true, 'says' => 'grown:x'], $ops->log['after'] ?? null, 'the door now judges and runs what the app just learned: ' . json_encode($ops->log));
    }
}

/** What the app offers at the start of the turn: the step that switches a capability on. */
final class GrowsTheAppProvider implements CommandProvider
{
    public static string $root = '';

    public function __construct(DIContainerInterface $container)
    {
    }

    public function operations(): array
    {
        return [new Operation(
            name: 'lab:grow',
            description: 'switch a capability on: declare a provider in config/operations.php',
            handler: static fn (array $input): array => ['written' => Capabilities::registerOperations(self::$root, [GrownMidTurnProvider::class])],
            inputSchema: ['type' => 'object', 'properties' => [], 'required' => []],
            effects: EffectProfile::readOnly(),
        )];
    }
}

/** What `capabilities:enable` declares: a provider the door was not born with. */
final class GrownMidTurnProvider implements CommandProvider
{
    public function __construct(DIContainerInterface $container)
    {
    }

    public function operations(): array
    {
        return [new Operation(
            name: 'lab:new',
            description: 'an operation the app learned mid-turn',
            handler: static fn (array $input): array => ['ok' => true, 'says' => 'grown:' . ($input['what'] ?? '')],
            inputSchema: ['type' => 'object', 'properties' => ['what' => ['type' => 'string']], 'required' => []],
            effects: EffectProfile::readOnly(),
        )];
    }
}

/** The real `ask()`, with the orchestrator seam replaced by one scripted turn through the real door. */
final class ScriptedTurnAgentOperations extends AgentOperations
{
    /** @var array<string, mixed> */
    public array $log = [];

    protected function orchestrator(
        LlmService $modeloRemoto,
        GatedToolCalls $cliente,
        int $pasos,
        ?PlanBoard $tablero,
        bool $lazyTools,
        ?SessionProgressProbe $sonda,
    ): AgentOrchestrator {
        return new ScriptedTurnOrchestrator($modeloRemoto, $cliente, $pasos, $this);
    }
}

/** One turn: the grown tool (control), the step that grows the app, the grown tool again. */
final class ScriptedTurnOrchestrator extends AgentOrchestrator
{
    public function __construct(
        LlmService $llm,
        private readonly GatedToolCalls $door,
        int $steps,
        private readonly ScriptedTurnAgentOperations $ops,
    ) {
        parent::__construct($llm, $door, $steps);
    }

    public function run(string $prompt, string $systemPrompt = 'You are a helpful assistant.', array $history = [], ?callable $onStep = null): string
    {
        try {
            $this->ops->log['before'] = $this->door->callTool('lab_new', ['what' => 'x']);
        } catch (ToolCallRefused $refused) {
            $this->ops->log['before'] = $refused->getMessage();
        }
        $this->ops->log['grow'] = $this->door->callTool('lab_grow', []);
        try {
            $this->ops->log['after'] = $this->door->callTool('lab_new', ['what' => 'x']);
        } catch (ToolCallRefused $refused) {
            $this->ops->log['after'] = $refused->getMessage();
        }
        if ($onStep !== null) {
            $onStep();
        }

        return 'done';
    }
}
