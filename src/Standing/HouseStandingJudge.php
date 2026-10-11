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
use Milpa\Command\Standing\ConditionReceipt;
use Milpa\Command\Standing\GrantStanding;
use Milpa\Command\Standing\GrantState;
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
 *   · LENDS, for the asking: a seat calling an operation some grant names is seen holding the words
 *     that operation declares, for that one call ({@see self::lends()}) — so the question that decides
 *     is this judge's, not a word the seat lacks. Nothing is written to the seat.
 *   · JUDGES: gathers the facts — who the signer is today, where the book says the grant stands, what
 *     the call acts on, what it would do, what was measured on that very content — and hands them to
 *     the pure judgment in milpa/command ({@see StandingJudge}). It adds no criterion of its own.
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
    /** Where a context says which of its words were lent for the asking, so they can be taken back. */
    public const LENT = 'standing.lent';

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
     * The words of this operation a seat lacks and would be lent for ONE call — whenever some grant of the book,
     * in any state, lends to that seat and names that operation. Admitted or not: the verdict is {@see self::decide()}'s.
     *
     * @return list<string>
     */
    public function lends(ToolContext $context, Operation $operation): array
    {
        $seat = self::seatOf($context);
        if ($seat === null || $context->hasScope('*')) {
            return []; // nobody to lend to, or nothing it lacks
        }
        foreach ($this->book()?->documents() ?? [] as $grant) {
            if ($grant->lendsTo($seat) && $grant->namesOperation($operation->name)) {
                return array_values(array_diff($operation->scopes, $context->scopes));
            }
        }

        return [];
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
        $concerned = false;
        foreach ($book->documents() as $grant) {
            $concerned = $concerned || ($grant->lendsTo($seat) && $grant->namesOperation($operation->name));
        }
        if (!$concerned) {
            return null;
        }

        $call = new StandingCall(
            $operation->name,
            $arguments,
            $seat,
            $operation->actsOnDigest($arguments),
            $operation->ceilingForCall($arguments),
            $this->clock === null ? new \DateTimeImmutable() : ($this->clock)(),
        );
        $receipts = $book->receipts();
        $refused = null;
        foreach ($book->records() as $record) {
            if (!$record->grant->lendsTo($seat) || !$record->grant->namesOperation($operation->name)) {
                continue;
            }
            $instruments = [];
            foreach ($record->grant->conditions as $condition) {
                $digest = $operationNamed($condition->by->operation)?->instrumentDigest();
                if ($digest !== null) {
                    $instruments[$condition->by->operation->canonical] = $digest;
                }
            }
            $verdict = StandingJudge::judge(
                $record->grant,
                new GrantStanding($record->state, $record->because, $record->fault, $record->watcherSignalAt, $receipts, $instruments),
                $call,
            );
            if ($verdict->passes()) {
                return new StandingDecision($call, $record, $verdict);
            }
            // Of the grants that name this call and refuse it, the one that got furthest speaks: it is the one
            // the caller is closest to, and the only one whose refusal the caller may be able to fix. Level with
            // another, the later grant speaks — it is the one a person gave knowing the earlier existed: a grant that
            // replaced another and then expired says «expired», not «superseded» of the one it replaced.
            if ($verdict->named() && ($refused === null || self::reached($verdict->failed) >= self::reached($refused->verdict?->failed))) {
                $refused = new StandingDecision($call, $record, $verdict);
            }
        }

        return $refused ?? new StandingDecision($call);
    }

    /**
     * Write down that the house is about to let this act pass, under which grant and on which receipts.
     *
     * BEFORE the act, and it throws when it cannot write: nothing is absorbed that the house cannot record.
     *
     * @throws \RuntimeException when the book cannot be written
     */
    public function absorbing(StandingDecision $decision): void
    {
        $record = $decision->record;
        $verdict = $decision->verdict;
        if ($record === null || $verdict === null || !$verdict->passes()) {
            return;
        }
        StandingLedger::forRoot($this->root)->append(StandingLedger::ABSORBED, [
            'grant' => $record->grant->id,
            'operation' => $decision->call->operation->canonical,
            'arguments' => $decision->call->arguments,
            'seat' => $decision->call->seat,
            'subject' => $decision->call->subject,
            'receipts' => array_map(static fn (ConditionReceipt $receipt): array => $receipt->toArray(), $verdict->cited),
            'undo' => $record->grant->undo,
        ]);
    }

    /**
     * After an absorbed act ran: if the content it acted on is not what was judged, the grant is suspended, with
     * what was judged and what was found. The act is not undone — this detects the swap, it does not prevent it.
     *
     * @throws \RuntimeException when the swap was seen and the suspension could not be written
     */
    public function absorbed(StandingDecision $decision, Operation $operation): void
    {
        $record = $decision->record;
        if ($record === null || !$decision->passes()) {
            return;
        }
        $found = $operation->actsOnDigest($decision->call->arguments);
        if ($found === $decision->call->subject) {
            return;
        }
        StandingLedger::forRoot($this->root)->append(StandingLedger::SUSPENDED, [
            'grant' => $record->grant->id,
            'by' => 'the house',
            'reason' => \sprintf(
                'what `%s` acted on changed between the judgment and the act: %s was judged, %s was there afterwards',
                $decision->call->operation->forCli(),
                (string) $decision->call->subject,
                $found ?? 'nothing readable',
            ),
        ]);
    }

    /** Write down a refusal of a call that a grant names. A book that cannot be written does not turn a refusal into a pass. */
    public function refused(StandingDecision $decision): void
    {
        $record = $decision->record;
        $verdict = $decision->verdict;
        if ($record === null || $verdict === null || $verdict->passes()) {
            return;
        }
        $this->quietly(fn () => StandingLedger::forRoot($this->root)->append(StandingLedger::REFUSED, [
            'grant' => $record->grant->id,
            'operation' => $decision->call->operation->canonical,
            'arguments' => $decision->call->arguments,
            'seat' => $decision->call->seat,
            'subject' => $decision->call->subject,
            'check' => $verdict->failed?->value,
            'reason' => $verdict->reason,
        ]));
    }

    /**
     * What the house must note about a call BEFORE it runs, when some grant names it as the operation that measures
     * a condition or watches a falsifier — or `null`: no grant does, and the run leaves no trace here.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{measures: bool, watches: bool, subject: ?string}|null
     */
    public function witnessing(Operation $operation, array $arguments): ?array
    {
        $measures = false;
        $watches = false;
        foreach ($this->book()?->documents() ?? [] as $grant) {
            foreach ($grant->conditions as $condition) {
                $measures = $measures || $condition->by->matches($operation->name, $arguments);
            }
            $watches = $watches || $grant->falsifier->watcher->matches($operation->name, $arguments);
        }

        return $measures || $watches
            ? ['measures' => $measures, 'watches' => $watches, 'subject' => $measures ? $operation->actsOnDigest($arguments) : null]
            : null;
    }

    /**
     * Write down what a measuring or a watching operation just answered — the house's own reading of its result,
     * never a field a caller filled in.
     *
     * A measurement becomes a receipt only when the operation can be pinned as an instrument and the content it
     * measured is the same before and after its run. A watcher's answer counts only when it says `fired` as true
     * or false; `true` suspends every grant in force whose falsifier it watches.
     *
     * @param array{measures: bool, watches: bool, subject: ?string} $noted     what {@see self::witnessing()} noted before the run
     * @param array<string, mixed>                                   $arguments
     *
     * @throws \RuntimeException when the house saw something and could not write it down
     */
    public function witnessed(array $noted, Operation $operation, array $arguments, mixed $result, ?ToolContext $context): void
    {
        $ledger = StandingLedger::forRoot($this->root);
        $now = $this->clock === null ? new \DateTimeImmutable() : ($this->clock)();
        $who = $context === null ? 'local-shell' : ($context->principal ?? 'unknown');
        $instrument = $operation->instrumentDigest();
        if ($noted['measures'] && $noted['subject'] !== null && $instrument !== null && $operation->actsOnDigest($arguments) === $noted['subject']) {
            $ledger->append(StandingLedger::MEASURED, (new ConditionReceipt(
                new OperationId($operation->name),
                $arguments,
                $noted['subject'],
                \is_array($result) && ($result['ok'] ?? null) === true,
                $instrument,
                $who,
                $now,
            ))->toArray(), $now);
        }

        $fired = \is_array($result) ? ($result['fired'] ?? null) : null;
        if (!$noted['watches'] || !\is_bool($fired)) {
            return;
        }
        $watching = [];
        foreach ($this->book()?->records() ?? [] as $record) {
            if ($record->state === GrantState::InForce && $record->grant->falsifier->watcher->matches($operation->name, $arguments)) {
                $watching[] = $record;
            }
        }
        $seen = $ledger->append(StandingLedger::WATCHED, [
            'watcher' => (new OperationId($operation->name))->canonical,
            'arguments' => $arguments,
            'fired' => $fired,
            'who' => $who,
        ], $now);
        foreach ($fired ? $watching : [] as $record) {
            $ledger->append(StandingLedger::SUSPENDED, [
                'grant' => $record->grant->id,
                'by' => 'the house',
                'reason' => \sprintf('its falsifier «%s» fired: `%s` reported it', $record->grant->falsifier->name, (new OperationId($operation->name))->forCli()),
                'watched' => $seen['hash'],
            ], $now);
        }
    }

    /** The context without the words that were lent to it for the asking. */
    public static function withoutLent(ToolContext $context): ToolContext
    {
        $lent = \is_array($context->extra[self::LENT] ?? null) ? $context->extra[self::LENT] : [];
        if ($lent === []) {
            return $context;
        }
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

    private static function reached(?StandingCheck $check): int
    {
        return (int) array_search($check, StandingCheck::cases(), true);
    }

    /** @param \Closure(): mixed $write */
    private function quietly(\Closure $write): void
    {
        try {
            $write();
        } catch (\RuntimeException) {
            // The refusal stands without its line: a book that cannot be written does not turn a no into a yes.
        }
    }
}
