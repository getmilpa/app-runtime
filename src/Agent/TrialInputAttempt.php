<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

/** The host's identity for one execution, never supplied by tool output. */
final readonly class TrialInputAttempt
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public string $id,
        public string $root,
        public string $copy,
        public string $operation,
        public array $arguments,
    ) {
    }
}
