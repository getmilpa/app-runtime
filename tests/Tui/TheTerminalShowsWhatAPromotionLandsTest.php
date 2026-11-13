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
            'judged' => ['trial_run' => ['operation' => 'make', 'exit' => 0]],
        ]);

        $text = $this->paint(new PendingQuestion('perm:sandbox:promote', 'The agent wants to promote trial «w1»…', ['yes', 'no'], $why));

        self::assertStringContainsString('What this promotion lands in the house', $text);
        self::assertStringContainsString('src/Feed.php (new file)', $text);
        self::assertStringContainsString("│ +return 'MARKER-OF-THE-CODE';", $text, 'the code, quoted as the agent\'s');
        self::assertStringContainsString('trial_run operation: make', $text);
        self::assertLessThan(strpos($text, 'The agent wants to promote'), strpos($text, 'MARKER-OF-THE-CODE'), 'read before the question');
    }

    public function testTheControlAQuestionThatIsNotAPromotionPaintsNoCode(): void
    {
        $text = $this->paint(new PendingQuestion('perm:make', 'May I make?', ['yes', 'no'], '{"operation":"make","arguments":{"what":"plugin"}}'));

        self::assertStringNotContainsString('What this promotion lands', $text);
    }

    private function paint(PendingQuestion $question): string
    {
        $session = new Session(id: 's1', goal: 'x', question: $question);
        $screen = new AgentScreen(static fn (string $q): array => ['ok' => true], static fn (): Session => $session, null, 100, 40, false);

        return preg_replace('/\e\[[0-9;]*m/', '', $screen->render()) ?? '';
    }
}
