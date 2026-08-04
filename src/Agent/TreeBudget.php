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
 * El fondo de pasos que TODO el árbol comparte en una vuelta (§5.4, contrato «presupuesto» de P19.3).
 *
 * ── QUÉ ESTABA ABIERTO ──────────────────────────────────────────────────────────────────────────
 *
 * Cada hijo recibía el techo COMPLETO del padre. La profundidad es 1 por construcción —el hijo no
 * tiene `agent_spawn` en su catálogo— pero la ANCHURA no estaba acotada por nada: un padre con techo
 * de doce podía delegar diez veces y gastar ciento veinte. «Un árbol de agentes sin techo es un gasto
 * sin fondo», dice el rung que esto cierra, y hasta hoy era literal.
 *
 * ── UN FONDO, NO UN TECHO POR HIJO ──────────────────────────────────────────────────────────────
 *
 * Bajarle el techo a cada hijo sería una constante y no haría falta esta clase: acotaría al hijo y no
 * al árbol, que es lo que gasta. Lo que se acota es el TOTAL, y cada hijo lo descuenta al terminar.
 *
 * ── LO QUE PASA EN CERO ES EL DISEÑO ────────────────────────────────────────────────────────────
 *
 * Darle al siguiente hijo lo que quede, en silencio, produciría hijos que mueren a media tarea con un
 * «me quedé sin pasos» — el modo de fallo que [Q-P19-R] persiguió doce llamadas. Por eso **la negativa
 * llega ANTES de gastar la vuelta del modelo, y con el número adentro**: quien delega se entera de que
 * ya no puede delegar mientras todavía puede hacer algo con lo que le queda.
 *
 * El piso por hijo existe por lo mismo. Un sub-agente con un paso no alcanza ni a leer y contestar:
 * gastaría una llamada al proveedor para producir un reporte vacío, que es peor que negarse.
 *
 * ── LO QUE NO ACOTA, Y HAY QUE DECIRLO ──────────────────────────────────────────────────────────
 *
 * Una vuelta. Que el humano vuelva a preguntar abre un fondo nuevo, y así debe ser: el presupuesto es
 * del árbol, no de la relación. Y acota PASOS, que no son tokens: un paso con veinte mil tokens de
 * contexto y uno con doscientos cuestan igual aquí. Un techo de gasto real es otra cosa y no está
 * medida.
 */
final class TreeBudget
{
    private int $gastado = 0;

    /**
     * @param int $total         pasos que el árbol entero puede gastar en esta vuelta
     * @param int $minimoPorHijo por debajo de esto no se delega: un hijo que no alcanza a leer y
     *                           contestar gasta una llamada para producir nada
     */
    public function __construct(
        private readonly int $total,
        private readonly int $minimoPorHijo = 3,
    ) {
    }

    /** Cuántos pasos le quedan al árbol, sin bajar de cero. */
    public function restante(): int
    {
        return max(0, $this->total - $this->gastado);
    }

    /** El techo del siguiente hijo: el suyo, o lo que quede si es menos. */
    public function techoParaElSiguiente(int $techoDelHijo): int
    {
        return min($techoDelHijo, $this->restante());
    }

    /** Lo que un hijo gastó de verdad — se descuenta al terminar, no al empezar. */
    public function anota(int $pasos): void
    {
        if ($pasos > 0) {
            $this->gastado += $pasos;
        }
    }

    /**
     * Por qué esta delegación no procede, o `null` si sí.
     *
     * EL NÚMERO VA ADENTRO. «No hay presupuesto» deja a quien delega adivinando si le queda algo para
     * hacerlo por su cuenta; «quedan 2 pasos y un sub-agente necesita 3» le dice qué puede hacer con
     * lo que tiene, que es la diferencia entre una negativa y un callejón.
     */
    public function motivoParaNoDelegar(): ?string
    {
        $quedan = $this->restante();
        if ($quedan >= $this->minimoPorHijo) {
            return null;
        }

        return "el presupuesto de este árbol se agotó: quedan {$quedan} paso(s) de {$this->total} y un "
            . "sub-agente necesita al menos {$this->minimoPorHijo}. Termina con lo que tú puedes hacer "
            . 'y reporta lo que quedó fuera.';
    }
}
