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
            static fn (string $t): bool => $t !== $tool,
        ));
    }

    /** Por qué esta llamada no procede todavía, o `null` si la mesa ya está abierta. */
    public function motivoParaEsperar(string $tool): ?string
    {
        if ($this->pendientes === [] || \in_array($tool, $this->pendientes, true)) {
            return null;
        }

        // SE DICE QUÉ FALTA Y CON QUÉ NOMBRE. Una negativa sin salida obliga a adivinar, y adivinar
        // fue lo que gastó doce llamadas en Q-P19-Q. El hecho es la negativa; la frase es para que la
        // siguiente llamada sea la correcta.
        return \count($this->pendientes) === 1
            ? "«{$tool}» todavía no procede: antes corre «{$this->pendientes[0]}», que es lo que se pidió primero."
            : "«{$tool}» todavía no procede: antes corren «" . implode('», «', $this->pendientes) . '», que es lo que se pidió primero.';
    }

    /** @return list<string> lo que sigue faltando — para que la pantalla lo pueda decir sin adivinar */
    public function pendientes(): array
    {
        return $this->pendientes;
    }
}
