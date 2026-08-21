<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Command\Effect\TrialConfinement;

/**
 * The decision that ONE call goes to trial: the workspace it runs in and the confinement the gate
 * reads. Built once per call by {@see TrialRouter} and handed to both the gate and the executor, so
 * there is a single source for «this call is confined» (greenhouse decisions/0069).
 */
final readonly class TrialPlan
{
    public function __construct(
        public TrialWorkspace $workspace,
        public TrialConfinement $confinement,
    ) {
    }
}
