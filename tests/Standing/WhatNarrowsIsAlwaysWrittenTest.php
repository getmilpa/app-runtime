<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Standing;

use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\Command\Standing\GrantState;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * WHAT NARROWS AND WAS SAID IS WRITTEN (greenhouse decisions/0615 — the seventh review, 7-H1 and 7-M1; the eighth,
 * 8-M1; Option C decided by Rod, 2026-10-14).
 *
 * Four times an economy of lines was put in front of the one write that may never be skipped: an «already said»
 * (6-M1), a bound on a name's length (6-M2), the budget of D18 (7-H1), and — the other way — no bound at all on what
 * narrows, nor on what a non-seat writes, so one caller filled the book to its ceiling and denied the feature to the
 * whole house (8-M1). Option C answers the last pair by moving the budget off the grant and onto THE WRITER, and by
 * asking it of the RUN, before any line exists — never in front of the write. So:
 *
 *   · A NO AND A «FIRED» A RUN SAID ARE WRITTEN, whatever a grant stands as, from every door, whoever says them: the
 *     write is not asked a budget. Held here by a sweep, not by an example — every state a grant can stand in, the two
 *     doors a run comes through, every kind of caller, and the two things that narrow: one more line, and it is that
 *     one. What the budget denies is the RUN of a writer that spent it — before it says anything ({@see
 *     TheBudgetIsTheWritersTest}).
 *   · THE BUDGET IS THE WRITER'S: what one writer leaves is counted against it, by name, never against a grant — so
 *     whoever cannot stop a grant does not stop it by measuring; its runs spend only its own budget.
 *
 * @subject-in milpa/app-runtime
 */
final class WhatNarrowsIsAlwaysWrittenTest extends TestCase
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

    // ── the sweep: state × door × caller × what narrows ─────────────────────────────────────────────────────

    /**
     * Every state a grant can stand in, as what stages it — and what the book then reads it as (the control that the
     * state was staged), or `null` where that is not what the state is about.
     *
     * @return array<string, array{\Closure(LabHouse): void, ?GrantState}>
     */
    private static function states(): array
    {
        $pause = static fn (LabHouse $house, string $by): mixed => $house->signed($by, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen']);

        return [
            'in force and watched' => [static fn (LabHouse $house) => $house->granted(), GrantState::InForce],
            'in force, never watched' => [static fn (LabHouse $house) => $house->granted(watched: false), GrantState::InForce],
            'paused by its seat' => [static function (LabHouse $house) use ($pause): void {
                $house->granted();
                $pause($house, LabHouse::SEAT);
            }, GrantState::Suspended],
            'stopped by a person' => [static function (LabHouse $house) use ($pause): void {
                $house->granted();
                $pause($house, LabHouse::PERSON);
            }, GrantState::Suspended],
            'stopped by a «fired»' => [static function (LabHouse $house): void {
                $house->granted();
                $house->fired = true;
                $house->as(LabHouse::SEAT, 'lab:watch');
            }, GrantState::Suspended],
            'lifted by a person' => [static function (LabHouse $house) use ($pause): void {
                $house->granted();
                $pause($house, LabHouse::SEAT);
                $house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => (string) $house->book()->record('release-line')?->suspension]);
            }, GrantState::InForce],
            'withdrawn by a person' => [static function (LabHouse $house): void {
                $house->granted();
                $house->signed(LabHouse::PERSON, 'standing:revoke', ['grant' => 'release-line']);
            }, GrantState::Revoked],
            'replaced by another grant' => [static function (LabHouse $house): void {
                $house->granted();
                $house->granted(['id' => 'release-line-2', 'supersedes' => 'release-line']);
            }, GrantState::Superseded],
            'given over an instrument that changed since' => [static function (LabHouse $house): void {
                $house->granted();
                $house->hardenScanner();
            }, null],
        ];
    }

    /** @return array<string, \Closure(LabHouse): ToolContext> who says it */
    private static function callers(): array
    {
        return [
            'its seat' => static fn (LabHouse $house): ToolContext => $house->contextOf(LabHouse::SEAT),
            'the person who gave it' => static fn (LabHouse $house): ToolContext => $house->contextOf(LabHouse::PERSON),
            'another key of the house' => static fn (): ToolContext => new ToolContext(principal: 'key:' . LabHouse::OTHER_SEAT, channel: 'cli', scopes: ['lab:scan', 'lab:watch']),
            'a token that is no key' => static fn (): ToolContext => new ToolContext(principal: 'ci', channel: 'http', scopes: ['lab:scan', 'lab:watch']),
            'the terminal of the house' => static fn (): ToolContext => ToolContext::cli(),
        ];
    }

    /** @return iterable<string, array{string, bool, string, bool}> state, whether the run is this process's own, caller, whether it is a «fired» */
    public static function everyCell(): iterable
    {
        foreach (array_keys(self::states()) as $state) {
            foreach (['its own run' => true, 'a run that left this process' => false] as $door => $here) {
                foreach (array_keys(self::callers()) as $caller) {
                    foreach (['a no' => false, 'a «fired»' => true] as $said => $fired) {
                        yield "{$state} · {$door} · {$caller} · {$said}" => [$state, $here, $caller, $fired];
                    }
                }
            }
        }
    }

    #[DataProvider('everyCell')]
    public function testWhateverAGrantStandsAsANoAndAFiredAreWrittenFromEveryDoorWhoeverSaysThem(string $state, bool $here, string $caller, bool $fired): void
    {
        [$stage, $standsAs] = self::states()[$state];
        $stage($this->house);
        if ($standsAs !== null) {
            self::assertSame($standsAs, $this->house->book()->record('release-line')?->state, 'control: the state this cell is about was not staged');
        }
        $context = self::callers()[$caller]($this->house);
        $red = $this->house->candidate('red.txt', "machine-name: one\n");
        [$name, $input, $answer] = $fired
            ? ['lab:watch', [], ['fired' => true]]
            : ['lab:scan', ['path' => $red], ['ok' => false, 'acted_on' => $this->house->digestOf($red)]];
        $this->house->fired = $fired;
        $before = \count($this->house->everyLine());

        if ($here) {
            $this->house->through($context, $name, $input);
        } else {
            $this->house->policy->crossing($context, $this->house->operation($name), $input, static fn (): array => $answer, static fn (array $given): array => $given);
        }

        $added = \array_slice($this->house->everyLine(), $before);
        self::assertCount(1, $added, 'what narrows left ' . \count($added) . ' lines, and it is owed exactly one: ' . json_encode(array_column($added, 'type')));
        if ($fired) {
            self::assertSame([StandingLedger::WATCHED, true], [$added[0]['type'], $added[0]['data']['fired'] ?? null], 'the line that was written is not the «fired»');
        } else {
            self::assertSame([StandingLedger::MEASURED, false, $this->house->digestOf($red)], [$added[0]['type'], $added[0]['data']['ok'] ?? null, $added[0]['data']['subject'] ?? null], 'the line that was written is not the no');
        }
    }

    // ── 7-H1 under Option C: the budget denies the RUN, never the write of a no a run said ───────────────────

    public function testANoAWithinBudgetRunSaidIsWrittenAndItIsTheNextRunTheBudgetDenies(): void
    {
        // 7-H1 was a no and a «fired» DROPPED while a grant was stopped for its budget; a person lifted the stop and
        // the act was lent on a candidate a no had been said of. Under Option C nothing is dropped: the budget is
        // asked of the RUN, and a no a run within budget said goes straight into the book.
        $this->house->granted();
        $good = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $good]);
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $good]), 'control: the green counts and the act is lent');

        // The seat is one line short of its budget; a stricter run says no — within budget, so it is written.
        $this->spendSeatTo(StandingLedger::BUDGET - 1);
        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $good]);
        $noes = array_filter($this->house->lines(StandingLedger::MEASURED), fn (array $r): bool => $r['subject'] === $this->house->digestOf($good) && $r['ok'] === false);
        self::assertCount(1, $noes, 'the no said by a run within budget was not written');
        self::assertSame(['lines' => StandingLedger::BUDGET, 'stopped' => false], $this->house->ledger()->spentBy('key:' . LabHouse::SEAT));

        // That no counts: the act is refused on that candidate until it is measured green again.
        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $good]), 'an act was lent on a candidate a no was said of');

        // The seat's NEXT run of the instrument is the one the budget denies — before it says anything.
        $before = \count($this->house->lines(StandingLedger::MEASURED));
        $denied = $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $good]);
        self::assertCount($before, $this->house->lines(StandingLedger::MEASURED), 'a run past the budget still said a no');
        self::assertStringContainsString('spent its budget', (string) ($denied['refused'] ?? 'RAN'));
        self::assertTrue($this->house->ledger()->spentBy('key:' . LabHouse::SEAT)['stopped']);

        // And a person lifts the writer's stop, by its name; the writer scans again.
        $stop = (string) $this->house->ledger()->haltedAt('key:' . LabHouse::SEAT);
        self::assertTrue($this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['writer' => 'key:' . LabHouse::SEAT, 'suspension' => $stop])['ok'] ?? null);
        $this->house->scanner = static fn (string $bytes): array => ['ok' => true];
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $good]);
        self::assertSame([true, false, true], array_column(array_filter($this->house->lines(StandingLedger::MEASURED), fn (array $r): bool => $r['subject'] === $this->house->digestOf($good)), 'ok'), 'after the lift the writer measures again (green, no, green)');
    }

    // ── 7-M1 at the root: the budget is the writer's, counted by name, never a grant's ──────────────────────

    /** @return iterable<string, array{string, string, array<string, mixed>}> a writer, and a line it leaves */
    public static function whatEachWriterLeaves(): iterable
    {
        $receipt = static fn (string $who): array => ['by' => 'lab.scan', 'arguments' => ['path' => 'x'], 'subject' => 'sha256:x', 'ok' => true, 'instrument' => 'sha256:any', 'who' => $who, 'at' => '2026-10-11T12:00:00+00:00'];
        $sign = static fn (string $who): array => ['watcher' => 'lab.watch', 'arguments' => [], 'fired' => false, 'who' => $who, 'pin' => 'sha256:any'];
        yield 'a seat, by its key' => ['key:' . LabHouse::SEAT, StandingLedger::MEASURED, $receipt('key:' . LabHouse::SEAT)];
        yield 'the person who gave it' => ['key:' . LabHouse::PERSON, StandingLedger::MEASURED, $receipt('key:' . LabHouse::PERSON)];
        yield 'another key of the house' => ['key:' . LabHouse::OTHER_SEAT, StandingLedger::WATCHED, $sign('key:' . LabHouse::OTHER_SEAT)];
        yield 'a token that is no key' => ['ci', StandingLedger::MEASURED, $receipt('ci')];
        yield 'the terminal of the house' => ['local-shell', StandingLedger::WATCHED, $sign('local-shell')];
        // The bearer of a token carries any actor's name — a seat's very key, without what says it is a key: another writer.
        yield 'a token that carries the name of its seat' => [LabHouse::SEAT, StandingLedger::MEASURED, $receipt(LabHouse::SEAT)];
    }

    /** @param array<string, mixed> $line */
    #[DataProvider('whatEachWriterLeaves')]
    public function testWhatAWriterLeavesIsCountedAgainstItByNameAndNeverStopsAGrant(string $writer, string $type, array $line): void
    {
        $this->house->granted(watched: false);

        $this->house->writtenByHand($type, $line);
        $this->house->writtenByHand($type, $line);

        self::assertSame(2, $this->house->ledger()->spentBy($writer)['lines'], 'the two lines were not counted against the writer that left them');
        self::assertSame([], $this->house->lines(StandingLedger::OVERSPENT), 'two lines stopped a writer');
        self::assertSame(GrantState::InForce, $this->house->book()->record('release-line')?->state, 'what a writer wrote stopped a grant');
    }

    public function testALineThatSaysNoWriterIsCountedAgainstNobody(): void
    {
        $this->house->granted(watched: false);
        $receipt = ['by' => 'lab.scan', 'arguments' => ['path' => 'x'], 'subject' => 'sha256:x', 'ok' => true, 'instrument' => 'sha256:any', 'at' => '2026-10-11T12:00:00+00:00'];

        $this->house->writtenByHand(StandingLedger::MEASURED, $receipt);

        self::assertSame([], $this->house->lines(StandingLedger::OVERSPENT));
        self::assertSame(0, $this->house->ledger()->spentBy('local-shell')['lines'], 'a line that names no writer was counted against the terminal');
    }

    /** @return iterable<string, array{\Closure(LabHouse): ToolContext}> */
    public static function whoIsNotItsSeat(): iterable
    {
        foreach (array_diff_key(self::callers(), ['its seat' => true]) as $caller => $context) {
            yield $caller => [$context];
        }
    }

    /** @param \Closure(LabHouse): ToolContext $context */
    #[DataProvider('whoIsNotItsSeat')]
    public function testWhoeverIsNotASeatOfAGrantDoesNotStopItByMeasuring(\Closure $context): void
    {
        // Found with a token the decided stop — `standing:suspend` — refuses: 2,997 of its measurements stopped a
        // seat's grant (7-M1). Under Option C its measurements spend its OWN budget and stop its OWN runs; the grant is
        // never stopped, and the seat is lent its act as before.
        $this->house->granted();
        $good = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $good]);
        $caller = $context($this->house);
        $who = $caller->principal ?? 'local-shell';
        for ($written = 0; $written < StandingLedger::BUDGET; ++$written) {
            $this->house->writtenByHand(StandingLedger::MEASURED, ['by' => 'lab.scan', 'arguments' => ['path' => 'seen-' . $written . '.txt'], 'subject' => 'sha256:' . hash('sha256', (string) $written), 'ok' => true, 'instrument' => 'sha256:any', 'who' => $who, 'at' => $this->house->now->format(\DATE_ATOM), 'arrived' => 'unsigned']);
        }

        $measured = $this->house->through($caller, 'lab:scan', ['path' => $this->house->candidate('theirs.txt', "a release of theirs\n")]);

        self::assertSame([], $this->house->lines(StandingLedger::SUSPENDED), 'a grant was stopped for what a writer that is not its seat wrote');
        self::assertSame(GrantState::InForce, $this->house->book()->record('release-line')?->state);
        self::assertStringContainsString('spent its budget', (string) ($measured['refused'] ?? 'RAN'), 'the writer that measured was not the one stopped');
        self::assertSame([$who], array_column($this->house->lines(StandingLedger::OVERSPENT), 'writer'), 'the stop did not name the writer that spent its budget');
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $good]), 'and its seat was lent nothing');
    }

    // ── what these tests are staged with ────────────────────────────────────────────────────────────────────

    /** Write, as the lab grant's own seat measuring content after content, lines until the seat has left this many. */
    private function spendSeatTo(int $lines): void
    {
        for ($spent = $this->house->ledger()->spentBy('key:' . LabHouse::SEAT)['lines']; $spent < $lines; ++$spent) {
            $this->house->writtenByHand(StandingLedger::MEASURED, ['by' => 'lab.scan', 'arguments' => ['path' => 'seen-' . $spent . '.txt'], 'subject' => 'sha256:' . hash('sha256', (string) $spent), 'ok' => true, 'instrument' => 'sha256:any', 'who' => 'key:' . LabHouse::SEAT, 'at' => $this->house->now->format(\DATE_ATOM), 'arrived' => 'unsigned']);
        }
    }
}
