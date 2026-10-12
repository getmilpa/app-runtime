<?php

/**
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Standing;

use Milpa\AppRuntime\Standing\HouseStandingJudge;
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\Command\Standing\Canonical;
use Milpa\Command\Standing\GrantState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every call of every seat reads the whole book, so what a seat can make the book keep is what a seat can make every
 * command of the house cost (greenhouse decisions/0615 — the sibling of the fourth review's M3, found by sweeping
 * every kind of line by who can make the house write it).
 *
 * A stop was bounded (D15). The lines beside it were not: a receipt, a watcher's sign, an act let pass and a refusal
 * each carried the arguments of a call, whole — and a caller chooses those. Measured before this file: one «no» with a
 * 4 MiB argument nobody described left a line of 4 MiB; so did one covered act; and a process of 128M stops reading a
 * book at about 40 MB.
 *
 * WHAT IS BUILT HERE IS A PROPOSAL, NOT DECIDED — built on the narrow side, to be taken out if Rod says otherwise:
 * no bound loses a stop or a red, and none turns anything green.
 */
final class WhatASeatCanMakeTheBookKeepTest extends TestCase
{
    /** What an argument nobody described weighs in these tests: a hundred times what a line should. */
    private const LONG = 100 * 1024;

    /** No line a seat causes is longer than this — a page of the disk, with room to spare. */
    private const A_LINE = 2048;

    private LabHouse $house;

    protected function setUp(): void
    {
        $this->house = new LabHouse();
    }

    protected function tearDown(): void
    {
        $this->house->destroy();
    }

    // ── how long a line is ───────────────────────────────────────────────────────────────────────────────────

    public function testANoKeepsTheArgumentsAGrantDescribesAndOnlyTheSpellingOfTheRest(): void
    {
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];
        $carried = ['path' => $path, 'note' => str_repeat('x', self::LONG)];

        $this->house->as(LabHouse::SEAT, 'lab:scan', $carried);

        $red = $this->last(StandingLedger::MEASURED);
        self::assertFalse($red['data']['ok'], 'control: the no was written');
        self::assertLessThan(self::A_LINE, $this->written($red), 'a no kept an argument nobody described, whole');
        self::assertSame($path, $red['data']['arguments']['path'] ?? null, 'the argument the grant describes is kept as it came');
        self::assertArrayNotHasKey('note', $red['data']['arguments']);
        self::assertIsString($red['data']['arguments'][HouseStandingJudge::NOT_KEPT] ?? null);
        self::assertSame(['bytes' => \strlen(Canonical::json($carried)), 'sha256' => 'sha256:' . hash('sha256', Canonical::json($carried))], $red['data']['arguments_given'] ?? null, 'the line does not say which arguments it keeps a spelling of');
        // …and it stops what it stopped before.
        self::assertStringContainsString('does not hold on this candidate', (string) ($this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path])['refused'] ?? 'RAN'));
        self::assertNull($this->house->ledger()->fault());
    }

    public function testANoWhoseDescribedArgumentIsLongerThanALineKeepsIsStillANo(): void
    {
        // The argument the grant leaves free, three thousand bytes long: the same file, by a longer path.
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => str_repeat('./', 1500) . $path]);

        $red = $this->last(StandingLedger::MEASURED);
        self::assertFalse($red['data']['ok'], 'the no was lost with the argument it could not keep');
        self::assertLessThan(self::A_LINE, $this->written($red));
        self::assertArrayNotHasKey('path', $red['data']['arguments']);
        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]));
    }

    public function testAFiredKeepsOnlyTheSpellingOfAnArgumentNobodyDescribed(): void
    {
        $this->house->granted();
        $this->house->fired = true;

        $this->house->as(LabHouse::SEAT, 'lab:watch', ['note' => str_repeat('x', self::LONG)]);

        $sign = $this->last(StandingLedger::WATCHED);
        self::assertTrue($sign['data']['fired'], 'control: «fired» was written');
        self::assertLessThan(self::A_LINE, $this->written($sign), '«fired» kept an argument nobody described, whole');
        self::assertArrayNotHasKey('note', $sign['data']['arguments']);
        self::assertArrayHasKey('arguments_given', $sign['data']);
        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state, 'the stop was lost with the argument');
    }

    public function testAYesWhoseFreeArgumentIsLongerThanALineKeepsLeavesNoReceipt(): void
    {
        // A yes needs its arguments whole to be the run a condition describes: so it is the argument that is bounded,
        // and over the bound there is no green.
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");

        self::assertTrue($this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => str_repeat('./', 1500) . $path])['ok'] ?? null, 'control: the instrument said yes');
        self::assertSame([], $this->house->lines(StandingLedger::MEASURED), 'a yes was kept with a free argument three thousand bytes long');

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        self::assertCount(1, $this->house->lines(StandingLedger::MEASURED), 'control: the same file by a path a line keeps is measured');
    }

    public function testASignOfCalmWhoseFreeArgumentIsLongerThanALineKeepsIsNotWritten(): void
    {
        // The same bound, for the other answer that speaks FOR a grant: a watcher's free argument.
        $this->house->granted(['falsifier' => ['name' => 'a-published-name', 'statement' => 'a published release names a machine', 'watcher' => ['operation' => 'lab:watch', 'free' => ['zone']], 'within_seconds' => 3600]], watched: false);

        self::assertFalse($this->house->as(LabHouse::SEAT, 'lab:watch', ['zone' => str_repeat('z', 3000)])['fired'] ?? null, 'control: the watcher said all is calm');
        self::assertSame([], $this->house->lines(StandingLedger::WATCHED), 'a sign of calm was kept with a free argument three thousand bytes long');

        $this->house->as(LabHouse::SEAT, 'lab:watch', ['zone' => 'north']);
        self::assertCount(1, $this->house->lines(StandingLedger::WATCHED), 'control: with a zone a line keeps, the sign is written');
        self::assertNotNull($this->house->book()->record('release-line')?->watcherSignalAt);
    }

    public function testWhatARunSaysItReadIsADigestALineLongOrItNamesNoContent(): void
    {
        // The edge of D14 keeps a no for the content the RUN says it read. That is the operation's own answer, and it
        // went into the receipt as it came: an answer a hundred kilobytes long made a line that long.
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");

        $this->house->policy->crossing($this->house->contextOf(LabHouse::SEAT), $this->house->operation('lab:scan'), ['path' => $path], static fn (): array => ['ok' => false, 'acted_on' => 'sha256:' . str_repeat('a', self::LONG)], static fn (array $answer): array => $answer);

        self::assertSame([$this->house->digestOf('good.txt')], array_column($this->house->lines(StandingLedger::MEASURED), 'subject'), 'the no is kept for what the house holds, and for nothing a line cannot keep');
        self::assertLessThan(self::A_LINE, $this->written($this->last(StandingLedger::MEASURED)));
    }

    public function testAnActWhoseArgumentsAreLongerThanTheHouseWritesDownIsNotLent(): void
    {
        // The house writes an act down BEFORE it runs, with what it was called with. What it will not write, it does
        // not let pass under a grant: a person makes that call.
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);

        $said = $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path, 'note' => str_repeat('x', self::LONG)]);

        self::assertStringContainsString('of arguments', (string) ($said['refused'] ?? 'RAN'), 'an act with a hundred kilobytes of arguments was let pass, and written down whole');
        self::assertSame([], $this->house->done('merged'));
        self::assertSame([], $this->house->lines(StandingLedger::ABSORBED));
        // …and one a line keeps is lent as before.
        self::assertArrayNotHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path, 'note' => str_repeat('x', 1024)]));
        self::assertCount(1, $this->house->lines(StandingLedger::ABSORBED));
        self::assertLessThan(2 * HouseStandingJudge::ACT_ARGUMENTS_KEPT, $this->written($this->last(StandingLedger::ABSORBED)));
    }

    public function testARefusalKeepsOnlyTheSpellingOfArgumentsLongerThanTheHouseWritesDown(): void
    {
        $this->house->granted();
        $path = $this->house->candidate('never-measured.txt', "nobody measured this\n");
        $carried = ['path' => $path, 'note' => str_repeat('x', self::LONG)];

        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', $carried));

        $refusal = $this->last(StandingLedger::REFUSED);
        self::assertLessThan(self::A_LINE, $this->written($refusal), 'a refusal kept the arguments of the call, whole');
        self::assertSame(['bytes' => \strlen(Canonical::json($carried)), 'sha256' => 'sha256:' . hash('sha256', Canonical::json($carried))], $refusal['data']['arguments_given'] ?? null);
    }

    // ── how many lines the same answer leaves ────────────────────────────────────────────────────────────────

    public function testTheSameKeySayingNoAgainOfContentThatIsStillRedWritesNoLine(): void
    {
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];

        foreach ([1, 2, 3] as $again) {
            self::assertFalse($this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path, 'run' => $again])['ok'] ?? null, 'control: the seat is answered no every time');
        }

        self::assertSame([true, false], array_column($this->house->lines(StandingLedger::MEASURED), 'ok'), 'the same no of the same key over the same content was written again');
        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]));
    }

    public function testANoAfterAYesIsWrittenAgainBecauseWhatTheBookSaysChanged(): void
    {
        // Red, green, red: the third is a new fact, and it has to stop the act the second let pass.
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $no = static fn (string $bytes): array => ['ok' => false];
        $yes = $this->house->scanner;
        $this->house->scanner = $no;
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $this->house->scanner = $yes;
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        self::assertArrayNotHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]), 'control: measured green again, the act passes');
        $this->house->scanner = $no;

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);

        self::assertSame([false, true, false], array_column($this->house->lines(StandingLedger::MEASURED), 'ok'), 'a no after a yes was taken for the no before it, and not written');
        self::assertStringContainsString('does not hold on this candidate', (string) ($this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path])['refused'] ?? 'RAN'));
    }

    public function testANoOfAnotherKeyOverTheSameContentIsWritten(): void
    {
        // What is not written twice is ONE key's same answer. Another key that says no is another witness.
        $this->house->granted();
        $this->house->enroll(LabHouse::OTHER_SEAT, ['agent:run', 'lab:scan'], LabHouse::PERSON);
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $this->house->as(LabHouse::OTHER_SEAT, 'lab:scan', ['path' => $path]);
        $this->house->as(LabHouse::OTHER_SEAT, 'lab:scan', ['path' => $path]);

        self::assertSame(['key:' . LabHouse::SEAT, 'key:' . LabHouse::OTHER_SEAT], array_column($this->house->lines(StandingLedger::MEASURED), 'who'));
    }

    public function testANoOfOtherContentIsWrittenEvenFromTheSameKey(): void
    {
        $this->house->granted();
        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $this->house->candidate('one.txt', "one\n")]);
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $this->house->candidate('two.txt', "two\n")]);

        self::assertSame([$this->house->digestOf('one.txt'), $this->house->digestOf('two.txt')], array_column($this->house->lines(StandingLedger::MEASURED), 'subject'));
    }

    public function testTheSameKeySayingFiredAgainOverWhatIsStillStoppedWritesNoLine(): void
    {
        $this->house->granted();
        $this->house->fired = true;

        foreach ([1, 2, 3] as $again) {
            self::assertTrue($this->house->as(LabHouse::SEAT, 'lab:watch', ['run' => $again])['fired'] ?? null, 'control: the seat is answered «fired» every time');
        }

        self::assertCount(1, $this->fired(), 'the same «fired» of the same key, over a grant it had already stopped, was written again');
        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state);
    }

    public function testAFiredIsWrittenAgainOnceAPersonLiftedTheStop(): void
    {
        // Stopped, lifted by a person, and the falsifier still fires: that is a new stop, and it has to be written.
        $this->house->granted();
        $this->house->fired = true;
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $lifted = $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => (string) $this->house->book()->record('release-line')?->suspension]);
        self::assertSame(GrantState::InForce, $this->house->book()->record('release-line')?->state, 'control: a person lifted the stop — ' . json_encode($lifted));

        $this->house->as(LabHouse::SEAT, 'lab:watch');

        self::assertCount(2, $this->fired(), 'a «fired» after a person lifted the stop was taken for the one before it');
        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state, 'a grant stayed in force while its falsifier fired');
    }

    public function testAFiredIsWrittenAgainWhenAnotherGrantItWatchesIsGiven(): void
    {
        // The guard of that rule: «still stopped» is asked of EVERY grant the operation watches. One given after the
        // first «fired» is in force, and the same key's next «fired» is what stops it.
        $this->house->granted();
        $this->house->fired = true;
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $this->house->granted(['id' => 'second-line'], watched: false);
        self::assertSame(GrantState::InForce, $this->house->book()->record('second-line')?->state, 'control: the second grant is in force');

        $this->house->as(LabHouse::SEAT, 'lab:watch');

        self::assertCount(2, $this->fired());
        self::assertSame(GrantState::Suspended, $this->house->book()->record('second-line')?->state, 'a grant given after the first «fired» was left in force by the second');
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function argumentsALineCannotHold(): iterable
    {
        yield 'text that is not text' => [['note' => "\xff\xfe"]];
        yield 'a number JSON writes as -0' => [['note' => -0.0]];
    }

    /** @param array<string, mixed> $odd */
    #[DataProvider('argumentsALineCannotHold')]
    public function testANoStillStopsWhenAnArgumentNobodyDescribedIsOneALineCannotHold(array $odd): void
    {
        // The fourth review's L1, under the bound: what the line cannot hold is spelled, never a reason to lose the no.
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path] + $odd);

        self::assertSame([true, false], array_column($this->house->lines(StandingLedger::MEASURED), 'ok'));
        self::assertSame($path, $this->last(StandingLedger::MEASURED)['data']['arguments']['path'] ?? null);
        self::assertNull($this->house->ledger()->fault());
    }

    /** @return array{type: string, at: string, prev: ?string, hash: string, data: array<string, mixed>} */
    private function last(string $type): array
    {
        $of = array_values(array_filter($this->house->ledger()->entries(), static fn (array $entry): bool => $entry['type'] === $type));
        self::assertNotSame([], $of, "the book holds no «{$type}» line");

        return $of[\count($of) - 1];
    }

    /** @param array<string, mixed> $entry how many bytes the line of that entry is */
    private function written(array $entry): int
    {
        return \strlen(Canonical::json($entry)) + 1;
    }

    /** @return list<array<string, mixed>> the watcher's signs that say «fired» */
    private function fired(): array
    {
        return array_values(array_filter($this->house->lines(StandingLedger::WATCHED), static fn (array $sign): bool => ($sign['fired'] ?? null) === true));
    }
}
