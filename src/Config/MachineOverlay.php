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

namespace Milpa\AppRuntime\Config;

/**
 * What the machine wrote, laid over what the human wrote.
 *
 * `config/app.php` belongs to the human: it is a template, it carries the comments, and it is the
 * file a person opens. `.milpa/` belongs to the machine — it already holds the constitution, which
 * a governed rite writes and nobody edits by hand. Configuration changed through a governed
 * operation lands there, beside it, for the same reason.
 *
 * THE MACHINE'S VALUE WINS, and not by hierarchy. It wins because it passed through consent and
 * left an acta, and a consented change that a silent hand edit overwrites is exactly the hole
 * greenhouse decisions/0027 named — an operation that can relax the gate while the gate goes on
 * reporting that it governs.
 *
 * The human does not lose authority; the same path is open to them, which is the point.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO is forbid editing the file. Nobody takes an editor away from
 * anyone. A key declared in both places is DIVERGENCE, and divergence is detected rather than
 * prevented — the shape this family already uses for its mirrored actas.
 */
final class MachineOverlay
{
    /** Where a governed operation writes. Beside the constitution, in the directory the machine owns. */
    public const RUTA = '/.milpa/agent.json';

    /**
     * The human's configuration with the machine's on top.
     *
     * The merge is RECURSIVE for arrays and replacing for everything else: writing
     * `agent.instructions` must not erase `agent.compaction` that nobody touched. A flat
     * `array_replace` would do exactly that, and it would look like it worked.
     *
     * @param array<string, mixed> $delHumano lo que devuelve config/app.php
     *
     * @return array<string, mixed>
     */
    public static function sobre(array $delHumano, string $root): array
    {
        $archivo = $root . self::RUTA;
        if (! is_file($archivo)) {
            return $delHumano;
        }

        $crudo = json_decode((string) file_get_contents($archivo), true);

        // UN ARCHIVO ILEGIBLE NO BORRA LA CONFIGURACIÓN DE NADIE.
        //
        // Devolver lo del humano intacto es lo único honesto: la alternativa —arrancar con la
        // configuración vacía— convertiría un JSON con una coma de más en una app sin instrucciones
        // y sin plazos, y lo haría en silencio.
        if (! \is_array($crudo)) {
            return $delHumano;
        }

        return self::fundir($delHumano, $crudo);
    }

    /**
     * Las llaves que los DOS declararon — lo que este diseño no puede prevenir y sí puede decir.
     *
     * Prohibir la edición a mano no está a nuestro alcance: nadie le quita a nadie su editor. Lo que
     * sí se puede es no dejar que la divergencia sea invisible, y ésa es la forma que esta familia ya
     * usa para sus actas espejadas — un duplicado sin detector es una mentira futura.
     *
     * La ruta se devuelve punteada (`agent.instructions`) porque es como se pide en `Config::get()`,
     * y así lo que el reporte dice se puede copiar tal cual a la operación que lo va a corregir.
     *
     * @param  array<string, mixed> $delHumano lo que devuelve config/app.php
     * @return list<string>         rutas punteadas, ordenadas
     */
    public static function divergencias(array $delHumano, string $root): array
    {
        $archivo = $root . self::RUTA;
        if (! is_file($archivo)) {
            return [];
        }

        $crudo = json_decode((string) file_get_contents($archivo), true);
        if (! \is_array($crudo)) {
            return [];
        }

        $rutas = self::comunes($delHumano, $crudo, '');
        sort($rutas);

        return $rutas;
    }

    /**
     * @param  array<string, mixed> $a
     * @param  array<string, mixed> $b
     * @return list<string>
     */
    private static function comunes(array $a, array $b, string $prefijo): array
    {
        $rutas = [];

        foreach ($b as $llave => $valor) {
            if (! \array_key_exists($llave, $a)) {
                continue;
            }

            $ruta = $prefijo === '' ? (string) $llave : $prefijo . '.' . $llave;

            // Se BAJA mientras los dos sean arreglos: declarar `agent` en ambos no es divergencia si
            // adentro tocan llaves distintas. Reportar el padre diría que chocan cuando conviven.
            if (\is_array($valor) && \is_array($a[$llave])) {
                $rutas = array_merge($rutas, self::comunes($a[$llave], $valor, $ruta));

                continue;
            }

            $rutas[] = $ruta;
        }

        return $rutas;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $encima
     *
     * @return array<string, mixed>
     */
    private static function fundir(array $base, array $encima): array
    {
        foreach ($encima as $llave => $valor) {
            $base[$llave] = \is_array($valor) && \is_array($base[$llave] ?? null)
                ? self::fundir($base[$llave], $valor)
                : $valor;
        }

        return $base;
    }
}
