<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Operations;

use Milpa\Command\Effect\AuthorityPolicy;
use Milpa\Command\Effect\ContextFacts;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;

/** An operation whose dry-run invocation is read-only without changing its declared ceiling. */
final readonly class DryRunOperation extends Operation
{
    /**
     * Return a read-only ceiling for a dry-run call and delegate every other invocation.
     *
     * @param array<string, mixed> $arguments
     */
    public function ceilingForCall(array $arguments, ?AuthorityPolicy $policy = null, ?ContextFacts $facts = null): EffectProfile
    {
        if (($arguments['dry_run'] ?? false) === true) {
            return EffectProfile::readOnly();
        }

        return parent::ceilingForCall($arguments, $policy, $facts);
    }
}
