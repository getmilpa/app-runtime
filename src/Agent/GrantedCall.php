<?php

/**
 * The recorded call a grant was given for, and when the house re-issues it itself.
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */
declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionStore;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;

/**
 * After a person grants the scope a recorded call was refused for, the seat's next leg opens with THAT call
 * (greenhouse decisions/0577).
 *
 * The house used to tell the model «Your call #44 … that same call can run now», and the model spent an inference
 * retyping arguments the house held byte for byte (evidence/1109, call 6). What is resumed is narrow on purpose:
 *
 *  - the call recorded at the seq the grant was given for, with the arguments recorded there — the grant's own fact
 *    carries their digest, and a call that does not match it is not that call;
 *  - a producer the authoring policy confines in a trial ({@see PluginAuthoringPolicy::BUILD}), never a promotion or
 *    anything else that lands: what the trial shows is read by the model, and landing it stays a judged act;
 *  - once, as the first move of the first leg after the grant: any model call, any other tool call, or a resume
 *    already played, and the grant resumes nothing;
 *  - while the grant is fresh ({@see FRESH_SECONDS}).
 *
 * This class decides from the session's stream alone. Who runs the leg and whether the house has trials are the
 * leg's to check ({@see \Milpa\AppRuntime\Operations\AgentOperations}); the call then re-enters the same door as
 * any call the model makes, judged from scratch.
 */
final class GrantedCall
{
    /** The fact a grant leaves in the seat's session: which recorded refusal it was given for. */
    public const GRANTED = 'session.refusal_granted';

    /** The fact the house leaves when it plays the call itself, so the ledger never reads it as the model's move. */
    public const RESUMED = 'session.call_resumed';

    /** How long after the grant the house still plays the call itself; later, the model is told and decides. */
    public const FRESH_SECONDS = 3600;

    /**
     * Record, when a grant is given, the refused call it was given for.
     *
     * Written by the grant, after it succeeded. A refusal that is not a recorded tool call with arguments leaves
     * nothing: there would be no call to name.
     *
     * @param array<string, string|int> $admitted what else the fact says. For the admission of a built verb
     *                                            (greenhouse decisions/0590): the capability and the digest of the
     *                                            contract a person approved. For a grant over existing work: what it
     *                                            opened ({@see OpenedWorks::fact()})
     */
    public static function granted(EventStoreInterface $events, string $session, Event $refused, string $permission, string $authorizedBy, array $admitted = []): void
    {
        $tool = $refused->payload['tool'] ?? null;
        $arguments = $refused->payload['arguments'] ?? null;
        if ($refused->type !== 'session.tool_called' || !\is_string($tool) || !\is_array($arguments)) {
            return;
        }
        $events->append(new Event(
            streamId: SessionStore::PREFIX . $session,
            type: self::GRANTED,
            payload: [
                'seq' => $refused->seq,
                'tool' => $tool,
                'permission' => $permission,
                'arguments_sha256' => ConsentBridge::digest($arguments),
                'authorized_by' => $authorizedBy,
                ...$admitted,
            ],
            seq: $events->nextSeq(),
        ));
    }

    /**
     * The recorded call this session's next leg opens with — or null.
     *
     * @param list<Event> $stream the session's own stream, in order
     *
     * @return array{seq: int, tool: string, arguments: array<string, mixed>, granted: int}|null
     */
    public static function toResume(array $stream, \DateTimeImmutable $now): ?array
    {
        $refused = null;
        foreach ($stream as $event) {
            if ($event->type === 'session.tool_called') {
                $refused = $event;
            }
        }
        $tool = $refused?->payload['tool'] ?? null;
        $arguments = $refused?->payload['arguments'] ?? null;
        if ($refused === null || ($refused->payload['ok'] ?? null) !== false
            || !\is_string($tool) || !\in_array($tool, PluginAuthoringPolicy::BUILD, true) || !\is_array($arguments)) {
            return null;
        }

        $grant = null;
        foreach ($stream as $event) {
            if ($event->seq <= $refused->seq) {
                continue;
            }
            if ($event->type === self::GRANTED && ($event->payload['seq'] ?? null) === $refused->seq) {
                $grant = $event;
            } elseif ($grant !== null && \in_array($event->type, ['session.model_called', self::RESUMED], true)) {
                // The leg after the grant already ran: whatever it did, the house's turn to play has passed.
                return null;
            }
        }
        if ($grant === null || ($grant->payload['tool'] ?? null) !== $tool
            || ($grant->payload['arguments_sha256'] ?? null) !== ConsentBridge::digest($arguments)) {
            return null;
        }
        $age = $grant->recordedAt === null ? null : $now->getTimestamp() - $grant->recordedAt->getTimestamp();
        if ($age === null || $age < 0 || $age > self::FRESH_SECONDS) {
            return null;
        }

        return ['seq' => $refused->seq, 'tool' => $tool, 'arguments' => $arguments, 'granted' => $grant->seq];
    }

    /**
     * The call as the model would have issued it: one tool call, and no words.
     *
     * @param array{seq: int, tool: string, arguments: array<string, mixed>, granted: int} $call
     *
     * @return array{role: string, content: string, tool_calls: list<array{id: string, type: string, function: array{name: string, arguments: string}}>}
     */
    public static function move(array $call): array
    {
        return ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
            'id' => 'resumed-' . $call['seq'],
            'type' => 'function',
            'function' => [
                'name' => $call['tool'],
                'arguments' => json_encode($call['arguments'] === [] ? new \stdClass() : $call['arguments'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            ],
        ]]];
    }

    /**
     * Hand the leg's orchestrator the call as its opening move. False when the installed gateway cannot open with
     * one: the model is then told, and re-issues the call itself, as before.
     *
     * @param array{seq: int, tool: string, arguments: array<string, mixed>, granted: int} $call
     */
    public static function open(object $orchestrator, array $call): bool
    {
        if (!method_exists($orchestrator, 'setOpeningMove')) {
            return false;
        }
        $orchestrator->setOpeningMove(self::move($call));

        return true;
    }

    /**
     * Record that the house played the call, and as whom.
     *
     * @param array{seq: int, tool: string, arguments: array<string, mixed>, granted: int} $call
     */
    public static function resumed(EventStoreInterface $events, string $session, array $call, string $as): void
    {
        $events->append(new Event(
            streamId: SessionStore::PREFIX . $session,
            type: self::RESUMED,
            payload: [
                'seq' => $call['seq'],
                'granted' => $call['granted'],
                'tool' => $call['tool'],
                'arguments_sha256' => ConsentBridge::digest($call['arguments']),
                'as' => $as,
            ],
            seq: $events->nextSeq(),
        ));
    }
}
