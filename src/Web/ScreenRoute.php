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

/**
 * The route a declared screen is mounted at (greenhouse decisions/0567 §3, slice BV-1).
 *
 * A LITERAL GET PATH, as HTTP writes it: `/blog`, `/feed.xml`, `/`. No parameters, no query and no fragment — a
 * screen is one page, and the house can only observe a route it can request as written (decisions/0555 reads a
 * goal's `GET /blog` the same way). A trailing slash is the same route, and two routes that differ only in case
 * are one: the house records `blog` and `/blog` for the same page.
 */
final class ScreenRoute
{
    /** The prefix of the name the live door gives a mounted screen's route: `live.screen.<screen>`. */
    public const NAME_PREFIX = 'live.screen.';

    /**
     * The route as the store keeps it, or a refusal that names what is wrong with it.
     *
     * @throws InvalidScreenTree at path `route`
     */
    public static function parse(mixed $route): string
    {
        if (! \is_string($route) || ! str_starts_with($route = trim($route), '/')) {
            throw new InvalidScreenTree('route', 'a route starts with /, as HTTP writes it: /<path>');
        }
        if (str_contains($route, '{') || str_contains($route, '}')) {
            throw new InvalidScreenTree('route', 'a screen is one page: its route takes no parameters');
        }
        if (str_contains($route, '?')) {
            throw new InvalidScreenTree('route', 'a route is a path: no query');
        }
        if (preg_match('~^[A-Za-z0-9._\~/-]+$~D', $route) !== 1) {
            throw new InvalidScreenTree('route', 'a route is written with only letters, digits, and . _ ~ - between its slashes');
        }
        $route = rtrim($route, '/');
        if (str_contains($route, '//')) {
            throw new InvalidScreenTree('route', 'a route has no empty segment');
        }

        return $route === '' ? '/' : $route;
    }

    /** What two spellings of one route share: no slash at either end, no case. */
    public static function key(string $path): string
    {
        return strtolower(trim($path, '/'));
    }

    /**
     * Whether a route the house already declares would answer this literal path: the same path, or a
     * parameterised one (`/posts/{id}`) whose shape it fits.
     */
    public static function answers(string $declared, string $route): bool
    {
        if (! str_contains($declared, '{')) {
            return self::key($declared) === self::key($route);
        }
        $pattern = preg_replace('~\\\\\{[^/]*?\\\\\}~', '[^/]+', preg_quote(self::key($declared), '~'));

        return preg_match('~^' . $pattern . '$~D', self::key($route)) === 1;
    }
}
