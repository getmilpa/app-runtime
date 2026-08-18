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

use Milpa\Agent\SessionStore;
use Milpa\AiGateway\OptionTable;
use Milpa\AiGateway\PlanBoard;
use Milpa\AiGateway\ToolCallGate;
use Milpa\AiGateway\ToolCallRecorder;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The operation that composes a session window also hands its declaration to the intake recorder.
 */
final class AgentWindowRecordingTest extends TestCase
{
    public function testTheExactComposedWindowIsAvailableWhenTheIntakeIsRecorded(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'continue the work');
        $store->setPlan('s1', '1. Inspect 2. Change 3. Verify');
        $store->recordTurn('s1', 'assistant', 'previous answer');
        $session = $store->load('s1') ?? self::fail('the session must exist');
        $expectedWindow = $session->classifiedWindow();
        $expectedProviderHistory = $session->window();

        $container = new DIContainer();
        $container->registerService(SessionStore::class, $store);
        $kernel = Kernel::boot([
            'root' => \dirname(__DIR__, 2),
            'container' => $container,
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [],
        ]);
        $container->registerService(Kernel::class, $kernel);
        $operations = new WindowRecordingAgentOperations($container);
        $operations->spawnChild = true;

        $previousOpenAi = getenv('OPENAI_API_KEY');
        $previousAnthropic = getenv('ANTHROPIC_API_KEY');
        putenv('OPENAI_API_KEY=test-key');
        putenv('ANTHROPIC_API_KEY');

        try {
            $result = null;
            foreach ($operations->operations() as $operation) {
                if ($operation->name === 'agent') {
                    $result = ($operation->handler)(['prompt' => 'continue', 'session' => 's1']);
                }
            }
        } finally {
            $previousOpenAi === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previousOpenAi);
            $previousAnthropic === false ? putenv('ANTHROPIC_API_KEY') : putenv('ANTHROPIC_API_KEY=' . $previousAnthropic);
        }

        self::assertTrue($result['ok'] ?? false, (string) ($result['error'] ?? 'agent run failed'));

        $recorded = null;
        foreach ($events->replay(SessionStore::PREFIX . 's1') as $event) {
            if ($event->type === 'session.model_called') {
                $recorded = $event->payload;
            }
        }

        self::assertNotNull($recorded);
        self::assertSame($expectedWindow, $recorded['window']);
        self::assertSame($expectedProviderHistory, $operations->providerHistory);
        foreach ($operations->providerMessages as $message) {
            self::assertArrayNotHasKey('class', $message);
        }

        $children = array_values(array_filter(
            $store->ids(),
            static fn (string $id): bool => str_starts_with($id, 's1.sub-'),
        ));
        self::assertCount(1, $children);
        $childCalls = array_values(array_filter(
            $events->replay(SessionStore::PREFIX . $children[0]),
            static fn (object $event): bool => $event->type === 'session.model_called',
        ));
        self::assertCount(1, $childCalls, 'the child intake belongs to the child stream');
        self::assertSame([], $childCalls[0]->payload['window'], 'a fresh child receives an empty session window');
    }
}

/** The network seam replaced with an observed provider payload. */
final class WindowRecordingAgentOperations extends AgentOperations
{
    /** @var list<array<string, mixed>> */
    public array $providerMessages = [];

    /** @var list<array<string, mixed>> */
    public array $providerHistory = [];

    public bool $spawnChild = false;

    private bool $spawned = false;

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
        $spawn = $registry->getDefinition('agent_spawn');
        if ($spawn !== null) {
            $this->providerHistory = $history;
        }
        if ($this->spawnChild && !$this->spawned && $spawn !== null) {
            $this->spawned = true;
            ($spawn->callback)(['brief' => 'inspect the child session']);
        }

        $this->providerMessages = [
            ['role' => 'system', 'content' => 'you are an agent'],
            ...$history,
            ['role' => 'user', 'content' => $prompt],
        ];
        $this->observadorDeEntrada()?->observe('https://provider.test/v1/chat/completions', [
            'model' => $modelo,
            'messages' => $this->providerMessages,
        ]);
        $onStep();

        return 'done';
    }
}
