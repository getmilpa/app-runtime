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

use Milpa\Agent\EvidenceKind;
use Milpa\Agent\Session;
use Milpa\Agent\SessionFacts;
use Milpa\Agent\SessionStore;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;

/**
 * Whether the session's recorded work has current positive evidence, never inferred from answer prose.
 * The scope is recorded_work; this does not certify completeness against an undeclared goal contract.
 *
 * ── THE DEBT THIS PAYS, MEASURED (greenhouse evidence/0442) ─────────────────────────────────────
 *
 * A headless run ended with the answer «listo» while the scaffolded behavioral test was red and
 * todos were open: the agent declared done with state < verified, and nothing in the runtime named
 * the gap. The answer is the model's claim; the ledger is the system's. This verdict is the ledger
 * speaking beside the claim — it BLOCKS THE ASSERTION, not the write: the answer still returns,
 * but the envelope cannot present a completion the recorded facts do not back.
 *
 * ── DERIVED, NEVER RE-MEASURED ──────────────────────────────────────────────────────────────────
 *
 * Everything here reads facts already in the stream — unevidenced dones, open todos, the latest
 * producer-declared verification verdicts. No filesystem scan, no test run, no model call: the
 * verdict is deterministic for a given stream, so replaying the session reproduces it exactly.
 */
final class ClosureVerdict
{
    /**
     * The event type the verdict is appended under — outside {@see \Milpa\Agent\SessionEvent} on
     * purpose: the reducer ignores types it does not know (a stream may carry events from a newer
     * producer), so the projection is additive and an old reader keeps folding the session
     * untouched. Surfaces that want the verdict read it from the stream by this name.
     */
    public const EVENT = 'session.closure_derived';

    /** Reasons name facts, they do not dump them: past this many, the rest is counted. */
    private const MAX_REASONS = 16;

    /**
     * Derive the closure verdict for a session's final answer from its recorded facts.
     *
     * Positive recorded evidence is required; an empty ledger is not verification. Every touched
     * artifact needs a current verification, every done needs evidence, and nothing stays open.
     * Read-only discovery does not create a verification obligation. The explicit scope covers
     * recorded work, not completeness against a human goal whose criteria were never declared.
     *
     * A session that never opened a todo kept no record of its own; given its stream, the HOUSE derives
     * the closure from its own receipts instead ({@see HouseObservedClosure}, greenhouse decisions/0487):
     * the house observed a subject the goal names served in the house after the last change landed
     * (decisions/0522: an observation of anything else never closes). A session with todos
     * keeps its own record — every todo done with accepted evidence — and when the house observed what
     * landed, its observation stands beside that record: what never landed stops binding, and the scope
     * says both (`recorded_work_and_house_observation`, greenhouse decisions/0509). A done todo whose test
     * reference's last run is red is not done, with or without the house.
     *
     * A call is a change to the house only when its operation's own declaration says it lasts (`$lasting`,
     * {@see LastingCalls}, decisions/0523): a green test run after the observation does not take it back.
     *
     * WORK IN THE DOMAIN closes on the receipts of what the house itself executed ({@see HouseExecutedWork}, greenhouse
     * decisions/0599): every act a person's admission covers, run in the house and changing its state, with nothing left
     * halfway. The scope says so — `house_execution`, or `recorded_work_and_house_execution` beside todos — and the work
     * carries `asked: "unjudged"`: the house saw what was done, not whether that was what was asked. It needs the
     * house's reading of what was admitted (`$admitted`); without it nothing is derived from work, as before.
     *
     * @param list<Event>|null                                     $stream   the session's stream, or `null` to judge the recorded work alone
     * @param (\Closure(string, array<string, mixed>): ?bool)|null $lasting  the house's reading of which calls last; null reads the recorded flag
     * @param (\Closure(string, ?string): ?bool)|null              $admitted whether a person's admission covers an operation for a principal; null
     *                                                                       when it is no verb of a built capability; no closure, no work is read
     *
     * WHAT THE HOUSE OWES (`houseOwes`, decided 2026-10-09): the capabilities it last saw throw and has not seen run
     * since ({@see HouseObservedClosure}), which hold the closure in every form above, and whether they are ALL that
     * holds it — then the house runs them again at the natural end of the leg ({@see CapabilityExercise::atTheEnd()}).
     *
     * @return array{verified: bool, reasons: list<string>, scope: string, derivedFrom?: array<string, mixed>, houseOwes?: array{capabilities: list<array{subject: string, seq: int, threwAt: int, changedAt: ?int, tried: bool}>, holdsAlone: bool}}
     */
    public static function derive(Session $session, SessionFacts $facts, ?array $stream = null, ?\Closure $lasting = null, ?\Closure $admitted = null): array
    {
        $reasons = [];
        $hasEvidence = false;
        foreach ($session->todos as $todo) {
            $hasEvidence = $hasEvidence || $session->isDoneVerified($todo->id);
        }

        foreach ($session->unverifiedDones() as $todo) {
            $reasons[] = "todo {$todo->id} done without evidence";
        }

        $open = \count($session->pendingTodos());
        if ($open > 0) {
            $reasons[] = $open === 1 ? '1 todo open' : "{$open} todos open";
        }

        // A TODO IS NOT DONE AGAINST A RED JUDGE (greenhouse decisions/0509 §4). A done todo backed by a passed test
        // is only as done as the last run that declares that reference: a red run after the green one the claim
        // cited takes it back. Measured (evidence/1036): the claim door accepted the last GREEN run, whatever came
        // after it, and the closure never asked again.
        if ($stream !== null) {
            foreach ($session->evidence as $evidence) {
                if ($evidence->kind !== EvidenceKind::TestPassed || $evidence->todo === null || ! $session->isDoneVerified($evidence->todo)) {
                    continue;
                }
                $last = LastTestRun::of($stream, $evidence->reference);
                if ($last !== null && ! $last['green']) {
                    $reasons[] = "todo {$evidence->todo} rests on «{$evidence->reference}», whose last test run is red (seq {$last['seq']})";
                }
            }
        }

        // NOR OVER A REHEARSAL (greenhouse decisions/0587). A done todo backed by a call that only ran in a trial
        // nothing promoted — or by an artifact only such trials hold — rests on a fact about a copy. Measured on
        // published 0.211.1 (decisions/0585): a domain write ran in a trial with «nothing to apply», its claim was
        // accepted, and this verdict said `verified: true` with the house's store untouched. The door refuses that
        // claim now; a session recorded before it did already holds the evidence, so the verdict asks the stream
        // too, with the door's own reading ({@see LandedCalls}). It speaks only when the stream SHOWS the rehearsal:
        // evidence the stream says nothing about is judged as it always was.
        if ($stream !== null) {
            $calls = LandedCalls::of($stream, $lasting);
            foreach ($session->evidence as $evidence) {
                if ($evidence->todo === null || ! $session->isDoneVerified($evidence->todo)) {
                    continue;
                }
                if ($evidence->kind === EvidenceKind::OperationOk) {
                    $rehearsal = $calls->answeredOk($evidence->reference) === null && $calls->executed($evidence->reference) === null
                        ? $calls->rehearsalOf($evidence->reference) : null;
                    $did = 'answered ok';
                } elseif ($evidence->kind === EvidenceKind::ArtifactCreated) {
                    $state = $facts->workStateFor($evidence->reference);
                    $rehearsal = $calls->madeOnlyInATrial(\is_array($state['workState']['attempts'] ?? null) ? $state['workState']['attempts'] : []);
                    $did = 'was made';
                } else {
                    continue;
                }
                if ($rehearsal !== null) {
                    $reasons[] = "todo {$evidence->todo} rests on «{$evidence->reference}», which {$did} only inside a trial nothing promoted (seq {$rehearsal['seq']})";
                }
            }
        }

        // A session that never opened a todo kept no record of its own; given its stream, the HOUSE derives the
        // closure from what landed in it and what it observed after (decisions/0487).
        //
        // A session WITH todos keeps its record, and the house speaks beside it only when it observed what landed
        // (greenhouse decisions/0509 §2): something landed, and the house saw it served after, fresh. Measured
        // (evidence/1036): a resident planned 8 todos, closed them all with accepted evidence, and the house saw
        // /blog served after its last promotion — and the verdict stayed open on eight artifacts written in trials
        // (and one refused call) that no call could ever verify. When the house did not observe what landed, a
        // session with todos is judged as it always was, and the house's reason is not added (§3).
        //
        // AND IT SPEAKS ONLY OF WHAT THE GOAL NAMES (greenhouse decisions/0522). An observation of a subject the
        // standing ask does not name is not an observation of the work: measured (evidence/1050), registering an
        // empty plugin made the house see `GET /` → 200, and that closed a session whose goal was `GET /blog`.
        //
        // AND WHEN THE GOAL WRITES A ROUTE, ONLY OF THAT ROUTE (greenhouse decisions/0555). Measured (evidence/1088):
        // «published posts» named `/posts`, and the house verified the session on it while `/blog` answered 404.
        $ask = $stream !== null ? StandingAsk::in($stream) : null;
        $house = $stream !== null && $ask !== null
            ? HouseObservedClosure::of($stream, $facts, static fn (string $subject): bool => $ask->namesSubject($subject), $lasting, $ask->explicitRoutes())
            : null;
        // A PAGE THE HOUSE SAW AND THAT DOES NOT LIST IS SAID IN EVERY FORM (greenhouse decisions/0576 §2). Measured
        // (evidence/1110): a session with todos whose house observation does not derive is judged by its record alone
        // (§3 of 0509, below), so closing the todos made the house's finding vanish and an empty page closed verified.
        $notListing = $house['unlisted'] ?? [];
        // AND SO IS WHAT THE HOUSE LAST SAW THROW AND HAS NOT LOOKED AT AGAIN (decided by Rod, 2026-10-09). A landing
        // takes back the receipt that said a capability threw; when the house's observation then does not stand, the
        // record below judged alone — and closed over it. It is the house's own debt, and it holds every form.
        $owed = $house['owed'] ?? [];
        if ($session->todos !== [] && $house !== null && ! ($house['derived'] && $house['lastChangeSeq'] !== null)) {
            $house = null;
        }

        $state = $facts->workState();
        $artifacts = \is_array($state['artifacts'] ?? null) ? $state['artifacts'] : [];
        foreach ($artifacts as $entry) {
            if (! \is_array($entry)) {
                continue;
            }
            $verification = $entry['verification'] ?? null;
            $artifact = \is_array($entry['artifact'] ?? null) && \is_string($entry['artifact']['value'] ?? null)
                ? $entry['artifact']['value']
                : '?';
            $current = ($entry['state'] ?? null) === 'verified';
            $hasEvidence = $hasEvidence || $current;
            if (\is_array($verification) && ($verification['verified'] ?? null) === false) {
                $judge = \is_string($verification['operation'] ?? null) ? $verification['operation'] : '?';
                $reasons[] = "judge {$judge} recorded red for {$artifact}";
                continue;
            }
            $touched = false;
            foreach ($entry['attempts'] ?? [] as $attempt) {
                if (($attempt['mutating'] ?? false) === true && ($attempt['awaitingConfirmation'] ?? null) !== true) {
                    $touched = true;
                }
            }
            // WHAT NEVER LANDED DOES NOT BIND THE HOUSE (greenhouse decisions/0494 §5). When the house derived its
            // closure, an artifact every mutating attempt of which was a rehearsal or a call that failed is a fact
            // about a copy: the house changed only through what landed, and it observed that served after. Measured
            // (evidence/1024): a blog written in trials and promoted kept five such obligations no call could meet.
            if ($house !== null && $house['derived'] && $verification === null
                && array_intersect(array_column($entry['attempts'] ?? [], 'seq'), $house['landed']) === []) {
                continue;
            }
            if (!$current && ($touched || $verification !== null)) {
                $reasons[] = "artifact {$artifact} has no current verification";
            }
        }
        // What the HOUSE saw, apart from what the session's own record lacks: it is said first (below).
        $seen = $notListing;
        if ($house !== null) {
            if ($house['derived']) {
                $hasEvidence = true;
            } elseif ($house['lastChangeSeq'] !== null && $house['reason'] !== null) {
                $reasons[] = $house['reason'];
                $seen = [$house['reason']];
                $notListing = [];
            }
        }
        $reasons = [...$reasons, ...$notListing];
        // THE WORK THE HOUSE EXECUTED (greenhouse decisions/0599): its own receipts are evidence, and what they leave
        // halfway is said.
        $worked = $stream !== null && $admitted !== null ? HouseExecutedWork::of($stream, $admitted) : null;
        if ($worked !== null && $worked['derived']) {
            $hasEvidence = true;
        } elseif ($worked !== null) {
            $reasons = [...$reasons, ...$worked['reasons']];
        }
        if (!$hasEvidence) {
            $reasons[] = 'no positive verification evidence recorded';
        }

        // THE HOUSE DOES NOT CLOSE ON WHAT IT LAST SAW THROW. Whether anything ELSE holds the closure is said beside it:
        // only then is there nothing for the house to run — it looks again when its look is all that is missing.
        $closesButForIt = $reasons === [];
        $owes = array_column($owed, 'why');
        $reasons = [...$owes, ...$reasons];
        $seen = [...$owes, ...$seen];
        $houseOwes = $owed === [] ? [] : ['houseOwes' => [
            'capabilities' => array_map(static fn (array $one): array => array_diff_key($one, ['why' => true]), $owed),
            'holdsAlone' => $closesButForIt,
        ]];

        // WHAT THE HOUSE SAW IS THE FIRST THING SAID (greenhouse decisions/0605). A session that does not close is told
        // of every class it wrote that no call verified, and the house's own finding came after those lines. Measured
        // on houses a build run left (evidence/1171): of ten reasons, the one that names the operations that threw
        // was the ninth; and since the verdict says sixteen facts and counts the rest, with enough classes it was
        // counted and not said. What a session can act on comes before what no call of its can satisfy.
        $reasons = [...array_values(array_intersect($reasons, $seen)), ...array_values(array_diff($reasons, $seen))];
        if (\count($reasons) > self::MAX_REASONS) {
            $overflow = \count($reasons) - (self::MAX_REASONS - 1);
            $reasons = \array_slice($reasons, 0, self::MAX_REASONS - 1);
            $reasons[] = "… and {$overflow} more recorded facts";
        }

        $work = $worked !== null && $worked['derived'] ? ['work' => $worked['work']] : [];
        // The form the verdict takes — and, beside whichever it is, what the house owes.
        $form = match (true) {
            $house !== null && $house['derived'] => [
                'scope' => $session->todos === [] ? 'house_observation' : 'recorded_work_and_house_observation',
                'derivedFrom' => ['observation' => $house['observation'], 'lastChangeSeq' => $house['lastChangeSeq']] + $work,
            ],
            $work !== [] => ['scope' => $session->todos === [] ? 'house_execution' : 'recorded_work_and_house_execution', 'derivedFrom' => $work],
            default => ['scope' => 'recorded_work'],
        };

        return ['verified' => $reasons === [], 'reasons' => $reasons] + $form + $houseOwes;
    }

    /**
     * What a surface watching the session is told when the house records its verdict, or null for any other fact.
     *
     * The stream keeps the whole fact; the surface gets what it paints — whether the house verified, why not,
     * and on what scope — the same three things a reloaded page reads from the stream (greenhouse
     * decisions/0563). Only a literal `true` is a verification.
     *
     * @return array{session: string, kind: 'closure', at: int, closure: array{verified: bool, reasons: list<string>, scope: string}}|null
     */
    public static function surface(Event $event, string $sessionId): ?array
    {
        if ($event->type !== self::EVENT) {
            return null;
        }
        $p = $event->payload;

        return [
            'session' => $sessionId,
            'kind' => 'closure',
            'at' => $event->seq,
            'closure' => [
                'verified' => ($p['verified'] ?? null) === true,
                'reasons' => array_values(array_filter(
                    \is_array($p['reasons'] ?? null) ? $p['reasons'] : [],
                    static fn (mixed $reason): bool => \is_string($reason) && $reason !== '',
                )),
                'scope' => \is_string($p['scope'] ?? null) ? $p['scope'] : '',
            ],
        ];
    }

    /**
     * Append the verdict to the session's own stream, so surfaces can project it.
     *
     * One append per leg that ends on the closure — a final answer, or an epilogue the house opened
     * on this verdict and the model exhausted (greenhouse decisions/0489); the caller's single
     * natural-end site is the only one that records. It goes through the raw store because {@see SessionStore} types its appends by its
     * own enum; the reducer skips what it does not know, so the session keeps folding unchanged
     * while any projection may read the verdict back by {@see self::EVENT}.
     *
     * @param array{verified: bool, reasons: list<string>, scope?: string} $closure
     */
    public static function record(EventStoreInterface $events, string $sessionId, array $closure): void
    {
        $events->append(new Event(
            streamId: SessionStore::PREFIX . $sessionId,
            type: self::EVENT,
            payload: $closure,
            seq: $events->nextSeq(),
        ));
    }
}
