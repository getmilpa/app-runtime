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

namespace Milpa\AppRuntime\Standing;

use Milpa\Command\Standing\StandingCall;
use Milpa\Command\Standing\StandingVerdict;

/**
 * What the house answered about one call of a seat that some standing grant has to do with
 * (greenhouse decisions/0615 §3).
 *
 * `verdict` is `null` when a grant names the operation for this seat but none names THIS call — its
 * arguments are not the ones a grant fixes. That is not a refusal: the call goes on to be judged as it
 * was before any grant existed, with nothing lent for the asking.
 */
final readonly class StandingDecision
{
    public function __construct(
        public StandingCall $call,
        public ?GrantRecord $record = null,
        public ?StandingVerdict $verdict = null,
    ) {
    }

    /** Whether some grant names this very call — and so governs it, whatever the seat holds on its own. */
    public function named(): bool
    {
        return $this->verdict !== null;
    }

    /** Whether a grant names this call and every one of its checks held. */
    public function passes(): bool
    {
        return $this->verdict?->passes() === true;
    }
}
