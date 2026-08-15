<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\ModelCallIntake;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\ChannelObserver;

/**
 * El cable entre el canal y el stream.
 *
 * Los dos extremos ya estaban probados: la puerta de enlace entrega lo que serializó, el almacén
 * graba lo que le entregan. Lo que faltaba era que alguien los conectara — y mientras nadie lo haga,
 * una superficie de desarrollador reporta con toda razón que nadie observó la entrada, para siempre.
 *
 * ── ESTO NO PUEDE ROMPER LO QUE OBSERVA ─────────────────────────────────────────────────────────
 *
 * Un grabador que truena convertiría un agente que funciona en uno roto en el momento en que alguien
 * pide verlo, y la falla se leería como del agente y no del instrumento. Así que una escritura mala
 * pierde la observación y nada más.
 */
final class IntakeObserver implements ChannelObserver
{
    public function __construct(
        private readonly SessionStore $sessions,
        private readonly string $session,
    ) {
    }

    /** Graba lo que viajó como un hecho de la sesión, junto a lo que el agente hizo con ello. */
    public function observe(string $uri, array $payload): void
    {
        try {
            $this->sessions->recordModelCall($this->session, ModelCallIntake::fromChannelPayload($uri, $payload));
        } catch (\Throwable) {
            // Se pierde la observación, no la corrida. Observar no puede cambiar lo observado, y eso
            // incluye no poder tumbarlo.
        }
    }
}
