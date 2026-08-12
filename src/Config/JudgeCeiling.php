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

namespace Milpa\AppRuntime\Config;

use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;

/**
 * The ceiling an operation carries when what it edits is the judge's own criterion.
 *
 * greenhouse decisions/0027 refused to add a sixth axis or a new Subject level for this, because
 * the house had already proved the rule that resolves it. evidence/0143 closed a rung showing a
 * child session inherits the STRICTEST mode of its whole ancestry, and GOV-05 already counts the
 * unclassified as the maximum of every dimension. Same shape, pointed upwards:
 *
 *   an operation that edits the judge's criterion carries the ceiling of the HEAVIEST thing that
 *   criterion can permit — a borrowed ceiling, not one of its own.
 *
 * A child does not exceed its parent; whoever edits the judge does not weigh less than what the
 * judge governs.
 *
 * Nothing here is invented: `EffectProfile::join()` is already the least-upper-bound and is
 * monotonic by construction, so folding it can only raise. That the primitive existed is what
 * answered falsifier F-J1 — the borrowed ceiling was derivable rather than merely sayable.
 */
final class JudgeCeiling
{
    /**
     * The config keys whose value is a criterion of the judge, and therefore borrow their ceiling.
     *
     * `agent.transitions.*` is the prerequisite list: greenhouse evidence/0144 measured that
     * changing it turns a PASS into a REFUSAL, which is a verdict and not a consequence.
     *
     * `agent.permissionWindow` is deliberately ABSENT. It moves when an unanswered question dies,
     * not whether a call is refused — lengthening it lets nothing through, and decisions/0027 left
     * it undecided rather than stretching a rule over a case it never measured.
     */
    private const CRITERIOS = ['agent.transitions'];

    /** Does editing this key change the criterion by which the judge decides? */
    public static function esCriterioDelJuez(string $llave): bool
    {
        foreach (self::CRITERIOS as $criterio) {
            if ($llave === $criterio || str_starts_with($llave, $criterio . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The heaviest thing this criterion can permit, folded over what it governs.
     *
     * An EMPTY catalogue returns `unclassified`, and that is the honest answer rather than a
     * convenient one: GOV-05 says what nobody classified carries the maximum of every dimension, so
     * a criterion governing nothing known is not free — it is unbounded.
     *
     * @param iterable<Operation> $gobernadas
     */
    public static function prestado(iterable $gobernadas): EffectProfile
    {
        $techo = null;

        foreach ($gobernadas as $operacion) {
            $suyo = $operacion->effectCeiling();
            $techo = $techo === null ? $suyo : $techo->join($suyo);
        }

        return $techo ?? EffectProfile::unclassified();
    }
}
