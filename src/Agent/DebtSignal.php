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

use Milpa\Agent\SessionStore;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;

/**
 * A structured debt observation the HOUSE emits at a site where it can PROVE the observation
 * (greenhouse decisions/0183 primitive #5, the ruling of 0184/0445).
 *
 * ── AN OBSERVATION, NEVER AN AUTHORITY ──────────────────────────────────────────────────────────
 *
 * A signal carries NO authority, changes NO behavior, blocks NOTHING. It exists so the Measuring
 * Stick receives EXECUTION data instead of post-hoc analysis: the tool that lived the fact writes
 * it down beside the fact, and whoever measures later reads a ledger instead of reconstructing one.
 * The model never emits signals; only the house does, at the sites that hold the proof in hand —
 * exactly the license greenhouse decisions/0184 did NOT give to a permission event, honored here by
 * emitting a plain observation instead.
 *
 * ── THE SAME STREAM THE SESSION WRITES, THE SAME TOLERANCE THAT ADMITS IT ───────────────────────
 *
 * The event type lives outside {@see \Milpa\Agent\SessionEvent} on purpose, precedent
 * {@see ClosureVerdict::EVENT}: the reducer skips types it does not know, so the session keeps
 * folding untouched while any projection may read the signals back by {@see self::EVENT}. It goes
 * through the raw store because {@see SessionStore} types its appends by its own enum.
 *
 * ── DEGRADES TO SILENCE ─────────────────────────────────────────────────────────────────────────
 *
 * An observation channel must never break the observed run. No reachable store means no signal —
 * never an error — and a store that fails mid-append is swallowed the same way: the run being
 * observed has priority over the observation of it.
 */
final class DebtSignal
{
    /**
     * The event type every debt signal is appended under, payload
     * `{"signal": "<kind>", "context": {...bounded fields}}`.
     */
    public const EVENT = 'session.debt_signaled';

    /**
     * The perm: ceremony was skipped because an exact confirmed intent claim admitted the call
     * (the named residue of greenhouse evidence/0445): the zero-authority skip becomes visible as
     * a SIGNAL, not as authority.
     */
    public const ADMITTED_INTENT_SKIP = 'admitted_intent_skip';

    /**
     * A perm: question was asked while an EXACT confirmed intent claim existed, because
     * {@see IntentAdmissibility} ruled the tier NEVER — deliberate policy COUNTED as institutional
     * friction (the plugins.register case of greenhouse evidence/0444), never prevented.
     */
    public const HIGH_TIER_DOUBLE_CEREMONY = 'high_tier_double_ceremony';

    /**
     * A consent denial landed while the session held a grant for the SAME operation whose argument
     * constraints did not cover the call — the measured TareasPlugin case of greenhouse
     * evidence/0444: a material naming decision silently changed the authority scope.
     */
    public const SCOPE_FRAGILITY = 'scope_fragility';

    /** Every context field is bounded: a signal names a fact, it never dumps one. */
    private const MAX_FIELD_CHARS = 256;

    /**
     * @param ?EventStoreInterface $events    the SAME captured event store the closure verdict
     *                                        records through, or `null` when no store is reachable —
     *                                        then every emission degrades to silence
     * @param string               $sessionId the session whose stream receives the signals
     */
    public function __construct(
        private readonly ?EventStoreInterface $events,
        private readonly string $sessionId,
    ) {
    }

    /**
     * Appends one debt signal to the session's own stream — or does nothing at all.
     *
     * Silence is deliberate twice over: with no reachable store there is nowhere to write, and a
     * store that throws mid-append must not turn an observation into a failure of the observed
     * run. Every context field is truncated to a bounded length; callers pass digests over raw
     * values, so nothing here should ever come close to the bound — it exists so a mistake
     * upstream stays a truncated field instead of a bloated stream.
     *
     * @param string                $signal  one of the kind constants of this class
     * @param array<string, string> $context bounded fields naming the observation
     */
    public function emit(string $signal, array $context): void
    {
        if ($this->events === null) {
            return;
        }

        $bounded = [];
        foreach ($context as $field => $value) {
            $bounded[$field] = mb_substr($value, 0, self::MAX_FIELD_CHARS);
        }

        try {
            $this->events->append(new Event(
                streamId: SessionStore::PREFIX . $this->sessionId,
                type: self::EVENT,
                payload: ['signal' => $signal, 'context' => $bounded],
                seq: $this->events->nextSeq(),
            ));
        } catch (\Throwable) {
            // An observation channel must never break the observed run: the signal is lost, the
            // run is not — and a lost signal is itself the kind of debt a later slice may count.
        }
    }
}
