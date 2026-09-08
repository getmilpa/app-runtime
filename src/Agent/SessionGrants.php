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

use Milpa\Agent\Principal;
use Milpa\Command\Consent\ConsentGrant;
use Milpa\Command\Consent\OperationId;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;

/**
 * The ConsentGrants a session already collected, derived from its decisions — for EVERY door.
 *
 * Consent is never stored as a grant: it is re-derived from the ledger each run, from the answers a
 * human gave (greenhouse decisions/0184, evidence/0209). This used to live inside `AgentOperations`,
 * and only the agent's door handed it to the tool-runtime gate. The sequence door handed `grants: []`
 * — so a step the human had just approved in the Desktop was refused on resume with «needs explicit
 * consent … none was presented», measured in the browser ceremony of decisions/0223 F4 (greenhouse
 * evidence/0561). One derivation, both doors.
 */
final class SessionGrants
{
    /**
     * The grants a session's decisions derive — every affirmative answer to a permission question, and every
     * confirmed intent claim the policy rules admissible — as the tool-runtime gate reads them: facts with
     * their exact arguments.
     *
     * ONLY DECISIONS THAT STORED THE STRUCTURED FACT COUNT. An old session carries `why` as the bare JSON
     * of the arguments without naming the operation, and from that nobody can reconstruct what the human
     * said yes to without reading the question's TEXT. That session asks again, and that is right: failing
     * upwards is the only failure this family can afford on this axis (greenhouse decisions/0029).
     *
     * @param list<array<string, mixed>> $decisions what the session already decided, each with its fact inside (`why`)
     * @param list<Operation>            $catalogue the operations whose DECLARED ceilings judge an intent claim
     *
     * @return list<ConsentGrant>
     */
    public static function of(array $decisions, ?string $sessionId, \DateTimeImmutable $ahora, array $catalogue): array
    {
        if ($decisions === []) {
            return [];
        }

        $grants = [];

        foreach ($decisions as $decision) {
            $reason = $decision['reason'] ?? null;
            if ($reason !== 'permission' && $reason !== 'target_not_named') {
                continue;
            }
            if (! AffirmativeAnswer::is((string) ($decision['answer'] ?? ''))) {
                continue;
            }
            $hecho = json_decode(\is_string($decision['why'] ?? null) ? $decision['why'] : '', true);
            if (! \is_array($hecho) || ! \is_string($hecho['operation'] ?? null)) {
                continue;
            }

            // A CONFIRMED INTENT IS A CLAIM, NOT A PERMISSION (greenhouse decisions/0184). It never
            // minted an event, so it is DERIVED per run like everything else here — but only for the
            // tiers the policy rules admissible, judged from the operation's declared ceiling. The
            // PolicyGate layer then honours the same semantics with its exact `covers()`.
            if ($reason === 'target_not_named') {
                $grant = self::grantFromIntentClaim($decision, $hecho, $ahora, $sessionId, $catalogue);
                if ($grant !== null) {
                    $grants[] = $grant;
                }

                continue;
            }

            // QUIÉN LO AUTORIZÓ SE LEE DEL REGISTRO, NO DEL ENTORNO.
            //
            // Esto se armaba con `getenv('USER')` y `gethostname()`, o sea con la identidad de quien
            // estuviera corriendo AHORA. Como el consentimiento no se guarda sino que se re-deriva
            // cada vez, el mismo sí grabado volvía a nombre de otra persona según quién retomara la
            // sesión: medido en ganado —rod contestó, impostor retomó, la operación corrió— y el
            // registro sólo nombraba a rod (greenhouse evidence/0209).
            //
            // Leer el `by` grabado NO lo asciende: llega `verified:false` y se queda `verified:false`.
            // Lo único que cambia es que la autoridad deja de pertenecerle al lector.
            //
            // Y donde no hay `by` —streams escritos antes de que la respuesta lo cargara— queda
            // `null`. Un registro con un hueco es peor de ver y más verdadero que uno rellenado con
            // quien pasaba por ahí, que es exactamente el defecto que esto viene a quitar.
            $concedio = ($decision['by'] ?? null) instanceof Principal ? $decision['by'] : null;

            $grants[] = new ConsentGrant(
                operation: new OperationId($hecho['operation']),
                principal: $concedio?->id,
                session: $sessionId,
                grantedAt: $ahora,
                // Cómo se ganó, para que ningún consumidor tenga que volver a ganarlo. Un sí
                // sembrado al lanzar conserva su procedencia: el auditor distingue el grant de
                // arranque del sí contestado a media sesión sin releer el stream.
                provenance: ($decision['executor'] ?? null) === LaunchGrants::EXECUTOR
                    ? LaunchGrants::EXECUTOR
                    : 'session.question_answered',
                arguments: \is_array($hecho['arguments'] ?? null) ? $hecho['arguments'] : [],
            );
        }

        return $grants;
    }

    /**
     * The ConsentGrant a confirmed intent claim derives, or `null` when the claim buys none.
     *
     * «La intención describe qué quiere el humano. La policy decide qué autoridad compra haberlo
     * dicho.» (Rod, greenhouse decisions/0184). The tier is judged at judgment time from the
     * operation's DECLARED ceiling — an operation the captured catalogue does not declare, or one
     * that never declared its effects, fails closed. A claim that names no arguments also derives
     * nothing: an argument-less ConsentGrant covers every call of its operation, and a claim may
     * never buy a blanket.
     *
     * @param array<string, mixed> $decision
     * @param array<string, mixed> $hecho
     * @param list<Operation>      $catalogue
     */
    private static function grantFromIntentClaim(array $decision, array $hecho, \DateTimeImmutable $ahora, ?string $sessionId, array $catalogue): ?ConsentGrant
    {
        $argumentos = \is_array($hecho['arguments'] ?? null) ? $hecho['arguments'] : null;
        if ($argumentos === null || $argumentos === []) {
            return null;
        }

        $operation = (string) $hecho['operation'];
        if (IntentAdmissibility::tier(self::declaredCeilingOf($operation, $catalogue)) === IntentAdmissibility::NEVER) {
            return null;
        }

        // WHO CONFIRMED IT, read from the record — never from the environment (evidence/0209).
        $concedio = ($decision['by'] ?? null) instanceof Principal ? $decision['by'] : null;

        return new ConsentGrant(
            operation: new OperationId($operation),
            principal: $concedio?->id,
            session: $sessionId,
            grantedAt: $ahora,
            provenance: 'intent-confirmed',
            arguments: $argumentos,
        );
    }

    /**
     * The declared EffectProfile of an operation in the captured catalogue — identity, not spelling.
     *
     * `null` both when the catalogue does not declare the operation and when the operation declared
     * no profile: the two are the same answer to the caller, «no ceiling to judge by», and the
     * admissibility table treats that as NEVER.
     *
     * @param list<Operation> $catalogue
     */
    private static function declaredCeilingOf(string $operation, array $catalogue): ?EffectProfile
    {
        $id = new OperationId($operation);
        foreach ($catalogue as $operacion) {
            if ($id->is($operacion->name)) {
                return $operacion->effects;
            }
        }

        return null;
    }

}
