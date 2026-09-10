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

    /**
     * 🚨 WHERE THE VALUE CAME FROM, because «unreachable» without it sends a person to the wrong fix.
     *
     * `unreachable: http://llama.local:11438` reads as «start that machine» when the value was never
     * theirs — it was a package's fallback, or a stray variable in a shell they forgot. Saying WHICH
     * of the three answered turns the same red into an instruction (greenhouse decisions/0266).
     *
     * It resolves in this class for the reason this class exists: a surface computing its own
     * provenance would be a THIRD copy of a precedence that evidence/0165 already measured going
     * wrong at two.
     */
    public function testItSaysWhereTheEndpointAndTheModelCameFrom(): void
    {
        $declared = new Config(['agent' => ['model' => 'declarado', 'baseUrl' => 'https://propio.local']]);
        self::assertSame('config', AgentEndpoint::baseUrlSource($declared));
        self::assertSame('config', AgentEndpoint::modelSource($declared));

        putenv('MILPA_AGENT_BASE_URL=https://del-entorno.local');
        putenv('MILPA_AGENT_MODEL=del-entorno');
        self::assertSame('environment', AgentEndpoint::baseUrlSource(null));
        self::assertSame('environment', AgentEndpoint::modelSource(null));
        // And the same precedence the values follow: declared still wins, and SAYS it wins.
        self::assertSame('config', AgentEndpoint::baseUrlSource($declared));
        self::assertSame('config', AgentEndpoint::modelSource($declared));
    }

    /** Nothing anywhere is `none` — never a guess, and never a host this package invented. */
    public function testWithNothingAnywhereTheSourceIsNone(): void
    {
        self::assertSame('none', AgentEndpoint::baseUrlSource(null));
        self::assertSame('none', AgentEndpoint::modelSource(null));
        self::assertSame('none', AgentEndpoint::baseUrlSource(new Config(['agent' => ['baseUrl' => '']])), 'empty is not declared');
    }

    /**
     * NO BASE URL, NO QUESTION — the same first guard `measuredContextTokens()` established.
     *
     * An app that talks to a provider's default endpoint has not told this framework where its model
     * lives, and interrogating an invented host is egress nobody asked for.
     */
    public function testWithNoEndpointThereIsNoReachQuestion(): void
    {
        $asked = [];
        AgentEndpoint::useProviderFetcher(static function (string $url) use (&$asked): ?string {
            $asked[] = $url;

            return '{"data":[{"id":"whatever"}]}';
        });

        self::assertNull(AgentEndpoint::providerReach(null), 'nothing to ask');
        self::assertSame([], $asked, 'and nothing was asked');

        AgentEndpoint::useProviderFetcher(null);
    }

    /**
     * WHETHER A MODEL ANSWERS, asked of the endpoint the turns actually use — including the arm
     * nobody was checking.
     *
     * Skipped where `ProviderReach` is not installed, which is the «no reader, no question» guard
     * this class already applies to the context window: the answer is absent and the run is
     * byte-identical to the one before.
     */
    public function testItSaysWhetherAModelAnsweredAndWhetherItServesTheDeclaredOne(): void
    {
        if (!class_exists(\Milpa\AiGateway\ProviderReach::class)) {
            self::markTestSkipped('milpa/ai-gateway does not ship ProviderReach yet: no reader, no question');
        }

        AgentEndpoint::useProviderFetcher(static fn (string $url): ?string => '{"data":[{"id":"qwen3.8-27b"}]}');

        $good = new Config(['agent' => ['baseUrl' => 'https://propio.local', 'model' => 'qwen3.8-27b']]);
        self::assertSame(
            ['reached' => true, 'models' => ['qwen3.8-27b'], 'serves_declared' => true],
            AgentEndpoint::providerReach($good),
        );

        // 🚨 THE ARM NOBODY CHECKED: it answered, and it does not serve what this house declared.
        $wrong = new Config(['agent' => ['baseUrl' => 'https://propio.local', 'model' => 'gpt-4o']]);
        self::assertFalse(AgentEndpoint::providerReach($wrong)['serves_declared']);
        self::assertTrue(AgentEndpoint::providerReach($wrong)['reached'], 'reached is not the same fact');

        AgentEndpoint::useProviderFetcher(null);
    }

    /** NEVER TWICE, and the memo is keyed by endpoint AND model — two questions, not one. */
    public function testTheReachIsAskedOncePerEndpointAndModel(): void
    {
        if (!class_exists(\Milpa\AiGateway\ProviderReach::class)) {
            self::markTestSkipped('milpa/ai-gateway does not ship ProviderReach yet');
        }
        $calls = 0;
        AgentEndpoint::useProviderFetcher(static function () use (&$calls): ?string {
            ++$calls;

            return '{"data":[{"id":"a"}]}';
        });

        $one = new Config(['agent' => ['baseUrl' => 'https://propio.local', 'model' => 'a']]);
        AgentEndpoint::providerReach($one);
        AgentEndpoint::providerReach($one);
        self::assertSame(1, $calls, 'one endpoint, one model, one question');

        // A DIFFERENT declared model is a different question about the same endpoint: keying the memo
        // by the URL alone would answer `serves_declared` for the model somebody asked about first.
        AgentEndpoint::providerReach(new Config(['agent' => ['baseUrl' => 'https://propio.local', 'model' => 'b']]));
        self::assertSame(2, $calls);

        AgentEndpoint::useProviderFetcher(null);
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
