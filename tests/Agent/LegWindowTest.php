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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\WindowBudget;
use Milpa\AppRuntime\Agent\LegWindow;
use Milpa\AppRuntime\Config\AgentEndpoint;
use Milpa\Runtime\Config;
use PHPUnit\Framework\TestCase;

/**
 * The numbers of greenhouse decisions/0538, on the window Rod's live run met (evidence/1071):
 * qwen3.8-27b, 49,152 tokens, 8,192 reserved for the answer, 40,960 of input.
 *
 * @internal
 */
final class LegWindowTest extends TestCase
{
    private string|false $context = false;

    private string|false $baseUrl = false;

    protected function setUp(): void
    {
        $this->context = getenv('MILPA_AGENT_CONTEXT_TOKENS');
        $this->baseUrl = getenv('MILPA_AGENT_BASE_URL');
        putenv('MILPA_AGENT_CONTEXT_TOKENS');
        putenv('MILPA_AGENT_BASE_URL');
        AgentEndpoint::useProviderFetcher(static fn (string $url): ?string => null);
    }

    protected function tearDown(): void
    {
        $this->context === false ? putenv('MILPA_AGENT_CONTEXT_TOKENS') : putenv('MILPA_AGENT_CONTEXT_TOKENS=' . $this->context);
        $this->baseUrl === false ? putenv('MILPA_AGENT_BASE_URL') : putenv('MILPA_AGENT_BASE_URL=' . $this->baseUrl);
        AgentEndpoint::useProviderFetcher(null);
    }

    /** The live run's window: the input limit is the wall 1071 met four times, and every share is cut from it. */
    public function testTheLiveRunsWindowGivesFortyStepsAndAFifthOfTheInputToWhatALegInherits(): void
    {
        $leg = LegWindow::of(new Config(['agent' => ['contextTokens' => 49152]]));

        self::assertNotNull($leg);
        self::assertSame(8192, $leg->outputTokens, 'the output the house derives (decisions/0514) is what the loop reserves');
        self::assertSame(40960, $leg->inputLimit());
        self::assertSame(40, $leg->autoSteps(), 'half of 40,960 over 512 tokens a step');
        self::assertSame(13653, $leg->inheritedContext());
        self::assertSame(8191, (new WindowBudget($leg->inheritedContext()))->composedTokens, 'milpa/agent composes 60 % of it: a fifth of the input limit');
        self::assertLessThan(10000, (new WindowBudget($leg->inheritedContext()))->chars((new WindowBudget($leg->inheritedContext()))->factsTokens), 'the operational facts: under 10k characters, not 1071\'s 35k');
    }

    /** A declared output limit is the reserve, and the input limit moves with it. */
    public function testADeclaredOutputIsTheReserve(): void
    {
        $leg = LegWindow::of(new Config(['agent' => ['contextTokens' => 49152, 'outputTokens' => 4096]]));

        self::assertSame(45056, $leg?->inputLimit());
        self::assertSame(40, $leg?->autoSteps(), 'the window would give 44; the ceiling is 40');
    }

    /** No derived output under 24,576 tokens: the reserve is the gateway's own, a quarter of a small window. */
    public function testASmallWindowReservesWhatTheGatewayReserves(): void
    {
        $leg = LegWindow::of(new Config(['agent' => ['contextTokens' => 8000]]));

        self::assertSame(2000, $leg?->outputTokens);
        self::assertSame(6000, $leg?->inputLimit());
        self::assertSame(12, $leg?->autoSteps(), 'never fewer steps than today');
    }

    /** A large window does not buy an unbounded leg: 40 is the cost ceiling Rod set (decisions/0538). */
    public function testALargeWindowStopsAtTheCostCeiling(): void
    {
        self::assertSame(40, LegWindow::MAX_STEPS);
        self::assertSame(40, LegWindow::sized(1_000_000, 16384)->autoSteps());
        self::assertSame(40, LegWindow::sized(131072, 16384)->autoSteps(), 'a 128k window would derive 112');
    }

    /** No window known, no derivation: every rule keeps today's. */
    public function testWithoutAWindowThereIsNothingToDeriveFrom(): void
    {
        self::assertNull(LegWindow::of(null));
        self::assertNull(LegWindow::of(new Config([])));
        self::assertSame(12, LegWindow::steps(null, AutonomyMode::Auto, null));
    }

    /** What someone typed wins; ASK keeps 12; only an AUTO leg derives. */
    public function testStepsAreTypedOrDerivedOnlyForAnAutoLeg(): void
    {
        $leg = LegWindow::sized(49152, 8192);

        self::assertSame(40, LegWindow::steps(null, AutonomyMode::Auto, $leg));
        self::assertSame(7, LegWindow::steps(7, AutonomyMode::Auto, $leg), 'an explicit --steps wins');
        self::assertSame(100, LegWindow::steps(100, AutonomyMode::Auto, $leg), 'even above the ceiling: someone typed it');
        self::assertSame(12, LegWindow::steps(null, AutonomyMode::Ask, $leg));
        self::assertSame(12, LegWindow::steps(null, null, $leg), 'no session, no mode: today');
        self::assertSame(40, LegWindow::steps(0, AutonomyMode::Auto, $leg), 'a zero is not a ceiling anyone meant');
        self::assertSame(40, LegWindow::steps('12', AutonomyMode::Auto, $leg), 'a string is not a typed integer');
    }

    /** The room after the provider's last count: both its numbers are spent by the next request. */
    public function testTheRoomIsWhatTheProviderLeftUnspent(): void
    {
        $leg = LegWindow::sized(49152, 8192);

        self::assertSame(2000, $leg->roomAfter(['prompt_tokens' => 38000, 'completion_tokens' => 960]));
        self::assertSame(0, $leg->roomAfter(['prompt_tokens' => 41000, 'completion_tokens' => 10]));
        self::assertSame(20960, $leg->roomAfter(['prompt_tokens' => 20000]));
        self::assertNull($leg->roomAfter([]), 'no count, no claim');
        self::assertNull($leg->roomAfter(['prompt_tokens' => 0]));
        self::assertSame(3000, LegWindow::pageChars(2000), 'half the room, at three characters a token');
    }

    /** The output is clipped so a leg always has one token of input to reason about. */
    public function testAnOutputAsLargeAsTheWindowLeavesOneTokenOfInput(): void
    {
        self::assertSame(1, LegWindow::sized(100, 500)->inputLimit());
        self::assertSame(1, LegWindow::sized(100, 500)->inheritedContext());
    }
}
