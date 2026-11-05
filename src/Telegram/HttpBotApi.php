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

namespace Milpa\AppRuntime\Telegram;

/**
 * The Bot API over HTTPS, with PHP's own streams: `POST <api>/bot<token>/<method>`, a JSON body, a short timeout.
 *
 * The token is part of the URL Telegram defines, so no failure here is ever turned into a message: a call that
 * did not work answers null or false and says nothing else, because the only text available would carry the token.
 */
final class HttpBotApi implements BotApi
{
    public const DEFAULT_API = 'https://api.telegram.org';

    /**
     * @param string $api     the Bot API's base URL — Telegram's, unless the house declares another (a local server)
     * @param float  $timeout seconds one call may take: the house's work never waits long for a notification
     */
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $token,
        private readonly string $api = self::DEFAULT_API,
        private readonly float $timeout = 4.0,
    ) {
    }

    /** `sendMessage`: the id of the message Telegram took, or null. */
    public function send(string $chat, string $text, ?string $button = null, ?string $url = null): ?int
    {
        $answer = $this->call('sendMessage', ['chat_id' => $chat, 'text' => $text] + self::keyboard($button, $url));
        $id = $answer['result']['message_id'] ?? null;

        return \is_int($id) ? $id : null;
    }

    /** `editMessageText`: whether Telegram took the rewrite. */
    public function edit(string $chat, int $message, string $text, ?string $button = null, ?string $url = null): bool
    {
        // The keyboard is always stated: an empty one is how a settled card loses its button.
        $markup = self::keyboard($button, $url) ?: ['reply_markup' => ['inline_keyboard' => []]];

        return $this->call('editMessageText', ['chat_id' => $chat, 'message_id' => $message, 'text' => $text] + $markup) !== null;
    }

    /**
     * @return array{reply_markup?: array{inline_keyboard: list<list<array{text: string, url: string}>>}}
     */
    private static function keyboard(?string $button, ?string $url): array
    {
        return $button === null || $url === null
            ? []
            : ['reply_markup' => ['inline_keyboard' => [[['text' => $button, 'url' => $url]]]]];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>|null Telegram's answer when it said `ok`, otherwise null
     */
    private function call(string $method, array $body): ?array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => (string) json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            'timeout' => $this->timeout,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents(rtrim($this->api, '/') . '/bot' . $this->token . '/' . $method, false, $context);
        $answer = \is_string($raw) ? json_decode($raw, true) : null;

        return \is_array($answer) && ($answer['ok'] ?? false) === true ? $answer : null;
    }
}
