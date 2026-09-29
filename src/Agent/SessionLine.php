<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionStore;
use Milpa\Command\InvocationContext;

/**
 * Who may decide on a session over a channel that promises identity (greenhouse decisions/0495, 0497).
 *
 * Deciding on a session — answering its question, discarding it, changing its mode or goal, sending it the next
 * turn — belongs to whoever opened it and, when a verified seat opened it, to the line that enrolled that seat
 * (decisions/0493, {@see SeatFrontier}). The terminal stays the honest unverified case it already is
 * (`cli:user@host`), and a session no verified principal opened keeps the rule it had — any attributable holder of
 * the operation's scope — because nobody is on record to answer for it.
 *
 * One judgment for every operation that touches a session by the name the caller sends: the panel mounts its own
 * doors to them (decisions/0495, 0497), and a door without this judgment would let a passkey another key enrolled,
 * holding the scope, decide the seat's session.
 */
final class SessionLine
{
    /**
     * The refusal owed to a caller who does not answer for this session — or null when they may decide it.
     *
     * @param string      $act  the past participle the refusal ends with: «nothing was <act>»
     * @param string|null $root the app's root, where the enrollment ledger lives; null when there is no kernel
     *
     * @return array{ok: false, error: string, hint: string}|null
     */
    public static function refusal(SessionStore $sessions, string $id, ?InvocationContext $ctx, string $act, ?string $root): ?array
    {
        if ($ctx === null || $ctx->channel === 'cli') {
            return null;
        }
        // WHO OPENED IT, AND NOTHING ELSE (greenhouse decisions/0517): the opening event, read without the session.
        // Reading the whole stream here was where a session too big to read died — before the leg could arm the
        // record of its own death (evidence/1045 §3).
        $opening = $sessions->opening($id);
        $by = \is_array($opening?->payload['by'] ?? null) ? $opening->payload['by'] : [];
        $opener = ($by['verified'] ?? false) === true && \is_string($by['id'] ?? null) ? $by['id'] : null;
        $actor = self::bare((string) $ctx->actor);
        if ($opener === null || self::bare($opener) === $actor) {
            return null;
        }
        if ($root !== null && SeatFrontier::forRoot($root, $sessions)->answersFor($actor, $id)) {
            return null;
        }

        return [
            'ok' => false,
            'error' => sprintf(
                'you do not answer for session «%s» — only the principal that opened it, or the line that enrolled its seat, may decide it; nothing was %s',
                $id,
                $act,
            ),
            'hint' => 'decide it with the passkey or key whose line enrolled the seat (greenhouse decisions/0493, 0495)',
        ];
    }

    /**
     * The principal without the projector's `actor:` prefix: the HTTP projector attributes as `actor:<id>`, while
     * the ledger and a seat's session name the bare principal (`passkey:<id>`, `key:<fp>`). Compared bare, or the
     * owner of the session reads as a stranger.
     */
    private static function bare(string $principal): string
    {
        return str_starts_with($principal, 'actor:') ? substr($principal, 6) : $principal;
    }
}
