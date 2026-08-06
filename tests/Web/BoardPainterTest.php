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

namespace Milpa\AppRuntime\Tests\Web;

use PHPUnit\Framework\TestCase;

/**
 * The painter, exercised as the artifact it is: the SAME JavaScript file the browser runs, executed
 * under `node`. A painter that can only be verified inside a browser is a painter that in practice
 * nobody verifies — and these claims (a born-done card is set apart, model text cannot become
 * markup, nothing writable is painted) are exactly the ones the board spec makes acceptance
 * criteria, so they get checks that can fail.
 */
final class BoardPainterTest extends TestCase
{
    protected function setUp(): void
    {
        exec('command -v node 2>/dev/null', $out, $code);
        if ($code !== 0) {
            // A skip is visible in the run's output; a check that silently passes without its
            // runtime would be silence wearing green (ADR-0029).
            self::markTestSkipped('node is required to exercise the browser painter');
        }
    }

    /**
     * Runs one painter function under node with one JSON argument, and returns what it returned.
     *
     * @param array<string, mixed> $argument
     */
    private static function paint(string $function, array $argument): mixed
    {
        $painter = \dirname(__DIR__, 2) . '/resources/web/board-painter.js';
        $script = sprintf(
            'const p = require(%s); const r = p[%s](JSON.parse(process.argv[1]));'
            . ' console.log(JSON.stringify(r === undefined ? null : r));',
            json_encode($painter, \JSON_THROW_ON_ERROR),
            json_encode($function, \JSON_THROW_ON_ERROR),
        );

        exec(
            sprintf(
                'node -e %s %s 2>&1',
                escapeshellarg($script),
                escapeshellarg(json_encode($argument, \JSON_THROW_ON_ERROR)),
            ),
            $lines,
            $code,
        );
        self::assertSame(0, $code, 'the painter crashed under node: ' . implode("\n", $lines));

        return json_decode($lines[\count($lines) - 1], true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $columns
     *
     * @return array<string, mixed> a well-formed `agent:board` response to build fixtures from
     */
    private static function board(array $columns, ?string $question = null): array
    {
        $board = ['ok' => true, 'session' => 'org-1', 'columns' => $columns];
        if ($question !== null) {
            $board['pending_question'] = $question;
        }

        return $board;
    }

    /**
     * Criterion 2 of the spec, made executable: a card born already done is set apart — and one
     * that crossed is NOT. Both directions live in one test on purpose: a painter that marked
     * everything or marked nothing would each fail exactly one of these assertions.
     */
    public function testACardBornDoneIsSetApartAndACrossedOneIsNot(): void
    {
        $html = self::paint('paintBoard', self::board([
            'pending' => [],
            'in_progress' => [],
            'done' => [
                ['id' => 't1', 'text' => 'create the plugin', 'version' => 1, 'origin' => 'retrospective', 'unexplained' => 0],
                ['id' => 't2', 'text' => 'register the plugin', 'version' => 2, 'origin' => 'planned', 'unexplained' => 0],
            ],
            'blocked' => [],
        ]));

        self::assertIsString($html);
        self::assertStringContainsString('data-id="t1" data-origin="retrospective" data-crossed="false"', $html);
        self::assertStringContainsString('appeared already done', $html);
        self::assertStringContainsString('data-id="t2" data-origin="planned" data-crossed="true"', $html);
        self::assertSame(1, substr_count($html, 'appeared already done'), 'only the born-done card carries the flag');
    }

    /**
     * The columns are the keys the data brings, in the order it brings them. The enum decides
     * server-side; a list written in the painter would be the second place deciding how many
     * columns exist, and a new status would be born invisible on this surface.
     */
    public function testTheColumnsAreTheKeysTheDataBringsInTheOrderItBringsThem(): void
    {
        $html = self::paint('paintBoard', self::board([
            'pending' => [], 'in_progress' => [], 'done' => [], 'blocked' => [], 'someday' => [],
        ]));

        self::assertIsString($html);
        $positions = [];
        foreach (['pending', 'in_progress', 'done', 'blocked', 'someday'] as $status) {
            $at = strpos($html, 'data-status="' . $status . '"');
            self::assertIsInt($at, "the {$status} column was not painted");
            $positions[] = $at;
        }
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'the columns were reordered by the painter');
    }

    /** Card texts are written by a model. A model that writes markup must land as text. */
    public function testModelWrittenTextLandsAsTextNeverAsMarkup(): void
    {
        $html = self::paint('paintBoard', self::board([
            'pending' => [['id' => 't1', 'text' => '<script>alert(1)</script>', 'version' => 1, 'origin' => 'planned', 'unexplained' => 0]],
        ]));

        self::assertIsString($html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * The open question is stopped work waiting for a human — the one thing this surface must not
     * hide. But it is painted WITHOUT an answer control: answering writes to the stream, and that
     * step is gated on verified identity.
     */
    public function testTheOpenQuestionIsPaintedWithoutAnyWritableControl(): void
    {
        $html = self::paint('paintBoard', self::board(
            ['pending' => []],
            'La petición no nombra a «PluginTres». ¿Confirmas?',
        ));

        self::assertIsString($html);
        self::assertStringContainsString('PluginTres', $html);
        self::assertStringNotContainsStringIgnoringCase('<button', $html);
        self::assertStringNotContainsStringIgnoringCase('<form', $html);
        self::assertStringNotContainsStringIgnoringCase('<input', $html);
    }

    /**
     * The tool NAME is what makes progress observable while nothing durable changes: a fixed text
     * for sixteen seconds does not distinguish work from a hang; a changing name does.
     */
    public function testActivityNamesTheToolThatIsRunning(): void
    {
        $line = self::paint('paintActivity', [
            'session' => 'org-1', 'kind' => 'activity', 'at' => 7,
            'activity' => ['state' => 'tool', 'detail' => 'plugins_list', 'mutating' => false, 'ok' => true],
        ]);

        self::assertSame('running plugins_list', $line);
    }

    /**
     * An answer clears the waiting face: the line says the question was answered — and by whom,
     * when the fact names an executor — while the fold refetch removes the banner. Without this
     * case the surface kept saying «waiting for a human» about a question already answered.
     */
    public function testAnAnswerIsSaidSoTheWaitingFaceClears(): void
    {
        $line = self::paint('paintActivity', [
            'session' => 'org-1', 'kind' => 'answered', 'at' => 9,
            'answered' => ['answer' => 'sí', 'by' => null, 'executor' => 'cli:rod'],
        ]);

        self::assertSame('question answered: sí (cli:rod)', $line);
    }

    /**
     * An unknown kind is not guessed. A stream is read years after it was written, and a surface
     * that invents what to do with the unknown paints anything with the face of data.
     */
    public function testAnUnknownKindIsNotGuessed(): void
    {
        self::assertNull(self::paint('paintActivity', ['session' => 'org-1', 'kind' => 'something.new', 'at' => 7]));
    }

    /** A board that could not be read says so — it does not paint four empty, plausible columns. */
    public function testAFailedFoldIsSaidNotDressedAsAnEmptyBoard(): void
    {
        $html = self::paint('paintBoard', ['ok' => false, 'error' => 'no existe la sesión «x»']);

        self::assertIsString($html);
        self::assertStringContainsString('no existe la sesión', $html);
        self::assertStringNotContainsString('data-status=', $html);
    }
}
