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
 *
 * Each offered refusal says what granting OPENS (decisions/0510): the recorded call itself, whether its
 * plugin is `new` or `existing` in the house, and whether the standing ask names it. A grant over an
 * existing plugin opens write over that plugin's whole work, not only the refused call, so it is never
 * one touch: its consent is `informed`, and the grant must repeat the plugin's name inside what the
 * passkey or the signature approves. The call's text is shown, never classified: in evidence/1043 the
 * call a reading took for «empties the plugin» was refused by its own handler. And a refusal the seat
 * moved on from — a later run worked and ended without retrying it — is no longer offered.
 *
 * @phpstan-type Refusal array{seq: int, tool: string, plugin: ?string, permission: string, call: array<string, string|int|float|bool|null>, target: 'new'|'existing'|null, named: bool, consent: 'touch'|'informed'}
 * @phpstan-type SeatRefusal array{seq: int, tool: string, plugin: ?string, permission: string, call: array<string, string|int|float|bool|null>, target: 'new'|'existing'|null, named: bool, consent: 'touch'|'informed', seat: string}
 */
final class SeatFrontier
{
    /** How the house's own turns begin (decisions/0495): a fact it recorded, never the start of a run. */
    public const NOTICE_PREFIX = '[house] ';

    /** How much of one argument's text a card shows before it says the rest's size. */
    private const SHOWN_BYTES = 120;

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
        // The opening event alone (greenhouse decisions/0517): the line check asks this of every session it judges.
        $opening = $this->sessions->opening($session);

        return $opening === null ? null : $this->seatIn([$opening]);
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
     * @return list<array{session: string, goal: string, seat: string, refusals: list<Refusal>}>
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
     * @return list<Refusal>
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
     * Open means offered: the call's shape is one the frontier shows — any retry of it names the same scope —
     * so a refusal the seat moved on from is not open here either (decisions/0510).
     *
     * @return SeatRefusal|null
     */
    public function refusal(string $session, int $seq): ?array
    {
        $events = $this->sessions->stream($session);
        $seat = $this->seatIn($events);
        if ($seat === null) {
            return null;
        }
        $offered = [];
        foreach ($this->refusalsIn($events, $seat) as $open) {
            $offered[self::shape($open)] = true;
        }
        $standing = self::standingAskIn($events);
        foreach ($events as $event) {
            if ($event->seq !== $seq) {
                continue;
            }
            // Any retry of an offered call shape names the same scope; one the seat moved on from names none.
            $refusal = $this->judge($event, $seat, $standing);

            return $refusal === null || !isset($offered[self::shape($refusal)]) ? null : $refusal + ['seat' => $seat];
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
     * @return list<Refusal>
     */
    private function refusalsIn(array $events, string $seat): array
    {
        $latest = [];
        $standing = self::standingAskIn($events);
        foreach ($events as $event) {
            $refusal = $this->judge($event, $seat, $standing);
            if ($refusal === null) {
                continue;
            }
            // One row per missing permission and call shape: the model retries a refused call, and the
            // human decides the scope once, not once per retry — on the LATEST retry, the one the seat
            // is still asking for (decisions/0510).
            $key = self::shape($refusal);
            unset($latest[$key]);
            $latest[$key] = $refusal;
        }

        $open = [];
        foreach ($latest as $refusal) {
            if (!self::movedOn($events, $refusal['seq'])) {
                $open[] = $refusal;
            }
        }

        return $open;
    }

    /**
     * A refusal's call shape: the missing permission, the tool and the plugin — what a retry repeats.
     *
     * @param Refusal $refusal
     */
    private static function shape(array $refusal): string
    {
        return $refusal['permission'] . "\0" . $refusal['tool'] . "\0" . ($refusal['plugin'] ?? '');
    }

    /**
     * Whether the seat moved on from a refusal: after the run that recorded it, a later run began, the model
     * worked in it, and it ended — by `session.run_terminated` or by the next turn — without the same call
     * shape being refused again (a retry is a newer row, so it never reaches here). Staleness is measured in
     * the seat's work, never in minutes (decisions/0510).
     *
     * @param list<Event> $events
     */
    private static function movedOn(array $events, int $seq): bool
    {
        $phase = 0;
        foreach ($events as $event) {
            if ($event->seq <= $seq) {
                continue;
            }
            $content = $event->payload['content'] ?? null;
            $turn = $event->type === 'session.turn' && ($event->payload['role'] ?? null) === 'user'
                && !(\is_string($content) && str_starts_with($content, self::NOTICE_PREFIX));
            if ($phase === 0 && $turn) {
                $phase = 1;
            } elseif ($phase === 1 && $event->type === 'session.model_called') {
                $phase = 2;
            } elseif ($phase === 2 && ($turn || $event->type === 'session.run_terminated')) {
                return true;
            }
        }

        return false;
    }

    /** @return Refusal|null */
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
        $plugin = $missing->plugin ?? (\is_string($arguments['plugin'] ?? null) ? $arguments['plugin'] : null);
        $exists = $plugin !== null && $this->policy->pluginExists($plugin);
        $named = $plugin !== null && self::names($standing, $plugin);
        if ($missing->plugin !== null && !$exists && !$named) {
            return null;
        }

        return [
            'seq' => $event->seq,
            'tool' => $payload['tool'],
            'plugin' => $plugin,
            'permission' => $missing->permission,
            'call' => self::shown($arguments),
            'target' => $plugin === null ? null : ($exists ? 'existing' : 'new'),
            'named' => $named,
            // Write over work the house already has is never one touch, named or not: a destructive call can
            // only touch what exists, and which call is destructive is not read from its text.
            'consent' => $exists ? 'informed' : 'touch',
        ];
    }

    /**
     * The recorded arguments as a person reads them: scalars as they were, long text cut with its size
     * said, structures as JSON under the same cut. Shown, never interpreted.
     *
     * @param array<mixed> $arguments
     *
     * @return array<string, string|int|float|bool|null>
     */
    private static function shown(array $arguments): array
    {
        $out = [];
        foreach ($arguments as $name => $value) {
            if (!\is_string($value) && !\is_scalar($value) && $value !== null) {
                $value = (string) json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            }
            if (\is_string($value) && \strlen($value) > self::SHOWN_BYTES) {
                $value = mb_strcut($value, 0, self::SHOWN_BYTES, 'UTF-8') . \sprintf('… (%d bytes)', \strlen($value));
            }
            $out[(string) $name] = $value;
        }

        return $out;
    }

    /**
     * Whether the standing ask names this plugin as a whole identifier, ignoring case ({@see StandingAsk}).
     */
    private static function names(string $standing, string $plugin): bool
    {
        return StandingAsk::ofText($standing)->namesIdentifier($plugin);
    }

    /**
     * The standing ask: the session's current goal and every turn the human wrote into it.
     *
     * @param list<Event> $events
     */
    private static function standingAskIn(array $events): string
    {
        return StandingAsk::in($events)->text();
    }

    /** @param list<Event> $events */
    private static function goalIn(array $events): string
    {
        return StandingAsk::goalIn($events);
    }
}
