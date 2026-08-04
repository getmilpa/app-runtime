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

namespace Milpa\AppRuntime\Support;

use Psr\Log\AbstractLogger;

/**
 * Un logger que escribe a STDERR, para que «lo dice» sea cierto.
 *
 * Existe por una contradicción medida: `SecondOpinionGate` promete en su docblock que un verificador
 * caído «no calla» — y su warning iba a un `NullLogger`, así que callaba. Lo encontró una revisión
 * adversaria del pre-registro de Q-P19-J, y no es cosmético: en el laboratorio, un juez que no pudo
 * opinar deja pasar la llamada, y sin esta línea esa corrida es indistinguible de una donde el juez
 * aprobó — que es exactamente la confusión que el falsificador 1 de Q-P19-D nombra.
 *
 * STDERR y no el stream de la sesión, a propósito: «no pude opinar» es un hecho del PROCESO, no de la
 * sesión — depende de la red de esta corrida, no del trabajo. El arnés (`lab-run.sh`) captura stderr
 * por corrida en su `.err`, y en una terminal el operador lo ve donde se ven las advertencias.
 */
final class StderrLogger extends AbstractLogger
{
    /**
     * Mientras esto sea verdadero, NADIE escribe al tty — ni siquiera este logger.
     *
     * Lo destapó una revisión adversaria el mismo día en que se agregó: apagar `display_errors`
     * protege al TUI de los avisos de PHP, y NO de un `fwrite(STDERR)` explícito como el de abajo.
     * O sea que la pieza que existe para que «lo dice» sea cierto era, ella misma, una de las que
     * podía destruir la pantalla donde se iba a leer.
     *
     * No se pierde nada: con el TUI arriba el aviso va al `error_log` del proceso —que la propia
     * pantalla apunta a `var/coa-tui.log`— y ahí se lee sin pelear por el mismo espacio.
     */
    private static bool $pantallaTomada = false;

    /** La pantalla la tomó un TUI: a partir de aquí los avisos van al log, no al tty. */
    public static function pantallaTomada(bool $tomada): void
    {
        self::$pantallaTomada = $tomada;
    }

    /** @param array<string, mixed> $context */
    /**
     * Escribe una línea en `stderr`, y en `stderr` a propósito.
     *
     * `stdout` es la respuesta del comando: lo que otro proceso lee por tubería. Un log ahí se
     * mezcla con esa respuesta y rompe a quien la esté parseando, sin ningún síntoma aquí.
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $ctx = $context === [] ? '' : ' ' . (json_encode($context, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '');
        $linea = '[' . $level . '] ' . $message . $ctx;

        if (self::$pantallaTomada) {
            error_log($linea);

            return;
        }

        fwrite(\STDERR, $linea . "\n");
    }
}
