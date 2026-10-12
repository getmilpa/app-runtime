<?php

/**
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Standing;

use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\Command\Standing\GrantState;
use PHPUnit\Framework\TestCase;

/**
 * What a FIFTH independent review found in the house's judge of standing grants (greenhouse decisions/0615): the
 * siblings, again. The eight findings of the fourth held on the path each had walked; what fell was the door beside
 * each one. Every test here was red first, by the door the reviewer used.
 */
final class WhatAFifthReviewFoundInTheHouseTest extends TestCase
{
    /** A second person of the line: a key that holds the word that recognizes identities. */
    private const SECOND = LabHouse::STRANGER;

    private LabHouse $house;

    protected function setUp(): void
    {
        $this->house = new LabHouse();
    }

    protected function tearDown(): void
    {
        $this->house->destroy();
    }

    // ── 5-M1 · what shrinks does not come back on its own — by ANY way of shrinking (D1) ─────────────────────────

    public function testAReinstatementDoesNotReviveWhenItsSignersWordIsTakenInPlaceAndGivenBack(): void
    {
        // The fourth review's M2 counted how many times a key LEFT. `identity:enroll` over a live key replaces its
        // state in place: no departure. A second person lifted a stop, lost the word that makes it a person —
        // the grant was stopped again, the safe way — and was given the word back: in force, with no signature
        // that named the grant written since.
        $this->house->enroll(self::SECOND, ['identity:enroll'], LabHouse::PERSON);
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $this->house->signed(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen by the seat']);
        $lifted = $this->house->signed(self::SECOND, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => (string) $this->state()?->suspension]);
        self::assertSame(GrantState::InForce, $this->state()?->state, 'control: the second person lifted the stop — ' . json_encode($lifted));

        $this->house->enroll(self::SECOND, ['lab:scan'], LabHouse::PERSON);
        self::assertSame(GrantState::Suspended, $this->state()?->state, 'control: its word taken in place, the lift does not hold');
        $signed = $this->signedByAPerson();

        $this->house->enroll(self::SECOND, ['identity:enroll'], LabHouse::PERSON);

        self::assertSame($signed, $this->signedByAPerson(), 'control: no person signed anything about the grant in between');
        self::assertSame(GrantState::Suspended, $this->state()?->state, 'a reinstatement came back when its signer was given the word back');
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]));
        self::assertSame([], $this->house->done('merged'));
    }

    public function testAGrantDoesNotReviveWhenItsSignersWordIsTakenInPlaceAndGivenBack(): void
    {
        // The same, on the grant's own line.
        $this->house->enroll(self::SECOND, ['identity:enroll'], LabHouse::PERSON);
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        self::assertArrayNotHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]), 'control: the grant lends');

        $this->house->enroll(LabHouse::PERSON, ['lab:scan'], self::SECOND);
        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]), 'control: its signer is no person today, the grant is not honoured');

        $this->house->enroll(LabHouse::PERSON, LabHouse::PERSON_SCOPES, self::SECOND);

        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]), 'a grant came back when its signer was given the word back');
        self::assertCount(1, $this->house->done('merged'));
    }

    public function testASecondStopIsWrittenWhenALiftWasWrittenAfterTheFirstEvenOneThatDoesNotHoldToday(): void
    {
        // D15 answers a key's second stop of what it already stopped without writing. «Already stopped» leaned on
        // what the book derives from TODAY's identities: while the grant was stopped only because the lift did not
        // hold, the seat's second stop left no line — and there was nothing in the book of that second fault.
        $this->house->enroll(self::SECOND, ['identity:enroll'], LabHouse::PERSON);
        $this->house->granted();
        $this->house->signed(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'the first fault']);
        $this->house->signed(self::SECOND, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => (string) $this->state()?->suspension]);
        $this->house->enroll(self::SECOND, ['lab:scan'], LabHouse::PERSON);
        self::assertSame(GrantState::Suspended, $this->state()?->state, 'control: the lift does not hold today');

        $second = $this->house->signed(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'the second fault']);

        self::assertNotFalse($second['written'] ?? null, 'a second fault, seen after a person had lifted the first stop, left no line: ' . json_encode($second));
        self::assertCount(2, $this->house->lines(StandingLedger::SUSPENDED));
    }

    public function testASecondStopWithNoLiftInBetweenIsStillAnsweredAndNotWritten(): void
    {
        // The guard: D15 as decided.
        $this->house->granted();
        $this->house->signed(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'the first fault']);

        $second = $this->house->signed(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'again']);

        self::assertSame([true, false], [$second['ok'] ?? null, $second['written'] ?? null]);
        self::assertCount(1, $this->house->lines(StandingLedger::SUSPENDED));
    }

    // ── the sweep: SIGNED LINE × WHAT MAKES IT STOP HOLDING AND COME BACK ────────────────────────────────────────
    //
    // The rule Rod decided (D1) is one: what shrinks does not come back on its own. It was built for ONE way of
    // shrinking. Every kind of line that is honoured by who its signer or its seats are TODAY, by every way a key
    // stops being what it was and returns that the ledger of identities keeps.

    private const A_THIRD = LabHouse::OTHER_SEAT;

    /** @return iterable<string, array{0: string, 1: string, 2: bool}> the line, the way, and whether the seat is lent anything once the key is back */
    public static function everyLineByEveryWayOfComingBack(): iterable
    {
        $shrinks = [
            'leaves the house and is recognized again' => false,
            'is recognized again by somebody else in place, and then by its enroller' => false,
        ];
        $ofASigner = [
            'loses the word that makes it a person in place, and is given it back' => false,
            // SAID, NOT CLOSED: nothing in the house writes down that a key did not verify for a while. A grant is
            // not honoured while its signature cannot be read, and is again when it can.
            'stops verifying in this keyring and verifies again' => true,
        ];
        $stays = ['has its words typed again by its enroller, with one more' => true];
        foreach (['a grant, by its signer' => [...$shrinks, ...$ofASigner, ...$stays], 'a grant, by its seat' => [...$shrinks, ...$stays], 'a lift, by its signer' => [...$shrinks, ...$ofASigner, ...$stays]] as $line => $ways) {
            foreach ($ways as $way => $lends) {
                yield "{$line} that {$way}" => [$line, $way, $lends];
            }
        }
        // A withdrawal never lends, whatever becomes of whoever signed it: both of its readings are a stop.
        foreach ([...$shrinks, ...$ofASigner, ...$stays] as $way => $lends) {
            yield "a withdrawal, by its signer that {$way}" => ['a withdrawal, by its signer', $way, false];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everyLineByEveryWayOfComingBack')]
    public function testWhatShrinksDoesNotComeBackOnItsOwn(string $line, string $way, bool $lendsOnceBack): void
    {
        $house = $this->house;
        $house->enroll(self::SECOND, ['identity:enroll'], LabHouse::PERSON);
        $house->enroll(self::A_THIRD, ['identity:enroll'], LabHouse::PERSON);
        $house->granted();
        $path = $house->candidate('good.txt', "clean release\n");
        $house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $lends = static function () use ($house, $path): bool {
            $house->later(1000);
            $house->as(LabHouse::SEAT, 'lab:watch');

            return !isset($house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path])['refused']);
        };
        [$key, $scopes] = match ($line) {
            'a grant, by its signer' => [LabHouse::PERSON, LabHouse::PERSON_SCOPES],
            'a grant, by its seat' => [LabHouse::SEAT, LabHouse::SEAT_SCOPES],
            default => [self::SECOND, ['identity:enroll']],
        };
        if ($line === 'a lift, by its signer') {
            $house->signed(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'seen']);
            $house->signed(self::SECOND, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => (string) $this->state()?->suspension]);
        }
        if ($line === 'a withdrawal, by its signer') {
            $house->signed(self::SECOND, 'standing:revoke', ['grant' => 'release-line']);
        }
        self::assertSame($line !== 'a withdrawal, by its signer', $lends(), 'control: before anything becomes of that key');
        $ledger = new \Milpa\AppRuntime\Identity\FileEnrollmentStore($house->root . '/storage/identity/enrollments.json');
        $by = (string) $ledger->authorizedBy($key);
        $enroller = substr($by, 4);
        $signed = $this->signedByAPerson();

        // The key stops being what it was…
        match ($way) {
            'leaves the house and is recognized again' => $ledger->revoke($key, 'key:' . self::A_THIRD),
            'is recognized again by somebody else in place, and then by its enroller' => $house->enroll($key, $scopes, self::A_THIRD),
            'loses the word that makes it a person in place, and is given it back' => $house->enroll($key, ['lab:scan'], $enroller),
            'stops verifying in this keyring and verifies again' => $house->revokeKey($key),
            'has its words typed again by its enroller, with one more' => $house->enroll($key, [...$scopes, 'lab:another'], $enroller),
        };
        if (!$lendsOnceBack || $way === 'stops verifying in this keyring and verifies again') {
            self::assertFalse($lends(), 'control: while that key is not what it was, nothing is lent');
        }
        // …and is it again.
        match ($way) {
            'stops verifying in this keyring and verifies again' => $house->verifiesAgain($key),
            'has its words typed again by its enroller, with one more' => null,
            default => $house->enroll($key, $scopes, $enroller),
        };

        self::assertSame($signed, $this->signedByAPerson(), 'control: no person signed a grant, a lift or a withdrawal in between');
        self::assertSame($lendsOnceBack, $lends(), $lendsOnceBack ? 'nothing shrank that the ledger keeps, and the line stopped holding' : 'it came back on its own');
    }

    public function testAGrantDoesNotComeBackWhenAKeyOnTheLineOfWhoAnswersForItsSeatLeavesAndReturns(): void
    {
        // A third key: neither the seat nor the signer, but the one through which the signer answers for the seat.
        // The line kept the recognition of its seats and of its signer only.
        $link = self::SECOND;
        $this->house->enroll($link, ['identity:enroll'], LabHouse::PERSON);
        $this->house->enroll(LabHouse::SEAT, LabHouse::SEAT_SCOPES, $link);
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        self::assertArrayNotHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]), 'control: the person answers for the seat through the key that enrolled it');
        $ledger = new \Milpa\AppRuntime\Identity\FileEnrollmentStore($this->house->root . '/storage/identity/enrollments.json');

        $ledger->revoke($link, 'key:' . LabHouse::PERSON);
        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]), 'control: with that key out of the house nobody answers for the seat');
        $this->house->enroll($link, ['identity:enroll'], LabHouse::PERSON);

        self::assertStringContainsString('left this house and was recognized again', (string) ($this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path])['refused'] ?? 'RAN'));
    }

    // ── 5-M3 · a process that was never told its catalogue still judges — or a seat stops ───────────────────────

    public function testAStoppedGrantStopsASeatWithTheWordOfItsOwnInAProcessThatWasNeverToldItsCatalogue(): void
    {
        // The web kernel installs the boundary and, by the call the HTTP surface makes to build its routes, was never
        // told which operations the house has. The verdict looked the operation up BY NAME in what it was told: with
        // nothing told, the judge was not asked, and a covered act of a seat that holds the word ran under a grant
        // that was stopped — nothing written before it.
        $this->house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'lab:merge'], LabHouse::PERSON);
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);
        $this->house->signed(LabHouse::PERSON, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'stopped by a person']);
        self::assertStringContainsString('suspended', (string) ($this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path])['refused'] ?? 'RAN'), 'control: a process that was told refuses');

        $this->house->bootsUntold();
        $said = $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]);

        self::assertArrayHasKey('refused', $said, 'a stopped grant did not stop a seat in a process that was never told its catalogue');
        self::assertSame([], $this->house->done('merged'));
    }

    public function testACoveredActIsNotLetPassUnwrittenInAProcessThatWasNeverToldItsCatalogue(): void
    {
        // The same door with the grant in force: the act ran, and the house wrote nothing down before it.
        $this->house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'lab:merge'], LabHouse::PERSON);
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);

        $this->house->bootsUntold();
        $said = $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $path]);

        self::assertSame(\count($this->house->done('merged')), \count($this->house->lines(StandingLedger::ABSORBED)), 'an act a grant covers ran with no line written before it: ' . json_encode($said));
        self::assertStringContainsString('was never told', (string) ($said['refused'] ?? 'RAN'), 'a process that cannot say which operations this house has went on as if no grant named the call');
    }

    public function testTheOperatorsOwnTerminalIsNotStoppedByAProcessThatWasNeverTold(): void
    {
        // The guard: this judge says nothing of a caller no grant can name.
        $this->house->granted();
        $path = $this->house->candidate('good.txt', "clean release\n");

        $this->house->bootsUntold();

        self::assertArrayNotHasKey('refused', $this->house->as(null, 'lab:merge', ['path' => $path]));
    }

    public function testAskingForTheDeclaredOperationsTellsTheBoundaryItsCatalogue(): void
    {
        // What the HTTP surface calls to build its routes — `Operations::declared()` — installed the boundary and
        // returned: only the terminal and the agent's catalogue told it anything.
        $container = new \Milpa\Container\DIContainer();

        $declared = \Milpa\AppRuntime\Support\Operations::declared($container, [Plugins\DeclaredInTheLab::class], $this->house->root);

        $boundary = $container->get(\Milpa\Console\OperationBoundary::class);
        self::assertInstanceOf(\Milpa\AppRuntime\Agent\PluginAuthoringPolicy::class, $boundary);
        self::assertNotSame([], $declared, 'control: the provider declared its operations');
        self::assertNotNull($boundary->operationNamed($declared[0]->name), 'the boundary of a web process was never told the operations it will be asked to run');
    }

    private function state(): ?\Milpa\AppRuntime\Standing\GrantRecord
    {
        return $this->house->book()->record('release-line');
    }

    /** How many lines of the book a person signed: a grant, a lift, a withdrawal. */
    private function signedByAPerson(): int
    {
        return \count($this->house->lines(StandingLedger::GRANTED)) + \count($this->house->lines(StandingLedger::REINSTATED)) + \count($this->house->lines(StandingLedger::REVOKED));
    }
}
