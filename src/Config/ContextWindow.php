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

namespace Milpa\AppRuntime\Config;

/**
 * The context window this run is governed by, WITH the two numbers that produced it.
 *
 * ── WHO WINS WHEN THE HUMAN AND THE PROVIDER DISAGREE ────────────────────────────────────────────
 *
 * Neither, always: **the smaller of the two wins** (greenhouse decisions/0233).
 *
 * Taking the declaration always would leave the defect exactly where it was — a figure nothing
 * verifies, discovered only when the provider rejects the prompt mid-run. Taking the provider
 * always would overwrite a deliberate decision: a human may declare LESS on purpose, to leave air.
 * And taking the LARGER is the one clearly wrong answer, because overrunning the real window is
 * what breaks. So the declaration stays intent, and the measurement is a ceiling that cannot be
 * exceeded — the same shape greenhouse evidence/0546 fixed for the per-result cap («it may only
 * TIGHTEN»), applied one level up.
 *
 * ── AND WHEN ONLY ONE OF THEM SPOKE ──────────────────────────────────────────────────────────────
 *
 * That one. When neither did, {@see $tokens} is `null` and the orchestrator behaves exactly as it
 * did before this slice existed — it gives up on the derived budget and falls back to its fixed
 * cap. Nothing is invented to fill the hole.
 *
 * ── THE CLAIM THIS OBJECT REFUSES TO MAKE ────────────────────────────────────────────────────────
 *
 * «The house no longer depends on somebody declaring well.» It does: it depends on the provider
 * ANSWERING, and a provider can be down, answer something else, or expose only the window its model
 * was trained for. In every one of those cases the house keeps what was declared and SAYS it could
 * not ask ({@see couldNotAsk()}) — it never guesses, and it never goes quiet.
 */
final readonly class ContextWindow
{
    /**
     * @param null|int            $tokens   the window that governs, or `null` when there is none
     * @param ContextWindowSource $source   which of the two produced {@see $tokens}
     * @param null|int            $declared what the app declared, config first then environment
     * @param null|int            $measured what the provider said it had allocated
     * @param bool                $asked    whether there was a provider to ask at all
     */
    public function __construct(
        public ?int $tokens,
        public ContextWindowSource $source,
        public ?int $declared,
        public ?int $measured,
        public bool $asked,
    ) {
    }

    /**
     * Compose the two sources into the window that governs — the smaller one, and its provenance.
     *
     * `$asked` is not derived from `$measured`: «there was nobody to ask» and «I asked and got
     * nothing back» are different facts about the world, and only the first is a reason for the
     * caller to stay quiet about it.
     */
    public static function compose(?int $declared, ?int $measured, bool $asked): self
    {
        if ($declared === null && $measured === null) {
            return new self(null, ContextWindowSource::Undeclared, null, null, $asked);
        }
        if ($declared === null) {
            return new self($measured, ContextWindowSource::Measured, null, $measured, $asked);
        }
        if ($measured === null || $measured >= $declared) {
            // Equal counts as declared, not as tightened: agreeing with a declaration does not
            // clip it, and a surface that reported «tightened by the provider» on two identical
            // numbers would be sending a human to debug a change that never happened.
            return new self($declared, ContextWindowSource::Declared, $declared, $measured, $asked);
        }

        return new self($measured, ContextWindowSource::TightenedByTheProvider, $declared, $measured, $asked);
    }

    /**
     * True when there was a provider to ask and it produced no window.
     *
     * Down, non-JSON, silent on the field, or exposing only the window its model was TRAINED for —
     * they are one fact to the caller: the ceiling here is unverified, so whatever governs this run
     * is somebody's word. Said out loud rather than left to be inferred from a `null`.
     */
    public function couldNotAsk(): bool
    {
        return $this->asked && $this->measured === null;
    }
}
