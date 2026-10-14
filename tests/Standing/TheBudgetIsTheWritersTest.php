<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Standing;

use Milpa\AppRuntime\Standing\NotAbsorbed;
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\Command\Standing\GrantState;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * THE BUDGET IS THE WRITER'S (Option C — decided by Rod, 2026-10-14; greenhouse decisions/0615, the eighth review,
 * 8-M1).
 *
 * 8-M1: the budget of D18 bounded a grant's SEATS, and left two things unbounded — what narrows (a no, a «fired»,
 * from anyone) and what a NON-seat writes. So a token with only the instrument's scope, or a seat measuring red,
 * grew the book to its eight-mebibyte ceiling in about twenty-six minutes, turning the whole book into a fault and
 * denying the feature to every grant in the house until a person archived it by hand.
 *
 * Option C moves the budget off the grant and onto the WRITER (a key, a token, the terminal), and asks it of the RUN,
 * before any line exists. Exhaust a writer's budget and the house refuses its runs of an instrument or a watcher, and
 * the acts it would be lent — never another writer's, and never by dropping a no or a «fired» a run within budget had
 * already said. This file reproduces 8-M1 red-first and shows it closed, with the controls Option C turns on.
 *
 * @subject-in milpa/app-runtime
 */
final class TheBudgetIsTheWritersTest extends TestCase
{
    private LabHouse $house;

    protected function setUp(): void
    {
        $this->house = new LabHouse();
    }

    protected function tearDown(): void
    {
        $this->house->destroy();
    }

    private function token(): ToolContext
    {
        return new ToolContext(principal: 'ci', channel: 'http', scopes: ['lab:scan', 'lab:watch']);
    }

    /** As the writer `$who`, by hand, the measured lines that bring it to its budget. */
    private function writerSpendsItsBudget(string $who): void
    {
        for ($n = $this->house->ledger()->spentBy($who)['lines']; $n < StandingLedger::BUDGET; ++$n) {
            $this->house->writtenByHand(StandingLedger::MEASURED, ['by' => 'lab.scan', 'arguments' => ['path' => 'x-' . $n], 'subject' => 'sha256:' . hash('sha256', $who . $n), 'ok' => true, 'instrument' => 'sha256:any', 'who' => $who, 'at' => $this->house->now->format(\DATE_ATOM), 'arrived' => 'unsigned']);
        }
    }

    // ── 8-M1, reproduced and closed ─────────────────────────────────────────────────────────────────────────

    public function testANonSeatWriterCannotGrowTheBookPastItsBudget(): void
    {
        // As found (t83): a token that is no key, holding only the instrument's scope, measured without bound.
        $this->house->granted();
        $candidate = $this->house->candidate('theirs.txt', "a release of theirs\n");
        $this->writerSpendsItsBudget('ci');
        $before = \count($this->house->lines(StandingLedger::MEASURED));

        $denied = $this->house->through($this->token(), 'lab:scan', ['path' => $candidate]);

        self::assertCount($before, $this->house->lines(StandingLedger::MEASURED), 'a non-seat writer grew the book past its budget (8-M1)');
        self::assertStringContainsString('spent its budget', (string) ($denied['refused'] ?? 'RAN'));
        self::assertSame(['ci'], array_column($this->house->lines(StandingLedger::OVERSPENT), 'writer'));
    }

    public function testASeatCannotGrowTheBookWithNoesPastItsBudget(): void
    {
        // The other half (t100): a no bypassed the budget, so a hostile seat measured red without bound.
        $this->house->granted();
        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];
        $red = $this->house->candidate('red.txt', "machine-name: one\n");
        $this->writerSpendsItsBudget('key:' . LabHouse::SEAT);
        $before = \count($this->house->lines(StandingLedger::MEASURED));

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $red]);

        self::assertCount($before, $this->house->lines(StandingLedger::MEASURED), 'a seat grew the book with noes past its budget (8-M1)');
        self::assertSame(['key:' . LabHouse::SEAT], array_column($this->house->lines(StandingLedger::OVERSPENT), 'writer'));
    }

    // ── the controls Option C turns on ──────────────────────────────────────────────────────────────────────

    public function testAWriterWellWithinItsBudgetRunsAndIsWritten(): void
    {
        // The positive control for the two above: the gate does not over-block — a writer with budget left is written.
        $this->house->granted();
        $good = $this->house->candidate('good.txt', "clean release\n");

        $this->house->through($this->token(), 'lab:scan', ['path' => $good]);

        self::assertSame([true], $this->receiptsOn($good), 'a within-budget run was not written');
        self::assertSame([], $this->house->lines(StandingLedger::OVERSPENT));
        self::assertSame(['lines' => 1, 'stopped' => false], $this->house->ledger()->spentBy('ci'));
    }

    public function testOnlyTheWritersOwnBudgetStopsItsOwnRunsNotAnothers(): void
    {
        // 7-M1 at the root: exhausting one writer stops ITS runs, and leaves a writer with budget untouched.
        $this->house->granted();
        $good = $this->house->candidate('good.txt', "clean release\n");
        $this->writerSpendsItsBudget('ci');

        self::assertStringContainsString('spent its budget', (string) ($this->house->through($this->token(), 'lab:scan', ['path' => $good])['refused'] ?? 'RAN'));
        $before = \count($this->house->lines(StandingLedger::MEASURED));
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $good]);

        self::assertCount($before + 1, $this->house->lines(StandingLedger::MEASURED), 'a writer with budget left was denied for another writer');
        self::assertTrue($this->house->ledger()->spentBy('ci')['stopped']);
        self::assertFalse($this->house->ledger()->spentBy('key:' . LabHouse::SEAT)['stopped']);
    }

    public function testAKeyThatSpentItsBudgetCanStillStopAGrant(): void
    {
        // «una llave · estrecha → agotada le queda standing:suspend»: stopping names a grant, not an instrument, so
        // the budget does not deny it — a key keeps the one mouth that is a stop.
        $this->house->granted();
        $this->writerSpendsItsBudget('key:' . LabHouse::SEAT);
        self::assertStringContainsString('spent its budget', (string) ($this->house->as(LabHouse::SEAT, 'lab:watch')['refused'] ?? 'RAN'), 'control: its runs are denied');

        $stopped = $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'I think its watcher is wrong']);

        self::assertTrue($stopped['ok'] ?? false, (string) json_encode($stopped));
        self::assertSame('key:' . LabHouse::SEAT, $this->house->lines(StandingLedger::SUSPENDED)[0]['by'] ?? null);
        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state);
    }

    public function testAnOverspentTokensDeniedWatcherDoesNotStopTheGrant(): void
    {
        // §4.5: a token that spent its budget has no other mouth — and the house must NOT give it one. Its denied
        // watcher does not stop the grant it watched (that would return 7-M1 by another door); fail-closed, the grant
        // falls to «unwatched» when its window lapses, never stopped by the token.
        $this->house->granted();
        $this->writerSpendsItsBudget('ci');
        $this->house->fired = true;

        $denied = $this->house->through($this->token(), 'lab:watch', []);

        self::assertStringContainsString('spent its budget', (string) ($denied['refused'] ?? 'RAN'));
        self::assertSame([], array_values(array_filter(array_column($this->house->lines(StandingLedger::WATCHED), 'fired'))), 'an overspent token stopped the grant it watched');
        self::assertSame(GrantState::InForce, $this->house->book()->record('release-line')?->state);

        // control: a within-budget caller's «fired» DOES stop the grant — the denial above was the budget's, not a bug.
        self::assertSame(['ok' => true, 'fired' => true], $this->house->as(LabHouse::SEAT, 'lab:watch'));
        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state);
    }

    public function testTheLegDoorAlsoDeniesAnOverspentWritersRun(): void
    {
        // The budget is asked at every door that runs an instrument or a watcher — the leg's, as the terminal's.
        $this->house->granted();
        $this->writerSpendsItsBudget('ci');
        $candidate = $this->house->candidate('theirs.txt', "a release of theirs\n");

        $this->expectException(NotAbsorbed::class);
        $this->expectExceptionMessage('spent its budget');
        $this->house->policy->crossing($this->token(), $this->house->operation('lab:scan'), ['path' => $candidate], static fn (): array => ['ok' => true, 'acted_on' => 'sha256:x'], static fn (array $r): array => $r);
    }

    /** @return list<bool> what the receipts on this candidate say, in order */
    private function receiptsOn(string $path): array
    {
        $subject = $this->house->digestOf($path);

        return array_column(array_values(array_filter($this->house->lines(StandingLedger::MEASURED), static fn (array $r): bool => $r['subject'] === $subject)), 'ok');
    }
}
