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
 * A SECOND KIND OF REFUSAL IS OPEN HERE (greenhouse decisions/0590): the seat called a verb of a capability built
 * in this house, and no person has admitted that verb for it. The authoring policy names nothing for such a call;
 * the judge of admissions does ({@see CapabilityAdmissions}) — the same one the gate asks. Its row is marked
 * `kind: capability` and carries what a person must see to admit: every verb that scope of the capability opens,
 * each with what it declares, where its work keeps its state and how a call of it would run here, and `contract`,
 * the digest of exactly that — which is what the admission approves.
 * It is offered only by a frontier that was told what the house built; one that was not offers what it always did.
 *
 * @phpstan-type Verb array{verb: string, tool: string, description: string, mutating: bool, requiresConfirmation: bool, namedTarget: ?string, surfaces: ?list<string>, scopes: list<string>, effects: array<string, mixed>, state: array{paths: list<string>, source: string, refused?: string}|null, runs: array{how: string, why?: string, pre_image?: bool}, digest: string, standing: 'admitted'|'never'|'changed'|'added'|'withdrawn', not_admissible: ?string}
 * @phpstan-type Refusal array{seq: int, tool: string, plugin: ?string, permission: string, call: array<string, string|int|float|bool|null>, target: 'new'|'existing'|null, named: bool, consent: 'touch'|'informed', kind?: 'capability', capability?: string, scope?: string, why?: 'never'|'changed'|'added'|'withdrawn'|'in_works', opens?: list<Verb>, contract?: string, not_admissible?: ?string, withdrawn?: array{by: string, at: string}|null, works?: array{holders: list<string>}|null, suspended?: bool, stands_for?: 'session'|null, suspends?: list<array{seat: string, scopes: list<string>}>}
 * @phpstan-type SeatRefusal array{seq: int, tool: string, plugin: ?string, permission: string, call: array<string, string|int|float|bool|null>, target: 'new'|'existing'|null, named: bool, consent: 'touch'|'informed', seat: string, kind?: 'capability', capability?: string, scope?: string, why?: 'never'|'changed'|'added'|'withdrawn'|'in_works', opens?: list<Verb>, contract?: string, not_admissible?: ?string, withdrawn?: array{by: string, at: string}|null, works?: array{holders: list<string>}|null, suspended?: bool, stands_for?: 'session'|null, suspends?: list<array{seat: string, scopes: list<string>}>}
 */
final class SeatFrontier
{
    /** How the house's own turns begin (decisions/0495): a fact it recorded, never the start of a run. */
    public const NOTICE_PREFIX = '[house] ';

    /** How the principal of a person who opened a session from the panel is spelled: the actor her passkey resolves to. */
    public const PERSON_PREFIX = 'actor:passkey:';

    /** How much of one argument's text a card shows before it says the rest's size. */
    private const SHOWN_BYTES = 120;

    private readonly EnrollmentLine $line;

    public function __construct(
        private readonly SessionStore $sessions,
        private readonly FileEnrollmentStore $enrollments,
        private readonly PluginAuthoringPolicy $policy,
        private readonly ?CapabilityAdmissions $admissions = null,
    ) {
        $this->line = new EnrollmentLine($enrollments);
    }

    /**
     * Build the frontier over an app root's ledger, its policy and the session store it was handed.
     *
     * `$built` is what the house built, for the frontier that offers the admission of a built verb (greenhouse
     * decisions/0590). Without it nothing of that kind is offered — which is what a reader that only knows the
     * authoring card must be handed.
     */
    public static function forRoot(string $root, SessionStore $sessions, ?BuiltCapabilities $built = null): self
    {
        $enrollments = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');

        return new self(
            $sessions,
            $enrollments,
            new PluginAuthoringPolicy($root),
            $built === null || $built->isEmpty() ? null : new CapabilityAdmissions($enrollments, $built),
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

    /**
     * Whether a PERSON opened this session — a passkey the house verified — and so it is nobody's seat (greenhouse
     * decisions/0609). A person decides for the seats her line enrolled; her own session has no frontier, and nothing
     * read here gives it one. Read from the opening event alone, as {@see seatOf()} is.
     */
    public function openedByAPerson(string $session): bool
    {
        $opening = $this->sessions->opening($session);
        $by = $opening !== null && \is_array($opening->payload['by'] ?? null) ? $opening->payload['by'] : [];

        return ($by['verified'] ?? false) === true && \is_string($by['id'] ?? null) && str_starts_with($by['id'], self::PERSON_PREFIX);
    }

    /**
     * What a session a PERSON opened was refused and nobody can grant (greenhouse decisions/0609, I2): each recorded
     * call of hers that lacked a permission of a plugin, shaped like a seat's refusal without a seat. It is read so
     * her panel can tell her, in her own conversation, what her session cannot do. The house judges the recorded call
     * again, as it does for a seat; it never reads the sentence.
     *
     * ONE ROW PER TURN IT HAPPENED IN: within a turn the model retries a refused call, and she is told once — of the
     * latest retry of each call shape. A later turn refused the same thing is told again, there: a thread read back
     * from the record says what it said while it ran.
     *
     * IT IS NOT A FRONTIER. Nothing here can be granted: {@see openRefusals()}, {@see refusal()} and
     * {@see wouldOffer()} stay empty for her session, and no one answers for it.
     *
     * @param bool $ofTheLastTurn only what was refused since the last turn a person or a caller wrote — the turn
     *                            that just ran —, so a surface is not told again of an earlier one
     *
     * @return list<array{seq: int, tool: string, plugin: ?string, permission: string, call: array<string, mixed>}>
     */
    public function refusedToAPerson(string $session, bool $ofTheLastTurn = false): array
    {
        if (!$this->openedByAPerson($session)) {
            return [];
        }
        $opening = $this->sessions->opening($session);
        $person = substr((string) ($opening?->payload['by']['id'] ?? ''), \strlen('actor:'));
        // What the ledger says she holds NOW, as a seat's refusal is judged with what the seat holds now.
        $authority = new ToolContext($person, 'web', $this->enrollments->scopesFor(substr($person, \strlen('passkey:'))) ?? []);
        $earlier = [];
        $latest = [];
        foreach ($this->sessions->stream($session) as $event) {
            $payload = $event->payload;
            // A turn begins when a person or a caller writes — never with a notice of the house, which nobody typed.
            if ($event->type === 'session.turn' && ($payload['role'] ?? null) === 'user'
                && !(\is_string($payload['content'] ?? null) && str_starts_with($payload['content'], self::NOTICE_PREFIX))) {
                $earlier = [...$earlier, ...array_values($latest)];
                $latest = [];
            }
            if ($event->type !== 'session.tool_called' || ($payload['ok'] ?? null) !== false || !\is_string($payload['tool'] ?? null)) {
                continue;
            }
            $arguments = \is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [];
            $missing = $this->policy->missing($authority, $payload['tool'], $arguments);
            if ($missing === null) {
                continue;
            }
            $row = [
                'seq' => $event->seq,
                'tool' => $payload['tool'],
                'plugin' => $missing->plugin ?? (\is_string($arguments['plugin'] ?? null) ? $arguments['plugin'] : null),
                'permission' => $missing->permission,
                'call' => self::shown($arguments),
            ];
            // Told apart as a seat's refusals are ({@see shape()}): the permission, the tool and the plugin — what a
            // retry repeats. The latest retry is the one kept.
            $key = $row['permission'] . "\0" . $row['tool'] . "\0" . ($row['plugin'] ?? '');
            unset($latest[$key]);
            $latest[$key] = $row;
        }

        return $ofTheLastTurn ? array_values($latest) : [...$earlier, ...array_values($latest)];
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
     * `refusals` are the authoring ones, as every reader of this list knows them; a built verb waiting for a
     * person's admission is listed apart, under `admissions`, so a reader that only knows the authoring card is
     * never handed one it would word as write over a plugin (greenhouse decisions/0590).
     *
     * @return list<array{session: string, goal: string, seat: string, refusals: list<Refusal>, admissions: list<Refusal>}>
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
            $open = $this->refusalsIn($events, $seat);
            $out[] = [
                'session' => $id,
                'goal' => self::goalIn($events),
                'seat' => 'key:' . $seat,
                'refusals' => array_values(array_filter($open, static fn (array $row): bool => !isset($row['kind']))),
                'admissions' => array_values(array_filter($open, static fn (array $row): bool => isset($row['kind']))),
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

    /**
     * The refusal this frontier WOULD offer for a call the seat was just refused, before it is recorded — or null.
     *
     * The same judgement {@see openRefusals()} makes over the stream, asked of one call about to be answered, so
     * the refusal the model reads can say who grants it (greenhouse decisions/0543). It reads, never records.
     *
     * @param array<string, mixed> $arguments
     *
     * @return Refusal|null
     */
    public function wouldOffer(string $session, string $tool, array $arguments): ?array
    {
        $events = $this->sessions->stream($session);
        $seat = $this->seatIn($events);
        if ($seat === null) {
            return null;
        }
        $call = new Event(SessionStore::PREFIX . $session, 'session.tool_called', ['tool' => $tool, 'ok' => false, 'arguments' => $arguments], \PHP_INT_MAX);

        return $this->judge($call, $seat, self::standingAskIn($events));
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
            return $this->admission($event->seq, $payload['tool'], $arguments, $seat, $standing);
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
            // WHAT ELSE GRANTING IT DOES (greenhouse decisions/0590, rule 10): the building permit of a capability
            // persons admitted puts it back in works, and while it stands no seat uses its verbs. Who was admitted
            // what — so the person who reopens the works reads what stops running.
            'suspends' => $exists && $missing->permission === \Milpa\AppRuntime\Identity\FileEnrollmentStore::permitOf((string) $plugin)
                ? ($this->admissions?->admittedOf((string) $plugin) ?? [])
                : [],
            // AND HOW FAR IT REACHES (greenhouse decisions/0602): granted knowingly over existing work, it names what
            // is inside that plugin for THIS session — the house then writes there without asking about each piece
            // ({@see OpenedWorks}). A fact, so every surface that draws its own card says it before the act, as the
            // terminal does. Over an existing plugin the policy names one permission, its building permit: the card
            // that asks for informed consent is the card of the grant that stands.
            'stands_for' => $exists ? 'session' : null,
        ];
    }

    /**
     * The row for a call to a built verb no standing admission covers — or null: the frontier was not told what the
     * house built, the tool is no built verb, or a person already admitted it as it stands (decisions/0590).
     *
     * @param array<mixed> $arguments
     *
     * @return Refusal|null
     */
    private function admission(int $seq, string $tool, array $arguments, string $seat, string $standing): ?array
    {
        $admissions = $this->admissions;
        $verb = $admissions?->verb($tool);
        if ($admissions === null || $verb === null) {
            return null;
        }
        $missing = $admissions->missingFor($seat, $verb);
        $card = $missing === null ? null : $admissions->card($seat, $verb->capability, $missing->scope);
        if ($missing === null || $card === null) {
            return null;
        }

        return [
            'seq' => $seq,
            'tool' => $tool,
            'plugin' => $verb->capability,
            'permission' => $card['permission'],
            'call' => self::shown($arguments),
            'target' => 'existing',
            'named' => self::names($standing, $verb->capability),
            // A built capability is work the house already has: admitting it is never one touch (decisions/0510).
            'consent' => 'informed',
            'kind' => 'capability',
            'capability' => $verb->capability,
            'scope' => $missing->scope,
            'why' => $missing->why,
            'opens' => $card['opens'],
            'contract' => $card['contract'],
            'not_admissible' => $card['not_admissible'],
            // When a person took this scope back from the seat (decisions/0590, rule 12): who, and when.
            'withdrawn' => $card['withdrawn'],
            // Who holds the capability's building permit now: admitting takes it from them (rule 10).
            'works' => $card['works'],
            'suspended' => $card['suspended'],
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
