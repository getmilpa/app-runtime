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
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The agent operation reports the REAL token cost — the sum the provider spoke, not an estimate
 * (greenhouse decisions/0192). The total is every `session.model_returned` usage added up; the
 * context in play is the last call's prompt tokens. A provider that never reported usage leaves the
 * figure UNsaid — absent, not zero.
 */
final class AgentTokenCostTest extends TestCase
{
    public function testTheResultCarriesTheSummedProviderUsage(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'do the work');
        // Two turns the provider already counted: 90+40 then 120+30 tokens.
        $store->recordModelReturn('s1', ['usage' => ['prompt_tokens' => 90, 'completion_tokens' => 40, 'total_tokens' => 130]]);
        $store->recordModelReturn('s1', ['usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30, 'total_tokens' => 150]]);

        $result = $this->runAgentOn('s1', $events, $store);

        self::assertTrue($result['ok'] ?? false, (string) ($result['error'] ?? 'agent run failed'));
        self::assertSame(280, $result['tokens'] ?? null, 'the total is the sum of every reported usage');
        self::assertSame(120, $result['contextTokens'] ?? null, 'the context in play is the last call prompt tokens');
    }

    public function testAProviderThatNeverReportedUsageLeavesTheFigureUnsaid(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s2', 'do the work');
        // A turn was recorded, but the provider spoke no usage — the figure must stay absent, not zero.
        $store->recordModelReturn('s2', ['content' => 'done']);

        $result = $this->runAgentOn('s2', $events, $store);

        self::assertTrue($result['ok'] ?? false, (string) ($result['error'] ?? 'agent run failed'));
        self::assertArrayNotHasKey('tokens', $result, 'no usage means UNsaid — a surface must tell «no lo dijo» from «costó cero»');
        self::assertArrayNotHasKey('contextTokens', $result);
    }

    /**
     * @return array<string, mixed>
     */
    private function runAgentOn(string $sessionId, InMemoryEventStore $events, SessionStore $store): array
    {
        $container = new DIContainer();
        $container->registerService(EventStoreInterface::class, $events);
        $container->registerService(SessionStore::class, $store);
        $kernel = Kernel::boot([
            'root' => \dirname(__DIR__, 2),
            'container' => $container,
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [],
        ]);
        $container->registerService(Kernel::class, $kernel);
        $operations = new SilentAgentOperations($container);

        $previousOpenAi = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=test-key');

        try {
            $result = null;
            foreach ($operations->operations() as $operation) {
                if ($operation->name === 'agent') {
                    $result = ($operation->handler)(['prompt' => 'continue', 'session' => $sessionId]);
                }
            }
        } finally {
            $previousOpenAi === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previousOpenAi);
        }

        self::assertIsArray($result);

        return $result;
    }
}

/** The network seam replaced with a no-op so the run reaches its token accounting without a provider. */
final class SilentAgentOperations extends AgentOperations
{
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
        $onStep();

        return 'done';
    }
}
