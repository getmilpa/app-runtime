<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Identity\EnrollmentLine;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\EventStore\Event;
use Milpa\ToolRuntime\Contracts\ToolContext;

/**
 * The frontier of a seat: the refusals a human who answers for it can decide (greenhouse decisions/0493).
 *
 * A missing scope stays a refusal (decisions/0317): the seat's call is refused, and nothing is asked. What
 * this reads is who may SEE that refusal and decide it. The seat of a session is the verified key that
 * opened it (`session.started.by`); the human answers for it through the enrollment ledger
 * ({@see EnrollmentLine}). A refusal is OPEN while the authoring policy, asked again with the scopes the
 * seat holds now, still names a permission for the recorded call — the text of the refusal is never read.
 *
 * A refusal for one plugin's write scope is OFFERED only when that plugin is a real or an intended target
 * (decisions/0496): the house already has it, or the standing ask — the session's goal and the human's
 * turns — names it as a whole identifier. A name the model made up stays a refusal in the stream; it
 * never becomes a Grant button.
 */
final class SeatFrontier
{
    private readonly EnrollmentLine $line;

    public function __construct(
        private readonly SessionStore $sessions,
        private readonly FileEnrollmentStore $enrollments,
        private readonly PluginAuthoringPolicy $policy,
    ) {
        $this->line = new EnrollmentLine($enrollments);
    }

    /** Build the frontier over an app root's ledger, its policy and the session store it was handed. */
    public static function forRoot(string $root, SessionStore $sessions): self
    {
        return new self(
            $sessions,
            new FileEnrollmentStore($root . '/storage/identity/enrollments.json'),
            new PluginAuthoringPolicy($root),
        );
    }

    /**
     * The seat's fingerprint for a session — the verified key that opened it, live in the ledger — or null.
     */
    public function seatOf(string $session): ?string
    {
        return $this->seatIn($this->sessions->stream($session));
    }

    /** Whether this principal answers for the session's seat. */
    public function answersFor(string $principal, string $session): bool
    {
        $seat = $this->seatOf($session);

        return $seat !== null && $this->line->answersFor($principal, $seat);
    }

    /**
     * The sessions whose seat this principal answers for, each with its open refusals.
     *
     * @return list<array{session: string, goal: string, seat: string, refusals: list<array{seq: int, tool: string, plugin: ?string, permission: string}>}>
     */
    public function sessionsFor(string $principal): array
    {
        $out = [];
        foreach ($this->sessions->ids() as $id) {
            $events = $this->sessions->stream($id);
            $seat = $this->seatIn($events);
            if ($seat === null || !$this->line->answersFor($principal, $seat)) {
                continue;
            }
            $out[] = [
                'session' => $id,
                'goal' => self::goalIn($events),
                'seat' => 'key:' . $seat,
                'refusals' => $this->refusalsIn($events, $seat),
            ];
        }

        return $out;
    }

    /**
     * The session's open refusals, or `[]` when it has no seat.
     *
     * @return list<array{seq: int, tool: string, plugin: ?string, permission: string}>
     */
    public function openRefusals(string $session): array
    {
        $events = $this->sessions->stream($session);
        $seat = $this->seatIn($events);

        return $seat === null ? [] : $this->refusalsIn($events, $seat);
    }

    /**
     * One open refusal by its sequence number, with the seat it belongs to — or null when it is not open.
     *
     * @return array{seq: int, tool: string, plugin: ?string, permission: string, seat: string}|null
     */
    public function refusal(string $session, int $seq): ?array
    {
        $events = $this->sessions->stream($session);
        $seat = $this->seatIn($events);
        if ($seat === null) {
            return null;
        }
        $standing = self::standingAskIn($events);
        foreach ($events as $event) {
            if ($event->seq !== $seq) {
                continue;
            }
            $refusal = $this->judge($event, $seat, $standing);

            return $refusal === null ? null : $refusal + ['seat' => $seat];
        }

        return null;
    }

    /** @param list<Event> $events */
    private function seatIn(array $events): ?string
    {
        foreach ($events as $event) {
            if ($event->type !== 'session.started') {
                continue;
            }
            $by = \is_array($event->payload['by'] ?? null) ? $event->payload['by'] : [];
            $id = \is_string($by['id'] ?? null) ? $by['id'] : '';
            if (($by['verified'] ?? false) !== true || !str_starts_with($id, 'key:')) {
                return null;
            }
            $seat = substr($id, 4);

            return $seat !== '' && $this->enrollments->scopesFor($seat) !== null ? $seat : null;
        }

        return null;
    }

    /**
     * @param list<Event> $events
     *
     * @return list<array{seq: int, tool: string, plugin: ?string, permission: string}>
     */
    private function refusalsIn(array $events, string $seat): array
    {
        $open = [];
        $seen = [];
        $standing = self::standingAskIn($events);
        foreach ($events as $event) {
            $refusal = $this->judge($event, $seat, $standing);
            if ($refusal === null) {
                continue;
            }
            // One row per missing permission and call shape: the model retries a refused call, and the
            // human decides the scope once, not once per retry.
            $key = $refusal['permission'] . "\0" . $refusal['tool'] . "\0" . ($refusal['plugin'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $open[] = $refusal;
        }

        return $open;
    }

    /** @return array{seq: int, tool: string, plugin: ?string, permission: string}|null */
    private function judge(Event $event, string $seat, string $standing): ?array
    {
        $payload = $event->payload;
        if ($event->type !== 'session.tool_called' || ($payload['ok'] ?? null) !== false || !\is_string($payload['tool'] ?? null)) {
            return null;
        }
        $arguments = \is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [];
        $scopes = $this->enrollments->scopesFor($seat) ?? [];
        $missing = $this->policy->missing(new ToolContext('key:' . $seat, 'cli', $scopes), $payload['tool'], $arguments);
        if ($missing === null) {
            return null;
        }
        if ($missing->plugin !== null && !$this->policy->pluginExists($missing->plugin) && !self::names($standing, $missing->plugin)) {
            return null;
        }

        return [
            'seq' => $event->seq,
            'tool' => $payload['tool'],
            'plugin' => \is_string($arguments['plugin'] ?? null) ? $arguments['plugin'] : null,
            'permission' => $missing->permission,
        ];
    }

    /**
     * Whether the standing ask names this plugin as a whole identifier, ignoring case.
     *
     * Stricter than the `target_not_named` gate (decisions/0009), which only relaxes a question and so
     * accepts a substring: a grant is authority over one identifier, so «a plugin named Blog» names
     * `Blog` and `blog`, but neither `BlogPlugin` nor `log`. Ignoring case has a named cost: a common
     * word of the goal names itself, so that sentence also names `Plugin`.
     */
    private static function names(string $standing, string $plugin): bool
    {
        return preg_match('/(?<![A-Za-z0-9_])' . preg_quote($plugin, '/') . '(?![A-Za-z0-9_])/iu', $standing) === 1;
    }

    /**
     * The standing ask: the session's current goal and every turn the human wrote into it.
     *
     * @param list<Event> $events
     */
    private static function standingAskIn(array $events): string
    {
        $ask = [self::goalIn($events)];
        foreach ($events as $event) {
            if ($event->type === 'session.turn' && ($event->payload['role'] ?? null) === 'user' && \is_string($event->payload['content'] ?? null)) {
                $ask[] = $event->payload['content'];
            }
        }

        return implode("\n", $ask);
    }

    /** @param list<Event> $events */
    private static function goalIn(array $events): string
    {
        $goal = '';
        foreach ($events as $event) {
            if (\in_array($event->type, ['session.started', 'session.goal_changed'], true) && \is_string($event->payload['goal'] ?? null)) {
                $goal = $event->payload['goal'];
            }
        }

        return $goal;
    }
}
