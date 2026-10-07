<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

/**
 * What one call of work did to the house, as the house saw it (greenhouse decisions/0588, rule 5).
 *
 * The handler's answer is in `run`; this is the house's own account beside it: for each path of the state, its
 * digest before and after — `null` for a path that was not there. Equal digests are «it did not change», whatever
 * the handler said.
 */
final readonly class WorkOutcome
{
    /**
     * @param list<array{path: string, before: ?string, after: ?string}> $state
     * @param ?string                                                    $preImage the id under `var/work/` that keeps what the state was, or null when none is kept
     */
    public function __construct(
        public TrialRun $run,
        public array $state,
        public bool $changed,
        public ?string $preImage,
    ) {
    }
}
