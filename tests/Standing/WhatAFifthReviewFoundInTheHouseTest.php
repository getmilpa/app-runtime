<?php

/**
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Standing;

use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\AppRuntime\Tests\Standing\Plugins\Shelf\LabShelf;
use Milpa\Command\Operation;
use Milpa\Command\Standing\GrantState;
use Milpa\Console\McpProjector;
use Milpa\ToolRuntime\Contracts\CallPolicy;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Policy\AuthorizationResult;
use Milpa\ToolRuntime\ToolResult;
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
        $this->leg?->destroy();
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

    // ── 5-M4 and the sweep: «ARE YOU THE HOUSE'S POLICY?» × WHAT CAN BE THERE ────────────────────────────────────
    //
    // Every door of a leg asked an identity question of whatever its gate holds, and went on as if there were no
    // judge when the answer was no. There is one: the house's boundary. The answer wanted is ONE question with ONE
    // answer — and where it is «I do not know» with a book in the house, a seat stops.

    /** @return iterable<string, array{0: \Closure(PluginAuthoringPolicy): (CallPolicy|false)}> what an app left as the container's call policy */
    public static function whatTheGateOfALegCanHold(): iterable
    {
        yield 'the app\'s own policy' => [static fn (PluginAuthoringPolicy $house): CallPolicy => new class () implements CallPolicy {
            public function authorize(ToolContext $context, \Milpa\ToolRuntime\ToolDefinition $tool, array $arguments): AuthorizationResult
            {
                return AuthorizationResult::allowed();
            }
        }];
        yield 'a decorator of the app around the house\'s' => [static fn (PluginAuthoringPolicy $house): CallPolicy => new class ($house) implements CallPolicy {
            public function __construct(private readonly CallPolicy $inner)
            {
            }

            public function authorize(ToolContext $context, \Milpa\ToolRuntime\ToolDefinition $tool, array $arguments): AuthorizationResult
            {
                return $this->inner->authorize($context, $tool, $arguments);
            }
        }];
        yield 'nothing at all' => [static fn (PluginAuthoringPolicy $house): bool => false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('whatTheGateOfALegCanHold')]
    public function testAFiredRunAsWorkIsHeardWhateverTheGateOfTheLegHolds(\Closure $onTheGate): void
    {
        $leg = $this->shelfLine('shelf:patrol', onTheGate: $onTheGate);
        touch($this->house->root . '/shelf-fired');

        $said = $leg->on->call('shelf_patrol', [], $leg->seat());

        self::assertTrue(self::answered($said)['fired'] ?? null, 'control: the seat was handed «fired»: ' . $said->error);
        self::assertSame(GrantState::Suspended, $this->house->book()->record('shelf-line')?->state, 'a falsifier fired on a leg and the grant stayed in force: the leg found no judge behind its gate, and went on as if there were none');
    }

    /** @return iterable<string, array{0: \Closure, 1: string}> */
    public static function whatTheGateCanHoldByEachActThatLeavesTheProcess(): iterable
    {
        foreach (self::whatTheGateOfALegCanHold() as $held => [$onTheGate]) {
            yield "{$held} · work confined in the house" => [$onTheGate, 'shelf:lend'];
            yield "{$held} · a rehearsal in a copy" => [$onTheGate, 'shelf:label'];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('whatTheGateCanHoldByEachActThatLeavesTheProcess')]
    public function testAStoppedGrantStopsASeatWithTheWordOfItsOwnWhateverTheGateOfTheLegHolds(\Closure $onTheGate, string $act): void
    {
        $this->coveredShelf();
        $this->house->signed(LabHouse::PERSON, 'standing:suspend', ['grant' => 'shelf-line', 'reason' => 'stopped by a person']);
        $leg = $this->leg = new LabLeg($this->house, $this->shelf->operations(), $this->shelf, onTheGate: $onTheGate);

        $said = $leg->on->call(McpProjector::toolName($act), ['path' => 'good.txt'], $leg->seat());

        self::assertFalse($said->success, 'a stopped grant did not stop a seat that holds the word of its own: ' . json_encode($said->data));
        self::assertSame([], $this->house->lines(StandingLedger::ABSORBED));
        self::assertFileDoesNotExist($this->house->root . '/storage/shelf/log.txt');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('whatTheGateOfALegCanHold')]
    public function testNoGrantIsGivenInAHouseWhoseGateDoesNotHoldTheHousesPolicy(\Closure $onTheGate): void
    {
        // The door that gives a grant asked only whether the BOUNDARY is the house's. With another call policy in
        // the container nothing is lent by any gate — the terminal's included — so the grant would be a sentence
        // that lends nothing. Measured before the fix: it was given, and its answer warned of nothing.
        $held = $onTheGate($this->house->policy);
        if ($held instanceof CallPolicy) {
            $this->house->container->registerService(CallPolicy::class, $held);
        }

        $said = $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document()]);

        if ($held === false) {
            self::assertTrue($said['ok'] ?? null, 'control: with no call policy registered, the house\'s is the one every gate is given: ' . json_encode($said));

            return;
        }
        self::assertFalse($said['ok'] ?? null, 'a grant was given in a house whose gates hold a policy that is not the house\'s: ' . json_encode($said['warnings'] ?? 'no warning'));
        self::assertStringContainsString('call policy', (string) ($said['error'] ?? ''));
        self::assertSame([], $this->house->lines(StandingLedger::GRANTED));
    }

    public function testTheHousesPolicyIsFoundBehindAnyNumberOfTheConsolesWrappers(): void
    {
        // `behind()` looked behind ONE wrapper. What it finds, by what can be there.
        $house = $this->house->policy;
        $judge = new class () implements \Milpa\Console\OperationPermissionPolicy {
            public function enforce(Operation $op, ToolContext $caller, array $arguments): AuthorizationResult
            {
                return AuthorizationResult::allowed();
            }
        };
        $wrapped = new \Milpa\Console\PermissionCallPolicy($judge, $house);
        $other = new class () implements CallPolicy {
            public function authorize(ToolContext $context, \Milpa\ToolRuntime\ToolDefinition $tool, array $arguments): AuthorizationResult
            {
                return AuthorizationResult::allowed();
            }
        };

        self::assertSame($house, PluginAuthoringPolicy::behind($house));
        self::assertSame($house, PluginAuthoringPolicy::behind($wrapped));
        self::assertSame($house, PluginAuthoringPolicy::behind(new \Milpa\Console\PermissionCallPolicy($judge, $wrapped)), 'the house\'s policy two wrappers deep was not found');
        self::assertNull(PluginAuthoringPolicy::behind(new \Milpa\Console\PermissionCallPolicy($judge, null)));
        self::assertNull(PluginAuthoringPolicy::behind(new \Milpa\Console\PermissionCallPolicy($judge, $other)));
        self::assertNull(PluginAuthoringPolicy::behind($other));
        self::assertNull(PluginAuthoringPolicy::behind(null));
    }

    public function testALegHandedNoJudgeAndWithNoneBehindItsGateDoesNotGoOnForASeatInAHouseThatKeepsABook(): void
    {
        // «A leg that cannot find the judge does not go on as if there were none.» Built as it was before the fifth
        // review — nobody hands it the judge — over a gate that holds an app's own policy.
        $this->coveredShelf();
        $app = iterator_to_array(self::whatTheGateOfALegCanHold())['the app\'s own policy'][0];
        $leg = $this->leg = new LabLeg($this->house, $this->shelf->operations(), $this->shelf, onTheGate: $app);
        $unhanded = new \Milpa\AppRuntime\Agent\TrialAwareRegistry($leg->off, $leg->router, $this->shelf->operations());

        $said = $unhanded->call('shelf_label', ['path' => 'good.txt'], $leg->seat());

        self::assertFalse($said->success, 'a run left the process for a seat with no judge asked, in a house that keeps a book: ' . json_encode($said->data));
        self::assertStringContainsString('found no judge of standing grants', (string) $said->error);
        // …and the operator's own terminal, which no grant names, goes as it always did.
        self::assertTrue($unhanded->call('shelf_label', ['path' => 'good.txt'], ToolContext::cli())->success);
    }

    public function testInAHouseWithNoBookALegWithNoJudgeGoesAsItAlwaysDid(): void
    {
        // The guard: a house that never gave a grant behaves as the published runtime, whatever its gates hold.
        $this->shelf = new LabShelf($this->house->root);
        $this->house->offer(...$this->shelf->operations());
        $this->house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'shelf:write'], LabHouse::PERSON);
        $this->house->candidate('good.txt', "clean release\n");
        $app = iterator_to_array(self::whatTheGateOfALegCanHold())['the app\'s own policy'][0];
        $leg = $this->leg = new LabLeg($this->house, $this->shelf->operations(), $this->shelf, onTheGate: $app);
        $unhanded = new \Milpa\AppRuntime\Agent\TrialAwareRegistry($leg->off, $leg->router, $this->shelf->operations());

        self::assertTrue($unhanded->call('shelf_label', ['path' => 'good.txt'], $leg->seat())->success);
        self::assertFileDoesNotExist($this->house->root . '/' . StandingLedger::PATH);
    }

    public function testAHouseThatKeepsABookAndWhoseBoundaryIsNotTheHousesLetsNoSeatAct(): void
    {
        // The other half of the one question. An app registered ITS OWN boundary: the house's policy is then asked
        // only at the gates, never told a catalogue, and no act is written before it runs. A grant given while the
        // house judged — or a book put there — would be read by nobody: so where the house's policy IS asked, with a
        // book in the house and no boundary of its own, a seat is refused and told why.
        $this->house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'lab:merge'], LabHouse::PERSON);
        $this->house->granted();
        $container = new \Milpa\Container\DIContainer();
        $container->registerService(\Milpa\Console\OperationBoundary::class, new class () implements \Milpa\Console\OperationBoundary {
            public function execute(Operation $operation, array $input, ?ToolContext $authority, \Closure $next): mixed
            {
                return $next();
            }
        });
        PluginAuthoringPolicy::install($container, $this->house->root);
        $policy = $container->get(CallPolicy::class);
        self::assertInstanceOf(PluginAuthoringPolicy::class, $policy, 'control: the house\'s policy is what the gates are given');
        $merge = $this->house->operation('lab:merge');
        $tool = new \Milpa\ToolRuntime\ToolDefinition('lab_merge', $merge->description, $merge->inputSchema ?? [], $merge->handler, scopes: $merge->scopes, mutating: true);

        $said = $policy->authorize($this->house->contextOf(LabHouse::SEAT), $tool, ['path' => 'good.txt']);

        self::assertFalse($said->allowed, 'a seat was let through by the house\'s policy in a house that keeps a book it does not judge by its boundary');
        self::assertStringContainsString('does not judge', (string) $said->reason);
        // …and whoever no grant can name is answered as before.
        self::assertTrue($policy->authorize(ToolContext::cli(), $tool, ['path' => 'good.txt'])->allowed);
    }

    public function testNoGrantIsGivenOverAnOperationTypedByAPermission(): void
    {
        // The guard of a door the sweep found (it held): a grant lends WORDS, and such an operation asks for none.
        // Over HTTP a run the host's judge of permissions admitted reaches the handler with no verdict of this
        // policy (greenhouse decisions/0544) — so a grant over it would name a call this judge is not asked about.
        $this->house->offer(new Operation('lab:census', 'Lab: an operation typed by a permission', static fn (array $input): array => ['ok' => true], effects: \Milpa\Command\Effect\EffectProfile::readOnly(), permission: 'lab.census'));

        $said = $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document(['covers' => [['operation' => 'lab:census']]])]);

        self::assertFalse($said['ok'] ?? null, 'a grant was given over an operation that asks for a permission, not for a word: ' . json_encode($said));
        self::assertStringContainsString('declares no scope, so there is nothing for a grant to lend', (string) ($said['error'] ?? ''));
    }

    public function testARunTheHttpPolicyAdmittedIsNotLetPastAGrantThatNamesIt(): void
    {
        // The guard behind that door: a line written by hand, or an operation that came to ask for a permission after
        // a grant named it. The shortcut for a run the HTTP policy judged runs the handler with no verdict.
        $census = new Operation('lab:census', 'Lab: an operation typed by a permission', static fn (array $input): array => ['ok' => true, 'ran' => true], effects: \Milpa\Command\Effect\EffectProfile::readOnly(), permission: 'lab.census');
        $this->house->offer($census);
        $this->house->grantedByHand(['covers' => [['operation' => 'lab:census']]]);
        $mark = new \Milpa\AppRuntime\Agent\JudgedPermission();
        $policy = new PluginAuthoringPolicy($this->house->root, judged: $mark, standing: new \Milpa\AppRuntime\Standing\HouseStandingJudge($this->house->root, $this->house, fn (): \DateTimeImmutable => $this->house->now));
        (function () use ($census): void {
            $this->catalogued['lab_census'] = $census;
        })->call($policy);
        $mark->executing('operation.executing', ['event' => new \Milpa\Console\Events\OperationExecutingEvent($census, [], 'http')]);

        $ran = null;
        try {
            $ran = $policy->execute($census, [], $this->house->contextOf(LabHouse::SEAT), static fn (): array => ['ok' => true, 'ran' => true]);
        } catch (\RuntimeException $refused) {
            self::assertStringContainsString('lab:census', $refused->getMessage());
        }

        self::assertNull($ran, 'a call a grant names ran with no verdict of the judge of standing grants: the HTTP policy had admitted it');
    }

    private LabShelf $shelf;

    private ?LabLeg $leg = null;

    /** A grant over `lab:merge`, watched by one operation of the shelf — and a leg over the shelf's operations. */
    private function shelfLine(string $watcher, ?\Closure $onTheGate = null): LabLeg
    {
        $this->shelf = new LabShelf($this->house->root);
        $this->house->offer(...$this->shelf->operations());
        $this->house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'shelf:write'], LabHouse::PERSON);
        $this->house->granted([
            'id' => 'shelf-line',
            'covers' => [['operation' => 'lab:merge']],
            'falsifier' => ['name' => 'a-shelf-out-of-place', 'statement' => 'the shelf is out of place', 'watcher' => ['operation' => $watcher], 'within_seconds' => 3600],
        ], watched: false);

        return $this->leg = new LabLeg($this->house, [...$this->shelf->operations(), $this->house->operation('lab:merge')], $this->shelf, onTheGate: $onTheGate);
    }

    /** A grant over the shelf's two acts that leave a leg's process, for a seat that holds their word on its own. */
    private function coveredShelf(): void
    {
        $this->shelf = new LabShelf($this->house->root);
        $this->house->offer(...$this->shelf->operations());
        $this->house->enroll(LabHouse::SEAT, [...LabHouse::SEAT_SCOPES, 'shelf:write'], LabHouse::PERSON);
        $this->house->granted(['id' => 'shelf-line', 'covers' => [['operation' => 'shelf:lend'], ['operation' => 'shelf:label']]]);
        $this->house->candidate('good.txt', "clean release\n");
        self::assertTrue($this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => 'good.txt'])['ok'] ?? false);
    }

    /** @return array<string, mixed> what the act itself answered, read out of what the leg handed back */
    private static function answered(ToolResult $said): array
    {
        $data = \is_array($said->data) ? $said->data : [];
        if (\is_array($data['output'] ?? null)) {
            return $data['output'];
        }
        if ($data !== []) {
            return $data;
        }
        $error = json_decode((string) $said->error, true);

        return \is_array($error) ? $error : [];
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
