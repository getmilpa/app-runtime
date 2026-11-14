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

namespace Milpa\AppRuntime\Tests\Tui;

use Milpa\Agent\PendingQuestion;
use Milpa\Agent\Session;
use Milpa\AppRuntime\Tui\AgentScreen;
use PHPUnit\Framework\TestCase;

/**
 * WHAT A PROMOTION LANDS IS READ BEFORE IT IS ANSWERED (greenhouse decisions/0628).
 *
 * The terminal painted the promotion's question, its files and its ceiling, but not the code: a person answered
 * yes to bytes the screen never showed. It is painted now, above the question, as the house showed it.
 */
final class TheTerminalShowsWhatAPromotionLandsTest extends TestCase
{
    public function testThePromotionsCodeIsPaintedAboveItsQuestion(): void
    {
        $why = (string) json_encode([
            'operation' => 'sandbox:promote',
            'arguments' => ['workspace' => 'w1'],
            'base' => ['subject' => 'executable'],
            'digest' => 'diff:' . str_repeat('a', 64),
            'lands' => [['path' => 'src/Feed.php', 'status' => 'added', 'sha256' => null, 'shown' => "+<?php\n+return 'MARKER-OF-THE-CODE';\n"]],
            'judged' => ['trial_run' => ['operation' => 'make', 'exit' => 0], 'producer' => ['ok' => true]],
        ]);

        $text = $this->paint(new PendingQuestion('perm:sandbox:promote', 'The agent wants to promote trial «w1»…', ['yes', 'no'], $why));

        self::assertStringContainsString('What this promotion lands in the house', $text);
        self::assertStringContainsString('src/Feed.php (new file)', $text);
        self::assertStringContainsString("│ +return 'MARKER-OF-THE-CODE';", $text, 'the code, quoted as the agent\'s');
        self::assertStringContainsString('trial_run operation: make', $text);
        self::assertMatchesRegularExpression('/producer ok:\s+yes/u', $text, 'a recorded yes reads as a word');
        self::assertLessThan(strpos($text, 'The agent wants to promote'), strpos($text, 'MARKER-OF-THE-CODE'), 'read before the question');
    }

    public function testAWrappedRowOfCodeStillCarriesTheQuoteMark(): void
    {
        $long = "+\$x = '" . str_repeat('abcdefghij', 25) . "-END-OF-LONG-LINE';";
        $why = (string) json_encode(['operation' => 'sandbox:promote', 'lands' => [['path' => 'src/F.php', 'status' => 'added', 'shown' => $long . "\n"]]]);

        $text = $this->paint(new PendingQuestion('perm:sandbox:promote', 'Promote?', ['yes', 'no'], $why));
        $rows = array_values(array_filter(explode("\n", $text), static fn (string $row): bool => str_contains($row, 'abcdefghij')));

        self::assertGreaterThan(1, \count($rows), 'the long line takes more than one row');
        foreach ($rows as $row) {
            self::assertMatchesRegularExpression('/^\s*│/u', $row, 'every row of agent-written code is quoted');
        }
        self::assertStringContainsString('-END-OF-LONG-LINE', $text, 'and its end is shown');
    }

    private const DIGEST = 'diff:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    /** A promotion's claim as the gate writes it, with files of the size a real trial lands. */
    private static function promotion(string $workspace = 'w1'): string
    {
        $code = static fn (string $name): string => "+<?php\n" . implode('', array_map(static fn (int $i): string => "+// line {$i} of {$name}, ordinary code\n", range(1, 35)));

        return (string) json_encode([
            'operation' => 'sandbox:promote',
            'arguments' => ['workspace' => $workspace],
            'base' => ['authority' => 'write_as_user', 'reversibility' => 'manual_recovery', 'mutation' => 'persistent', 'externality' => 'none', 'subject' => 'executable'],
            'digest' => self::DIGEST,
            'lands' => [
                ['path' => 'src/Plugins/Blog/Blog.php', 'status' => 'added', 'sha256' => null, 'shown' => $code('Blog')],
                ['path' => 'src/Plugins/Blog/Controllers/ProbeController.php', 'status' => 'added', 'sha256' => null, 'shown' => $code('Probe')],
            ],
            'judged' => ['trial_run' => ['operation' => 'make', 'exit' => 0], 'producer' => ['tool' => 'make', 'ok' => true]],
        ]);
    }

    /** THE ANSWER NAMES THE BYTES (greenhouse decisions/0633): typed with the start of the digest the frame shows. */
    public function testAPromotionIsAnsweredByTypingTheStartOfItsDigest(): void
    {
        foreach (['yes cccc' => 'yes', 'no cccc' => 'no', 'NO CCCC' => 'NO'] as $typed => $answer) {
            [$screen, $sent] = $this->screenOn(self::promotion());
            $screen->render();
            $this->type($screen, $typed);
            $screen->press('enter');

            self::assertSame([[$answer, ['question' => 'perm:sandbox:promote', 'digest' => self::DIGEST]]], $sent->calls, $typed);
        }
    }

    public function testAnEnterOrAnArrowAloneDoesNotAnswerAPromotionAndTheScreenSaysHow(): void
    {
        [$screen, $sent] = $this->screenOn(self::promotion());

        $text = preg_replace('/\e\[[0-9;]*m/', '', $screen->render()) ?? '';
        self::assertStringContainsString('type «yes cccc» or «no cccc»', $text, 'the screen says how');
        $screen->press('left');
        $screen->press('enter');

        self::assertSame([], $sent->calls, 'neither a bare Enter nor an arrow answers it');
        $turns = $screen->conversation();
        self::assertStringContainsString('a promotion is answered by typing «yes cccc»', (string) ($turns[\count($turns) - 1]['texto'] ?? ''));
    }

    public function testAnAnswerNamingOtherBytesAnswersNothing(): void
    {
        [$screen, $sent] = $this->screenOn(self::promotion());
        $screen->render();
        $this->type($screen, 'yes 9f63');
        $screen->press('enter');

        self::assertSame([], $sent->calls);
    }

    /**
     * Keys typed for one promotion cannot answer the next (the timing race of five reviews): what they carry is the start
     * of the digest the person saw, and the frame now shows another.
     */
    public function testAnAnswerTypedForOnePromotionDoesNotAnswerTheNext(): void
    {
        $other = json_decode(self::promotion(), true);
        $other['digest'] = 'diff:' . str_repeat('2', 64);
        $why = self::promotion();
        $sent = new \ArrayObject();
        $session = new Session(id: 's1', goal: 'x', question: new PendingQuestion('perm:sandbox:promote', 'Promote?', ['yes', 'no'], $why));
        $screen = new AgentScreen(static fn (string $q): array => ['ok' => true], static function () use (&$session): Session {
            return $session;
        }, static function (string $answer, array $seen = []) use ($sent): array {
            $sent[] = [$answer, $seen];

            return ['ok' => true];
        }, 100, 40, false);

        $screen->render();
        $this->type($screen, 'yes cccc');
        $session = new Session(id: 's1', goal: 'x', question: new PendingQuestion('perm:sandbox:promote', 'Promote?', ['yes', 'no'], (string) json_encode($other)));
        $screen->render();
        $screen->press('enter');

        self::assertSame([], $sent->getArrayCopy(), 'the start of the old digest answers nothing on the new promotion');
    }

    public function testASubAgentsPromotionIsShownWithItsCodeAndAnsweredByItsDigest(): void
    {
        $session = new Session('jornada', 'x');
        $sent = new \ArrayObject();
        $screen = new AgentScreen(
            static fn (string $q): array => ['ok' => true],
            static fn (): Session => $session,
            null,
            100,
            40,
            false,
            preguntaDeHijo: static fn (): array => ['session' => 'jornada.sub-1', 'question' => 'The agent wants to promote trial «wchild1»…', 'options' => ['yes', 'no'], 'id' => 'perm:sandbox:promote', 'why' => self::promotion('wchild1')],
            contestarHijo: static function (string $child, string $answer, array $seen = []) use ($sent): array {
                $sent[] = [$child, $answer, $seen];

                return ['ok' => true];
            },
        );

        $text = preg_replace('/\e\[[0-9;]*m/', '', $screen->render()) ?? '';
        self::assertStringContainsString('│ +// line 35 of Probe, ordinary code', $text, 'the child\'s code is on the screen, quoted');
        self::assertStringContainsString('type «yes cccc»', $text);

        $screen->press('enter');
        self::assertSame([], $sent->getArrayCopy(), 'a bare Enter answers nothing');
        $this->type($screen, 'yes cccc');
        $screen->press('enter');

        self::assertSame([['jornada.sub-1', 'yes', ['question' => 'perm:sandbox:promote', 'digest' => self::DIGEST]]], $sent->getArrayCopy());
    }

    public function testATightenedYesEndingInTheDigestTravelsAsAnEnvelopeNamingIt(): void
    {
        $session = new Session(id: 's1', goal: 'x', question: new PendingQuestion('perm:sandbox:promote', 'Promote?', ['yes', 'no'], self::promotion()));
        $sent = new \ArrayObject();
        $screen = new AgentScreen(static fn (string $q): array => ['ok' => true], static fn (): Session => $session, static fn (string $a, array $s = []): array => ['ok' => true], 100, 40, false, apretar: static function (array $envelope, array $seen = []) use ($sent): array {
            $sent[] = [$envelope, $seen];

            return ['ok' => true, 'granted' => 'x', 'tightened' => ['subject']];
        });

        $screen->render();
        $this->type($screen, 'subject=configuration cccc');
        $screen->press('enter');

        self::assertSame([[['subject' => 'configuration'], ['question' => 'perm:sandbox:promote', 'digest' => self::DIGEST]]], $sent->getArrayCopy());
    }

    public function testNoControlCharacterOfTheAgentsTextReachesTheTerminal(): void
    {
        $why = (string) json_encode(['operation' => 'sandbox:promote', 'digest' => "diff:x\r■ FORGED-DIGEST", 'judged' => ['producer' => ['verified' => "ok\r■ FORGED-JUDGED"]], 'lands' => [[
            'path' => "src/A.php\n■ FORGED-PATH-ROW", 'status' => 'added', 'sha256' => null,
            'shown' => "+ROW-ONE\r■ FORGED-ROW\n+\eM\e7overwrite\x08\x07\x7f\u{0085}\u{009B}tail\n",
        ]]]);

        $text = $this->paint(new PendingQuestion('perm:sandbox:promote', 'Promote?', ['yes', 'no'], $why));

        foreach (["\r", "\e", "\x08", "\x07", "\x7f", "\u{0085}", "\u{009B}"] as $control) {
            self::assertStringNotContainsString($control, $text, 'a control is drawn, never obeyed');
        }
        self::assertStringContainsString('+ROW-ONE␍⟨U+25A0⟩ FORGED-ROW', $text, 'the carriage return is shown where it is, and the agent cannot draw the house\'s mark');
        self::assertDoesNotMatchRegularExpression('/^\s*■ FORGED/mu', $text, 'no row of agent text starts as the house\'s');
    }

    public function testRowsOfWideCharactersStayQuoted(): void
    {
        $why = (string) json_encode(['operation' => 'sandbox:promote', 'lands' => [['path' => 'src/F.php', 'status' => 'added', 'shown' => '+' . str_repeat('漢', 120) . "\n" . '+' . str_repeat('🌽', 90) . "\n"]]]);

        $text = $this->paint(new PendingQuestion('perm:sandbox:promote', 'Promote?', ['yes', 'no'], $why));
        $rows = array_values(array_filter(explode("\n", $text), static fn (string $row): bool => str_contains($row, '⟨U+6F22⟩') || str_contains($row, '⟨U+1F33D⟩')));

        self::assertGreaterThan(2, \count($rows));
        foreach ($rows as $row) {
            self::assertMatchesRegularExpression('/^\s*│/u', $row, 'every row of wide characters is quoted');
        }
    }

    public function testTheAnswerAndTheInputStayOnScreenWithARealPromotion(): void
    {
        foreach ([[80, 24], [100, 40], [120, 50]] as [$width, $height]) {
            $session = new Session(id: 's1', goal: 'x', question: new PendingQuestion('perm:sandbox:promote', 'The agent wants to promote trial «w1» into the house: 2 changed file(s), ' . self::DIGEST . '. Do you promote exactly these changes?', ['yes', 'no'], self::promotion()));
            $text = preg_replace('/\e\[[0-9;]*m/', '', (new AgentScreen(static fn (string $q): array => ['ok' => true], static fn (): Session => $session, null, $width, $height, false))->render()) ?? '';

            self::assertStringContainsString('type «yes cccc»', $text, "{$width}×{$height}: the answer row is on screen");
            self::assertStringContainsString('tu respuesta…', $text, "{$width}×{$height}: and the input");
            self::assertStringContainsString('[Enter] contestar', $text, "{$width}×{$height}: and the help");
        }
    }

    public function testEveryRowTakesNoMoreColumnsThanTheScreenWithWideCharacters(): void
    {
        $why = (string) json_encode(['operation' => 'sandbox:promote', 'digest' => self::DIGEST, 'lands' => [['path' => 'src/F.php', 'status' => 'added', 'shown' => "+echo '✅ running 🚀 漢字 ＡＢＣ e\u{0301}';\n"]]]);

        $text = $this->paint(new PendingQuestion('perm:sandbox:promote', 'Promote?', ['yes', 'no'], $why));

        self::assertStringContainsString('⟨U+1F680⟩', $text, 'a wide character is written as its code point');
        self::assertStringNotContainsString('🚀', $text);
        self::assertStringContainsString('⟨U+2705⟩', $text);
        self::assertStringContainsString('⟨U+0301⟩', $text, 'a combining mark too');
        foreach (explode("\n", $text) as $row) {
            self::assertLessThanOrEqual(100, \Milpa\Live\Tui\TuiString::visibleLength($row), 'no row wraps in the terminal (0633, H1): ' . $row);
        }
    }

    public function testTheClaimLineDrawsTheTrialsPathsWithoutObeyingThem(): void
    {
        $why = (string) json_encode(['operation' => 'sandbox:promote', 'arguments' => ['workspace' => 'w1'], 'cambios' => ["src/a.php\n■ FORGED-CAMBIA\r\eM" => 'added']]);

        $text = $this->paint(new PendingQuestion('perm:sandbox:promote', 'Promote?', ['yes', 'no'], $why));

        self::assertStringNotContainsString("\e", $text);
        self::assertStringNotContainsString("\r", $text);
        self::assertDoesNotMatchRegularExpression('/^\s*■ FORGED/mu', $text, 'a path never starts a row as the house\'s (0633, M1)');
    }

    public function testASubAgentsQuestionIsReadOncePerFrame(): void
    {
        $reads = 0;
        $session = new Session('jornada', 'x', plan: 'a plan');
        $question = static function (string $workspace): array {
            $why = json_decode(self::promotion($workspace), true);
            $why['lands'] = [['path' => 'src/F.php', 'status' => 'added', 'sha256' => null, 'shown' => "+// CODE-OF-{$workspace}\n"]];
            $why['digest'] = 'diff:' . str_repeat($workspace === 'wa' ? 'a' : 'b', 64);

            return ['session' => 'jornada.sub-1', 'question' => "Promote trial «{$workspace}»?", 'options' => ['yes', 'no'], 'id' => 'perm:sandbox:promote', 'why' => (string) json_encode($why)];
        };
        $screen = new AgentScreen(
            static fn (string $q): array => ['ok' => true],
            static fn (): Session => $session,
            null,
            100,
            40,
            false,
            preguntaDeHijo: static function () use (&$reads, $question): array {
                return $question(++$reads <= 1 ? 'wa' : 'wb');
            },
        );

        $text = $screen->render();

        // Whatever reading the frame took, its code and its question row are the same trial's.
        self::assertSame(str_contains($text, 'Promote trial «wa»'), str_contains($text, 'CODE-OF-wa'), 'one reading for the row and the code');
        self::assertSame(str_contains($text, 'Promote trial «wb»'), str_contains($text, 'CODE-OF-wb'));
        self::assertLessThanOrEqual(2, $reads, 'one reading for what is painted, one to choose the screen');
    }

    public function testTheControlAQuestionThatIsNotAPromotionPaintsNoCode(): void
    {
        $text = $this->paint(new PendingQuestion('perm:make', 'May I make?', ['yes', 'no'], '{"operation":"make","arguments":{"what":"plugin"}}'));

        self::assertStringNotContainsString('What this promotion lands', $text);
    }

    /** @return array{AgentScreen, object{calls: list<array{string, array<string, string>}>}} */
    private function screenOn(string $why): array
    {
        $session = new Session(id: 's1', goal: 'x', question: new PendingQuestion('perm:sandbox:promote', 'Promote?', ['yes', 'no'], $why));
        $sent = new class () {
            /** @var list<array{string, array<string, string>}> */
            public array $calls = [];
        };
        $screen = new AgentScreen(static fn (string $q): array => ['ok' => true], static fn (): Session => $session, static function (string $answer, array $seen = []) use ($sent): array {
            $sent->calls[] = [$answer, $seen];

            return ['ok' => true];
        }, 100, 40, false);

        return [$screen, $sent];
    }

    private function type(AgentScreen $screen, string $text): void
    {
        foreach (preg_split('//u', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $screen->press($char);
        }
    }

    private function paint(PendingQuestion $question): string
    {
        $session = new Session(id: 's1', goal: 'x', question: $question);
        $screen = new AgentScreen(static fn (string $q): array => ['ok' => true], static fn (): Session => $session, null, 100, 40, false);

        return preg_replace('/\e\[[0-9;]*m/', '', $screen->render()) ?? '';
    }
}
