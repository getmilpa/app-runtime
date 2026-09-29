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

namespace Milpa\AppRuntime\Console;

/**
 * What a `coa chat` child hands to the clean one after it when its kernel went stale (greenhouse decisions/0519).
 *
 * The session it was on, what the person had typed and not sent, and whether that text HAD been sent — pressed on a
 * stale kernel, so held instead of run — and as what (an answer, or a counter-offer to the open question). One line
 * of JSON: the supervisor passes it on without reading it, except to tell the person what they would lose if the
 * next child never boots.
 */
final class ChatHandoff
{
    /**
     * @param string $session the session the chat was on
     * @param string $draft   what the person typed and did not send — or sent, when `$send`
     * @param bool   $send    whether `$draft` was already sent and held: the next child sends it once, on opening
     * @param bool   $counter whether what was held is a counter-offer to the open question
     */
    public function __construct(
        public readonly string $session,
        public readonly string $draft = '',
        public readonly bool $send = false,
        public readonly bool $counter = false,
    ) {
    }

    /** The handoff as the one line a child writes on descriptor 3. */
    public function encode(): string
    {
        return (string) json_encode(
            ['session' => $this->session, 'draft' => $this->draft, 'send' => $this->send, 'counter' => $this->counter],
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
        );
    }

    /** The handoff a child received — null when there was none, or it is not one a chat wrote. */
    public static function decode(?string $line): ?self
    {
        $data = $line !== null ? json_decode($line, true) : null;
        if (!\is_array($data) || !\is_string($data['session'] ?? null) || $data['session'] === '') {
            return null;
        }

        return new self(
            $data['session'],
            \is_string($data['draft'] ?? null) ? $data['draft'] : '',
            ($data['send'] ?? false) === true,
            ($data['counter'] ?? false) === true,
        );
    }

    /**
     * What the person loses if the next child never boots — said once, on the terminal, instead of vanishing.
     *
     * Null when there is nothing typed: a closed chat with an empty prompt loses nothing but the screen.
     */
    public static function lostOnClose(string $line): ?string
    {
        $handoff = self::decode($line);
        if ($handoff === null || $handoff->draft === '') {
            return null;
        }

        return ($handoff->send ? 'Not sent — it never ran: ' : 'What you were typing: ') . $handoff->draft;
    }
}
