<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Standing;

use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\Command\Standing\GrantState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE BOOK THAT FILLS (greenhouse decisions/0615, D18; Option C — decided by Rod, 2026-10-14).
 *
 * The book of standing grants is read whole by every command, and past eight mebibytes it is a fault: no seat acts
 * and no stop is written. One caller measuring content after content reached that in twenty-six minutes. The budget
 * of D18 bounded a GRANT's seats, and left unbounded what narrows and what a non-seat writes — so one caller still
 * filled the book and denied the feature to the whole house (the eighth review, 8-M1). So the budget is the WRITER's:
 *
 *   · WHAT A WRITER LEAVES HAS A BUDGET — every line, counted against the writer by name: a receipt, a sign of a
 *     watcher, an act it was lent, a refusal it was given. Past the budget the house will not RUN an instrument or a
 *     watcher for that writer, nor lend it an act; it writes ONE line, a stop that names the writer, and a person
 *     lifts it — which is where the count starts again. A no and a «fired» a run within budget DID say are written,
 *     always ({@see WhatNarrowsIsAlwaysWrittenTest}); the denial is of the run, before it says one.
 *   · THE HOUSE SAYS WHEN ITS BOOK IS PAST HALF, to the person who lists it and to the seat it lends to.
 *   · KEEPING THE BOOK SOMEWHERE ELSE IS A PERSON'S ACT, by hand for now: the book and its head, together — and what
 *     is left behind is every stop, every withdrawal and every receipt.
 *
 * @subject-in milpa/app-runtime
 */
final class ABookThatFillsTest extends TestCase
{
    private const SEAT = LabHouse::SEAT;

    private LabHouse $house;

    protected function setUp(): void
    {
        $this->house = new LabHouse();
    }

    protected function tearDown(): void
    {
        $this->house->destroy();
    }

    // ── the budget of a writer ───────────────────────────────────────────────────────────────────────────────

    public function testTheFirstRunPastAWritersBudgetIsAStopThatNamesItAndNothingItWouldSayRuns(): void
    {
        $this->house->granted();
        $this->spendTo('key:' . self::SEAT, StandingLedger::BUDGET);
        $good = $this->house->candidate('good.txt', "clean release\n");
        self::assertSame(['lines' => StandingLedger::BUDGET, 'stopped' => false], $this->house->ledger()->spentBy('key:' . self::SEAT));
        $before = \count($this->house->everyLine());

        // The next run the writer makes is the one the house denies — in its place, the writer's stop.
        $denied = $this->house->as(self::SEAT, 'lab:scan', ['path' => $good]);

        $stops = $this->house->lines(StandingLedger::OVERSPENT);
        self::assertCount(1, $stops);
        self::assertSame(['key:' . self::SEAT, 'the house'], [$stops[0]['writer'], $stops[0]['by']]);
        self::assertSame($before + 1, \count($this->house->everyLine()), 'the denied run left a line other than the stop');
        self::assertSame([], $this->receiptsOn($good), 'the run said a receipt though the writer spent its budget');
        self::assertStringContainsString('spent its budget', (string) ($denied['refused'] ?? 'RAN'));
        self::assertStringContainsString('standing:reinstate --writer=key:' . self::SEAT, (string) $denied['refused']);
        self::assertTrue($this->house->ledger()->spentBy('key:' . self::SEAT)['stopped']);

        // From then on nothing it runs writes a line: not a receipt, not another stop.
        $this->house->as(self::SEAT, 'lab:scan', ['path' => $good]);
        self::assertSame($before + 1, \count($this->house->everyLine()), 'a writer that spent its budget went on filling the book');
        self::assertSame(GrantState::InForce, $this->state(), 'the grant itself was stopped for a writer that spent its budget');
    }

    public function testAPersonLiftsTheWritersStopAndItsCountStartsAgain(): void
    {
        $this->house->granted();
        $this->spendTo('key:' . self::SEAT, StandingLedger::BUDGET);
        $good = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(self::SEAT, 'lab:scan', ['path' => $good]);
        $stop = (string) $this->house->ledger()->haltedAt('key:' . self::SEAT);
        self::assertNotSame('', $stop, 'control: the writer was stopped for its budget');

        // Not a seat — stopping is anyone's, lifting is a person's. And not an older stop.
        self::assertStringContainsString("Missing required scope for tool 'standing_reinstate'", (string) ($this->house->signed(self::SEAT, 'standing:reinstate', ['writer' => 'key:' . self::SEAT, 'suspension' => $stop])['refused'] ?? 'LIFTED'));
        self::assertStringContainsString('lifts the one it names and no other', (string) ($this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['writer' => 'key:' . self::SEAT, 'suspension' => 'sha256:another'])['error'] ?? 'LIFTED'));

        $lifted = $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['writer' => 'key:' . self::SEAT, 'suspension' => $stop]);

        self::assertTrue($lifted['ok'] ?? null, (string) json_encode($lifted));
        self::assertSame(['lines' => 0, 'stopped' => false], $this->house->ledger()->spentBy('key:' . self::SEAT));
        $this->house->as(self::SEAT, 'lab:watch');
        $this->house->as(self::SEAT, 'lab:scan', ['path' => $good]);
        self::assertSame([true], $this->receiptsOn($good), 'after the lift, what the writer runs is written again');
        self::assertSame(['ok' => true], $this->house->as(self::SEAT, 'lab:merge', ['path' => $good]));
        self::assertSame(3, $this->house->ledger()->spentBy('key:' . self::SEAT)['lines'], 'a sign, a receipt and an act, all counted after the lift');
    }

    public function testAnActIsNotLentToAWriterThatSpentItsBudget(): void
    {
        $this->house->granted();
        $good = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(self::SEAT, 'lab:scan', ['path' => $good]);
        $this->spendTo('key:' . self::SEAT, StandingLedger::BUDGET);

        $merged = $this->house->as(self::SEAT, 'lab:merge', ['path' => $good]);

        self::assertStringContainsString('spent its budget', (string) ($merged['refused'] ?? 'RAN'), 'an act was lent past the budget');
        self::assertSame([], $this->house->done('merged'), 'and it ran');
        self::assertSame([], $this->house->lines(StandingLedger::ABSORBED));
        self::assertSame([], $this->house->lines(StandingLedger::REFUSED), 'the house wrote a refusal for an act it denied for the budget');
        self::assertCount(1, $this->house->lines(StandingLedger::OVERSPENT));
    }

    /** @return iterable<string, array{string, array<string, mixed>}> a line a caller's call leaves, as the house writes it */
    public static function whatAWriterLeaves(): iterable
    {
        yield 'a receipt of an instrument' => [StandingLedger::MEASURED, ['by' => 'lab.scan', 'arguments' => ['path' => 'x'], 'subject' => 'sha256:x', 'ok' => false, 'instrument' => 'sha256:any', 'who' => 'key:' . self::SEAT, 'at' => '2026-10-11T12:00:00+00:00']];
        yield 'a sign of a watcher' => [StandingLedger::WATCHED, ['watcher' => 'lab.watch', 'arguments' => [], 'fired' => false, 'who' => 'key:' . self::SEAT, 'pin' => 'sha256:any']];
        yield 'a «fired» of a watcher' => [StandingLedger::WATCHED, ['watcher' => 'lab.watch', 'arguments' => [], 'fired' => true, 'who' => 'key:' . self::SEAT, 'pin' => null]];
        yield 'an act it was lent' => [StandingLedger::ABSORBED, ['grant' => 'release-line', 'operation' => 'lab.merge', 'seat' => self::SEAT, 'who' => 'key:' . self::SEAT, 'subject' => 'sha256:x', 'receipts' => []]];
        yield 'a refusal it was given' => [StandingLedger::REFUSED, ['grant' => 'release-line', 'operation' => 'lab.merge', 'seat' => self::SEAT, 'who' => 'key:' . self::SEAT, 'subject' => 'sha256:x', 'check' => 'conditions', 'reason' => 'measured_red']];
    }

    /** @param array<string, mixed> $line */
    #[DataProvider('whatAWriterLeaves')]
    public function testEveryLineAWriterLeavesIsCountedAgainstIt(string $type, array $line): void
    {
        $this->house->granted(watched: false);
        self::assertSame(0, $this->house->ledger()->spentBy('key:' . self::SEAT)['lines']);

        $this->house->writtenByHand($type, $line);
        $this->house->writtenByHand($type, $line);

        self::assertSame(2, $this->house->ledger()->spentBy('key:' . self::SEAT)['lines']);
    }

    public function testAStopAndALiftAreNotThemselvesCounted(): void
    {
        $this->house->granted(watched: false);
        $receipt = ['by' => 'lab.scan', 'arguments' => ['path' => 'x'], 'subject' => 'sha256:x', 'ok' => true, 'instrument' => 'sha256:any', 'who' => 'key:' . self::SEAT, 'at' => '2026-10-11T12:00:00+00:00'];
        $this->house->writtenByHand(StandingLedger::MEASURED, $receipt);
        $overspent = $this->house->writtenByHand(StandingLedger::OVERSPENT, ['writer' => 'key:' . self::SEAT, 'by' => 'the house', 'reason' => 'it left 1 line']);
        self::assertSame(['lines' => 1, 'stopped' => true], $this->house->ledger()->spentBy('key:' . self::SEAT), 'the stop was counted as a line of its writer');

        $this->house->writtenByHand(StandingLedger::RESTORED, ['writer' => 'key:' . self::SEAT, 'suspension' => $overspent['hash']]);

        self::assertSame(['lines' => 0, 'stopped' => false], $this->house->ledger()->spentBy('key:' . self::SEAT), 'the lift did not start the count again, or was counted itself');
    }

    public function testARefusalCountsAgainstTheSeatThatIsRefused(): void
    {
        $this->house->granted();
        $never = $this->house->candidate('never.txt', "never measured\n");

        $this->house->as(self::SEAT, 'lab:merge', ['path' => $never]);

        self::assertSame([self::SEAT], array_column($this->house->lines(StandingLedger::REFUSED), 'seat'), 'control: the seat was refused and the refusal written');
        self::assertSame(2, $this->house->ledger()->spentBy('key:' . self::SEAT)['lines'], 'the sign of the watcher and the refusal count against the seat');
    }

    public function testTheCountIsTheSameReadFromTheFirstLineAndReadOnward(): void
    {
        // A process that lives reads only what was added to the book: what a writer spent is counted the same.
        $this->house->granted();
        $this->house->aProcessPerCall = false;
        $this->house->boots();
        $good = $this->house->candidate('good.txt', "clean release\n");
        foreach (range(1, 5) as $_) {
            $this->house->as(self::SEAT, 'lab:scan', ['path' => $good]);
            $this->house->as(self::SEAT, 'lab:merge', ['path' => $good]);
        }
        $onward = $this->house->ledger()->spentBy('key:' . self::SEAT);

        $this->house->boots();

        self::assertSame($onward, $this->house->ledger()->spentBy('key:' . self::SEAT));
        self::assertSame(11, $onward['lines'], 'one sign, five receipts, five acts');
    }

    // ── the house says when its book is past half ───────────────────────────────────────────────────────────

    public function testTheHouseSaysNothingOfABookThatIsNotHalfFull(): void
    {
        $this->house->granted();
        $good = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(self::SEAT, 'lab:scan', ['path' => $good]);

        self::assertNull($this->house->ledger()->nearlyFull());
        self::assertArrayNotHasKey('book', $this->house->as(LabHouse::PERSON, 'standing:granted'));
        self::assertSame(['ok' => true], $this->house->as(self::SEAT, 'lab:merge', ['path' => $good]));
    }

    public function testPastHalfThePersonWhoListsItAndTheSeatItLendsToAreTold(): void
    {
        $this->house->granted();
        $good = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(self::SEAT, 'lab:scan', ['path' => $good]);
        $this->fillTo(StandingLedger::WARNS_FROM - 4096);
        self::assertNull($this->house->ledger()->nearlyFull(), 'control: just under half, nothing is said');
        $this->fillTo(StandingLedger::WARNS_FROM);

        $said = (string) $this->house->ledger()->nearlyFull();
        $listed = $this->house->as(LabHouse::PERSON, 'standing:granted');
        $merged = $this->house->as(self::SEAT, 'lab:merge', ['path' => $good]);

        self::assertStringContainsString('holds 4.0 of the 8 MiB this house reads', $said);
        self::assertStringContainsString('`storage/identity/standing.jsonl` and `storage/identity/standing.jsonl.head`, the two together', $said);
        self::assertSame($said, $listed['book'] ?? null, 'the person who lists the grants was not told');
        self::assertTrue($merged['ok'] ?? false, 'control: the act was lent — a book past half is not a fault');
        self::assertSame($said, $merged['standing_book'] ?? null, 'the seat that was lent an act was not told');
        self::assertNull($this->house->ledger()->fault());
    }

    // ── keeping the book somewhere else: a person's act, by hand, and what is left behind ───────────────────

    public function testTheBookAndItsHeadKeptSomewhereElseTogetherLeaveAHouseWithNoBookAndEveryStopBehind(): void
    {
        $this->house->granted();
        $red = $this->house->candidate('red.txt', "machine-name: one\n");
        $this->house->as(self::SEAT, 'lab:scan', ['path' => $red]);
        $this->house->signed(self::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen by the seat']);
        self::assertSame(GrantState::Suspended, $this->state(), 'control: stopped, with a red candidate');
        $book = $this->house->root . '/' . StandingLedger::PATH;

        $kept = $this->house->root . '/kept/' . StandingLedger::PATH;
        mkdir(\dirname($kept), 0o755, true);
        rename($book, $kept);
        rename($book . '.head', $kept . '.head');
        $this->house->boots();

        self::assertFalse($this->house->ledger()->exists(), 'the house still keeps a book');
        self::assertSame([], $this->house->as(LabHouse::PERSON, 'standing:granted')['grants'] ?? null);
        self::assertNull(StandingLedger::forRoot($this->house->root . '/kept')->fault());
        self::assertCount(\count(LabHouse::everyLineOf($kept)), StandingLedger::forRoot($this->house->root . '/kept')->recent());

        $this->house->granted();
        self::assertSame(GrantState::InForce, $this->state(), 'said, in the README: every stop and every withdrawal stays in the book that was kept');
        self::assertStringContainsString('was never measured', (string) ($this->house->as(self::SEAT, 'lab:merge', ['path' => $red])['refused'] ?? 'RAN'));
        $this->house->as(self::SEAT, 'lab:scan', ['path' => $red]);
        self::assertStringContainsString('does not hold', (string) ($this->house->as(self::SEAT, 'lab:merge', ['path' => $red])['refused'] ?? 'RAN'));
        self::assertSame([], $this->house->done('merged'));
    }

    /** @return iterable<string, array{string}> which of the two files is taken away alone */
    public static function oneOfTheTwo(): iterable
    {
        yield 'the book, and its head stays' => [''];
        yield 'the head, and the book stays' => ['.head'];
    }

    #[DataProvider('oneOfTheTwo')]
    public function testOneOfTheTwoTakenAwayAloneIsNotABookKeptSomewhereElse(string $taken): void
    {
        $this->house->granted();
        $good = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(self::SEAT, 'lab:scan', ['path' => $good]);
        $this->house->signed(self::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen by the seat']);

        rename($this->house->root . '/' . StandingLedger::PATH . $taken, $this->house->root . '/taken');
        $this->house->boots();

        self::assertTrue($this->house->ledger()->exists());
        self::assertArrayHasKey('refused', $this->house->as(self::SEAT, 'lab:merge', ['path' => $good]), 'a stop was left behind by taking one file');
        self::assertSame([], $this->house->done('merged'));
    }

    public function testABookLongerThanTheHouseReadsIsAFaultAndNoSeatActs(): void
    {
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(self::SEAT, 'lab:scan', ['path' => $path]);
        self::assertArrayNotHasKey('refused', $this->house->as(self::SEAT, 'lab:merge', ['path' => $path]), 'control: the seat acts');
        $pad = str_repeat('x', 256 * 1024);
        while (filesize($this->house->root . '/' . StandingLedger::PATH) <= StandingLedger::LONGEST) {
            $this->house->writtenByHand(StandingLedger::REFUSED, ['grant' => 'another', 'operation' => 'lab_merge', 'arguments' => ['note' => $pad], 'seat' => LabHouse::STRANGER, 'subject' => null, 'check' => 'conditions', 'reason' => 'a long line, written by hand']);
            clearstatcache();
        }

        self::assertStringContainsString('longer than this house reads', (string) $this->house->ledger()->fault(), 'a book past that length was read whole');
        self::assertStringContainsString('does not verify', (string) ($this->house->as(self::SEAT, 'lab:merge', ['path' => $path])['refused'] ?? 'RAN'));
        self::assertSame(1, \count($this->house->done('merged')), 'a seat acted over a book the house does not read');
    }

    // ── what these tests are staged with ────────────────────────────────────────────────────────────────────

    private function state(): ?GrantState
    {
        return $this->house->book()->record('release-line')?->state;
    }

    /** @return list<bool> what the receipts on this candidate say, in the order they were written */
    private function receiptsOn(string $path): array
    {
        $subject = $this->house->digestOf($path);

        return array_column(array_values(array_filter($this->house->lines(StandingLedger::MEASURED), static fn (array $receipt): bool => $receipt['subject'] === $subject)), 'ok');
    }

    /** Write, as a writer measuring content after content, the lines that bring it to this many left. */
    private function spendTo(string $who, int $lines): void
    {
        for ($spent = $this->house->ledger()->spentBy($who)['lines']; $spent < $lines; ++$spent) {
            $this->house->writtenByHand(StandingLedger::MEASURED, ['by' => 'lab.scan', 'arguments' => ['path' => 'seen-' . $spent . '.txt'], 'subject' => 'sha256:' . hash('sha256', (string) $spent), 'ok' => false, 'instrument' => 'sha256:any', 'who' => $who, 'at' => $this->house->now->format(\DATE_ATOM), 'arrived' => 'unsigned']);
        }
    }

    /** Make the book at least this long, with lines of a grant nobody gave and no writer: they are under nobody's budget. */
    private function fillTo(int $bytes): void
    {
        $book = $this->house->root . '/' . StandingLedger::PATH;
        for (clearstatcache(); filesize($book) < $bytes; clearstatcache()) {
            $this->house->writtenByHand(StandingLedger::REFUSED, ['grant' => 'nobody-gave-this', 'operation' => 'lab.merge', 'seat' => LabHouse::OTHER_SEAT, 'subject' => 'sha256:x', 'check' => 'conditions', 'reason' => str_repeat('filling ', min(2000, max(1, intdiv($bytes - (int) filesize($book), 8) - 40)))]);
        }
    }
}
