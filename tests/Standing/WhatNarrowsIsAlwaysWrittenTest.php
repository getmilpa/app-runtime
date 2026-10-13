<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Standing;

use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\Command\Standing\GrantState;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * WHAT NARROWS AND WAS SAID IS WRITTEN (greenhouse decisions/0615 — the seventh review, 7-H1 and 7-M1; decided by
 * Rod, 2026-10-13).
 *
 * Three times an economy of lines was put in front of the one write that may never be skipped: an «already said»
 * (6-M1), a bound on a name's length (6-M2), and the budget of D18 (7-H1) — a «fired» and a no said while a grant
 * was stopped for its budget were not written, a person lifted the stop the house named, and the act was lent. The
 * sentence «a line that narrows is always written» was a comment, and the no and the «fired» went down the same road
 * as every other line. So:
 *
 *   · A NO AND A «FIRED» ARE WRITTEN, whatever a grant stands as, from every door, whoever says them: the budget is
 *     not asked. Held here by a sweep, not by an example — every state a grant can stand in, the two doors a run
 *     comes through, every kind of caller, and the two things that narrow: one more line, and it is that one.
 *   · THE BUDGET OF A GRANT IS ITS SEATS': what somebody else writes is not counted under it, so whoever cannot stop
 *     a grant does not stop it by measuring.
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
            'past its budget, the stop not written yet' => [static function (LabHouse $house): void {
                $house->granted();
                self::spendAllBut($house, 0);
            }, GrantState::InForce],
            'stopped for its budget' => [static function (LabHouse $house): void {
                $house->granted();
                self::spendAllBut($house, 0);
                $house->as(LabHouse::SEAT, 'lab:scan', ['path' => $house->candidate('good.txt', "clean release\n")]);
            }, GrantState::Suspended],
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
        if ($state === 'stopped for its budget') {
            self::assertSame('the house', $this->house->lines(StandingLedger::SUSPENDED)[0]['by'] ?? null, 'control: it is the house that stopped it');
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

    // ── 7-H1, as it was found ────────────────────────────────────────────────────────────────────────────────

    public function testALiftOfABudgetStopDoesNotBringBackWhatAFiredAndANoSaidWhileItStood(): void
    {
        $this->house->granted();
        $good = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $good]);
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $good]), 'control: the green counts and the act is lent');
        self::spendAllBut($this->house, 0);
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $this->house->candidate('other.txt', "another clean release\n")]);
        $budgetStop = (string) $this->house->book()->record('release-line')?->suspension;
        self::assertSame(['the house'], array_column($this->house->lines(StandingLedger::SUSPENDED), 'by'), 'control: the house stopped the grant for its budget');

        // WHILE IT IS STOPPED: the falsifier fires and a person runs the watcher; a stricter run says no of the candidate.
        $this->house->fired = true;
        $this->house->as(LabHouse::PERSON, 'lab:watch');
        $this->house->fired = false;
        $lenient = $this->house->scanner;
        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];
        $this->house->as(LabHouse::PERSON, 'lab:scan', ['path' => $good]);
        $this->house->scanner = $lenient;

        self::assertSame([true], array_values(array_filter(array_column($this->house->lines(StandingLedger::WATCHED), 'fired'))), 'the «fired» said while the grant was stopped for its budget was not written');
        $noes = array_filter($this->house->lines(StandingLedger::MEASURED), fn (array $receipt): bool => $receipt['subject'] === $this->house->digestOf($good) && $receipt['ok'] === false);
        self::assertCount(1, $noes, 'the no said while the grant was stopped for its budget was not written');

        // The stop that stands is the «fired» now, and the lift of the budget stop — by its name — lifts nothing.
        $lifted = $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $budgetStop]);
        self::assertNotTrue($lifted['ok'] ?? null, 'the stop the house had named was lifted over a «fired» written after it');
        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state);

        // A person lifts the stop that stands; the watcher is calm again; and the no still says no of that candidate.
        self::assertTrue($this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => (string) $this->house->book()->record('release-line')?->suspension])['ok'] ?? null);
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $merged = $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $good]);

        self::assertArrayHasKey('refused', $merged, 'an act was lent on a candidate a no was said of, and never measured again');
        self::assertCount(1, $this->house->done('merged'), 'and it ran');
    }

    // ── 7-M1: whose budget it is ─────────────────────────────────────────────────────────────────────────────

    /** @return iterable<string, array{string, array<string, mixed>}> a line the house writes for somebody who is not a seat of the lab grant */
    public static function whatSomebodyElseWrites(): iterable
    {
        $receipt = static fn (string $who): array => ['by' => 'lab.scan', 'arguments' => ['path' => 'x'], 'subject' => 'sha256:x', 'ok' => true, 'instrument' => 'sha256:any', 'who' => $who, 'at' => '2026-10-11T12:00:00+00:00'];
        yield 'a receipt of the person who gave it' => [StandingLedger::MEASURED, $receipt('key:' . LabHouse::PERSON)];
        yield 'a receipt of another key of the house' => [StandingLedger::MEASURED, $receipt('key:' . LabHouse::OTHER_SEAT)];
        yield 'a receipt of a token that is no key' => [StandingLedger::MEASURED, $receipt('ci')];
        yield 'a receipt of the terminal of the house' => [StandingLedger::MEASURED, $receipt('local-shell')];
        // The bearer of a token carries any actor's name — a seat's very key, without what says it is a key.
        yield 'a receipt of a token that carries the name of its seat' => [StandingLedger::MEASURED, $receipt(LabHouse::SEAT)];
        yield 'a receipt that does not say who measured' => [StandingLedger::MEASURED, array_diff_key($receipt('x'), ['who' => true])];
        yield 'a sign of its watcher by another key' => [StandingLedger::WATCHED, ['watcher' => 'lab.watch', 'arguments' => [], 'fired' => false, 'who' => 'key:' . LabHouse::OTHER_SEAT, 'pin' => 'sha256:any']];
        yield 'a «fired» of its watcher by a token' => [StandingLedger::WATCHED, ['watcher' => 'lab.watch', 'arguments' => [], 'fired' => true, 'who' => 'ci', 'pin' => null]];
        yield 'a refusal it gave to a key that is not its seat' => [StandingLedger::REFUSED, ['grant' => 'release-line', 'operation' => 'lab.merge', 'seat' => LabHouse::OTHER_SEAT, 'subject' => 'sha256:x', 'check' => 'conditions', 'reason' => 'measured_red']];
    }

    /** @param array<string, mixed> $line */
    #[DataProvider('whatSomebodyElseWrites')]
    public function testWhatSomebodyWhoIsNotASeatOfAGrantWritesIsNotCountedUnderIt(string $type, array $line): void
    {
        $this->house->granted(watched: false);

        $this->house->writtenByHand($type, $line);
        $this->house->writtenByHand($type, $line);

        self::assertSame(0, $this->house->ledger()->spentUnder('release-line')['lines']);
    }

    public function testTheSeatsOfAGrantAreTheOnesItsOwnSignedDocumentNames(): void
    {
        $this->house->granted(['to' => [LabHouse::SEAT, LabHouse::OTHER_SEAT]], watched: false);
        $receipt = static fn (string $who): array => ['by' => 'lab.scan', 'arguments' => ['path' => 'x'], 'subject' => 'sha256:x', 'ok' => true, 'instrument' => 'sha256:any', 'who' => $who, 'at' => '2026-10-11T12:00:00+00:00'];

        $this->house->writtenByHand(StandingLedger::MEASURED, $receipt('key:' . LabHouse::SEAT));
        $this->house->writtenByHand(StandingLedger::MEASURED, $receipt('key:' . LabHouse::OTHER_SEAT));
        $this->house->writtenByHand(StandingLedger::MEASURED, $receipt('key:' . LabHouse::PERSON));

        self::assertSame(2, $this->house->ledger()->spentUnder('release-line')['lines']);
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
        // Found with a token that the decided stop — `standing:suspend` — refuses: 2,997 of its measurements stopped
        // a seat's grant. As many lines as a budget holds are written here as that caller's, and then it measures
        // once more through the door.
        $this->house->granted();
        $good = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $good]);
        $caller = $context($this->house);
        for ($written = 0; $written < StandingLedger::BUDGET; ++$written) {
            $this->house->writtenByHand(StandingLedger::MEASURED, ['by' => 'lab.scan', 'arguments' => ['path' => 'seen-' . $written . '.txt'], 'subject' => 'sha256:' . hash('sha256', (string) $written), 'ok' => true, 'instrument' => 'sha256:any', 'who' => $caller->principal ?? 'local-shell', 'at' => $this->house->now->format(\DATE_ATOM), 'arrived' => 'unsigned']);
        }

        $this->house->through($caller, 'lab:scan', ['path' => $this->house->candidate('theirs.txt', "a release of theirs\n")]);

        self::assertSame([], $this->house->lines(StandingLedger::SUSPENDED), 'a grant was stopped for what somebody who is not its seat wrote');
        self::assertSame(GrantState::InForce, $this->house->book()->record('release-line')?->state);
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $good]), 'and its seat was lent nothing');
    }

    // ── what these tests are staged with ────────────────────────────────────────────────────────────────────

    /** Write, as the lab grant's own seat measuring content after content, the lines that leave this many of its budget unspent. */
    private static function spendAllBut(LabHouse $house, int $left): void
    {
        for ($spent = $house->ledger()->spentUnder('release-line')['lines']; $spent < StandingLedger::BUDGET - $left; ++$spent) {
            $house->writtenByHand(StandingLedger::MEASURED, ['by' => 'lab.scan', 'arguments' => ['path' => 'seen-' . $spent . '.txt'], 'subject' => 'sha256:' . hash('sha256', (string) $spent), 'ok' => true, 'instrument' => 'sha256:any', 'who' => 'key:' . LabHouse::SEAT, 'at' => $house->now->format(\DATE_ATOM), 'arrived' => 'unsigned']);
        }
    }
}
