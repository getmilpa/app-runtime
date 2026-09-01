<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Config;

use Milpa\AppRuntime\Config\AgentEndpoint;
use Milpa\Runtime\Config;
use PHPUnit\Framework\TestCase;

/**
 * The battery greenhouse evidence/0166 froze before this class existed.
 *
 * The second case is the control, and it decides whether this fixed a lie or swapped it for another
 * one. Whoever exports MILPA_AGENT_BASE_URL and never writes a config file is walking the path this
 * framework documents; making the governed source win must not take theirs away, only settle who
 * wins when both speak.
 */
final class AgentEndpointTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $antes = [];

    protected function setUp(): void
    {
        foreach ([
            'MILPA_AGENT_BASE_URL',
            'MILPA_AGENT_MODEL',
            'MILPA_AGENT_CONTEXT_TOKENS',
            'ANTHROPIC_API_KEY',
            'OPENAI_API_KEY',
        ] as $v) {
            $this->antes[$v] = getenv($v);
            putenv($v);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->antes as $v => $valor) {
            $valor === false ? putenv($v) : putenv("{$v}={$valor}");
        }
    }

    /** 1 · declared configuration is what the banner reports. */
    public function testTheDeclaredModelIsWhatGetsReported(): void
    {
        $config = new Config(['agent' => ['model' => 'declarado', 'baseUrl' => 'https://propio.local']]);

        self::assertSame('declarado', AgentEndpoint::model($config));
        self::assertSame('local · declarado', AgentEndpoint::describe($config));
    }

    /**
     * 2 · THE CONTROL: with nothing declared, the environment still works.
     *
     * If making the config win stopped the environment working, nothing was fixed — one lie was
     * swapped for another, and the broken one belongs to whoever exports variables.
     */
    public function testWithNothingDeclaredTheEnvironmentStillWorks(): void
    {
        putenv('MILPA_AGENT_BASE_URL=https://del-entorno.local');
        putenv('MILPA_AGENT_MODEL=del-entorno');

        self::assertSame('https://del-entorno.local', AgentEndpoint::baseUrl(null));
        self::assertSame('local · del-entorno', AgentEndpoint::describe(null));
    }

    /** 3 · with both set and disagreeing, the declared one wins — the same one the call uses. */
    public function testWhenBothSpeakTheDeclaredOneWins(): void
    {
        putenv('MILPA_AGENT_MODEL=del-entorno');
        putenv('MILPA_AGENT_BASE_URL=https://del-entorno.local');
        $config = new Config(['agent' => ['model' => 'declarado', 'baseUrl' => 'https://declarado.local']]);

        self::assertSame('declarado', AgentEndpoint::model($config));
        self::assertSame('https://declarado.local', AgentEndpoint::baseUrl($config));
    }

    /**
     * 4 · a declared endpoint is never reported as "no credential".
     *
     * That line was the only sentence a human read before typing, and it appeared while the agent
     * was configured and answering.
     */
    public function testADeclaredEndpointIsNotReportedAsUnconfigured(): void
    {
        $config = new Config(['agent' => ['baseUrl' => 'https://llama.local']]);

        self::assertStringNotContainsString('sin credencial', AgentEndpoint::describe($config));
        self::assertStringStartsWith('local · ', AgentEndpoint::describe($config));
    }

    /** 5 · with nothing anywhere, it says so — failing loudly beats guessing a provider. */
    public function testWithNothingAtAllItSaysSo(): void
    {
        self::assertStringContainsString('sin credencial', AgentEndpoint::describe(null));
    }

    /** 6 · the declared context wins over the environment, by the same precedence as the endpoint. */
    public function testTheDeclaredContextWinsOverTheEnvironment(): void
    {
        putenv('MILPA_AGENT_CONTEXT_TOKENS=8000');
        $config = new Config(['agent' => ['contextTokens' => 32768]]);

        self::assertSame(32768, AgentEndpoint::contextTokens($config));
    }

    /** 7 · THE CONTROL: with nothing declared, the environment still declares the context. */
    public function testWithNothingDeclaredTheEnvironmentDeclaresTheContext(): void
    {
        putenv('MILPA_AGENT_CONTEXT_TOKENS=24000');

        self::assertSame(24000, AgentEndpoint::contextTokens(null));
    }

    /** 8 · absent everywhere is `null` — no number is invented for a model nobody measured. */
    public function testAnUndeclaredContextResolvesAsNull(): void
    {
        self::assertNull(AgentEndpoint::contextTokens(null));
        self::assertNull(AgentEndpoint::contextTokens(new Config([])));
    }

    /** 9 · a value that could not bound a window resolves as undeclared, not as a poison share. */
    public function testAValueThatCannotBudgetResolvesAsUndeclared(): void
    {
        putenv('MILPA_AGENT_CONTEXT_TOKENS=not-a-number');
        self::assertNull(AgentEndpoint::contextTokens(null));

        putenv('MILPA_AGENT_CONTEXT_TOKENS=0');
        self::assertNull(AgentEndpoint::contextTokens(null));

        self::assertNull(AgentEndpoint::contextTokens(new Config(['agent' => ['contextTokens' => -5]])));
        self::assertSame(16000, AgentEndpoint::contextTokens(new Config(['agent' => ['contextTokens' => '16000']])));
    }
}
