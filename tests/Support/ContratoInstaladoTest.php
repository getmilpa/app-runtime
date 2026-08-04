<?php

/**
 * This file is part of Milpa Framework — the composer create-project starting point for a Milpa app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/framework
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Support\ContratoInstalado;
use PHPUnit\Framework\TestCase;

/**
 * Leer contra un vendor que puede no traer la propiedad — el defecto que Rod encontró corriendo
 * `coa chat` con un lock desactualizado, y que se veía como un stack trace encima de su conversación.
 */
final class ContratoInstaladoTest extends TestCase
{
    /** El caso que rompía: la propiedad no existe, y leerla NO puede emitir un aviso. */
    public function testAMissingPropertyIsNullAndSaysNothing(): void
    {
        $viejo = new class () {
            public string $name = 'plugins.disable';
        };

        $avisos = [];
        set_error_handler(static function (int $n, string $s) use (&$avisos): bool {
            $avisos[] = $s;

            return true;
        });

        $leido = ContratoInstalado::cadena($viejo, 'namedTarget');
        $lista = ContratoInstalado::listaDeCadenas($viejo, 'removedOptions');

        restore_error_handler();

        self::assertNull($leido);
        self::assertSame([], $lista, 'una lista ausente es la lista vacía, no null contra un `: array`');
        self::assertSame([], $avisos, 'un aviso aquí se escribe sobre la pantalla del TUI y la destruye');
    }

    /** Y cuando el vendor SÍ la trae, se lee tal cual. */
    public function testAPresentPropertyIsReadAsIs(): void
    {
        $nuevo = new class () {
            public ?string $namedTarget = 'name';

            /** @var list<string> */
            public array $removedOptions = ['plugins_disable'];
        };

        self::assertSame('name', ContratoInstalado::cadena($nuevo, 'namedTarget'));
        self::assertSame(['plugins_disable'], ContratoInstalado::listaDeCadenas($nuevo, 'removedOptions'));
    }

    /** Una cadena vacía o un tipo que no es cadena valen lo mismo que la ausencia: nada declarado. */
    public function testAnEmptyOrWrongTypedValueCountsAsNotDeclared(): void
    {
        $raro = new class () {
            public ?string $namedTarget = '';

            public ?array $removedOptions = null;
        };

        self::assertNull(ContratoInstalado::cadena($raro, 'namedTarget'));
        self::assertSame([], ContratoInstalado::listaDeCadenas($raro, 'removedOptions'));
    }
}
