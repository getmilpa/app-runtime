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

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Web\Controllers\BoardDataController;
use Milpa\AppRuntime\Web\Controllers\BoardPageController;
use Milpa\Attributes\PluginMetadata;
use Milpa\EventStore\FileEventStore;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Plugin\Contracts\AppRoot;
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
 * Scope, on purpose (greenhouse evidence/0288): this slice makes the surface SERVABLE. Its live feed
 * (a SurfaceBroadcaster from config, not from editing boot.php) is the next slice — here the page is
 * a photograph the browser refreshes. The board only READS the stream, so it registers a read-only
 * SessionStore from the app root for  to fold. That the web container lacks the Kernel
 * and a PSR-17 factory the CLI has is a framework gap this surfaced, named and left for productizing.
 */
#[PluginMetadata(version: '0.1.0', author: 'Rodrigo Vicente - TeamX Agency', site: 'https://teamx.agency', name: 'Board', type: 'Web')]
final class BoardPlugin implements PluginInterface, RouteProviderInterface
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /** Register the read-only session store the board folds, and its controllers. */
    public function boot(): void
    {
        if (! $this->container->has(SessionStore::class)) {
            $root = $this->container->has(AppRoot::class) ? $this->container->get(AppRoot::class) : null;
            $base = $root instanceof AppRoot ? $root->path : (getcwd() ?: '.');
            $this->container->registerService(SessionStore::class, new SessionStore(new FileEventStore($base . '/var/agent-sessions.jsonl')));
        }
        $this->container->registerService(BoardPageController::class, new BoardPageController());
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
