<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\ModelCallIntake;
use Milpa\AiGateway\{ChannelObserver,LlmService,McpClientService};
use Milpa\AppRuntime\Agent\{DiagnosticContract,ObservedExecutor};
use Milpa\AppRuntime\Config\{AgentEndpoint,AgentKeys};
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Tests\Agent\DiagnosticJudgeTest;
use Milpa\Container\DIContainer;
use Milpa\EventStore\{EventStoreInterface,InMemoryEventStore};
use Milpa\Runtime\Config;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

/** Configuration must reach the parent loop and the actual observed request. @internal */
final class OutputBudgetWiringTest extends TestCase
{
    protected function setUp(): void
    {
        AgentEndpoint::useProviderFetcher(static fn (string $url): ?string => null);
    }

    protected function tearDown(): void
    {
        AgentEndpoint::useProviderFetcher(null);
    }

    public function testTheDeclaredKeyIsTypedAndInvalidValuesAreNotIgnored(): void
    {
        self::assertSame('int', AgentKeys::todas()['agent.outputTokens']['type']);
        self::assertNull(AgentEndpoint::outputTokens(null));
        foreach ([null,0,-1,'8192',8192.5,true,[]] as $value) {
            try {
                AgentEndpoint::outputTokens(new Config(['agent' => ['outputTokens' => $value]]));
                self::fail('Invalid declaration accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('agent.outputTokens', $e->getMessage());
            }
        }
    }

    public function testBothParentFactoryPathsTransmitAndObserveTheDeclaration(): void
    {
        foreach ([false,true] as $diagnostic) {
            foreach ([null,8192] as $limit) {
                $container = new DIContainer();
                $config = ['contextTokens' => 32768,'baseUrl' => 'http://fixture.invalid'];
                if ($limit !== null) {
                    $config['outputTokens'] = $limit;
                }
                $container->registerService(Config::class, new Config(['agent' => $config]));
                $events = new InMemoryEventStore();
                $container->registerService(EventStoreInterface::class, $events);
                $ops = new AgentOperations($container);
                if ($diagnostic) {
                    $store = $ops->sessionStore();
                    $store->start('s', 'Diagnose');
                    DiagnosticContract::record($events, 's', DiagnosticJudgeTest::criterion() + ['output' => 'json_schema'], ObservedExecutor::unknown());
                    (new \ReflectionProperty(AgentOperations::class, 'promptSession'))->setValue($ops, $store->load('s'));
                }
                $observer = new class () implements ChannelObserver {
                    public ?ModelCallIntake $intake = null;
                    public function observe(string $uri, array $payload): void
                    {
                        $this->intake = ModelCallIntake::fromChannelPayload($uri, $payload);
                    }
                };
                $http = $this->createMock(ClientInterface::class);
                $http->expects(self::once())->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use ($limit, $diagnostic): Response {
                    $q = json_decode((string)$request->getBody(), true);
                    self::assertSame($limit ?? 4096, $q['max_completion_tokens']);
                    self::assertSame($diagnostic, isset($q['response_format']));
                    return new Response(200, [], json_encode(['choices' => [['finish_reason' => 'stop','message' => ['role' => 'assistant','content' => 'The complete answer.']]]]));
                });
                $llm = new LlmService('', 'fixture', 'openai', httpClient:$http, channelObserver:$observer);
                $tools = $this->createMock(McpClientService::class);
                $tools->method('getToolSummaries')->willReturn([]);
                $tools->expects(self::never())->method('callTool');
                $loop = (new \ReflectionMethod(AgentOperations::class, 'orchestrator'))->invoke($ops, $llm, $tools, 1, null, false, null);
                $loop->run('Answer.');
                self::assertSame(['max_completion_tokens' => $limit ?? 4096], $observer->intake->outputBudget);
            }
        }
    }

    public function testAnOlderGatewayRefusesExplicitOutputBeforeGeneration(): void
    {
        $container = new DIContainer();
        $container->registerService(Config::class, new Config(['agent' => ['outputTokens' => 8192,'contextTokens' => 32768]]));
        $ops = new class ($container) extends AgentOperations {
            protected function orchestratorAdmitsOutputTokens(): bool
            {
                return false;
            }
        };
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::never())->method('generateResponse');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('output-budget-aware');
        (new \ReflectionMethod(AgentOperations::class, 'orchestrator'))->invoke($ops, $llm, $this->createMock(McpClientService::class), 1, null, false, null);
    }
}
