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
 * What happened to one declared step: the step itself, its final status, whatever the executor
 * returned when it ran, and — when it was refused — the reason a human would be shown.
 */
final readonly class StepOutcome
{
    public function __construct(
        public SequenceStep $step,
        public StepStatus $status,
        public mixed $result = null,
        public ?string $reason = null,
    ) {
    }
}
