<?php

/**
 * The operations a session scaffolded and has not written yet, read from its own record.
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionStore;
use Milpa\EventStore\Event;

/**
 * What a session left half done that the house can tell from its record alone (an experiment of greenhouse
 * decisions/0604 — held, not decided).
 *
 * Scaffolding an operation makes a class whose body is still to write; writing it is another call. A leg that
 * inherits a fold is told the session's own plan and todos, and of a past call a few names — never that a scaffold
 * was left without its body. So the leg reads the house again to find out (evidence/1164: 15 of 31 reads in the legs
 * after one that ran out of window were of something already read).
 *
 * This reads ONLY the session's recorded calls: a successful scaffold of an operation, and whether a successful
 * authoring call on that plugin and class came after it. It does not read the house, and it claims nothing it cannot
 * know from there: a scaffold whose trial was discarded is still listed, and a class written by another session or
 * by hand is too. It is an observation for the run context, never an instruction and never a permission.
 *
 * TWO LIMITS, the second one measured. An authoring call the house accepted counts as written even when its trial
 * never landed: this does not read whether it was promoted. And the run context is a snapshot of the START of an
 * invocation: in the one run a real resident made with it, the list arrived once, was true, and then went stale
 * inside the leg — the prompt still said four when one had been written.
 */
final class ScaffoldsNotWritten
{
    /** The calls that write the body of a class. */
    private const WRITES = ['implement', 'edit'];

    /**
     * The operations this session scaffolded and has no later accepted authoring call for.
     *
     * @param list<Event> $events any events; only this session's recorded calls are read
     *
     * @return list<array{plugin: string, class: string, scaffolded_at: int}> in the order they were scaffolded
     */
    public static function of(array $events, string $session): array
    {
        $open = [];
        foreach ($events as $event) {
            if ($event->streamId !== SessionStore::PREFIX . $session || $event->type !== 'session.tool_called' || ($event->payload['ok'] ?? null) !== true) {
                continue;
            }
            $tool = $event->payload['tool'] ?? null;
            $arguments = \is_array($event->payload['arguments'] ?? null) ? $event->payload['arguments'] : [];
            $plugin = $arguments['plugin'] ?? null;
            $class = $tool === 'make' ? ($arguments['name'] ?? null) : ($arguments['class'] ?? null);
            if (!\is_string($plugin) || !\is_string($class)) {
                continue;
            }
            $key = $plugin . "\0" . $class;
            if ($tool === 'make' && ($arguments['what'] ?? null) === 'operation') {
                // Scaffolded again after being written, it is a scaffold again: last in, in its new place.
                unset($open[$key]);
                $open[$key] = ['plugin' => $plugin, 'class' => $class, 'scaffolded_at' => $event->seq];
            } elseif (\in_array($tool, self::WRITES, true)) {
                unset($open[$key]);
            }
        }

        return array_values($open);
    }
}
