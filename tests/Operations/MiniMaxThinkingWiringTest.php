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

/** Explicit mode crosses both native factory paths and the durable intake. */
final class MiniMaxThinkingWiringTest extends TestCase
{
    protected function setUp(): void
    {
        AgentEndpoint::useProviderFetcher(static fn (string $url): ?string => null);
    }

    protected function tearDown(): void
    {
        AgentEndpoint::useProviderFetcher(null);
    }

    public function testTheDeclaredKeyRejectsInvalidOrUnsupportedValues(): void
    {
        self::assertTrue(AgentKeys::conocida('agent.minimaxThinking'));
        self::assertNull(AgentEndpoint::miniMaxThinking(null));
        self::assertNull(AgentEndpoint::miniMaxThinking(new Config([])));
        foreach ([null, true, false, 1, [], '', 'enabled', 'none'] as $value) {
            try {
                AgentEndpoint::miniMaxThinking(new Config(['agent' => ['minimaxThinking' => $value]]));
                self::fail('Invalid mode accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('agent.minimaxThinking', $e->getMessage());
            }
        }
        self::assertSame('disabled', AgentKeys::coerceDeclaredValue('agent.minimaxThinking', 'disabled'));
        self::assertSame('adaptive', AgentKeys::coerceDeclaredValue('agent.minimaxThinking', 'adaptive'));
    }

    public function testBothNativeFactoryPathsTransmitAndRecordTheMode(): void
    {
        foreach ([false, true] as $diagnostic) {
            foreach ([null, 'disabled', 'adaptive'] as $mode) {
                $container = new DIContainer();
                $config = ['contextTokens' => 131072, 'outputTokens' => 16384, 'baseUrl' => 'https://fixture.invalid'];
                if ($mode !== null) {
                    $config['minimaxThinking'] = $mode;
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
                $http->expects(self::once())->method('sendRequest')->willReturnCallback(function (RequestInterface $r) use ($mode, $diagnostic): Response {
                    $wire = json_decode((string) $r->getBody(), true);
                    self::assertSame($mode === null ? null : ['type' => $mode], $wire['thinking'] ?? null);
                    self::assertSame(16384, $wire['max_completion_tokens']);
                    self::assertSame($diagnostic, isset($wire['response_format']));
                    return new Response(200, [], json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Complete.']]]]));
                });
                $llm = new LlmService('', 'MiniMax-M3', 'openai', httpClient: $http, channelObserver: $observer);
                $tools = $this->createMock(McpClientService::class);
                $tools->method('getToolSummaries')->willReturn([]);
                $tools->expects(self::never())->method('callTool');
                $loop = (new \ReflectionMethod(AgentOperations::class, 'orchestrator'))->invoke($ops, $llm, $tools, 1, null, false, null);
                $loop->run('Answer.');
                self::assertSame($mode === null ? null : ['type' => $mode], $observer->intake->thinking);
                self::assertSame($observer->intake->thinking, $observer->intake->toPayload()['thinking'] ?? null);
            }
        }
    }

    public function testExplicitModeRefusesAnOlderDependencyBeforeGeneration(): void
    {
        $container = new DIContainer();
        $container->registerService(Config::class, new Config(['agent' => ['minimaxThinking' => 'disabled']]));
        $ops = new class ($container) extends AgentOperations {
            protected function miniMaxThinkingAvailable(): bool
            {
                return false;
            }
        };
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::never())->method('generateResponse');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('profile-aware');
        (new \ReflectionMethod(AgentOperations::class, 'orchestrator'))->invoke($ops, $llm, $this->createMock(McpClientService::class), 1, null, false, null);
    }
}
