<?php

/**
 * This file is part of Milpa App Runtime.
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
use Milpa\Console\McpProjector;

/**
 * Qué está de verdad en la mesa del agente, preguntado UNA vez.
 *
 * ── POR QUÉ EXISTE ─────────────────────────────────────────────────────────────────────────────
 *
 * Medido sobre ganado (greenhouse `evidence/0198`): retirar por clase de efecto declaraba TRECE
 * retiros mientras OCHO herramientas dejaban de viajar. Los cinco fantasmas nunca estuvieron en la
 * mesa —tres de ellos fuera a propósito—, y quien leyera la observación entendía que el agente había
 * perdido trece capacidades.
 *
 * La regla que decide ya existía, partida en dos: el opt-in de superficie del proyector, y una lista
 * de operaciones adjudicadoras. **Quien resolvía el retiro no llamaba a ninguna de las dos.** No era
 * un problema de orden: era la misma app decidiendo dos veces qué está en la mesa, y una de las dos
 * veces sin enterarse de la mitad de la regla.
 *
 * *La convención se llama, no se copia* (greenhouse `evidence/0141`).
 */
final class AgentTable
{
    /**
     * Las que ADJUDICAN, y no son herramientas de la sesión que espera.
     *
     * Contestar la pregunta que pausó una sesión, cambiar cuánta autonomía tiene y cerrarla no son
     * cosas que la sesión se haga a sí misma. `agent:discard` está desde que nació: cierra una
     * sesión, y un padre que pudiera cerrar a su hijo pausado haría desaparecer la pregunta que el
     * humano tenía que ver.
     *
     * NO ES UNA REGLA DE SUPERFICIE. Un cliente MCP con un humano detrás contesta legítimamente por
     * ahí; lo que no puede es que la sesión que espera se conteste sola.
     *
     * `agent:goal` joins them the day it is born (greenhouse decisions/0202): the goal is the
     * standing ask the gate compares targets against, and in the automatic mode it bounds what
     * runs without a question. A session that could widen its own standing ask would be naming its
     * own targets — consent narrated by the party it governs.
     *
     * `skill:invoke` too, for the same reason on the other flag: it is the HUMAN's door to a
     * user-invocable skill, including one marked `disable-model-invocation`. On the model's table it
     * would be the bar `skill:load` honours, handed back as a tool — the invoker comes from the
     * surface, never from an argument the model fills.
     */
    private const ADJUDICAN = ['agent:answer', 'agent:mode', 'agent:goal', 'agent:discard', 'skill:invoke'];

    /**
     * ¿Esta operación llega a la mesa del agente?
     *
     * Es la pregunta que hay que hacer antes de retirar algo: retirar lo que nunca estuvo apunta un
     * hecho que no ocurrió, y quien lea la bitácora creerá que el agente perdió más de lo que perdió.
     */
    public static function offers(Operation $operation): bool
    {
        if (\in_array($operation->name, self::ADJUDICAN, true)) {
            return false;
        }

        // EL OPT-IN DE SUPERFICIE SE LE PREGUNTA AL PROYECTOR, no se reimplementa aquí. `surfaces:
        // null` significa todas y una lista vacía significa ninguna — dos cosas opuestas que una
        // copia distraída confunde el primer día.
        return (new McpProjector())->supports($operation);
    }
}
