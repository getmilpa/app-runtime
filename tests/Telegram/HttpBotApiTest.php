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

use Milpa\AppRuntime\Telegram\HttpBotApi;
use PHPUnit\Framework\TestCase;

/**
 * The client against a LOCAL stand-in for the Bot API (tests/Telegram/fake-bot-api.php) — not Telegram: it holds
 * the shape of the calls this house makes, and that a call which did not work says nothing.
 */
final class HttpBotApiTest extends TestCase
{
    use FakeTelegram;

    public function testACardIsSentPlainWithOneUrlButtonAndItsIdComesBack(): void
    {
        $id = $this->bot()->send('4242', 'A <b>seat</b>\'s call was refused.', 'Open the house', 'https://casa.example/telegram/open/x');

        self::assertIsInt($id);
        $sent = $this->state()['messages'][(string) $id];
        self::assertSame('4242', $sent['chat_id']);
        self::assertSame('A <b>seat</b>\'s call was refused.', $sent['text']);
        self::assertSame([[['text' => 'Open the house', 'url' => 'https://casa.example/telegram/open/x']]], $sent['reply_markup']['inline_keyboard']);
        $call = $this->state()['calls'][array_key_last($this->state()['calls'])];
        self::assertArrayNotHasKey('parse_mode', $call['body'], 'plain text: nothing a card names becomes markup');
    }

    public function testAMessageWithoutAButtonSendsNoKeyboard(): void
    {
        $id = $this->bot()->send('4242', 'plain');

        self::assertNull($this->state()['messages'][(string) $id]['reply_markup']);
    }

    public function testARewriteReplacesTheTextAndTakesTheButtonAway(): void
    {
        $id = (int) $this->bot()->send('4242', 'waits', 'Open the house', 'https://casa.example/telegram/open/x');

        self::assertTrue($this->bot()->edit('4242', $id, 'Decided: key:C1FEA43B… granted plugins.Blog:write.'));
        $after = $this->state()['messages'][(string) $id];
        self::assertSame('Decided: key:C1FEA43B… granted plugins.Blog:write.', $after['text']);
        self::assertSame(['inline_keyboard' => []], $after['reply_markup']);

        self::assertTrue($this->bot()->edit('4242', $id, 'expired', 'Open the panel', 'https://casa.example/milpa/admin'));
        self::assertSame('https://casa.example/milpa/admin', $this->state()['messages'][(string) $id]['reply_markup']['inline_keyboard'][0][0]['url']);
    }

    public function testACallThatDidNotWorkAnswersNothingAndSaysNothing(): void
    {
        self::assertFalse($this->bot()->edit('4242', 999_999, 'no such message'));
        self::assertFalse($this->bot()->edit('1111', 1, 'another chat\'s message'));
        self::assertNull((new HttpBotApi('wrong-token', self::$api))->send('4242', 'x'), 'a wrong token');
        self::assertNull((new HttpBotApi(self::TOKEN, 'http://127.0.0.1:1', 0.5))->send('4242', 'x'), 'nobody listening');
    }

    public function testTheTokenIsNotAmongWhatAStackTraceShows(): void
    {
        $constructor = new \ReflectionMethod(HttpBotApi::class, '__construct');

        self::assertNotSame([], $constructor->getParameters()[0]->getAttributes(\SensitiveParameter::class));
    }

    private function bot(): HttpBotApi
    {
        return new HttpBotApi(self::TOKEN, self::$api . '/');
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        return self::told();
    }
}
