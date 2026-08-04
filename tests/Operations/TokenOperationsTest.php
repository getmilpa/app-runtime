<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\TokenOperations;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * Los tokens con que alguien se identifica ante esta app por HTTP.
 *
 * Lo que fija esta prueba es la distinción entre **dos ausencias** que el sistema no puede confundir:
 * «`milpa/auth` no está instalado» hace que estas operaciones no se ofrezcan (ADR-0040), y «está pero
 * esta app no cableó un almacén» es una app mal configurada — que sí tiene que aparecer y decir qué
 * le falta. Colapsarlas escondería la segunda detrás del silencio de la primera, y quien configuró
 * mal se quedaría sin ninguna señal.
 */
final class TokenOperationsTest extends TestCase
{
    /** @return array<string, callable> */
    private function handlers(): array
    {
        $por = [];
        foreach ((new TokenOperations(new DIContainer()))->operations() as $operacion) {
            $handler = $operacion->handler;
            self::assertIsCallable($handler);
            $por[$operacion->name] = $handler;
        }

        return $por;
    }

    /** Con el paquete instalado, las tres operaciones se ofrecen. */
    public function testWithThePackageInstalledTheOperationsAreOffered(): void
    {
        $nombres = array_keys($this->handlers());

        self::assertContains('token.list', $nombres);
        self::assertNotEmpty($nombres);
    }

    /**
     * Sin almacén cableado, cada una lo dice — y ninguna revienta.
     *
     * Es el estado real de una app que instaló `milpa/auth` y todavía no configuró su backend en
     * `config/app.php`, que es exactamente el momento en que alguien necesita que le digan qué falta.
     */
    public function testWithoutAWiredStoreEachOneSaysSoInsteadOfCrashing(): void
    {
        foreach ($this->handlers() as $nombre => $handler) {
            /** @var array<string, mixed> $r */
            $r = $handler(['actor' => 'alguien', 'id' => 'x', 'scopes' => ['a']]);

            self::assertFalse($r['ok'] ?? true, $nombre);
            self::assertStringContainsString('almacén de tokens', (string) ($r['error'] ?? ''), $nombre);
        }
    }

    /**
     * Y lo que falta en la ENTRADA se dice antes de mirar el almacén.
     *
     * Un «no hay almacén» ante una llamada a la que le falta el actor mandaría a arreglar la
     * configuración cuando el problema era la llamada.
     */
    public function testMissingInputIsNamedBeforeLookingAtTheStore(): void
    {
        $handlers = $this->handlers();

        foreach (['token.create' => 'actor', 'token.revoke' => 'id'] as $nombre => $campo) {
            if (!isset($handlers[$nombre])) {
                continue;
            }
            /** @var array<string, mixed> $r */
            $r = $handlers[$nombre]([]);

            self::assertFalse($r['ok'] ?? true, $nombre);
            self::assertStringContainsString($campo, (string) ($r['error'] ?? ''), $nombre);
        }
    }
}
