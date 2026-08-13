<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Tui;

use Milpa\AppRuntime\Tui\AgentScreen;
use Milpa\AppRuntime\Tui\TuiProbe;
use PHPUnit\Framework\TestCase;

/**
 * The question tmux could not answer, answered here instead.
 *
 * greenhouse evidence/0169 shipped the selector window openly unverified, because that screen could
 * only be read as pixels: the cursor marker is one character `grep` did not find, a plain capture
 * hid and `cat -A` showed, and three runs gave three answers that disagreed. I reached for a runtime
 * probe behind an environment variable when what the question actually needed was a test — one that
 * drives the keys and reads the rendered text, deterministically, with no terminal in the way.
 *
 * The second case is the control and it is the whole point: BEFORE this window followed the cursor,
 * pressing Down past the last visible row took the marker off screen, nothing looked selected, and
 * Enter opened a session the human had never seen.
 */
final class SelectorWindowTest extends TestCase
{
    /** 1 · with the cursor on the first row, the marker is on screen. */
    public function testTheMarkerIsVisibleAtTheTop(): void
    {
        $pantalla = $this->conSesiones(30);
        $this->abrirSelector($pantalla);

        self::assertStringContainsString('› sesion-00', $pantalla->render());
    }

    /**
     * 2 · THE CONTROL: driven far past the visible rows, the marker is STILL on screen.
     *
     * This is the case that broke. Without the window following, the marker left the screen and
     * Enter opened a row nobody had seen.
     */
    public function testTheMarkerStaysVisibleWhenDrivenPastTheBottom(): void
    {
        $pantalla = $this->conSesiones(30);
        $this->abrirSelector($pantalla);

        for ($i = 0; $i < 25; ++$i) {
            $pantalla->press('down');
        }

        $texto = $pantalla->render();

        self::assertStringContainsString('› sesion-25', $texto, 'el marcador salió de la pantalla');
        self::assertStringContainsString('más arriba', $texto, 'no dice que hay filas por encima');
    }

    /** 3 · and coming back up brings it back to the top. */
    public function testComingBackUpReturnsToTheTop(): void
    {
        $pantalla = $this->conSesiones(30);
        $this->abrirSelector($pantalla);

        for ($i = 0; $i < 25; ++$i) {
            $pantalla->press('down');
        }
        for ($i = 0; $i < 25; ++$i) {
            $pantalla->press('up');
        }

        self::assertStringContainsString('› sesion-00', $pantalla->render());
    }

    /** 4 · the probe prints the state it claims to print, when it is asked for. */
    public function testTheProbeSaysWhereTheCursorIs(): void
    {
        putenv('MILPA_TUI_DEBUG=1');

        try {
            $pantalla = $this->conSesiones(30);
            $this->abrirSelector($pantalla);
            $pantalla->press('down');

            self::assertStringContainsString('[sonda] cursor=1', $pantalla->render());
            self::assertStringContainsString('total=30', $pantalla->render());
        } finally {
            putenv('MILPA_TUI_DEBUG');
        }
    }

    /**
     * 5 · THE CONTROL FOR THE PROBE: unasked, it says nothing.
     *
     * A probe that cannot be turned off is not an instrument, it is noise on every human's screen.
     */
    public function testUnaskedTheProbeSaysNothing(): void
    {
        putenv('MILPA_TUI_DEBUG');

        $pantalla = $this->conSesiones(30);
        $this->abrirSelector($pantalla);

        self::assertFalse(TuiProbe::pedida());
        self::assertStringNotContainsString('[sonda]', $pantalla->render());
    }

    private function abrirSelector(AgentScreen $pantalla): void
    {
        foreach (str_split('/sessions') as $c) {
            $pantalla->press($c);
        }
        $pantalla->press('enter');
    }

    private function conSesiones(int $cuantas): AgentScreen
    {
        $filas = [];
        for ($i = 0; $i < $cuantas; ++$i) {
            $filas[] = [
                'id' => sprintf('sesion-%02d', $i),
                'state' => 'viva',
                'turns' => 1,
                'goal' => 'objetivo ' . $i,
            ];
        }

        return new AgentScreen(
            static fn (string $p): array => ['ok' => true, 'answer' => '.'],
            null,
            null,
            90,
            20,
            false,
            catalogo: static fn (): array => $filas,
        );
    }
}
