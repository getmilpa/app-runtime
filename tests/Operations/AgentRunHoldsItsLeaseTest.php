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
use Milpa\AppRuntime\Agent\RunLease;
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
 * Greenhouse decisions/0513 §3 and §5, wired: a leg holds its session's lease while it runs and records the window it
 * obeys — the two facts the panel read wrong in greenhouse evidence/1036 because nobody wrote them down.
 */
final class AgentRunHoldsItsLeaseTest extends TestCase
{
    private const MODELS_AS_MEASURED = '{"data":[{"id":"qwen3.8-27b","meta":{"n_ctx":49152,"n_ctx_train":262144}}]}';

    private string $root;

    /** @var array<string, false|string> */
    private array $before = [];

    protected function setUp(): void
    {
        foreach (['MILPA_AGENT_BASE_URL', 'MILPA_AGENT_CONTEXT_TOKENS', 'OPENAI_API_KEY'] as $v) {
            $this->before[$v] = getenv($v);
            putenv($v);
        }
        putenv('OPENAI_API_KEY=test-key');
        AgentEndpoint::useProviderFetcher(static fn (string $url): ?string => str_ends_with($url, '/v1/models') ? self::MODELS_AS_MEASURED : null);
        $this->root = sys_get_temp_dir() . '/milpa-lease-wiring-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        LeaseObservingAgentOperations::$seen = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->before as $v => $value) {
            $value === false ? putenv($v) : putenv($v . '=' . $value);
        }
        AgentEndpoint::useProviderFetcher(null);
        foreach (glob($this->root . '/var/agent-runs/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->root . '/var/agent-runs');
        @rmdir($this->root . '/var');
        @rmdir($this->root);
    }

    public function testTheLegHoldsItsLeaseWhileItAsksAndReleasesItAfter(): void
    {
        $result = $this->leg(['baseUrl' => 'http://provider.test']);

        self::assertTrue($result['ok'] ?? false, (string) ($result['error'] ?? ''));
        self::assertSame([true], LeaseObservingAgentOperations::$seen, 'while the model is asked, the session reads as running');
        self::assertFalse(RunLease::held($this->root, 's1'), 'once the leg returned, it does not');
    }

    public function testTheLegRecordsTheWindowItObeysBeforeTheModelIsCalled(): void
    {
        $events = new InMemoryEventStore();
        $this->leg(['baseUrl' => 'http://provider.test'], $events);

        $types = array_map(static fn ($e): string => $e->type, $events->replay(SessionStore::PREFIX . 's1'));
        $windows = array_values(array_filter($events->replay(SessionStore::PREFIX . 's1'), static fn ($e): bool => $e->type === AgentOperations::WINDOW_COMPOSED));

        self::assertCount(1, $windows, 'once per leg');
        self::assertSame(['tokens' => 49152, 'source' => 'measured', 'declared' => null, 'measured' => 49152], $windows[0]->payload);
        self::assertLessThan(array_search('session.run_terminated', $types, true), array_search(AgentOperations::WINDOW_COMPOSED, $types, true));
    }

    public function testTheRecordedWindowIsTheOneTheResultReports(): void
    {
        $events = new InMemoryEventStore();
        $result = $this->leg(['baseUrl' => 'http://provider.test', 'contextTokens' => 100000], $events);

        $windows = array_values(array_filter($events->replay(SessionStore::PREFIX . 's1'), static fn ($e): bool => $e->type === AgentOperations::WINDOW_COMPOSED));
        self::assertSame($result['contextWindow'], $windows[0]->payload['tokens']);
        self::assertSame($result['contextWindowSource'], $windows[0]->payload['source']);
        self::assertSame(100000, $windows[0]->payload['declared']);
    }

    /**
     * @param array<string, mixed> $agent
     *
     * @return array<string, mixed>
     */
    private function leg(array $agent, ?InMemoryEventStore $events = null): array
    {
        $events ??= new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s1', 'do the work');

        $container = new DIContainer();
        $container->registerService(EventStoreInterface::class, $events);
        $container->registerService(SessionStore::class, $store);
        $container->registerService(Config::class, new Config(['agent' => $agent]));
        $kernel = Kernel::boot(['root' => $this->root, 'container' => $container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => []]);
        $container->registerService(Kernel::class, $kernel);
        LeaseObservingAgentOperations::$root = $this->root;

        foreach ((new LeaseObservingAgentOperations($container))->operations() as $operation) {
            if ($operation->name === 'agent') {
                $result = ($operation->handler)(['prompt' => 'continue', 'session' => 's1']);
                self::assertIsArray($result);

                return $result;
            }
        }
        self::fail('no agent operation');
    }
}

/** The provider call replaced with one that asks whether the session is running while it is asked. */
final class LeaseObservingAgentOperations extends AgentOperations
{
    public static string $root = '';

    /** @var list<bool> */
    public static array $seen = [];

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
        self::$seen[] = RunLease::held(self::$root, 's1');
        $onStep();

        return 'done';
    }
}
