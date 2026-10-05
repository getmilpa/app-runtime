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
 * The two things this surface asks of Telegram's Bot API: say a card, and rewrite it.
 *
 * Both go OUT. Nothing here receives an update: a card carries a link to the house, and the decision is taken
 * there with a passkey (greenhouse decisions/0572). Text is sent plain, with no parse mode, so nothing a card
 * names can become markup.
 */
interface BotApi
{
    /**
     * Send a message, with one URL button under it when both button arguments are given.
     *
     * @return int|null the message id Telegram answered, or null when it was not sent
     */
    public function send(string $chat, string $text, ?string $button = null, ?string $url = null): ?int;

    /** Replace a sent message's text and button; without button arguments the message keeps none. */
    public function edit(string $chat, int $message, string $text, ?string $button = null, ?string $url = null): bool;
}
