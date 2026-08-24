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
 * A second real-time-stream transport that is NOT a hub: it appends each broadcast fact as one JSON
 * line to a file (greenhouse evidence/0290).
 *
 * It exists to prove {@see RealtimeStreamFactory} is a PRIMITIVE, not a MercureBroadcaster in
 * disguise. A transport different in kind — no HTTP, no hub, no JWT — slots in as one factory arm and
 * this one class; nothing in a surface, in the agent's broadcast path, or in `BoardPlugin` changes. A
 * surface names «realtime»; the transport is chosen by config, and this proves the choice is real.
 */
final class LogBroadcaster implements SurfaceBroadcaster
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * Append one fact — its topic and already-translated payload — as a JSON line.
     *
     * @param array<string, mixed> $payload
     */
    public function broadcast(string $topic, array $payload): void
    {
        $dir = \dirname($this->path);
        if (! is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }
        file_put_contents(
            $this->path,
            json_encode(['topic' => $topic, 'payload' => $payload], \JSON_UNESCAPED_SLASHES) . "\n",
            \FILE_APPEND | \LOCK_EX,
        );
    }
}
