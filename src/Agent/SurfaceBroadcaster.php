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
 * Empujar un hecho ya traducido hacia donde alguien lo esté mirando.
 *
 * ── POR QUÉ EXISTE UN PUERTO Y NO SE LLAMA A MERCURE DIRECTO ────────────────────────────────────
 *
 * Porque quien traduce no tiene por qué saber quién empuja. El puente
 * ({@see BroadcastingEventStore}) sabe **qué** significa un evento; este puerto decide **a dónde va**,
 * y las dos decisiones cambian por razones distintas: cambiar de Mercure a websockets no debería
 * tocar una sola línea de la traducción, y agregar un evento nuevo no debería tocar el transporte.
 *
 * También es lo que hace que el puente se pueda probar sin levantar un hub: la prueba usa un
 * broadcaster que guarda lo que recibió, y compara. Un puente que sólo se puede verificar contra un
 * servicio corriendo es un puente que en la práctica nadie verifica.
 *
 * ── LO QUE UN IMPLEMENTADOR NO PUEDE HACER ──────────────────────────────────────────────────────
 *
 * **No puede reinterpretar el payload.** Llega traducido y completo; un transporte que decida
 * agregarle campos, quitarle otros o cambiarle nombres estaría contando una historia distinta a la
 * que cuenta el mismo hecho por el otro camino —el de ponerse al día— y la divergencia aparecería
 * justo en el evento que nadie probó.
 */
interface SurfaceBroadcaster
{
    /**
     * Lleva un hecho ya traducido a quien lo esté esperando.
     *
     * @param string               $topic   a quién le interesa: `milpa/sessions/<id>`
     * @param array<string, mixed> $payload el hecho ya traducido por `SessionProjector`
     *
     * @throws \Throwable si el transporte falla — el puente decide qué hacer con eso, no el transporte
     */
    public function broadcast(string $topic, array $payload): void;
}
