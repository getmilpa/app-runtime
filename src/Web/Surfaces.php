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
 * The pages this house serves from a declaration, as `house:context` answers them (greenhouse decisions/0579 §5).
 *
 * `house:context` listed the paths of the route table and nothing about what answers there. Which of them a declared
 * screen serves is written in the declarations the house already reads, so it can say so without asking any route.
 *
 * A page written by hand is NOT here, and that is on purpose: the house learns of one only when it observes it
 * answer `text/html` (its `served` receipt then says `surface: {kind: visual, screen: null}`). Listing them all would
 * mean requesting every route of the house on every session.
 */
final class Surfaces
{
    /** How a page a visitor reads is made in this house, in the one line a reader needs before choosing how to make one. */
    public const string CONVENTION = 'a page a visitor reads is a screen mounted at a route — make what=page';

    /**
     * The mounted screens, by route, each with the entity whose public rows it lists when it lists one.
     *
     * @return array{pages: list<array{route: string, screen: string, type: string, lists?: string}>, convention: string}
     */
    public static function declared(ScreenStore $screens): array
    {
        $pages = [];
        try {
            $mounts = $screens->mounts();
            ksort($mounts);
            foreach ($mounts as $route => $name) {
                $screen = $screens->screen($name);
                $entity = \is_array($screen) && \is_array($screen['props']['source'] ?? null) ? ($screen['props']['source']['entity'] ?? null) : null;
                $pages[] = ['route' => (string) $route, 'screen' => $name, 'type' => \is_array($screen) ? (string) $screen['type'] : '']
                    + (\is_string($entity) ? ['lists' => ListedContent::named($entity)] : []);
            }
        } catch (\Throwable) {
            // Declarations the house cannot read declare no page: the convention is still true.
            $pages = [];
        }

        return ['pages' => $pages, 'convention' => self::CONVENTION];
    }
}
