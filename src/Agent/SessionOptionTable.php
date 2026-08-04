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

use Milpa\AppRuntime\Support\ContratoInstalado;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\OptionTable;

/**
 * La mesa de una sesión, sostenida por su stream.
 *
 * ── POR QUÉ UN HECHO Y NO UN ARREGLO EN MEMORIA ─────────────────────────────────────────────────
 *
 * La mesa pertenece a la SESIÓN, no al proceso. Si viviera en una propiedad de esta clase no
 * sobreviviría a una compactación ni a retomar la sesión mañana, y sería exactamente la segunda copia
 * del estado que la spec del tablero prohíbe: dos sitios que saben qué opciones hay, divergiendo.
 *
 * Por eso quitar apenda `session.option_removed` como cualquier otro hecho, y por eso leer vuelve a
 * reducir el stream. Tres cosas se ganan con eso, y ninguna es teórica:
 *
 *   1. **Sobrevive.** Retomar la sesión mañana encuentra la misma mesa, sin que nadie la reconstruya.
 *   2. **Es auditable.** Quién la quitó, cuándo y bajo qué motivo quedan escritos — la misma exigencia
 *      que ya rige para contestar una pregunta ({@see \Milpa\Agent\Principal}).
 *   3. **El falsificador se puede leer.** «¿El agente volvió a llamar la opción que ya no estaba?» se
 *      contesta comparando el hecho que la quitó contra la llamada que vino después, en el stream, sin
 *      inferir nada de la conducta.
 *
 * ── SE VUELVE A LEER, SIEMPRE ───────────────────────────────────────────────────────────────────
 *
 * {@see removed()} carga la sesión en cada llamada. Capturar el estado en el constructor sería
 * reintroducir la foto que este trabajo entero vino a quitar: el catálogo quedaría congelado en el
 * momento en que se construyó este objeto, y retirar una opción no cambiaría nada de lo que el modelo
 * ve. El costo es una reducción por paso, y es el precio de que la proyección sea derivada y no
 * recordada.
 */
final readonly class SessionOptionTable implements OptionTable
{
    public function __construct(
        private SessionStore $sessions,
        private string $sessionId,
    ) {
    }

    /**
     * Apenda que esta opción salió de la mesa.
     *
     * @param string      $code    el motivo como código estable
     * @param string|null $message la frase de hoy, que puede cambiar
     */
    public function remove(string $option, string $code, ?string $message = null): void
    {
        $this->sessions->removeOption($this->sessionId, $option, $code, $message);
    }

    /**
     * Lo que hoy no está en la mesa, releído del stream.
     *
     * Una sesión que no existe devuelve la mesa completa —ninguna opción retirada— y no un error:
     * quedarse sin catálogo porque no se pudo cargar la sesión sería apagar al agente por un fallo de
     * lectura, que es peor que correr sin la mejora.
     *
     * @return list<string>
     */
    public function removed(): array
    {
        $sesion = $this->sessions->load($this->sessionId);

        // Leída a través del contrato y no directo: `Session::$removedOptions` nació el 2026-08-01 y
        // este `src/` convive con el vendor que su dueño tenga. Sin esto, una sesión de un `agent`
        // anterior devuelve null contra un `: array` — TypeError, no aviso.
        return $sesion === null ? [] : ContratoInstalado::listaDeCadenas($sesion, 'removedOptions');
    }

    /**
     * Si el hecho existe en el stream de esta sesión.
     *
     * En esta implementación coincide con {@see removed()} — y tiene que coincidir: aquí el hecho y la
     * proyección salen del mismo fold. La separación existe para el brazo de laboratorio que apenda sin
     * proyectar ({@see RecordOnlyOptionTable}), no para que una app las haga divergir.
     */
    public function wasRemoved(string $option): bool
    {
        return \in_array($option, $this->removed(), true);
    }
}
