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

use Milpa\Agent\SessionEvent;
use Milpa\Command\Consent\OperationId;
use Milpa\EventStore\Event;

/**
 * The work a session did IN THE HOUSE, read from the receipts of what the house itself executed (greenhouse
 * decisions/0599).
 *
 * ── THE DEBT THIS PAYS, MEASURED (greenhouse evidence/1145 §6) ─────────────────────────────────
 *
 * Three stations of work did what was asked — every act ran in the house, confined, with the seat as principal,
 * and the store ended as expected — and the closure said `verified: false`: «nothing observed served in the
 * house». It looked for something served, and work serves nothing.
 *
 * ── THE HOUSE ALREADY LOOKED, WHEN IT EXECUTED ─────────────────────────────────────────────────
 *
 * Every act of work the domain accepted left `session.operation_executed`, a fact of the house's own that is never
 * read out of what a tool answered (decisions/0588): where it ran, confined, the digests of its state before and
 * after, whether it changed, and who ran it. Nothing is observed again here; those receipts are read.
 *
 * ── WHAT COUNTS ────────────────────────────────────────────────────────────────────────────────
 *
 * - WORK is a receipt whose environment is the house, of a verb of a capability built in the house, that CHANGED
 *   its state. One that left the house as it was did not reach it, whatever the verb answered. A read is not work.
 * - A PERSON'S ADMISSION BOUNDS IT (decisions/0590). A script of work names nothing of the house, so nothing the
 *   goal names can bound it: what bounds the work a seat may do is the admission. An act that ran for a principal
 *   no admission covers is said, and stops the closure. The house is ASKED ({@see of()}); a house that cannot be
 *   asked derives nothing.
 * - NOTHING LEFT HALFWAY. A call of a built verb that was accepted and has no receipt of the house — it ran in a
 *   trial, or only its answer says it ran — stops the closure (decisions/0587). So does a receipt of a trial, a call
 *   refused for lack of an admission that nobody admitted, and receipts that do not chain: when the state a receipt
 *   started from is not the state the one before left, somebody else changed it in between.
 * - AN ACT THE DOMAIN REFUSED DOES NOT STOP IT. Refusing well is working well. It is counted and said.
 *
 * ── WHAT IT DOES NOT PROVE ──────────────────────────────────────────────────────────────────────
 *
 * That what was done is what was asked: the request is in the words of the domain, and the house does not read
 * them. The work says so beside the verdict — `asked: "unjudged"`.
 */
final class HouseExecutedWork
{
    /** What the house says when a call of a built verb is refused because no admission covers it (decisions/0590). */
    private const UNADMITTED = ['no person has admitted', 'no longer covers it'];

    /**
     * Read the work of a session from its stream.
     *
     * @param list<Event>                      $stream   the session's own stream, in order
     * @param \Closure(string, ?string): ?bool $admitted whether a person's admission covers this operation for this
     *                                                   principal — true or false; null when the operation is no
     *                                                   verb of a capability built in the house. Asked with a null
     *                                                   principal, it only says whether it is such a verb
     *
     * @return array{derived: bool, reasons: list<string>, work: ?array{executed: int, refused_by_the_domain: int, state: list<array{path: string, after: ?string}>, asked: 'unjudged'}}
     */
    public static function of(array $stream, \Closure $admitted): array
    {
        $built = static fn (string $operation): bool => $admitted($operation, null) !== null;
        // seq => the call of a built verb the domain accepted, until the house's receipt of it arrives.
        $accepted = [];
        // seq => the tool of a call refused for lack of an admission, until a person admits it.
        $waiting = [];
        // path => [the digest the last receipt left, its seq].
        $state = [];
        $last = null;
        $executed = $refused = $acts = 0;
        $reasons = [];
        foreach ($stream as $event) {
            $payload = $event->payload;
            if ($event->type === GrantedCall::GRANTED) {
                unset($waiting[$payload['seq'] ?? null]);

                continue;
            }
            if ($event->type === SessionEvent::ToolCalled->value && \is_string($payload['tool'] ?? null) && $built($payload['tool'])) {
                $last = null;
                if (($payload['mutating'] ?? false) !== true) {
                    continue;
                }
                $result = json_decode(\is_string($payload['result'] ?? null) ? $payload['result'] : '', true);
                if (($payload['ok'] ?? true) !== true) {
                    $said = \is_string($payload['result'] ?? null) ? $payload['result'] : '';
                    if (\is_array($result) && ($result['ran_in_house'] ?? null) === true) {
                        // THE VERB RAN AND SAID NO: the house records a refusal of the domain as a call that failed, with
                        // what it left — nothing. It is an act, and it is not one that changed anything.
                        $acts++;
                        $refused++;
                    } elseif (!\is_array($result) && array_filter(self::UNADMITTED, static fn (string $phrase): bool => str_contains($said, $phrase)) !== []) {
                        $waiting[$event->seq] = $payload['tool'];
                    }

                    continue;
                }
                $acts++;
                $answer = \is_array($result) ? (\is_array($result['output'] ?? null) ? $result['output'] : $result) : [];
                if (($answer['ok'] ?? null) === false) {
                    $refused++;

                    continue;
                }
                $accepted[$event->seq] = $payload['tool'];
                $last = $event->seq;

                continue;
            }
            if ($event->type !== SessionEvent::OperationExecuted->value || !\is_string($payload['environment'] ?? null)
                || !\is_string($payload['operation'] ?? null) || !$built($payload['operation'])) {
                continue;
            }
            $operation = $payload['operation'];
            if ($last !== null && (new OperationId($operation))->is($accepted[$last])) {
                unset($accepted[$last]);
            }
            $last = null;
            if ($payload['environment'] !== 'house') {
                $reasons[] = "«{$operation}» ran in a {$payload['environment']}, not in the house (seq {$event->seq}): a rehearsal is not work";

                continue;
            }
            $principal = \is_array($payload['executed_by'] ?? null) && ($payload['executed_by']['verified'] ?? false) === true
                && \is_string($payload['executed_by']['principal'] ?? null) ? $payload['executed_by']['principal'] : null;
            if ($admitted($operation, $principal) !== true) {
                $reasons[] = "«{$operation}» ran in the house for «" . ($principal ?? 'nobody the house verified')
                    . "» outside any admission (seq {$event->seq}): only work a person admitted closes";

                continue;
            }
            if (($payload['changed'] ?? null) !== true) {
                continue;
            }
            $executed++;
            foreach (\is_array($payload['state'] ?? null) ? $payload['state'] : [] as $one) {
                if (!\is_array($one) || !\is_string($one['path'] ?? null)) {
                    continue;
                }
                $before = \is_string($one['before'] ?? null) ? $one['before'] : null;
                if (isset($state[$one['path']]) && $state[$one['path']]['after'] !== $before) {
                    $reasons[] = "the state at «{$one['path']}» was changed by someone else between the acts at seq {$state[$one['path']]['seq']} and seq {$event->seq}";
                }
                $state[$one['path']] = ['after' => \is_string($one['after'] ?? null) ? $one['after'] : null, 'seq' => $event->seq];
            }
        }
        foreach ($accepted as $seq => $tool) {
            $reasons[] = "«{$tool}» was accepted and the house has no receipt of having run it (seq {$seq}): only what the house executed is work";
        }
        foreach ($waiting as $seq => $tool) {
            $reasons[] = "a call of «{$tool}» was refused for lack of an admission, and nobody has admitted it (seq {$seq})";
        }
        if ($executed === 0 && $reasons === [] && $acts > 0 && $refused === $acts) {
            $reasons[] = "the work of this session changed nothing the house keeps: {$acts} act" . ($acts === 1 ? '' : 's') . ', all refused by the domain';
        }
        $left = [];
        foreach ($state as $path => $digest) {
            $left[] = ['path' => $path, 'after' => $digest['after']];
        }

        return [
            'derived' => $executed > 0 && $reasons === [],
            'reasons' => $reasons,
            'work' => $executed > 0 ? ['executed' => $executed, 'refused_by_the_domain' => $refused, 'state' => $left, 'asked' => 'unjudged'] : null,
        ];
    }

    /**
     * The house's reading of WHAT A PERSON ADMITTED, as {@see of()} asks it: whether an admission covers an operation
     * for a principal — and null when the operation is no verb of a capability built in the house. It is the judge the
     * gate itself asks (decisions/0590), so the closure and the gate cannot disagree on what was admitted. A principal
     * that is no seat of this house holds no admission: its work is bounded by nothing a person approved.
     *
     * @return \Closure(string, ?string): ?bool
     */
    public static function admittedBy(CapabilityAdmissions $admissions): \Closure
    {
        return static function (string $operation, ?string $principal) use ($admissions): ?bool {
            $verb = $admissions->verb($operation);
            if ($verb === null) {
                return null;
            }
            $seat = $admissions->seatOf($principal);

            return $seat !== null && $admissions->missingFor($seat, $verb) === null;
        };
    }

    /**
     * The calls of a stream that are WORK IN THE HOUSE: the seq of each tool call whose receipt says the house ran it.
     * Work changes the state a verb keeps, not what the house declares or serves (decisions/0599, question 3).
     *
     * @param list<Event> $stream
     *
     * @return array<int, true>
     */
    public static function calls(array $stream): array
    {
        $calls = [];
        $last = null;
        foreach ($stream as $event) {
            if ($event->type === SessionEvent::ToolCalled->value) {
                $last = \is_string($event->payload['tool'] ?? null) ? ['seq' => $event->seq, 'tool' => $event->payload['tool']] : null;
            } elseif ($event->type === SessionEvent::OperationExecuted->value && $last !== null
                && ($event->payload['environment'] ?? null) === 'house' && \is_string($event->payload['operation'] ?? null)
                && (new OperationId($event->payload['operation']))->is($last['tool'])) {
                $calls[$last['seq']] = true;
            }
        }

        return $calls;
    }
}
