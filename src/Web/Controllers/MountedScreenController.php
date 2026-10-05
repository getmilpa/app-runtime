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

namespace Milpa\AppRuntime\Web\Controllers;

use Milpa\AppRuntime\Web\ScreenRoute;
use Milpa\AppRuntime\Web\ScreenStore;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves a declared screen at the route its declaration names (greenhouse decisions/0567 §3, slice BV-1).
 *
 * One page, two addresses: `GET /blog` answers what `GET /live/page?component=blog` answers, through the same
 * page controller — the same props, the same visibility, the same look. This class adds nothing to the page;
 * it only says WHICH screen a path is, and it reads that from the declarations on every request, so a screen
 * mounted a moment ago is served without a deploy.
 *
 * The screen is the one mounted at the path, never one the visitor names: a `component` in the query string is
 * replaced, so a route is not a second door to every other screen of the house.
 */
final class MountedScreenController
{
    public function __construct(
        private readonly ScreenStore $screens,
        private readonly LiveComponentPageController $page,
    ) {
    }

    /**
     * The page of the screen mounted at the requested path, or 404 when none is.
     */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $mounted = [];
        foreach ($this->screens->mounts() as $route => $screen) {
            $mounted[ScreenRoute::key($route)] = $screen;
        }
        $screen = $mounted[ScreenRoute::key($request->getUri()->getPath())] ?? null;
        if ($screen === null) {
            return new Response(
                404,
                ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
                (string) json_encode(['error' => 'no_screen_mounted_here', 'message' => 'No declared screen is mounted at this path.']),
            );
        }

        return $this->page->show($request->withQueryParams(['component' => $screen] + $request->getQueryParams()));
    }
}
