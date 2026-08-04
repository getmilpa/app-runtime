<?php

/**
 * This file is part of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\AiGateway\OptionTable;

/**
 * Una mesa que APENDA el hecho y NO cambia la proyección. Existe para poder medir, y sólo para eso.
 *
 * ── QUÉ VARIABLE AÍSLA, Y POR QUÉ HIZO FALTA ────────────────────────────────────────────────────
 *
 * El cierre de Q-P19-H se quedó sin poder atribuir su propio resultado. El brazo que corrió cambiaba
 * DOS cosas a la vez —retirar la opción **y** dejar que la vuelta siguiera— porque sin lo segundo lo
 * primero no tiene efecto observable. Así que el 16/16 de observación podía deberse a cualquiera de
 * las dos, y decir «quitar es lo que redirige» habría sido afirmar lo que los datos no sostenían.
 *
 * Esto es el brazo que las separa:
 *
 *   · {@see remove()} apenda `session.option_removed` igual que {@see SessionOptionTable}. Eso hace que
 *     la negativa sea recuperable y **la vuelta siga**.
 *   · {@see removed()} devuelve SIEMPRE la lista vacía. El catálogo nunca pierde la herramienta, así
 *     que **la mesa no cambia**.
 *
 * Si con esto el agente vuelve a observar, lo que redirigía era que la vuelta continuara y la retirada
 * es adorno. Si se apaga como el brazo A, la retirada es la que hace el trabajo.
 *
 * ── POR QUÉ EL HECHO SÍ SE APENDA ───────────────────────────────────────────────────────────────
 *
 * Podría no apendarse y el efecto sobre el catálogo sería el mismo. Se apenda porque el stream es la
 * evidencia: sin el hecho no habría forma de contar en cuántas corridas el verificador llegó a negar,
 * y ese denominador es justo lo que le dio sentido al número del brazo F —9 de 16, no 16 de 16—.
 * Un brazo de control que además pierde evidencia no controla nada.
 *
 * ── ESTO NO ES UN MODO DE PRODUCCIÓN ────────────────────────────────────────────────────────────
 *
 * Una app que lo declarara tendría lo peor de las dos: apendaría que una opción se retiró y seguiría
 * ofreciéndola. Vive detrás de `agent.removeRefusedOptions: 'record-only'`, que es un valor de
 * laboratorio y se llama así para que nadie lo confunda con una política.
 */
final readonly class RecordOnlyOptionTable implements OptionTable
{
    public function __construct(private OptionTable $real)
    {
    }

    /**
     * Apenda el hecho, tal cual — el stream no pierde nada.
     *
     * @param string      $code    el motivo como código estable
     * @param string|null $message la frase de hoy, que puede cambiar
     */
    public function remove(string $option, string $code, ?string $message = null): void
    {
        $this->real->remove($option, $code, $message);
    }

    /**
     * Siempre vacía: la proyección no se entera.
     *
     * @return list<string>
     */
    public function removed(): array
    {
        return [];
    }

    /**
     * El HECHO sí se contesta, y por eso la vuelta sigue.
     *
     * Es exactamente la divergencia que este brazo introduce: el sistema sabe que la opción se declaró
     * ida —así que la negativa es recuperable y el bucle continúa— y el modelo la sigue viendo enfrente.
     */
    public function wasRemoved(string $option): bool
    {
        return $this->real->wasRemoved($option);
    }
}
