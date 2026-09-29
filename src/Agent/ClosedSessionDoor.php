<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\Session;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;

/**
 * A session the house already verified does not pay the model to say so again (greenhouse decisions/0529).
 *
 * Measured (evidence/1050): after the blog was built and observed, nine legs of «continue» cost 422,073 tokens,
 * each ending on «the goal is met». Once the closure is recorded verified (decisions/0523), a leg that asks nothing
 * new is answered by the house, without a model call.
 *
 * «Nothing new was asked» is read from the stream, never from how the prompt sounds alone: since the last recorded
 * verdict, only what this class lists may have happened, and the leg itself must carry a pure continuation and no
 * other argument. It is an ALLOW list on purpose — anything it does not know reaches the model, so a mistake here
 * costs one call (what every leg cost before), never a hidden request.
 */
final class ClosedSessionDoor
{
    /**
     * The fact the door leaves in the session's stream — outside {@see \Milpa\Agent\SessionEvent} on purpose, like
     * {@see ClosureVerdict::EVENT}: the reducer skips it, so no turn the house wrote reaches the model's window.
     */
    public const EVENT = 'session.leg_answered_by_closure';

    /**
     * The prompts that ask for nothing but «go on», compared after trimming, lower-casing and dropping final
     * punctuation. `continue` is the word the house itself teaches (`agent "continue" --session=…`).
     */
    public const CONTINUATIONS = ['continue', 'go on', 'keep going', 'continúa', 'continua', 'sigue'];

    /** Arguments a leg may carry and still ask nothing new; `mode` only when it is the session's own. */
    private const QUIET_INPUT = ['prompt', 'session', 'steps', 'mode'];

    /**
     * What may follow the verdict without reopening it: the house keeping its window, the mode, who signs — and
     * this door's own fact. Human turns are judged by their words ({@see isContinuation()}); everything else,
     * including any type this list does not name, reopens.
     */
    private const QUIET_EVENTS = [
        'session.compacted',
        'session.window_composed',
        'session.mode_changed',
        'session.sequence_authorized',
        'session.authorization_cited',
        'session.authorization_released',
        'session.ownership_asserted',
        self::EVENT,
    ];

    /**
     * Whether a prompt asks for nothing but to go on — one of {@see CONTINUATIONS}, whole.
     */
    public static function isContinuation(string $prompt): bool
    {
        $said = mb_strtolower(trim($prompt));
        $said = trim((string) preg_replace('/[.!?…\s]+$/u', '', $said));
        $said = (string) preg_replace('/\s+/u', ' ', $said);

        return \in_array($said, self::CONTINUATIONS, true);
    }

    /**
     * Whether the leg itself carries nothing new: a pure continuation, and no argument but the session, a step
     * ceiling and a mode — judged before the session is read, so a leg that asks something never pays for the read.
     *
     * @param array<string, mixed> $input
     */
    public static function legAsksNothing(array $input): bool
    {
        return array_diff(array_keys($input), self::QUIET_INPUT) === []
            && \is_string($input['prompt'] ?? null) && self::isContinuation($input['prompt']);
    }

    /**
     * Whether the leg's mode, if it names one, is the session's own: changing it is something new.
     *
     * @param array<string, mixed> $input
     */
    public static function keepsTheMode(array $input, Session $session): bool
    {
        return ! \array_key_exists('mode', $input) || $input['mode'] === $session->mode->value;
    }

    /**
     * The seq of the last verdict the house recorded, when it is verified and nothing new followed it; `null`
     * otherwise — no verdict, a verdict that is not verified, or anything after it that asks.
     *
     * @param list<Event> $stream the session's stream
     */
    public static function standingVerdict(array $stream): ?int
    {
        $verdict = null;
        foreach ($stream as $i => $event) {
            if ($event->type === ClosureVerdict::EVENT) {
                $verdict = $i;
            }
        }
        if ($verdict === null || ($stream[$verdict]->payload['verified'] ?? null) !== true) {
            return null;
        }
        foreach (\array_slice($stream, $verdict + 1) as $event) {
            if (\in_array($event->type, self::QUIET_EVENTS, true)) {
                continue;
            }
            if ($event->type === 'session.turn' && ($event->payload['role'] ?? null) === 'user'
                && \is_string($event->payload['content'] ?? null) && self::isContinuation($event->payload['content'])
            ) {
                continue;
            }

            return null;
        }

        return $stream[$verdict]->seq;
    }

    /**
     * What the house answers in place of the model.
     *
     * @param array<string, mixed> $closure the verdict read now
     */
    public static function answer(int $closureSeq, array $closure): string
    {
        $observation = \is_array($closure['derivedFrom'] ?? null) ? ($closure['derivedFrom']['observation'] ?? null) : null;
        $observed = \is_array($observation) && \is_string($observation['subject'] ?? null) && \is_int($observation['seq'] ?? null)
            ? " It observed {$observation['subject']} served at seq {$observation['seq']}, after the last change."
            : '';

        return "The house already verified this session's work (closure recorded at seq {$closureSeq}).{$observed} "
            . 'Nothing new was asked since, so no model was called. '
            . 'To reopen it, ask for something new in your own words or set a new goal.';
    }

    /**
     * Leave the fact that the door answered this leg, and to what.
     */
    public static function record(EventStoreInterface $events, string $sessionId, string $prompt, int $closureSeq): void
    {
        $events->append(new Event(
            streamId: SessionStore::PREFIX . $sessionId,
            type: self::EVENT,
            payload: ['prompt' => $prompt, 'closureSeq' => $closureSeq],
            seq: $events->nextSeq(),
        ));
    }
}
