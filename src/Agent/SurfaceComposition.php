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

namespace Milpa\AppRuntime\Agent;

use Milpa\Container\DIContainer;

/**
 * Composes every audience of one app's session facts into the single {@see SurfaceBroadcaster} the
 * bridge reads — the screen the chat opened AND whatever transport the app declared, fanned out.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────────────────────────
 *
 * A chat opens an {@see \Milpa\AppRuntime\Tui\AgentScreen}, which is itself a surface. An app may
 * ALSO declare a realtime transport — a Surface (e.g. the board) registers one from config during
 * plugin boot, which runs BEFORE this. The screen and that transport are audiences of the same
 * fact, not rivals for the channel: the bare screen must not displace the web board, and the web
 * board must not crash the chat.
 *
 * Before this, the chat looked ONLY for a Mercure hub and re-registered SurfaceBroadcaster::class —
 * so a transport a Surface had already registered (`websocket`, `log`, or a config-built Mercure)
 * collided: the container refuses to overwrite a service in silence, and a real `coa chat` with
 * `transport: websocket` died at boot (greenhouse evidence/0294). Here the already-declared
 * transport is READ back and joined as an audience, and the composition is written with
 * {@see DIContainer::replaceService()} — the deliberate «I am overriding, and I say so».
 */
final class SurfaceComposition
{
    /**
     * Register, under {@see SurfaceBroadcaster}, the screen fanned out with the app's declared transport.
     *
     * The screen is always an audience. A transport a Surface already declared from config joins it;
     * failing that, a bare Mercure hub the app wired directly is wrapped and joins. With no second
     * audience the screen stands alone — no needless fan-out wrapper.
     */
    public static function compose(DIContainer $container, SurfaceBroadcaster $screen): void
    {
        $audiences = [$screen];

        if ($container->has(SurfaceBroadcaster::class)) {
            $declared = $container->get(SurfaceBroadcaster::class);
            if ($declared instanceof SurfaceBroadcaster && $declared !== $screen) {
                $audiences[] = $declared;
            }
        } elseif ($container->has('Milpa\\Mercure\\MercureService')) {
            $hub = $container->get('Milpa\\Mercure\\MercureService');
            if (\is_object($hub) && method_exists($hub, 'publish')) {
                $audiences[] = new MercureBroadcaster($hub);
            }
        }

        $container->replaceService(
            SurfaceBroadcaster::class,
            \count($audiences) === 1 ? $screen : new FanOutBroadcaster($audiences),
        );
    }
}
