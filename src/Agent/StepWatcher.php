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

use Milpa\AiGateway\RunInterrupted;

/**
 * Mira el teclado entre paso y paso, para que el humano pueda decir «para».
 *
 * ── EL PROBLEMA QUE RESUELVE ────────────────────────────────────────────────────────────────────
 *
 * Una vuelta del agente bloquea la superficie completa: el TUI llama a la operación y no vuelve hasta
 * que termina, así que durante el trabajo **no lee teclas y no repinta**. Ahí no hay Esc que valga —
 * ni spinner que gire. La única costura donde la vuelta cede el control es `onStep`, entre pasos.
 *
 * ── POR QUÉ ES UN SERVICIO DEL CONTENEDOR ───────────────────────────────────────────────────────
 *
 * Porque la superficie no tiene handle sobre la operación: llama `agent` por el registro, que devuelve
 * un arreglo. Registrar el vigía antes de abrir el chat es la vía que ya usan `Config` y `SessionStore`
 * para lo mismo — que la app decida algo que la operación consulta.
 *
 * Y por eso también es OPCIONAL en la operación: sin él, el bucle corre exactamente como antes. Una
 * app sin terminal —un webhook, una cola— no tiene a quién preguntarle si quiere parar.
 *
 * ── LO QUE HACE CON LAS TECLAS QUE NO SON ESC ───────────────────────────────────────────────────
 *
 * Las guarda. Si el humano escribió mientras el agente trabajaba, esos bytes son suyos y tirarlos
 * sería comerse lo que tecleó; la pantalla los recupera con {@see tecleadoMientrasTanto()} y los pone
 * en la entrada.
 *
 * **Limitación declarada:** sólo se conservan los bytes imprimibles. Una flecha o un `Home` llegan como
 * secuencia que empieza con ESC, y aquí ESC significa «para» — así que una flecha tecleada a media
 * vuelta interrumpe. Distinguirlas requiere esperar al resto de la secuencia, y esperar es lo único
 * que este vigía no puede hacer: corre dentro del paso del agente.
 */
final class StepWatcher
{
    /** @var resource */
    private $entrada;

    private string $tecleado = '';

    private bool $pedido = false;

    /** @param resource|null $entrada */
    public function __construct($entrada = null)
    {
        $this->entrada = \is_resource($entrada) ? $entrada : \STDIN;
    }

    /**
     * Un paso más. Lanza si el humano pidió parar.
     *
     * @throws RunInterrupted
     */
    public function paso(int $numero): void
    {
        if ($this->hayEsc()) {
            $this->pedido = true;
        }

        if ($this->pedido) {
            throw RunInterrupted::porElHumano($numero);
        }
    }

    /** Lo que el humano tecleó mientras el agente trabajaba, y que la pantalla le debe devolver. */
    public function tecleadoMientrasTanto(): string
    {
        $texto = $this->tecleado;
        $this->tecleado = '';

        return $texto;
    }

    /** Para volver a usar el mismo vigía en la siguiente vuelta. */
    public function olvidar(): void
    {
        $this->pedido = false;
        $this->tecleado = '';
    }

    /**
     * Si hay un ESC entre lo que está esperando en el teclado AHORA.
     *
     * No bloquea: `stream_select` con timeout cero pregunta «¿hay algo?» y se va. Bloquear aquí
     * colgaría la vuelta del agente esperando a que alguien teclee, que es exactamente al revés.
     */
    private function hayEsc(): bool
    {
        $encontro = false;

        while (true) {
            $leer = [$this->entrada];
            $escribir = null;
            $excepcion = null;

            // UN STREAM QUE NO SE PUEDE VIGILAR NO INTERRUMPE NADA.
            //
            // `stream_select` lanza `ValueError` con streams sin descriptor real —`php://memory`, y
            // cualquier envoltorio que no lo tenga—. Un vigía que no puede mirar el teclado es un vigía
            // que no encuentra ESC; lo que NO puede es tumbar la vuelta del agente al intentarlo.
            //
            // El bucle envuelve `onStep` en un catch que registra y sigue, así que un ValueError aquí
            // no sería fatal — sería peor: el vigía quedaría mudo y una interrupción dejaría de
            // funcionar sin que nadie pudiera distinguirlo de un humano que no apretó nada.
            try {
                $listos = @stream_select($leer, $escribir, $excepcion, 0, 0);
            } catch (\ValueError) {
                return $encontro;
            }

            if ($listos !== 1) {
                return $encontro;
            }

            $byte = @fread($this->entrada, 1);
            if (!\is_string($byte) || $byte === '') {
                return $encontro;
            }

            if ($byte === "\x1b") {
                $encontro = true;
                continue;
            }

            // Lo imprimible se guarda; lo de control se tira. Un `\n` a media vuelta no puede
            // «enviar» nada porque no hay nada que enviar todavía.
            if (\ord($byte) >= 32) {
                $this->tecleado .= $byte;
            }
        }
    }
}
