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

use Milpa\AppRuntime\Telegram\BotApi;

/** A Bot API that keeps what it was told, and refuses when told to. */
final class RecordingBot implements BotApi
{
    /** @var array<int, array{chat: string, text: string, button: ?string, url: ?string, edits: int}> */
    public array $messages = [];

    public bool $down = false;

    /** @var (\Closure(): void)|null what happens while Telegram is asked — a test's way to make it slow */
    public ?\Closure $onCall = null;

    public function send(string $chat, string $text, ?string $button = null, ?string $url = null): ?int
    {
        ($this->onCall ?? static fn () => null)();
        if ($this->down) {
            return null;
        }
        $id = \count($this->messages) + 1;
        $this->messages[$id] = ['chat' => $chat, 'text' => $text, 'button' => $button, 'url' => $url, 'edits' => 0];

        return $id;
    }

    public function edit(string $chat, int $message, string $text, ?string $button = null, ?string $url = null): bool
    {
        if ($this->down || !isset($this->messages[$message])) {
            return false;
        }
        $this->messages[$message] = ['chat' => $chat, 'text' => $text, 'button' => $button, 'url' => $url, 'edits' => $this->messages[$message]['edits'] + 1];

        return true;
    }

    /** The token at the end of a card's link. */
    public function token(int $message): string
    {
        return basename((string) $this->messages[$message]['url']);
    }
}
