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
 * The one governed door a caller may drive to originate an operation: gate + consent +
 * EffectProfile + authority + the execution facts, exactly as an individual call gets them.
 * ConsentBridge is the shipped implementation; the sequence runner depends on THIS, never on
 * ConsentBridge concretely, so its logic is testable with a fake (greenhouse decisions/0074).
 */
interface GovernedExecutor
{
    /**
     * A consent frontier is signalled TWO ways, and a governed caller (the sequence runner) MUST
     * treat both as a stop:
     *
     *   1. THROWS `Milpa\AiGateway\ToolCallRefusedException` when the SESSION gate refuses or
     *      pauses the call — e.g. AutonomyMode::Ask with no grant yet
     *      (`SessionToolGate::refuse()`, checked before the call ever reaches the tool registry).
     *
     *   2. RETURNS, WITHOUT THROWING, a sentinel shaped
     *      `['requires_confirmation' => true, 'confirm_token' => '...', ...]` when the SESSION
     *      gate itself returned Allow but the TOOL-RUNTIME token gate is the frontier — reachable
     *      in AutonomyMode::Auto or Acknowledge (the session gate does not pause before mutation),
     *      on a channel whose policy demands confirmation for mutating ops (e.g. `telegram`'s
     *      `require_confirmation_for_mutating`), or for a registered tool that is not an app
     *      `Operation` at all. `ConsentBridge::callTool` returns this sentinel untouched whenever
     *      no `ConsentGrant` covers the pending call (see ConsentBridge.php: the branches after
     *      `parent::callTool()` that hand the pending result back rather than consuming a token).
     *
     * A caller that only watches for (1) sees (2) as an ordinary successful return and keeps
     * going — silently downgrading a frontier a human has not cleared into a success. This was the
     * root cause of the Critical finding in the whole-branch review of GovernedSequenceRunner
     * (greenhouse decisions/0074, falsifier #1): the runner must stop at the SAME consent frontier
     * an individual call would, regardless of which of the two shapes signals it.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws \Milpa\AiGateway\ToolCallRefusedException when the session gate refuses or pauses.
     */
    public function callTool(string $operation, array $arguments): mixed;
}
