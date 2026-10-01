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

use Milpa\Command\Consent\OperationId;

/**
 * Una obligación de ORDEN, ejecutada: hasta que la herramienta obligada corra, el resto no procede.
 *
 * ── DE DÓNDE SALE ───────────────────────────────────────────────────────────────────────────────
 *
 * De la única pregunta que la serie Q-P20 dejó abierta. `deny` cubre lo que se puede QUITAR y se
 * cumplió 8/8 ({@see settlement-q-p20h}); `must` entrega obligaciones que sólo se pueden PEDIR, y
 * pedir gobernó 0/8 ({@see settlement-q-p20g}) — la obligación llegó las ocho veces, se leyó las ocho
 * veces y no cambió nada. La doctrina de la casa, medida cinco veces: **el sistema hace; el prompt
 * sugiere.**
 *
 * La hipótesis que esta clase pone a prueba es que «planea antes de empezar» **no es una obligación
 * irreducible**: es una prohibición disfrazada — «no empieces sin plan» — y por lo tanto tiene la
 * misma traducción que ya funcionó. Lo que se cambia no es lo que el agente lee, es lo que puede
 * hacer.
 *
 * ── POR QUÉ SE NIEGA Y NO RETIRA ────────────────────────────────────────────────────────────────
 *
 * `deny` retira del catálogo porque la prohibición es permanente: la herramienta no vuelve. Aquí la
 * mesa se abre en cuanto la obligación se cumpla, y una mesa que cambia a media vuelta le enseñaría
 * al modelo un catálogo distinto en cada turno sin explicarle por qué. La negativa es igual de fáctica
 * —la llamada NO corre— y además dice qué falta, que es lo que permite cumplir en la siguiente
 * llamada en vez de atorarse.
 *
 * **El costo de esa diferencia es lo que la medición tiene que ver.** Retirar no gasta llamadas;
 * negarse sí puede gastar una —la que se intentó antes de plan— y la pregunta honesta no es si la
 * obligación se cumple (por construcción se cumple) sino **si el trabajo sale peor**: menos
 * completadas, más llamadas, más pausas. Si sale peor, la respuesta a la pregunta abierta es que esta
 * clase de obligación tampoco se puede gobernar y hay que dejar de prometerlo.
 *
 * ── LO QUE NO HACE ──────────────────────────────────────────────────────────────────────────────
 *
 * No juzga el CONTENIDO de lo que se cumplió. Que el plan escrito sea bueno, o siquiera pertinente,
 * está fuera de su alcance: verifica que la herramienta corrió, no que sirvió. Prometer más sería el
 * certificado sustituto que la revisión de P-0001 tardó siete generaciones en matar.
 */
final class PrerequisiteGate
{
    /** @var list<string> lo que todavía falta; se vacía y ya no vuelve a llenarse */
    private array $pendientes;

    /**
     * @param list<string> $primero herramientas que tienen que correr antes que cualquier otra. Vacío
     *                              es la compuerta abierta, que es el comportamiento de siempre
     */
    public function __construct(array $primero = [])
    {
        $this->pendientes = array_values(array_unique(array_filter(
            $primero,
            static fn (string $t): bool => trim($t) !== '',
        )));
    }

    /**
     * Lo que corrió, para que deje de faltar.
     *
     * SÓLO CUENTA SI SALIÓ BIEN. Un `plan` que falló no dejó plan, y abrir la mesa con eso sería
     * exactamente la sustitución que esta clase existe para no hacer: tratar el intento como el hecho.
     */
    public function anota(string $tool, bool $ok): void
    {
        if (!$ok) {
            return;
        }

        $this->pendientes = array_values(array_filter(
            $this->pendientes,
            static fn (string $t): bool => ! (new OperationId($t))->is($tool),
        ));
    }

    /**
     * Why this call does not proceed yet, or `null` once the table is open.
     *
     * THE SAME ACT HOWEVER IT WAS SPELLED (greenhouse decisions/0550). The obligation arrives as the operator
     * typed it — `recipe.plan`, `recipe:plan` — and the call arrives as the catalogue spells it, `recipe_plan`.
     * Comparing the strings refused the very tool the obligation names, so the session could never meet it:
     * measured on fresh cattle, `--first=recipe.plan` ended the leg on its first step, the right call refused.
     * Identity is {@see OperationId}'s, the atom every gate of this family already asks (decisions/0030).
     */
    public function motivoParaEsperar(string $tool): ?string
    {
        if ($this->pendientes === []) {
            return null;
        }
        foreach ($this->pendientes as $pendiente) {
            if ((new OperationId($pendiente))->is($tool)) {
                return null;
            }
        }

        // IT SAYS WHAT IS MISSING, AND BY THE NAME THE MODEL CALLS. A refusal with no way out forces a guess, and
        // guessing is what spent twelve calls in Q-P19-Q. The refusal is the fact; the sentence is so the next
        // call is the right one — so it names the tool as the catalogue spells it, never as the operator typed it.
        $faltan = array_map(static fn (string $t): string => (new OperationId($t))->forTool(), $this->pendientes);

        return \count($faltan) === 1
            ? "«{$tool}» does not proceed yet: «{$faltan[0]}» runs first, which is what was asked first."
            : "«{$tool}» does not proceed yet: «" . implode('», «', $faltan) . '» run first, which is what was asked first.';
    }

    /** @return list<string> lo que sigue faltando — para que la pantalla lo pueda decir sin adivinar */
    public function pendientes(): array
    {
        return $this->pendientes;
    }
}
