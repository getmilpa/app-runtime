<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app INSTALLS, not copies.
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
 * Remembers which concrete call each passkey challenge stands for (greenhouse decisions/0187, D-01).
 *
 * {@see IntentChallengeBinding} is the fact; this is where it lives between issuing a challenge and
 * admitting the assertion that answers it. `take` is single-use by contract — a returned binding is
 * removed — so a challenge authorises exactly one admission, the same shape the crypto challenge's
 * own consume already has.
 */
interface IntentChallengeStore
{
    /** Record that `$challenge` stands for `$binding`'s call. */
    public function bind(string $challenge, IntentChallengeBinding $binding): void;

    /** Return and REMOVE the binding for `$challenge`, or `null` when there is none. */
    public function take(string $challenge): ?IntentChallengeBinding;
}
