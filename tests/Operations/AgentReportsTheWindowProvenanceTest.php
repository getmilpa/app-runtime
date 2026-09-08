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
use Milpa\AppRuntime\Config\AgentEndpoint;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Gate\ToolCallGate;
use Milpa\ToolRuntime\Gate\ToolCallRecorder;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Point 4 of greenhouse decisions/0233: the answer SAYS where the window came from.
 *
 * A budget that changes without saying why is a budget nobody can debug. A human who declared
 * 100,000 and finds the run compacting at 32,768 must be able to read that the PROVIDER said so —
 * not be left wondering whether their configuration was read at all.
 *
 * `contextTokens` in the same result is a DIFFERENT number and stays untouched: it is the context
 * IN PLAY, the last call's prompt tokens straight from the provider's usage. These keys are the
 * window that BOUNDS it. Relabelling the old one would have been a lie about what it measures.
 */
final class AgentReportsTheWindowProvenanceTest extends TestCase
{
    private const MODELS_AS_MEASURED = '{"data":[{"id":"qwen3.8-27b","meta":{"n_ctx":32768,"n_ctx_train":262144}}]}';

    /** @var array<string, false|string> */
    private array $antes = [];

    protected function setUp(): void
    {
        foreach (['MILPA_AGENT_BASE_URL', 'MILPA_AGENT_CONTEXT_TOKENS'] as $v) {
            $this->antes[$v] = getenv($v);
            putenv($v);
        }
        AgentEndpoint::useProviderFetcher(static fn (string $url): ?string => null);
    }

    protected function tearDown(): void
    {
        foreach ($this->antes as $v => $valor) {
            $valor === false ? putenv($v) : putenv($v . '=' . $valor);
        }
        AgentEndpoint::useProviderFetcher(null);
    }

    /** A declaration the provider clipped is reported as clipped, with the surviving number. */
    public function testTheResultSaysTheProviderTightenedTheWindow(): void
    {
        AgentEndpoint::useProviderFetcher(static fn (string $url): ?string => str_ends_with($url, '/v1/models') ? self::MODELS_AS_MEASURED : null);

        $result = $this->runAgentWith(['baseUrl' => 'http://provider.test', 'contextTokens' => 100000]);

        self::assertSame(32768, $result['contextWindow'] ?? null);
        self::assertSame('tightened-by-the-provider', $result['contextWindowSource'] ?? null);
        self::assertFalse($result['contextWindowCouldNotAsk'] ?? null);
    }

    /** A provider that did not answer leaves the declaration standing AND is said out loud. */
    public function testTheResultSaysWhenItCouldNotAskTheProvider(): void
    {
        $result = $this->runAgentWith(['baseUrl' => 'http://provider.test', 'contextTokens' => 100000]);

        self::assertSame(100000, $result['contextWindow'] ?? null, 'the run continues with what was declared');
        self::assertSame('declared', $result['contextWindowSource'] ?? null);
        self::assertTrue($result['contextWindowCouldNotAsk'] ?? null, 'never quiet about an unverified ceiling');
    }

    /**
     * THE CONTROL: an app with neither source reports no window and today's behaviour.
     *
     * `contextTokens` — a different figure with a different meaning — is untouched by any of this,
     * which is what «backward compatible» has to mean here.
     */
    public function testAnAppWithNeitherSourceReportsNoWindowAndKeepsTheOldFigureIntact(): void
    {
        $result = $this->runAgentWith([], recordUsage: true);

        self::assertArrayHasKey('contextWindow', $result, 'the key is present and null — «there is none», not «I forgot to say»');
        self::assertNull($result['contextWindow']);
        self::assertSame('undeclared', $result['contextWindowSource'] ?? null);
        self::assertFalse($result['contextWindowCouldNotAsk'] ?? null, 'there was nobody to ask');
        self::assertSame(120, $result['contextTokens'] ?? null, 'the context IN PLAY is untouched by the window');
        self::assertSame(280, $result['tokens'] ?? null);
    }

    /**
     * @param array<string, mixed> $agent
     *
     * @return array<string, mixed>
     */
    private function runAgentWith(array $agent, bool $recordUsage = false): array
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'do the work');
        if ($recordUsage) {
            $store->recordModelReturn('s1', ['usage' => ['prompt_tokens' => 90, 'completion_tokens' => 40, 'total_tokens' => 130]]);
            $store->recordModelReturn('s1', ['usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30, 'total_tokens' => 150]]);
        }

        $container = new DIContainer();
        $container->registerService(EventStoreInterface::class, $events);
        $container->registerService(SessionStore::class, $store);
        if ($agent !== []) {
            $container->registerService(Config::class, new Config(['agent' => $agent]));
        }
        $kernel = Kernel::boot([
            'root' => \dirname(__DIR__, 2),
            'container' => $container,
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [],
        ]);
        $container->registerService(Kernel::class, $kernel);
        $operations = new WindowReportingAgentOperations($container);

        $previousOpenAi = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=test-key');

        try {
            $result = null;
            foreach ($operations->operations() as $operation) {
                if ($operation->name === 'agent') {
                    $result = ($operation->handler)(['prompt' => 'continue', 'session' => 's1']);
                }
            }
        } finally {
            $previousOpenAi === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previousOpenAi);
        }

        self::assertIsArray($result);
        self::assertTrue($result['ok'] ?? false, (string) ($result['error'] ?? 'agent run failed'));

        return $result;
    }
}

/** The provider call replaced with a no-op, so the run reaches its reporting without a model. */
final class WindowReportingAgentOperations extends AgentOperations
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
