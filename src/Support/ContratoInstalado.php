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

namespace Milpa\AppRuntime\Support;

/**
 * Leer una propiedad que el vendor instalado PUEDE NO TENER.
 *
 * ── POR QUÉ ESTO EXISTE, Y POR QUÉ RECIBE `object` ──────────────────────────────────────────────
 *
 * Un pin declara una exigencia; no la garantiza. Entre el `composer.json` que pide `^0.5` y el
 * `vendor/` que alguien tiene de verdad hay un **lock**, y ese lock puede estar en cualquier estado
 * — el de un usuario real tenía `command v0.4.0` mientras su manifiesto pedía `^0.5`. Este `src/`
 * viaja con `composer create-project` y convive con el vendor que su dueño tenga, no con el que su
 * manifiesto pide.
 *
 * El parámetro es `object` y no la clase concreta A PROPÓSITO: el análisis estático ve el paquete
 * instalado AQUÍ, donde la propiedad siempre existe, y con el tipo estrecho concluiría —con razón,
 * desde su vista— que la comprobación sobra. En runtime no sobra, y el daño es desproporcionado: un
 * `Warning` de propiedad indefinida se escribe sobre la pantalla de un TUI y la vuelve ilegible,
 * así que un desajuste de versiones se ve como un sistema roto.
 *
 * Esto NO es una licencia para leer cualquier cosa sin declararla. El pin sigue siendo la exigencia
 * y el gate de cascada sigue vigilándolo; esto es lo que hace que la exigencia incumplida degrade en
 * vez de romper.
 */
final class ContratoInstalado
{
    /** El valor de la propiedad si el vendor la trae y es una cadena no vacía; si no, `null`. */
    public static function cadena(object $de, string $propiedad): ?string
    {
        if (!property_exists($de, $propiedad)) {
            return null;
        }

        /** @var mixed $valor */
        $valor = $de->{$propiedad};

        return \is_string($valor) && $valor !== '' ? $valor : null;
    }

    /**
     * El `value` de una propiedad que es un enum respaldado, o `null` si el vendor no la trae.
     *
     * El nullsafe (`?->value`) protege el desreferenciado y NO la lectura: contra un vendor sin la
     * propiedad emite el aviso igual, y ese aviso es el que rompe la pantalla.
     */
    public static function valorDeEnum(object $de, string $propiedad): ?string
    {
        if (!property_exists($de, $propiedad)) {
            return null;
        }

        /** @var mixed $valor */
        $valor = $de->{$propiedad};

        return $valor instanceof \BackedEnum && \is_string($valor->value) ? $valor->value : null;
    }

    /**
     * Una propiedad que debe ser un arreglo, o el arreglo vacío si el vendor no la trae.
     *
     * Existe porque el fallo aquí NO es un aviso: pasar `null` a `array_map()` o devolverlo contra
     * un `: array` es `TypeError`, y la operación entera se cae en vez de mostrar lo que sí sabe.
     *
     * @return array<array-key, mixed>
     */
    public static function arreglo(object $de, string $propiedad): array
    {
        if (!property_exists($de, $propiedad)) {
            return [];
        }

        /** @var mixed $valor */
        $valor = $de->{$propiedad};

        return \is_array($valor) ? $valor : [];
    }

    /**
     * El valor de la propiedad si el vendor la trae y es una lista; si no, la lista vacía.
     *
     * @return list<string>
     */
    public static function listaDeCadenas(object $de, string $propiedad): array
    {
        if (!property_exists($de, $propiedad)) {
            return [];
        }

        /** @var mixed $valor */
        $valor = $de->{$propiedad};

        return \is_array($valor)
            ? array_values(array_filter($valor, static fn ($x): bool => \is_string($x)))
            : [];
    }
}
