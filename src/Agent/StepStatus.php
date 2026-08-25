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
 * A step's place in a governed sequence's run: it ran, it is the frontier a refusal stopped at,
 * it never got the chance to start, or it ran and raised something other than a refusal.
 */
enum StepStatus: string
{
    case Executed = 'executed';
    case Paused = 'paused';
    case NotStarted = 'not_started';
    case Failed = 'failed';
}
