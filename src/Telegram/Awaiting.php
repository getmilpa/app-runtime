<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Telegram;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SeatFrontier;

/**
 * What waits for one person, read from the house: the refusals of the seats they answer for, and the open
 * questions of the sessions they may decide.
 *
 * Nothing here is new judgement. A refusal is whatever {@see SeatFrontier} offers that principal (greenhouse
 * decisions/0493, 0510); a question is shown under the rule {@see \Milpa\AppRuntime\Agent\SessionLine} holds every
 * door to — the principal that opened the session, or the line that enrolled its seat. A session nobody verified
 * opened is nobody's here: this surface sends a card to one named person, so «any holder of the scope» has no
 * reader and the question stays in the panel.
 *
 * @phpstan-type Waiting array{key: string, kind: 'frontier'|'question', session: string, ref: string, subject: string, tool: string}
 */
final class Awaiting
{
    public function __construct(
        private readonly SessionStore $sessions,
        private readonly SeatFrontier $frontier,
    ) {
    }

    /** Over an app root's ledger and policy, and the session store it was handed. */
    public static function forRoot(string $root, SessionStore $sessions): self
    {
        return new self($sessions, SeatFrontier::forRoot($root, $sessions));
    }

    /**
     * Everything that waits for this principal, in a stable order — or only what one session holds.
     *
     * @return list<Waiting>
     */
    public function of(string $principal, ?string $session = null): array
    {
        $waiting = [];
        foreach ($this->frontier->sessionsFor($principal) as $seat) {
            if ($session !== null && $seat['session'] !== $session) {
                continue;
            }
            foreach ($seat['refusals'] as $refusal) {
                $waiting[] = self::item('frontier', $seat['session'], (string) $refusal['seq'], $refusal['permission'], $refusal['tool']);
            }
        }
        foreach ($this->sessions->loadAll() as $id => $state) {
            $id = (string) $id;
            if ($state->question === null || ($session !== null && $id !== $session) || !$this->decides($principal, $id)) {
                continue;
            }
            $why = json_decode((string) $state->question->why, true);
            $operation = \is_array($why) && \is_string($why['operation'] ?? null) ? $why['operation'] : '';
            $waiting[] = self::item('question', $id, $state->question->id . '@' . $this->askedAt($id, $state->question->id), $operation, '');
        }
        usort($waiting, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        return $waiting;
    }

    /** Whether this principal opened the session, verified, or answers for its seat. */
    public function decides(string $principal, string $session): bool
    {
        $opening = $this->sessions->opening($session);
        $by = \is_array($opening?->payload['by'] ?? null) ? $opening->payload['by'] : [];
        if (($by['verified'] ?? false) !== true || !\is_string($by['id'] ?? null)) {
            return false;
        }

        return $by['id'] === $principal || $this->frontier->answersFor($principal, $session);
    }

    /**
     * Who settled a card, as the house recorded it — or null when nobody did and it simply stopped waiting.
     *
     * A grant is read from the enrollment ledger (the seat holds the scope now, and the ledger says who
     * recognized it last); an answer from the session's own `session.question_answered` — `by` is empty when the
     * house recorded who answered only as an unverified claim.
     *
     * @param array{kind: string, session: string, ref: string, subject: string} $card
     *
     * @return array{by: string, answer: ?string}|null
     */
    public function settledBy(array $card, \Milpa\AppRuntime\Identity\FileEnrollmentStore $enrollments): ?array
    {
        if ($card['kind'] === 'frontier') {
            $seat = $this->frontier->seatOf($card['session']);
            if ($seat === null || !\in_array($card['subject'], $enrollments->scopesFor($seat) ?? [], true)) {
                return null;
            }
            $by = $enrollments->authorizedBy($seat);

            return $by === null ? null : ['by' => $by, 'answer' => null];
        }

        [$question, $asked] = array_pad(explode('@', $card['ref'], 2), 2, '0');
        foreach ($this->sessions->stream($card['session']) as $event) {
            if ($event->type !== 'session.question_answered' || $event->seq <= (int) $asked || ($event->payload['id'] ?? null) !== $question) {
                continue;
            }
            $by = \is_array($event->payload['by'] ?? null) && \is_string($event->payload['by']['id'] ?? null) ? $event->payload['by'] : null;
            $answer = \is_string($event->payload['answer'] ?? null) ? $event->payload['answer'] : null;
            if ($by === null) {
                return null;
            }
            // The HTTP projector attributes as `actor:<principal>`; the ledger and a card name the bare principal.
            // A name nobody verified is recorded by the house as a claim, and a card does not repeat a claim.
            $id = str_starts_with($by['id'], 'actor:') ? substr($by['id'], 6) : $by['id'];

            return ['by' => ($by['verified'] ?? false) === true ? $id : '', 'answer' => $answer];
        }

        return null;
    }

    /** The position of the question's last asking — what tells one asking of `perm:make` from the next. */
    private function askedAt(string $session, string $question): int
    {
        $at = 0;
        foreach ($this->sessions->stream($session) as $event) {
            if ($event->type === 'session.question_asked' && ($event->payload['id'] ?? null) === $question) {
                $at = $event->seq;
            }
        }

        return $at;
    }

    /** @return Waiting */
    private static function item(string $kind, string $session, string $ref, string $subject, string $tool): array
    {
        /** @var 'frontier'|'question' $kind */
        return [
            'key' => hash('sha256', $kind . "\0" . $session . "\0" . $ref),
            'kind' => $kind,
            'session' => $session,
            'ref' => $ref,
            'subject' => $subject,
            'tool' => $tool,
        ];
    }
}
