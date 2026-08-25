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
 * One declared unit of intent in a governed sequence: an operation and the arguments it carries
 * into the ONE governed door (GovernedExecutor). Domain-blind — it names an operation, never a
 * recipe or a consumer.
 */
final readonly class SequenceStep
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public string $operation,
        public array $arguments,
    ) {
    }
}
