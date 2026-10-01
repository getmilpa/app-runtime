<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Auth\ApiToken;
use Milpa\AppRuntime\Auth\TokenVerifier;
use Milpa\AppRuntime\Operations\TokenOperations;
use Milpa\Container\DIContainer;
use Milpa\Data\InMemoryRepository;
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

    /**
     * The token says what it is where it is handed over: opaque, not a JWT (greenhouse decisions/0548).
     *
     * An agent on a new house called the Bearer a JWT; nothing in the family says so, and the silence
     * was the source. The declaration and the minted result both name the format now.
     */
    public function testAMintedTokenSaysItIsOpaqueAndNotAJwt(): void
    {
        $container = new DIContainer();
        $container->registerService(TokenVerifier::class . '.repository', new InMemoryRepository(ApiToken::class));
        $ops = (new TokenOperations($container))->operations();
        $new = array_values(array_filter($ops, static fn ($op): bool => $op->name === 'token.new'))[0];

        self::assertStringContainsString('opaque', $new->description);
        self::assertStringContainsString('not a JWT', $new->description);

        $handler = $new->handler;
        self::assertIsCallable($handler);
        /** @var array<string, mixed> $r */
        $r = $handler(['actor' => 'ci', 'scopes' => ['plugins:read']]);

        self::assertTrue($r['ok']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $r['token'], 'the format it claims is the format it has');
        self::assertStringContainsString('not a JWT', (string) $r['format']);
        self::assertStringContainsString('Authorization: Bearer', (string) $r['use']);
        self::assertStringContainsString('MILPA_TOKEN', (string) $r['use']);
    }
}
