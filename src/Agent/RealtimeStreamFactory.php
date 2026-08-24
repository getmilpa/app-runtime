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

/**
 * Builds a {@see SurfaceBroadcaster} — the neutral real-time-stream contract — from an app's declared
 * configuration, so a surface gets its live feed BY CONFIG, not by editing boot.php (greenhouse
 * evidence/0289).
 *
 * This is the one place that knows a transport by name: a caller (a surface, the framework) hands it
 * the app's realtime config and gets back the contract, or null. A surface never names Mercure — it
 * names «realtime»; the mapping from a declared transport to its adapter lives here, so adding a
 * transport is a new arm here, not a change anywhere a surface reads.
 */
final class RealtimeStreamFactory
{
    /**
     * The broadcaster an app's realtime config declares, or null if it declares none this runtime can build.
     *
     * Expected shape: `['transport' => 'mercure', 'hub' => <server-side URL>, 'public' => <browser URL>,
     * 'key' => <shared JWT secret>]`. The `public` URL defaults to `hub`.
     *
     * @param array<string, mixed> $config the value of the app's realtime configuration key
     */
    public static function fromConfig(array $config): ?SurfaceBroadcaster
    {
        $transport = \is_string($config['transport'] ?? null) ? $config['transport'] : '';

        // A second transport, different in kind (greenhouse evidence/0290): a new arm here and its
        // broadcaster class are all it takes — no surface, agent, or BoardPlugin changes. That is what
        // makes this a primitive rather than a MercureBroadcaster wearing a config.
        if ($transport === 'log') {
            $path = \is_string($config['path'] ?? null) ? $config['path'] : '';

            return $path === '' ? null : new LogBroadcaster($path);
        }

        // A browser-live transport with a different protocol (greenhouse evidence/0291): the agent
        // POSTs to a relay, the browser subscribes over WebSocket. Another arm, another broadcaster.
        if ($transport === 'websocket') {
            $publish = \is_string($config['publish'] ?? null) ? $config['publish'] : '';

            return $publish === '' ? null : new WebsocketBroadcaster($publish);
        }

        if ($transport === 'mercure' && class_exists('Milpa\\Mercure\\MercureService')) {
            $hub = \is_string($config['hub'] ?? null) ? $config['hub'] : '';
            $public = \is_string($config['public'] ?? null) ? $config['public'] : $hub;
            $key = \is_string($config['key'] ?? null) ? $config['key'] : '';
            if ($hub === '' || $key === '') {
                return null;
            }
            $service = new \Milpa\Mercure\MercureService($hub, $public, $key, $key);

            return new MercureBroadcaster($service);
        }

        return null;
    }

    /** The browser-reachable hub URL an app's realtime config declares, for a page to subscribe to. */
    public static function publicUrlFromConfig(mixed $config): ?string
    {
        if (! \is_array($config)) {
            return null;
        }
        $public = $config['public'] ?? $config['hub'] ?? null;

        return \is_string($public) && $public !== '' ? $public : null;
    }
}
