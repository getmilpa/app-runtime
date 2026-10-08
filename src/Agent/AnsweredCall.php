<?php

/**
 * The call a person said yes to, and when the house runs it itself.
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionStore;
use Milpa\Console\McpProjector;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;

/**
 * After a person says yes to a question the house asked about a call, the seat's next leg opens with THAT call
 * (greenhouse decisions/0600).
 *
 * The house asks a person before a call whose target the request does not name, and, where the mode asks, before
 * any call that changes something. The question carries the operation and its arguments, structured
 * (decisions/0031), and the yes covers exactly those (decisions/0226). After the yes nothing took the call up: the
 * model was left to type it again. The record of the lab's houses — of 375 yeses about a call, the model typed that
 * same call again, argument for argument, 275 times; 23 times it typed something the yes did not cover.
 *
 * A grant names a scope, and so {@see GrantedCall} resumes only what does not land by itself. A YES NAMES A CALL —
 * the person had its arguments in view — so what is taken up here is that call WHATEVER IT IS, whether it lands or
 * not. It is narrow in everything else:
 *
 *  - the call of the question the answer answers: its operation and its arguments, as the house wrote them when it
 *    asked — never anything a model said;
 *  - a yes, from a principal the house verified. A «no», an answer that is neither, a question that carries no
 *    call, and what asks for a signature — which is not answered, but signed — take nothing up;
 *  - once, as the first move of the first leg after the answer: any model call, any tool call, another question or
 *    a resume already played, and the answer takes nothing up;
 *  - while the answer is fresh ({@see FRESH_SECONDS}).
 *
 * This class decides from the session's stream alone. Who runs the leg is the leg's to check
 * ({@see \Milpa\AppRuntime\Operations\AgentOperations}); the call then re-enters the same door as any call the model
 * makes, judged from scratch — the recorded yes is what admits it there, and whatever else that door asks, it asks.
 */
final class AnsweredCall
{
    /** How long after the answer the house still plays the call itself; later, the model decides. */
    public const FRESH_SECONDS = 3600;

    /** What the house never does again once any of these follows the answer: its turn to play has passed. */
    private const MOVED_ON = ['session.model_called', 'session.tool_called', 'session.question_asked', GrantedCall::RESUMED];

    /**
     * The call this session's next leg opens with — or null.
     *
     * @param list<Event> $stream the session's own stream, in order
     *
     * @return array{question: string, asked: int, answered: int, tool: string, arguments: array<string, mixed>}|null
     */
    public static function toResume(array $stream, \DateTimeImmutable $now): ?array
    {
        $answer = null;
        $movedOn = false;
        foreach ($stream as $event) {
            if ($event->type === 'session.question_answered') {
                $answer = $event;
                $movedOn = false;
            } elseif (\in_array($event->type, self::MOVED_ON, true)) {
                // Something happened after that answer: whatever it was, the house's turn to play it has passed.
                $movedOn = true;
            }
        }
        $id = $answer?->payload['id'] ?? null;
        if ($answer === null || $movedOn || !\is_string($id) || !AffirmativeAnswer::is((string) ($answer->payload['answer'] ?? ''))
            || ($answer->payload['by']['verified'] ?? null) !== true) {
            return null;
        }
        $age = $answer->recordedAt === null ? null : $now->getTimestamp() - $answer->recordedAt->getTimestamp();
        if ($age === null || $age < 0 || $age > self::FRESH_SECONDS) {
            return null;
        }

        $question = null;
        foreach ($stream as $event) {
            // No question follows the answer here: one asked since would have been the session moving on.
            if ($event->type === 'session.question_asked' && ($event->payload['id'] ?? null) === $id) {
                $question = $event;
            }
        }
        if ($question === null || ($question->payload['reason'] ?? null) === 'signature' || str_starts_with($id, 'sign:')) {
            return null;
        }
        $why = json_decode(\is_string($question->payload['why'] ?? null) ? $question->payload['why'] : '', true);
        $operation = \is_array($why) ? ($why['operation'] ?? null) : null;
        $arguments = \is_array($why) ? ($why['arguments'] ?? null) : null;
        if (!\is_string($operation) || $operation === '' || !\is_array($arguments)) {
            return null;
        }

        return ['question' => $id, 'asked' => $question->seq, 'answered' => $answer->seq, 'tool' => McpProjector::toolName($operation), 'arguments' => $arguments];
    }

    /**
     * The call as the model would have issued it: one tool call, and no words.
     *
     * @param array{question: string, asked: int, answered: int, tool: string, arguments: array<string, mixed>} $call
     *
     * @return array{role: string, content: string, tool_calls: list<array{id: string, type: string, function: array{name: string, arguments: string}}>}
     */
    public static function move(array $call): array
    {
        return ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
            'id' => 'answered-' . $call['answered'],
            'type' => 'function',
            'function' => [
                'name' => $call['tool'],
                'arguments' => json_encode($call['arguments'] === [] ? new \stdClass() : $call['arguments'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            ],
        ]]];
    }

    /**
     * Hand the leg's orchestrator the call as its opening move. False when the installed gateway cannot open with
     * one: the model then re-issues the call itself, as before.
     *
     * @param array{question: string, asked: int, answered: int, tool: string, arguments: array<string, mixed>} $call
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
     * Record that the house played the call, after which answer, and as whom — the same fact a resumed grant
     * leaves, so the ledger never reads this call as the model's.
     *
     * @param array{question: string, asked: int, answered: int, tool: string, arguments: array<string, mixed>} $call
     */
    public static function resumed(EventStoreInterface $events, string $session, array $call, string $as): void
    {
        $events->append(new Event(
            streamId: SessionStore::PREFIX . $session,
            type: GrantedCall::RESUMED,
            payload: [
                'question' => $call['question'],
                'asked' => $call['asked'],
                'answered' => $call['answered'],
                'tool' => $call['tool'],
                'arguments_sha256' => ConsentBridge::digest($call['arguments']),
                'as' => $as,
            ],
            seq: $events->nextSeq(),
        ));
    }
}
