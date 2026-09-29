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

namespace Milpa\AppRuntime\Tests\Config;

use Milpa\AppRuntime\Config\AgentEndpoint;
use Milpa\Runtime\Config;
use PHPUnit\Framework\TestCase;

/**
 * The house declares the output limit it sends (greenhouse decisions/0514, evidence/1036).
 *
 * 1036 ran a 49,152-token model with nothing declared: every call asked for the gateway's fixed 4096,
 * two answers were cut at it, and the input budget reserved nothing for it. Undeclared, the house now
 * derives a sixth of the window that governs — the one the provider measured, when it was asked —
 * and a declared `agent.outputTokens` always wins.
 *
 * @internal
 */
final class TheOutputLimitIsDerivedFromTheWindowTest extends TestCase
{
    private const PROVIDER = 'http://llama.tailf880b7.ts.net:11438';

    /** @var array<string, false|string> */
    private array $before = [];

    protected function setUp(): void
    {
        foreach (['MILPA_AGENT_BASE_URL', 'MILPA_AGENT_CONTEXT_TOKENS'] as $variable) {
            $this->before[$variable] = getenv($variable);
            putenv($variable);
        }
        AgentEndpoint::useProviderFetcher(static fn (string $url): ?string => null);
    }

    protected function tearDown(): void
    {
        foreach ($this->before as $variable => $value) {
            $value === false ? putenv($variable) : putenv($variable . '=' . $value);
        }
        AgentEndpoint::useProviderFetcher(null);
    }

    /** A sixth of the window, in 1024-token steps, between 4096 and 16,384. */
    public function testASixthOfTheGoverningWindow(): void
    {
        foreach ([49152 => 8192, 32768 => 5120, 24576 => 4096, 65536 => 10240, 131072 => 16384, 262144 => 16384] as $window => $expected) {
            self::assertSame($expected, AgentEndpoint::derivedOutputTokens($this->config(['contextTokens' => $window])), "window {$window}");
        }
    }

    /** Below 24,576 tokens a sixth is under 4096: the gateway keeps its own default for small windows. */
    public function testNoDerivationWithoutARoomyWindow(): void
    {
        self::assertNull(AgentEndpoint::derivedOutputTokens($this->config(['contextTokens' => 24575])));
        self::assertNull(AgentEndpoint::derivedOutputTokens($this->config([])));
        self::assertNull(AgentEndpoint::derivedOutputTokens(null));
    }

    /** The MEASURED window governs the derivation: the provider's n_ctx, not what a human wrote. */
    public function testTheProviderMeasuredWindowIsTheOneDividedBySix(): void
    {
        AgentEndpoint::useProviderFetcher(static fn (string $url): ?string => str_ends_with($url, '/v1/models')
            ? '{"object":"list","data":[{"id":"qwen3.8-27b","meta":{"n_ctx":49152,"n_ctx_train":262144}}]}'
            : null);

        self::assertSame(8192, AgentEndpoint::derivedOutputTokens($this->config(['baseUrl' => self::PROVIDER])));
        self::assertSame(8192, AgentEndpoint::derivedOutputTokens($this->config(['baseUrl' => self::PROVIDER, 'contextTokens' => 100000])), 'the smaller window governs');
    }

    /** A declared limit wins, and every reader can say who decided the one in force. */
    public function testTheDeclaredLimitWinsAndTheSourceIsSaid(): void
    {
        self::assertSame(['tokens' => 2048, 'source' => 'declared'], AgentEndpoint::effectiveOutputTokens($this->config(['contextTokens' => 49152, 'outputTokens' => 2048])));
        self::assertSame(['tokens' => 8192, 'source' => 'derived'], AgentEndpoint::effectiveOutputTokens($this->config(['contextTokens' => 49152])));
        self::assertSame(['tokens' => null, 'source' => 'default'], AgentEndpoint::effectiveOutputTokens($this->config([])));
    }

    /** @param array<string, mixed> $agent */
    private function config(array $agent): Config
    {
        return new Config(['agent' => $agent]);
    }
}
