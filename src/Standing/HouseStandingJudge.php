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
use Milpa\Command\Standing\GrantState;
use Milpa\Command\Standing\NamedCall;
use Milpa\Command\Standing\StandingCall;
use Milpa\Command\Standing\StandingCheck;
use Milpa\Command\Standing\StandingJudge;
use Milpa\Command\Standing\StandingVerdict;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\SignatureVerifier;
use Milpa\ToolRuntime\PolicyGate;

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
    public const REFUSALS_END = 'refusals of this seat under this grant are not written after this one, until the standing of the grant changes';

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
    public function offers(ToolContext $context, Operation $operation, \Closure $operationNamed, ?\Closure $answeredTwice = null): array
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
            if ($record->grant->lendsTo($seat) && $record->grant->namesOperation($operation->name) && $this->faultOf($record, $operation, $operationNamed, $answeredTwice) === null && $record->inForceAt($now)) {
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
     * @param array<string, mixed>                         $arguments
     * @param \Closure(OperationId|string): ?Operation     $operationNamed the catalogue, asked for the instruments a grant names
     * @param (\Closure(OperationId|string): ?string)|null $answeredTwice  the catalogue, asked why a name is one two
     *                                                                     operations answer to ({@see self::faultOf()})
     */
    public function decide(ToolContext $context, Operation $operation, array $arguments, \Closure $operationNamed, ?\Closure $answeredTwice = null): ?StandingDecision
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
                'standing' => new GrantStanding($record->state, $record->because, $this->faultOf($record, $operation, $operationNamed, $answeredTwice), $record->watcherSignalAt, $receipts, $instruments, recordFault: $fault),
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
            return $this->governedUnderDoubt($context, $operation, $call, $held, $records) ?? new StandingDecision($call);
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
     * DECIDED BY ROD, 2026-10-12, NARROW (greenhouse decisions/0615, D10 and D13) — with the contract's «under doubt,
     * a stop stops». The house's
     * refusal of a call NO grant names exactly, made by a seat that holds the word on its own, while a grant IN FORCE
     * may be the one that names it — or `null`: there is no such grant, or the seat's own word would not decide.
     *
     * THE RULE OF THIS HOUSE, AND IT IS NARROW. A grant governs the seat it names whatever that seat holds on its
     * own: a condition that a word given separately walks around is not a condition. That held for a call said
     * exactly as the grant says it. Said another way — the argument the grant fixes left out, or empty, or in another
     * kind — the call was «nobody's», the seat's own word decided, and the conditions were left behind by not
     * saying one argument. The contract hands a house the question ({@see StandingJudge::mayBeNaming()}) and leaves
     * the answer to it. THIS HOUSE REFUSES, and says how the call is said so that it is one thing or the other.
     *
     *   · ONLY A GRANT IN FORCE AND HONOURED NOW — the one a word would be lent on ({@see self::offers()}). A grant
     *     that is revoked, superseded, past its date or not honoured governs nothing; a stopped one has already
     *     spoken through the judge.
     *   · ONLY WHERE THE SEAT'S OWN WORD WOULD DECIDE. A seat that lacks the word is refused at the gate as it
     *     always was, in the gate's own sentence: nothing could be walked around there.
     *   · NOTHING IS LENT ON IT, and nothing passes on it: it is a refusal, written down as the refusals of a grant
     *     are.
     *
     * AND A GRANT THAT IS GONE GOVERNS NOTHING UNDER DOUBT (D13): past its date or revoked, it lends nothing and
     * stops nobody here — while a call said EXACTLY under one is still refused to the seat it names, as it always was.
     *
     * @param list<array{grant: \Milpa\Command\Standing\StandingGrant, standing: GrantStanding}> $held
     * @param array<string, GrantRecord>                                                         $records
     */
    private function governedUnderDoubt(ToolContext $context, Operation $operation, StandingCall $call, array $held, array $records): ?StandingDecision
    {
        // The gate's own question about a word, asked of the caller as it is on its own.
        if (!(new PolicyGate())->authorizeScopes(self::withoutLent($context), $operation->name, $operation->scopes)->allowed) {
            return null;
        }
        foreach ($held as ['grant' => $grant, 'standing' => $standing]) {
            if ($standing->signatureFault !== null || !$records[$grant->id]->inForceAt($call->at) || !StandingJudge::mayBeNaming($grant, $call)) {
                continue;
            }

            return new StandingDecision($call, $records[$grant->id], StandingVerdict::refuse(StandingCheck::Named, 'named_under_doubt', \sprintf(
                'Standing grant «%1$s» is in force and may be the one that names this call: it lends to this seat and covers `%2$s`, and the call does not say, beyond doubt, another value for what it fixes. What a grant may be naming is not done on a seat\'s own word: its conditions would be left behind. Say each argument «%1$s» fixes as it fixes it, and the call is judged under that grant; or say another value for one of them — of the same kind, spelled as a grant spells it — and the call is this seat\'s own.',
                $grant->id,
                Canonical::short($call->operation->forCli()),
            )));
        }

        return null;
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

    /**
     * Whether this caller is one a grant can name, in a house that keeps a book: an enrolled key that is not a
     * person. The operator's own terminal and a person are not this judge's to stop.
     */
    public function couldName(ToolContext $context): bool
    {
        return self::seatOf($context) !== null && !$context->hasScope(StandingBook::AUTHORITY) && $this->book() !== null;
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
    private function faultOf(GrantRecord $record, Operation $operation, \Closure $operationNamed, ?\Closure $answeredTwice = null): ?string
    {
        if ($record->fault !== null) {
            return $record->fault;
        }
        // A NAME TWO OPERATIONS ANSWER TO IS NOBODY'S TO GRANT OVER (fourth review, M4). The judge reads one of them
        // under that name and a terminal runs the other; a house takes such a catalogue as the published runtime
        // does, and it is here that it is not taken — for the operation a grant covers, for what measures it and for
        // what watches it. Until one of the two takes another name, the grant is not honoured.
        $named = [new OperationId($operation->name), ...array_map(static fn (GrantCondition $condition): OperationId => $condition->by->operation, $record->grant->conditions), $record->grant->falsifier->watcher->operation];
        foreach ($answeredTwice === null ? [] : $named as $name) {
            $twice = $answeredTwice($name);
            if ($twice !== null) {
                return $twice . ' — no grant is honoured over that name until one of them takes another';
            }
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
    public function notHonoured(GrantRecord $record, \Closure $operationNamed, ?\Closure $answeredTwice = null): array
    {
        $why = [];
        foreach ($record->grant->covers as $act) {
            $operation = $operationNamed($act->operation);
            $fault = $operation === null
                ? $record->fault ?? \sprintf('`%s` is not in this house any more', $act->operation->forCli())
                : $this->faultOf($record, $operation, $operationNamed, $answeredTwice);
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
    public function absorbing(ToolContext $context, Operation $operation, array $arguments, \Closure $operationNamed, ?\Closure $answeredTwice = null): ?StandingDecision
    {
        $ledger = StandingLedger::forRoot($this->root);

        return $ledger->exclusively(function () use ($ledger, $context, $operation, $arguments, $operationNamed, $answeredTwice): ?StandingDecision {
            $decision = $this->decide($context, $operation, $arguments, $operationNamed, $answeredTwice);
            $record = $decision?->record;
            $verdict = $decision?->verdict;
            if ($decision === null || $record === null || $verdict === null || !$verdict->passes()) {
                return $decision;
            }
            // WHAT THE HOUSE WILL NOT WRITE DOWN, IT DOES NOT LET PASS (a proposal, not decided). The line of an act
            // carries what it was called with, a seat chooses that, and every command reads every line: one act with
            // four megabytes of an argument the grant does not fix left a line of four megabytes. A call that long
            // is a person's to make.
            $carried = self::given($decision->call->arguments)['bytes'] ?? 0;
            if ($carried > self::ACT_ARGUMENTS_KEPT) {
                throw new \RuntimeException(\sprintf(
                    'Standing grant «%s» lends nothing to this call of `%s`: it carries %d bytes of arguments, and the house writes an act down, with what it was called with, before it runs — at most %d. A person makes a call that long. Nothing ran.',
                    $record->grant->id,
                    $decision->call->operation->forCli(),
                    $carried,
                    self::ACT_ARGUMENTS_KEPT,
                ));
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
        // WHAT AN ACT SAID IS WRITTEN AS THE HOUSE SPELLS IT (fourth review, L1). `acted_on` is the act's own answer,
        // and it came into the reason of this stop as it came: bytes that are not text made a line the book cannot
        // hold, the stop was dropped, and the act could be made again — three times, measured.
        $spelled = static fn (?string $digest): string => StandingBook::said($digest, 'nothing readable');
        $reason = match (true) {
            $found !== $judged => \sprintf('what `%s` acted on changed between the judgment and the act: %s was judged, %s was there afterwards', $name, $spelled($judged), $spelled($found)),
            $returned && $said === null => \sprintf('`%s` did not say what it consumed (`acted_on`), so the house cannot tell it acted on %s, which was judged', $name, $spelled($judged)),
            $returned && $said !== $judged => \sprintf('`%s` said it consumed %s, and %s was judged', $name, $spelled($said), $spelled($judged)),
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
        // A REFUSAL IS A LINE LONG TOO (a proposal, not decided): it is only a record, so arguments longer than a
        // line keeps of one are kept as their spelling, with how long they were and their digest. Two refusals that
        // differ past what a line keeps still differ by that digest — they are two lines, as they were.
        $given = self::given($line['arguments']);
        if (($given['bytes'] ?? 0) > self::ARGUMENT_KEPT) {
            $line['arguments'] = [self::NOT_KEPT => Canonical::shown($line['arguments'])];
            $line['arguments_given'] = $given;
        }
        try {
            $same = Canonical::json($line);
        } catch (\JsonException) {
            return; // a refusal the book could not hold as a line: it stands without one
        }
        // COUNTED OVER WHAT THE GRANT'S STANDING WAS, NOT OVER THE TAIL OF THE BOOK (a proposal, not decided — the
        // fifth review, 5-M2). The count ended at the first line of another kind, anyone's: a seat with no word of
        // its own wrote its refusals again for each line the house wrote for somebody else. What makes a refusal a
        // new fact is that the grant's standing changed — it was given, stopped, lifted, withdrawn, or a falsifier
        // fired — so the count, and «the same one is not written twice», run back to the last of those.
        $kept = 0;
        foreach (array_reverse($ledger->entries()) as $entry) {
            if ($entry['type'] === StandingLedger::GRANTED || ($entry['type'] === StandingLedger::WATCHED && ($entry['data']['fired'] ?? null) === true)
                || (\in_array($entry['type'], [StandingLedger::SUSPENDED, StandingLedger::REINSTATED, StandingLedger::REVOKED], true) && ($entry['data']['grant'] ?? null) === $line['grant'])) {
                break;
            }
            if ($entry['type'] !== StandingLedger::REFUSED) {
                continue;
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
     * AND WHICH ARGUMENTS SOME GRANT DESCRIBES FOR IT (`described`): the names a condition or a falsifier fixes or
     * leaves free for this operation. A line that only narrows keeps those, and the spelling of the rest
     * ({@see self::narrowing()} — a proposal, not decided).
     *
     * @return array{measures: bool, refutes: bool, watches: bool, stops: bool, subject: ?string, described: list<string>}|null
     */
    public function witnessing(Operation $operation, array $arguments): ?array
    {
        $measures = false;
        $refutes = false;
        $watches = false;
        $stops = false;
        $described = [];
        foreach ($this->book()?->documents() ?? [] as $grant) {
            foreach ([...array_map(static fn (GrantCondition $condition): NamedCall => $condition->by, $grant->conditions), $grant->falsifier->watcher] as $named) {
                if ($named->operation->is($operation->name)) {
                    $described = [...$described, ...array_map(strval(...), array_keys($named->arguments)), ...$named->free];
                }
            }
            foreach ($grant->conditions as $condition) {
                $measures = $measures || $condition->by->matchesExactly($operation->name, $arguments);
                $refutes = $refutes || $condition->by->operation->is($operation->name);
            }
            $watches = $watches || $grant->falsifier->watcher->matchesExactly($operation->name, $arguments);
            $stops = $stops || $grant->falsifier->watcher->operation->is($operation->name);
        }

        return $refutes || $stops
            ? ['measures' => $measures, 'refutes' => $refutes, 'watches' => $watches, 'stops' => $stops, 'subject' => $refutes ? $operation->actsOnDigest($arguments) : null, 'described' => array_values(array_unique($described))]
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
     * AND THE PIN OF WHAT PRODUCED IT IS THE ONE THE PROCESS THAT ANSWERED HAD LOADED (fourth review, M1). The pin
     * was read from the disk AFTER the run, and a process that lives long answers with the code it loaded: a yes
     * from a guard loaded before it was hardened was written under the pin of the hardened one. So a YES and a SIGN
     * OF CALM are written only when the pin read now is the one this process read when it was told of the operation
     * (`$loaded`) — a process that has none to give, because it started before its house kept a book, writes neither.
     * A no and a «fired» stay as they were: they only narrow.
     *
     * AND FROM A RUN THAT LEFT THIS PROCESS, ONLY WHAT NARROWS (fourth review, H1; decided by Rod, 2026-10-12: D14): work a
     * leg ran confined in the house, a rehearsal in a copy. The house did not run that code and has no pin of what
     * was loaded to give for it — whoever hands such a run in says so with `null` — so its «fired» stops, its no is a
     * red receipt, and its yes and its sign of calm are never written: for a green, a candidate is measured in the
     * house.
     *
     * AND WHAT A SEAT CAN MAKE THE BOOK KEEP IS BOUNDED (A PROPOSAL, NOT DECIDED — the sibling of the fourth review's
     * M3, found by sweeping every kind of line by who can make the house write it). Every call of every seat reads
     * the whole book, and a receipt or a sign carried the arguments of its run whole — one «no» with four megabytes
     * of an argument nobody described left a line of four megabytes — and was written at every run, the same answer
     * of the same key over the same thing included. So:
     *
     *  - a line that only narrows keeps the arguments a grant describes, each as long as a line keeps
     *    ({@see self::ARGUMENT_KEPT}), and the spelling of the rest, with how long they were and their digest
     *    ({@see self::narrowing()});
     *  - a no of a key that already said no of that content, with that instrument, and no yes since, is answered
     *    and not written; so is a «fired» of a key that already said so, while no grant that operation watches is in
     *    force and none was given or lifted since. Red, green, red writes: what the book says changed;
     *  - a yes is the run a condition describes, so it needs its arguments whole: it is the ARGUMENT that is
     *    bounded, and over the bound there is no green.
     *
     * None of it loses a stop or a red: what would have stopped something is written, and what is not written
     * would have changed nothing the book says. What is read and what is then written are one step, with the book
     * locked — a yes that lands between the two must not be followed by a no that was taken for the one before it.
     *
     * @param array{measures: bool, refutes: bool, watches: bool, stops: bool, subject: ?string, described?: list<string>} $noted     what {@see self::witnessing()} noted before the run
     * @param array<string, mixed>                                                                                         $arguments
     * @param string|null                                                                                                  $loaded    the pin of the operation as the process that ran it read it when it was told of it — or `null`: it has none to give, or the run left this process
     *
     * @throws \RuntimeException when the house saw something and could not write it down
     */
    public function witnessed(array $noted, Operation $operation, array $arguments, mixed $result, ?ToolContext $context, ?string $loaded): void
    {
        $ledger = StandingLedger::forRoot($this->root);
        $ledger->exclusively(function () use ($ledger, $noted, $operation, $arguments, $result, $context, $loaded): void {
            $this->written($ledger, $noted, $operation, $arguments, $result, $context, $loaded);
        });
    }

    /**
     * {@see self::witnessed()}, with the book locked.
     *
     * @param array{measures: bool, refutes: bool, watches: bool, stops: bool, subject: ?string, described?: list<string>} $noted
     * @param array<string, mixed>                                                                                         $arguments
     */
    private function written(StandingLedger $ledger, array $noted, Operation $operation, array $arguments, mixed $result, ?ToolContext $context, ?string $loaded): void
    {
        $now = $this->now();
        $described = $noted['described'] ?? [];
        $name = (new OperationId($operation->name))->canonical;
        $who = $context === null ? 'local-shell' : ($context->principal ?? 'unknown');
        $pin = OperationPin::of($operation, $this->root);
        $subject = $noted['subject'];
        // What speaks FOR a grant — a yes, a sign of calm — is taken from this process, as the code it loaded: the
        // pin this process answers for, or none.
        $mine = $pin !== null && $pin === $loaded ? $pin : null;
        $no = \is_array($result) && ($result['ok'] ?? null) !== true;
        $said = \is_array($result) && \is_string($result['acted_on'] ?? null) ? $result['acted_on'] : null;
        $receipt = static fn (string $content, bool $green, string $by): \Closure => static fn (array $carried): array => (new ConditionReceipt($operation->name, $carried, $content, $green, $by, $who, $now))->toArray();
        if ($no && $noted['refutes']) {
            // A NO IS NEVER LOST (decided by Rod, 2026-10-12: D14 — «a no is heard and stops, whatever run it comes
            // from»). It is kept for every content it can be about: the one the HOUSE read for this call before the
            // run, the one it reads after it, and the one the run says it read — a rehearsal measures a copy, and a
            // copy may hold other bytes than the house. A no asks for nothing else: not for the run's `acted_on` to
            // agree, which is what a YES owes, and not for the instrument to be pinned — a receipt of an instrument
            // that is not the grant's refuses the act all the same. It can never turn anything green. When the house
            // reads no content for the call and the run names none, there is no candidate to stop, and no receipt.
            //
            // What a RUN says it read is one digest, a line long: an answer longer than a line keeps names no
            // content the house could ever hold a candidate of, and is not written.
            $about = array_filter([$subject, $operation->actsOnDigest($arguments), $said], static fn (?string $content): bool => $content !== null && trim($content) !== '' && Canonical::oneLine($content) && \strlen($content) <= self::ARGUMENT_KEPT);
            foreach (array_values(array_unique($about)) as $content) {
                if (!self::saidNoAlready($ledger->entries(), $name, $content, $who, $pin ?? self::NOT_PINNED)) {
                    self::narrowing($ledger, StandingLedger::MEASURED, $receipt($content, false, $pin ?? self::NOT_PINNED), $arguments, $described, $now);
                }
            }
        } elseif (!$no && $noted['measures'] && $mine !== null && $subject !== null && self::aLineKeeps($arguments)
            && $operation->actsOnDigest($arguments) === $subject && $said === $subject) {
            // A YES is a receipt only from the run a condition describes, made in this process by the code it loaded,
            // over content that is the same before and after, which the instrument says are the bytes it read — and
            // (a proposal, not decided) with every argument as long as a line keeps: a yes needs its arguments whole
            // to be the run a condition describes, so over that bound there is no green; and not the same yes of the
            // same key twice while nobody said no of that content in between ({@see self::saidYesAlready()}). The
            // receipt is given the NAME, as the catalogue spells it: the contract makes the identity from the text
            // after looking at it, and takes no identity built elsewhere (its seventh review).
            if (!self::saidYesAlready($ledger->entries(), $name, $subject, $who, $mine, $arguments)) {
                $ledger->append(StandingLedger::MEASURED, $receipt($subject, true, $mine)($arguments), $now);
            }
        }

        $fired = \is_array($result) ? ($result['fired'] ?? null) : null;
        // «All is calm» speaks FOR a grant, as a yes does: from this process, by the run the falsifier describes —
        // and (a proposal, not decided) with every argument as long as a line keeps.
        if ($fired === true
            ? $noted['stops'] && !$this->saidFiredAlready($ledger->entries(), $operation, $name, $who)
            : ($fired === false && $noted['watches'] && $mine !== null && self::aLineKeeps($arguments) && !$this->saidCalmJustNow($ledger->entries(), $operation, $name, $who, $mine, $arguments, $now))) {
            $sign = static fn (array $carried): array => [
                'watcher' => $name,
                'arguments' => $carried,
                'fired' => $fired,
                'who' => $who,
                'pin' => $pin,
            ];
            $fired
                ? self::narrowing($ledger, StandingLedger::WATCHED, $sign, $arguments, $described, $now)
                : $ledger->append(StandingLedger::WATCHED, $sign($arguments), $now);
        }
    }

    /**
     * Whether this key already said no of this content, with this instrument, and nobody said yes of it since
     * (a proposal, not decided).
     *
     * Read from the end of the book: the first yes found for that operation and that content means what the book
     * says of it changed since — and a no after a yes is a new fact, to be written. Another key's no, or the same
     * key's with another instrument, is another witness: it does not stand for this one.
     *
     * @param list<array{type: string, data: array<string, mixed>}> $entries
     */
    private static function saidNoAlready(array $entries, string $by, string $content, string $who, string $instrument): bool
    {
        for ($at = \count($entries) - 1; $at >= 0; --$at) {
            $data = $entries[$at]['data'];
            if ($entries[$at]['type'] !== StandingLedger::MEASURED || ($data['by'] ?? null) !== $by || ($data['subject'] ?? null) !== $content) {
                continue;
            }
            if (($data['ok'] ?? null) !== false) {
                return false;
            }
            if (($data['who'] ?? null) === $who && ($data['instrument'] ?? null) === $instrument) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this key already said yes of this content, by this instrument, with these arguments, and nobody said
     * no of it since (a proposal, not decided).
     *
     * A receipt does not age, so the same yes says nothing the book does not say already. Read from the end of the
     * book: the first no found for that operation and that content means what the book says of it changed — and a
     * yes after a no is what lets an act pass again, to be written. Another key's yes is another witness.
     *
     * @param list<array{type: string, data: array<string, mixed>}> $entries
     * @param array<string, mixed>                                  $arguments
     */
    private static function saidYesAlready(array $entries, string $by, string $content, string $who, string $instrument, array $arguments): bool
    {
        for ($at = \count($entries) - 1; $at >= 0; --$at) {
            $data = $entries[$at]['data'];
            if ($entries[$at]['type'] !== StandingLedger::MEASURED || ($data['by'] ?? null) !== $by || ($data['subject'] ?? null) !== $content) {
                continue;
            }
            if (($data['ok'] ?? null) !== true) {
                return false;
            }
            if (($data['who'] ?? null) === $who && ($data['instrument'] ?? null) === $instrument && ($data['arguments'] ?? null) == $arguments) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this key gave this very sign of calm so recently that another would move nothing worth a line
     * (a proposal, not decided — and the one bound here that is a trade, not a repetition).
     *
     * A sign of life is a TIME: the book keeps a grant watched for its falsifier's window after the last one. So a
     * sign is never «the same» as the one before — but one written a minute after another moves that time by a
     * minute, and a watcher run every minute wrote sixty lines an hour that every command reads. It is written again
     * once the last one of that key is a quarter of the SHORTEST window among the grants that run watches old.
     *
     * ONE KEY'S OWN, AND NEVER ACROSS WHAT CHANGED A GRANT'S STANDING. Another key's sign is another's, so no key
     * keeps a sign somebody else needs out of the book. And reading from the end of the book, anything that is not a
     * receipt, a refusal, an act or a sign of calm — a grant given, a stop, a lift, a withdrawal, a «fired» — means
     * «write»: a grant a person lifted is in force and unwatched until its watcher speaks again.
     *
     * What it costs: the time the book holds may be up to a quarter of the window older than the watcher's last
     * run. It never makes a grant read as watched for longer than a written sign says.
     *
     * @param list<array{type: string, at: string, data: array<string, mixed>}> $entries
     * @param array<string, mixed>                                              $arguments
     */
    private function saidCalmJustNow(array $entries, Operation $operation, string $watcher, string $who, string $pin, array $arguments, \DateTimeImmutable $now): bool
    {
        $window = null;
        foreach ($this->book()?->documents() ?? [] as $grant) {
            if ($grant->falsifier->watcher->matchesExactly($operation->name, $arguments)) {
                $window = min($window ?? $grant->falsifier->withinSeconds, $grant->falsifier->withinSeconds);
            }
        }
        if ($window === null) {
            return false;
        }
        for ($at = \count($entries) - 1; $at >= 0; --$at) {
            $data = $entries[$at]['data'];
            if (\in_array($entries[$at]['type'], [StandingLedger::MEASURED, StandingLedger::REFUSED, StandingLedger::ABSORBED], true)) {
                continue;
            }
            if ($entries[$at]['type'] !== StandingLedger::WATCHED || ($data['fired'] ?? null) !== false) {
                return false;
            }
            if (($data['watcher'] ?? null) === $watcher && ($data['who'] ?? null) === $who && ($data['pin'] ?? null) === $pin && ($data['arguments'] ?? null) == $arguments) {
                $last = date_create_immutable($entries[$at]['at']);

                return $last !== false && $now->getTimestamp() - $last->getTimestamp() < intdiv($window, self::SIGNS_A_WINDOW);
            }
        }

        return false;
    }

    /** How many signs of calm of one key the book keeps in one window of a falsifier (a proposal, not decided). */
    public const SIGNS_A_WINDOW = 4;

    /**
     * Whether this key's «fired» would stop nothing it has not already said stopped (a proposal, not decided).
     *
     * The book stops, on a «fired», every grant whose falsifier names the operation and that is in force, or
     * stopped by something a person could lift that is not itself a «fired» — and reads nothing else into that line
     * ({@see StandingBook}). So while there is one, the line is what stops it, and it is always written. With none,
     * the same key saying so again changes nothing the book says — unless a
     * grant was given or a stop was lifted since it last did, which is read from the end of the book and means
     * «write».
     *
     * @param list<array{type: string, data: array<string, mixed>}> $entries
     */
    private function saidFiredAlready(array $entries, Operation $operation, string $watcher, string $who): bool
    {
        foreach ($this->book()?->records() ?? [] as $record) {
            // …or one stopped by something a person could lift by name that is not a «fired»: this line takes its
            // place, so it is what stops it (the fifth review, 5-L2).
            $itStops = $record->state === GrantState::InForce || ($record->state === GrantState::Suspended && !$record->stopped && !$record->fired);
            if ($itStops && $record->grant->falsifier->watcher->operation->is($operation->name)) {
                return false;
            }
        }
        for ($at = \count($entries) - 1; $at >= 0; --$at) {
            $data = $entries[$at]['data'];
            if (\in_array($entries[$at]['type'], [StandingLedger::GRANTED, StandingLedger::REINSTATED], true)) {
                return false;
            }
            if ($entries[$at]['type'] === StandingLedger::WATCHED && ($data['fired'] ?? null) === true && ($data['watcher'] ?? null) === $watcher && ($data['who'] ?? null) === $who) {
                return true;
            }
        }

        return false;
    }

    /** How many bytes of ONE argument, as the book spells it, a receipt or a sign keeps as it came (a proposal, not decided). */
    public const ARGUMENT_KEPT = 256;

    /** How many bytes of arguments, as the book spells them, the house writes down of an act before it runs — and of a refusal (a proposal, not decided). */
    public const ACT_ARGUMENTS_KEPT = 4096;

    /**
     * What a receipt or a sign keeps of the arguments of its run: the ones a grant describes, each as long as a line
     * keeps — and, when that is not all of them, the spelling of the rest with how long the arguments were and their
     * digest, so whoever holds them can show they are the ones (a proposal, not decided).
     *
     * @param array<string, mixed> $arguments what the run carried
     * @param list<string>         $described the names some grant fixes or leaves free for the operation
     *
     * @return array{0: array<string, mixed>, 1: array{bytes: int, sha256: string}|array{}|null} what is kept, and — `null` when that is everything — what it was kept from (empty when the arguments cannot be spelled at all)
     */
    private static function keptOf(array $arguments, array $described): array
    {
        $kept = [];
        $rest = [];
        foreach ($arguments as $argument => $value) {
            $fits = false;
            if (\in_array((string) $argument, $described, true)) {
                try {
                    $fits = \strlen(Canonical::json([$value])) - 2 <= self::ARGUMENT_KEPT;
                } catch (\JsonException) {
                    // a value a line cannot hold is not kept as it came
                }
            }
            $fits ? $kept[$argument] = $value : $rest[$argument] = $value;
        }
        if ($rest === []) {
            return [$arguments, null];
        }

        return [$kept + [self::NOT_KEPT => Canonical::shown($rest)], self::given($arguments)];
    }

    /**
     * Whether every argument of a run is as long as a line keeps: what a yes and a sign of calm are asked for, since
     * they need their arguments whole to be the run a grant describes (a proposal, not decided).
     *
     * @param array<string, mixed> $arguments
     */
    private static function aLineKeeps(array $arguments): bool
    {
        return self::keptOf($arguments, array_map(strval(...), array_keys($arguments)))[1] === null;
    }

    /**
     * How long a call's arguments were, and their digest, as the book spells them — empty when they cannot be spelled.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{bytes: int, sha256: string}|array{}
     */
    private static function given(array $arguments): array
    {
        try {
            $spelled = Canonical::json($arguments);
        } catch (\JsonException) {
            return [];
        }

        return ['bytes' => \strlen($spelled), 'sha256' => 'sha256:' . hash('sha256', $spelled)];
    }

    /** What a red receipt says measured it when the house could not pin the operation that did: no pin is this. */
    public const NOT_PINNED = 'not pinned';

    /** Under which name a line keeps the arguments of a run when it cannot keep them as they came: no argument is named so. */
    public const NOT_KEPT = 'not kept as it came';

    /**
     * Write a line that only NARROWS — a watcher's «fired», an instrument's no — whatever its run carried (fourth
     * review, L1; what Rod decided for both on 2026-10-12: from ANY run).
     *
     * A line carries the arguments of the run, and a caller chooses those: a number JSON writes as `-0`, a value
     * nested deeper than a line is read, bytes that are not text. The book does not take such a line — and it was
     * the stop that was dropped: the falsifier had fired, the caller was told «could not be written», and the grant
     * stayed in force. Stopping asks for nobody's arguments. So when the line does not fit with them it is written
     * with their SPELLING in their place — the house's own, a line long ({@see Canonical::shown()}) — and only if
     * that cannot be written either does this throw.
     *
     * AND IT IS A LINE LONG WHATEVER ITS RUN CARRIED (a proposal, not decided). It keeps the arguments some grant
     * describes for the operation, each as long as a line keeps, and the spelling of the rest ({@see self::keptOf()}):
     * a caller chooses how much it carries, and every command of the house reads every line.
     *
     * @param \Closure(array<string, mixed>): array<string, mixed> $line      the line, given the arguments it keeps
     * @param array<string, mixed>                                 $arguments what the run carried
     * @param list<string>                                         $described the names some grant describes for the operation
     *
     * @throws \RuntimeException when the book cannot be written at all
     */
    private static function narrowing(StandingLedger $ledger, string $type, \Closure $line, array $arguments, array $described, \DateTimeImmutable $now): void
    {
        [$kept, $given] = self::keptOf($arguments, $described);
        try {
            $ledger->append($type, $line($kept) + ($given === null || $given === [] ? [] : ['arguments_given' => $given]), $now);
        } catch (\RuntimeException) {
            $ledger->append($type, $line([self::NOT_KEPT => Canonical::shown($arguments)]), $now);
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
