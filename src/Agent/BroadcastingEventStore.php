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

use Milpa\Agent\SessionProjector;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * El puente entre el stream de una sesión y la superficie que lo está mirando.
 *
 * ── POR QUÉ ES UN DECORADOR DEL ALMACÉN Y NO UN LECTOR QUE PERSIGUE EL ARCHIVO ──────────────────
 *
 * Un tailer tendría que recordar por dónde iba. Ese cursor sería **estado propio del puente**, y un
 * puente con estado es la primera de las dos copias que la spec del tablero prohíbe: en el momento en
 * que haya dos sitios que contesten «¿en qué va esto?», divergirán, y la única pregunta es cuándo.
 *
 * Envolviendo el almacén no hay nada que recordar. El hecho se escribe y, en el mismo camino, se
 * empuja. No hay ventana entre «quedó guardado» y «alguien lo supo» donde un reinicio pueda perder
 * algo — y si la pierde, ponerse al día con `agent:timeline` la recupera, porque la fuente sigue
 * siendo el stream.
 *
 * ── EL ORDEN NO ES NEGOCIABLE: PRIMERO SE GUARDA ────────────────────────────────────────────────
 *
 * Se apenda antes de traducir y de empujar. **El stream es la verdad; la superficie es una vista.**
 * Publicar primero abriría la puerta a que alguien vea una tarjeta moverse por un hecho que después
 * no se pudo guardar, y eso es peor que no verla: sería una pantalla afirmando algo que el sistema no
 * recuerda.
 *
 * Por la misma razón, que el transporte falle **no rompe la escritura**. Se registra —un `catch` que
 * no dice nada convierte un hub caído en una superficie que simplemente deja de moverse, sin que
 * nadie sepa por qué— y se sigue. Lo que se perdió se recupera pidiendo la línea de tiempo.
 *
 * ── LA MISMA TRADUCCIÓN QUE EL CAMINO LENTO ─────────────────────────────────────────────────────
 *
 * Usa {@see SessionProjector}, que es exactamente lo que usa `agent:timeline` para ponerse al día.
 * Dos traducciones —una para la historia y otra para lo nuevo— son dos oportunidades de pintar
 * distinto el mismo hecho. Ésa es la propiedad que la prueba del criterio 1 fija: la secuencia que
 * sale por aquí, evento por evento, tiene que ser idéntica a la que sale de `projectAll()`.
 *
 * ── LO QUE NO PASA POR EL PUENTE ────────────────────────────────────────────────────────────────
 *
 * Los streams que no son de sesión no se tocan: este almacén puede estar guardando gobernanza o
 * cualquier otra cosa, y empujar eso a un tópico de sesiones sería inventarle audiencia a un hecho.
 * Y los eventos que el proyector traduce a `null` tampoco: ese `null` es la afirmación de que ese
 * hecho no cambia lo que se ve, no un descarte por descuido.
 */
final readonly class BroadcastingEventStore implements EventStoreInterface
{
    public const TOPIC_PREFIX = 'milpa/sessions/';

    public function __construct(
        private EventStoreInterface $inner,
        private SurfaceBroadcaster $broadcaster,
        private SessionProjector $projector = new SessionProjector(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** Apenda el hecho al almacén real y lo traduce a la superficie: una escritura, dos destinos. */
    public function append(Event $event): void
    {
        // PRIMERO SE GUARDA. Si esto lanza, no se empuja nada: nadie debe ver moverse una tarjeta por
        // un hecho que el sistema no llegó a recordar.
        $this->inner->append($event);

        if (!str_starts_with($event->streamId, SessionStore::PREFIX)) {
            return;
        }

        $proyectado = $this->projector->project($event);
        if ($proyectado === null) {
            return;
        }

        $sesion = substr($event->streamId, \strlen(SessionStore::PREFIX));

        try {
            $this->broadcaster->broadcast(self::TOPIC_PREFIX . $sesion, $proyectado);
        } catch (\Throwable $e) {
            // NO se relanza: el hecho ya está guardado y la escritura no depende de que alguien lo
            // esté mirando. Pero tampoco se calla — un hub caído se vería igual que una sesión
            // tranquila, y quien mire la pantalla no tendría cómo distinguirlos.
            $this->logger->warning('no se pudo empujar un hecho de sesión a la superficie', [
                'session' => $sesion,
                'kind' => $proyectado['kind'],
                'seq' => $event->seq,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return list<Event> */
    /** Todo lo que ese stream guarda — delegado tal cual al almacén de verdad. */
    public function replay(string $streamId): array
    {
        return $this->inner->replay($streamId);
    }

    /** La secuencia que le tocaría al siguiente evento. */
    public function nextSeq(): int
    {
        return $this->inner->nextSeq();
    }

    /** @return list<string> */
    /** Qué streams existen, según el almacén que envuelve. */
    public function streams(): array
    {
        return $this->inner->streams();
    }

    /** @return array<string, list<Event>> */
    /** Cada stream de una sola pasada — delegado tal cual al almacén que envuelve. */
    public function replayAll(): array
    {
        return $this->inner->replayAll();
    }
}
