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
use Milpa\Agent\SessionFacts;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;

/**
 * Whether a final answer may claim completion — derived from what the session RECORDED, never from
 * the answer's prose.
 *
 * ── THE DEBT THIS PAYS, MEASURED (greenhouse evidence/0442) ─────────────────────────────────────
 *
 * A headless run ended with the answer «listo» while the scaffolded behavioral test was red and
 * todos were open: the agent declared done with state < verified, and nothing in the runtime named
 * the gap. The answer is the model's claim; the ledger is the system's. This verdict is the ledger
 * speaking beside the claim — it BLOCKS THE ASSERTION, not the write: the answer still returns,
 * but the envelope cannot present a completion the recorded facts do not back.
 *
 * ── DERIVED, NEVER RE-MEASURED ──────────────────────────────────────────────────────────────────
 *
 * Everything here reads facts already in the stream — unevidenced dones, open todos, the latest
 * producer-declared verification verdicts. No filesystem scan, no test run, no model call: the
 * verdict is deterministic for a given stream, so replaying the session reproduces it exactly.
 */
final class ClosureVerdict
{
    /**
     * The event type the verdict is appended under — outside {@see \Milpa\Agent\SessionEvent} on
     * purpose: the reducer ignores types it does not know (a stream may carry events from a newer
     * producer), so the projection is additive and an old reader keeps folding the session
     * untouched. Surfaces that want the verdict read it from the stream by this name.
     */
    public const EVENT = 'session.closure_derived';

    /** Reasons name facts, they do not dump them: past this many, the rest is counted. */
    private const MAX_REASONS = 16;

    /**
     * Derive the closure verdict for a session's final answer from its recorded facts.
     *
     * `verified` is true only when the ledger backs completion: every done carries verifiable
     * evidence, nothing is left open, and no artifact's latest verification verdict is red. Each
     * failing fact becomes one bounded reason naming it.
     *
     * @return array{verified: bool, reasons: list<string>}
     */
    public static function derive(Session $session, SessionFacts $facts): array
    {
        $reasons = [];

        foreach ($session->unverifiedDones() as $todo) {
            $reasons[] = "todo {$todo->id} done without evidence";
        }

        $open = \count($session->pendingTodos());
        if ($open > 0) {
            $reasons[] = $open === 1 ? '1 todo open' : "{$open} todos open";
        }

        $state = $facts->workState();
        $artifacts = \is_array($state['artifacts'] ?? null) ? $state['artifacts'] : [];
        foreach ($artifacts as $entry) {
            if (! \is_array($entry)) {
                continue;
            }
            $verification = $entry['verification'] ?? null;
            if (! \is_array($verification) || ($verification['verified'] ?? null) !== false) {
                continue;
            }
            $judge = \is_string($verification['operation'] ?? null) ? $verification['operation'] : '?';
            $artifact = \is_array($entry['artifact'] ?? null) && \is_string($entry['artifact']['value'] ?? null)
                ? $entry['artifact']['value']
                : '?';
            $reasons[] = "judge {$judge} recorded red for {$artifact}";
        }

        if (\count($reasons) > self::MAX_REASONS) {
            $overflow = \count($reasons) - (self::MAX_REASONS - 1);
            $reasons = \array_slice($reasons, 0, self::MAX_REASONS - 1);
            $reasons[] = "… and {$overflow} more recorded facts";
        }

        return ['verified' => $reasons === [], 'reasons' => $reasons];
    }

    /**
     * Append the verdict to the session's own stream, so surfaces can project it.
     *
     * One append per final answer — the caller's single natural-end site is the only one that
     * records. It goes through the raw store because {@see SessionStore} types its appends by its
     * own enum; the reducer skips what it does not know, so the session keeps folding unchanged
     * while any projection may read the verdict back by {@see self::EVENT}.
     *
     * @param array{verified: bool, reasons: list<string>} $closure
     */
    public static function record(EventStoreInterface $events, string $sessionId, array $closure): void
    {
        $events->append(new Event(
            streamId: SessionStore::PREFIX . $sessionId,
            type: self::EVENT,
            payload: $closure,
            seq: $events->nextSeq(),
        ));
    }
}
