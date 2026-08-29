<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\LayoutStateStore;
use Milpa\AppRuntime\Web\ScreenOperations;
use Milpa\AppRuntime\Web\ScreenStore;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use PHPUnit\Framework\TestCase;

/**
 * The one truth a layout's children share (greenhouse decisions/0169): server-authoritative, isolated per
 * session. What this fixes: a value written for one session/screen reads back only there, two sessions never
 * leak into each other, and the write declares a CHEAP effect profile — the contract decides the cost.
 */
final class LayoutStateStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/milpa-layout-' . bin2hex(random_bytes(6)) . '/var/layout-state.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
            @rmdir(\dirname($this->path));
            @rmdir(\dirname($this->path, 2));
        }
    }

    public function testAValueReadsBackOnlyForItsOwnSessionAndScreen(): void
    {
        $store = new LayoutStateStore($this->path);
        self::assertSame([], $store->values('A', 'equipo'));

        self::assertTrue($store->set('A', 'equipo', 'role', 'fundador')['ok']);
        self::assertSame(['role' => 'fundador'], $store->values('A', 'equipo'));
        self::assertSame([], $store->values('A', 'otra'));   // a different screen is untouched
    }

    public function testTwoSessionsNeverContaminateEachOther(): void
    {
        $store = new LayoutStateStore($this->path);
        $store->set('A', 'equipo', 'role', 'fundador');
        $store->set('B', 'equipo', 'role', 'agente');

        self::assertSame(['role' => 'fundador'], $store->values('A', 'equipo'));
        self::assertSame(['role' => 'agente'], $store->values('B', 'equipo'));
    }

    public function testAnIncompleteWriteIsRefused(): void
    {
        $store = new LayoutStateStore($this->path);
        self::assertFalse($store->set('', 'equipo', 'role', 'x')['ok']);
        self::assertFalse($store->set('A', 'equipo', '', 'x')['ok']);
        self::assertFileDoesNotExist($this->path);
    }

    public function testFromConfigResolvesRelativeAbsoluteAndDefaultPaths(): void
    {
        self::assertSame([], LayoutStateStore::fromConfig(['layout_state_path' => 'var/x.json'], '/tmp/app')->values('a', 'b'));
        self::assertSame([], LayoutStateStore::fromConfig(['layout_state_path' => '/tmp/milpa-abs.json'], '/tmp/app')->values('a', 'b'));
        self::assertSame([], LayoutStateStore::fromConfig([], '/tmp/app')->values('a', 'b'));
    }

    public function testValuesCoercesScalarsAndSkipsNonScalars(): void
    {
        @mkdir(\dirname($this->path), 0o755, true);
        file_put_contents($this->path, json_encode(['A' => ['equipo' => ['role' => 'x', 'n' => 5, 'bad' => ['nested']]]]));
        $store = new LayoutStateStore($this->path);

        self::assertSame(['role' => 'x', 'n' => '5'], $store->values('A', 'equipo'));   // scalar coerced, non-scalar skipped
        self::assertSame([], $store->values('missing', 'equipo'));                       // absent session → empty
    }

    public function testSetStateWithoutAWiredStoreIsANoOpThatSaysSo(): void
    {
        $ops = [];
        foreach ((new ScreenOperations(new ScreenStore($this->path . '.screens'), []))->operations() as $o) {
            $ops[$o->name] = $o;
        }
        $result = ($ops['screen:set-state']->handler)(['session' => 'A', 'screen' => 'x', 'key' => 'k', 'value' => 'v']);

        self::assertFalse($result['ok']);
    }

    public function testSetStateThroughTheOperationWritesTheValue(): void
    {
        $ops = [];
        foreach ((new ScreenOperations(new ScreenStore($this->path . '.screens'), [], new LayoutStateStore($this->path)))->operations() as $o) {
            $ops[$o->name] = $o;
        }
        $result = ($ops['screen:set-state']->handler)(['session' => 'A', 'screen' => 'equipo', 'key' => 'role', 'value' => 'fundador']);

        self::assertTrue($result['ok']);
        self::assertSame(['role' => 'fundador'], (new LayoutStateStore($this->path))->values('A', 'equipo'));
    }

    public function testSetStateGraduatesAsACheapEffectByItsContract(): void
    {
        $ops = [];
        foreach ((new ScreenOperations(new ScreenStore($this->path . '.screens'), [], new LayoutStateStore($this->path)))->operations() as $o) {
            $ops[$o->name] = $o;
        }
        $op = $ops['screen:set-state'];

        self::assertTrue($op->mutating);
        self::assertSame(Mutation::Ephemeral, $op->effects?->mutation);
        self::assertSame(Externality::None, $op->effects?->externality);
        self::assertSame(Reversibility::Guaranteed, $op->effects?->reversibility);
        self::assertSame(Authority::WriteAsUser, $op->effects?->authority);
    }
}
