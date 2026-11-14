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

    /** THE ANSWER NAMES WHAT THE SCREEN SHOWED (greenhouse decisions/0633): the question and the bytes it covers. */
    public function testAnAnswerFromTheScreenNamesTheQuestionAndItsBytes(): void
    {
        $session = new Session(id: 's1', goal: 'x', question: new PendingQuestion('perm:sandbox:promote', 'Promote?', ['yes', 'no'], self::promotion()));
        $sent = null;
        $screen = new AgentScreen(static fn (string $q): array => ['ok' => true], static fn (): Session => $session, static function (string $answer, array $seen = []) use (&$sent): array {
            $sent = [$answer, $seen];

            return ['ok' => true];
        }, 100, 40, false);
        $now = 100.0;
        $this->clock($screen, $now);

        $screen->render();
        $now += 1.0;
        $screen->press('enter');

        self::assertSame(['no', ['question' => 'perm:sandbox:promote', 'digest' => self::DIGEST]], $sent, 'the default is no, and it names what was shown');
    }

    public function testASubAgentsPromotionIsShownWithItsCodeOpensOnNoAndItsAnswerNamesItsBytes(): void
    {
        $session = new Session('jornada', 'x');
        $sent = null;
        $screen = new AgentScreen(
            static fn (string $q): array => ['ok' => true],
            static fn (): Session => $session,
            null,
            100,
            40,
            false,
            preguntaDeHijo: static fn (): array => ['session' => 'jornada.sub-1', 'question' => 'The agent wants to promote trial «wchild1»…', 'options' => ['yes', 'no'], 'id' => 'perm:sandbox:promote', 'why' => self::promotion('wchild1')],
            contestarHijo: static function (string $child, string $answer, array $seen = []) use (&$sent): array {
                $sent = [$child, $answer, $seen];

                return ['ok' => true];
            },
        );

        $now = 100.0;
        $this->clock($screen, $now);
        $text = preg_replace('/\e\[[0-9;]*m/', '', $screen->render()) ?? '';
        self::assertStringContainsString('│ +// line 35 of Probe, ordinary code', $text, 'the child\'s code is on the screen, quoted');
        self::assertStringContainsString('▸ no', $text, 'and the answer opens on no');

        $now += 1.0;
        $screen->press('enter');

        self::assertSame(['jornada.sub-1', 'no', ['question' => 'perm:sandbox:promote', 'digest' => self::DIGEST]], $sent);
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

            self::assertStringContainsString('▸ no', $text, "{$width}×{$height}: the answer row is on screen");
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

    public function testALaterPromotionOpensOnNoAgainAndTheAnswerNamesWhatWasPainted(): void
    {
        $other = 'diff:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
        $question = new PendingQuestion('perm:sandbox:promote', 'Promote?', ['yes', 'no'], self::promotion());
        $session = new Session(id: 's1', goal: 'x', question: $question);
        $sent = [];
        $screen = new AgentScreen(static fn (string $q): array => ['ok' => true], static function () use (&$session): Session {
            return $session;
        }, static function (string $answer, array $seen = []) use (&$sent): array {
            $sent[] = [$answer, $seen];

            return ['ok' => true];
        }, 100, 40, false);
        $now = 100.0;
        $this->clock($screen, $now);

        $screen->render();
        $screen->press('left');
        self::assertStringContainsString('▸ yes', $screen->render(), 'the person moved to yes on the first');

        $session = new Session(id: 's1', goal: 'x');
        $screen->render();
        $second = json_decode(self::promotion(), true);
        $second['digest'] = $other;
        $session = new Session(id: 's1', goal: 'x', question: new PendingQuestion('perm:sandbox:promote', 'Promote?', ['yes', 'no'], (string) json_encode($second)));

        self::assertStringContainsString('▸ no', $screen->render(), 'other bytes open on no again (0633, M2)');

        // A key handled after the question changed names what the frame painted, not what waits now.
        $session = new Session(id: 's1', goal: 'x', question: $question);
        $now += 1.0;
        $screen->press('enter');
        self::assertSame($other, $sent[0][1]['digest'] ?? null, 'the answer names the painted bytes; the house refuses them if others wait');
    }

    /**
     * A KEY TYPED BEFORE A PROMOTION WAS ON SCREEN DOES NOT ANSWER IT (the third review of 0633, M2): keys typed together
     * are handled one per frame, and an Enter typed while one promotion was shown must not land on the next.
     */
    public function testAnEnterRightAfterThePromotionChangedIsDroppedAndOneAfterReadingAnswers(): void
    {
        $first = new PendingQuestion('perm:sandbox:promote', 'Promote?', ['yes', 'no'], self::promotion());
        $second = json_decode(self::promotion(), true);
        $second['digest'] = 'diff:' . str_repeat('2', 64);
        $session = new Session(id: 's1', goal: 'x', question: $first);
        $sent = [];
        $screen = new AgentScreen(static fn (string $q): array => ['ok' => true], static function () use (&$session): Session {
            return $session;
        }, static function (string $answer, array $seen = []) use (&$sent): array {
            $sent[] = [$answer, $seen['digest'] ?? null];

            return ['ok' => true];
        }, 100, 40, false);
        $now = 100.0;
        $this->clock($screen, $now);

        $screen->render();
        $now += 5.0;
        $session = new Session(id: 's1', goal: 'x', question: new PendingQuestion('perm:sandbox:promote', 'Promote?', ['yes', 'no'], (string) json_encode($second)));
        $screen->render();
        $now += 0.05;
        $screen->press('left');
        $screen->press('enter');

        self::assertSame([], $sent, 'an Enter 50 ms after the bytes changed answers nothing');

        $now += 1.0;
        $screen->render();
        $screen->press('enter');

        self::assertSame([['yes', 'diff:' . str_repeat('2', 64)]], $sent, 'once it has been on screen, the Enter answers what is shown — the selection the person can see');
    }

    public function testThePromotionAskedAgainAfterItWentAwayOpensOnNo(): void
    {
        $question = new PendingQuestion('perm:sandbox:promote', 'Promote?', ['yes', 'no'], self::promotion());
        $session = new Session(id: 's1', goal: 'x', question: $question);
        $screen = new AgentScreen(static fn (string $q): array => ['ok' => true], static function () use (&$session): Session {
            return $session;
        }, null, 100, 40, false);

        $screen->render();
        $screen->press('left');
        $session = new Session(id: 's1', goal: 'x', plan: 'a plan, so the screen is the session and not its cover');
        $screen->render();
        $session = new Session(id: 's1', goal: 'x', question: $question);

        self::assertStringContainsString('▸ no', $screen->render(), 'a question that went away and came back opens on its own default');
    }

    public function testTheControlAQuestionThatIsNotAPromotionPaintsNoCode(): void
    {
        $text = $this->paint(new PendingQuestion('perm:make', 'May I make?', ['yes', 'no'], '{"operation":"make","arguments":{"what":"plugin"}}'));

        self::assertStringNotContainsString('What this promotion lands', $text);
    }

    /** A clock the test moves: the screen holds a promotion's answer until it has been on screen long enough to read. */
    private function clock(AgentScreen $screen, float &$now): void
    {
        $screen->useClock(static function () use (&$now): float {
            return $now;
        });
    }

    private function paint(PendingQuestion $question): string
    {
        $session = new Session(id: 's1', goal: 'x', question: $question);
        $screen = new AgentScreen(static fn (string $q): array => ['ok' => true], static fn (): Session => $session, null, 100, 40, false);

        return preg_replace('/\e\[[0-9;]*m/', '', $screen->render()) ?? '';
    }
}
