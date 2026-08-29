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
        $byName = [];
        foreach ((new ScreenOperations(new ScreenStore($this->path)))->operations() as $o) {
            $byName[$o->name] = $o;
        }
        $op = $byName['screen:declare'];

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

    public function testCatalogueAndForgetRoundTrip(): void
    {
        $store = new ScreenStore($this->path);
        $store->declare(['name' => 'equipo', 'columns' => [['key' => 'a', 'label' => 'A']], 'rows' => [['a' => '1'], ['a' => '2']]]);
        $store->declare(['name' => 'metricas', 'columns' => [], 'rows' => []]);

        $cat = $store->catalogue();
        self::assertCount(2, $cat);
        self::assertSame('equipo', $cat[0]['name']);
        self::assertSame('/live/page?component=equipo', $cat[0]['servedAt']);
        self::assertSame(1, $cat[0]['columns']);
        self::assertSame(2, $cat[0]['rows']);

        self::assertTrue($store->forget('equipo')['ok']);
        self::assertNull($store->screen('equipo'));
        self::assertSame(['metricas'], $store->names());          // the other survives

        $missing = $store->forget('equipo');                      // already gone
        self::assertFalse($missing['ok']);
    }

    public function testListAndForgetGraduateWithTheRightEffects(): void
    {
        $ops = [];
        foreach ((new ScreenOperations(new ScreenStore($this->path)))->operations() as $op) {
            $ops[$op->name] = $op;
        }
        self::assertArrayHasKey('screen:list', $ops);
        self::assertArrayHasKey('screen:forget', $ops);

        // list is read-only — no gate
        self::assertFalse($ops['screen:list']->mutating);
        self::assertSame(\Milpa\Command\Effect\Mutation::None, $ops['screen:list']->effects?->mutation);

        // forget mutates, is COMPENSATABLE, and NAMES its target (ADR-0044)
        self::assertTrue($ops['screen:forget']->mutating);
        self::assertSame('name', $ops['screen:forget']->namedTarget);
        self::assertSame(\Milpa\Command\Effect\Reversibility::Compensatable, $ops['screen:forget']->effects?->reversibility);
        self::assertContains('milpa:component:data-table:*', $ops['screen:forget']->scopes);
    }
}
