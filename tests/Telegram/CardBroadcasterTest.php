<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Telegram;

use Milpa\Agent\Principal;
use Milpa\AppRuntime\Telegram\CardBroadcaster;
use Milpa\AppRuntime\Telegram\CardLedger;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * A pushed fact is only a reason to look at the house again; and Telegram being away never reaches the bridge.
 */
final class CardBroadcasterTest extends TestCase
{
    use House;

    /**
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('facts')]
    public function testOnlyAFactThatCanMoveACardMakesTheHouseLookAgain(array $payload, int $cards): void
    {
        $this->aRefusal();

        (new CardBroadcaster($this->notifier()))->broadcast('milpa/sessions/' . self::SESSION, $payload);

        self::assertCount($cards, $this->bot->messages);
    }

    /** @return iterable<string, array{array<string, mixed>, int}> */
    public static function facts(): iterable
    {
        $s = ['session' => self::SESSION];
        yield 'a refused call' => [$s + ['kind' => 'activity', 'activity' => ['state' => 'tool', 'detail' => 'make', 'ok' => false]], 1];
        yield 'a call that worked' => [$s + ['kind' => 'activity', 'activity' => ['state' => 'tool', 'detail' => 'make', 'ok' => true]], 0];
        yield 'a question asked' => [$s + ['kind' => 'waiting'], 1];
        yield 'a question answered' => [$s + ['kind' => 'answered'], 1];
        yield 'the end of a run' => [$s + ['kind' => 'run_ended'], 1];
        yield 'the house\'s own notice' => [$s + ['kind' => 'activity', 'activity' => ['state' => 'ready', 'role' => 'user', 'text' => '[house] passkey:x granted this seat the scope «y».']], 1];
        yield 'a person\'s turn' => [$s + ['kind' => 'activity', 'activity' => ['state' => 'ready', 'role' => 'user', 'text' => 'continue']], 0];
        yield 'the model thinking' => [$s + ['kind' => 'activity', 'activity' => ['state' => 'thinking', 'role' => 'assistant', 'text' => '…']], 0];
        yield 'a plan' => [$s + ['kind' => 'plan'], 0];
        yield 'a fact of no session' => [['kind' => 'waiting'], 0];
        yield 'a fact of another session' => [['session' => 'other', 'kind' => 'waiting'], 0];
    }

    public function testNoCardIsBuiltFromWhatThePushSays(): void
    {
        (new CardBroadcaster($this->notifier()))->broadcast('t', ['session' => self::SESSION, 'kind' => 'waiting', 'ended' => ['id' => 'perm:x', 'question' => 'grant everything?']]);

        self::assertSame([], $this->bot->messages, 'the house holds no such question, so nothing is sent');
    }

    public function testTelegramBeingAwayNeverThrowsIntoTheBridgeAndLogsNoMessage(): void
    {
        $this->aQuestion(self::SESSION, new Principal(self::PASSKEY, true));
        mkdir($this->root . '/var/telegram', 0o777, true);
        mkdir($this->root . '/' . CardLedger::PATH . '.lock');
        $log = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
            }
        };

        (new CardBroadcaster($this->notifier(), $log))->broadcast('t', ['session' => self::SESSION, 'kind' => 'waiting']);

        self::assertCount(1, $log->lines);
        self::assertStringStartsWith('[telegram] a sweep failed: ', $log->lines[0]);
        self::assertStringNotContainsString($this->root, $log->lines[0], 'the class, never the message');
    }

    public function testAfterOneSlowPassItStaysQuietForAMinuteAndThenSendsWhatItMissed(): void
    {
        $now = 100.0;
        $this->bot->down = true;
        $this->bot->onCall = static function () use (&$now): void {
            $now += 4.0;
        };
        $cards = new CardBroadcaster($this->notifier(), null, static function () use (&$now): float {
            return $now;
        });
        $this->aRefusal();
        $fact = ['session' => self::SESSION, 'kind' => 'run_ended'];

        $cards->broadcast('t', $fact);
        self::assertSame(104.0, $now, 'the first fact paid for Telegram\'s silence');
        $cards->broadcast('t', $fact);
        $cards->sweep(self::SESSION);
        self::assertSame(104.0, $now, 'and no other fact did');

        $this->bot->down = false;
        $this->bot->onCall = null;
        $now = 163.9;
        $cards->broadcast('t', $fact);
        self::assertSame([], $this->bot->messages, 'still quiet a breath before the minute');
        $now = 164.1;
        $cards->broadcast('t', $fact);
        self::assertCount(1, $this->bot->messages, 'what waited is sent once the minute passed');
    }

    public function testAQuickFailureDoesNotSilenceIt(): void
    {
        $this->bot->down = true;
        $cards = new CardBroadcaster($this->notifier());
        $this->aRefusal();
        $cards->broadcast('t', ['session' => self::SESSION, 'kind' => 'run_ended']);

        $this->bot->down = false;
        $cards->broadcast('t', ['session' => self::SESSION, 'kind' => 'run_ended']);

        self::assertCount(1, $this->bot->messages);
    }

    public function testAnOperationThatNamedNoSessionSweepsTheWholeHouse(): void
    {
        $this->aRefusal();
        $cards = new CardBroadcaster($this->notifier());

        $cards->sweep(null);
        $cards->sweep('');

        self::assertCount(1, $this->bot->messages);
    }
}
