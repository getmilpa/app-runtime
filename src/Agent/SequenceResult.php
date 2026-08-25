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

/**
 * The full record of a governed sequence's run: every declared step's outcome, in order. A caller
 * asks it three things — did every step execute, how many did, and where (if anywhere) the run
 * stopped — instead of re-deriving them from the raw outcome list each time.
 */
final readonly class SequenceResult
{
    /** @param list<StepOutcome> $outcomes */
    public function __construct(public array $outcomes)
    {
    }

    /** True only when EVERY step executed — one paused, failed or not-started step makes the sequence incomplete. */
    public function completed(): bool
    {
        foreach ($this->outcomes as $o) {
            if ($o->status !== StepStatus::Executed) {
                return false;
            }
        }

        return true;
    }

    /** How many steps actually ran through the governed pipeline. */
    public function executedCount(): int
    {
        return count(array_filter($this->outcomes, static fn (StepOutcome $o): bool => $o->status === StepStatus::Executed));
    }

    /** The first step that did NOT execute — where the sequence stopped — or null when every step executed. */
    public function frontier(): ?StepOutcome
    {
        foreach ($this->outcomes as $o) {
            if ($o->status !== StepStatus::Executed) {
                return $o;
            }
        }

        return null;
    }
}
