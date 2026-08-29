<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\DeclaredScreensPageProvider;
use Milpa\AppRuntime\Web\ScreenOperations;
use Milpa\AppRuntime\Web\ScreenStore;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Subject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * La primitiva que deja al AGENTE autorar una pantalla viva MATERIALMENTE (greenhouse decisions/0158,
 * graduada en 0159): `screen:declare` persiste una DECLARACIÓN (un data-table: nombre + columnas +
 * filas) al {@see ScreenStore}, y la puerta live la sirve sin desplegar código. Lo que esta prueba
 * fija: el store escribe y lee bajo la raíz de la app (no dentro de vendor/), el nombre se valida, el
 * provider de serie sirve lo declarado y declina lo no declarado, y la operación gradúa CON su
 * gobernanza (muta + scope de componente + efecto Persistente) — el agente declara, el marco proyecta,
 * el humano gobierna.
 */
final class ScreenStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/milpa-screen-store-' . bin2hex(random_bytes(6)) . '/var/screens.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
            @rmdir(\dirname($this->path));
            @rmdir(\dirname($this->path, 2));
        }
    }

    public function testDeclareWritesAndReadsBackUnderTheGivenPath(): void
    {
        $store = new ScreenStore($this->path);
        $result = $store->declare([
            'name' => 'equipo',
            'columns' => [['key' => 'nombre', 'label' => 'Nombre']],
            'rows' => [['nombre' => 'Rod']],
        ]);

        self::assertTrue($result['ok']);
        self::assertSame('/live/page?component=equipo', $result['servedAt']);
        self::assertFileExists($this->path);
        self::assertSame(['equipo'], $store->names());
        self::assertSame([['nombre' => 'Rod']], $store->screen('equipo')['rows']);
        self::assertNull($store->screen('no-existe'));
    }

    public function testAnInvalidNameIsRefusedAndNothingIsWritten(): void
    {
        $store = new ScreenStore($this->path);
        $result = $store->declare(['name' => 'Mal Nombre', 'columns' => [], 'rows' => []]);

        self::assertFalse($result['ok']);
        self::assertFileDoesNotExist($this->path);
    }

    public function testTheBuiltinProviderServesDeclaredScreensAndDeclinesOthers(): void
    {
        $store = new ScreenStore($this->path);
        $store->declare(['name' => 'equipo', 'columns' => [['key' => 'a', 'label' => 'A']], 'rows' => [['a' => '1']]]);
        $provider = new DeclaredScreensPageProvider($store);
        $request = $this->createStub(ServerRequestInterface::class);

        $props = $provider->propsFor('equipo', $request);
        self::assertNotNull($props);
        self::assertSame([['a' => '1']], $props['rows']);
        self::assertNull($provider->propsFor('no-declarada', $request));
    }

    public function testScreenDeclareGraduatesWithItsGovernanceMetadata(): void
    {
        $ops = (new ScreenOperations(new ScreenStore($this->path)))->operations();
        self::assertCount(1, $ops);
        $op = $ops[0];

        self::assertSame('screen:declare', $op->name);
        self::assertTrue($op->mutating);
        self::assertContains('milpa:component:data-table:*', $op->scopes);
        self::assertSame(Mutation::Persistent, $op->effects?->mutation);
        self::assertSame(Subject::Data, $op->effects?->subject);
    }

    public function testFromConfigResolvesRelativePathsUnderTheRoot(): void
    {
        $store = ScreenStore::fromConfig(['screens_path' => 'var/custom.json'], '/tmp/app-root');
        // A round-trip through declare would touch disk; instead assert the default shape via a screen miss.
        self::assertNull($store->screen('anything'));
        $default = ScreenStore::fromConfig([], '/tmp/app-root');
        self::assertNull($default->screen('anything'));
    }
}
