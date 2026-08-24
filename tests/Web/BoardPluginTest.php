<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Web\BoardPlugin;
use Milpa\AppRuntime\Web\Controllers\BoardDataController;
use Milpa\AppRuntime\Web\Controllers\BoardPageController;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Http\Routing\Route;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The board is a DECLARED SURFACE served by the framework (greenhouse evidence/0288): the plugin
 * contributes bound routes, and its controllers render the real page and fold the real board — no
 * hand-made front controller. That another plugin can declare another surface the same way is what
 * makes this a primitive, not a board-shaped special case; that control lives on cattle.
 */
final class BoardPluginTest extends TestCase
{
    public function testItDeclaresTwoBoundRoutesForTheSurface(): void
    {
        $routes = (new BoardPlugin(new DIContainer()))->routes();

        self::assertCount(2, $routes);
        $paths = array_map(static fn (Route $r): string => $r->path, $routes);
        self::assertSame(['/board', '/board/data'], $paths, 'the page and its data');
        foreach ($routes as $route) {
            self::assertNotNull($route->handler, 'every route is bound — the kernel scans no attributes');
        }
    }

    public function testThePageControllerRendersTheRealBoardForItsSession(): void
    {
        $r = (new BoardPageController())->show((new ServerRequest('GET', '/board?session=s1'))->withQueryParams(['session' => 's1']));

        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('text/html', $r->getHeaderLine('Content-Type'));
        self::assertStringContainsString('agent board', (string) $r->getBody(), 'it is the real BoardPage');
    }

    public function testTheDataControllerFoldsTheSessionIntoColumns(): void
    {
        $eventos = new InMemoryEventStore();
        $store = new SessionStore($eventos);
        $store->start('s1', 'x');
        $store->recordTurn('s1', 'user', 'inspecciona');
        $store->recordToolCall('s1', 'plugins_list', [], 'ok');
        $store->recordTurn('s1', 'assistant', 'listo');
        $container = new DIContainer();
        $container->registerService(SessionStore::class, $store);

        $r = (new BoardDataController($container))->data((new ServerRequest('GET', '/board/data?session=s1'))->withQueryParams(['session' => 's1']));

        self::assertSame(200, $r->getStatusCode());
        $data = json_decode((string) $r->getBody(), true);
        self::assertTrue($data['ok'] ?? false, (string) $r->getBody());
        self::assertSame('plugins_list', $data['columns']['done'][0]['text'] ?? null, 'the finished cycle folded into done');
    }
}
