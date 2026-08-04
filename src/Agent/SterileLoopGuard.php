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
 * Se niega a repetir una llamada que ya falló dos veces con los mismos argumentos (Q-P19-R).
 *
 * ── EL HECHO QUE LO JUSTIFICA ───────────────────────────────────────────────────────────────────
 *
 * Medido el 2026-08-03 con qwen3-coder:30b (`.milpa/evidence/lab/q-p19q/`): un sub-agente llamó DOCE
 * veces seguidas `make` con los mismos argumentos inválidos, recibió doce veces el mismo error —uno
 * útil, que traía la corrección exacta adentro— y agotó su techo de pasos sin cambiar nada. El
 * resultado SÍ le vuelve al modelo; no es un defecto de cableado. El modelo simplemente no lo usó.
 *
 * Un bucle así es visible sin interpretar nada: misma herramienta, mismos argumentos, mismo fallo.
 * Verlo es trabajo del sistema — el agente ya tiene bastante con decidir QUÉ quiere hacer.
 *
 * ── LO QUE NO HACE, Y ES LO QUE LO VUELVE USABLE ────────────────────────────────────────────────
 *
 * No termina la vuelta, no retira la herramienta del catálogo y no juzga intención. Sólo se niega a
 * ejecutar la repetición y devuelve el hecho —con el error que la herramienta ya había dicho— para
 * que el modelo tenga con qué corregir. Tres distinciones lo mantienen fuera del trabajo legítimo:
 *
 *   · repetir algo que FUNCIONÓ nunca se corta (listar dos veces es trabajo, no bucle);
 *   · cambiar los argumentos arranca la cuenta de cero (corregir no se castiga);
 *   · un éxito borra los fallos anteriores (un fallo transitorio no veta la llamada para siempre).
 *
 * ── EL FALLO SE LEE DONDE SE DECLARA ────────────────────────────────────────────────────────────
 *
 * Dos niveles, los dos honestos y ninguno adivinado: `$ok === false` es el runtime diciendo que la
 * herramienta no corrió, y `ok: false` dentro del payload es la operación diciendo que no pudo. La
 * segunda hace falta porque `ToolRegistry` envuelve en `ToolResult::success()` **todo lo que el
 * handler devuelva sin lanzar** — así que al nivel del protocolo una operación fallida es
 * indistinguible de una exitosa, y el único lugar donde el fallo existe es el campo que la operación
 * declara.
 *
 * Un resultado que no es JSON no se interpreta: sin `ok` declarado y con el runtime en verde, pasó.
 * Buscar palabras como «error» en la prosa fabricaría fallos donde no los hay.
 */
final class SterileLoopGuard
{
    /** @var array<string, array{veces: int, error: string}> huella de la llamada → cuántas veces falló y con qué */
    private array $fallos = [];

    /**
     * @param int $tolerancia cuántos fallos idénticos se dejan pasar antes de negarse. Dos por
     *                        defecto: insistir una vez es legítimo —un fallo puede ser transitorio—
     *                        y a la tercera ya no hay información nueva que esperar
     */
    public function __construct(private readonly int $tolerancia = 2)
    {
    }

    /**
     * Lo que una llamada devolvió, para que la siguiente igual se pueda reconocer.
     *
     * @param array<string, mixed> $arguments
     * @param bool                 $ok        si el runtime pudo ejecutar la herramienta
     */
    public function anota(string $tool, array $arguments, string $result, bool $ok): void
    {
        $huella = $this->huella($tool, $arguments);
        $error = $this->errorDeclarado($result, $ok);

        if ($error === null) {
            // Un éxito borra la cuenta: lo que falló y luego funcionó no está en bucle.
            unset($this->fallos[$huella]);

            return;
        }

        $this->fallos[$huella] = [
            'veces' => ($this->fallos[$huella]['veces'] ?? 0) + 1,
            // El ÚLTIMO error, no el primero: si la herramienta cambió de queja, la que sirve para
            // corregir es la de ahora.
            'error' => $error,
        ];
    }

    /**
     * Por qué esta llamada no se vuelve a ejecutar, o `null` si procede.
     *
     * @param array<string, mixed> $arguments
     */
    public function motivoParaNoRepetir(string $tool, array $arguments): ?string
    {
        $visto = $this->fallos[$this->huella($tool, $arguments)] ?? null;
        if ($visto === null || $visto['veces'] < $this->tolerancia) {
            return null;
        }

        return sprintf(
            'Esta llamada a «%s» ya falló %s con exactamente estos argumentos, así que no se repite: %s. '
            . 'Cambia los argumentos o haz otra cosa.',
            $tool,
            $visto['veces'] === 2 ? 'dos veces' : $visto['veces'] . ' veces',
            $visto['error'],
        );
    }

    /** Para volver a empezar — otra vuelta, otra sesión. */
    public function olvidar(): void
    {
        $this->fallos = [];
    }

    /**
     * El error que este resultado DECLARA, o `null` si no declara ninguno.
     */
    private function errorDeclarado(string $result, bool $ok): ?string
    {
        if (!$ok) {
            return $result === '' ? 'la herramienta no pudo ejecutarse' : $result;
        }

        $datos = json_decode($result, true);
        if (!\is_array($datos) || ($datos['ok'] ?? null) !== false) {
            return null;
        }

        $error = $datos['error'] ?? null;

        return \is_string($error) && $error !== '' ? $error : 'la operación devolvió ok:false sin decir por qué';
    }

    /**
     * La identidad de una llamada: herramienta más argumentos, insensible al orden de las llaves.
     *
     * Ordenar antes de serializar no es cosmético — sin eso, `{"a":1,"b":2}` y `{"b":2,"a":1}` serían
     * dos llamadas distintas y un modelo que alterna el orden pasaría por debajo del vigía.
     *
     * @param array<string, mixed> $arguments
     */
    private function huella(string $tool, array $arguments): string
    {
        $ordenados = $arguments;
        ksort($ordenados);

        return $tool . '|' . (json_encode($ordenados, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '');
    }
}
