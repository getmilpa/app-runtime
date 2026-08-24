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
 * A browser-live second transport that is NOT a Mercure hub (greenhouse evidence/0291): it POSTs each
 * broadcast fact to a WebSocket relay, which pushes it to the browsers subscribed on that topic.
 *
 * Where {@see LogBroadcaster} proved a transport different in kind slots in as one factory arm, this
 * proves a transport different in LIVE PROTOCOL does too: the agent's broadcast path is unchanged, and
 * only the browser subscribe (WebSocket vs SSE) differs — chosen by the URL scheme the config declares,
 * on the client. Server-side, a surface still names «realtime», never a protocol.
 */
final class WebsocketBroadcaster implements SurfaceBroadcaster
{
    public function __construct(private readonly string $publishUrl)
    {
    }

    /**
     * POST one fact — topic and already-translated payload — to the relay; best-effort, never fatal.
     *
     * @param array<string, mixed> $payload
     */
    public function broadcast(string $topic, array $payload): void
    {
        $handle = curl_init($this->publishUrl);
        if ($handle === false) {
            return;
        }
        curl_setopt_array($handle, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => (string) json_encode(['topic' => $topic, 'payload' => $payload], \JSON_UNESCAPED_SLASHES),
            \CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => 2,
        ]);
        curl_exec($handle);
        curl_close($handle);
    }
}
