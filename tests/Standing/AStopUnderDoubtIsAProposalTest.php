<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Standing;

use Milpa\Command\Standing\GrantState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * UNDER DOUBT, A STOP STOPS — DECIDED BY ROD, 2026-10-12 (greenhouse decisions/0615, D10 and D13), asked here by the
 * door a seat works through. It was a proposal of the contract's seventh review, rebuilt as the eighth asked; this file
 * keeps the name it had while it was one.
 *
 * A narrow grant fixes the channel and is stopped. The seat says the same act another way: without the channel,
 * with an empty one, with a space after it. The contract's judge used to say the stopped grant «does not name» that
 * call, and a wider grant lent it — or, where the seat holds the word on its own, nobody's grant did and the seat's
 * own word decided.
 */
final class AStopUnderDoubtIsAProposalTest extends TestCase
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

    private function measured(string $name): string
    {
        $path = $this->house->candidate($name, "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);

        return $path;
    }

    public function testAWiderGrantDoesNotLendTheActAStoppedGrantMayBeNaming(): void
    {
        $this->house->granted(); // «release-line»: publish with the channel fixed to "stable", merge, announce
        $this->house->granted(['id' => 'any-release', 'covers' => [['operation' => 'lab:publish']]]);
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $this->measured('a.txt')]), 'control: both in force, the call that says no channel runs');

        $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'a published release names a machine']);
        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state);
        self::assertSame(GrantState::InForce, $this->house->book()->record('any-release')?->state);
        $published = \count($this->house->done('published'));

        // Spelled as the stopped grant spells it: refused, as it always was.
        self::assertStringContainsString('«release-line» is suspended', (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $this->measured('b.txt'), 'channel' => 'stable'])['refused'] ?? 'RAN'));
        // The same act, spelled without the channel: the wide grant would lend it, and the stop stands in the way.
        $refused = (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $this->measured('c.txt')])['refused'] ?? 'RAN');
        self::assertStringContainsString('Standing grant «release-line» is suspended, and it may be the one that names this call', $refused);
        self::assertCount($published, $this->house->done('published'), 'the act ran');
        // A call that SAYS another channel is certainly another act: the wide grant lends it.
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $this->measured('d.txt'), 'channel' => 'beta']));
    }

    public function testOnlyACallThatSaysAnotherValueBeyondDoubtIsOutsideTheStop(): void
    {
        $this->house->granted();
        $this->house->granted(['id' => 'any-release', 'covers' => [['operation' => 'lab:publish']]]);
        $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'a published release names a machine']);
        $published = \count($this->house->done('published'));

        foreach (['the empty text' => '', 'a space after the value' => 'stable ', 'a space before it' => ' stable', 'a number' => 7, 'a list that holds the value' => ['stable']] as $how => $channel) {
            $answer = $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $this->measured('e.txt'), 'channel' => $channel]);

            self::assertStringContainsString('it may be the one that names this call', (string) ($answer['refused'] ?? 'RAN'), $how);
        }
        self::assertCount($published, $this->house->done('published'), 'the act ran');
        // Not reached, and said: an operation's own reading of a value. Capitals say another value to the judge,
        // and the wide grant lends that call; a house hands the judge the arguments as its operation reads them.
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $this->measured('f.txt'), 'channel' => 'Stable']));
    }

    /** THE SECOND HALF: the stopped grant alone, and a seat that holds the word on its own. */
    public function testASeatThatHoldsTheWordOnItsOwnDoesNotWalkAroundAStopByLeavingTheArgumentOut(): void
    {
        $this->house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'lab:publish'], LabHouse::PERSON);
        $this->house->granted();
        // control: the stop in force, said exactly — the grant governs the seat it names, whatever the seat holds.
        $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'a published release names a machine']);
        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state);
        self::assertStringContainsString('«release-line» is suspended', (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $this->measured('a.txt'), 'channel' => 'stable'])['refused'] ?? 'RAN'));
        $published = \count($this->house->done('published'));

        // No other grant would lend this call: the answer was «nobody's call», and the seat's own word decided.
        $refused = (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $this->measured('b.txt')])['refused'] ?? 'RAN');
        self::assertStringContainsString('Standing grant «release-line» is suspended, and it may be the one that names this call', $refused);
        self::assertStringContainsString('until a person lifts that stop or gives a grant that supersedes «release-line» by name', $refused);
        self::assertCount($published, $this->house->done('published'), 'the act ran');

        // A call that says another channel is certainly not that grant's: it is the seat's own to make, as before.
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $this->measured('c.txt'), 'channel' => 'beta']));
    }

    /**
     * THE RULE OF THIS HOUSE, and it is the house's: a grant IN FORCE that may be naming a call governs a seat that
     * holds the word on its own. The contract hands the question and leaves the answer here.
     */
    public function testASeatDoesNotLeaveTheConditionsOfAGrantInForceBehindByLeavingTheArgumentOut(): void
    {
        $this->house->granted(); // «release-line», in force: publish with the channel fixed to "stable", once measured green
        $unmeasured = $this->house->candidate('never-measured.txt', "clean release\n");

        // A seat that LACKS the word is refused at the gate as it always was: nothing could be walked around.
        $lacks = (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $unmeasured])['refused'] ?? 'RAN');
        self::assertStringNotContainsString('may be the one that names this call', $lacks);
        self::assertStringContainsString('Missing required scope', $lacks);

        $this->house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'lab:publish'], LabHouse::PERSON);
        // control: said exactly, the grant governs the seat whatever it holds — the candidate was never measured.
        self::assertStringContainsString('was never measured on this candidate', (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $unmeasured, 'channel' => 'stable'])['refused'] ?? 'RAN'));
        $published = \count($this->house->done('published'));

        // The same act with the channel left out, or empty, or in another kind: it was «nobody's call», the seat's
        // own word decided, and the candidate nobody measured was published.
        foreach (['the channel left out' => [], 'the channel empty' => ['channel' => ''], 'a space after it' => ['channel' => 'stable ']] as $how => $said) {
            $refused = (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $unmeasured] + $said)['refused'] ?? 'RAN');
            self::assertStringContainsString('Standing grant «release-line» is in force and may be the one that names this call', $refused, $how);
            self::assertStringContainsString('Say each argument «release-line» fixes as it fixes it', $refused, $how);
        }
        self::assertCount($published, $this->house->done('published'), 'the act ran on a candidate nobody measured');
        // It is written down as the refusals of a grant are, and the book still verifies.
        self::assertNull($this->house->book()->ledger()->fault());
        self::assertStringContainsString('named_under_doubt', (string) file_get_contents($this->house->root . '/storage/identity/standing.jsonl'));

        // A call that says another channel is certainly not that grant's: it is the seat's own to make, as before.
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $unmeasured, 'channel' => 'beta']));
        // …and measured, and said as the grant says it, the act is lent under the grant.
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $this->measured('measured.txt'), 'channel' => 'stable']));
    }

    /** @return iterable<string, array{string}> */
    public static function waysAGrantIsNotInForce(): iterable
    {
        yield 'past its own date' => ['expired'];
        yield 'revoked by a person' => ['revoked'];
        yield 'not honoured: its signer may no longer give it' => ['demoted'];
    }

    #[DataProvider('waysAGrantIsNotInForce')]
    public function testAGrantThatIsNotInForceAndHonouredGovernsNothingUnderDoubt(string $how): void
    {
        $this->house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'lab:publish'], LabHouse::PERSON);
        $this->house->granted(['expires_at' => '2026-10-11T13:00:00+00:00']);
        $unmeasured = $this->house->candidate('never-measured.txt', "clean release\n");
        self::assertStringContainsString('is in force and may be the one that names this call', (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $unmeasured])['refused'] ?? 'RAN'), 'control: in force and honoured, it governs');

        match ($how) {
            'expired' => $this->house->later(3600),
            'revoked' => $this->house->signed(LabHouse::PERSON, 'standing:revoke', ['grant' => 'release-line']),
            'demoted' => $this->house->enroll(LabHouse::PERSON, ['lab:merge'], LabHouse::PERSON),
        };

        // It lends nothing, and under doubt it governs nothing: the call is the seat's own again.
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $unmeasured]));
    }
}
