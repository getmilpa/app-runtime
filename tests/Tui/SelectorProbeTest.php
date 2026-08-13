<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Tui;

use Milpa\AppRuntime\Tui\TuiProbe;
use PHPUnit\Framework\TestCase;

/**
 * The probe is an instrument, so it gets the treatment an instrument gets here: proof that it can
 * both speak and stay quiet.
 *
 * greenhouse evidence/0169 shipped a selector fix unverified because the screen could only be read
 * as pixels — a marker grep did not find, a plain capture hid, and three runs disagreed. This line
 * exists so the cursor can be READ. An instrument nobody tested is the thing this house refuses
 * most, and coverage caught exactly that: the branch shipped untested and CI said so.
 */
final class SelectorProbeTest extends TestCase
{
    private string|false $antes;

    protected function setUp(): void
    {
        $this->antes = getenv('MILPA_TUI_DEBUG');
    }

    protected function tearDown(): void
    {
        $this->antes === false ? putenv('MILPA_TUI_DEBUG') : putenv("MILPA_TUI_DEBUG={$this->antes}");
    }

    /** 1 · with the variable set, the probe speaks. */
    public function testTheProbeSpeaksWhenAsked(): void
    {
        putenv('MILPA_TUI_DEBUG=1');

        self::assertTrue($this->sondaActiva());
    }

    /**
     * 2 · THE CONTROL: with nothing set, it stays quiet.
     *
     * An instrument that leaks into everyone's screen stops being an instrument and becomes noise,
     * and a probe that cannot be turned off would have been shipped to every human using the app.
     */
    public function testWithoutTheVariableItStaysQuiet(): void
    {
        putenv('MILPA_TUI_DEBUG');

        self::assertFalse($this->sondaActiva());
    }

    /** 3 · an empty value is not «on» — otherwise `MILPA_TUI_DEBUG=` would turn it on. */
    public function testAnEmptyValueIsNotOn(): void
    {
        putenv('MILPA_TUI_DEBUG=');

        self::assertFalse($this->sondaActiva());
    }

    /**
     * THE RULE IS CALLED, NOT COPIED (greenhouse evidence/0141).
     *
     * The first version of this test rewrote the condition here, which measured a copy: the screen's
     * branch could have changed and every case would still have passed.
     */
    private function sondaActiva(): bool
    {
        return TuiProbe::pedida();
    }
}
