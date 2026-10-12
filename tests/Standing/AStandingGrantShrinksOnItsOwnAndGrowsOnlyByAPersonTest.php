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

use Milpa\AppRuntime\Standing\StandingLedger;
use PHPUnit\Framework\TestCase;

/**
 * Shrinking is automatic and anyone's; growing back is a person's new signature (greenhouse decisions/0613 §4,
 * decisions/0615 §4).
 *
 * The second case behind this is real: a class of acts went on being exercised for hours after its mandate had
 * suspended itself, because nothing read the mandate's state at the moment of the act. Here the state is read on
 * every call, and a suspended grant refuses its whole class — the harmless act included.
 *
 * @guards a suspension written by the house the moment a watcher reports its falsifier fired; a suspended grant
 *         refusing every act it covers, between two calls of the same session; anyone recognized stopping a grant,
 *         with nothing widened for anyone; a reinstatement by a person that names the suspension it lifts; a watcher
 *         that must give a sign of life again afterwards; the grant refusing once its signer may no longer give it
 *
 * @refuses a reinstatement signed by a seat, written into the book by hand, replayed from an older suspension, or
 *          signed by a key that does not answer for the seat; a watcher that never gave a sign or went quiet; a
 *          sign of life from before the suspension; a grant past its own date, superseded, or revoked
 *
 * @subject-in milpa/app-runtime
 */
final class AStandingGrantShrinksOnItsOwnAndGrowsOnlyByAPersonTest extends TestCase
{
    private LabHouse $house;

    private string $evidence;

    protected function setUp(): void
    {
        $this->house = new LabHouse();
        $this->house->granted();
        // A change that only adds evidence: the «innocent» act, measured green.
        $this->evidence = $this->house->candidate('evidence.diff', 'an acta and nothing else');
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $this->evidence]);
    }

    protected function tearDown(): void
    {
        $this->house->destroy();
    }

    /** @return array<string, mixed> */
    private function merge(string $as = LabHouse::SEAT): array
    {
        return $this->house->as($as, 'lab:merge', ['path' => $this->evidence]);
    }

    private function suspension(): string
    {
        return (string) $this->house->book()->record('release-line')?->suspension;
    }

    public function testAWatcherThatNeverGaveASignIsNotGreenAndOneThatWentQuietIsNotEither(): void
    {
        $fresh = new LabHouse();
        try {
            $fresh->granted(watched: false);
            $candidate = $fresh->candidate('a.tar', 'candidate a');
            $fresh->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);

            self::assertStringContainsString('has given no sign of life since the grant was given or last restored', $fresh->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate])['refused']);
            self::assertSame(['ok' => true, 'fired' => false], $fresh->as(LabHouse::SEAT, 'lab:watch'));
            self::assertSame(['ok' => true], $fresh->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate]));
        } finally {
            $fresh->destroy();
        }

        // The grant asks for a sign within 3600 seconds.
        $this->house->later(3600);
        self::assertSame(['ok' => true], $this->merge());
        $this->house->later(1);
        self::assertStringContainsString('A watcher that went quiet is not green', $this->merge()['refused']);
        $this->house->as(LabHouse::OTHER_SEAT, 'lab:watch');
        self::assertStringContainsString('went quiet', $this->merge()['refused'], 'a seat without the watcher\'s word does not run it');
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        self::assertSame(['ok' => true], $this->merge());
    }

    public function testAnAnswerThatDoesNotSayFiredIsNotASignOfLife(): void
    {
        $this->house->later(3601);
        $this->house->fired = ['ok' => true];

        $this->house->as(LabHouse::SEAT, 'lab:watch');

        self::assertStringContainsString('went quiet', $this->merge()['refused']);
        $this->house->fired = 'yes';
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        self::assertCount(1, $this->house->lines(StandingLedger::WATCHED), 'only the first sign, from when the grant was given');
    }

    public function testWhenTheFalsifierFiresTheHouseSuspendsAndTheNextActIsRefusedEvenTheHarmlessOne(): void
    {
        self::assertSame(['ok' => true], $this->merge(), 'control: in force, the act passes');

        $this->house->fired = true;
        self::assertSame(['ok' => true, 'fired' => true], $this->house->as(LabHouse::SEAT, 'lab:watch'));

        // The line that says the falsifier fired IS the suspension: nothing else is written for it, and a person
        // lifts it by the digest of that very line.
        self::assertSame([], $this->house->lines(StandingLedger::SUSPENDED));
        $watched = $this->house->lines(StandingLedger::WATCHED);
        self::assertTrue(end($watched)['fired']);
        $record = $this->house->book()->record('release-line');
        self::assertSame('suspended', $record?->standsAs($this->house->now));
        self::assertStringContainsString('its falsifier «a-published-name» fired: `lab:watch` reported it', (string) $record?->because);
        self::assertSame($this->house->ledger()->entries()[\count($this->house->ledger()->entries()) - 1]['hash'], $record?->suspension);

        // The same session, the next call: nothing about the act changed — only the grant's state, read now.
        $refused = $this->merge();
        self::assertStringContainsString('Standing grant «release-line» is suspended', $refused['refused']);
        self::assertStringContainsString('every act it covers is the person\'s again, this one included', $refused['refused']);
        self::assertStringContainsString('No seat can, and neither can the house', $refused['refused']);
        self::assertCount(1, $this->house->done('merged'));
        self::assertSame('suspended', $this->house->lines(StandingLedger::REFUSED)[0]['reason']);

        // The class went back to the person: the same act, by the person, runs.
        self::assertSame(['ok' => true], $this->merge(LabHouse::PERSON));
    }

    public function testAnyoneRecognizedMayStopAGrantAndNothingWidens(): void
    {
        // The accepted cost: a seat the grant does not even name stops it.
        $stopped = $this->house->as(LabHouse::OTHER_SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'I think its watcher is looking at the wrong place']);

        self::assertTrue($stopped['ok'], (string) ($stopped['error'] ?? $stopped['refused'] ?? ''));
        self::assertSame('suspended', $stopped['stands_as']);
        self::assertSame($this->suspension(), $stopped['suspension']);
        self::assertStringContainsString('Only a person lifts it: standing:reinstate --grant=release-line --suspension=' . $stopped['suspension'], $stopped['next']);
        self::assertSame('key:' . LabHouse::OTHER_SEAT, $this->house->lines(StandingLedger::SUSPENDED)[0]['by']);

        // The class is the person's again…
        self::assertStringContainsString('is suspended — I think its watcher is looking at the wrong place', $this->merge()['refused']);
        self::assertSame(['ok' => true], $this->merge(LabHouse::PERSON));
        // …and whoever stopped it gained nothing: it is refused the act by the word it lacks, as before.
        self::assertStringContainsString('Missing required scope', $this->merge(LabHouse::OTHER_SEAT)['refused']);
        self::assertStringContainsString("Missing required scope for tool 'standing_reinstate'", $this->house->signed(LabHouse::OTHER_SEAT, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $stopped['suspension']])['refused']);
    }

    public function testStoppingAGrantNeedsOneThatExistsAndNeverAReason(): void
    {
        self::assertStringContainsString('no standing grant «nope»', $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'nope', 'reason' => 'x'])['error']);
        self::assertSame([], $this->house->lines(StandingLedger::SUSPENDED));

        // The terminal's own operator may stop one too, and is written down as what it is.
        self::assertTrue($this->house->as(null, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen from the terminal'])['ok']);
        self::assertSame('local-shell', $this->house->lines(StandingLedger::SUSPENDED)[0]['by']);
        // A stop with no reason is a stop (the fourth review, M3 — a proposal): this door used to send it back.
        $bare = $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => ' ']);
        self::assertTrue($bare['ok'] ?? false, (string) json_encode($bare));
        self::assertStringContainsString('no reason was given', (string) $this->house->book()->record('release-line')?->because);
    }

    public function testOnlyAPersonLiftsASuspensionWithASignatureThatNamesIt(): void
    {
        $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'I measured after the act']);
        $suspension = $this->suspension();

        // Not a seat, signing its own call.
        self::assertStringContainsString("Missing required scope for tool 'standing_reinstate'. Need one of: identity:enroll", $this->house->signed(LabHouse::SEAT, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $suspension])['refused']);
        // Not a key the house never recognized, though it runs at the terminal with every scope.
        self::assertStringContainsString('does not answer for seat', $this->house->signed(LabHouse::STRANGER, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $suspension])['error']);
        // Not without a signature, and not with a signature over another suspension.
        self::assertStringContainsString('requires the signature that names WHO decides', $this->house->as(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $suspension])['error']);
        self::assertStringContainsString('a signature lifts the one it names and no other', $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => 'sha256:another'])['error']);
        self::assertStringContainsString('no standing grant «nope»', $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'nope', 'suspension' => $suspension])['error']);
        self::assertStringContainsString('is suspended', $this->merge()['refused'], 'none of that lifted anything');
        // The watcher runs while the grant is suspended: a sign of life that is not a sign of this grant being watched.
        $this->house->as(LabHouse::SEAT, 'lab:watch');

        $lifted = $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $suspension]);
        self::assertTrue($lifted['ok'], (string) ($lifted['error'] ?? ''));
        self::assertSame('in force', $lifted['stands_as']);
        self::assertStringContainsString('A sign from before the suspension does not count', $lifted['next']);

        // In force again — and unwatched until its watcher speaks again.
        self::assertStringContainsString('no sign of life since the grant was given or last restored', $this->merge()['refused']);
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        self::assertSame(['ok' => true], $this->merge());
        self::assertStringContainsString('is not suspended — it is in force', $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $suspension])['error']);
    }

    public function testALineWrittenByHandAndAnOldSignatureLiftNothing(): void
    {
        // First round: suspended, lifted by the person. Its signed bytes are in the book for anyone to copy.
        $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'first']);
        $first = $this->suspension();
        $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $first]);
        $old = $this->house->lines(StandingLedger::REINSTATED)[0];

        // Second round: suspended again.
        $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'second']);
        $second = $this->suspension();
        self::assertNotSame($first, $second);

        // Whoever holds the disk appends a line with a link that verifies — the chain does not stop that. Each line
        // says which recognition of its signer it stands over, as a line the door writes does (the fourth review, M2):
        // what keeps each of them from lifting is the one thing it lacks, and nothing else.
        $over = fn (string $key): array => ['recognized' => $this->house->book()->recognitions([$key])];
        $this->house->writtenByHand(StandingLedger::REINSTATED, ['grant' => 'release-line', 'suspension' => $second, 'fingerprint' => LabHouse::PERSON] + $over(LabHouse::PERSON));
        $this->house->writtenByHand(StandingLedger::REINSTATED, ['grant' => 'release-line', 'suspension' => $second, 'fingerprint' => LabHouse::PERSON, 'payload' => '{"made":"up"}', 'signature' => 'made up'] + $over(LabHouse::PERSON));
        // …the seat signs one itself, and it is a real signature — of a key that may not lift…
        $own = $this->house->signature(LabHouse::SEAT, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $second]);
        $this->house->writtenByHand(StandingLedger::REINSTATED, ['grant' => 'release-line', 'suspension' => $second, 'fingerprint' => LabHouse::SEAT, 'payload' => $own->payload, 'signature' => $own->signature] + $over(LabHouse::SEAT));
        // …and the person's OLD signature is replayed: genuine, and it names the first suspension, not this one.
        $this->house->writtenByHand(StandingLedger::REINSTATED, ['suspension' => $second] + $old);
        // A signed revocation replayed as a reinstatement is not one either.
        $revoke = $this->house->signature(LabHouse::PERSON, 'standing:revoke', ['grant' => 'release-line']);
        $this->house->writtenByHand(StandingLedger::REINSTATED, ['grant' => 'release-line', 'suspension' => $second, 'fingerprint' => LabHouse::PERSON, 'payload' => $revoke->payload, 'signature' => $revoke->signature] + $over(LabHouse::PERSON));

        self::assertNull($this->house->ledger()->fault(), 'the book verifies: every line links to the one before it');
        self::assertSame($second, $this->suspension());
        self::assertStringContainsString('is suspended — second', $this->merge()['refused']);

        // The control: the person's signature over THIS suspension lifts it.
        self::assertTrue($this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $second])['ok']);
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        self::assertSame(['ok' => true], $this->merge());
    }

    public function testAGrantIsRefusedOnceItsSignerMayNoLongerGiveIt(): void
    {
        self::assertSame(['ok' => true], $this->merge());

        // The person stops holding the word that recognizes identities: read today, not on the day of the signature.
        $this->house->enroll(LabHouse::PERSON, ['lab:merge'], LabHouse::PERSON);
        $refused = $this->merge();
        self::assertStringContainsString('names this call and is not honoured: the key that signed it does not hold `identity:enroll` today', $refused['refused']);
        self::assertSame('signature', $this->house->lines(StandingLedger::REFUSED)[0]['check']);
        self::assertSame('not honoured', $this->house->book()->record('release-line')?->standsAs($this->house->now));

        // And back: the same bytes are honoured again the moment the signer may give them again.
        $this->house->enroll(LabHouse::PERSON, LabHouse::PERSON_SCOPES, LabHouse::PERSON);
        self::assertSame(['ok' => true], $this->merge());
    }

    public function testAGrantIsRefusedWhenItsSignatureCannotBeVerifiedHere(): void
    {
        // The key is gone from the keyring that judges — expired, revoked, or never imported.
        $this->house->revokeKey(LabHouse::PERSON);

        $refused = $this->merge();
        self::assertStringContainsString('is not honoured: its signature does not verify here', $refused['refused']);
        self::assertStringContainsString('the keyring of this process does not hold the public key of whoever signed', $refused['refused']);
    }

    public function testAGrantSignedOnAnotherHostIsNotThisHouses(): void
    {
        $fresh = new LabHouse();
        try {
            $elsewhere = $fresh->signature(LabHouse::PERSON, 'standing:grant', ['grant' => $fresh->document()], host: 'another-host');
            $fresh->writtenByHand(StandingLedger::GRANTED, ['grant' => 'release-line', 'digest' => 'sha256:whatever', 'payload' => $elsewhere->payload, 'signature' => $elsewhere->signature, 'fingerprint' => LabHouse::PERSON]);

            self::assertSame('it was signed on another host, not on this one', $fresh->book()->record('release-line')?->fault);
        } finally {
            $fresh->destroy();
        }
    }

    public function testAGrantPastItsOwnDateLendsNothing(): void
    {
        $fresh = new LabHouse();
        try {
            $fresh->granted(['expires_at' => '2026-10-11T13:00:00+00:00']);
            $candidate = $fresh->candidate('a.tar', 'candidate a');
            $fresh->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);

            self::assertSame(['ok' => true], $fresh->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate]));
            $fresh->later(3600);
            $fresh->as(LabHouse::SEAT, 'lab:watch');
            self::assertStringContainsString('expired at 2026-10-11T13:00:00+00:00', $fresh->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate])['refused']);
            self::assertSame('expired', $fresh->book()->record('release-line')?->standsAs($fresh->now));
        } finally {
            $fresh->destroy();
        }
    }

    public function testAPersonWithdrawsAGrantForGood(): void
    {
        self::assertStringContainsString("Missing required scope for tool 'standing_revoke'", $this->house->signed(LabHouse::SEAT, 'standing:revoke', ['grant' => 'release-line'])['refused']);
        self::assertStringContainsString('does not answer for seat', $this->house->signed(LabHouse::STRANGER, 'standing:revoke', ['grant' => 'release-line'])['error']);
        // A line written by hand withdraws nothing — and it STOPS the grant, as any line that asks for it to stop
        // does: the house cannot tell a withdrawal nobody signed from one whose signer has left since, and what
        // takes authority away never reads as nothing (decisions/0615, «Lo que la segunda revisión encontró», 2).
        self::assertNotNull($this->house->book()->record('release-line')?->watcherSignalAt, 'control: the watcher had given a sign');
        $this->house->writtenByHand(StandingLedger::REVOKED, ['grant' => 'release-line', 'fingerprint' => LabHouse::PERSON]);
        self::assertSame('stopped', $this->house->book()->record('release-line')?->standsAs($this->house->now));
        self::assertNull($this->house->book()->record('release-line')?->watcherSignalAt, 'a stop forgets the watcher\'s sign, as a suspension does: nobody is shown one from before it');
        self::assertStringContainsString('Standing grant «release-line» is suspended', $this->merge()['refused']);
        // …and NOT lifted like a suspension (decided by Rod, 2026-10-12): a reinstatement would bring back what a
        // withdrawal took away. Only a new grant that names that stop takes it out of the way.
        $stop = $this->suspension();
        $lifted = $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $stop]);
        self::assertStringContainsString('a reinstatement does not lift that', (string) ($lifted['error'] ?? 'LIFTED'));
        self::assertStringContainsString('"supersedes": "release-line" and "lifts": "' . $stop . '"', (string) $lifted['error']);
        self::assertStringContainsString('already stopped, by what only a new grant lifts', (string) $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'x'])['error']);
        // …nor does a suspension written by hand: it is one a person could lift by name, and it does not take the
        // place of the stop that stands.
        $this->house->writtenByHand(StandingLedger::SUSPENDED, ['grant' => 'release-line', 'by' => 'key:' . LabHouse::SEAT, 'reason' => 'to be lifted by name']);
        self::assertSame($stop, $this->suspension(), 'and nobody puts a stop a person could lift in its place');
        self::assertSame('stopped', $this->house->book()->record('release-line')?->standsAs($this->house->now));
        $this->house->granted(['id' => 'release-line-again', 'supersedes' => 'release-line', 'lifts' => $stop]);
        self::assertSame('superseded', $this->house->book()->record('release-line')?->standsAs($this->house->now));
        self::assertSame(['ok' => true], $this->merge(), 'under the grant a person gave again');

        // The grant a person withdraws by their own signed act is gone, as before.
        $withdrawn = $this->house->signed(LabHouse::PERSON, 'standing:revoke', ['grant' => 'release-line-again']);
        self::assertTrue($withdrawn['ok'], (string) ($withdrawn['error'] ?? ''));
        self::assertSame('revoked', $withdrawn['stands_as']);

        $refused = $this->merge();
        self::assertStringContainsString('Standing grant «release-line-again» is revoked', $refused['refused']);
        self::assertStringContainsString('a person gives a new grant', $refused['refused']);
        // Nothing stops or restores what is gone.
        self::assertStringContainsString('already revoked', $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line-again', 'reason' => 'x'])['error']);
        self::assertStringContainsString('already revoked', $this->house->signed(LabHouse::PERSON, 'standing:revoke', ['grant' => 'release-line-again'])['error']);
        self::assertStringContainsString('is not suspended — it is revoked', $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line-again', 'suspension' => 'sha256:x'])['error']);
    }

    public function testAGrantThatReplacesAnotherTakesOverAndTheOldOneLendsNothing(): void
    {
        // Suspended, and replaced by a person with a grant that names the suspension it takes out of the way.
        $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'it needs another condition']);
        $this->house->granted(['id' => 'release-line-2', 'supersedes' => 'release-line', 'lifts' => $this->suspension()]);

        self::assertSame(['ok' => true], $this->merge(), 'the new grant lends; the old one\'s suspension is not its own');
        self::assertSame('release-line-2', $this->house->lines(StandingLedger::ABSORBED)[0]['grant']);
        self::assertStringContainsString('already superseded', $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'x'])['error']);

        // A replacement whose own signature does not hold replaces nothing — and it STOPS the grant it names: what
        // asks for a grant to be taken out of the way is at least a stop, never nothing (second review, 2).
        $forged = $this->house->signature(LabHouse::SEAT, 'standing:grant', ['grant' => $this->house->document(['id' => 'release-line-3', 'supersedes' => 'release-line-2'])]);
        $this->house->writtenByHand(StandingLedger::GRANTED, ['grant' => 'release-line-3', 'digest' => 'sha256:x', 'payload' => $forged->payload, 'signature' => $forged->signature, 'fingerprint' => LabHouse::SEAT]);
        self::assertNotNull($this->house->book()->record('release-line-3')?->fault);
        $second = $this->house->book()->record('release-line-2');
        self::assertSame('stopped', $second?->standsAs($this->house->now), 'replaced it is not; stopped it is');
        self::assertNull($second?->watcherSignalAt, 'and the sign its watcher gave before the stop is forgotten');
        self::assertStringContainsString('«release-line-3» names it as replaced', (string) $second?->because);
        self::assertStringContainsString('Standing grant «release-line-2» is suspended', $this->merge()['refused']);
        // …and only a new grant that names that stop takes it out of the way: no reinstatement does.
        self::assertStringContainsString('a reinstatement does not lift that', (string) $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line-2', 'suspension' => (string) $second?->suspension])['error']);
        $unnamed = (string) $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document(['id' => 'release-line-4', 'supersedes' => 'release-line-2'])])['error'];
        self::assertStringContainsString('is stopped (' . $second?->suspension . ')', $unnamed);
        self::assertStringNotContainsString('a person lifts it first', $unnamed, 'nobody lifts that stop first: a reinstatement does not');
        $this->house->granted(['id' => 'release-line-4', 'supersedes' => 'release-line-2', 'lifts' => (string) $second?->suspension], watched: false);
        self::assertStringContainsString('has given no sign of life', $this->merge()['refused'], 'the new grant has to be watched like any other');
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        self::assertSame(['ok' => true], $this->merge());
    }

    public function testASuspendedGrantRefusesTheCallWhateverAnotherGrantWouldLend(): void
    {
        // Two grants over the same act, both in force: one that passes is enough.
        $this->house->granted(['id' => 'second-line']);
        self::assertSame(['ok' => true], $this->merge(), 'control: both in force');

        // One of them is stopped. Shrinking wins: the other, in force and with everything green, lends nothing —
        // a suspension that a second grant walks around is not a suspension.
        $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'stopped']);
        $refused = $this->merge();
        self::assertStringContainsString('Standing grant «release-line» is suspended — stopped', $refused['refused']);
        self::assertStringContainsString('Another grant names this call too, and it lends nothing while «release-line» is suspended', $refused['refused']);
        $written = $this->house->lines(StandingLedger::REFUSED);
        self::assertSame(['release-line', 'suspended'], [$written[0]['grant'], $written[0]['reason']]);
        self::assertCount(1, $this->house->done('merged'));

        // Giving yet another grant beside it does not take it out of the way…
        $this->house->granted(['id' => 'third-line']);
        self::assertStringContainsString('Standing grant «release-line» is suspended', $this->merge()['refused']);
        // …a person's act that NAMES it does: here, a grant that supersedes it and says which suspension it lifts.
        $this->house->granted(['id' => 'fourth-line', 'supersedes' => 'release-line', 'lifts' => $this->suspension()]);
        self::assertSame(['ok' => true], $this->merge());
        self::assertSame('superseded', $this->house->book()->record('release-line')?->standsAs($this->house->now));
    }

    public function testLiftingTheSuspensionLetsTheOtherGrantLendAgain(): void
    {
        $this->house->granted(['id' => 'second-line']);
        $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'second-line', 'reason' => 'stopped']);
        self::assertStringContainsString('Standing grant «second-line» is suspended', $this->merge()['refused']);

        $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'second-line', 'suspension' => (string) $this->house->book()->record('second-line')?->suspension]);

        // The first grant was never stopped and its watcher never went quiet: it lends, though the one just lifted is
        // not watched yet.
        self::assertSame(['ok' => true], $this->merge());
        self::assertSame('release-line', $this->house->lines(StandingLedger::ABSORBED)[0]['grant']);
    }

    public function testARevokedGrantStopsNobody(): void
    {
        $this->house->granted(['id' => 'second-line']);
        $this->house->signed(LabHouse::PERSON, 'standing:revoke', ['grant' => 'release-line']);

        self::assertSame(['ok' => true], $this->merge(), 'what is gone lends nothing and stops nobody');
        self::assertSame('second-line', $this->house->lines(StandingLedger::ABSORBED)[0]['grant']);
    }

    public function testWhenNoGrantLendsTheRefusalThatGotFurthestSpeaks(): void
    {
        // Given first: a grant the caller is one measurement away from. Given after it: one whose ceiling this act
        // will never fit under. The refusal the caller reads is the one it can do something about.
        $other = new LabHouse();
        try {
            $other->granted(['conditions' => [['name' => 'builds', 'statement' => 'it builds', 'by' => ['operation' => 'lab:scan', 'arguments' => ['strict' => true], 'free' => ['path']]]]]);
            $other->granted(['id' => 'second-line', 'ceiling' => ['mutation' => 'persistent', 'externality' => 'same_principal', 'reversibility' => 'compensatable', 'authority' => 'write_as_user', 'subject' => 'executable']]);
            $candidate = $other->candidate('evidence.diff', 'an acta and nothing else');
            $other->as(LabHouse::SEAT, 'lab:scan', ['path' => $candidate]);

            self::assertStringContainsString('Standing grant «release-line» lends nothing to this call: condition "builds" was never measured', $other->as(LabHouse::SEAT, 'lab:merge', ['path' => $candidate])['refused']);
        } finally {
            $other->destroy();
        }
    }

    public function testLevelWithAnotherTheLaterGrantSpeaks(): void
    {
        // A grant that replaced another and then ran out says so — not «superseded» of the one it replaced.
        $this->house->granted(['id' => 'release-line-2', 'supersedes' => 'release-line', 'expires_at' => '2026-10-11T12:30:00+00:00']);
        self::assertSame(['ok' => true], $this->merge());

        $this->house->later(1800);
        $this->house->as(LabHouse::SEAT, 'lab:watch');

        self::assertStringContainsString('Standing grant «release-line-2» expired at', $this->merge()['refused']);
    }
}
