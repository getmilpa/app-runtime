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
use Milpa\Agent\SessionFacts;
use Milpa\EventStore\Event;

/**
 * The closure the HOUSE derives for a session that kept no record of its own (greenhouse decisions/0487).
 *
 * ── THE DEBT THIS PAYS, MEASURED (greenhouse evidence/1017) ─────────────────────────────────────
 *
 * 20–21 of 24 resident sessions never open a todo. They promote their rehearsal and the house observes
 * the screen served in the house — a receipt the house itself produced — and still the closure verdict
 * said «no positive verification evidence recorded», so the epilogue (0477) never opened for them. The
 * session was asked to keep a record the house already holds.
 *
 * ── WHAT COUNTS, READ ONLY FROM THE STREAM ──────────────────────────────────────────────────────
 *
 * - A CHANGE to the house: a succeeded mutating call that was not kept in a rehearsal — a promotion, or
 *   any write whose receipt does not declare a trial environment. Failed calls and calls still awaiting
 *   confirmation change nothing.
 * - An OBSERVATION of the house: a succeeded call whose receipt declares the predicate `served` in the
 *   HOUSE environment. A trial's served receipt is the rehearsal speaking, not the house.
 *
 * Closure is derived when the last observation comes at or after the last change, and it is still fresh
 * ({@see SessionFacts::evidenceByPredicate()}: no later forget or failed re-declare). Nothing is read from
 * prose or from the goal; nothing re-runs.
 *
 * ── WHAT IT DOES NOT PROVE ──────────────────────────────────────────────────────────────────────
 *
 * That the observed screen is the one the goal asked for, that it satisfies criteria never declared, or
 * that every subject a change touched was observed: a promotion names paths, not screens. The scope says
 * so — `house_observation` — beside the verdict.
 */
final class HouseObservedClosure
{
    /**
     * @param list<Event> $stream the session's own stream, in order
     *
     * @return array{derived: bool, reason: ?string, observation: ?array{subject: string, seq: int}, lastChangeSeq: ?int}
     */
    public static function of(array $stream, SessionFacts $facts): array
    {
        $lastChange = null;
        $observation = null;
        foreach ($stream as $event) {
            if ($event->type !== SessionEvent::ToolCalled->value) {
                continue;
            }
            $payload = $event->payload;
            $result = json_decode(\is_string($payload['result'] ?? null) ? $payload['result'] : '', true);
            $result = \is_array($result) ? $result : [];
            if (($payload['ok'] ?? true) !== true || ($result['ok'] ?? true) === false) {
                continue;
            }
            $evidence = $result['evidence'] ?? (\is_array($result['output'] ?? null) ? ($result['output']['evidence'] ?? null) : null);
            $evidence = \is_array($evidence) ? $evidence : [];
            $environment = \is_array($evidence['environment'] ?? null) ? ($evidence['environment']['kind'] ?? null) : null;

            if (($payload['mutating'] ?? false) === true && ($payload['awaitingConfirmation'] ?? null) !== true
                && $environment !== 'trial') {
                $lastChange = $event->seq;
            }
            if (($evidence['predicate'] ?? null) === 'served' && $environment === 'house'
                && ($evidence['invalidates'] ?? false) !== true && \is_string($evidence['subject'] ?? null)) {
                $observation = ['subject' => $evidence['subject'], 'seq' => $event->seq];
            }
        }

        $reason = null;
        if ($observation === null) {
            $reason = 'nothing observed served in the house';
        } elseif ($lastChange !== null && $lastChange > $observation['seq']) {
            $reason = "the house changed at seq {$lastChange} after its last observation (seq {$observation['seq']})";
        } elseif (($facts->evidenceByPredicate('served', $observation['subject'])['evidence']['fresh'] ?? false) !== true) {
            $reason = "the house observation of «{$observation['subject']}» went stale";
        }

        return ['derived' => $reason === null, 'reason' => $reason, 'observation' => $observation, 'lastChangeSeq' => $lastChange];
    }
}
