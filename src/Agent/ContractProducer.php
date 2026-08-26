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

use Milpa\Command\Operation;

/**
 * An authorized producer of a tool's operational CONTRACT — what {@see SessionToolGate} judges by.
 *
 * ── WHY THE GATE NEEDS THIS ─────────────────────────────────────────────────────────────────────
 *
 * Some tools the agent can call never appear among the app's `Operations` the gate resolves against:
 * the session's own notebook ({@see SessionBookkeeping}) and delegation ({@see SubAgentSpawner})
 * reach the executor through the registry's `$extra`, not through `Operations::all()`. Under the old
 * gate they resolved to «no Operation», which used to mean ALLOW — so they ran WITHOUT ever asking
 * the consent their own contract declares. That is a dead policy (greenhouse decisions/0078): a
 * `requiresConfirmation` nobody enforced.
 *
 * They are not unknown, though — each is DECLARED, with an `EffectProfile` and a confirmation flag,
 * by the object that owns it. This seam is how the gate reaches that declaration: it asks each
 * recognized producer «what contract do you declare for this tool?» and judges the answer. A tool no
 * producer claims and no Operation backs is the only thing left genuinely unjudgeable — and the gate
 * denies that.
 *
 * The point is that the gate judges the CONTRACT resolved from a recognized producer, never the
 * tool's NAME. A name switch is a directory somebody has to maintain; a producer stating an effect is
 * a rule.
 */
interface ContractProducer
{
    /**
     * SUMMARY: The {@see Operation} whose `EffectProfile` and `requiresConfirmation` the gate should
     * judge this call by, or `null` when this producer declares no contract for `$tool`.
     */
    public function contractFor(string $tool): ?Operation;
}
