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
     * @param array<string, mixed> $arguments
     *
     * @throws \Milpa\AiGateway\ToolCallRefusedException when the gate refuses.
     */
    public function callTool(string $operation, array $arguments): mixed;
}
