<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AiGateway\{LlmService,McpClientService};
use Milpa\AppRuntime\Config\{AgentEndpoint,AgentKeys};
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Config;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

final class OllamaReasoningWiringTest extends TestCase
{
    public function testTheDeclaredKeyAcceptsOnlyDocumentedEfforts(): void
    {
        self::assertTrue(AgentKeys::conocida('agent.ollamaReasoningEffort'));
        self::assertNull(AgentEndpoint::ollamaReasoningEffort(null));
        self::assertNull(AgentEndpoint::ollamaReasoningEffort(new Config([])));
        foreach (['low', 'medium', 'high'] as $effort) {
            self::assertSame($effort, AgentEndpoint::ollamaReasoningEffort(
                new Config(['agent' => ['ollamaReasoningEffort' => $effort]])
            ));
            self::assertSame($effort, AgentKeys::coerceDeclaredValue('agent.ollamaReasoningEffort', $effort));
        }
        foreach ([null, true, false, 1, [], '', 'disabled', 'max'] as $value) {
            try {
                AgentEndpoint::ollamaReasoningEffort(new Config(['agent' => ['ollamaReasoningEffort' => $value]]));
                self::fail('Invalid effort accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('agent.ollamaReasoningEffort', $error->getMessage());
            }
        }
    }

    public function testTheNativeOrchestratorSendsLowEffortToOllamaCloud(): void
    {
        $container = new DIContainer();
        $container->registerService(Config::class, new Config(['agent' => [
            'baseUrl' => 'https://ollama.com/v1',
            'model' => 'glm-5.3-flash',
            'outputTokens' => 16384,
            'ollamaReasoningEffort' => 'low',
        ]]));
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('sendRequest')->willReturnCallback(
            static function (RequestInterface $request): Response {
                $wire = json_decode((string) $request->getBody(), true);
                self::assertSame('low', $wire['reasoning_effort']);
                self::assertSame('glm-5.3-flash', $wire['model']);
                self::assertSame(16384, $wire['max_tokens']);
                return new Response(200, [], json_encode(['choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'Complete.'],
                ]]]));
            }
        );
        $llm = new LlmService(
            '',
            'glm-5.3-flash',
            'openai',
            httpClient: $http,
            baseUrl: 'https://ollama.com/v1'
        );
        $tools = $this->createMock(McpClientService::class);
        $tools->method('getToolSummaries')->willReturn([]);
        $tools->expects(self::never())->method('callTool');

        $loop = (new \ReflectionMethod(AgentOperations::class, 'orchestrator'))
            ->invoke(new AgentOperations($container), $llm, $tools, 1, null, false, null);

        self::assertSame('Complete.', $loop->run('Answer.'));
    }

    public function testExplicitEffortRefusesAnOlderGatewayBeforeGeneration(): void
    {
        $container = new DIContainer();
        $container->registerService(Config::class, new Config(['agent' => ['ollamaReasoningEffort' => 'low']]));
        $ops = new class ($container) extends AgentOperations {
            protected function ollamaReasoningEffortAvailable(): bool
            {
                return false;
            }
        };
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::never())->method('generateResponse');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('profile-aware gateway');
        (new \ReflectionMethod(AgentOperations::class, 'orchestrator'))
            ->invoke($ops, $llm, $this->createMock(McpClientService::class), 1, null, false, null);
    }
}
