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
 */
final class GovernedSequenceRunner
{
    /** @param list<SequenceStep> $steps */
    public function run(array $steps, GovernedExecutor $executor): SequenceResult
    {
        $outcomes = [];
        $stopped = false;
        foreach ($steps as $step) {
            if ($stopped) {
                $outcomes[] = new StepOutcome($step, StepStatus::NotStarted);
                continue;
            }
            try {
                $result = $executor->callTool($step->operation, $step->arguments);
                $outcomes[] = new StepOutcome($step, StepStatus::Executed, $result);
            } catch (\Milpa\AiGateway\ToolCallRefusedException $refused) {
                // FAIL-CLOSED at the exact frontier an individual call would stop at: the refused
                // step is paused, nothing after it is started (greenhouse decisions/0074, falsifier #1).
                $outcomes[] = new StepOutcome($step, StepStatus::Paused, null, $refused->getMessage());
                $stopped = true;
            }
        }

        return new SequenceResult($outcomes);
    }
}
