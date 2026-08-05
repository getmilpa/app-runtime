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

namespace Milpa\AppRuntime\Agent\Relay;

/**
 * One stretch of the race: who runs it, and which baton they receive.
 *
 * It carries no prompt of its own beyond an optional extra brief. What the specialist IS lives in
 * the role — which is where it can also carry the restrictions that govern. Letting a leg redefine
 * the specialist would be a second place to look for why a reviewer behaved like an implementer.
 */
final class RelayLeg
{
    public function __construct(
        public readonly string $name,
        public readonly string $role,
        /** The name of an earlier leg whose artifact this one receives, or `null` for the first. */
        public readonly ?string $takes = null,
        /** Extra instructions for this leg, appended after the role's own prompt. */
        public readonly ?string $brief = null,
    ) {
    }

    /**
     * The leg as data — including `takes`, which is what makes the chain readable.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'role' => $this->role,
            'takes' => $this->takes,
            'brief' => $this->brief,
        ];
    }
}
