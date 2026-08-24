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

namespace Milpa\AppRuntime\Web;

use Milpa\AppRuntime\Agent\RealtimeStreamFactory;
use Milpa\AppRuntime\Agent\SurfaceBroadcaster;
use Milpa\AppRuntime\Web\Controllers\BoardDataController;
use Milpa\AppRuntime\Web\Controllers\BoardPageController;
use Milpa\Runtime\Config;
use Milpa\Attributes\PluginMetadata;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Runtime\Http\RouteProviderInterface;

/**
 * The board as a DECLARED SURFACE, served by the framework — no hand-made front controller.
 *
 * An Operation declares what can be DONE; a Surface declares what can be SEEN. This plugin is the
 * proof that a surface is a capability: it declares two bound routes (the page and its data) via
 * {@see RouteProviderInterface}, and the kernel serves them the same way it would any plugin's —
 * the framework does not know «board», it knows «a plugin contributed routes». An app enables Board
 * (adds this class to config/plugins.php) and a servable board appears; nothing is wired by hand.
 *
 * A Surface does not own its store (greenhouse evidence/0292). It declares its transport — the
 * SurfaceBroadcaster built from config — and its controllers; the SessionStore both the agent WRITES
 * and the board READS is resolved at REQUEST time by the operation layer (AgentOperations, the same
 * branch the CLI trusts), where the Kernel that names the app root is in the container. boot() runs
 * before that Kernel exists, so resolving a store here would only duplicate — and risk diverging
 * from — the one resolution the framework already owns.
 */
#[PluginMetadata(version: '0.1.0', author: 'Rodrigo Vicente - TeamX Agency', site: 'https://teamx.agency', name: 'Board', type: 'Web')]
final class BoardPlugin implements PluginInterface, RouteProviderInterface
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /** Build the realtime stream and the session store from config, and register the controllers. */
    public function boot(): void
    {
        // The board's live feed comes from CONFIG, not from editing boot.php (greenhouse evidence/0289):
        // read the app's declared realtime stream and build the neutral SurfaceBroadcaster from it.
        // Board names «realtime»; only RealtimeStreamFactory names a transport. This registration is
        // load-bearing in BOTH containers: it is how the CLI agent's writes reach a transport too
        // (AgentOperations bridges the store with whatever SurfaceBroadcaster is registered).
        $realtime = $this->container->has(Config::class) ? $this->container->get(Config::class)->get('realtime') : null;
        $broadcaster = \is_array($realtime) ? RealtimeStreamFactory::fromConfig($realtime) : null;
        if ($broadcaster !== null && ! $this->container->has(SurfaceBroadcaster::class)) {
            $this->container->registerService(SurfaceBroadcaster::class, $broadcaster);
        }

        $this->container->registerService(BoardPageController::class, new BoardPageController(RealtimeStreamFactory::publicUrlFromConfig($realtime)));
        $this->container->registerService(BoardDataController::class, new BoardDataController($this->container));
    }


    /** Board keeps no persistent state of its own; installing it does nothing. */
    public function install(): void
    {
    }

    /** Nothing to tear down: the board owns no storage. */
    public function uninstall(): void
    {
    }

    /** Enabling contributes the routes; there is no other switch to flip. */
    public function enable(): void
    {
    }

    /** Disabling withdraws the routes with the plugin; nothing else to undo. */
    public function disable(): void
    {
    }

    /**
     * The board's surface: the page and its data, each bound to a controller.
     *
     * @return list<Route>
     */
    public function routes(): array
    {
        return [
            new Route(path: '/board', methods: HttpMethod::GET, name: 'board', handler: new HandlerReference(BoardPageController::class, 'show')),
            new Route(path: '/board/data', methods: HttpMethod::GET, name: 'board.data', handler: new HandlerReference(BoardDataController::class, 'data')),
        ];
    }
}
