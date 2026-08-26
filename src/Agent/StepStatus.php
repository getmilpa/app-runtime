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
 * A step's place in a governed sequence's run: it ran, it is the consent frontier a refusal stopped
 * at, it is the door the gate closed for good, it never got the chance to start, or it ran and
 * raised something other than a refusal.
 *
 * `Paused` and `Denied` are BOTH refusals from the governed door, and they are different facts
 * (greenhouse decisions/0079): a pause waits on a human who can answer it and is therefore
 * resumable; a deny — the gate's UNJUDGEABLE fail-closed (decisions/0078) — waits on nobody, because
 * no answer could ever make the call judgeable. Recording a deny as a pause persisted a session as
 * «waiting» on a human who cannot exist, and a resume ran nothing and paused again, forever.
 */
enum StepStatus: string
{
    case Executed = 'executed';
    case Paused = 'paused';
    case Denied = 'denied';
    case NotStarted = 'not_started';
    case Failed = 'failed';
}
