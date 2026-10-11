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
 *
 * `stopped` is the one refusal that belongs to no grant: the book itself does not verify, so the house
 * cannot know which grant names what, and refuses the seat rather than guess that none does.
 */
final readonly class StandingDecision
{
    public function __construct(
        public StandingCall $call,
        public ?GrantRecord $record = null,
        public ?StandingVerdict $verdict = null,
        public ?string $stopped = null,
    ) {
    }

    /** Whether the house has a verdict of its own about this very call — whatever the seat holds on its own. */
    public function named(): bool
    {
        return $this->verdict !== null || $this->stopped !== null;
    }

    /** The sentence of a refusal, for whoever reads it — or `null` when this is not one. */
    public function refusal(): ?string
    {
        return $this->stopped ?? ($this->verdict !== null && !$this->verdict->passes() ? $this->verdict->sentence : null);
    }

    /** Whether a grant names this call and every one of its checks held. */
    public function passes(): bool
    {
        return $this->verdict?->passes() === true;
    }
}
