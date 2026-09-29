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
 *   confirmation change nothing, and neither does a call that ran in a trial and was not applied
 *   (`ran_in_trial` without `applied`, greenhouse decisions/0494 §4): the rehearsal did not land.
 * - An OBSERVATION of the house: a succeeded call whose receipt declares the predicate `served` in the
 *   HOUSE environment — a screen observed ({@see \Milpa\AppRuntime\Web\ScreenOperations}), or a route
 *   the promotion that landed it observed through the house's own front controller (`observed`,
 *   {@see HouseRouteObserver}, decisions/0494). A trial's served receipt is the rehearsal speaking, not
 *   the house, and a result that ran in a trial observes nothing about the house.
 *
 * Closure is derived when the last observation comes at or after the last change, and it is still fresh:
 * for a screen, no later forget or failed re-declare ({@see SessionFacts::evidenceByPredicate()}); for a
 * route, no later observation of it that did not answer 200. And no route the house observed is left
 * answering a server error, nor a house that did not boot to be observed. Nothing is read from prose or
 * from the goal; nothing re-runs.
 *
 * ── WHAT IT DOES NOT PROVE ──────────────────────────────────────────────────────────────────────
 *
 * That the observed subject satisfies criteria never declared, or that every subject a change touched was
 * observed: a promotion names paths, not screens. The scope says so — `house_observation` — beside the verdict.
 *
 * ── ONLY WHAT THE GOAL NAMES CLOSES (greenhouse decisions/0522) ─────────────────────────────────
 *
 * Given the subjects the goal names ({@see StandingAsk::namesSubject()}), an observation of any OTHER subject is
 * not an observation of the work: it never closes, and the reason says what was seen instead. Measured
 * (evidence/1050): registering an empty plugin made the house observe `GET /` → 200, and that closed a session
 * whose goal was `GET /blog` — while `/blog` answered 404 and the resident itself said the goal was not met.
 * Every route the house answered still counts against it: a server error on an unnamed route is still an error.
 */
final class HouseObservedClosure
{
    /**
     * Derive whether the house observed itself serving after the last change that landed in it.
     *
     * @param list<Event>                   $stream the session's own stream, in order
     * @param (\Closure(string): bool)|null $named  whether the goal names an observed subject; null counts every subject
     *
     * @return array{derived: bool, reason: ?string, observation: ?array{subject: string, seq: int}, lastChangeSeq: ?int, landed: list<int>}
     */
    public static function of(array $stream, SessionFacts $facts, ?\Closure $named = null): array
    {
        $counts = $named ?? static fn (string $subject): bool => true;
        // The last observation of a subject the goal does not name: said in the reason, never counted.
        $unnamed = null;
        $lastChange = null;
        $landed = [];
        $observation = null;
        // route => [seq, status] of the last time the house answered it, and whether that was «served».
        $routes = [];
        $unobservable = null;
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
            $rehearsed = ($result['ran_in_trial'] ?? false) === true && ($result['applied'] ?? false) !== true;

            if (($payload['mutating'] ?? false) === true && ($payload['awaitingConfirmation'] ?? null) !== true
                && $environment !== 'trial' && !$rehearsed) {
                $lastChange = $event->seq;
                $landed[] = $event->seq;
            }
            if (($evidence['predicate'] ?? null) === 'served' && $environment === 'house'
                && ($evidence['invalidates'] ?? false) !== true && \is_string($evidence['subject'] ?? null)) {
                if ($counts($evidence['subject'])) {
                    $observation = ['subject' => $evidence['subject'], 'seq' => $event->seq];
                } else {
                    $unnamed = ['subject' => $evidence['subject'], 'seq' => $event->seq];
                }
            }
            if ($rehearsed) {
                continue;
            }
            if (\is_string($result['observation_error'] ?? null)) {
                $unobservable = ['seq' => $event->seq, 'error' => $result['observation_error']];
            } elseif (\is_array($result['observed'] ?? null)) {
                $unobservable = null;
            }
            $served = null;
            foreach (\is_array($result['observed'] ?? null) ? $result['observed'] : [] as $entry) {
                if (!\is_array($entry) || !\is_string($entry['subject'] ?? null)
                    || (\is_array($entry['environment'] ?? null) ? ($entry['environment']['kind'] ?? null) : null) !== 'house') {
                    continue;
                }
                $status = \is_int($entry['status'] ?? null) ? $entry['status'] : null;
                $isServed = ($entry['predicate'] ?? null) === 'served' && $status === 200;
                $routes[$entry['subject']] = ['seq' => $event->seq, 'status' => $status, 'served' => $isServed,
                    'everServed' => $isServed || ($routes[$entry['subject']]['everServed'] ?? false)];
                if ($isServed && ! $counts($entry['subject'])) {
                    $unnamed = ['subject' => $entry['subject'], 'seq' => $event->seq];
                } else {
                    $served ??= $isServed ? ['subject' => $entry['subject'], 'seq' => $event->seq] : null;
                }
            }
            $observation = $served ?? $observation;
        }

        // A route the house answered with a server error, or with nothing, is not served — and one it served
        // before and no longer answers 200 went stale, whatever else was observed since.
        $failing = [];
        $stale = [];
        foreach ($routes as $route => $answer) {
            if ($answer['status'] === null || $answer['status'] >= 500) {
                $failing[] = "the house answered «{$route}» with " . ($answer['status'] === null ? 'nothing' : "HTTP {$answer['status']}") . " at seq {$answer['seq']}";
            } elseif (! $answer['served'] && $answer['everServed']) {
                $stale[] = "the house observation of «{$route}» went stale: it answered HTTP {$answer['status']} at seq {$answer['seq']}";
            }
        }

        $reason = null;
        if ($unobservable !== null) {
            $reason = "the house could not be observed after the change at seq {$unobservable['seq']}: {$unobservable['error']}";
        } elseif ($failing !== []) {
            $reason = implode('; ', $failing);
        } elseif ($observation === null && $unnamed !== null) {
            // A route that went stale is the stronger fact; otherwise, say what was seen instead of the work.
            $reason = $stale !== [] ? implode('; ', $stale)
                : "the house observed «{$unnamed['subject']}» served (seq {$unnamed['seq']}), a subject the goal does not name";
        } elseif ($observation === null) {
            $reason = 'nothing observed served in the house';
        } elseif ($stale !== []) {
            $reason = implode('; ', $stale);
        } elseif ($lastChange !== null && $lastChange > $observation['seq']) {
            $reason = "the house changed at seq {$lastChange} after its last observation (seq {$observation['seq']})";
        } elseif (! isset($routes[$observation['subject']])
            && ($facts->evidenceByPredicate('served', $observation['subject'])['evidence']['fresh'] ?? false) !== true) {
            $reason = "the house observation of «{$observation['subject']}» went stale";
        }

        return ['derived' => $reason === null, 'reason' => $reason, 'observation' => $observation, 'lastChangeSeq' => $lastChange, 'landed' => $landed];
    }
}
