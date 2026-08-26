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
use Milpa\AppRuntime\Agent\SurfaceBroadcaster;
use Milpa\AppRuntime\Web\BoardPlugin;
use Milpa\AppRuntime\Web\Controllers\BoardDataController;
use Milpa\AppRuntime\Web\Controllers\BoardPageController;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Http\Routing\Route;
use Milpa\Runtime\Config;
use Milpa\Command\Operation;
use Milpa\Command\OperationHttpPolicy;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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

    public function testBootRegistersTheBroadcasterAndControllersButNotItsOwnStore(): void
    {
        // A Surface names «realtime», so its transport must reach the container from config; but it
        // must NOT own a SessionStore. The store is resolved at request time by the operation layer
        // (AgentOperations::sessionStore, the same branch the CLI trusts), so a Surface stops
        // duplicating that resolution in boot() where the Kernel is not yet available
        // (greenhouse evidence/0292).
        $container = new DIContainer();
        $container->registerService(Config::class, new Config([
            'realtime' => ['transport' => 'log', 'path' => sys_get_temp_dir() . '/board-0292.log'],
        ]));

        (new BoardPlugin($container))->boot();

        self::assertTrue($container->has(SurfaceBroadcaster::class), 'the declared transport reaches the container');
        self::assertTrue($container->has(BoardPageController::class), 'the page controller is registered');
        self::assertTrue($container->has(BoardDataController::class), 'the data controller is registered');
        self::assertFalse($container->has(SessionStore::class), 'a Surface no longer owns its store — the operation layer resolves it at request time');
    }

    /**
     * THE SECOND DOOR JUDGES WITH THE SAME POLICY (greenhouse decisions/0082): /board/data used to call
     * agent:board's handler directly, so the OperationHttpPolicy the projector consults never saw it and
     * an anonymous caller read the fold (evidence/0318, 0319). Now the controller asks the container's
     * policy first — and without one it refuses: a door nobody judges is not a door.
     */
    public function testTheDataControllerFoldsTheSessionIntoColumnsWhenThePolicyAdmits(): void
    {
        $container = $this->containerWithASession();
        $container->registerService(OperationHttpPolicy::class, $this->policyThat(null));

        $r = (new BoardDataController($container))->data((new ServerRequest('GET', '/board/data?session=s1'))->withQueryParams(['session' => 's1']));

        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('text/html', $r->getHeaderLine('Content-Type'), 'the board is server-rendered by the Live component now');
        $html = (string) $r->getBody();
        self::assertStringContainsString('data-status="done"', $html);
        self::assertStringContainsString('plugins_list', $html, 'the finished cycle folded into the done column');
    }

    public function testTheDataControllerReturnsThePolicysRefusalUntouched(): void
    {
        $container = $this->containerWithASession();
        $container->registerService(OperationHttpPolicy::class, $this->policyThat(new Response(403, ['Content-Type' => 'application/json'], '{"error":"scope"}')));

        $r = (new BoardDataController($container))->data((new ServerRequest('GET', '/board/data?session=s1'))->withQueryParams(['session' => 's1']));

        self::assertSame(403, $r->getStatusCode());
        self::assertStringNotContainsString('plugins_list', (string) $r->getBody(), 'a refused caller sees no session fact');
    }

    public function testWithoutAPolicyTheDataControllerFailsClosed(): void
    {
        $container = $this->containerWithASession();

        $r = (new BoardDataController($container))->data((new ServerRequest('GET', '/board/data?session=s1'))->withQueryParams(['session' => 's1']));

        self::assertSame(401, $r->getStatusCode());
        $body = (string) $r->getBody();
        self::assertStringNotContainsString('plugins_list', $body, 'no session fact leaks through an unjudged door');
        self::assertStringContainsString('milpa/auth', $body, 'the refusal teaches what is missing');
    }

    private function containerWithASession(): DIContainer
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x');
        $store->recordTurn('s1', 'user', 'inspecciona');
        $store->recordToolCall('s1', 'plugins_list', [], 'ok');
        $store->recordTurn('s1', 'assistant', 'listo');
        $container = new DIContainer();
        $container->registerService(SessionStore::class, $store);

        return $container;
    }

    /** A policy whose verdict is fixed: null admits, a response refuses — the shape OperationHttpPolicy::enforce returns. */
    private function policyThat(?ResponseInterface $verdict): OperationHttpPolicy
    {
        return new class ($verdict) implements OperationHttpPolicy {
            public function __construct(private readonly ?ResponseInterface $verdict)
            {
            }

            public function enforce(Operation $op, ServerRequestInterface $request): ?ResponseInterface
            {
                return $this->verdict;
            }
        };
    }
}
