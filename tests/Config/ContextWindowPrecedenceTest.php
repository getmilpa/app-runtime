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
use Milpa\AppRuntime\Config\ContextWindow;
use Milpa\AppRuntime\Config\ContextWindowSource;
use Milpa\Runtime\Config;
use PHPUnit\Framework\TestCase;

/**
 * F2 and F3 of greenhouse decisions/0233: declared and measured both feed the window, the SMALLER
 * one wins, and when the provider says nothing the house keeps the declaration AND says so.
 *
 * The bodies are the ones measured on 2026-09-08 against `qwen3.8-27b` on llama over Tailscale.
 * Every test drives the network seam, so nothing here reaches a model host: a CI run that quietly
 * asked somebody's provider on every build would be egress nobody consented to, and a test that
 * needed a live provider is a test nobody runs.
 */
final class ContextWindowPrecedenceTest extends TestCase
{
    private const MODELS_AS_MEASURED = '{"object":"list","data":[{"id":"qwen3.8-27b","meta":{"n_ctx":32768,"n_ctx_train":262144}}]}';

    private const PROVIDER = 'http://llama.tailf880b7.ts.net:11438';

    /** @var array<string, false|string> */
    private array $antes = [];

    protected function setUp(): void
    {
        foreach (['MILPA_AGENT_BASE_URL', 'MILPA_AGENT_CONTEXT_TOKENS'] as $v) {
            $this->antes[$v] = getenv($v);
            putenv($v);
        }
        AgentEndpoint::useProviderFetcher(static fn (string $url): ?string => null);
    }

    protected function tearDown(): void
    {
        foreach ($this->antes as $v => $valor) {
            $valor === false ? putenv($v) : putenv($v . '=' . $valor);
        }
        AgentEndpoint::useProviderFetcher(null);
    }

    /** F2: a declaration eight times too large is clipped down to what the provider allocated. */
    public function testADeclarationLargerThanTheProviderIsTightenedDownToIt(): void
    {
        $window = $this->windowFor(declared: 100000, providerSays: self::MODELS_AS_MEASURED);

        self::assertSame(32768, $window->tokens, 'the smaller of the two governs');
        self::assertSame(ContextWindowSource::TightenedByTheProvider, $window->source);
        self::assertSame(100000, $window->declared, 'what the human asked for is still readable');
        self::assertSame(32768, $window->measured);
        self::assertFalse($window->couldNotAsk());
    }

    /**
     * F2, THE INVERSE CONTROL: a declaration SMALLER than the provider's window is not overwritten.
     *
     * This is the case that decides whether the slice fixed a defect or swapped it for another one.
     * Declaring less is how a human leaves air on purpose; a rule that always took the provider's
     * number would silently spend the air they reserved.
     */
    public function testADeclarationSmallerThanTheProviderSurvivesIt(): void
    {
        $window = $this->windowFor(declared: 8000, providerSays: self::MODELS_AS_MEASURED);

        self::assertSame(8000, $window->tokens, 'what a human declared can tighten too');
        self::assertSame(ContextWindowSource::Declared, $window->source);
        self::assertSame(32768, $window->measured, 'the measurement is still reported, it just did not win');
    }

    /** With nobody declaring anything, the provider's allocated window is the budget. */
    public function testWithNothingDeclaredTheProvidersWindowGoverns(): void
    {
        $window = $this->windowFor(declared: null, providerSays: self::MODELS_AS_MEASURED);

        self::assertSame(32768, $window->tokens);
        self::assertSame(ContextWindowSource::Measured, $window->source);
        self::assertNull($window->declared);
    }

    /** Two numbers that agree are not a tightening — nothing changed, so nothing is reported as changed. */
    public function testAProviderThatAgreesWithTheDeclarationDidNotTightenAnything(): void
    {
        $window = $this->windowFor(declared: 32768, providerSays: self::MODELS_AS_MEASURED);

        self::assertSame(32768, $window->tokens);
        self::assertSame(ContextWindowSource::Declared, $window->source, 'agreeing with a declaration does not clip it');
    }

    /** F3: a silent provider keeps the declaration, and the answer SAYS the ceiling went unverified. */
    public function testASilentProviderKeepsTheDeclarationAndSaysItCouldNotAsk(): void
    {
        $window = $this->windowFor(declared: 100000, providerSays: null);

        self::assertSame(100000, $window->tokens, 'the run continues with what was declared');
        self::assertSame(ContextWindowSource::Declared, $window->source);
        self::assertNull($window->measured);
        self::assertTrue($window->couldNotAsk(), 'never quiet: the ceiling here is somebody\'s word');
    }

    /** F1 again, one level up: a provider that exposes only its TRAINING window has said nothing. */
    public function testAProviderThatExposesOnlyTheTrainingWindowCannotTightenAnything(): void
    {
        $window = $this->windowFor(
            declared: 100000,
            providerSays: '{"data":[{"id":"m","meta":{"n_ctx_train":262144}}]}',
        );

        self::assertSame(100000, $window->tokens, '262144 is what the MODEL was trained for, not what the server allocated');
        self::assertNull($window->measured);
        self::assertTrue($window->couldNotAsk());
    }

    /**
     * F3, THE CONTROL: with neither source, the answer is `null` and the run is yesterday's.
     *
     * `contextTokens()` is what the orchestrator and the compaction bridge read, so this asserts on
     * the value they receive — a composition that returned anything but `null` here would change
     * every app that never declared a window, which is most of them.
     */
    public function testWithNeitherSourceTheAnswerIsNullAndTheBudgetIsTodaysExactly(): void
    {
        $window = $this->windowFor(declared: null, providerSays: null);

        self::assertNull($window->tokens);
        self::assertSame(ContextWindowSource::Undeclared, $window->source);
        self::assertNull(AgentEndpoint::contextTokens($this->config(null)), 'the orchestrator must still receive null');
    }

    /** An app that named no provider is never asked, so it is never told the ask failed. */
    public function testAnAppThatDeclaresNoProviderIsNeverAsked(): void
    {
        $asked = 0;
        AgentEndpoint::useProviderFetcher(function (string $url) use (&$asked): ?string {
            ++$asked;

            return self::MODELS_AS_MEASURED;
        });

        $window = AgentEndpoint::contextWindow(new Config(['agent' => ['contextTokens' => 8000]]));

        self::assertSame(0, $asked, 'no base URL, no question — egress nobody asked for');
        self::assertSame(8000, $window->tokens);
        self::assertSame(ContextWindowSource::Declared, $window->source);
        self::assertFalse($window->couldNotAsk(), 'there was nobody to ask, which is not the same as asking and failing');
    }

    /** The question is asked ONCE per run, however many callers resolve the window. */
    public function testTheProviderIsAskedOncePerRunBecauseAskingIsEgress(): void
    {
        $asked = 0;
        AgentEndpoint::useProviderFetcher(function (string $url) use (&$asked): ?string {
            ++$asked;

            return str_ends_with($url, '/v1/models') ? self::MODELS_AS_MEASURED : null;
        });

        $config = $this->config(null);
        AgentEndpoint::contextWindow($config);
        AgentEndpoint::contextTokens($config);
        AgentEndpoint::contextWindow($config);

        self::assertSame(1, $asked, 'the orchestrator and the compaction bridge both resolve this every turn');
    }

    /** The environment declaration composes exactly like the governed one. */
    public function testTheEnvironmentDeclarationIsTightenedTheSameWay(): void
    {
        putenv('MILPA_AGENT_CONTEXT_TOKENS=100000');
        AgentEndpoint::useProviderFetcher(fn (string $url): ?string => str_ends_with($url, '/v1/models') ? self::MODELS_AS_MEASURED : null);

        $window = AgentEndpoint::contextWindow($this->config(null));

        self::assertSame(32768, $window->tokens);
        self::assertSame(ContextWindowSource::TightenedByTheProvider, $window->source);
    }

    /** A config carrying the provider, and optionally a declared window. */
    private function config(?int $declared): Config
    {
        $agent = ['baseUrl' => self::PROVIDER];
        if ($declared !== null) {
            $agent['contextTokens'] = $declared;
        }

        return new Config(['agent' => $agent]);
    }

    /** The window this app would run under, given what it declared and what its provider answers. */
    private function windowFor(?int $declared, ?string $providerSays): ContextWindow
    {
        AgentEndpoint::useProviderFetcher(
            static fn (string $url): ?string => $providerSays !== null && str_ends_with($url, '/v1/models') ? $providerSays : null,
        );

        return AgentEndpoint::contextWindow($this->config($declared));
    }
}
