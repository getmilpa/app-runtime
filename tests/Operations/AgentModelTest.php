<?php

/**
 * This file is part of milpa/app-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Config\AgentEndpoint;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Operation;
use Milpa\Runtime\Config;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * `agent:model` — WHICH MODEL THIS APP CAN ACTUALLY TALK TO.
 *
 * Nothing in this framework could answer «is there a reachable model». Every surface read the
 * configured name and printed it, so a house whose provider was down looked identical to one talking
 * happily (greenhouse decisions/0266). This is the operation a surface asks instead of resolving.
 */
final class AgentModelTest extends TestCase
{
    /** @var array<string, false|string> */
    private array $antes = [];

    protected function setUp(): void
    {
        foreach (['MILPA_AGENT_BASE_URL', 'MILPA_AGENT_MODEL'] as $v) {
            $this->antes[$v] = getenv($v);
            putenv($v);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->antes as $v => $valor) {
            $valor === false ? putenv($v) : putenv("{$v}={$valor}");
        }
        AgentEndpoint::useProviderFetcher(null);
    }

    /**
     * 🚨 IT READS AND IT IS NOT `readOnly()`, and that is the honest classification.
     *
     * This operation makes an HTTP request to whatever host the app declared, so `externality` is
     * `third_party`. A house that classified provider egress as «touches nobody» would let an agent
     * probe an arbitrary host under a ceiling that says it reaches no one — and GOV-05's whole point
     * is that a ceiling nobody set is at its maximum, not that a comfortable one may be invented.
     */
    public function testItDeclaresProviderEgressRatherThanCallingItselfReadOnly(): void
    {
        $op = self::declared();

        self::assertSame(Externality::ThirdParty, $op->effects?->externality, 'asking a provider reaches a third party');
        self::assertSame('none', $op->effects?->mutation->value);
        self::assertSame('read', $op->effects?->authority->value);
        self::assertTrue($op->effects?->isFullyClassified(), 'and nothing is left for GOV-05 to maximise');
    }

    public function testItSaysWhatAnsweredWhichModelsAndWhetherTheDeclaredOneIsServed(): void
    {
        AgentEndpoint::useProviderFetcher(static fn (): ?string => '{"data":[{"id":"qwen3.8-27b"},{"id":"llama3.2:3b"}]}');

        $out = self::report(['agent' => ['baseUrl' => 'https://propio.local', 'model' => 'qwen3.8-27b']]);

        self::assertTrue($out['ok']);
        self::assertTrue($out['asked']);
        self::assertTrue($out['reached']);
        self::assertSame(['qwen3.8-27b', 'llama3.2:3b'], $out['models']);
        self::assertTrue($out['serves_declared']);
    }

    /**
     * THE ARM NOTHING WAS CHECKING: it answered, and it does not serve what this house declared.
     *
     * Every turn would fail AT the provider and the failure would look like a bug in the turn.
     */
    public function testAProviderThatDoesNotServeTheDeclaredModelIsSaidPlainly(): void
    {
        AgentEndpoint::useProviderFetcher(static fn (): ?string => '{"data":[{"id":"qwen3.8-27b"}]}');

        $out = self::report(['agent' => ['baseUrl' => 'https://propio.local', 'model' => 'gpt-4o']]);

        self::assertTrue($out['reached'], 'it answered');
        self::assertFalse($out['serves_declared'], 'and it does not serve gpt-4o');
    }

    /**
     * THE PROVENANCE IS THE HALF NOBODY ASKS FOR AND EVERYBODY NEEDS.
     *
     * «unreachable: http://llama.local:11438» reads as «start that machine» when the value was never
     * the reader's. Saying which of the three answered turns the same red into an instruction.
     */
    public function testItSaysWhereTheEndpointAndTheModelCameFrom(): void
    {
        AgentEndpoint::useProviderFetcher(static fn (): ?string => '{"data":[]}');

        $declared = self::report(['agent' => ['baseUrl' => 'https://propio.local', 'model' => 'm']]);
        self::assertSame('config', $declared['endpoint_from']);
        self::assertSame('config', $declared['model_from']);

        putenv('MILPA_AGENT_BASE_URL=https://del-entorno.local');
        putenv('MILPA_AGENT_MODEL=del-entorno');
        $fromEnv = self::report(null);
        self::assertSame('environment', $fromEnv['endpoint_from']);
        self::assertSame('environment', $fromEnv['model_from']);
    }

    /**
     * `ask: false` GOES NOWHERE, and this counts the requests.
     *
     * A caller that only wants what the house declared should not pay for a round trip — measured at
     * five seconds against a provider that was down.
     */
    public function testWithoutAskingItMakesNoRequestAtAllAndSaysSo(): void
    {
        $calls = 0;
        AgentEndpoint::useProviderFetcher(static function () use (&$calls): ?string {
            ++$calls;

            return '{"data":[{"id":"m"}]}';
        });

        $out = self::report(['agent' => ['baseUrl' => 'https://propio.local', 'model' => 'm']], ask: false);

        self::assertSame(0, $calls, 'not one request');
        self::assertFalse($out['asked'], 'and it says nobody asked');
        self::assertArrayNotHasKey('reached', $out, 'rather than reporting a silence it never heard');
        self::assertSame('m', $out['model'], 'what was declared is still answered');
    }

    /**
     * TWO GUARDS PRODUCE THE SAME SILENCE, so it says WHICH one it hit.
     *
     * «reached: null» alone would leave a reader guessing between a missing endpoint and a missing
     * reader, and those have completely different fixes.
     */
    public function testWithNoEndpointItNamesTheSilenceItHit(): void
    {
        $out = self::report(null);

        self::assertTrue($out['asked']);
        self::assertNull($out['reached']);
        self::assertSame([], $out['models']);
        self::assertNull($out['serves_declared']);
        self::assertStringContainsString('no endpoint is declared', $out['cannot_say']);
    }

    /** @param null|array<string, mixed> $config */
    private static function report(?array $config, bool $ask = true): array
    {
        $container = new DIContainer();
        if ($config !== null) {
            $container->registerService(Config::class, new Config($config));
        }
        $method = new \ReflectionMethod(AgentOperations::class, 'modelReport');
        $method->setAccessible(true);

        return $method->invoke(new AgentOperations($container), $ask);
    }

    private static function declared(): Operation
    {
        foreach ((new AgentOperations(new DIContainer()))->operations() as $op) {
            if ($op->name === 'agent:model') {
                return $op;
            }
        }

        self::fail('agent:model is not declared');
    }
}
