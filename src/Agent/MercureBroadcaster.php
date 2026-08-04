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
 * El transporte que hay hoy: el hub Mercure de esta app.
 *
 * ── POR QUÉ RECIBE UN `object` Y NO UN `MercureService` ─────────────────────────────────────────
 *
 * Porque este proyecto **no requiere `milpa/mercure`**, y no debería empezar a requerirlo sólo para
 * poder anotar un tipo. Una app que quiera el tablero instala el hub; una que no, sigue arrancando —
 * y hoy la mayoría no lo tiene.
 *
 * Es la misma decisión que `HttpProjector` toma con `milpa/auth`: se verifica la **forma** que se
 * necesita —un `publish(string, array)`— y se refuse a construir si no está. Duck-typing con puerta:
 * lo que se acepta es explícito, y el error llega al construir y no la primera vez que alguien mira
 * el tablero.
 */
final readonly class MercureBroadcaster implements SurfaceBroadcaster
{
    /**
     * @param object $hub cualquier cosa con `publish(string $topic, array $data): void` —
     *                    `Milpa\Mercure\MercureService` lo cumple
     *
     * @throws \InvalidArgumentException si no lo cumple
     */
    public function __construct(private object $hub)
    {
        if (!method_exists($hub, 'publish')) {
            throw new \InvalidArgumentException(
                'el hub no sabe publicar: se esperaba publish(string $topic, array $data) — '
                . 'quizá falta `composer require milpa/mercure`',
            );
        }
    }

    /** Empuja el hecho ya traducido al hub, para quien esté mirando en vivo. */
    public function broadcast(string $topic, array $payload): void
    {
        // Se pasa TAL CUAL. El payload ya viene traducido, y un transporte que le agregue o le quite
        // campos contaría una historia distinta a la que cuenta el mismo hecho al ponerse al día.
        $this->hub->publish($topic, $payload);
    }
}
