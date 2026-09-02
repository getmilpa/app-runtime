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

use Milpa\Agent\ProgressReceipt;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\ProgressProbe;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;

/**
 * The {@see ProgressProbe} over the LIVE session stream (greenhouse decisions/0185): after each
 * step it replays the session's events since the last checkpoint and derives a
 * {@see ProgressReceipt} — the ONE authority on whether anything provable grew.
 *
 * ── THE WINDOW, AND WHEN IT SPEAKS ──────────────────────────────────────────────────────────────
 *
 * The probe stays silent until {@see self::STALL_AFTER_CALLS} consecutive model calls show zero
 * newEvidence + newArtifacts + closedTodos. «Consecutive» is enforced by the checkpoint, not by a
 * counter: any advancing window moves the checkpoint forward, so a productive call resets the
 * count by construction. When the probe DOES speak, it records the stall as an additive
 * `session.progress_stalled` fact on the session's own stream — through the same captured-store
 * seam {@see DebtSignal} uses, with the same doctrine: silence on a null or throwing store,
 * because an observation channel must never break the observed run — and then moves the
 * checkpoint, so speaking again requires a FRESH window of zero-growth calls instead of
 * re-flagging the same stale one every step.
 *
 * ── WHAT THE NOTICE SAYS ────────────────────────────────────────────────────────────────────────
 *
 * The notice words Rod's forced choice (decisions/0185) with the receipt's numbers and the exact
 * markers the orchestrator enforces: act, `HOUSE_DEBT:`, a human decision, or `ABANDON:`. Option
 * E — more prose about the work instead of the work — is the one the ruling deleted.
 */
final class SessionProgressProbe implements ProgressProbe
{
    /**
     * How many consecutive zero-growth model calls make a stall. Four, from the measured pattern:
     * the fifth run's philosophize phases ran well past it while a healthy build phase resets the
     * window every call or two.
     */
    public const STALL_AFTER_CALLS = 4;

    /**
     * The additive event a detected stall appends, payload `{"atStep": int, "receipt": {...}}`.
     * Outside {@see \Milpa\Agent\SessionEvent} on purpose, precedent {@see DebtSignal::EVENT}:
     * the tolerant reducer skips it, projections read it back by this constant.
     */
    public const EVENT = 'session.progress_stalled';

    /**
     * The last stream position already measured — `null` until a replay succeeds, so a store that
     * fails at construction degrades to a probe with no opinion instead of an exception.
     */
    private ?int $checkpointSeq = null;

    /**
     * @param ?EventStoreInterface $events    the SAME captured event store the session writes
     *                                        through, or `null` when none is reachable — then the
     *                                        probe cannot measure and never has an opinion
     * @param string               $sessionId the session whose stream is measured
     */
    public function __construct(
        private readonly ?EventStoreInterface $events,
        private readonly string $sessionId,
    ) {
        // Arm the checkpoint at the run's start: what happened in earlier turns was already
        // somebody's progress or somebody's stall — this run is measured from here.
        $this->checkpointSeq = $this->lastSeq();
    }

    /**
     * Measures the window since the checkpoint and speaks only on a proven stall.
     *
     * @return array{stalled: bool, notice: string, receipt: array<string, mixed>}|null
     */
    public function afterStep(int $step): ?array
    {
        $stream = $this->replayed();
        if ($stream === null) {
            return null;
        }

        if ($this->checkpointSeq === null) {
            // The store was unreadable at construction and answers now: arm here, measure from
            // the next step — never from a beginning this run does not own.
            $this->checkpointSeq = $this->seqOfLast($stream);

            return null;
        }

        $last = $this->seqOfLast($stream);
        $receipt = ProgressReceipt::of($stream, $this->checkpointSeq, $last);

        if ($receipt->progress === ProgressReceipt::ADVANCING) {
            // Growth moves the checkpoint: the count of zero-growth calls resets by construction.
            $this->checkpointSeq = $last;

            return null;
        }

        if ($receipt->calls < self::STALL_AFTER_CALLS) {
            return null;
        }

        $this->recordStall($step, $receipt);
        // Speaking consumes the window: flagging the SAME stale window on every later step would
        // turn one measured stall into a drumbeat, and the next verdict must be earned by four
        // fresh zero-growth calls.
        $this->checkpointSeq = $last;

        return [
            'stalled' => true,
            'notice' => $this->notice($receipt),
            'receipt' => $receipt->toArray(),
        ];
    }

    /** The forced choice, worded with the receipt's numbers and the exact markers enforced upstream. */
    private function notice(ProgressReceipt $receipt): string
    {
        return sprintf(
            'House progress check: your last %d model calls produced 0 new evidence, 0 materialized '
            . 'artifacts and 0 closed todos (%d succeeded read-only facts). More analysis is not on '
            . 'the table. Choose NOW, in this very answer: (A) call a tool that produces evidence or '
            . 'materializes an artifact; (B) if the blocker is framework-owned, reply starting '
            . 'exactly with "HOUSE_DEBT: <one-line digest of the gap>"; (C) if a genuine human '
            . 'decision is missing, ask it through the session; (D) to drop the current hypothesis '
            . 'and keep working, reply starting exactly with "ABANDON: <the hypothesis you drop>". '
            . 'An answer that is none of these ends this leg as stalled.',
            $receipt->calls,
            $receipt->newFacts,
        );
    }

    /**
     * Appends the stall as a fact of the session's own stream — or does nothing at all, the
     * {@see DebtSignal} doctrine verbatim: the run being observed has priority over observing it.
     */
    private function recordStall(int $step, ProgressReceipt $receipt): void
    {
        if ($this->events === null) {
            return;
        }

        try {
            $this->events->append(new Event(
                streamId: SessionStore::PREFIX . $this->sessionId,
                type: self::EVENT,
                payload: ['atStep' => $step, 'receipt' => $receipt->toArray()],
                seq: $this->events->nextSeq(),
            ));
        } catch (\Throwable) {
            // The recorded fact is lost; the notice and the run are not.
        }
    }

    /**
     * The session's replayed stream, or `null` when there is no store or it cannot answer.
     *
     * @return list<Event>|null
     */
    private function replayed(): ?array
    {
        if ($this->events === null) {
            return null;
        }

        try {
            return $this->events->replay(SessionStore::PREFIX . $this->sessionId);
        } catch (\Throwable) {
            return null;
        }
    }

    /** The checkpoint the constructor arms, or `null` when the store could not answer yet. */
    private function lastSeq(): ?int
    {
        $stream = $this->replayed();

        return $stream === null ? null : $this->seqOfLast($stream);
    }

    /** @param list<Event> $stream */
    private function seqOfLast(array $stream): int
    {
        $last = 0;
        foreach ($stream as $event) {
            $last = max($last, $event->seq);
        }

        return $last;
    }
}
