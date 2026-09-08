<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Support;

use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\Route;
use Milpa\Runtime\Http\RouteProviderInterface;
use Milpa\Runtime\Kernel;

/**
 * The route table of a booted app, WITH who declared each route — the one fold `routes:list` and the panel's
 * Routes section share (greenhouse decisions/0216, F4: two doors, one fact).
 *
 * The kernel's router holds the assembled table and has forgotten which plugin brought each route; so the
 * fold asks every booted plugin that provides routes for them again, the way the admin's section does, and
 * attributes each row. Rows are sorted by path, then method, so two readers print the same order.
 */
final class Routes
{
    /**
     * The table, one row per declared route, attributed to the plugin that declared it.
     *
     * @return list<array{method: string, path: string, name: string, handler: string, middleware: list<string>, plugin: string}>
     */
    public static function table(Kernel $kernel): array
    {
        $rows = [];
        foreach ($kernel->plugins() as $plugin) {
            if (!$plugin instanceof RouteProviderInterface) {
                continue;
            }
            foreach ($plugin->routes() as $route) {
                $rows[] = self::row($route, $plugin::class);
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]);

        return $rows;
    }

    /**
     * @return array{method: string, path: string, name: string, handler: string, middleware: list<string>, plugin: string}
     */
    private static function row(Route $route, string $plugin): array
    {
        $handler = $route->handler;

        return [
            'method' => implode(' ', array_map(static fn (HttpMethod $method): string => $method->value, $route->methods)),
            'path' => $route->path,
            'name' => (string) ($route->name ?? ''),
            'handler' => $handler === null ? '' : self::shortName($handler->controller) . '::' . $handler->method,
            'middleware' => array_map(self::shortName(...), array_values(array_filter($route->middleware, 'is_string'))),
            'plugin' => self::shortName($plugin),
        ];
    }

    private static function shortName(string $class): string
    {
        $slash = strrpos($class, '\\');

        return $slash === false ? $class : substr($class, $slash + 1);
    }
}
