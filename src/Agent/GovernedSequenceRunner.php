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
 * Runs a DECLARED, ordered list of Operations through the ONE governed door (GovernedExecutor,
 * i.e. ConsentBridge): each step gets the same gate, consent, EffectProfile, authority and
 * execution facts as an individual call. It compresses the intent, never the governance. It is
 * domain-blind and recipe-blind. Ordered, NOT atomic (greenhouse decisions/0074, H-SEQUENCE-1).
 *
 * A run that pauses at a consent frontier can be RESUMED (`resume()`) from a `SequenceCursor`:
 * the already-executed prefix is carried, never re-run, and the same fail-closed loop drives the
 * rest through the same executor — a grant that covers the pending step never authorizes a later
 * one (greenhouse decisions/0075, H-CONTINUITY-1).
 */
final class GovernedSequenceRunner
{
    /**
     * Run the declared steps in order through the governed executor, stopping fail-closed at the
     * first consent frontier (a thrown refusal OR a returned `requires_confirmation` sentinel) or
     * the first failure — later steps are never started. Ordered, not atomic: prior executed steps
     * stay recorded, nothing is rolled back.
     *
     * @param list<SequenceStep> $steps
     */
    public function run(array $steps, GovernedExecutor $executor): SequenceResult
    {
        return $this->drive($steps, 0, [], $executor);
    }

    /**
     * Resume a previously paused run from its cursor: the Executed prefix (`$cursor->done`) is
     * carried untouched — indices before `$cursor->nextIndex` are NEVER passed to the executor
     * again (greenhouse decisions/0075, property 1) — and the SAME fail-closed loop `run()` uses
     * drives `$steps` forward from there, through the SAME `GovernedExecutor`. A later step that
     * still needs consent pauses exactly as it would on a first pass: resuming the step the grant
     * covers never authorizes the ones after it (property 5 holds by this per-step fail-closed,
     * not by any new authority logic here).
     *
     * The declared step list is re-hashed and checked against the cursor's digest FIRST — a
     * mutated sequence is rejected before the executor is ever touched (property 4).
     *
     * @param list<SequenceStep> $steps the FULL declared step list, exactly as originally run
     *
     * @throws \InvalidArgumentException when `$steps` does not hash to `$cursor->digest`
     */
    public function resume(array $steps, SequenceCursor $cursor, GovernedExecutor $executor): SequenceResult
    {
        if (SequenceCursor::digestOf($steps) !== $cursor->digest) {
            throw new \InvalidArgumentException(
                'cannot resume: the declared step list no longer matches the paused cursor',
            );
        }

        return $this->drive($steps, $cursor->nextIndex, $cursor->done, $executor);
    }

    /**
     * The one fail-closed loop both `run()` and `resume()` ride: start at `$from`, treat
     * `$done` (indices before `$from`) as already-recorded outcomes, and drive every step from
     * `$from` onward through `$executor`, stopping at the first consent frontier (thrown OR
     * returned) or the first failure exactly as `run()` always has. Extracted so a resume can
     * never duplicate — and so drift from — this fail-closed logic (greenhouse decisions/0075).
     *
     * @param list<SequenceStep> $steps the FULL declared step list
     * @param list<StepOutcome>  $done  outcomes already recorded for indices before `$from`
     */
    private function drive(array $steps, int $from, array $done, GovernedExecutor $executor): SequenceResult
    {
        $outcomes = $done;
        $stopped = false;
        foreach ($steps as $index => $step) {
            if ($index < $from) {
                continue;
            }
            if ($stopped) {
                $outcomes[] = new StepOutcome($step, StepStatus::NotStarted);
                continue;
            }
            try {
                $result = $executor->callTool($step->operation, $step->arguments);
                if ($this->isConsentFrontier($result)) {
                    // FAIL-CLOSED at the OTHER frontier shape: `GovernedExecutor::callTool` did not
                    // throw, it RETURNED a requires_confirmation/confirm_token sentinel — the
                    // tool-runtime token gate (reachable in AutonomyMode Auto/Acknowledge, a
                    // confirming channel, or a non-Operation tool). Recording this as Executed and
                    // continuing is exactly the forbidden `A✓ B(needs consent)✓ C✓`: it silently
                    // downgrades a frontier to success and starts steps a human never cleared
                    // (greenhouse decisions/0074, falsifier #1). Treat it exactly like the thrown
                    // frontier below: paused, stopped, nothing after it starts.
                    /** @var array<string, mixed> $result */
                    $message = \is_string($result['message'] ?? null) ? $result['message'] : 'requires confirmation';
                    $outcomes[] = new StepOutcome($step, StepStatus::Paused, $result, $message);
                    $stopped = true;
                    continue;
                }
                $outcomes[] = new StepOutcome($step, StepStatus::Executed, $result);
            } catch (\Milpa\AiGateway\ToolCallRefusedException $refused) {
                // FAIL-CLOSED at the exact frontier an individual call would stop at: the refused
                // step is paused, nothing after it is started (greenhouse decisions/0074, falsifier #1).
                //
                // THIS CATCH MUST STAY FIRST. ToolCallRefusedException extends \RuntimeException, so
                // the broad \Throwable catch below would swallow it as a plain failure if it came
                // first — collapsing a gate's deliberate pause into an error nobody asked to happen.
                $outcomes[] = new StepOutcome($step, StepStatus::Paused, null, $refused->getMessage());
                $stopped = true;
            } catch (\Throwable $failed) {
                // ORDERED, NOT ATOMIC (greenhouse decisions/0074): a step that raised anything other
                // than a refusal is a FAILURE, not a frontier — nobody is waiting on a human answer,
                // something just broke. The prior Executed steps stay recorded exactly as they ran;
                // there is no rollback. Domain-blind: any throwable stops the sequence the same way,
                // whichever operation raised it.
                $outcomes[] = new StepOutcome($step, StepStatus::Failed, null, $failed->getMessage());
                $stopped = true;
            }
        }

        return new SequenceResult($outcomes);
    }

    /**
     * The OTHER consent frontier: `GovernedExecutor::callTool` (ConsentBridge) does not always
     * signal it by throwing. When the session gate itself returns Allow — AutonomyMode Auto or
     * Acknowledge, a channel whose policy demands confirmation for mutating ops, or a registered
     * tool that is not an app Operation — the tool-runtime token gate answers with a plain,
     * non-throwing return: `['requires_confirmation' => true, 'confirm_token' => '...', ...]`
     * (see ConsentBridge::callTool, the branch that returns the pending result untouched when no
     * grant covers the call). A caller that only watches for the throw sees that return as an
     * ordinary success and keeps going.
     *
     * DOMAIN-BLIND ON PURPOSE: this looks at the SHAPE of the result, never at which operation
     * produced it. Any executor's return carrying this shape is a frontier, whatever it is named.
     */
    private function isConsentFrontier(mixed $result): bool
    {
        return \is_array($result) && ($result['requires_confirmation'] ?? false) === true;
    }
}
