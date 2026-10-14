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
 * WHAT IS BUILT HERE IS A PROPOSAL, NOT DECIDED — built on the narrow side, to be taken out if Rod says otherwise.
 * After the sixth review it is ONE rule for what a line keeps of a call's arguments, and no line is left unwritten
 * because of it; a sign of calm once per key per quarter window; and a seat's refusals counted over the lines that
 * change the standing of its grant.
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

    // ── what a line keeps of the arguments of a call ─────────────────────────────────────────────────────────
    //
    // ONE RULE FOR EVERY LINE, AND IT NEVER DROPS ONE: the arguments as they came when they are short and a line can
    // hold them, and otherwise how long they were and their digest. It replaces a bound per kind of line — two of
    // which lost a line the house owed (the sixth review, 6-M2 and 6-M6).

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function argumentsALineDoesNotKeepAsTheyCame(): iterable
    {
        yield 'a hundred kilobytes of an argument' => [['note' => str_repeat('x', self::LONG)]];
        yield 'text that is not text' => [['note' => "\xff\xfe"]];
        yield 'a number JSON writes as -0 and reads as 0' => [['note' => -0.0]];
    }

    /** @param array<string, mixed> $odd */
    #[DataProvider('argumentsALineDoesNotKeepAsTheyCame')]
    public function testANoIsWrittenWhateverItsRunCarriedAndStops(array $odd): void
    {
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path] + $odd);

        self::assertSame([true, false], array_column($this->house->lines(StandingLedger::MEASURED), 'ok'), 'a no was not written for what its run carried');
        $no = $this->last(StandingLedger::MEASURED);
        self::assertLessThan(self::A_LINE, $this->written($no));
        self::assertSame([], $no['data']['arguments']);
        self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', (string) ($no['data']['arguments_given']['sha256'] ?? ''));
        self::assertNull($this->house->ledger()->fault());
        self::assertStringContainsString('said no', (string) ($this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path])['refused'] ?? 'RAN'));
    }

    /** @param array<string, mixed> $odd */
    #[DataProvider('argumentsALineDoesNotKeepAsTheyCame')]
    public function testAFiredIsWrittenWhateverItsRunCarriedAndStops(array $odd): void
    {
        $this->house->granted();
        $this->house->fired = true;

        $this->house->as(LabHouse::SEAT, 'lab:watch', $odd);

        $fired = $this->last(StandingLedger::WATCHED);
        self::assertTrue($fired['data']['fired']);
        self::assertLessThan(self::A_LINE, $this->written($fired));
        self::assertArrayHasKey('arguments_given', $fired['data']);
        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state);
        self::assertNull($this->house->ledger()->fault());
    }

    /** @param array<string, mixed> $odd */
    #[DataProvider('argumentsALineDoesNotKeepAsTheyCame')]
    public function testAnActIsLentWhateverItCarriesAndARefusalIsKept(array $odd): void
    {
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $other = $this->house->candidate('never-measured.txt', "nobody measured this\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);

        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path] + $odd), 'an act the grant covers was not lent for what it carried');
        $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $other] + $odd);

        foreach ([StandingLedger::ABSORBED, StandingLedger::REFUSED] as $kind) {
            $line = $this->last($kind);
            self::assertLessThan(self::A_LINE, $this->written($line), "a «{$kind}» line kept what the call carried");
            self::assertSame([], $line['data']['arguments']);
            self::assertArrayHasKey('arguments_given', $line['data']);
        }
        self::assertNull($this->house->ledger()->fault());
    }

    public function testALineKeepsShortArgumentsAsTheyCame(): void
    {
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]);

        foreach ([StandingLedger::MEASURED, StandingLedger::ABSORBED] as $kind) {
            self::assertSame(['path' => $path], $this->last($kind)['data']['arguments']);
            self::assertArrayNotHasKey('arguments_given', $this->last($kind)['data']);
        }
    }

    public function testAYesAndASignOfCalmAreOnlyTakenFromARunWhoseArgumentsALineKeepsAsTheyCame(): void
    {
        // What speaks FOR a grant is the run a grant describes, argument by argument: one whose arguments a line does
        // not keep as they came is not written as that run. It only narrows.
        $this->house->granted(['conditions' => [['name' => 'no-host-names', 'statement' => 'what is published names no machine', 'by' => ['operation' => 'lab:scan', 'free' => ['path', 'note']]]],
            'falsifier' => ['name' => 'a-published-name', 'statement' => 'a published release names a machine', 'watcher' => ['operation' => 'lab:watch', 'free' => ['note']], 'within_seconds' => 3600]], watched: false);
        $path = $this->house->candidate('good.txt', "clean release\n");

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path, 'note' => str_repeat('x', self::LONG)]);
        $this->house->as(LabHouse::SEAT, 'lab:watch', ['note' => str_repeat('x', self::LONG)]);
        self::assertSame([], $this->house->lines(StandingLedger::MEASURED), 'a yes was written from a run a line does not keep as it came');
        self::assertSame([], $this->house->lines(StandingLedger::WATCHED));

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path, 'note' => 'short']);
        $this->house->as(LabHouse::SEAT, 'lab:watch', ['note' => 'short']);
        self::assertCount(1, $this->house->lines(StandingLedger::MEASURED), 'control: the same run, short, is written');
        self::assertCount(1, $this->house->lines(StandingLedger::WATCHED));
    }

    public function testWhatARunSaysItReadIsWrittenAsItsDigestWhenItIsNotOneShortLine(): void
    {
        // A no is kept for the content the run SAYS it read too — a rehearsal measures a copy. What the house read is
        // written as it is, whatever its length (6-M2); what a run says, when it is not a short line, as its digest.
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $long = str_repeat('a name nobody could act on ', 40);
        $this->house->scanner = static fn (string $bytes): array => ['ok' => false, 'acted_on' => $long];
        $this->house->scannerSaysWhatItRead = false;

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);

        $about = array_column($this->house->lines(StandingLedger::MEASURED), 'subject');
        self::assertSame([$this->house->digestOf($path), 'sha256:' . hash('sha256', $long)], $about, 'a no was lost, or a line carried what a run said whole');
    }

    // ── a line that narrows is always written ────────────────────────────────────────────────────────────────

    public function testTheSameNoAndTheSameFiredAreWrittenEachTimeTheyAreSaid(): void
    {
        // «Already said» was asked of what a process derives of the book today, and a process that could not verify a
        // lift wrote nothing (the sixth review, 6-M1; 6-L2 for a yes). Nothing asks it any more.
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->scanner = static fn (string $bytes): array => ['ok' => false];
        $this->house->fired = true;

        foreach ([1, 2, 3] as $time) {
            $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
            $this->house->as(LabHouse::SEAT, 'lab:watch');
        }

        self::assertCount(3, $this->house->lines(StandingLedger::MEASURED));
        self::assertCount(3, array_filter($this->house->lines(StandingLedger::WATCHED), static fn (array $line): bool => $line['fired'] === true));
    }

    public function testASignOfCalmIsNotWrittenAgainWhileTheLastOneOfThatKeyIsYoung(): void
    {
        // A sign of life is a time, so it cannot be «the same» as the one before — but one a minute after another
        // moves that time by a minute. It is written again once the last one of that key is a quarter of the
        // falsifier's window old: a grant watched every minute keeps four lines an hour, not sixty.
        $this->house->granted();
        self::assertCount(1, $this->house->lines(StandingLedger::WATCHED), 'control: the first sign is written');

        foreach ([1, 2, 3] as $again) {
            $this->house->later(60);
            self::assertFalse($this->house->as(LabHouse::SEAT, 'lab:watch')['fired'] ?? null);
        }
        self::assertCount(1, $this->house->lines(StandingLedger::WATCHED), 'a sign of calm three minutes after the last one of that key was written again');

        $this->house->later(900);
        $this->house->as(LabHouse::SEAT, 'lab:watch');

        self::assertCount(2, $this->house->lines(StandingLedger::WATCHED), 'a sign a quarter of the window after the last one was not written');
        self::assertEquals($this->house->now, $this->house->book()->record('release-line')?->watcherSignalAt);
    }

    public function testASignOfCalmIsWrittenAtOnceAfterAStopWasLifted(): void
    {
        // The guard of that rule: a grant a person lifted is in force and UNWATCHED until its watcher speaks again.
        // That sign must not be taken for the young one before the stop.
        $this->house->granted();
        $this->house->signed(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen']);
        $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => (string) $this->house->book()->record('release-line')?->suspension]);
        self::assertNull($this->house->book()->record('release-line')?->watcherSignalAt, 'control: lifted, and unwatched');

        $this->house->as(LabHouse::SEAT, 'lab:watch');

        self::assertNotNull($this->house->book()->record('release-line')?->watcherSignalAt, 'a grant stayed unwatched because its watcher had spoken a moment before the stop');
    }

    public function testAnotherKeysSignOfCalmIsItsOwn(): void
    {
        // No key can keep another's sign of life out of the book: what is not written again is ONE key's own.
        $this->house->granted();
        $this->house->enroll(LabHouse::OTHER_SEAT, ['agent:run', 'lab:watch'], LabHouse::PERSON);

        $this->house->as(LabHouse::OTHER_SEAT, 'lab:watch');
        $this->house->as(LabHouse::OTHER_SEAT, 'lab:watch');

        self::assertSame(['key:' . LabHouse::SEAT, 'key:' . LabHouse::OTHER_SEAT], array_column($this->house->lines(StandingLedger::WATCHED), 'who'));
    }

    public function testASeatsRefusalsAreNotCountedAgainBecauseSomeoneElseWroteALine(): void
    {
        // The fifth review: the cap on a seat's refusals under a grant ended at the first line of another kind —
        // anyone's. A seat with no word of its own wrote its thirty-two again for each line the house wrote.
        $this->house->granted();
        $this->house->enroll(LabHouse::OTHER_SEAT, ['agent:run', 'lab:watch'], LabHouse::PERSON);
        $path = $this->house->candidate('never-measured.txt', "nobody measured this\n");
        $asks = function (string $round) use ($path): void {
            for ($n = 0; $n < 40; ++$n) {
                $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path, 'note' => $round . $n]);
            }
        };
        $asks('a');
        self::assertCount(HouseStandingJudge::REFUSALS_KEPT, $this->house->lines(StandingLedger::REFUSED), 'control: the cap');

        $this->house->as(LabHouse::OTHER_SEAT, 'lab:watch');
        $asks('b');

        self::assertCount(HouseStandingJudge::REFUSALS_KEPT, $this->house->lines(StandingLedger::REFUSED), 'the cap on one seat\'s refusals started again with another seat\'s line');
    }

    public function testASeatsRefusalsAreCountedAgainOnceTheGrantsStandingChanges(): void
    {
        // The guard: what a seat is refused after the grant was stopped is another fact, and it is written.
        $this->house->granted();
        $path = $this->house->candidate('never-measured.txt', "nobody measured this\n");
        for ($n = 0; $n < 40; ++$n) {
            $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path, 'note' => 'a' . $n]);
        }
        $this->house->signed(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen']);

        self::assertStringContainsString('suspended', (string) ($this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path])['refused'] ?? 'RAN'));

        self::assertCount(HouseStandingJudge::REFUSALS_KEPT + 1, $this->house->lines(StandingLedger::REFUSED), 'a refusal after the grant was stopped was not written');
    }

    /** @return iterable<string, array{0: \Closure(LabHouse): void, 1: string}> what changes the standing of a grant, and what a seat is then refused for */
    public static function whatChangesTheStandingOfAGrant(): iterable
    {
        yield 'its falsifier fires' => [static function (LabHouse $house): void {
            $house->fired = true;
            $house->as(LabHouse::OTHER_SEAT, 'lab:watch');
            $house->fired = false;
        }, 'suspended'];
        yield 'a person lifts a stop' => [static function (LabHouse $house): void {
            $house->signed(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen']);
            $house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => (string) $house->book()->record('release-line')?->suspension]);
            $house->as(LabHouse::OTHER_SEAT, 'lab:watch');
        }, 'never measured'];
        yield 'a person gives another grant' => [static function (LabHouse $house): void {
            $house->granted(['id' => 'second-line', 'covers' => [['operation' => 'lab:announce']]], watched: false);
        }, 'never measured'];
        yield 'a person withdraws it' => [static function (LabHouse $house): void {
            $house->signed(LabHouse::PERSON, 'standing:revoke', ['grant' => 'release-line']);
            $house->granted(['id' => 'given-again']);
        }, 'never measured'];
    }

    /** @param \Closure(LabHouse): void $changes */
    #[DataProvider('whatChangesTheStandingOfAGrant')]
    public function testASeatsRefusalsAreCountedAgainAfterEachThingThatChangesTheStandingOfAGrant(\Closure $changes, string $refusedFor): void
    {
        $this->house->granted();
        $this->house->enroll(LabHouse::OTHER_SEAT, ['agent:run', 'lab:watch'], LabHouse::PERSON);
        $path = $this->house->candidate('never-measured.txt', "nobody measured this\n");
        for ($n = 0; $n < 40; ++$n) {
            $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path, 'note' => 'a' . $n]);
        }
        $kept = \count($this->house->lines(StandingLedger::REFUSED));
        self::assertSame(HouseStandingJudge::REFUSALS_KEPT, $kept, 'control: the cap');

        $changes($this->house);
        $said = $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path, 'note' => 'after']);

        self::assertStringContainsString($refusedFor, (string) ($said['refused'] ?? 'RAN'), 'control: the seat is refused, for what stands now');
        self::assertCount($kept + 1, $this->house->lines(StandingLedger::REFUSED), 'a refusal after the standing of the grant changed was not written');
    }

    // ── how long the book is ─────────────────────────────────────────────────────────────────────────────────

    public function testABookLongerThanTheHouseReadsIsAFaultAndNoSeatActs(): void
    {
        // Every bound above makes a line short; none makes the book short: an act a seat is lent is a line, and a
        // house that works writes many. A process of 128M stops — dies, for every command of every caller — reading
        // a book of some twenty megabytes of short lines. So past a length the house does not read its book: that is
        // a fault of the book, said, like one that lost its last line break. No seat acts; a person still does.
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        self::assertArrayNotHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]), 'control: the seat acts');
        $pad = str_repeat('x', 256 * 1024);
        while (filesize($this->house->root . '/' . StandingLedger::PATH) <= StandingLedger::LONGEST) {
            $this->house->writtenByHand(StandingLedger::REFUSED, ['grant' => 'another', 'operation' => 'lab_merge', 'arguments' => ['note' => $pad], 'seat' => LabHouse::STRANGER, 'subject' => null, 'check' => 'conditions', 'reason' => 'a long line, written by hand']);
            clearstatcache();
        }

        self::assertStringContainsString('longer than this house reads', (string) $this->house->ledger()->fault(), 'a book past that length was read whole');
        self::assertStringContainsString('does not verify', (string) ($this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path])['refused'] ?? 'RAN'));
        self::assertSame(1, \count($this->house->done('merged')), 'a seat acted over a book the house does not read');
        // …and nothing is added to it, whoever asks the ledger to: a line more would be read by nobody.
        clearstatcache();
        $bytes = filesize($this->house->root . '/' . StandingLedger::PATH);
        try {
            $this->house->writtenByHand(StandingLedger::SUSPENDED, ['grant' => 'release-line', 'by' => 'key:' . LabHouse::SEAT, 'reason' => 'one line more']);
            self::fail('a line was added to a book the house does not read');
        } catch (\RuntimeException $unwritten) {
            self::assertStringContainsString('longer than this house reads', $unwritten->getMessage());
        }
        clearstatcache();
        self::assertSame($bytes, filesize($this->house->root . '/' . StandingLedger::PATH));
    }

    /** @return array{type: string, at: string, prev: ?string, hash: string, data: array<string, mixed>} */
    private function last(string $type): array
    {
        $of = array_values(array_filter($this->house->everyLine(), static fn (array $entry): bool => $entry['type'] === $type));
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
