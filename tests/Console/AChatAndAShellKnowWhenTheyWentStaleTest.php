<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Console;

use Milpa\Agent\PendingQuestion;
use Milpa\Agent\Session;
use Milpa\AppRuntime\Console\ChatHandoff;
use Milpa\AppRuntime\Console\KernelSupervisor;
use Milpa\AppRuntime\Console\StaleWatchTerminal;
use Milpa\AppRuntime\Support\KernelDefinition;
use Milpa\AppRuntime\Tui\AgentScreen;
use Milpa\Live\Contracts\Tui\TerminalInterface;
use PHPUnit\Framework\TestCase;

/**
 * `coa chat` and `coa shell` outlive what defines the house too (greenhouse decisions/0519, evidence/1053).
 *
 * The split of 0507 — a supervisor that holds the terminal and no kernel, a child that leaves with 75 when stale —
 * plus what a CONVERSATION needs on top of a panel: the session and the unsent text cross to the clean child, a
 * prompt pressed on a stale kernel runs once on the next one and never on the old, and what the person would lose
 * is said when the next one cannot boot. The supervisor runs for real, in this process, over a stand-in child.
 */
final class AChatAndAShellKnowWhenTheyWentStaleTest extends TestCase
{
    private string $state;

    protected function setUp(): void
    {
        $this->state = sys_get_temp_dir() . '/milpa-chat-shell-' . bin2hex(random_bytes(5));
        mkdir($this->state, 0o775, true);
    }

    protected function tearDown(): void
    {
        putenv('MILPA_LAB_EMPTY');
        putenv(KernelSupervisor::HANDOFF);
        foreach (['storage/plugins.json'] as $file) {
            @unlink($this->state . '/' . $file);
        }
        @rmdir($this->state . '/storage');
        foreach (glob($this->state . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->state);
    }

    // ── the chat screen ─────────────────────────────────────────────────────────────────────────

    public function testAPromptPressedOnAStaleKernelIsHeldNeitherSentNorCleared(): void
    {
        $asked = [];
        $screen = new AgentScreen(
            static function (string $q) use (&$asked): array {
                $asked[] = $q;

                return ['ok' => true, 'answer' => 'ran', 'steps' => 0, 'tools' => 1];
            },
            width: 74,
            height: 16,
            ansi: false,
            vigente: static fn (): bool => false,
        );

        self::type($screen, 'hola');
        $screen->press('enter');

        self::assertSame([], $asked, 'a stale kernel runs nothing');
        self::assertSame('hola', $screen->borrador(), 'and the text is still the person\'s');
        self::assertTrue($screen->retenida(), 'held: the next process sends it');
        self::assertFalse($screen->contraofertaRetenida());
        self::assertSame([], $screen->conversation(), 'nothing painted as asked');
    }

    public function testACurrentKernelRunsThePromptAsAlways(): void
    {
        $asked = [];
        $screen = new AgentScreen(
            static function (string $q) use (&$asked): array {
                $asked[] = $q;

                return ['ok' => true, 'answer' => 'ran', 'steps' => 0, 'tools' => 1];
            },
            width: 74,
            height: 16,
            ansi: false,
            vigente: static fn (): bool => true,
        );

        self::type($screen, 'hola');
        $screen->press('enter');

        self::assertSame(['hola'], $asked);
        self::assertFalse($screen->retenida());
        self::assertSame('', $screen->borrador());
    }

    public function testAScreenOpenedWithAHeldPromptSendsItOnceAfterTheSessionsHistory(): void
    {
        $asked = [];
        $session = new Session('chat-1', 'x', turns: [
            ['role' => 'user', 'content' => 'antes', 'seq' => 1],
            ['role' => 'assistant', 'content' => 'respuesta de antes', 'seq' => 2],
        ]);
        $screen = new AgentScreen(
            static function (string $q) use (&$asked): array {
                $asked[] = $q;

                return ['ok' => true, 'answer' => 'ran now', 'steps' => 0, 'tools' => 1];
            },
            static fn (): Session => $session,
            width: 74,
            height: 16,
            ansi: false,
            vigente: static fn (): bool => true,
            borrador: 'hola',
            enviarAlAbrir: true,
        );

        $screen->loop()->runOn(new SilentTerminal(), 0, maxTicks: 4);

        self::assertSame(['hola'], $asked, 'sent once — four ticks, one run');
        $said = array_column($screen->conversation(), 'texto');
        self::assertSame('antes', $said[0], 'the history the session already had comes first');
        self::assertSame('hola', $said[2]);
    }

    public function testAHeldPromptIsNotSentWhenNothingWasHeld(): void
    {
        $asked = [];
        $screen = new AgentScreen(
            static function (string $q) use (&$asked): array {
                $asked[] = $q;

                return ['ok' => true, 'answer' => 'ran', 'steps' => 0, 'tools' => 1];
            },
            width: 74,
            height: 16,
            ansi: false,
            borrador: 'a draft, never pressed',
        );

        $screen->loop()->runOn(new SilentTerminal(), 0, maxTicks: 3);

        self::assertSame([], $asked, 'a draft is only text: it waits for Enter');
        self::assertSame('a draft, never pressed', $screen->borrador());
    }

    public function testAHeldCounterOfferGoesOutAsOneAndAHeldAnswerAsAnAnswer(): void
    {
        $session = new Session('chat-1', 'x', question: new PendingQuestion('perm:charge', '¿autorizas charge(7)?', ['sí', 'no']));
        foreach ([true => 'counter', false => 'answer'] as $counter => $expected) {
            $calls = [];
            $screen = new AgentScreen(
                static fn (string $q): array => ['ok' => true, 'answer' => 'ran', 'steps' => 0, 'tools' => 1],
                static fn (): Session => $session,
                static function (string $a) use (&$calls): array {
                    $calls[] = 'answer:' . $a;

                    return ['ok' => true, 'granted' => null];
                },
                74,
                16,
                false,
                contraofertar: static function (string $c) use (&$calls): array {
                    $calls[] = 'counter:' . $c;

                    return ['ok' => false, 'error' => 'stop here'];
                },
                borrador: 'en 5',
                enviarAlAbrir: true,
                contraoferta: (bool) $counter,
            );

            $screen->loop()->runOn(new SilentTerminal(), 0, maxTicks: 2);

            self::assertSame([$expected . ':en 5'], $calls, "held as {$expected}, sent as {$expected}");
        }
    }

    public function testAHeldCounterOfferIsReportedAsOne(): void
    {
        $session = new Session('chat-1', 'x', question: new PendingQuestion('perm:charge', '¿autorizas charge(7)?', ['sí', 'no']));
        $screen = new AgentScreen(
            static fn (string $q): array => ['ok' => true, 'answer' => 'ran', 'steps' => 0, 'tools' => 1],
            static fn (): Session => $session,
            width: 74,
            height: 16,
            ansi: false,
            vigente: static fn (): bool => false,
        );

        self::type($screen, 'en 5');
        $screen->press('enter');

        self::assertTrue($screen->retenida());
        self::assertTrue($screen->contraofertaRetenida(), 'typing on an open question is the «yours» option: a counter-offer');
    }

    // ── the watch ───────────────────────────────────────────────────────────────────────────────

    public function testTheWatchAsksNowWhenAskedAndRemembersWhetherTheHouseHadSettled(): void
    {
        mkdir($this->state . '/storage');
        file_put_contents($this->state . '/storage/plugins.json', '{"plugins":[]}');
        $watch = new StaleWatchTerminal(new SilentTerminal(), KernelDefinition::before($this->state), every: 3600.0);

        self::assertNull($watch->staleNow(), 'current');
        self::assertTrue($watch->settled(), 'found current once: this screen served on a settled house');
        file_put_contents($this->state . '/storage/plugins.json', '{"plugins":[{"name":"Blog"}]}');
        self::assertSame('', $watch->pollInput(), 'the tick waits an hour here');
        self::assertSame('storage/plugins.json', $watch->staleNow(), 'the question before acting does not');
        self::assertSame("\x03", $watch->pollInput(), 'and the screen closes on the next poll');
        file_put_contents($this->state . '/storage/plugins.json', '{"plugins":[]}');
        self::assertSame('storage/plugins.json', $watch->staleNow(), 'stale is kept, even if the file went back');

        $fresh = new StaleWatchTerminal(new SilentTerminal(), KernelDefinition::before($this->state), every: 3600.0);
        file_put_contents($this->state . '/storage/plugins.json', '{"plugins":[{"name":"Again"}]}');
        self::assertNotNull($fresh->staleNow());
        self::assertFalse($fresh->settled(), 'stale at its first question: it never served');
    }

    // ── the handoff ─────────────────────────────────────────────────────────────────────────────

    public function testTheChatsHandoffCarriesTheSessionAndTheTextAndSaysWhatWouldBeLost(): void
    {
        $line = (new ChatHandoff('chat-0929-a1b2', 'añade «Blog»', true, true))->encode();
        self::assertStringNotContainsString("\n", $line, 'one line: the second one is the supervisor\'s');

        $back = ChatHandoff::decode($line);
        self::assertNotNull($back);
        self::assertSame(['chat-0929-a1b2', 'añade «Blog»', true, true], [$back->session, $back->draft, $back->send, $back->counter]);

        self::assertNull(ChatHandoff::decode(null));
        self::assertNull(ChatHandoff::decode('plugins'), 'a panel\'s section is not a chat\'s handoff');
        self::assertNull(ChatHandoff::decode('{"draft":"x"}'), 'no session, no chat');
        $loose = ChatHandoff::decode('{"session":"s","draft":7,"send":"yes"}');
        self::assertNotNull($loose);
        self::assertSame(['', false, false], [$loose->draft, $loose->send, $loose->counter], 'what is not the right type is not believed');

        self::assertSame('Not sent — it never ran: añade «Blog»', ChatHandoff::lostOnClose($line));
        self::assertSame('What you were typing: hola', ChatHandoff::lostOnClose((new ChatHandoff('s', 'hola'))->encode()));
        self::assertNull(ChatHandoff::lostOnClose((new ChatHandoff('s'))->encode()), 'nothing typed, nothing lost');
        self::assertNull(ChatHandoff::lostOnClose('routes'));
    }

    public function testAChildReadsItsHandoffOnceAndPassesItToNoOne(): void
    {
        putenv(KernelSupervisor::HANDOFF . '=the last child\'s');

        self::assertSame('the last child\'s', KernelSupervisor::handedOver());
        self::assertFalse(getenv(KernelSupervisor::HANDOFF), 'removed: a tool this child runs does not inherit it');
        self::assertNull(KernelSupervisor::handedOver());
    }

    // ── the supervisor of a terminal ────────────────────────────────────────────────────────────

    public function testManyChangesAPersonMakesInARowAreManyHealthyChildrenNotALoop(): void
    {
        file_put_contents($this->state . '/stale', (string) (KernelSupervisor::STALE_IN_A_ROW + 3));
        file_put_contents($this->state . '/settled', '');
        file_put_contents($this->state . '/exit', '0');

        self::assertSame(0, $this->terminal($errors));
        self::assertCount(KernelSupervisor::STALE_IN_A_ROW + 4, $this->lines('opened.log'), 'eight changes, nine children, all served');
        self::assertSame('', $errors);
    }

    public function testAHouseThatNeverSettlesStillStops(): void
    {
        file_put_contents($this->state . '/stale', '99');

        self::assertSame(1, $this->terminal($errors, 'chat'));
        self::assertCount(KernelSupervisor::STALE_IN_A_ROW, $this->lines('opened.log'));
        self::assertStringContainsString('while the chat was starting', $errors);
    }

    public function testTheNextChildFindsTheHandoffInAnEnvironmentOtherwiseUntouched(): void
    {
        putenv('MILPA_LAB_EMPTY=');
        file_put_contents($this->state . '/stale', '1');
        file_put_contents($this->state . '/showing', 'op:plugins:list');
        file_put_contents($this->state . '/exit', '0');

        self::assertSame(0, $this->terminal($errors, 'shell'));
        self::assertSame(['-', 'op:plugins:list'], $this->lines('handed.log'), 'the first child is handed nothing; the second, what the first showed');
        self::assertSame(['present', 'present'], $this->lines('env.log'), 'an empty variable of the person\'s reaches every child');
        self::assertFalse(getenv(KernelSupervisor::HANDOFF), 'and the supervisor keeps nothing of it');
    }

    public function testWhatTheChatHeldIsSaidWhenTheNextChildCannotBoot(): void
    {
        file_put_contents($this->state . '/stale', '1');
        file_put_contents($this->state . '/showing', (new ChatHandoff('chat-1', 'hola', true))->encode());
        file_put_contents($this->state . '/exit', '1');

        self::assertSame(1, $this->terminal($errors, 'chat', ChatHandoff::lostOnClose(...)));
        self::assertStringContainsString('the house did not start (exit 1); the chat closed', $errors);
        self::assertStringContainsString('Not sent — it never ran: hola', $errors);
        self::assertCount(2, $this->lines('opened.log'), 'no third attempt: stopped honestly');
    }

    public function testAChildThatClosesNormallyLosesNothingWorthSaying(): void
    {
        file_put_contents($this->state . '/stale', '1');
        file_put_contents($this->state . '/showing', (new ChatHandoff('chat-1', 'hola'))->encode());
        file_put_contents($this->state . '/exit', '130');

        self::assertSame(130, $this->terminal($errors, 'chat', ChatHandoff::lostOnClose(...)));
        self::assertSame('', $errors, 'Ctrl-C is the person leaving, not the house failing');
    }

    /**
     * Run the terminal supervisor here over the stand-in child; `$errors` receives what it told the person.
     *
     * @param null|callable(string): ?string $held
     */
    private function terminal(?string &$errors, string $surface = 'panel', ?callable $held = null): int
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $state = $this->state;
        $code = KernelSupervisor::terminal(
            static fn (?string $showing): array => [\PHP_BINARY, __DIR__ . '/../Fixtures/supervisor/terminal-child.php', $state],
            $this->state,
            surface: $surface,
            held: $held,
            errors: $stream,
        );
        rewind($stream);
        $errors = (string) stream_get_contents($stream);
        fclose($stream);

        return $code;
    }

    private static function type(AgentScreen $screen, string $text): void
    {
        foreach (preg_split('//u', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $screen->press($char);
        }
    }

    /** @return list<string> */
    private function lines(string $file): array
    {
        return is_file($this->state . '/' . $file) ? array_values(array_filter(explode("\n", (string) file_get_contents($this->state . '/' . $file)))) : [];
    }
}

/** A terminal nobody types on: every poll is silence, every paint goes nowhere. */
final class SilentTerminal implements TerminalInterface
{
    public function start(callable $onInput, callable $onResize): void
    {
    }

    public function stop(): void
    {
    }

    public function write(string $data): void
    {
    }

    public function pollInput(): string
    {
        return '';
    }

    public function atEndOfInput(): bool
    {
        return false;
    }

    public function columns(): int
    {
        return 74;
    }

    public function rows(): int
    {
        return 16;
    }

    public function moveBy(int $lines): void
    {
    }

    public function hideCursor(): void
    {
    }

    public function showCursor(): void
    {
    }

    public function clearLine(): void
    {
    }

    public function clearFromCursor(): void
    {
    }

    public function clearScreen(): void
    {
    }

    public function setTitle(string $title): void
    {
    }
}
