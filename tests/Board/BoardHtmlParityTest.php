<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Board;

use Milpa\AppRuntime\Board\BoardComponent;
use Milpa\AppRuntime\Board\BoardHtmlRenderer;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use PHPUnit\Framework\TestCase;

/**
 * Wiring the board component into the web host (greenhouse evidence/0296): the server-rendered board
 * must reach parity with the client painter it replaces (board-painter.js paintCard/paintBoard) —
 * the same columns, counts, badges and waiting alert — or moving the render server-side would lose
 * what a human relies on. This test IS the parity falsifier.
 */
final class BoardHtmlParityTest extends TestCase
{
    private function render(array $fold): string
    {
        $component = new BoardComponent();
        $request = new RenderRequest(
            context: new ComponentContext(componentId: 'board-1'),
            props: $fold,
            state: null,
            target: RenderTarget::HTML,
        );

        return (new BoardHtmlRenderer())->render($component, $request)->output;
    }

    public function testColumnsCarryTheirStatusAndCount(): void
    {
        $html = $this->render(['ok' => true, 'columns' => [
            'done' => [['id' => 'turn:15', 'text' => 'plugins_list', 'origin' => 'turn']],
            'blocked' => [],
        ]]);

        self::assertStringContainsString('data-status="done"', $html);
        self::assertStringContainsString('milpa-column-count">1', $html, 'the done column counts its one card');
        self::assertStringContainsString('milpa-column-count">0', $html, 'the empty blocked column counts zero');
        self::assertStringContainsString('plugins_list', $html);
    }

    public function testACardCarriesItsIdOriginAndCrossedState(): void
    {
        $html = $this->render(['ok' => true, 'columns' => [
            'done' => [['id' => 'turn:15', 'text' => 'work', 'origin' => 'turn', 'version' => 2]],
        ]]);

        self::assertStringContainsString('data-id="turn:15"', $html);
        self::assertStringContainsString('data-origin="turn"', $html);
        self::assertStringContainsString('data-crossed="true"', $html, 'an observed card is crossed');
        self::assertStringContainsString('data-version="2"', $html);
    }

    public function testABornDoneCardIsFlaggedNotCrossed(): void
    {
        foreach (['retrospective', 'unsupported'] as $origin) {
            $html = $this->render(['ok' => true, 'columns' => [
                'done' => [['id' => 'c1', 'text' => 'x', 'origin' => $origin]],
            ]]);
            self::assertStringContainsString('appeared already done', $html, "$origin is labelled");
            self::assertStringContainsString('data-crossed="false"', $html, "$origin is not painted as a crossing");
        }
    }

    public function testAWaitingCardAndTheBoardWaitingAlert(): void
    {
        $html = $this->render([
            'ok' => true,
            'session' => 's1',
            'pending_question' => 'which value?',
            'columns' => ['blocked' => [['id' => 'c1', 'text' => 'x', 'origin' => 'turn', 'held_by' => 'question']]],
        ]);

        self::assertStringContainsString('waiting for an answer', $html, 'the held card says why it is blocked');
        self::assertStringContainsString('milpa-board-waiting', $html, 'the board shows the pending question');
        self::assertStringContainsString('which value?', $html);
    }

    public function testUnexplainedMutationsShowAsACount(): void
    {
        $html = $this->render(['ok' => true, 'columns' => [
            'done' => [['id' => 'c1', 'text' => 'x', 'origin' => 'turn', 'unexplained' => 3]],
        ]]);

        self::assertStringContainsString('milpa-card-unexplained', $html);
        self::assertStringContainsString('>3<', $html, 'the unexplained count is shown');
    }

    public function testANotOkFoldRendersTheError(): void
    {
        $html = $this->render(['ok' => false, 'error' => 'no existe la sesión']);

        self::assertStringContainsString('milpa-board-error', $html);
        self::assertStringContainsString('no existe la sesión', $html);
    }
    public function testModelWrittenTextLandsAsTextNeverAsMarkup(): void
    {
        $html = $this->render(['ok' => true, 'columns' => [
            'done' => [['id' => 'c1', 'text' => '<script>alert(1)</script>', 'origin' => 'turn']],
        ]]);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'model-written text is escaped, never live markup');
        self::assertStringContainsString('&lt;script&gt;', $html, 'it lands as escaped text');
    }

    public function testColumnsKeepTheOrderTheFoldBringsThem(): void
    {
        $html = $this->render(['ok' => true, 'columns' => [
            'pending' => [],
            'in_progress' => [],
            'done' => [],
            'blocked' => [],
        ]]);

        self::assertLessThan(
            strpos($html, 'data-status="done"'),
            strpos($html, 'data-status="pending"'),
            'the enum decides the column order server-side; the renderer keeps it',
        );
    }

    public function testTheWaitingAlertCarriesNoWritableControl(): void
    {
        $html = $this->render([
            'ok' => true,
            'session' => 's1',
            'pending_question' => 'which value?',
            'columns' => ['blocked' => []],
        ]);

        self::assertStringNotContainsString('<button', $html, 'the one write lives in the page shell, never in the rendered board');
        self::assertStringNotContainsString('<form', $html);
    }
}
