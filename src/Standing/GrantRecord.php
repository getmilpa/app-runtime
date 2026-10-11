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

use Milpa\Command\Standing\GrantState;
use Milpa\Command\Standing\StandingGrant;

/**
 * One standing grant as the book holds it right now: the document a person signed, and what was
 * appended about it since (greenhouse decisions/0615).
 *
 * Derived on every read and never stored: a kept «in force» would be a grade that outlives its
 * evidence. `fault` is why the grant's own signature does not hold at this moment — `null` when it was
 * re-verified and it holds.
 */
final readonly class GrantRecord
{
    /**
     * @param string                  $entry           the digest of the line that granted it
     * @param string                  $grantedAt       when it was granted
     * @param string                  $grantedBy       the key that signed it, as a principal
     * @param string|null             $because         for a state other than in force: what put it there, in words
     * @param string|null             $suspension      the digest of the suspension that stands, which a reinstatement must name
     * @param \DateTimeImmutable|null $watcherSignalAt the watcher's last sign of life since it was granted or last reinstated
     * @param array<string, string>   $operations      what each covered operation WAS when the grant was given: its
     *                                                 identity => the digest that pins it. An operation that is no
     *                                                 longer that one is not the operation a person granted
     * @param array<string, string>   $instruments     the same, for what the grant is MEASURED and WATCHED by: the
     *                                                 operation of each condition and of its falsifier
     */
    public function __construct(
        public StandingGrant $grant,
        public string $entry,
        public string $grantedAt,
        public string $grantedBy,
        public ?string $fault = null,
        public GrantState $state = GrantState::InForce,
        public ?string $because = null,
        public ?string $suspension = null,
        public ?\DateTimeImmutable $watcherSignalAt = null,
        public array $operations = [],
        public array $instruments = [],
    ) {
    }

    /** The word a reader sees for where this grant stands, its own date and a broken signature included. */
    public function standsAs(\DateTimeImmutable $now): string
    {
        if ($this->fault !== null) {
            return 'not honoured';
        }
        if ($this->state === GrantState::InForce && !$this->inForceAt($now)) {
            return 'expired';
        }

        return str_replace('_', ' ', $this->state->value);
    }

    /** Whether the book has this grant in force at this moment: nothing took it back, and it is not past its own date. */
    public function inForceAt(\DateTimeImmutable $now): bool
    {
        return $this->state === GrantState::InForce && ($this->grant->expiresAt === null || $now < $this->grant->expiresAt);
    }
}
