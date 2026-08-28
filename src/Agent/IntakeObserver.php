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
use Milpa\AiGateway\ReturnObserver;

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
 *
 * The wire payload and the declared session window remain separate facts. The first says what the
 * provider received after gateway composition; the second says why each Session-composed history
 * message existed. Joining them here by index or content would turn this reader into the classifier.
 */
final class IntakeObserver implements ChannelObserver, ReturnObserver
{
    /** @param list<array{role: string, content: string, class: string}>|null $window */
    public function __construct(
        private readonly SessionStore $sessions,
        private readonly string $session,
        private readonly ?array $window = null,
    ) {
    }

    /** Graba lo que viajó como un hecho de la sesión, junto a lo que el agente hizo con ello. */
    public function observe(string $uri, array $payload): void
    {
        try {
            $this->sessions->recordModelCall($this->session, ModelCallIntake::fromChannelPayload(
                $uri,
                $payload,
                window: $this->window,
            ));
        } catch (\Throwable) {
            // Se pierde la observación, no la corrida. Observar no puede cambiar lo observado, y eso
            // incluye no poder tumbarlo.
        }
    }

    /**
     * Records what the model call cost as its own fact on the same session, beside what it was given.
     *
     * The gateway already normalized the usage across providers; this only lands it on the stream. It
     * follows {@see observe()}'s discipline exactly: an observer may not fell the run it observes, so
     * a failed write loses the cost, never the corrida.
     *
     * @param array<string, mixed> $meta
     */
    public function observeReturn(string $uri, array $meta): void
    {
        try {
            $this->sessions->recordModelReturn($this->session, $meta);
        } catch (\Throwable) {
            // Same contract as observe(): observing a channel may not change it.
        }
    }
}
