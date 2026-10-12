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

namespace Milpa\AppRuntime\Standing;

use Milpa\AppRuntime\Identity\IdentityKey;
use Milpa\Command\Consent\OperationId;
use Milpa\Command\Operation;
use Milpa\Command\Standing\Canonical;
use Milpa\Command\Standing\ConditionReceipt;
use Milpa\Command\Standing\GrantCondition;
use Milpa\Command\Standing\GrantStanding;
use Milpa\Command\Standing\StandingCall;
use Milpa\Command\Standing\StandingCheck;
use Milpa\Command\Standing\StandingJudge;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\SignatureVerifier;

/**
 * THE HOUSE'S JUDGE OF STANDING GRANTS: before a seat's act, it reads the book at that moment and
 * refuses or lets pass (greenhouse decisions/0613 stage 3, decisions/0615).
 *
 * ── WHY IT EXISTS ───────────────────────────────────────────────────────────────────────────────
 *
 * Two things that happened on one day. A condition of a mandate was measured AFTER the act it
 * guarded, on a package that failed it. And a class of acts went on being exercised for hours after
 * its mandate had suspended itself. Neither was bad faith: a mandate written as text depends on
 * someone obeying it, and a flag that is gone looks exactly like a flag that is obeyed. So the house
 * stops depending on that — a worker's mistake does not become the organisation's because the house
 * does not let it through, not because someone catches it afterwards.
 *
 * ── WHAT IT DOES, AND ONLY THIS ─────────────────────────────────────────────────────────────────
 *
 *   · LENDS, for the asking — and ONLY ON A GRANT THAT IS IN FORCE AND VERIFIED: a seat making a call
 *     such a grant names is seen, BY THE GATE AND BY NOTHING ELSE, holding the words that operation
 *     declares ({@see self::lends()}), so that the question that decides is this judge's and not a word
 *     the seat lacks. Nothing is written to the seat; what was lent is marked with the tool it was
 *     lent for and taken back before any other tool is asked about, before the boundary reads the
 *     caller, and before one of the house's own doors calls on. A grant that is suspended, superseded,
 *     revoked, expired or not honoured lends nothing, and neither does any grant while a suspended one
 *     stops the call: the gate refuses for the missing word, and the house's doors have that refusal
 *     cite this judge's reason — one that named only the word would name the wrong thing to fix.
 *   · JUDGES: gathers the facts — who the signer is today, where the book says each grant stands, what
 *     the call acts on, what it would do, what was measured on that very content — and hands them to
 *     the pure judgment in milpa/command ({@see StandingJudge}). It adds no criterion of its own, for
 *     one grant or for several: a suspended grant that names the call refuses it whatever another lends.
 *   · WRITES DOWN: every act it lets pass, BEFORE it runs; every refusal of a call a grant names; what
 *     a measuring operation found; what a watcher saw. And when a watcher reports its falsifier fired,
 *     or the content an act touched is not what was judged, it suspends the grant itself.
 *
 * ── WHAT IT NEVER DOES ──────────────────────────────────────────────────────────────────────────
 *
 * Grant. Widen. Reinstate. Answer a consent question: at the terminal a seat still signs its own call.
 * Touch a call no grant names. And it does not judge the terminal's own operator, nor a key the house
 * never recognized signing there: they are sovereign in this house, and no grant can name them
 * (measured, decisions/0615). A key a grant DOES name is governed whatever it holds on its own.
 */
final class HouseStandingJudge
{
    /** Where a context says what was lent to it for the asking, and for which tool — so it can be taken back. */
    public const LENT = 'standing.lent';

    /**
     * How many refusals of one seat under one grant the book keeps in a row, with nothing but refusals written since
     * the first of them ({@see self::refused()}).
     */
    public const REFUSALS_KEPT = 32;

    /** What the last refusal the book keeps of a seat under a grant says, so that a reader knows the rest are not there. */
    public const REFUSALS_END = 'refusals of this seat under this grant are not written after this one, until something else is written in the book';

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock the moment of the act; the wall clock when none is handed in
     */
    public function __construct(
        private readonly string $root,
        private readonly ?SignatureVerifier $verifier = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /** A fresh reading of the book, or `null` in a house where nobody ever granted anything. */
    public function book(): ?StandingBook
    {
        return StandingLedger::forRoot($this->root)->exists() ? StandingBook::forRoot($this->root, $this->verifier) : null;
    }

    /**
     * The words of this operation a seat lacks and is lent FOR THE GATE'S QUESTION about one call.
     *
     * Only on a grant that is in force and verified: `$decision` is this judge's answer about the call, and a word
     * is lent when it says such a grant names it — whatever the judge then finds about the candidate. Any other
     * answer lends nothing. The words that are authority themselves — every scope, the word that recognizes
     * identities — are never lent.
     *
     * @return list<string>
     */
    public function lends(ToolContext $context, Operation $operation, ?StandingDecision $decision): array
    {
        if ($decision?->lendable !== true || $context->hasScope('*')) {
            return [];
        }

        return self::words($context, $operation);
    }

    /**
     * Whether this tool is OFFERED to a seat for the asking: some grant in force and verified lends to it and names
     * the operation. Asked without a call in hand, so nothing can be said of arguments; the house answers for the
     * tool, and a call that arrives is judged as a call.
     *
     * @param \Closure(OperationId|string): ?Operation $operationNamed the catalogue, asked for the instruments a grant names
     *
     * @return list<string>
     */
    public function offers(ToolContext $context, Operation $operation, \Closure $operationNamed): array
    {
        $seat = self::seatOf($context);
        $book = $seat === null || $context->hasScope('*') ? null : $this->book();
        if ($seat === null || $book === null) {
            return [];
        }
        // Read without verifying first, so that the many tools no grant names cost nothing; whatever a document
        // names is then asked of the verified book.
        $named = false;
        foreach ($book->documents() as $grant) {
            $named = $named || ($grant->lendsTo($seat) && $grant->namesOperation($operation->name));
        }
        if (!$named) {
            return [];
        }
        // A book that does not verify honours no grant in it, so every record of one carries that fault.
        $now = $this->now();
        foreach ($book->records() as $record) {
            if ($record->grant->lendsTo($seat) && $record->grant->namesOperation($operation->name) && $this->faultOf($record, $operation, $operationNamed) === null && $record->inForceAt($now)) {
                return self::words($context, $operation);
            }
        }

        return [];
    }

    /**
     * The words of an operation this caller lacks, as far as they can be lent at all.
     *
     * @return list<string>
     */
    private static function words(ToolContext $context, Operation $operation): array
    {
        return array_values(array_diff(GrantableOperation::lendable($operation), $context->scopes));
    }

    /**
     * The house's answer about this call — or `null`: no grant has anything to do with it, and nothing changes.
     *
     * @param array<string, mixed>                     $arguments
     * @param \Closure(OperationId|string): ?Operation $operationNamed the catalogue, asked for the instruments a grant names
     */
    public function decide(ToolContext $context, Operation $operation, array $arguments, \Closure $operationNamed): ?StandingDecision
    {
        $seat = self::seatOf($context);
        $book = $seat === null ? null : $this->book();
        if ($seat === null || $book === null) {
            return null;
        }
        $call = new StandingCall(
            $operation->name,
            $arguments,
            $seat,
            $operation->actsOnDigest($arguments),
            $operation->ceilingForCall($arguments),
            $this->now(),
        );
        // A book that does not verify: the house's refusal of a seat, or nothing at all for a person acting for
        // themselves ({@see self::stops()}, which asks the same of a caller with no call in hand). Either way no
        // grant in it is read.
        $fault = $book->ledger()->fault();
        if ($fault !== null) {
            return $context->hasScope(StandingBook::AUTHORITY) ? null : new StandingDecision($call, stopped: self::unverified($fault));
        }
        $concerned = false;
        foreach ($book->documents() as $grant) {
            $concerned = $concerned || ($grant->lendsTo($seat) && $grant->namesOperation($operation->name));
        }
        if (!$concerned) {
            return null;
        }

        // Every grant that may concern this call is judged alone, and the contract says how their answers combine
        // ({@see StandingJudge::among()}): a suspended grant that names the call refuses it whatever another would
        // lend, otherwise one that passes is enough, otherwise the refusal that got furthest speaks.
        $receipts = $book->receipts();
        $held = [];
        $records = [];
        foreach ($book->records() as $record) {
            if (!$record->grant->lendsTo($seat) || !$record->grant->namesOperation($operation->name)) {
                continue;
            }
            // A RECEIPT COUNTS ONLY WHEN THE OPERATION THAT PRODUCED IT IS THE ONE THE GRANT WAS GIVEN OVER (third
            // review, M1; decided by Rod 2026-10-12). Every receipt carries the pin of what wrote it
            // ({@see self::witnessed()}), and the contract's judge is handed the pin the GRANT keeps of each
            // instrument: a measurement taken while other code held the instrument's name — softened and put back, or
            // the looser one a person has since granted again over — is «not that instrument any more».
            $instruments = [];
            foreach ($record->grant->conditions as $condition) {
                $pinned = $record->instruments[$condition->by->operation->canonical] ?? null;
                if ($pinned !== null) {
                    $instruments[$condition->by->operation->canonical] = $pinned;
                }
            }
            $held[] = [
                'grant' => $record->grant,
                // The book was asked first, above: it verifies, and every line of it reads as its kind today — a line
                // that did not would be a fault of the whole book, and no grant in it would have been read. That is
                // what this house says to the judge of its record of each grant, and it says it from the same answer.
                'standing' => new GrantStanding($record->state, $record->because, $this->faultOf($record, $operation, $operationNamed), $record->watcherSignalAt, $receipts, $instruments, recordFault: $fault),
            ];
            $records[$record->grant->id] = $record;
        }
        // THE HOUSE SAYS WHETHER ITS WHOLE RECORD READS, AND IT HAS TO (the contract's sixth review, M1). A document
        // it kept and can no longer read is no grant it could have put in this list, and it may be the one that names
        // this call, stopped. The contract's door for several grants asks for it with no default; this house answers
        // from what it asked of its book above — where a book that does not read already refused the seat, before
        // any grant in it was read.
        $answer = StandingJudge::among($held, $call, $fault);

        if ($answer === null) {
            return new StandingDecision($call);
        }
        if ($answer['grant'] === null) {
            // The judge speaks with no grant only for a record that does not read whole, and this house refused for
            // that before it asked. Should it ever be said here, it stops the seat as a book that does not verify does.
            return new StandingDecision($call, stopped: $answer['verdict']->sentence);
        }
        // A WORD IS LENT ONLY ON A GRANT THAT IS IN FORCE AND VERIFIED, and the contract's judge is who says so: the
        // grant that answers for this call got past its questions about the signature and about being in force —
        // whatever it found afterwards. When a suspended grant stops the call it is that grant that answers, so
        // nothing is lent then either, however many others name the call.
        $verdict = $answer['verdict'];
        $stands = $verdict->passes() || !\in_array($verdict->failed, [StandingCheck::Signature, StandingCheck::InForce], true);

        return new StandingDecision($call, $records[$answer['grant']->id], $verdict, lendable: $stands);
    }

    /**
     * The house's refusal of this caller because of THE BOOK ITSELF — it does not verify, and the caller is a seat —
     * or `null`: the book is whole, there is none, or the caller is not one it stops.
     *
     * A BOOK THAT DOES NOT VERIFY REFUSES EVERY ENROLLED SEAT, WHATEVER IT CALLS (greenhouse decisions/0615 §5). What
     * it holds cannot be told from what was put there, so the house cannot know which grant names what — and «nobody
     * granted anything» is the one reading it must not fall back to: the suspensions would vanish with the book for a
     * seat that holds a scope on its own.
     *
     * So this is a question about the caller and the book, with no call in it, and whoever decides anything for a
     * seat asks it FIRST: two doors answered before asking — promoting a trial, and a run the host's HTTP policy had
     * admitted — and a seat went through them with the book broken («Lo que la segunda revisión encontró», 5).
     *
     * It stops SEATS. A key that holds the word that recognizes identities is a person acting for themselves:
     * stopping their signed call would close the way to repair the book and protect nothing — it runs as it does in
     * a house that keeps no book. That is read from the ledger of identities, which does not depend on this book. (A
     * key that holds that word AND that a grant names as a seat goes through here too: whoever recognizes identities
     * can make a seat no grant names, so no grant ever bound it.)
     */
    public function stops(ToolContext $context): ?string
    {
        if (self::seatOf($context) === null || $context->hasScope(StandingBook::AUTHORITY)) {
            return null;
        }
        $fault = $this->book()?->ledger()->fault();

        return $fault === null ? null : self::unverified($fault);
    }

    private static function unverified(string $fault): string
    {
        return \sprintf(
            'The book of standing grants does not verify (%s), so this house cannot tell what any grant in it names. Nothing runs for a seat until a person has looked at it and repaired it.',
            $fault,
        );
    }

    /**
     * Why a grant is not honoured for this operation right now — its own signature, or the operation it covers — or
     * `null`: it is.
     *
     * THE FLOORS, AND WHAT THE OPERATION IS, ARE ASKED AGAIN NOW (review, 4). An operation is code and code is
     * replaced: one that has become ungrantable, or that is no longer the operation the grant was given over, is not
     * what a person granted — whatever its name still says.
     *
     * @param \Closure(OperationId|string): ?Operation $operationNamed the catalogue, asked for the instruments a grant names
     */
    private function faultOf(GrantRecord $record, Operation $operation, \Closure $operationNamed): ?string
    {
        if ($record->fault !== null) {
            return $record->fault;
        }
        $ungrantable = GrantableOperation::refusal($operation);
        if ($ungrantable !== null) {
            return $ungrantable . ' — it is not an operation a grant may lend any more';
        }
        $identity = new OperationId($operation->name);
        if (($record->operations[$identity->canonical] ?? null) !== OperationPin::of($operation, $this->root)) {
            return \sprintf('`%s` is not the operation it was when this grant was given: its declaration or its code changed since', $identity->forCli());
        }
        // …AND SO ARE WHAT IT IS MEASURED BY AND WHAT WATCHES IT (review, second, 4). An instrument or a watcher that
        // was replaced under its name is not the one a person granted over: until a person grants again, over what is
        // there now, the grant is not honoured — a softer instrument measures nothing for it.
        $watched = $record->grant->falsifier;
        $asked = array_map(static fn (GrantCondition $condition): array => [$condition->by->operation, \sprintf('which measures condition «%s»', $condition->name)], $record->grant->conditions);
        $asked[] = [$watched->watcher->operation, \sprintf('which watches falsifier «%s»', $watched->name)];
        foreach ($asked as [$named, $role]) {
            $today = $operationNamed($named);
            if ($today === null || ($record->instruments[$named->canonical] ?? null) !== OperationPin::of($today, $this->root)) {
                return \sprintf(
                    '`%s`, %s, %s — what a grant is measured and watched by is the operation it was given over, never whatever holds its name today',
                    $named->forCli(),
                    $role,
                    $today === null ? 'is not in this house any more' : 'is not the operation it was when this grant was given: its declaration or its code changed since',
                );
            }
        }

        return null;
    }

    /**
     * Why this judge does not honour a grant today, for each operation it covers that it does not honour — an empty
     * map when it honours them all. What the listing of grants says is what THIS says (third review, L3): the book
     * knows where a grant stands, and only the judge asks whether the operations it was given over are still those.
     *
     * @param \Closure(OperationId|string): ?Operation $operationNamed
     *
     * @return array<string, string> the operation, as typed at a terminal => the sentence
     */
    public function notHonoured(GrantRecord $record, \Closure $operationNamed): array
    {
        $why = [];
        foreach ($record->grant->covers as $act) {
            $operation = $operationNamed($act->operation);
            $fault = $operation === null
                ? $record->fault ?? \sprintf('`%s` is not in this house any more', $act->operation->forCli())
                : $this->faultOf($record, $operation, $operationNamed);
            if ($fault !== null) {
                $why[$act->operation->forCli()] = $fault;
            }
        }

        return $why;
    }

    /**
     * The judgment again, with the book locked, and — when it still passes — the line that says the house lets this
     * act pass, written under that same lock.
     *
     * BEFORE the act, and it throws when it cannot write: nothing is absorbed that the house cannot record. AGAIN,
     * because the first judgment read the book without a lock: a suspension written a moment later must not find
     * the act already on its way (review, 10).
     *
     * @param array<string, mixed>                     $arguments
     * @param \Closure(OperationId|string): ?Operation $operationNamed
     *
     * @return StandingDecision|null the judgment as it stands under the lock — `null` when no grant has to do with the call any more
     *
     * @throws \RuntimeException when the book cannot be written
     */
    public function absorbing(ToolContext $context, Operation $operation, array $arguments, \Closure $operationNamed): ?StandingDecision
    {
        $ledger = StandingLedger::forRoot($this->root);

        return $ledger->exclusively(function () use ($ledger, $context, $operation, $arguments, $operationNamed): ?StandingDecision {
            $decision = $this->decide($context, $operation, $arguments, $operationNamed);
            $record = $decision?->record;
            $verdict = $decision?->verdict;
            if ($decision === null || $record === null || $verdict === null || !$verdict->passes()) {
                return $decision;
            }
            $ledger->append(StandingLedger::ABSORBED, [
                'grant' => $record->grant->id,
                'operation' => $decision->call->operation->canonical,
                'arguments' => $decision->call->arguments,
                'seat' => $decision->call->seat,
                'subject' => $decision->call->subject,
                'receipts' => array_map(static fn (ConditionReceipt $receipt): array => $receipt->toArray(), $verdict->cited),
                'undo' => $record->grant->undo,
            ], $this->now());

            return $decision;
        });
    }

    /**
     * After an absorbed act ran: was what it acted on what was judged? If not, the grant is suspended, with what was
     * judged and what was found.
     *
     * Two readings, because one is blind to a swap that is undone. The house reads the content again — and the act
     * itself says, in `acted_on`, the digest of the bytes it consumed. An act that returned and does not say so is
     * treated as one that consumed something else: A → B → A leaves both of the house's readings equal (review, 3).
     * The act is not undone — this notices the swap, it does not prevent it.
     *
     * @param bool $returned whether the act returned at all; one that threw says nothing, and only the house's own
     *                       readings are compared
     *
     * @throws \RuntimeException when the swap was seen and the suspension could not be written
     */
    public function absorbed(StandingDecision $decision, Operation $operation, mixed $result, bool $returned): void
    {
        $record = $decision->record;
        if ($record === null || !$decision->passes()) {
            return;
        }
        $judged = (string) $decision->call->subject;
        $found = $operation->actsOnDigest($decision->call->arguments);
        $said = \is_array($result) && \is_string($result['acted_on'] ?? null) ? $result['acted_on'] : null;
        $name = $decision->call->operation->forCli();
        $reason = match (true) {
            $found !== $judged => \sprintf('what `%s` acted on changed between the judgment and the act: %s was judged, %s was there afterwards', $name, $judged, $found ?? 'nothing readable'),
            $returned && $said === null => \sprintf('`%s` did not say what it consumed (`acted_on`), so the house cannot tell it acted on %s, which was judged', $name, $judged),
            $returned && $said !== $judged => \sprintf('`%s` said it consumed %s, and %s was judged', $name, (string) $said, $judged),
            default => null,
        };
        if ($reason !== null) {
            StandingLedger::forRoot($this->root)->append(StandingLedger::SUSPENDED, ['grant' => $record->grant->id, 'by' => 'the house', 'reason' => $reason], $this->now());
        }
    }

    /**
     * Write down a refusal of a call that a grant names. A book that cannot be written does not turn a refusal into a pass.
     *
     * ONCE, AND NOT WITHOUT END (greenhouse decisions/0615, «Lo que la segunda revisión encontró», 8). Every call of
     * every seat reads the whole book, and a refused call used to add a line each time: a seat grew it for everyone by
     * asking again. So among the refusals the book ENDS with — nothing but refusals written since — the same one is
     * not written twice, and no more than {@see self::REFUSALS_KEPT} of ONE SEAT UNDER ONE GRANT are kept. The refusal
     * itself is given every time; what stops is the line. Whatever else is written — a measurement, a watcher's sign,
     * an act, a stop — ends that run, and what is refused after it is a new fact.
     *
     * AS NARROW AS IT READS (third review, L2; decided by Rod 2026-10-12). «The same» is the same line, byte for
     * byte in its canonical spelling: a refusal that differs in how one argument is written is another refusal.
     * The count is a seat's under a grant, so one seat's refusals never keep the book from saying another was
     * refused. And the last one kept says the rest are not written ({@see self::REFUSALS_END}): a reader of the book
     * knows where it stops being a count.
     */
    public function refused(StandingDecision $decision): void
    {
        $record = $decision->record;
        $verdict = $decision->verdict;
        if ($record === null || $verdict === null || $verdict->passes()) {
            return;
        }
        $ledger = StandingLedger::forRoot($this->root);
        $line = [
            'grant' => $record->grant->id,
            'operation' => $decision->call->operation->canonical,
            'arguments' => $decision->call->arguments,
            'seat' => $decision->call->seat,
            'subject' => $decision->call->subject,
            'check' => $verdict->failed?->value,
            'reason' => $verdict->reason,
        ];
        try {
            $same = Canonical::json($line);
        } catch (\JsonException) {
            return; // a refusal the book could not hold as a line: it stands without one
        }
        $kept = 0;
        foreach (array_reverse($ledger->entries()) as $entry) {
            if ($entry['type'] !== StandingLedger::REFUSED) {
                break;
            }
            $written = $entry['data'];
            if (($written['seat'] ?? null) !== $line['seat'] || ($written['grant'] ?? null) !== $line['grant']) {
                continue;
            }
            if (Canonical::json($written) === $same || ++$kept >= self::REFUSALS_KEPT) {
                return;
            }
        }
        if ($kept === self::REFUSALS_KEPT - 1) {
            $line['then'] = self::REFUSALS_END;
        }
        try {
            $ledger->append(StandingLedger::REFUSED, $line, $this->now());
        } catch (\RuntimeException) {
            // The refusal stands without its line: a book that cannot be written does not turn a no into a yes.
        }
    }

    /**
     * What the house must note about a call BEFORE it runs, when some grant describes it as the operation that
     * measures a condition or watches a falsifier — or `null`: none does, and the run leaves no trace here.
     *
     * «Describes» is a closed match: the call carries the arguments the grant fixes, the ones it leaves free, and
     * no other. A run with an argument nobody described is not that instrument being used (review, 5).
     *
     * FOR WHAT SPEAKS FOR A GRANT. WHAT STOPS ONE IS ANY RUN OF THE WATCHER (third review, M2; decided by Rod
     * 2026-10-12: «a watcher that says it fired ALWAYS stops» — read as the same «always» he completed for a red
     * receipt: from any run). A run of the operation that watches a falsifier is noted whatever arguments it carries
     * — a flag of the surface it was typed at, the argument the falsifier fixes missing or at another value —
     * (`stops`), because if it answers «fired» that answer stops the grant. The closed match was applied to both
     * answers, and the one that only narrows was thrown away.
     *
     * AND FOR A MEASUREMENT, ANY RUN OF THE INSTRUMENT (decided by Rod 2026-10-12: a red receipt counts from any
     * run). A run of the operation that measures a condition is noted whatever arguments it carries — one more, the
     * fixed one missing or at another value (`refutes`): if it answers NO, that is the instrument saying no of that
     * very content, and the contract's judge stops the act on it until the run the condition describes measures the
     * content again. A YES from such a run is never written down.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{measures: bool, refutes: bool, watches: bool, stops: bool, subject: ?string}|null
     */
    public function witnessing(Operation $operation, array $arguments): ?array
    {
        $measures = false;
        $refutes = false;
        $watches = false;
        $stops = false;
        foreach ($this->book()?->documents() ?? [] as $grant) {
            foreach ($grant->conditions as $condition) {
                $measures = $measures || $condition->by->matchesExactly($operation->name, $arguments);
                $refutes = $refutes || $condition->by->operation->is($operation->name);
            }
            $watches = $watches || $grant->falsifier->watcher->matchesExactly($operation->name, $arguments);
            $stops = $stops || $grant->falsifier->watcher->operation->is($operation->name);
        }

        return $refutes || $stops
            ? ['measures' => $measures, 'refutes' => $refutes, 'watches' => $watches, 'stops' => $stops, 'subject' => $refutes ? $operation->actsOnDigest($arguments) : null]
            : null;
    }

    /**
     * Write down what a measuring or a watching operation just answered — the house's own reading of its result,
     * never a field a caller filled in.
     *
     * A measurement becomes a receipt only when the operation can be pinned as an instrument, the content is the
     * same before and after its run, AND the instrument says, in `acted_on`, that those are the bytes it read: the
     * house's two readings cannot see content swapped and swapped back around the instrument's own (review, 3). A
     * watcher's answer counts only when it says `fired` as true or false — and `true` IS the suspension of every
     * grant in force whose falsifier it watches: the book derives it from this very line (review, 7).
     *
     * EVERY RECEIPT AND EVERY SIGN CARRIES THE PIN OF WHAT PRODUCED IT (third review, M1; decided by Rod 2026-10-12):
     * the house's own digest of the operation that just ran ({@see OperationPin}), not the contract's — which does
     * not change when the method a one-line handler calls does. What reads them counts only the ones whose pin is
     * the grant's. An instrument the house cannot pin leaves no receipt, and a watcher it cannot pin gives no sign of
     * life — but «fired» is written whoever answered it: stopping asks for nobody's pin.
     *
     * @param array{measures: bool, refutes: bool, watches: bool, stops: bool, subject: ?string} $noted     what {@see self::witnessing()} noted before the run
     * @param array<string, mixed>                                                               $arguments
     *
     * @throws \RuntimeException when the house saw something and could not write it down
     */
    public function witnessed(array $noted, Operation $operation, array $arguments, mixed $result, ?ToolContext $context): void
    {
        $ledger = StandingLedger::forRoot($this->root);
        $now = $this->now();
        $who = $context === null ? 'local-shell' : ($context->principal ?? 'unknown');
        $pin = OperationPin::of($operation, $this->root);
        $instrument = $operation->instrumentDigest() === null ? null : $pin;
        $subject = $noted['subject'];
        // A run that is exactly the one a condition describes leaves its receipt, green or red. Any other run of
        // that instrument leaves one when it says NO, and never when it says yes.
        $speaks = $noted['measures'] || ($noted['refutes'] && \is_array($result) && ($result['ok'] ?? null) !== true);
        if ($speaks && $subject !== null && $instrument !== null
            && $operation->actsOnDigest($arguments) === $subject
            && \is_array($result) && ($result['acted_on'] ?? null) === $subject) {
            $ledger->append(StandingLedger::MEASURED, (new ConditionReceipt(
                new OperationId($operation->name),
                $arguments,
                $subject,
                ($result['ok'] ?? null) === true,
                $instrument,
                $who,
                $now,
            ))->toArray(), $now);
        }

        $fired = \is_array($result) ? ($result['fired'] ?? null) : null;
        if ($fired === true ? $noted['stops'] : ($fired === false && $noted['watches'] && $pin !== null)) {
            $ledger->append(StandingLedger::WATCHED, [
                'watcher' => (new OperationId($operation->name))->canonical,
                'arguments' => $arguments,
                'fired' => $fired,
                'who' => $who,
                'pin' => $pin,
            ], $now);
        }
    }

    /** The context without the words that were lent to it for the asking — the caller as it is on its own. */
    public static function withoutLent(ToolContext $context): ToolContext
    {
        $mark = $context->extra[self::LENT] ?? null;
        if (!\is_array($mark)) {
            return $context;
        }
        $lent = \is_array($mark['scopes'] ?? null) ? $mark['scopes'] : [];
        $extra = $context->extra;
        unset($extra[self::LENT]);

        return new ToolContext(
            principal: $context->principal,
            channel: $context->channel,
            scopes: array_values(array_diff($context->scopes, $lent)),
            request_id: $context->request_id,
            ip: $context->ip,
            userAgent: $context->userAgent,
            extra: $extra,
            mode: $context->mode,
            resultBudget: $context->resultBudget,
        );
    }

    /**
     * The same context, seen by the gate holding these words for this one tool — and marked so, so that whoever reads
     * it next can take them back.
     *
     * @param list<string> $words
     */
    public static function withLent(ToolContext $context, string $tool, array $words): ToolContext
    {
        return new ToolContext(
            principal: $context->principal,
            channel: $context->channel,
            scopes: [...$context->scopes, ...$words],
            request_id: $context->request_id,
            ip: $context->ip,
            userAgent: $context->userAgent,
            extra: [self::LENT => ['tool' => $tool, 'scopes' => $words]] + $context->extra,
            mode: $context->mode,
            resultBudget: $context->resultBudget,
        );
    }

    /**
     * The seat behind a caller: a key, as the book would name it — or `null` for whoever is not a key. What the key
     * holds is not asked here: a grant governs the seat it names, even one that holds every scope.
     */
    public static function seatOf(ToolContext $context): ?string
    {
        $principal = $context->principal;
        if (!\is_string($principal) || !str_starts_with($principal, 'key:') || \strlen($principal) === 4) {
            return null;
        }

        return IdentityKey::normalize(substr($principal, 4));
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock === null ? new \DateTimeImmutable() : ($this->clock)();
    }
}
