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

use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\PlanBoard;
use Milpa\AiGateway\ProviderRefusedException;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\AppRuntime\Config\AgentEndpoint;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A declared endpoint that answers 401 names the variable it wanted (greenhouse decisions/0551).
 *
 * Measured on a new house (evidence/1085): `MILPA_AGENT_BASE_URL` pointed at an endpoint that demands a
 * key, only `OPENAI_API_KEY` exported, and the run died on «HTTP 401 Unauthorized» with nothing naming
 * `MILPA_AGENT_API_KEY`. The provider key is never sent to a declared endpoint — on purpose — so the
 * refusal is where the house has to say so.
 */
final class ADeclaredEndpointNamesTheKeyItWantsTest extends TestCase
{
    private const array VARS = ['MILPA_AGENT_BASE_URL', 'MILPA_AGENT_API_KEY', 'OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'MILPA_AGENT_MODEL'];

    /** @var array<string, string|false> */
    private array $saved = [];

    private DIContainer $container;

    protected function setUp(): void
    {
        foreach (self::VARS as $var) {
            $this->saved[$var] = getenv($var);
            putenv($var);
        }
        AgentEndpoint::useProviderFetcher(static fn (string $url): ?string => null);
        $this->container = new DIContainer();
        $kernel = Kernel::boot(['root' => \dirname(__DIR__, 2), 'container' => $this->container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => []]);
        $this->container->registerService(Kernel::class, $kernel);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $var => $value) {
            $value === false ? putenv($var) : putenv($var . '=' . $value);
        }
        AgentEndpoint::useProviderFetcher(null);
    }

    public function testAnEndpointThatWantsAKeyNamesMilpaAgentApiKeyAndWhyTheProviderKeyStayed(): void
    {
        putenv('MILPA_AGENT_BASE_URL=http://lab.invalid:11434');
        putenv('OPENAI_API_KEY=sk-a-provider-secret');

        $r = $this->legAgainst(401);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('HTTP 401', (string) $r['error'], 'the provider sentence still travels as it came');
        $hint = (string) ($r['hint'] ?? '');
        self::assertStringContainsString('MILPA_AGENT_API_KEY is not set', $hint);
        self::assertStringContainsString('Export MILPA_AGENT_API_KEY', $hint);
        self::assertStringContainsString('OPENAI_API_KEY is never sent to a declared endpoint', $hint);
        self::assertStringNotContainsString('sk-a-provider-secret', $hint, 'a hint never prints a value');
    }

    public function testWithNoProviderKeyAroundTheHintDoesNotMentionOne(): void
    {
        putenv('MILPA_AGENT_BASE_URL=http://lab.invalid:11434');

        $hint = (string) ($this->legAgainst(403)['hint'] ?? '');

        self::assertStringContainsString('answered 403', $hint);
        self::assertStringContainsString('MILPA_AGENT_API_KEY is not set', $hint);
        self::assertStringNotContainsString('OPENAI_API_KEY', $hint);
    }

    public function testAKeyTheEndpointRefusedIsNamedAsTheOneItRefused(): void
    {
        putenv('MILPA_AGENT_BASE_URL=http://lab.invalid:11434');
        putenv('MILPA_AGENT_API_KEY=lab-wrong-secret');

        $hint = (string) ($this->legAgainst(401)['hint'] ?? '');

        self::assertStringContainsString('refused MILPA_AGENT_API_KEY (401)', $hint);
        self::assertStringNotContainsString('lab-wrong-secret', $hint);
    }

    public function testAnyOtherStatusOrAPublicProviderGetsNoKeyHint(): void
    {
        putenv('MILPA_AGENT_BASE_URL=http://lab.invalid:11434');
        self::assertArrayNotHasKey('hint', $this->legAgainst(500), 'a 500 is not about the key');

        // The public provider is the one that refused its own key: its sentence is the whole story.
        putenv('MILPA_AGENT_BASE_URL');
        putenv('OPENAI_API_KEY=sk-a-provider-secret');
        self::assertArrayNotHasKey('hint', $this->legAgainst(401));
    }

    public function testWithNoCredentialAtAllTheHintNamesEveryWayIn(): void
    {
        $r = $this->legAgainst(200);

        self::assertFalse($r['ok']);
        $hint = (string) ($r['hint'] ?? '');
        foreach (['ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'MILPA_AGENT_BASE_URL', 'MILPA_AGENT_API_KEY'] as $var) {
            self::assertStringContainsString($var, $hint);
        }
    }

    /**
     * One leg whose model answers the given HTTP status as ai-gateway raises it.
     *
     * @return array<string, mixed>
     */
    private function legAgainst(int $status): array
    {
        $llm = $this->createMock(LlmService::class);
        $llm->method('generateResponse')->willThrowException(new ProviderRefusedException(
            "OpenAI API Error: HTTP {$status} - {\"error\":\"Invalid API key\"}",
            $status,
            'http://lab.invalid:11434/v1/chat/completions',
        ));
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([['name' => 'read', 'description' => 'Read', 'inputSchema' => ['type' => 'object']]]);
        $ops = new EndpointRefusalFixtureOperations($this->container);
        $ops->loop = new AgentOrchestrator($llm, $tools);

        foreach ($ops->operations() as $op) {
            if ($op->name === 'agent') {
                /** @var array<string, mixed> */
                return ($op->handler)(['prompt' => 'which plugins are on?']);
            }
        }
        self::fail('no agent operation');
    }
}

/** The agent operation with its orchestrator handed in, so the model is whatever the test makes it. */
final class EndpointRefusalFixtureOperations extends AgentOperations
{
    public AgentOrchestrator $loop;

    protected function orchestrator(LlmService $modeloRemoto, GatedToolCalls $cliente, int $pasos, ?PlanBoard $tablero, bool $lazyTools, ?SessionProgressProbe $sonda): AgentOrchestrator
    {
        return $this->loop;
    }
}
