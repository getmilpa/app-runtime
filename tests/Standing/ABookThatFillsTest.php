<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Standing;

use Milpa\AppRuntime\Standing\HouseStandingJudge;
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\Command\Standing\GrantState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE BOOK THAT FILLS (greenhouse decisions/0615, D18 — decided by Rod, 2026-10-13).
 *
 * The book of standing grants is read whole by every command, and past eight mebibytes it is a fault: no seat acts
 * and no stop is written. One seat measuring content after content reached that in twenty-six minutes. So:
 *
 *   · WHAT IS WRITTEN UNDER A GRANT HAS A BUDGET. Every line a seat's call leaves is written under some grant — the
 *     acts it was lent and the refusals it was given name theirs, a receipt and a sign of a watcher are written
 *     under every grant that operation measures or watches. Past the budget the house writes one line, a stop of
 *     that grant, and nothing more under it until a person lifts it.
 *   · THE HOUSE SAYS WHEN ITS BOOK IS PAST HALF, to the person who lists it and to the seat it lends to.
 *   · KEEPING THE BOOK SOMEWHERE ELSE IS A PERSON'S ACT, by hand for now: the book and its head, together — and what
 *     is left behind is every stop, every withdrawal and every receipt.
 *
 * @subject-in milpa/app-runtime
 */
final class ABookThatFillsTest extends TestCase
{
    private const SECOND = LabHouse::STRANGER;

    private LabHouse $house;

    protected function setUp(): void
    {
        $this->house = new LabHouse();
        $this->house->enroll(self::SECOND, ['identity:enroll'], LabHouse::PERSON);
    }

    protected function tearDown(): void
    {
        $this->house->destroy();
    }

    // ── the budget of a grant ────────────────────────────────────────────────────────────────────────────────

    public function testTheFirstLinePastAGrantsBudgetIsAStopOfThatGrantAndNothingMoreIsWrittenUnderIt(): void
    {
        $this->house->granted();
        $this->spendAllBut(1);
        $red = [$this->house->candidate('red-1.txt', "machine-name: one\n"), $this->house->candidate('red-2.txt', "machine-name: two\n"), $this->house->candidate('red-3.txt', "machine-name: three\n")];

        // The last line of the budget is written as any other…
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $red[0]]);
        self::assertSame(['lines' => StandingLedger::BUDGET, 'stopped' => false], $this->house->ledger()->spentUnder('release-line'));
        self::assertSame(GrantState::InForce, $this->state());
        $before = \count($this->house->everyLine());

        // …and the next one is not: in its place the house stops the grant.
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $red[1]]);

        $stops = $this->house->lines(StandingLedger::SUSPENDED);
        self::assertCount(1, $stops);
        self::assertEquals(['grant' => 'release-line', 'by' => 'the house', 'reason' => \sprintf(HouseStandingJudge::BUDGET_SPENT, StandingLedger::BUDGET)], $stops[0]);
        self::assertSame($before + 1, \count($this->house->everyLine()), 'the line past the budget was written as well as the stop');
        self::assertSame(GrantState::Suspended, $this->state());
        self::assertSame(['lines' => StandingLedger::BUDGET, 'stopped' => true], $this->house->ledger()->spentUnder('release-line'));

        // From then on nothing is written under it: not a no, not a «fired», not a refusal, not another stop.
        $this->house->fired = true;
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $red[2]]);
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $refused = $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $red[2]]);
        self::assertSame($before + 1, \count($this->house->everyLine()), 'a grant that spent its budget went on filling the book');
        // …and the seat reads why, and who lifts it.
        self::assertStringContainsString('spent its budget: ' . StandingLedger::BUDGET . ' lines were written under it', (string) ($refused['refused'] ?? 'RAN'));
        self::assertStringContainsString('until a person lifts this stop', (string) $refused['refused']);
        self::assertSame([], $this->house->done('merged'));
    }

    public function testAPersonLiftsThatStopAndTheCountStartsAgain(): void
    {
        $this->house->granted();
        $this->spendAllBut(0);
        $good = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $good]);
        self::assertSame(GrantState::Suspended, $this->state(), 'control: the budget was spent, and the grant stopped');
        self::assertSame([], $this->receiptsOn($good));

        $lifted = $this->house->signed(self::SECOND, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => (string) $this->house->book()->record('release-line')?->suspension]);

        self::assertTrue($lifted['ok'] ?? null, (string) json_encode($lifted));
        self::assertSame(['lines' => 0, 'stopped' => false], $this->house->ledger()->spentUnder('release-line'));
        self::assertSame(GrantState::InForce, $this->state());
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $good]);
        self::assertSame([true], $this->receiptsOn($good), 'after the lift, what is said under the grant is written again');
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $good]));
        self::assertSame(3, $this->house->ledger()->spentUnder('release-line')['lines'], 'a sign, a receipt and an act');
    }

    public function testAnActIsNotLentByAGrantThatSpentItsBudget(): void
    {
        $this->house->granted();
        $good = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $good]);
        $this->spendAllBut(0);
        self::assertSame(GrantState::InForce, $this->state(), 'control: spent to the last line, and in force');

        $merged = $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $good]);

        self::assertStringContainsString('spent its budget', (string) ($merged['refused'] ?? 'RAN'), 'an act was lent past the budget');
        self::assertSame([], $this->house->done('merged'), 'and it ran');
        self::assertSame([], $this->house->lines(StandingLedger::ABSORBED));
        self::assertCount(1, $this->house->lines(StandingLedger::SUSPENDED));
        self::assertSame([], $this->house->lines(StandingLedger::REFUSED), 'the refusal of a grant that spent its budget was written under it');
    }

    public function testARefusalPastTheBudgetIsTheStopToo(): void
    {
        $this->house->granted();
        $red = $this->house->candidate('red.txt', "machine-name: one\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $red]);
        $this->spendAllBut(0);

        $merged = $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $red]);

        self::assertArrayHasKey('refused', $merged);
        self::assertSame([], $this->house->lines(StandingLedger::REFUSED));
        self::assertSame(['the house'], array_column($this->house->lines(StandingLedger::SUSPENDED), 'by'));
    }

    /** @return iterable<string, array{string, array<string, mixed>}> a line a seat's call leaves, as the house writes it */
    public static function whatIsWrittenUnderAGrant(): iterable
    {
        yield 'a receipt of the operation that measures it' => [StandingLedger::MEASURED, ['by' => 'lab.scan', 'arguments' => ['path' => 'x'], 'subject' => 'sha256:x', 'ok' => false, 'instrument' => 'sha256:any', 'who' => 'key:' . LabHouse::OTHER_SEAT, 'at' => '2026-10-11T12:00:00+00:00']];
        yield 'a receipt whose line spells that operation another way' => [StandingLedger::MEASURED, ['by' => 'lab:scan', 'arguments' => ['path' => 'x'], 'subject' => 'sha256:x', 'ok' => true, 'instrument' => 'sha256:any', 'who' => 'key:' . LabHouse::SEAT, 'at' => '2026-10-11T12:00:00+00:00']];
        yield 'a sign of the operation that watches it' => [StandingLedger::WATCHED, ['watcher' => 'lab.watch', 'arguments' => [], 'fired' => false, 'who' => 'key:' . LabHouse::SEAT, 'pin' => 'sha256:any']];
        yield 'a «fired» of it' => [StandingLedger::WATCHED, ['watcher' => 'lab.watch', 'arguments' => [], 'fired' => true, 'who' => 'key:' . LabHouse::SEAT, 'pin' => null]];
        yield 'an act it lent' => [StandingLedger::ABSORBED, ['grant' => 'release-line', 'operation' => 'lab.merge', 'seat' => LabHouse::SEAT, 'subject' => 'sha256:x', 'receipts' => []]];
        yield 'a refusal it gave' => [StandingLedger::REFUSED, ['grant' => 'release-line', 'operation' => 'lab.merge', 'seat' => LabHouse::SEAT, 'subject' => 'sha256:x', 'check' => 'conditions', 'reason' => 'measured_red']];
    }

    /** @param array<string, mixed> $line */
    #[DataProvider('whatIsWrittenUnderAGrant')]
    public function testEveryLineASeatsCallLeavesIsCountedUnderItsGrant(string $type, array $line): void
    {
        $this->house->granted(watched: false);
        self::assertSame(0, $this->house->ledger()->spentUnder('release-line')['lines']);

        $this->house->writtenByHand($type, $line);
        $this->house->writtenByHand($type, $line);

        self::assertSame(2, $this->house->ledger()->spentUnder('release-line')['lines']);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function whatIsNotWrittenUnderAGrant(): iterable
    {
        yield 'a receipt of an operation it is not measured by' => [StandingLedger::MEASURED, ['by' => 'lab.other', 'arguments' => [], 'subject' => 'sha256:x', 'ok' => false, 'instrument' => 'sha256:any', 'who' => 'key:' . LabHouse::SEAT, 'at' => '2026-10-11T12:00:00+00:00']];
        yield 'a sign of an operation that does not watch it' => [StandingLedger::WATCHED, ['watcher' => 'lab.scan.other', 'arguments' => [], 'fired' => true, 'who' => 'key:' . LabHouse::SEAT, 'pin' => null]];
        yield 'an act another grant lent' => [StandingLedger::ABSORBED, ['grant' => 'another', 'operation' => 'lab.merge', 'seat' => LabHouse::SEAT, 'subject' => 'sha256:x', 'receipts' => []]];
        yield 'a refusal another grant gave' => [StandingLedger::REFUSED, ['grant' => 'another', 'operation' => 'lab.merge', 'seat' => LabHouse::SEAT, 'subject' => 'sha256:x', 'check' => 'conditions', 'reason' => 'measured_red']];
        yield 'a stop of it — a stop is never what fills a budget' => [StandingLedger::SUSPENDED, ['grant' => 'another', 'by' => 'someone', 'reason' => 'seen']];
    }

    /** @param array<string, mixed> $line */
    #[DataProvider('whatIsNotWrittenUnderAGrant')]
    public function testWhatIsWrittenUnderAnotherGrantOrByAnotherOperationIsNotCountedUnderThisOne(string $type, array $line): void
    {
        $this->house->granted(watched: false);

        $this->house->writtenByHand($type, $line);

        self::assertSame(['lines' => 0, 'stopped' => false], $this->house->ledger()->spentUnder('release-line'));
    }

    public function testAnInstrumentTwoGrantsNameSpeaksUnderBothAndIsWrittenWhileOneOfThemHasBudgetLeft(): void
    {
        $this->house->granted();
        $this->house->granted(['id' => 'second-line', 'covers' => [['operation' => 'lab:announce']]], watched: false);
        self::assertSame(['release-line', 'second-line'], $this->house->ledger()->spokenForBy('lab:scan'));
        // The first spends what is left of its budget on acts, which only it lent.
        for ($spent = $this->house->ledger()->spentUnder('release-line')['lines']; $spent < StandingLedger::BUDGET; ++$spent) {
            $this->house->writtenByHand(StandingLedger::ABSORBED, ['grant' => 'release-line', 'operation' => 'lab.merge', 'seat' => LabHouse::SEAT, 'subject' => 'sha256:' . $spent, 'receipts' => []]);
        }
        $red = $this->house->candidate('red.txt', "machine-name: one\n");

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $red]);

        self::assertSame([false], $this->receiptsOn($red), 'a no was not written though a grant it speaks under had budget left');
        self::assertSame(['release-line'], array_column($this->house->lines(StandingLedger::SUSPENDED), 'grant'), 'and the grant that spent its budget is stopped');
        self::assertSame(GrantState::InForce, $this->house->book()->record('second-line')?->state);
        self::assertSame(1, $this->house->ledger()->spentUnder('second-line')['lines'], 'this receipt — the sign of the watcher was written before this grant was given');
    }

    public function testWhatNoGrantsOwnLineAnswersForIsWrittenWhateverWasSpent(): void
    {
        // A grant as it stands when the door did not write it: its line does not say what it is measured by. There is
        // no stop to write in place of a no of that instrument, so the no is written — always.
        $this->house->grantedByHand(line: ['instruments' => []]);
        self::assertSame([], $this->house->ledger()->spokenForBy('lab:scan'), 'control: the line of that grant names no instrument');
        $red = $this->house->candidate('red.txt', "machine-name: one\n");

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $red]);

        self::assertSame([false], $this->receiptsOn($red));
    }

    public function testAGrantAlreadyStoppedByALineIsNotStoppedAgainByTheHouse(): void
    {
        // A stop is read from a LINE written since the grant was last given or lifted — whoever wrote it.
        $this->house->granted();
        $this->house->signed(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen by the seat']);
        $this->spendAllBut(0);
        $before = \count($this->house->everyLine());

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $this->house->candidate('red.txt', "machine-name: one\n")]);

        self::assertSame($before, \count($this->house->everyLine()));
        self::assertSame(['key:' . LabHouse::SEAT], array_column($this->house->lines(StandingLedger::SUSPENDED), 'by'));
    }

    public function testAGrantGivenAgainHasABudgetOfItsOwn(): void
    {
        $this->house->granted();
        $this->spendAllBut(0);
        $this->house->granted(['id' => 'release-line-2', 'supersedes' => 'release-line']);
        $good = $this->house->candidate('good.txt', "clean release\n");

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $good]);

        self::assertSame([true], $this->receiptsOn($good));
        self::assertSame(2, $this->house->ledger()->spentUnder('release-line-2')['lines'], 'its sign and this receipt');
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $good]));
    }

    public function testTheCountIsTheSameReadFromTheFirstLineAndReadOnward(): void
    {
        // A process that lives reads only what was added to the book: what a grant spent is counted the same.
        $this->house->granted();
        $this->house->aProcessPerCall = false;
        $this->house->boots();
        $good = $this->house->candidate('good.txt', "clean release\n");
        foreach (range(1, 5) as $_) {
            $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $good]);
            $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $good]);
        }
        $onward = $this->house->ledger()->spentUnder('release-line');

        $this->house->boots();

        self::assertSame($onward, $this->house->ledger()->spentUnder('release-line'));
        self::assertSame(11, $onward['lines'], 'one sign, five receipts, five acts');
    }

    // ── the house says when its book is past half ───────────────────────────────────────────────────────────

    public function testTheHouseSaysNothingOfABookThatIsNotHalfFull(): void
    {
        $this->house->granted();
        $good = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $good]);

        self::assertNull($this->house->ledger()->nearlyFull());
        self::assertArrayNotHasKey('book', $this->house->as(LabHouse::PERSON, 'standing:granted'));
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $good]));
    }

    public function testPastHalfThePersonWhoListsItAndTheSeatItLendsToAreTold(): void
    {
        $this->house->granted();
        $good = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $good]);
        $this->fillTo(StandingLedger::WARNS_FROM - 4096);
        self::assertNull($this->house->ledger()->nearlyFull(), 'control: just under half, nothing is said');
        $this->fillTo(StandingLedger::WARNS_FROM);

        $said = (string) $this->house->ledger()->nearlyFull();
        $listed = $this->house->as(LabHouse::PERSON, 'standing:granted');
        $merged = $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $good]);

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
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $red]);
        $this->house->signed(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen by the seat']);
        self::assertSame(GrantState::Suspended, $this->state(), 'control: stopped, with a red candidate');
        $book = $this->house->root . '/' . StandingLedger::PATH;

        // The procedure the README gives: nothing of the house running, then the two files, together.
        $kept = $this->house->root . '/kept/' . StandingLedger::PATH;
        mkdir(\dirname($kept), 0o755, true);
        rename($book, $kept);
        rename($book . '.head', $kept . '.head');
        $this->house->boots();

        self::assertFalse($this->house->ledger()->exists(), 'the house still keeps a book');
        self::assertSame([], $this->house->as(LabHouse::PERSON, 'standing:granted')['grants'] ?? null);
        // What was kept reads whole where it was put, laid out as a house keeps it.
        self::assertNull(StandingLedger::forRoot($this->house->root . '/kept')->fault());
        self::assertCount(\count(LabHouse::everyLineOf($kept)), StandingLedger::forRoot($this->house->root . '/kept')->recent());

        // WHAT IS LEFT BEHIND. The grant, given again, is in force: the stop is in the book that was kept…
        $this->house->granted();
        self::assertSame(GrantState::InForce, $this->state(), 'said, in the README: every stop and every withdrawal stays in the book that was kept');
        // …and so is every receipt, the red ones with the green: nothing is lent on a candidate until it is measured again.
        self::assertStringContainsString('was never measured', (string) ($this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $red])['refused'] ?? 'RAN'));
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $red]);
        self::assertStringContainsString('does not hold', (string) ($this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $red])['refused'] ?? 'RAN'));
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
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $good]);
        $this->house->signed(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen by the seat']);

        rename($this->house->root . '/' . StandingLedger::PATH . $taken, $this->house->root . '/taken');
        $this->house->boots();

        // The house still has a book, and the grant in it is still stopped — or the book is a fault and no seat acts.
        self::assertTrue($this->house->ledger()->exists());
        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $good]), 'a stop was left behind by taking one file');
        self::assertSame([], $this->house->done('merged'));
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

    /** Write, as a seat's refused calls would, the lines that leave this many of the lab grant's budget unspent. */
    private function spendAllBut(int $left): void
    {
        for ($spent = $this->house->ledger()->spentUnder('release-line')['lines']; $spent < StandingLedger::BUDGET - $left; ++$spent) {
            $this->house->writtenByHand(StandingLedger::MEASURED, ['by' => 'lab.scan', 'arguments' => ['path' => 'seen-' . $spent . '.txt'], 'subject' => 'sha256:' . hash('sha256', (string) $spent), 'ok' => false, 'instrument' => 'sha256:any', 'who' => 'key:' . LabHouse::OTHER_SEAT, 'at' => $this->house->now->format(\DATE_ATOM), 'arrived' => 'unsigned']);
        }
    }

    /** Make the book at least this long, with lines of a grant nobody gave: they are under nobody's budget. */
    private function fillTo(int $bytes): void
    {
        $book = $this->house->root . '/' . StandingLedger::PATH;
        for (clearstatcache(); filesize($book) < $bytes; clearstatcache()) {
            $this->house->writtenByHand(StandingLedger::REFUSED, ['grant' => 'nobody-gave-this', 'operation' => 'lab.merge', 'seat' => LabHouse::OTHER_SEAT, 'subject' => 'sha256:x', 'check' => 'conditions', 'reason' => str_repeat('filling ', min(2000, max(1, intdiv($bytes - (int) filesize($book), 8) - 40)))]);
        }
    }
}
