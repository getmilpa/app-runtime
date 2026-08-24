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

namespace Milpa\AppRuntime\Tests\Web;

use PHPUnit\Framework\TestCase;

/**
 * The browser painter's SURVIVING job: the live activity line.
 *
 * The board fold itself is no longer painted here — since greenhouse evidence/0296 the server renders
 * it with the one Live component ({@see \Milpa\AppRuntime\Board\BoardHtmlRenderer}, whose parity lives
 * in {@see \Milpa\AppRuntime\Tests\Board\BoardHtmlParityTest}). What stays client-side is
 * `paintActivity`: the one line for what the session is doing right now, pushed live, which has no
 * durable fold to refetch.
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
}
