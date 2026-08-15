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

/**
 * Las clases de efecto que un operador puede retirar, nombradas UNA vez.
 *
 * ── POR QUÉ EXISTE ESTA CLASE ───────────────────────────────────────────────────────────────────
 *
 * Medido sobre ganado (greenhouse `evidence/0197`): `--denyEffects=mutates` retiró cero, falló cero
 * y dijo cero. `mutates` no es una clase —son las cuatro de abajo—, así que quien lo tecleó pidió
 * retirar una clase entera de operaciones **y creyó que había pasado**.
 *
 * *Una bandera que acepta lo que no entiende es ley sin mecanismo (MILPA-G002), y su silencio es
 * peor que un error: el operador contiene menos de lo que cree y nada se lo dice.*
 *
 * ── Y BORRA UNA DUPLICACIÓN QUE YA ESTABA ───────────────────────────────────────────────────────
 *
 * Las cuatro estaban escritas DOS veces: en el `match` que resuelve y en la descripción del esquema
 * que un agente lee. Una tercera copia sería peor, y dos ya discrepan el día que alguien cambia una.
 * *La convención se llama, no se copia* (greenhouse `evidence/0141`).
 */
final class EffectClasses
{
    /**
     * Las cuatro, y su orden es el que se le enseña a quien pregunta.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return ['mutating', 'external', 'irreversible', 'authority'];
    }

    /**
     * Lo que se pidió y esta casa no sabe qué es.
     *
     * Devuelve lista vacía cuando no se pidió nada: no pedir no es pedir mal.
     *
     * @param string|list<string>|null $asked La CLI lo entrega como cadena separada por comas; una
     *                                        llamada programática, como lista.
     *
     * @return list<string>
     */
    public static function unknownIn(mixed $asked): array
    {
        $conocidas = self::all();
        $desconocidas = [];

        foreach (\is_string($asked) ? explode(',', $asked) : (\is_array($asked) ? $asked : []) as $clase) {
            $limpia = strtolower(trim((string) $clase));
            if ($limpia === '' || \in_array($limpia, $conocidas, true)) {
                continue;
            }
            $desconocidas[] = $limpia;
        }

        return array_values(array_unique($desconocidas));
    }

    /**
     * La negativa, que nombra lo que no entendió Y lo que sí habría entendido.
     *
     * Una negativa que sólo dice «no» deja al operador adivinando una ortografía, y esta casa ya
     * resolvió esa forma para los roles desconocidos.
     *
     * @param list<string> $desconocidas
     */
    public static function refusal(array $desconocidas): string
    {
        return \sprintf(
            'unknown effect class(es): %s — the classes are %s',
            implode(', ', $desconocidas),
            implode('|', self::all()),
        );
    }

    /** La descripción del esquema, armada de la misma lista para que no puedan discrepar. */
    public static function describe(): string
    {
        return 'Withdraw every operation in these effect classes: '
            . implode('|', self::all())
            . '. Unknown effects count as denied. Requires --session';
    }
}
