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

use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;

/**
 * Whether a confirmed INTENT claim is admissible EVIDENCE for consent (greenhouse decisions/0184).
 *
 * ── THE TWO COINS, AND WHY THIS EXISTS ──────────────────────────────────────────────────────────
 *
 * Measured in a live run (greenhouse evidence/0444): the operator answered the INTENT question —
 * «¿Confirmas plugins.register sobre "TareasPlugin"?» → sí — and the CONSENT gate still refused the
 * very same call. Intent answers «is this what you wanted?»; consent answers «do you authorize this
 * effect?». They stay distinct coins, and the intent yes never mints a grant. What the ruling
 * decided is narrower and sharper:
 *
 *     «La intención describe qué quiere el humano. La policy decide qué autoridad compra haberlo
 *     dicho.»  — the policy decides what authority saying it buys (Rod, decisions/0184).
 *
 * The confirmed intent is a CLAIM — IntentConfirmed {operation, arguments, principal}, already
 * recorded structured in the session's decisions — and THIS table is the policy's ruling on whether
 * that claim is admissible evidence to satisfy the consent contract, judged from the operation's
 * EffectProfile:
 *
 *     reads / ephemeral effects / no egress            → SUFFICIENT
 *     Persistent + WriteAsUser, no egress              → EXACT_SCOPE (only the exact confirmed call)
 *     Privileged, sensitive egress, destructive-class  → NEVER — intent does not equal consent
 *
 * Every `Unknown` axis sits with the worst of its axis (GOV-05): not knowing where an effect
 * reaches is never a reason to ask less. And `null` — an operation that never declared a profile —
 * is the ceiling of every dimension, so it is NEVER admissible.
 *
 * ── WHY THIS IS NOT A SECOND JUDGE ──────────────────────────────────────────────────────────────
 *
 * It rules on the admissibility of EVIDENCE for a question the policy already asked. Both ceremony
 * sites consult it only AFTER `SessionPolicy::decide()` routed the call to AskPermission: it never
 * widens a decision, never answers Allow for anything the policy did not route there, and the
 * RequireSignature path never consults it — a signature names the concrete call with a key that
 * lives outside the session, and no session fact substitutes for it. Context does not legislate:
 * the claim appends no PermissionGranted to the stream; admissibility is judged where consent is
 * judged, at judgment time, derived from recorded facts.
 *
 * ── EXACTNESS, STRICT ON PURPOSE ────────────────────────────────────────────────────────────────
 *
 * The claim covers one act. Every confirmed argument must equal the call's, and the call must not
 * carry an argument the confirmation did not name — a start-strict rule: the argument arrays must
 * be EQUAL (same keys, same values, key-order-insensitive). config-set(debug=true) confirmed is not
 * config-set(debug=false) authorized: the value IS the scope. Loosening any of this is an acta, not
 * a refactor.
 */
final class IntentAdmissibility
{
    /**
     * The tier where the confirmed claim satisfies the consent contract by itself: the effect reads,
     * or dies with the run, and nothing leaves the perimeter.
     */
    public const SUFFICIENT = 'sufficient';

    /**
     * The tier where the claim satisfies consent only WITHIN the exact confirmed scope: a durable
     * write as the user, local to the perimeter.
     */
    public const EXACT_SCOPE = 'exact_scope';

    /**
     * The tier where intent never substitutes explicit consent: privileged authority, egress past
     * the perimeter, or a destructive-class change. The double ceremony of evidence/0444 is
     * DELIBERATE policy here, not a bug.
     */
    public const NEVER = 'never';

    /**
     * The admissibility tier of decisions/0184 for this effect ceiling — the table, encoded.
     *
     * `null` — an operation that never declared its effects — carries the ceiling of every
     * dimension (GOV-05) and is NEVER admissible; so is every profile whose relevant axis reads
     * `Unknown`, because unknown sits with the worst of its axis by construction.
     */
    public static function tier(?EffectProfile $ceiling): string
    {
        if ($ceiling === null) {
            return self::NEVER;
        }

        // The high tier bars substitution on ANY of its three markers.
        if ($ceiling->authority === Authority::Privileged || $ceiling->authority === Authority::Unknown) {
            return self::NEVER;
        }
        if ($ceiling->externality !== Externality::None) {
            return self::NEVER;
        }
        if ($ceiling->reversibility === Reversibility::Irreversible || $ceiling->reversibility === Reversibility::Unknown) {
            return self::NEVER;
        }

        return match ($ceiling->mutation) {
            Mutation::None, Mutation::Ephemeral => self::SUFFICIENT,
            Mutation::Persistent => self::EXACT_SCOPE,
            Mutation::Unknown => self::NEVER,
        };
    }

    /**
     * Does the confirmed claim admit THIS call under THIS ceiling?
     *
     * Both admissible tiers demand the strict start: the argument arrays must be equal — same keys,
     * same values, key-order-insensitive. The claim describes one act; a call that adds, drops or
     * changes an argument is another act, and another act is another question.
     *
     * @param array<string, mixed> $confirmed the arguments the human was shown and said yes to
     * @param array<string, mixed> $call      the arguments of the call being judged now
     */
    public static function admits(array $confirmed, array $call, ?EffectProfile $ceiling): bool
    {
        return self::tier($ceiling) !== self::NEVER && self::exact($confirmed, $call);
    }

    /**
     * The strict start alone, with no tier ruling: are these the SAME act's arguments?
     *
     * Same keys, same values, key-order-insensitive — the exactness both admissible tiers demand.
     * Public because the DebtSignal arc must recognise an EXACT confirmed claim on a tier where it
     * never admits (`high_tier_double_ceremony`, greenhouse decisions/0183): the OBSERVATION needs
     * the comparison without the judgment, and a second comparator elsewhere would be the drift
     * this class exists to prevent.
     *
     * @param array<string, mixed> $confirmed the arguments the human was shown and said yes to
     * @param array<string, mixed> $call      the arguments of the call being judged now
     */
    public static function exact(array $confirmed, array $call): bool
    {
        return self::canonical($confirmed) === self::canonical($call);
    }

    /**
     * The same arguments whatever order a caller happened to build them in.
     *
     * Key order is projection, not identity — the same doctrine `ConsentBridge::digest()` applies
     * to the execution record. List order is NOT normalized: the order of a list is meaning.
     *
     * @param array<array-key, mixed> $arguments
     *
     * @return array<array-key, mixed>
     */
    private static function canonical(array $arguments): array
    {
        ksort($arguments);

        return array_map(
            static fn (mixed $value): mixed => \is_array($value) ? self::canonical($value) : $value,
            $arguments,
        );
    }
}
