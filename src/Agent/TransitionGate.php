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

use Milpa\AppRuntime\Support\Foundation;

/**
 * A lifecycle transition, enforced: the held tools wait until the declared STATE exists.
 *
 * ── WHAT SEPARATES THIS FROM PrerequisiteGate ───────────────────────────────────────────────────
 *
 * That gate adjudicates EXECUTION: it opens when the obliged tool ran, which is the right
 * contract for «plan before starting». This one adjudicates STATE: it opens when the condition
 * holds on durable state, re-derived on EVERY check, with no memory to trick. The difference is
 * the whole law (greenhouse evidence/0009): a transition is not earned by executing a rite; it
 * is earned by producing the state the rite was meant to demonstrate. A rite that ran and
 * produced nothing — aborted, wrote garbage — leaves the table exactly as closed as before.
 *
 * Statelessness is also what makes the rehydration case trivial: a resumed session, a new
 * process, a different day — same disk, same verdict. Nothing here to reconstruct.
 *
 * ── THE GATE IS THE MUSCLE, NOT THE LAW ─────────────────────────────────────────────────────────
 *
 * The transition DECLARES what must be true to cross (its condition, over durable state); this
 * class only refuses the held calls while it is not, saying what is missing and with which name —
 * the sentence is what makes the next call the correct one instead of a guess (Q-P19-Q). The
 * refusal is a fact with a teaching, never a question: there is nothing here a human must decide.
 */
final class TransitionGate
{
    /**
     * @param \Closure(string): ?string $whyNotYet why the arrow cannot be crossed yet —
     *                                             given the tool name, the teaching to
     *                                             return; `null` means the transition is
     *                                             EARNED and the table is open. Consulted
     *                                             on every check, never cached.
     * @param list<string>              $held      tool names this arrow holds closed;
     *                                             everything else is none of its business
     */
    public function __construct(
        private readonly \Closure $whyNotYet,
        private readonly array $held,
    ) {
    }

    /** Why this call does not proceed yet, or `null` if it does. */
    public function reasonToWait(string $tool): ?string
    {
        if (!\in_array($tool, $this->held, true)) {
            return null;
        }

        return ($this->whyNotYet)($tool);
    }

    /**
     * Whether this tool belongs on the OFFERED table right now (greenhouse decisions/0006).
     *
     * The frontier is presentation of the same law: what would be refused right now is not
     * offered right now — one judge for both, because two authorities over the same question
     * is the defect this house hunts. The measured doctrine behind removal over refusal:
     * an offered tool is a tool that will get called (Q-P19, the task-conditioned catalogue).
     */
    public function offers(string $tool): bool
    {
        return $this->reasonToWait($tool) === null;
    }

    /**
     * The arrow's current teaching with a neutral subject — for a spawned child's errand
     * (greenhouse decisions/0007: the child is born knowing).
     *
     * Derived from the same condition as the refusal and the frontier — one authority. `null`
     * when the transition is earned, or when this arrow holds nothing: then the errand travels
     * exactly as it always did.
     */
    public function teaching(): ?string
    {
        if ($this->held === []) {
            return null;
        }

        return ($this->whyNotYet)('building');
    }

    /**
     * The first arrow: UNFOUNDED → FOUNDED (greenhouse evidence/0009).
     *
     * Only a `founded` verdict opens the table. The other three verdicts refuse each with its own
     * teaching, because they mean different things and inviting the wrong next call is how an
     * agent burns its budget: `unfounded` teaches the rite; `invalid` teaches repair and NEVER
     * the rite (defective presence must not invite silent substitution); `indeterminate` teaches
     * hands-off (a foundation this code cannot adjudicate — possibly a newer schema — is not an
     * invitation to rewrite it).
     *
     * @param list<string> $held
     * @param null|string  $root test seam, same as {@see Foundation::verdict()}
     */
    public static function untilFounded(array $held, ?string $root = null): self
    {
        return new self(
            static function (string $tool) use ($root): ?string {
                $v = Foundation::verdict($root);

                return match ($v['verdict']) {
                    'founded' => null,
                    'unfounded' => "«{$tool}» does not proceed yet: this app is not founded, and "
                        . 'founding comes before building. Call `foundation` to see what founding '
                        . 'declares, then `foundation:found` with the domain the human named.',
                    'invalid' => "«{$tool}» does not proceed: .milpa/foundation.json exists and "
                        . "contradicts its contract ({$v['reason']}). Repair the document — "
                        . 're-founding over defective presence is refused.',
                    default => "«{$tool}» does not proceed: this app's foundation cannot be "
                        . "adjudicated ({$v['reason']}). Do not build on it and do not rewrite it.",
                };
            },
            $held,
        );
    }
}
