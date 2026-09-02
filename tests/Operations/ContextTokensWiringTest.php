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
use Milpa\AiGateway\McpClientService;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * The intra-leg budget's wiring (greenhouse fixture series, run 8): the app's declared
 * `agent.contextTokens` — the value the compaction bridge already hands the `Compactor` as the
 * whole-window budget — must ALSO reach the orchestrator's construction, so the gateway can bound
 * each outgoing projection WITHIN a leg. No declared context resolves to 0: unbounded, exactly
 * the run yesterday shipped. An installed ai-gateway too old to declare the parameter never
 * receives it — degrade, not break, the planBoard doctrine.
 */
final class ContextTokensWiringTest extends TestCase
{
    private string|false $previousEnv = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = getenv('MILPA_AGENT_CONTEXT_TOKENS');
        putenv('MILPA_AGENT_CONTEXT_TOKENS');
    }

    protected function tearDown(): void
    {
        $this->previousEnv === false
            ? putenv('MILPA_AGENT_CONTEXT_TOKENS')
            : putenv('MILPA_AGENT_CONTEXT_TOKENS=' . $this->previousEnv);
        parent::tearDown();
    }

    /** Builds the orchestrator through the SAME protected seam `ask()` uses. */
    private function builtOrchestrator(AgentOperations $operations): AgentOrchestrator
    {
        $llm = $this->createMock(LlmService::class);
        $client = $this->createMock(McpClientService::class);

        $method = new \ReflectionMethod(AgentOperations::class, 'orchestrator');

        $built = $method->invoke($operations, $llm, $client, 5, null, false, null);
        self::assertInstanceOf(AgentOrchestrator::class, $built);

        return $built;
    }

    /** What the constructed orchestrator actually received as its context declaration. */
    private function contextTokensOf(AgentOrchestrator $orquestador): int
    {
        $property = new \ReflectionProperty(AgentOrchestrator::class, 'contextTokens');

        return (int) $property->getValue($orquestador);
    }

    /**
     * Falsifier 1: the declared context reaches the orchestrator — the wiring passes the value
     * the endpoint resolver produced, not a copy of the precedence.
     */
    public function testTheDeclaredContextReachesTheOrchestrator(): void
    {
        putenv('MILPA_AGENT_CONTEXT_TOKENS=24000');

        $built = $this->builtOrchestrator(new AgentOperations(new DIContainer()));

        self::assertSame(24000, $this->contextTokensOf($built), 'the declared context must reach the construction');
    }

    /**
     * Falsifier 2: no declared context resolves to 0 — unbounded, byte-identical to the run
     * before the parameter existed.
     */
    public function testAnUndeclaredContextPassesZero(): void
    {
        $built = $this->builtOrchestrator(new AgentOperations(new DIContainer()));

        self::assertSame(0, $this->contextTokensOf($built), 'absent config must pass 0 — unbounded, exactly today');
    }

    /**
     * Falsifier 3: an installed ai-gateway whose constructor does not declare the parameter never
     * receives it — the guard fails toward the previous shapes instead of throwing «Unknown named
     * parameter» on every turn (the planBoard trap), even with a context declared.
     */
    public function testAnOlderGatewayNeverReceivesTheParameter(): void
    {
        putenv('MILPA_AGENT_CONTEXT_TOKENS=24000');

        $operations = new class (new DIContainer()) extends AgentOperations {
            protected function orchestratorAdmitsContextTokens(): bool
            {
                return false;
            }
        };

        $built = $this->builtOrchestrator($operations);

        self::assertSame(0, $this->contextTokensOf($built), 'an older gateway must be left on the byte-identical shapes');
    }
}
