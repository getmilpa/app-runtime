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

namespace Milpa\AppRuntime\Agent;

/**
 * One fact, every watching surface.
 *
 * ── THE DEFECT THIS EXISTS TO KILL ───────────────────────────────────────────────────────────────
 *
 * Found by a human watching the browser, not by a suite: the chat TUI registered ITSELF as the
 * session's `SurfaceBroadcaster`, and since the bridge takes the first broadcaster it finds, the
 * Mercure hub stopped receiving anything the moment a session ran inside the chat. The web board
 * stood still with a «live» face until someone reloaded — the exact transition the board exists to
 * show, lost to whoever was watching it.
 *
 * The bridge is ONE; the audiences are MANY. Registering a surface must never displace another.
 *
 * ── DELIVERY IS PER-SURFACE, AND FAILURE STILL SURFACES ──────────────────────────────────────────
 *
 * A dead hub must not blind the terminal, nor a closed terminal the hub: every surface is delivered
 * to even when another throws. The FIRST failure is rethrown after the round so the bridge's
 * warning fires — swallowing it would turn a hub outage into a board that simply stops moving,
 * with nobody able to tell why. That is the exact silence {@see BroadcastingEventStore} refuses.
 */
final readonly class FanOutBroadcaster implements SurfaceBroadcaster
{
    /** @param list<SurfaceBroadcaster> $surfaces every audience of this app's sessions, in order */
    public function __construct(private array $surfaces)
    {
    }

    /** Deliver one fact to every surface, then rethrow the first failure so the bridge logs it. */
    public function broadcast(string $topic, array $payload): void
    {
        $primera = null;
        foreach ($this->surfaces as $superficie) {
            try {
                $superficie->broadcast($topic, $payload);
            } catch (\Throwable $e) {
                $primera ??= $e;
            }
        }

        if ($primera !== null) {
            throw $primera;
        }
    }
}
