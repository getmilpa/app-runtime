<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Standing;

use Milpa\AppRuntime\Agent\HouseWork;
use Milpa\AppRuntime\Agent\JudgedPermission;
use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Standing\HouseStandingJudge;
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\AppRuntime\Tests\Standing\Plugins\Shelf\LabShelf;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Command\Standing\Canonical;
use Milpa\Command\Standing\GrantState;
use Milpa\Console\Events\OperationExecutingEvent;
use Milpa\ToolRuntime\ToolDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a second independent review found in this house's judge of standing grants, each as the review's own probe
 * reproduced it (greenhouse decisions/0615, «Lo que la segunda revisión encontró»).
 *
 * The first review read the code through the door of a terminal. This one went through the door a seat WORKS by — a
 * leg's registry — and asked what happens to authority that was taken back when something else in the house changes.
 */
final class WhatASecondReviewFoundInTheHouseTest extends TestCase
{
    private LabHouse $house;

    private ?LabLeg $leg = null;

    protected function setUp(): void
    {
        $this->house = new LabHouse();
    }

    protected function tearDown(): void
    {
        $this->leg?->destroy();
        $this->house->destroy();
    }

    // ── 1a · what moves authority is never rehearsed in a copy ───────────────────────────────────────────────

    public function testASeatThatStopsAGrantOnALegStopsItInTheHouse(): void
    {
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $leg = $this->leg = new LabLeg($this->house, [$this->house->operation('standing:suspend')]);

        $said = $leg->on->call('standing_suspend', ['grant' => 'release-line', 'reason' => 'the seat saw its own fault'], $leg->seat());

        self::assertTrue($said->success, (string) $said->error);
        self::assertArrayNotHasKey('ran_in_trial', (array) $said->data, 'the suspension was rehearsed in a copy of the house, and the seat was told it worked');
        self::assertCount(1, $this->house->lines(StandingLedger::SUSPENDED), "the book of the HOUSE holds the seat's suspension");
        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state);
        $after = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']);
        self::assertStringContainsString('is suspended', (string) ($after['refused'] ?? 'RAN'));
        self::assertSame([], $this->house->done('published'));
    }

    /** @return iterable<string, array{string}> */
    public static function whatMovesAuthority(): iterable
    {
        yield 'stopping a grant' => ['standing:suspend'];
        yield 'anything of the standing grants' => ['standing:anything'];
        yield 'anything of the identities' => ['identity:anything'];
    }

    #[DataProvider('whatMovesAuthority')]
    public function testWhatMovesAuthorityIsNeitherRehearsedInACopyNorRunAsWork(string $name): void
    {
        // Everything else about it would send it to a trial, or make it work: it mutates, asks no signature, and
        // nothing leaves the house. Only its name says it is one of the house's own acts.
        $declared = static fn (string $called, Subject $subject): Operation => new Operation(
            $called,
            'Lab: an act of the house',
            static fn (): array => ['ok' => true],
            mutating: true,
            scopes: ['lab:house'],
            effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Compensatable, Authority::WriteAsUser, subject: $subject),
        );
        $router = new TrialRouter($this->house->root, new TrialRunner(bwrap: '/nonexistent/bwrap'), __FILE__);

        self::assertTrue($router->eligible($declared('shelf:label', Subject::Configuration)), 'control: the same declaration under a name of the domain IS rehearsed');
        self::assertTrue(HouseWork::declaresWork($declared('shelf:lend', Subject::Data)), 'control: the same declaration under a name of the domain IS work');
        self::assertFalse($router->eligible($declared($name, Subject::Configuration)), "`{$name}` would be rehearsed in a copy of the house");
        self::assertFalse(HouseWork::declaresWork($declared($name, Subject::Data)), "`{$name}` would run as work in the domain");
    }

    // ── 1b · an act a grant covers crosses the judge's boundary on a leg too ─────────────────────────────────

    public function testWorkAGrantCoversIsWrittenDownBeforeItRunsOnALeg(): void
    {
        $leg = $this->shelfLine();

        $said = $leg->on->call('shelf_lend', ['path' => 'good.txt'], $leg->seat());

        self::assertTrue($said->success, (string) $said->error);
        self::assertTrue($said->data['ran_in_house'] ?? false, 'control: this is the path a leg takes for work in the domain');
        $absorbed = $this->house->lines(StandingLedger::ABSORBED);
        self::assertCount(1, $absorbed, 'the house let a covered act pass on a leg and wrote nothing down');
        self::assertSame(['shelf-line', 'shelf.lend', LabHouse::SEAT, $this->house->digestOf('good.txt')], [$absorbed[0]['grant'], $absorbed[0]['operation'], $absorbed[0]['seat'], $absorbed[0]['subject']]);
        self::assertCount(1, $absorbed[0]['receipts']);
        self::assertSame([hash_file('sha256', $this->house->root . '/good.txt') . ' absorbed-when-it-ran=1'], $this->shelfLog(), 'the line was there BEFORE the act ran');
        self::assertSame(GrantState::InForce, $this->house->book()->record('shelf-line')?->state, 'an act that said what it consumed, and consumed what was judged, leaves the grant as it stood');
    }

    public function testWhatTheHouseCannotNoteAfterWorkRanIsNotToldAsNothingRan(): void
    {
        // The act ran, did not say what it consumed, and left the book unwritable: the suspension it earned cannot be
        // written. That is said — and never as «nothing ran», which it did.
        $book = $this->house->root . '/' . StandingLedger::PATH;
        $leg = $this->shelfLine();

        try {
            $said = $leg->on->call('shelf_lend', ['path' => 'good.txt', 'quiet' => true, 'seal' => true], $leg->seat());
        } finally {
            chmod($book, 0o600);
        }

        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('whoever runs as root writes a file it made read-only');
        }
        self::assertCount(1, $this->shelfLog(), 'control: the act ran');
        self::assertFalse($said->success);
        self::assertStringContainsString('could not be opened for writing', (string) $said->error);
        self::assertStringNotContainsString('Nothing ran', (string) $said->error);
    }

    public function testWorkThatDiedWithoutAnAnswerSaysNothingAndTheHouseComparesItsOwnReadings(): void
    {
        // A run that gave no answer at all did not «return without saying what it consumed»: it did not return. The
        // house compares what it read before and after, as it does for a handler that threw, and those agree.
        $leg = $this->shelfLine();

        $said = $leg->on->call('shelf_lend', ['path' => 'good.txt', 'crash' => true], $leg->seat());

        self::assertFalse($said->success, 'control: the run did not succeed');
        self::assertCount(1, $this->shelfLog(), 'control: it had acted before it died');
        self::assertCount(1, $this->house->lines(StandingLedger::ABSORBED), 'and the house had written it down before');
        self::assertSame(GrantState::InForce, $this->house->book()->record('shelf-line')?->state);
    }

    public function testWorkThatDoesNotSayWhatItConsumedSuspendsTheGrantOnALeg(): void
    {
        $leg = $this->shelfLine();

        $leg->on->call('shelf_lend', ['path' => 'good.txt', 'quiet' => true], $leg->seat());

        $record = $this->house->book()->record('shelf-line');
        self::assertSame(GrantState::Suspended, $record?->state);
        self::assertStringContainsString('did not say what it consumed', (string) $record?->because);
    }

    public function testASuspensionThatLandsAfterTheGateStopsWorkOnALeg(): void
    {
        // The gate judged the call with the grant in force; before the work starts, someone stops the grant.
        $stopped = false;
        $leg = $this->shelfLine(function () use (&$stopped): void {
            if (!$stopped) {
                $stopped = true;
                $this->house->as(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'shelf-line', 'reason' => 'stopped between the gate and the act']);
            }
        });

        $said = $leg->on->call('shelf_lend', ['path' => 'good.txt'], $leg->seat());

        self::assertTrue($stopped, 'control: the suspension landed in that moment');
        self::assertFalse($said->success, 'the act ran under a grant that was suspended before it started');
        self::assertStringContainsString('is suspended', (string) $said->error);
        self::assertSame([], $this->shelfLog(), 'nothing ran');
        self::assertSame([], $this->house->lines(StandingLedger::ABSORBED));
    }

    public function testAnActAGrantCoversIsWrittenDownWhenALegRehearsesItInACopy(): void
    {
        $leg = $this->shelfLine();

        $said = $leg->on->call('shelf_label', ['path' => 'good.txt'], $leg->seat());

        self::assertTrue($said->success, (string) $said->error);
        self::assertTrue($said->data['ran_in_trial'] ?? false, 'control: this is the path a leg takes for a mutation that is not work');
        $absorbed = $this->house->lines(StandingLedger::ABSORBED);
        self::assertCount(1, $absorbed, "the house lent a word for a rehearsal and its book says nothing of it");
        self::assertSame(['shelf-line', 'shelf.label'], [$absorbed[0]['grant'], $absorbed[0]['operation']]);
        self::assertSame(GrantState::InForce, $this->house->book()->record('shelf-line')?->state, 'a rehearsal that said what it consumed leaves the grant as it stood');
    }

    public function testARehearsalThatDoesNotSayWhatItConsumedSuspendsTheGrant(): void
    {
        $leg = $this->shelfLine();

        $leg->on->call('shelf_label', ['path' => 'good.txt', 'quiet' => true], $leg->seat());

        self::assertSame(GrantState::Suspended, $this->house->book()->record('shelf-line')?->state);
    }

    public function testASuspensionThatLandsAfterTheGateStopsARehearsalToo(): void
    {
        // The first judgment AFTER the copy of the house was made is the one the run is taken across the judge with:
        // the gate has answered by then. While it reads the book, someone stops the grant.
        $leg = $this->shelfLine();
        $stopped = false;
        $this->house->whileVerifying = function () use (&$stopped): void {
            if (!$stopped && (glob($this->house->root . '/var/trials/*') ?: []) !== []) {
                $stopped = true;
                $this->house->whileVerifying = null;
                $this->house->as(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'shelf-line', 'reason' => 'stopped between the gate and the rehearsal']);
            }
        };

        $said = $leg->on->call('shelf_label', ['path' => 'good.txt'], $leg->seat());

        self::assertTrue($stopped, 'control: the suspension landed in that moment');
        self::assertFalse($said->success);
        self::assertStringContainsString('is suspended', (string) $said->error);
        self::assertSame([], $this->house->lines(StandingLedger::ABSORBED));
        self::assertSame([], glob($this->house->root . '/var/trials/*/copy/labels/log.txt') ?: [], 'nothing ran, not even in the copy');
    }

    public function testWorkIsNotStartedOnALegWhenTheHouseCannotWriteItDown(): void
    {
        // The gate judged the call; then the book cannot be written. Nothing is absorbed that the house cannot record.
        $book = $this->house->root . '/' . StandingLedger::PATH;
        $leg = $this->shelfLine(static function () use ($book): void {
            chmod($book, 0o400);
        });

        try {
            $said = $leg->on->call('shelf_lend', ['path' => 'good.txt'], $leg->seat());
        } finally {
            chmod($book, 0o600);
        }

        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('whoever runs as root writes a file it made read-only');
        }
        self::assertFalse($said->success);
        self::assertStringContainsString('could not be opened for writing', (string) $said->error);
        self::assertStringEndsWith(' Nothing ran.', (string) $said->error);
        self::assertSame([], $this->shelfLog());
    }

    public function testOnALegACallNoGrantHasToDoWithRunsAsItAlwaysDid(): void
    {
        // The no-regression side of the same door. A seat that holds the word on its own, and a grant that names
        // the operation for OTHER arguments: nothing is lent, nothing is judged, nothing is written.
        $plugin = new LabShelf($this->house->root);
        $this->house->offer(...$plugin->operations());
        $this->house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'shelf:write'], LabHouse::PERSON);
        $this->house->granted(['id' => 'shelf-line', 'covers' => [['operation' => 'shelf:lend', 'arguments' => ['path' => 'another.txt']], ['operation' => 'shelf:label', 'arguments' => ['path' => 'another.txt']]]]);
        $this->house->candidate('good.txt', "clean release\n");
        $leg = $this->leg = new LabLeg($this->house, $plugin->operations(), $plugin);
        $lines = \count($this->house->everyLine());

        $work = $leg->on->call('shelf_lend', ['path' => 'good.txt'], $leg->seat());
        $rehearsal = $leg->on->call('shelf_label', ['path' => 'good.txt'], $leg->seat());

        self::assertTrue($work->success && ($work->data['ran_in_house'] ?? false), (string) $work->error);
        self::assertTrue($rehearsal->success && ($rehearsal->data['ran_in_trial'] ?? false), (string) $rehearsal->error);
        self::assertCount($lines, $this->house->everyLine(), 'a call no grant names left a line in the book');
        // …and in a house with no book at all, the leg does not make one.
        $bare = new LabHouse();
        try {
            $bare->offer(...(new LabShelf($bare->root))->operations());
            $bare->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'shelf:write'], LabHouse::PERSON);
            $bare->candidate('good.txt', "clean release\n");
            $shelf = new LabShelf($bare->root);
            $other = new LabLeg($bare, $shelf->operations(), $shelf);
            self::assertTrue($other->on->call('shelf_lend', ['path' => 'good.txt'], $other->seat())->success);
            self::assertFileDoesNotExist($bare->root . '/' . StandingLedger::PATH);
            $other->destroy();
        } finally {
            $bare->destroy();
        }
    }

    public function testWithTrialsOffTheSameActsCrossTheBoundaryAsTheyAlwaysDid(): void
    {
        // The control of the four tests above: the same calls, straight to the house's boundary.
        $leg = $this->shelfLine();

        $said = $leg->off->call('shelf_lend', ['path' => 'good.txt'], $leg->seat());

        self::assertTrue($said->success, (string) $said->error);
        self::assertCount(1, $this->house->lines(StandingLedger::ABSORBED));
        self::assertSame([hash_file('sha256', $this->house->root . '/good.txt') . ' absorbed-when-it-ran=1'], $this->shelfLog());
        $leg->off->call('shelf_lend', ['path' => 'good.txt', 'quiet' => true], $leg->seat());
        self::assertSame(GrantState::Suspended, $this->house->book()->record('shelf-line')?->state);
    }

    // ── 2 · what shrinks never stops applying ────────────────────────────────────────────────────────────────

    public function testAWithdrawalStaysWhenWhoeverSignedItIsNoLongerRecognized(): void
    {
        // A second person: holds the word that recognizes identities, enrolled on the same line.
        $this->house->enroll(LabHouse::STRANGER, ['identity:enroll'], LabHouse::PERSON);
        $this->house->granted();
        $path = $this->measured();
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']), 'control: under the grant the seat publishes');
        self::assertSame(true, $this->house->signed(LabHouse::STRANGER, 'standing:revoke', ['grant' => 'release-line'])['ok'] ?? null);
        self::assertSame(GrantState::Revoked, $this->house->book()->record('release-line')?->state, 'control: it is withdrawn');

        // That second person leaves: an ordinary act on the ledger of identities, which names no grant.
        (new FileEnrollmentStore($this->house->root . '/storage/identity/enrollments.json'))->revoke(LabHouse::STRANGER, 'key:' . LabHouse::PERSON);
        $this->house->as(LabHouse::SEAT, 'lab:watch');

        self::assertNotSame(GrantState::InForce, $this->house->book()->record('release-line')?->state, 'authority that was withdrawn came back with no act that names the grant');
        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']));
        self::assertCount(1, $this->house->done('published'));
    }

    public function testAWithdrawalStaysWhenItsSignatureNoLongerVerifies(): void
    {
        $this->house->enroll(LabHouse::STRANGER, ['identity:enroll'], LabHouse::PERSON);
        $this->house->granted();
        $path = $this->measured();
        $this->house->signed(LabHouse::STRANGER, 'standing:revoke', ['grant' => 'release-line']);
        self::assertSame(GrantState::Revoked, $this->house->book()->record('release-line')?->state, 'control: it is withdrawn');

        // The key that withdrew it expires, is rotated, or leaves the keyring that judges.
        $this->house->revokeKey(LabHouse::STRANGER);
        $this->house->as(LabHouse::SEAT, 'lab:watch');

        self::assertNotSame(GrantState::InForce, $this->house->book()->record('release-line')?->state);
        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']));
        self::assertSame([], $this->house->done('published'));
    }

    public function testAGrantThatWasReplacedDoesNotComeBackWhenItsReplacementIsNoLongerHonoured(): void
    {
        $this->house->granted(); // covers publish(stable), merge and announce, for the seat
        $path = $this->measured();
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]), 'control: under the first grant the seat merges');
        // The person NARROWS: a grant that replaces the first, covers only publish, and lends to a second seat too.
        $narrowed = $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document([
            'id' => 'release-line-2', 'supersedes' => 'release-line', 'to' => [LabHouse::SEAT, LabHouse::OTHER_SEAT],
            'covers' => [['operation' => 'lab:publish', 'arguments' => ['channel' => 'stable']]],
        ])]);
        self::assertSame(true, $narrowed['ok'] ?? null, (string) json_encode($narrowed));
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        self::assertSame(GrantState::Superseded, $this->house->book()->record('release-line')?->state, 'control: the first grant is replaced');
        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]), 'control: the seat no longer merges');

        // An unrelated act on the ledger of identities: the SECOND seat is withdrawn from the house.
        (new FileEnrollmentStore($this->house->root . '/storage/identity/enrollments.json'))->revoke(LabHouse::OTHER_SEAT, 'key:' . LabHouse::PERSON);
        $this->house->as(LabHouse::SEAT, 'lab:watch');

        self::assertNotNull($this->house->book()->record('release-line-2')?->fault, 'control: the grant that replaced it is no longer honoured');
        self::assertNotSame(GrantState::InForce, $this->house->book()->record('release-line')?->state, 'the wider grant came back when someone ELSE lost their recognition');
        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]));
        self::assertCount(1, $this->house->done('merged'));
    }

    public function testAReplacementThatDoesNotHoldIsTheStopThatStandsOnAGrantAlreadyStopped(): void
    {
        // Every stop counts, the latest included: whoever takes a grant's stop out of the way names the one that
        // stands, having seen it — and this one, only with a new grant (decided by Rod, 2026-10-12).
        $this->house->granted();
        $path = $this->measured();
        $first = (string) ($this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'it needs another condition'])['suspension'] ?? '');
        self::assertSame($first, $this->house->book()->record('release-line')?->suspension, 'control: the first stop stands');
        // Someone asks for it to be taken out of the way, with a replacement that does not hold: signed by the seat.
        $forged = $this->house->signature(LabHouse::SEAT, 'standing:grant', ['grant' => $this->house->document(['id' => 'release-line-2', 'supersedes' => 'release-line'])]);
        $this->house->writtenByHand(StandingLedger::GRANTED, ['grant' => 'release-line-2', 'digest' => 'sha256:x', 'payload' => $forged->payload, 'signature' => $forged->signature, 'fingerprint' => LabHouse::SEAT]);

        $record = $this->house->book()->record('release-line');
        self::assertSame(GrantState::Suspended, $record?->state);
        self::assertNotSame($first, $record?->suspension, 'the stop that stands is the latest one');
        self::assertStringContainsString('«release-line-2» names it as replaced', (string) $record?->because);
        // A signature that names the FIRST stop was made before this one was there: it lifts nothing.
        $old = $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $first]);
        self::assertArrayNotHasKey('ok', array_filter($old, static fn (mixed $value): bool => $value === true), (string) json_encode($old));
        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state);
        // One that names the stop that stands lifts nothing either: it is not a suspension.
        self::assertStringContainsString('a reinstatement does not lift that', (string) $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => (string) $record?->suspension])['error']);
        // A new grant that names the FIRST stop does not take it out of the way; one that names the stop that stands does.
        self::assertStringContainsString('is stopped (' . $record?->suspension . ')', (string) $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document(['id' => 'release-line-3', 'supersedes' => 'release-line', 'lifts' => $first])])['error']);
        $this->house->granted(['id' => 'release-line-3', 'supersedes' => 'release-line', 'lifts' => (string) $record?->suspension]);
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']));
    }

    // ── 3 · what the chain is worth against whoever writes the file ──────────────────────────────────────────

    public function testABookRewrittenByWhoeverHoldsTheFileIsNotReadAsIfNothingHappened(): void
    {
        $this->house->granted();
        $bad = $this->house->candidate('bad.txt', "machine-name: build-host-7\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $bad]);
        $this->house->signed(LabHouse::PERSON, 'standing:revoke', ['grant' => 'release-line']);
        self::assertSame(GrantState::Revoked, $this->house->book()->record('release-line')?->state, 'control: it is withdrawn');

        // No key, only the file: keep the person's signed grant byte for byte, drop everything after it — the red
        // receipt and the withdrawal — and append a watcher's sign and a green receipt with their hashes recomputed.
        $file = $this->house->root . '/' . StandingLedger::PATH;
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));
        $first = json_decode($lines[0], true);
        $at = $this->house->now->format(\DATE_ATOM);
        $forge = static function (string $type, array $data, string $prev) use ($at): array {
            $entry = ['type' => $type, 'at' => $at, 'prev' => $prev, 'data' => $data];

            return $entry + ['hash' => StandingLedger::hashOf($entry)];
        };
        $watched = $forge(StandingLedger::WATCHED, ['watcher' => 'lab.watch', 'arguments' => [], 'fired' => false, 'who' => 'nobody'], $first['hash']);
        $measured = $forge(StandingLedger::MEASURED, ['by' => 'lab.scan', 'arguments' => ['path' => 'bad.txt'], 'subject' => $this->house->digestOf('bad.txt'), 'ok' => true, 'instrument' => $this->house->operation('lab:scan')->instrumentDigest(), 'who' => 'nobody', 'at' => $at], $watched['hash']);
        file_put_contents($file, $lines[0] . "\n" . Canonical::json($watched) . "\n" . Canonical::json($measured) . "\n");

        self::assertNotNull($this->house->ledger()->fault(), 'the rewritten book verifies');
        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $bad, 'channel' => 'stable']), 'the seat published a red candidate on a withdrawn grant');
        self::assertSame([], $this->house->done('published'));
    }

    /** @return iterable<string, array{\Closure(string): void}> */
    public static function howABookIsLost(): iterable
    {
        yield 'emptied to zero bytes' => [static fn (string $file) => file_put_contents($file, '')];
        yield 'deleted' => [static fn (string $file) => unlink($file)];
    }

    /** @param \Closure(string): void $lose */
    #[DataProvider('howABookIsLost')]
    public function testABookThatIsGoneAfterItExistedIsNotAHouseWhereNobodyGranted(\Closure $lose): void
    {
        // The seat holds the word on its own, so only the book stands between it and the act.
        $this->house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'lab:publish'], LabHouse::PERSON);
        $this->house->granted();
        $bad = $this->house->candidate('bad.txt', "machine-name: build-host-7\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $bad]);
        $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'stop']);
        self::assertStringContainsString('is suspended', (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $bad, 'channel' => 'stable'])['refused'] ?? 'RAN'), 'control: governed although it holds the word');

        $lose($this->house->root . '/' . StandingLedger::PATH);

        self::assertNotNull($this->house->ledger()->fault(), 'a book that is gone reads as a house where nobody granted anything');
        self::assertStringContainsString('does not verify', (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $bad, 'channel' => 'stable'])['refused'] ?? 'RAN'));
        self::assertSame([], $this->house->done('published'));
    }

    // ── 4 · the instrument and the watcher are the ones the grant was given over ─────────────────────────────

    public function testAnInstrumentReplacedAfterTheGrantDoesNotMeasureForIt(): void
    {
        $this->house->granted();
        $bad = $this->house->candidate('bad.txt', "machine-name: build-host-7\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $bad]);
        self::assertStringContainsString('does not hold on this candidate', (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $bad, 'channel' => 'stable'])['refused'] ?? 'RAN'), 'control: the real instrument measures it red');

        // The same name and the same declaration, another handler: it finds nothing, ever.
        $old = $this->house->operation('lab:scan');
        $root = $this->house->root;
        $this->house->offer(new Operation('lab:scan', $old->description, static fn (array $input): array => ['ok' => true, 'acted_on' => 'sha256:' . hash_file('sha256', $root . '/' . $input['path'])], $old->inputSchema, scopes: $old->scopes, effects: EffectProfile::readOnly(), actsOn: $old->actsOn));
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $bad]);

        $after = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $bad, 'channel' => 'stable']);
        self::assertArrayHasKey('refused', $after, 'a softer instrument under the same name measured the red candidate green');
        self::assertSame([], $this->house->done('published'));
    }

    public function testAWatcherReplacedAfterTheGrantDoesNotWatchForIt(): void
    {
        $this->house->granted();
        $path = $this->measured();
        // The falsifier fires — and the watcher is replaced by one that never sees it.
        $this->house->fired = true;
        $old = $this->house->operation('lab:watch');
        $this->house->offer(new Operation('lab:watch', $old->description, static fn (array $input): array => ['ok' => true, 'fired' => false], scopes: $old->scopes, effects: EffectProfile::readOnly()));
        $this->house->as(LabHouse::SEAT, 'lab:watch');

        $after = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']);
        self::assertArrayHasKey('refused', $after, 'a watcher that cannot see the falsifier kept the grant alive');
        self::assertSame([], $this->house->done('published'));
    }

    public function testAGrantIsNotGivenOverAWatcherTheHouseCannotRead(): void
    {
        // A watcher has to be pinned like everything else a grant is given over; one whose handler is not written
        // anywhere the house can read could be replaced under its name and nobody would know.
        $this->house->offer(new Operation('lab:unreadable', 'Lab: a watcher whose handler is not written in a file', 'array_values', scopes: ['lab:watch'], effects: EffectProfile::readOnly()));

        $given = $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document(['falsifier' => ['name' => 'f', 'statement' => 's', 'watcher' => ['operation' => 'lab:unreadable'], 'within_seconds' => 60]])]);

        self::assertStringContainsString('which this house cannot pin: its handler cannot be read', (string) ($given['error'] ?? 'GIVEN'));
        self::assertFileDoesNotExist($this->house->root . '/' . StandingLedger::PATH);
    }

    public function testAHandlerThatDelegatesIsNotTheSameOperationWhenWhatItCallsChanges(): void
    {
        // The idiom of every provider: the handler is one line that calls a method of its class. The line does not
        // change when the method does.
        $path = ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']];
        $root = $this->house->root;
        $declared = static fn (\Closure $handler): Operation => new Operation('lab:deliver', 'Lab: deliver a candidate', $handler, $path, mutating: true, requiresConfirmation: true, scopes: ['lab:deliver'], effects: new EffectProfile(Mutation::Persistent, Externality::Public, Reversibility::Compensatable, Authority::WriteAsUser, subject: Subject::Executable), actsOn: static fn (array $input): ?string => is_file($root . '/' . ($input['path'] ?? '')) ? 'sha256:' . hash_file('sha256', $root . '/' . $input['path']) : null);
        $asGranted = new LabDeliveryAsGranted($root);
        $replaced = new LabDeliveryReplaced($root);
        self::assertSame($declared($asGranted->handler())->instrumentDigest(), $declared($replaced->handler())->instrumentDigest(), 'control: the contract reads the two as one operation');

        $this->house->offer($declared($asGranted->handler()));
        $this->house->granted(['covers' => [['operation' => 'lab:deliver']]]);
        $candidate = $this->measured();
        self::assertSame(true, $this->house->as(LabHouse::SEAT, 'lab:deliver', ['path' => $candidate])['ok'] ?? null, 'control: as granted, it runs');
        $this->house->offer($declared($replaced->handler()));

        $after = $this->house->as(LabHouse::SEAT, 'lab:deliver', ['path' => $candidate]);
        self::assertStringContainsString('is not the operation it was when this grant was given', (string) ($after['refused'] ?? 'RAN'));
        self::assertSame(['delivered ' . $candidate], @file($root . '/var/lab-delivery.log', \FILE_IGNORE_NEW_LINES) ?: []);
    }

    public function testAGrantWhoseLineDoesNotSayWhatItIsMeasuredByIsNotHonoured(): void
    {
        // A line that carries no pin of its instruments — written before the house kept them, or by hand without
        // them — cannot be told from one whose instruments were replaced.
        $this->house->grantedByHand(line: ['instruments' => []]);
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $path = $this->measured();

        $refused = (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable'])['refused'] ?? 'RAN');

        self::assertStringContainsString('`lab:scan`, which measures condition «no-host-names», is not the operation it was when this grant was given', $refused);
        self::assertSame([], $this->house->done('published'));
    }

    public function testAGrantWhoseInstrumentOrWatcherIsGoneFromTheHouseIsNotHonoured(): void
    {
        $this->house->granted();
        $path = $this->measured();
        $seat = $this->house->contextOf(LabHouse::SEAT);
        $publish = $this->house->operation('lab:publish');
        $judge = $this->house->policy->standing();
        $without = fn (string $gone): \Closure => fn (\Milpa\Command\Consent\OperationId|string $named): ?Operation => (string) ($named instanceof \Milpa\Command\Consent\OperationId ? $named->canonical : $named) === $gone ? null : $this->house->policy->operationNamed($named);
        $input = ['path' => $path, 'channel' => 'stable'];

        self::assertTrue($judge->decide($seat, $publish, $input, $this->house->policy->operationNamed(...))?->passes(), 'control: with the catalogue whole, it passes');
        self::assertStringContainsString('`lab:scan`, which measures condition «no-host-names», is not in this house any more', (string) $judge->decide($seat, $publish, $input, $without('lab.scan'))?->refusal());
        self::assertStringContainsString('`lab:watch`, which watches falsifier «a-published-name», is not in this house any more', (string) $judge->decide($seat, $publish, $input, $without('lab.watch'))?->refusal());
        self::assertSame([], $judge->offers($seat, $publish, $without('lab.watch')), 'and nothing is offered on it for the asking');
        self::assertSame(['lab:publish'], $judge->offers($seat, $publish, $this->house->policy->operationNamed(...)), 'control: whole, the word is offered');
    }

    // ── 5 · a book that does not verify is asked before anything else ────────────────────────────────────────

    public function testABookThatDoesNotVerifyStopsASeatFromPromotingATrial(): void
    {
        $this->house->granted();
        $file = $this->house->root . '/' . StandingLedger::PATH;
        @mkdir($this->house->root . '/var/trials', 0o755, true);
        TrialWorkspace::materialize($this->house->root, 'wprobe', __FILE__);
        $seat = $this->house->contextOf(LabHouse::SEAT);
        $promote = static fn (string $tool): ToolDefinition => new ToolDefinition($tool, 'x', [], static fn (): array => [], mutating: true);
        self::assertTrue($this->house->policy->authorize($seat, $promote('sandbox_promote'), ['workspace' => 'wprobe'])->allowed, 'control: with the book whole, this policy lets the call through');

        file_put_contents($file, substr_replace((string) file_get_contents($file), 'X', 60, 1));
        self::assertNotNull($this->house->ledger()->fault(), 'control: the book does not verify');

        foreach (['sandbox_promote', 'sandbox_undo'] as $tool) {
            $verdict = $this->house->policy->authorize($seat, $promote($tool), ['workspace' => 'wprobe']);
            self::assertFalse($verdict->allowed, "`{$tool}` was let through for a seat while the book does not verify");
            self::assertStringContainsString('does not verify', (string) $verdict->reason);
        }
        $ran = false;
        try {
            $this->house->policy->execute(new Operation('sandbox:promote', 'x', static fn (array $input): array => ['ok' => true], ['type' => 'object'], mutating: true), ['workspace' => 'wprobe'], $seat, static function () use (&$ran): array {
                $ran = true;

                return ['ok' => true];
            });
        } catch (\RuntimeException) {
        }
        self::assertFalse($ran, 'the handler of sandbox:promote ran for a seat while the book does not verify');
    }

    public function testABookThatDoesNotVerifyStopsASeatOnARunTheHttpPolicyAdmitted(): void
    {
        // An operation typed by `permission` that the host's HTTP policy admitted reaches the boundary already judged
        // for its permission. Whether the book verifies is another question, and it is asked all the same.
        $this->house->granted();
        $judged = new JudgedPermission();
        $policy = new PluginAuthoringPolicy($this->house->root, judged: $judged, standing: new HouseStandingJudge($this->house->root, $this->house, fn (): \DateTimeImmutable => $this->house->now));
        $operation = new Operation('reports:export', 'Lab: an operation typed by permission', static fn (array $input): array => ['ok' => true], ['type' => 'object'], mutating: true, permission: 'reports.export');
        $seat = $this->house->contextOf(LabHouse::SEAT);
        $why = null;
        $admitted = static function () use ($policy, $judged, $operation, $seat, &$why): bool {
            $judged->executing('console.executing', ['event' => new OperationExecutingEvent($operation, [], 'http')]);
            $ran = false;
            try {
                $policy->execute($operation, [], $seat, static function () use (&$ran): array {
                    $ran = true;

                    return ['ok' => true];
                });
            } catch (\RuntimeException $refused) {
                $why = $refused->getMessage();
            }

            return $ran;
        };
        self::assertTrue($admitted(), 'control: with the book whole, the run the HTTP policy admitted goes through');

        $file = $this->house->root . '/' . StandingLedger::PATH;
        file_put_contents($file, substr_replace((string) file_get_contents($file), 'X', 60, 1));

        self::assertFalse($admitted(), 'a run the HTTP policy admitted went through for a seat while the book does not verify');
        // …and for THAT reason. Since the fifth review that door also refuses a call a grant names, and a book that
        // does not verify answers for every seat: two checks stop this run, and only the reason tells which one did.
        self::assertStringContainsString('does not verify', (string) $why, 'the seat was refused, and not told that the book does not verify');
    }

    public function testABookThatDoesNotVerifyStillStopsNoPersonAndNobodyWhoIsNotASeat(): void
    {
        $this->house->granted();
        $file = $this->house->root . '/' . StandingLedger::PATH;
        file_put_contents($file, substr_replace((string) file_get_contents($file), 'X', 60, 1));
        $judge = $this->house->policy->standing();

        self::assertStringContainsString('does not verify', (string) $judge->stops($this->house->contextOf(LabHouse::SEAT)));
        self::assertStringContainsString('Nothing runs for a seat until a person has looked at it', (string) $judge->stops($this->house->contextOf(LabHouse::OTHER_SEAT)));
        self::assertNull($judge->stops($this->house->contextOf(LabHouse::PERSON)), 'a person acting for themselves: stopping them would close the way to repair it');
        self::assertNull($judge->stops($this->house->contextOf(null)), "the terminal's own operator is no seat");
        self::assertNull($judge->stops(new \Milpa\ToolRuntime\Contracts\ToolContext(principal: 'passkey:someone', channel: 'web', scopes: ['lab:scan'])), 'nor is whoever is not a key');
        // And a book that verifies stops nobody; neither does a house that keeps none.
        $whole = new LabHouse();
        try {
            self::assertNull($whole->policy->standing()->stops($whole->contextOf(LabHouse::SEAT)));
            $whole->granted();
            self::assertNull($whole->policy->standing()->stops($whole->contextOf(LabHouse::SEAT)));
        } finally {
            $whole->destroy();
        }
    }

    // ── 7 · the boundary asks for the word again ─────────────────────────────────────────────────────────────

    public function testTheBoundaryAsksForTheWordAgainWhenTheCallerItIsHandedCarriedALoan(): void
    {
        $this->house->granted();
        $good = $this->measured();
        $bad = $this->house->candidate('bad.txt', "machine-name: build-host-7\n");
        $seat = $this->house->contextOf(LabHouse::SEAT);
        $operation = $this->house->operation('lab:publish');
        // The gate was asked about the call the grant covers…
        $seen = $this->house->policy->contextFor($seat, 'lab_publish', ['path' => $good, 'channel' => 'stable']);
        self::assertContains('lab:publish', $seen->scopes, 'control: the word was lent for that call');

        // …and the boundary is handed that context with a call NO grant names: another channel, an unmeasured candidate.
        $input = ['path' => $bad, 'channel' => 'beta'];
        $refused = null;
        try {
            $this->house->policy->execute($operation, $input, $seen, fn (): mixed => ($operation->handler)($input, null, $seen));
        } catch (\RuntimeException $error) {
            $refused = $error->getMessage();
        }

        self::assertStringContainsString('Missing required scope', (string) $refused, 'a word lent for one call opened the boundary for another');
        self::assertSame([], $this->house->done('published'));

        // The control: handed the same context with the call the grant DOES cover, the boundary lets it pass.
        $covered = ['path' => $good, 'channel' => 'stable'];
        $this->house->policy->execute($operation, $covered, $seen, fn (): mixed => ($operation->handler)($covered, null, $seen));
        self::assertCount(1, $this->house->done('published'));
    }

    public function testWithNoLoanInWhatItIsHandedTheBoundaryAsksForNoWordAsBefore(): void
    {
        // WHAT THIS DOES NOT CHANGE, pinned: the boundary never asked for a word of a caller that carries no loan —
        // the doors do, before it. A caller handed to it bare, for a call no grant names, runs as it did in the
        // published house.
        $this->house->granted();
        $bad = $this->house->candidate('bad.txt', "machine-name: build-host-7\n");
        $seat = $this->house->contextOf(LabHouse::SEAT);
        $operation = $this->house->operation('lab:publish');
        $input = ['path' => $bad, 'channel' => 'beta'];

        $this->house->policy->execute($operation, $input, $seat, fn (): mixed => ($operation->handler)($input, null, $seat));

        self::assertCount(1, $this->house->done('published'));
    }

    // ── 8 · what the book costs and how it is kept ───────────────────────────────────────────────────────────

    public function testTheBookIsReadableOnlyByWhoeverRunsTheHouse(): void
    {
        $umask = umask(0o022);
        try {
            $this->house->granted();
        } finally {
            umask($umask);
        }
        clearstatcache();

        self::assertSame('600', decoct(fileperms($this->house->root . '/' . StandingLedger::PATH) & 0o777), 'the book keeps the arguments of calls, and every user of the machine can read it');
    }

    public function testASeatThatRepeatsARefusedCallDoesNotGrowTheBook(): void
    {
        $this->house->granted();
        $unmeasured = $this->house->candidate('new.txt', "nobody measured this\n");
        $call = fn (): array => $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $unmeasured, 'channel' => 'stable']);
        self::assertStringContainsString('was never measured', (string) ($call()['refused'] ?? 'RAN'), 'control: the call is refused, and written down');
        $written = \count($this->house->lines(StandingLedger::REFUSED));
        self::assertSame(1, $written);

        for ($again = 0; $again < 40; ++$again) {
            self::assertArrayHasKey('refused', $call());
        }

        self::assertCount($written, $this->house->lines(StandingLedger::REFUSED), 'the same refusal, repeated, added a line each time');
    }

    public function testTheBookKeepsSoManyRefusalsInARowAndNoMore(): void
    {
        $this->house->granted();
        $refuse = function (int $which): void {
            $path = $this->house->candidate("new-{$which}.txt", "nobody measured candidate {$which}\n");
            self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable']));
        };

        // Two refusals, asked again in turn: each is written once.
        foreach ([1, 2, 1, 2, 1, 2] as $which) {
            $refuse($which);
        }
        self::assertCount(2, $this->house->lines(StandingLedger::REFUSED));

        // A refusal of every kind there is to ask, without end: the book keeps so many in a row.
        for ($which = 3; $which <= HouseStandingJudge::REFUSALS_KEPT + 20; ++$which) {
            $refuse($which);
        }
        self::assertCount(HouseStandingJudge::REFUSALS_KEPT, $this->house->lines(StandingLedger::REFUSED));

        // What ends that count is the grant's standing changing — it was «anything else the house writes», and a
        // seat wrote its refusals again for each line written for somebody else (the fifth review, 5-M2; a proposal,
        // not decided). A line of another kind does not start it again…
        $this->house->later(3600);
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $refuse(1);
        self::assertCount(HouseStandingJudge::REFUSALS_KEPT, $this->house->lines(StandingLedger::REFUSED));
        // …and a stop does: what is refused after it is a new fact.
        $this->house->signed(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen']);
        $refuse(1);
        self::assertCount(HouseStandingJudge::REFUSALS_KEPT + 1, $this->house->lines(StandingLedger::REFUSED));
        $refuse(1);
        self::assertCount(HouseStandingJudge::REFUSALS_KEPT + 1, $this->house->lines(StandingLedger::REFUSED));
    }

    private function measured(string $name = 'good.txt', string $bytes = "clean release\n"): string
    {
        $path = $this->house->candidate($name, $bytes);
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);

        return $path;
    }

    /** A grant over the two acts of the shelf, a measured candidate, and the leg a seat works by. */
    private function shelfLine(?\Closure $between = null): LabLeg
    {
        $plugin = new LabShelf($this->house->root);
        $this->house->offer(...$plugin->operations());
        $this->house->granted(['id' => 'shelf-line', 'covers' => [['operation' => 'shelf:lend'], ['operation' => 'shelf:label']]]);
        $this->measured();

        return $this->leg = new LabLeg($this->house, $plugin->operations(), $plugin, $between);
    }

    /** @return list<string> */
    private function shelfLog(): array
    {
        return @file($this->house->root . '/storage/shelf/log.txt', \FILE_IGNORE_NEW_LINES) ?: [];
    }
}
