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

use Milpa\Agent\SessionStore;
use Milpa\Agent\TodoStatus;
use Milpa\AiGateway\PlanBoard;

/**
 * El plan de una sesión, leído del stream en cada paso para volver a ponerlo delante del agente.
 *
 * ── ATADO A LA SESIÓN, NO PARAMETRIZADO POR ELLA ────────────────────────────────────────────────
 *
 * El identificador se captura al construirlo, por la misma razón que en {@see SessionBookkeeping}: un
 * plan que se pide por parámetro es un plan que se puede pedir mal, y mostrarle al agente el plan de
 * otra sesión sería peor que no mostrarle ninguno.
 *
 * ── LEE CADA VEZ, A PROPÓSITO ───────────────────────────────────────────────────────────────────
 *
 * Sin caché. El contrato de {@see PlanBoard} lo pide y no es celo: memorizar el plan aquí reproduciría
 * el defecto que esto arregla —un estado que se leyó una vez y se quedó viejo— sólo que un nivel más
 * abajo, donde la prueba de arriba ya no lo ve. Una sesión típica del laboratorio son ocho pasos;
 * ocho lecturas de un stream local no son el costo de nada.
 *
 * ── QUÉ SE LE MUESTRA, Y QUÉ NO ─────────────────────────────────────────────────────────────────
 *
 * El plan en prosa (`setPlan`) y las tarjetas con su estado. **No** se le muestra el `origin` ni el
 * `version`: los dos son observaciones que el sistema deriva PARA EL HUMANO, y ponérselas enfrente al
 * agente lo invitaría a razonar sobre cómo se ve su propio registro en vez de sobre el trabajo. La
 * medición de Q-P17-J/K es clara en que todo lo que se le agrega le cuesta; aquí se le agrega lo
 * mínimo que contesta «¿en qué iba?».
 */
final readonly class SessionPlanBoard implements PlanBoard
{
    public function __construct(
        private SessionStore $sessions,
        private string $sessionId,
    ) {
    }

    /** El plan vigente de la sesión, releído — no la foto de cuando empezó la vuelta. */
    public function current(): ?string
    {
        $sesion = $this->sessions->load($this->sessionId);
        if ($sesion === null) {
            return null;
        }

        // NO HAY PLAN TODAVÍA ES `null`, NO UN TABLERO VACÍO. Un encabezado con cero tarjetas ocupa
        // contexto para decir nada, y peor: le sugiere al modelo que el tablero es el tema. Antes de
        // que escriba su primer pendiente, aquí no hay nada que reproyectar.
        if ($sesion->todos === [] && ($sesion->plan === null || trim($sesion->plan) === '')) {
            return null;
        }

        $lineas = ['## Tu plan de esta sesión — estado vigente'];

        if ($sesion->plan !== null && trim($sesion->plan) !== '') {
            $lineas[] = '';
            $lineas[] = trim($sesion->plan);
        }

        if ($sesion->todos !== []) {
            $lineas[] = '';
            foreach ($sesion->todos as $todo) {
                $lineas[] = \sprintf('- [%s] %s · %s', $todo->id, $this->marca($todo->status), $todo->text);
            }
        }

        return implode("\n", $lineas);
    }

    /**
     * El estado en palabras y no en símbolos.
     *
     * `[x]` y `[ ]` colapsan cuatro estados en dos, y `blocked` y `pending` se ven igual desde afuera
     * siendo cosas distintas —uno espera su turno, el otro espera algo que la sesión no controla—.
     * Ésa es justo la confusión que {@see TodoStatus} existe para no permitir, y perderla en el
     * renglón que ve el agente sería perderla donde más cuesta.
     */
    private function marca(TodoStatus $estado): string
    {
        return match ($estado) {
            TodoStatus::Pending => 'pendiente',
            TodoStatus::InProgress => 'en curso',
            TodoStatus::Done => 'hecho',
            TodoStatus::Blocked => 'bloqueado',
        };
    }
}
