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

use Milpa\AppRuntime\Standing\HouseStandingJudge;
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * Before a seat's act, the house reads its book of standing grants at that moment and refuses or lets pass
 * (greenhouse decisions/0613 stage 3, decisions/0615).
 *
 * The case behind it is real: a condition of a mandate was measured AFTER the act it guarded, on a package that
 * failed it. Here the act does not run until the condition was measured on that very candidate — by content, not
 * by clock.
 *
 * @guards a seat with no grant refused exactly as before, and nothing written; an act that runs only with the
 *         grant in force, its watcher alive, its effect under the ceiling and every condition measured green on the
 *         digest of that candidate by today's instrument; each act written down before it runs, with the receipts
 *         it ran on; each refusal of a named call written down; a grant that governs even a seat that holds the
 *         scope on its own
 *
 * @refuses an act on a candidate nobody measured, measured red, measured as another candidate, changed by one byte,
 *          or measured by an instrument that has changed since; an act above the ceiling with every condition
 *          green; a call whose arguments the grant does not name, with the lent word taken back; a measurement
 *          taken after the act counting for the act that was refused
 *
 * @subject-in milpa/app-runtime
 */
final class TheHouseJudgesAStandingGrantTest extends TestCase
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

    public function testWithNoGrantASeatIsRefusedByTheWordItLacksAndThePersonActs(): void
    {
        $candidate = $this->house->candidate('a.tar', 'candidate a');

        $refused = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $candidate, 'channel' => 'stable']);
        self::assertSame("Missing required scope for tool 'lab_publish'. Need one of: lab:publish — context has: agent:run, lab:scan, lab:watch.", $refused['refused']);
        self::assertSame(['ok' => true], $this->house->as(LabHouse::PERSON, 'lab:publish', ['path' => $candidate, 'channel' => 'stable']));

        // And a house where nobody granted anything keeps no book: measuring and watching leave no trace either.
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        self::assertFileDoesNotExist($this->house->root . '/' . StandingLedger::PATH);
    }

    public function testACandidateNobodyMeasuredIsRefusedAndMeasuringItAfterwardsDoesNotMakeThatActRun(): void
    {
        $this->house->granted();
        $candidate = $this->house->candidate('b.tar', 'candidate b');

        $refused = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $candidate, 'channel' => 'stable']);
        self::assertStringContainsString('condition "no-host-names" was never measured on this candidate ("' . $this->house->digestOf($candidate) . '")', $refused['refused']);
        self::assertSame([], $this->house->done('published'));

        // Measured AFTER: the refused act stays refused in the book, and nothing ran because of the measurement.
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]));
        self::assertSame([], $this->house->done('published'), 'a measurement runs no act');
        self::assertSame('not_measured', $this->house->lines(StandingLedger::REFUSED)[0]['reason']);

        // What runs is the NEXT act — because now a receipt on this digest exists before it.
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $candidate, 'channel' => 'stable']));
        self::assertCount(1, $this->house->done('published'));
    }

    public function testAnActThatPassesIsWrittenDownBeforeItRunsWithTheReceiptsItRanOn(): void
    {
        $this->house->granted();
        $candidate = $this->house->candidate('a.tar', 'candidate a');
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);
        $seenByTheHandler = null;
        $this->house->whilePublishing = function () use (&$seenByTheHandler): void {
            $seenByTheHandler = $this->house->lines(StandingLedger::ABSORBED);
        };

        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $candidate, 'channel' => 'stable']));

        $absorbed = $this->house->lines(StandingLedger::ABSORBED);
        self::assertCount(1, $absorbed);
        self::assertSame($absorbed, $seenByTheHandler, 'the line was there while the act ran');
        self::assertSame('release-line', $absorbed[0]['grant']);
        self::assertSame('lab.publish', $absorbed[0]['operation']);
        self::assertSame(LabHouse::SEAT, $absorbed[0]['seat']);
        self::assertSame($this->house->digestOf($candidate), $absorbed[0]['subject']);
        self::assertSame('withdraw the release and publish the one before it', $absorbed[0]['undo']);
        self::assertCount(1, $absorbed[0]['receipts']);
        self::assertSame($this->house->digestOf($candidate), $absorbed[0]['receipts'][0]['subject']);
        self::assertSame('lab.scan', $absorbed[0]['receipts'][0]['by']);
        self::assertTrue($absorbed[0]['receipts'][0]['ok']);
        self::assertSame('key:' . LabHouse::SEAT, $absorbed[0]['receipts'][0]['who']);
        self::assertSame([], $this->house->lines(StandingLedger::SUSPENDED));
    }

    public function testAReceiptOnAnotherCandidateOrOneByteApartIsNotThisOne(): void
    {
        $this->house->granted();
        $measured = $this->house->candidate('a.tar', 'candidate a');
        $other = $this->house->candidate('c.tar', 'candidate c');
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $measured]);

        self::assertStringContainsString('never measured on this candidate', $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $other, 'channel' => 'stable'])['refused']);

        // The same name, one byte more: the receipt was for the bytes, not for the path.
        $this->house->candidate('a.tar', 'candidate a.');
        self::assertStringContainsString('never measured on this candidate', $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $measured, 'channel' => 'stable'])['refused']);
        self::assertSame([], $this->house->done('published'));
    }

    public function testACandidateMeasuredRedIsRefused(): void
    {
        $this->house->granted();
        $candidate = $this->house->candidate('e.tar', "candidate e\nmachine-name: somewhere\n");

        self::assertSame(['ok' => false], $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]));
        self::assertStringContainsString('does not hold on this candidate', $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $candidate, 'channel' => 'stable'])['refused']);
        self::assertSame([], $this->house->done('published'));
    }

    public function testWhatAnInstrumentFoundBeforeItChangedDoesNotCount(): void
    {
        $this->house->granted();
        $candidate = $this->house->candidate('a.tar', "candidate a\nhost: somewhere\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);

        $asGranted = $this->house->operation('lab:scan');
        $this->house->hardenScanner();

        // THE GRANT WAS GIVEN OVER THE INSTRUMENT AS IT WAS (decisions/0615, «Lo que la segunda revisión encontró», 4):
        // with another under its name — a stricter one here; a softer one would look the same from outside — the
        // grant is not honoured, and what the first one found does not count for what is there now.
        $refused = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $candidate, 'channel' => 'stable']);
        self::assertStringContainsString('`lab:scan`, which measures condition «no-host-names», is not the operation it was when this grant was given', $refused['refused']);
        // The instrument as it is today finds what the old one did not see…
        self::assertSame(['ok' => false], $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]));
        // …and a person who grants again, over what is there now, has it read: the candidate is red.
        $this->house->granted(['id' => 'release-line-2', 'supersedes' => 'release-line']);
        self::assertStringContainsString('does not hold on this candidate', $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $candidate, 'channel' => 'stable'])['refused']);

        // The other way round: the instrument the first grant was given over is put back. That grant is honoured again
        // — and what another instrument measured meanwhile is not a measurement of this one.
        $this->house->offer($asGranted);
        $clean = $this->house->candidate('b.tar', "candidate b\n");
        $this->house->hardenScanner();
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $clean]);
        $this->house->offer($asGranted);
        $this->house->signed(LabHouse::PERSON, 'standing:revoke', ['grant' => 'release-line-2']);
        $first = $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document(['id' => 'release-line-3'])]);
        self::assertTrue($first['ok'] ?? false, (string) ($first['error'] ?? ''));
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        self::assertStringContainsString('it is not that instrument any more', $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $clean, 'channel' => 'stable'])['refused']);
    }

    public function testAnEffectAboveTheCeilingIsRefusedWithEveryConditionGreen(): void
    {
        $this->house->granted();
        $candidate = $this->house->candidate('a.tar', 'candidate a');
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);

        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate]), 'control: the same candidate passes under the ceiling');
        $refused = $this->house->as(LabHouse::SEAT, 'lab:announce', ['path' => $candidate]);
        self::assertStringContainsString('is wider than the grant\'s ceiling', $refused['refused']);
        self::assertStringContainsString('reversibility «irreversible»', $refused['refused']);
        self::assertSame([], $this->house->done('announced'));
        self::assertSame('above_ceiling', $this->house->lines(StandingLedger::REFUSED)[0]['reason']);
    }

    public function testACallTheGrantDoesNotNameIsJudgedAsBeforeWithTheLentWordTakenBack(): void
    {
        $this->house->granted();
        $candidate = $this->house->candidate('a.tar', 'candidate a');
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);

        // The grant fixes channel=stable. Another channel is not its call: the seat is refused by the word it lacks,
        // in the gate's own sentence, and the word it was handed for the asking is not in what the sentence shows.
        $refused = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $candidate, 'channel' => 'beta']);
        self::assertSame("Missing required scope for tool 'lab_publish'. Need one of: lab:publish — context has: agent:run, lab:scan, lab:watch.", $refused['refused']);
        self::assertSame([], $this->house->lines(StandingLedger::REFUSED), 'what no grant names is not this book\'s refusal');
        self::assertSame([], $this->house->done('published'));
    }

    public function testAGrantGovernsEvenASeatThatHoldsTheWordOnItsOwn(): void
    {
        $this->house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'lab:publish'], LabHouse::PERSON);
        $candidate = $this->house->candidate('a.tar', 'candidate a');
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $candidate, 'channel' => 'stable']), 'control: with the word and no grant, nothing is asked');

        $this->house->granted();

        // A condition that could be walked around with a word given separately would not be a condition.
        $unmeasured = $this->house->candidate('b.tar', 'candidate b');
        self::assertStringContainsString('never measured on this candidate', $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $unmeasured, 'channel' => 'stable'])['refused']);
        // What the grant does not name stays the seat's own, as before.
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $unmeasured, 'channel' => 'beta']));
    }

    public function testOnlyTheSeatsAGrantNamesAreLentAnything(): void
    {
        $this->house->granted();
        $candidate = $this->house->candidate('a.tar', 'candidate a');
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);

        $refused = $this->house->as(LabHouse::OTHER_SEAT, 'lab:publish', ['path' => $candidate, 'channel' => 'stable']);
        self::assertStringContainsString('Missing required scope', $refused['refused']);
        self::assertStringContainsString('context has: agent:run.', $refused['refused']);

        // Not even for the asking: the other seat is handed nothing — for the tool or for the call — and the named
        // one the operation's word, marked with the tool it was lent for.
        $call = ['path' => $candidate, 'channel' => 'stable'];
        $other = new ToolContext(principal: 'key:' . LabHouse::OTHER_SEAT, channel: 'cli', scopes: ['agent:run']);
        $named = new ToolContext(principal: 'key:' . LabHouse::SEAT, channel: 'cli', scopes: LabHouse::SEAT_SCOPES);
        self::assertSame($other, $this->house->policy->contextFor($other, 'lab_publish'));
        self::assertSame($other, $this->house->policy->contextFor($other, 'lab_publish', $call));
        self::assertSame(['tool' => 'lab_publish', 'scopes' => ['lab:publish']], $this->house->policy->contextFor($named, 'lab_publish', $call)->extra[HouseStandingJudge::LENT]);
        self::assertSame($named, $this->house->policy->contextFor($named, 'lab_publish', ['channel' => 'beta'] + $call), 'a call the grant does not name is handed nothing');
        self::assertTrue($this->house->policy->contextFor($named, 'lab_publish')->hasScope('lab:publish'), 'asked only whether the tool is offered, it is');
    }

    public function testTheTerminalAndAKeyNoGrantNamesAreNotJudged(): void
    {
        $this->house->granted();
        $candidate = $this->house->candidate('b.tar', 'candidate b');

        // The terminal's own operator, and a key nobody recognized signing at it: sovereign in this house (measured,
        // greenhouse decisions/0615). No grant can name them, so the judge says nothing of them.
        self::assertSame(['ok' => true], $this->house->as(null, 'lab:publish', ['path' => $candidate, 'channel' => 'stable']));
        self::assertSame(['ok' => true], $this->house->as(LabHouse::STRANGER, 'lab:publish', ['path' => $candidate, 'channel' => 'stable']));
        self::assertSame([], $this->house->lines(StandingLedger::ABSORBED));
        self::assertSame([], $this->house->lines(StandingLedger::REFUSED));
        self::assertNull(HouseStandingJudge::seatOf(ToolContext::cli()));
        self::assertNull(HouseStandingJudge::seatOf(new ToolContext(principal: 'passkey:abc', scopes: ['lab:scan'])));
        self::assertNull(HouseStandingJudge::seatOf(new ToolContext(principal: 'key:', scopes: ['lab:scan'])));
        self::assertSame(LabHouse::SEAT, HouseStandingJudge::seatOf(new ToolContext(principal: 'key:' . strtolower(LabHouse::SEAT), scopes: ['lab:scan'])));
    }

    public function testASeatAGrantNamesIsGovernedEvenHoldingEveryScope(): void
    {
        // The widest form of «it already holds the word»: every word. The grant still governs what it names.
        $this->house->enroll(LabHouse::SEAT, ['*'], LabHouse::PERSON);
        $candidate = $this->house->candidate('b.tar', 'candidate b');
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $candidate, 'channel' => 'stable']), 'control: with every scope and no grant, nothing is asked');

        $this->house->granted();
        $unmeasured = $this->house->candidate('c.tar', 'candidate c');

        self::assertStringContainsString('never measured on this candidate', $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $unmeasured, 'channel' => 'stable'])['refused']);
        // Measured, it runs — and it is written down as an act absorbed under the grant, like any other.
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $unmeasured]);
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $unmeasured, 'channel' => 'stable']));
        self::assertCount(1, $this->house->lines(StandingLedger::ABSORBED));
        // What the grant does not name stays the seat's own, and nothing is handed to who lacks nothing.
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $unmeasured, 'channel' => 'beta']));
        $whole = new ToolContext(principal: 'key:' . LabHouse::SEAT, channel: 'cli', scopes: ['*']);
        self::assertSame($whole, $this->house->policy->contextFor($whole, 'lab_publish'));
    }

    public function testIfWhatAnActTouchedIsNotWhatWasJudgedTheGrantIsSuspended(): void
    {
        $this->house->granted();
        $candidate = $this->house->candidate('a.tar', 'candidate a');
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);
        $judged = $this->house->digestOf($candidate);
        $this->house->whilePublishing = fn () => $this->house->candidate('a.tar', 'swapped under the act');

        // The swap is detected, not prevented: the act ran. What the house does is stop lending.
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $candidate, 'channel' => 'stable']));

        $suspended = $this->house->lines(StandingLedger::SUSPENDED);
        self::assertCount(1, $suspended);
        self::assertSame('the house', $suspended[0]['by']);
        self::assertStringContainsString($judged . ' was judged', $suspended[0]['reason']);
        self::assertStringContainsString('is suspended', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate])['refused']);
    }

    public function testAMeasurementOfContentThatMovedWhileItWasMeasuredLeavesNoReceipt(): void
    {
        $this->house->granted();
        $candidate = $this->house->candidate('a.tar', 'candidate a');
        $this->house->scanner = function (string $bytes): array {
            $this->house->candidate('a.tar', 'rewritten while it was being measured');

            return ['ok' => true];
        };

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);

        self::assertSame([], $this->house->lines(StandingLedger::MEASURED));
    }

    public function testAnOperationTheHouseDoesNotKnowIsNotLentOrJudged(): void
    {
        $this->house->granted();
        $context = new ToolContext(principal: 'key:' . LabHouse::SEAT, channel: 'cli', scopes: LabHouse::SEAT_SCOPES);

        self::assertSame($context, $this->house->policy->contextFor($context, 'no_such_tool'));
        self::assertSame($context, $this->house->policy->contextFor($context, 'lab_scan'), 'an operation no grant covers is handed nothing');
        self::assertNull($this->house->policy->operationNamed('no:such'));
        self::assertSame($this->house->operation('lab:publish'), $this->house->policy->operationNamed('lab_publish'));
    }
}
