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
        array_map(spl_autoload_unregister(...), $this->autoloaders);
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

    // ── 5-L2 · a «fired» is a stop of its own, and a seat's stop does not hide it ────────────────────────────────

    public function testAFiredWrittenWhileASeatsStopStandsIsTheStopAPersonHasToLift(): void
    {
        // A seat stops the grant for a routine pause; the falsifier fires and the watcher's line is written; a person
        // lifts the SEAT's stop by name. The grant was in force, with no signature that named the «fired».
        $this->house->granted();
        $this->house->signed(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'routine pause, nothing wrong']);
        $pause = (string) $this->state()?->suspension;
        $this->house->fired = true;
        $this->house->as(LabHouse::SEAT, 'lab:watch');
        $this->house->fired = false;

        $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => $pause]);

        self::assertSame(GrantState::Suspended, $this->state()?->state, 'a person lifted a routine pause and the grant was in force again, its falsifier having fired meanwhile');
        self::assertStringContainsString('fired', (string) $this->state()?->because);
        // …and lifting the «fired» by name is what brings it back.
        $this->house->signed(LabHouse::PERSON, 'standing:reinstate', ['grant' => 'release-line', 'suspension' => (string) $this->state()?->suspension]);
        self::assertSame(GrantState::InForce, $this->state()?->state);
    }

    // ── 5-L1 · a stop is never refused for its reason — not by the terminal's signature either ─────────────────

    /** @return iterable<string, array{string}> */
    public static function reasonsThatAreNotText(): iterable
    {
        yield 'two bytes that are no character' => ["\xff\xfe"];
        yield 'a lone continuation byte' => ["seen \x80 here"];
        yield 'an overlong slash' => ["\xc0\xaf"];
        yield 'half a character' => ["caf\xc3"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reasonsThatAreNotText')]
    public function testAReasonThatIsNotTextIsSpelledBeforeTheTerminalSignsTheStop(string $reason): void
    {
        // The terminal signs a call before it runs, and what it signs is JSON: a reason carrying bytes that are not
        // text was refused THERE — «Malformed UTF-8» — before the house was asked, and the grant stayed in force.
        // So the house's terminal hands the signer the spelling of such a reason: what is signed is what is kept.
        $tokens = \Milpa\AppRuntime\Standing\StandingOperations::atATerminal('standing:suspend', ['--grant=release-line', '--reason=' . $reason, '--sign']);

        self::assertSame(['--grant=release-line', '--sign'], [$tokens[0], $tokens[2]]);
        self::assertTrue(mb_check_encoding($tokens[1], 'UTF-8'), 'the reason the signer is handed is still not text');
        self::assertNotFalse(json_encode(['reason' => substr($tokens[1], 9)]), 'the signer could not spell the call it is asked to sign');
        // …and the door takes that spelling as it takes any reason: the stop lands.
        $this->house->granted();
        $said = $this->house->signed(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => substr($tokens[1], 9)]);
        self::assertTrue($said['ok'] ?? null);
        self::assertSame(GrantState::Suspended, $this->state()?->state);
    }

    public function testAReasonThatIsTextAndEveryOtherCallReachTheSignerAsTheyWereTyped(): void
    {
        $typed = ['--grant=release-line', '--reason=seen: ñ 字', '--sign'];
        self::assertSame($typed, \Milpa\AppRuntime\Standing\StandingOperations::atATerminal('standing:suspend', $typed));
        $other = ['--path=' . "\xff", '--reason=' . "\xff"];
        self::assertSame($other, \Milpa\AppRuntime\Standing\StandingOperations::atATerminal('lab:scan', $other), 'another operation\'s tokens were rewritten');
    }

    public function testTheDoorSaysHowManyCharactersOfAReasonItKeptWhenTheCutEndsInASpace(): void
    {
        // A nit of the fifth review: 255 letters, a space, and more — the door said 256 were kept and 255 are.
        $this->house->granted();

        $said = $this->house->signed(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => str_repeat('a', 255) . ' ' . str_repeat('b', 50)]);

        self::assertSame(255, mb_strlen((string) $this->house->lines(StandingLedger::SUSPENDED)[0]['reason']));
        self::assertStringContainsString('the first 255 of the 306 characters', json_encode($said));
    }

    // ── M1's residue, executed by the fifth review on a real leg: a file of the unit loaded LATE ────────────────

    /** @return iterable<string, array{string}> how the instrument reaches the rule it applies */
    public static function waysAPluginLoadsAFileLate(): iterable
    {
        yield 'a class of the plugin, in a file of its own, first used when the instrument runs' => ['Rules::clean($bytes)'];
        yield 'a file of the plugin that is no class, required once when the instrument runs' => ["((require_once __DIR__ . '/rules.php') !== false) && clean_plain(\$bytes)"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('waysAPluginLoadsAFileLate')]
    public function testAYesIsNotWrittenByAProcessThatLoadedAFileOfTheInstrumentsPluginAfterItReadWhatTheInstrumentIs(string $rule): void
    {
        // A process reads what each operation is when it is told its catalogue. A file of the plugin it loads AFTER
        // that — softened in between and put back before it answers again — was run soft and pinned hard: the second
        // yes was written green under the grant's pin, and the act ran over a candidate the instrument refuses.
        $plugin = $this->aPluginWithItsRuleInAFileOfItsOwn($rule);
        $this->house->offer($plugin['scan']);
        $this->house->granted(['conditions' => [['name' => 'no-host-names', 'statement' => 'what is merged names no machine', 'by' => ['operation' => 'late:scan', 'free' => ['path']]]]]);
        $red = $this->house->candidate('red.txt', "machine-name: build-host-7\n");
        $this->house->aProcessPerCall = false;
        $this->house->boots();
        // Softened while the process lives, before it first uses that file…
        $hard = [(string) file_get_contents($plugin['dir'] . '/Rules.php'), (string) file_get_contents($plugin['dir'] . '/rules.php')];
        file_put_contents($plugin['dir'] . '/Rules.php', str_replace("!str_contains(\$bytes, 'machine-name:')", 'true', $hard[0]));
        file_put_contents($plugin['dir'] . '/rules.php', str_replace("!str_contains(\$bytes, 'machine-name:')", 'true', $hard[1]));
        $first = $this->house->as(LabHouse::SEAT, 'late:scan', ['path' => $red]);
        // …and put back, the same bytes, before it answers again.
        file_put_contents($plugin['dir'] . '/Rules.php', $hard[0]);
        file_put_contents($plugin['dir'] . '/rules.php', $hard[1]);

        $second = $this->house->as(LabHouse::SEAT, 'late:scan', ['path' => $red]);

        self::assertSame([], array_filter($this->house->lines(StandingLedger::MEASURED), static fn (array $receipt): bool => $receipt['ok'] === true), 'a yes was written under the grant\'s pin by a process running a softer rule than the disk holds: ' . json_encode([$first, $second]));
        self::assertArrayHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $red]));
        self::assertSame([], $this->house->done('merged'));
    }

    /** @return iterable<string, array{bool, int}> whether the file is put back before the process reads what the instrument is, and how many yeses are written */
    public static function whenAFileLoadedSoftIsPutBack(): iterable
    {
        yield 'put back BEFORE the process reads its pin — the window: SAID, NOT CLOSED' => [true, 1];
        yield 'put back AFTER the process read its pin — what it loaded is not what is on disk: it says nothing for a grant' => [false, 0];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('whenAFileLoadedSoftIsPutBack')]
    public function testAFileOfAPluginLoadedBeforeItsPinIsReadIsRunAsItWasLoaded(bool $backBeforeThePin, int $yeses): void
    {
        // THE SIXTH REVIEW'S 6-M3, MEASURED AND SAID — NOT CLOSED. A kernel loads a plugin's own file when it boots,
        // some tens of milliseconds before the process is told its catalogue and reads what each operation is. A
        // file softened, loaded, and put back INSIDE that window is run soft and pinned as it is on disk: the first
        // row is that, and it lends. Put back after the pin was read (the second row, and the cause of the «+0» the
        // review saw past the window): what the process loaded is not what the disk holds when the run ends, so its
        // yes is not written — and neither is anything else. Whether whoever can write a plugin's file is inside
        // what this judge promises is a person's question; no loader is built here to answer it.
        $plugin = $this->aPluginWithItsRuleInAFileOfItsOwn('Rules::clean($bytes)');
        $hard = (string) file_get_contents($plugin['dir'] . '/Rules.php');
        $soft = str_replace("!str_contains(\$bytes, 'machine-name:')", 'true', $hard);
        preg_match('/^namespace (.+);$/m', $hard, $found);
        file_put_contents($plugin['dir'] . '/Rules.php', $soft);
        self::assertTrue(class_exists($found[1] . '\\Rules'), 'control: the file is loaded, soft');
        file_put_contents($plugin['dir'] . '/Rules.php', $hard);
        $this->house->offer($plugin['scan']);
        $this->house->granted(['conditions' => [['name' => 'no-host-names', 'statement' => 'what is merged names no machine', 'by' => ['operation' => 'late:scan', 'free' => ['path']]]]]);
        $red = $this->house->candidate('red.txt', "machine-name: build-host-7\n");
        $this->house->aProcessPerCall = false;
        if (!$backBeforeThePin) {
            file_put_contents($plugin['dir'] . '/Rules.php', $soft);
        }
        $this->house->boots(); // the process is told its catalogue: it reads what the instrument is, from the disk
        file_put_contents($plugin['dir'] . '/Rules.php', $hard);

        $said = $this->house->as(LabHouse::SEAT, 'late:scan', ['path' => $red]);

        self::assertTrue($said['ok'] ?? false, 'control: the code this process runs is the soft one — it says yes of a candidate that names a machine');
        self::assertCount($yeses, $this->house->lines(StandingLedger::MEASURED), $yeses === 1 ? 'the window was closed: say so in the record of decisions/0615' : 'a yes was written by a process whose loaded file is not the one on disk');
        self::assertSame($yeses === 1, !isset($this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $red])['refused']));
    }

    public function testAPluginWhoseFilesAreAllLoadedWhenTheProcessIsToldStillMeasures(): void
    {
        // The guard: nothing changed on disk, the instrument's yes is a receipt as before.
        $plugin = $this->aPluginWithItsRuleInAFileOfItsOwn('Rules::clean($bytes)');
        $this->house->offer($plugin['scan']);
        $this->house->granted(['conditions' => [['name' => 'no-host-names', 'statement' => 'what is merged names no machine', 'by' => ['operation' => 'late:scan', 'free' => ['path']]]]]);
        $good = $this->house->candidate('good.txt', "clean release\n");
        $this->house->aProcessPerCall = false;
        $this->house->boots();

        $this->house->as(LabHouse::SEAT, 'late:scan', ['path' => $good]);

        self::assertSame([true], array_column($this->house->lines(StandingLedger::MEASURED), 'ok'));
        self::assertArrayNotHasKey('refused', $this->house->as(LabHouse::SEAT, 'lab:merge', ['path' => $good]));
    }

    /** @var list<\Closure> */
    private array $autoloaders = [];

    /**
     * A plugin written under this house whose instrument applies a rule that lives in ANOTHER file of the plugin —
     * a class the autoloader finds, and a plain file that is required.
     *
     * @return array{dir: string, scan: Operation}
     */
    private function aPluginWithItsRuleInAFileOfItsOwn(string $rule): array
    {
        $name = 'Late' . bin2hex(random_bytes(4));
        $dir = $this->house->root . '/packages/' . strtolower($name);
        $namespace = 'Lab\\Plugins\\' . $name;
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/Rules.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\nfinal class Rules\n{\n    public static function clean(string \$bytes): bool\n    {\n        return !str_contains(\$bytes, 'machine-name:');\n    }\n}\n");
        file_put_contents($dir . '/rules.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\nfunction clean_plain(string \$bytes): bool\n{\n    return !str_contains(\$bytes, 'machine-name:');\n}\n");
        file_put_contents($dir . '/Guard.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Milpa\Command\Effect\EffectProfile;
            use Milpa\Command\Operation;

            final class Guard
            {
                public function __construct(private readonly string \$root)
                {
                }

                public function scan(): Operation
                {
                    return new Operation('late:scan', 'Lab: measure a candidate by a rule in another file', fn (array \$input): array => \$this->measure((string) file_get_contents(\$this->root . '/' . \$input['path'])), ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']], scopes: ['lab:scan'], effects: EffectProfile::readOnly(), actsOn: fn (array \$input): ?string => is_file(\$this->root . '/' . (\$input['path'] ?? '')) ? 'sha256:' . hash_file('sha256', \$this->root . '/' . \$input['path']) : null);
                }

                private function measure(string \$bytes): array
                {
                    return ['ok' => {$rule}, 'acted_on' => 'sha256:' . hash('sha256', \$bytes)];
                }
            }
            PHP);
        $loader = static function (string $class) use ($namespace, $dir): void {
            $prefix = str_replace('\\\\', '\\', $namespace) . '\\';
            if (str_starts_with($class, $prefix) && is_file($dir . '/' . substr($class, \strlen($prefix)) . '.php')) {
                require $dir . '/' . substr($class, \strlen($prefix)) . '.php';
            }
        };
        spl_autoload_register($loader);
        $this->autoloaders[] = $loader;
        $guard = str_replace('\\\\', '\\', $namespace) . '\\Guard';

        return ['dir' => $dir, 'scan' => (new $guard($this->house->root))->scan()];
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
