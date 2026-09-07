<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Config\JudgeCeiling;
use Milpa\AppRuntime\Operations\ConfigOperations;
use Milpa\AppRuntime\Operations\SequenceOperations;
use Milpa\Container\DIContainer;
use Milpa\AppRuntime\Support\CatalogueBorrower;
use Milpa\AppRuntime\Support\Operations;
use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Consent\OperationId;
use Milpa\Command\Operation;
use PHPUnit\Framework\TestCase;

/**
 * A CEILING THAT DEPENDS ON THE ORDER OF A CONFIG FILE IS NOT A CEILING.
 *
 * Measured on a fresh app (greenhouse evidence/0559, then mutated): with `ConfigOperations` listed
 * before `SequenceOperations` in `config/operations.php`, `config:set` folded `sequence:run` at its
 * FIRST-PASS ceiling — the maximum — and came out unclassified on every axis. Swapping the two
 * lines made it classified. `evidence/0154` solved the fixed point of ONE borrower folding itself;
 * with two borrowers the same fixed point reappears BETWEEN them, and a single sequential pass
 * hands the second borrower's provisional maximum to the first.
 *
 * Two lab borrowers reproduce the two shipped shapes: one folds EVERYTHING it is lent (the way
 * `config:set` does), the other folds ONE NAMED operation and is the maximum when it cannot resolve
 * it (the way `sequence:run` folds its steps).
 */
final class TheLoanDoesNotDependOnProviderOrderTest extends TestCase
{
    /** F1 · the two orders yield the SAME catalogue, ceiling by ceiling. */
    public function testEitherOrderOfTwoBorrowersYieldsTheSameCeilings(): void
    {
        $forward = self::ceilings(Operations::withBorrowedCeilings(
            [self::heavy(), self::foldsAll(), self::foldsOne()],
            [[new FoldsEverythingLent(), ['folds.all']], [new FoldsOneNamedOperation('heavy'), ['folds.one']]],
        ));
        $backward = self::ceilings(Operations::withBorrowedCeilings(
            [self::heavy(), self::foldsAll(), self::foldsOne()],
            [[new FoldsOneNamedOperation('heavy'), ['folds.one']], [new FoldsEverythingLent(), ['folds.all']]],
        ));

        self::assertEquals($backward, $forward, 'the order of config/operations.php decided a ceiling');
    }

    /**
     * F2 · and neither order leaves the all-folding borrower unclassified: the other borrower's
     * ceiling is DERIVED (heavy, classified), so what it is lent is classified too.
     *
     * Control: the one-step borrower resolves `heavy` and is classified in both orders as well.
     */
    public function testABorrowerLentAnotherBorrowerIsLentItsDerivedCeilingNotItsProvisionalMaximum(): void
    {
        foreach ([
            [[new FoldsEverythingLent(), ['folds.all']], [new FoldsOneNamedOperation('heavy'), ['folds.one']]],
            [[new FoldsOneNamedOperation('heavy'), ['folds.one']], [new FoldsEverythingLent(), ['folds.all']]],
        ] as $borrowers) {
            $catalogue = Operations::withBorrowedCeilings([self::heavy(), self::foldsAll(), self::foldsOne()], $borrowers);

            $all = self::byName($catalogue, 'folds.all')->effectCeiling();
            $one = self::byName($catalogue, 'folds.one')->effectCeiling();

            self::assertTrue($all->isFullyClassified(), 'lent a provisional maximum instead of a derived ceiling');
            self::assertSame(Reversibility::Irreversible, $all->reversibility, 'the heaviest thing it can reach');
            self::assertTrue($one->isFullyClassified());
            self::assertSame(Reversibility::Irreversible, $one->reversibility);
        }
    }

    /**
     * F3 · THE CONTROL THAT KEEPS THIS FROM BEING A PERMISSIVE DEFAULT: a borrower naming an
     * operation that nobody offers is STILL the maximum, in either order, and the all-folding
     * borrower lent it is the maximum too — an unbounded step makes everything that can reach it
     * unbounded. The solver never publishes below floor ⊔ what the act can reach (the rise check,
     * GOV-14), and what nobody classified stays the maximum for itself and for whoever reaches it.
     * Relative to the single pass it lowers exactly one thing: the provisional maxima that were
     * never derived from anything.
     */
    public function testAnUnresolvableStepIsStillTheMaximumAndSoIsWhoeverCanReachIt(): void
    {
        foreach ([
            [[new FoldsEverythingLent(), ['folds.all']], [new FoldsOneNamedOperation('nobody.has.this'), ['folds.one']]],
            [[new FoldsOneNamedOperation('nobody.has.this'), ['folds.one']], [new FoldsEverythingLent(), ['folds.all']]],
        ] as $borrowers) {
            $catalogue = Operations::withBorrowedCeilings([self::heavy(), self::foldsAll(), self::foldsOne()], $borrowers);

            self::assertFalse(self::byName($catalogue, 'folds.one')->effectCeiling()->isFullyClassified());
            self::assertFalse(self::byName($catalogue, 'folds.all')->effectCeiling()->isFullyClassified(), 'reaching an unbounded step and staying bounded is a permissive default');
        }
    }

    /**
     * F4 · THE REAL FIXED POINT, not idempotence: hand every borrower the RESULT (minus its own) and it
     * answers exactly what the result says. Today's single pass fails this in the Config-first order —
     * config:set carries sequence:run's provisional maximum while sequence:run, asked against the
     * result, answers something classified. Re-running the pass would hide that (it re-seeds), so
     * pass∘pass idempotence is NOT the falsifier.
     */
    public function testEveryBorrowerReproducesTheResultWhenHandedIt(): void
    {
        $borrowers = [[new FoldsEverythingLent(), ['folds.all']], [new FoldsOneNamedOperation('heavy'), ['folds.one']]];
        $result = Operations::withBorrowedCeilings([self::heavy(), self::foldsAll(), self::foldsOne()], $borrowers);

        foreach ($borrowers as [$borrower, $mine]) {
            $theirs = array_values(array_filter($result, static fn (Operation $op): bool => !\in_array($op->name, $mine, true)));
            foreach ($borrower->withCatalogue($theirs)->operations() as $op) {
                self::assertEquals(
                    self::byName($result, $op->name)->effectCeiling()->toArray(),
                    $op->effectCeiling()->toArray(),
                    "{$op->name} is not a fixed point of its own loan",
                );
            }
        }
    }

    /**
     * F5 · A CYCLE IS A CLOSURE, NOT A MAXIMUM. The one-step borrower names the all-folding one, which in
     * turn folds it: each can reach the other, and both can reach `heavy`. The least fixed point is the
     * closure — irreversible, CLASSIFIED — in either order. A fixed point iterated down from the maximum
     * would have stayed there and called the cycle «unknown» while everything in it is declared by hand.
     *
     * Control: cut the cycle (the step is a read) and the one-step borrower keeps exactly its floor —
     * the raise was the cycle's.
     */
    public function testACycleBetweenBorrowersSettlesAtTheClosureNotAtTheMaximum(): void
    {
        foreach ([
            [[new FoldsEverythingLent(), ['folds.all']], [new FoldsOneNamedOperation('folds.all'), ['folds.one']]],
            [[new FoldsOneNamedOperation('folds.all'), ['folds.one']], [new FoldsEverythingLent(), ['folds.all']]],
        ] as $borrowers) {
            $catalogue = Operations::withBorrowedCeilings([self::heavy(), self::foldsAll(), self::foldsOne()], $borrowers);
            foreach (['folds.all', 'folds.one'] as $name) {
                $c = self::byName($catalogue, $name)->effectCeiling();
                self::assertTrue($c->isFullyClassified(), "{$name}: a cycle of hand-classified acts came out unknown");
                self::assertSame(Reversibility::Irreversible, $c->reversibility, "{$name}: the closure reaches heavy");
                self::assertSame(Subject::Executable, $c->subject);
            }
        }

        $cut = Operations::withBorrowedCeilings(
            [self::heavy(), self::read('quiet'), self::foldsAll(), self::foldsOne()],
            [[new FoldsEverythingLent(), ['folds.all']], [new FoldsOneNamedOperation('quiet'), ['folds.one']]],
        );
        self::assertSame(Reversibility::ManualRecovery, self::byName($cut, 'folds.one')->effectCeiling()->reversibility, 'with the cycle cut the floor must stand alone');
    }

    /**
     * F6 · THE SHAPE THAT PROVED SLICE 0 WAS A PREREQUISITE: an operation classified on four axes and
     * unknown on reversibility, reachable through a step. Both borrower orders — and both catalogue
     * orders — must agree, and both borrowed ops must stay unknown on reversibility. With `Unknown`
     * level with `Irreversible` (command 0.25.1) the Config-first order answered «irreversible,
     * classified»: the unknown hid behind the known label.
     */
    public function testAPartiallyClassifiedStepStaysUnknownInEveryOrder(): void
    {
        $partial = new Operation(
            name: 'partial',
            effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Unknown, Authority::WriteAsUser, subject: Subject::Data),
            description: 'x',
            handler: static fn (): array => [],
            inputSchema: ['type' => 'object'],
            mutating: true,
        );
        $seen = [];
        foreach ([[self::heavy(), $partial], [$partial, self::heavy()]] as $others) {
            foreach ([
                [[new FoldsEverythingLent(), ['folds.all']], [new FoldsOneNamedOperation('partial'), ['folds.one']]],
                [[new FoldsOneNamedOperation('partial'), ['folds.one']], [new FoldsEverythingLent(), ['folds.all']]],
            ] as $borrowers) {
                $catalogue = Operations::withBorrowedCeilings([...$others, self::foldsAll(), self::foldsOne()], $borrowers);
                $seen[] = self::ceilings($catalogue);
                foreach (['folds.all', 'folds.one'] as $name) {
                    self::assertSame(Reversibility::Unknown, self::byName($catalogue, $name)->effectCeiling()->reversibility, "{$name}: the unknown hid behind a known label");
                }
            }
        }
        self::assertCount(1, array_unique(array_map('json_encode', $seen)), 'four orders, more than one answer');
    }

    /**
     * F7 · SILENCE IS NOT A FLOOR. A borrower whose floor is unknown on one axis seeds at the maximum and
     * stays there in either order — and whoever can reach it is the maximum too. Control: the same
     * borrower with a classified floor derives.
     */
    public function testAFloorWithAnUnknownAxisSeedsAtTheMaximumAndDragsWhoeverReachesIt(): void
    {
        $silent = new EffectProfile(Mutation::Persistent, Externality::Unknown, Reversibility::Compensatable, Authority::WriteAsUser, subject: Subject::Data);
        foreach ([true, false] as $forward) {
            $borrowers = [[new FoldsEverythingLent(), ['folds.all']], [new WithAGivenFloor($silent), ['given']]];
            $catalogue = Operations::withBorrowedCeilings(
                [self::heavy(), self::foldsAll(), (new WithAGivenFloor($silent))->operations()[0]],
                $forward ? $borrowers : array_reverse($borrowers),
            );
            self::assertFalse(self::byName($catalogue, 'given')->effectCeiling()->isFullyClassified());
            self::assertFalse(self::byName($catalogue, 'folds.all')->effectCeiling()->isFullyClassified(), 'reached a silent floor and stayed bounded');
        }

        $spoken = new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Compensatable, Authority::WriteAsUser, subject: Subject::Data);
        $catalogue = Operations::withBorrowedCeilings(
            [self::heavy(), self::foldsAll(), (new WithAGivenFloor($spoken))->operations()[0]],
            [[new FoldsEverythingLent(), ['folds.all']], [new WithAGivenFloor($spoken), ['given']]],
        );
        self::assertTrue(self::byName($catalogue, 'given')->effectCeiling()->isFullyClassified(), 'the control: a spoken floor derives');
        self::assertTrue(self::byName($catalogue, 'folds.all')->effectCeiling()->isFullyClassified());
    }

    /** F8a · a borrower whose floor names other operations than it contributed is refused BY NAME. */
    public function testAFloorForOtherOperationsIsRefusedByName(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('operationsAtTheFloor() names [folds.all] but operations() contributed [somebody.else]');

        Operations::withBorrowedCeilings([self::heavy(), self::foldsAll()], [[new FoldsEverythingLent(), ['somebody.else']]]);
    }

    /**
     * F8b · A LOAN THAT COMES DOWN IS REFUSED, in either order and naming the borrower and the operation.
     * Control: the monotone shapes above never trip it.
     */
    public function testABorrowerWhoseCeilingComesDownBetweenRoundsIsRefusedByName(): void
    {
        foreach ([true, false] as $forward) {
            $borrowers = [[new FoldsEverythingLent(), ['folds.all']], [new AnswersLowerOnceLent(), ['lower']]];
            try {
                Operations::withBorrowedCeilings(
                    [self::heavy(), self::foldsAll(), (new AnswersLowerOnceLent())->operationsAtTheFloor()[0]],
                    $forward ? $borrowers : array_reverse($borrowers),
                );
                self::fail('a descending loan was accepted');
            } catch (\LogicException $e) {
                self::assertStringContainsString(AnswersLowerOnceLent::class, $e->getMessage());
                self::assertStringContainsString('`lower` came DOWN', $e->getMessage());
            }
        }
    }

    /**
     * F8c · TWO BORROWERS THAT NEVER SETTLE hit the bound and are refused, rather than looping. Each
     * answers one escalator more than it sees on the other, so every round both grow by one: a rise
     * that never stops. (One alone cannot: its own operation is excluded from its view, so it sees
     * nothing to outgrow.) Control: the monotone shapes above settle in a handful of rounds.
     */
    public function testBorrowersThatNeverSettleAreRefusedAtTheBound(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('did not settle');

        Operations::withBorrowedCeilings(
            [self::heavy(), (new OutgrowsTheOther('mint.a', 'mint.b'))->operationsAtTheFloor()[0], (new OutgrowsTheOther('mint.b', 'mint.a'))->operationsAtTheFloor()[0]],
            [[new OutgrowsTheOther('mint.a', 'mint.b'), ['mint.a']], [new OutgrowsTheOther('mint.b', 'mint.a'), ['mint.b']]],
        );
    }

    /**
     * F9 · THE SHIPPED FLOORS ARE REAL: same names as `operations()`, fully classified, for both
     * providers the framework ships. `config:set` folds the whole catalogue, so against a heavy one its
     * loan sits strictly above its floor; `sequence:run` folds only declared steps, so with none
     * declared its loan IS its floor — «a floor with nothing to raise it», not a silent one.
     */
    public function testTheShippedProvidersDeclareRealFloors(): void
    {
        $config = ConfigOperations::para(sys_get_temp_dir(), [self::heavy()]);
        $sequence = new SequenceOperations(new DIContainer());

        foreach ([$config, $sequence] as $provider) {
            $floor = $provider->operationsAtTheFloor();
            self::assertSame(
                array_map(static fn (Operation $o): string => $o->name, $provider->operations()),
                array_map(static fn (Operation $o): string => $o->name, $floor),
                $provider::class . ': the floor is for other operations',
            );
            foreach ($floor as $op) {
                self::assertTrue($op->effectCeiling()->isFullyClassified(), $provider::class . ": {$op->name} has a silent floor");
            }
        }

        $set = self::byName($config->operationsAtTheFloor(), 'config:set')->effectCeiling();
        self::assertSame(Reversibility::Compensatable, $set->reversibility, 'what writing a key does alone');
        self::assertSame(Reversibility::Irreversible, self::byName($config->operations(), 'config:set')->effectCeiling()->reversibility, 'the loan raised it');

        $run = self::byName($sequence->withCatalogue([self::heavy()])->operations(), 'sequence:run')->effectCeiling();
        self::assertEquals(self::byName($sequence->operationsAtTheFloor(), 'sequence:run')->effectCeiling()->toArray(), $run->toArray(), 'no sequence declared: the loan is exactly the floor');
    }

    /** @param list<Operation> $catalogue @return array<string, array<string, string>> */
    private static function ceilings(array $catalogue): array
    {
        $out = [];
        foreach ($catalogue as $op) {
            $c = $op->effectCeiling();
            $out[$op->name] = [
                'mutation' => $c->mutation->name, 'externality' => $c->externality->name,
                'reversibility' => $c->reversibility->name, 'authority' => $c->authority->name, 'subject' => $c->subject->name,
            ];
        }
        ksort($out);

        return $out;
    }

    /** @param list<Operation> $catalogue */
    private static function byName(array $catalogue, string $name): Operation
    {
        foreach ($catalogue as $op) {
            if ($op->name === $name) {
                return $op;
            }
        }
        self::fail("no operation named {$name}");
    }

    private static function read(string $name): Operation
    {
        return new Operation(name: $name, effects: EffectProfile::readOnly(), description: 'x', handler: static fn (): array => [], inputSchema: ['type' => 'object']);
    }

    private static function heavy(): Operation
    {
        return new Operation(
            name: 'heavy',
            effects: new EffectProfile(Mutation::Persistent, Externality::ThirdParty, Reversibility::Irreversible, Authority::Privileged, subject: Subject::Executable),
            description: 'x',
            handler: static fn (): array => [],
            inputSchema: ['type' => 'object'],
            mutating: true,
        );
    }

    private static function foldsAll(): Operation
    {
        return (new FoldsEverythingLent())->operations()[0];
    }

    private static function foldsOne(): Operation
    {
        return (new FoldsOneNamedOperation('heavy'))->operations()[0];
    }
}

/** Folds EVERYTHING it is lent over its own floor — the shape of `config:set`. */
final class FoldsEverythingLent implements CommandProvider, CatalogueBorrower
{
    /** @param list<Operation> $lent */
    public function __construct(private readonly array $lent = [])
    {
    }

    public function withCatalogue(array $catalogue): self
    {
        return new self($catalogue);
    }

    public function operations(): array
    {
        return self::build(self::floor()->join(JudgeCeiling::prestado($this->lent)));
    }

    public function operationsAtTheFloor(): array
    {
        return self::build(self::floor());
    }

    private static function floor(): EffectProfile
    {
        return new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Compensatable, Authority::WriteAsUser, subject: Subject::Configuration);
    }

    /** @return list<Operation> */
    private static function build(EffectProfile $ceiling): array
    {
        return [new Operation(
            name: 'folds.all',
            effects: $ceiling,
            description: 'x',
            handler: static fn (): array => [],
            inputSchema: ['type' => 'object'],
            mutating: true,
        )];
    }
}

/** Folds ONE named operation over its own floor, the maximum when it cannot resolve it — the shape of `sequence:run`. */
final class FoldsOneNamedOperation implements CommandProvider, CatalogueBorrower
{
    /** @param ?list<Operation> $lent */
    public function __construct(private readonly string $step, private readonly ?array $lent = null)
    {
    }

    public function withCatalogue(array $catalogue): self
    {
        return new self($this->step, $catalogue);
    }

    public function operations(): array
    {
        $resolved = null;
        foreach ($this->lent ?? [] as $op) {
            if ((new OperationId($this->step))->is($op->name)) {
                $resolved = $op;
            }
        }

        return self::build(self::floor()->join($resolved?->effectCeiling() ?? EffectProfile::unclassified()));
    }

    public function operationsAtTheFloor(): array
    {
        return self::build(self::floor());
    }

    private static function floor(): EffectProfile
    {
        return new EffectProfile(Mutation::Persistent, Externality::ThirdParty, Reversibility::ManualRecovery, Authority::Privileged, subject: Subject::Executable);
    }

    /** @return list<Operation> */
    private static function build(EffectProfile $ceiling): array
    {
        return [new Operation(
            name: 'folds.one',
            effects: $ceiling,
            description: 'x',
            handler: static fn (): array => [],
            inputSchema: ['type' => 'object'],
            mutating: true,
        )];
    }
}

/** A borrower with whatever floor the test hands it, folding everything it is lent. */
final class WithAGivenFloor implements CommandProvider, CatalogueBorrower
{
    /** @param list<Operation> $lent */
    public function __construct(private readonly EffectProfile $floor, private readonly array $lent = [])
    {
    }

    public function withCatalogue(array $catalogue): self
    {
        return new self($this->floor, $catalogue);
    }

    public function operations(): array
    {
        return [new Operation(name: 'given', effects: $this->floor->join(JudgeCeiling::prestado($this->lent)), description: 'x', handler: static fn (): array => [], inputSchema: ['type' => 'object'], mutating: true)];
    }

    public function operationsAtTheFloor(): array
    {
        return [new Operation(name: 'given', effects: $this->floor, description: 'x', handler: static fn (): array => [], inputSchema: ['type' => 'object'], mutating: true)];
    }
}

/** The defect the solver must refuse: a floor that is HIGHER than what the loan answers. */
final class AnswersLowerOnceLent implements CommandProvider, CatalogueBorrower
{
    public function __construct(private readonly bool $lent = false)
    {
    }

    public function withCatalogue(array $catalogue): self
    {
        return new self(true);
    }

    public function operations(): array
    {
        $reversibility = $this->lent ? Reversibility::Compensatable : Reversibility::Irreversible;

        return [new Operation(name: 'lower', effects: new EffectProfile(Mutation::Persistent, Externality::None, $reversibility, Authority::WriteAsUser, subject: Subject::Data), description: 'x', handler: static fn (): array => [], inputSchema: ['type' => 'object'], mutating: true)];
    }

    public function operationsAtTheFloor(): array
    {
        return (new self(false))->operations();
    }
}

/** A borrower that never settles: one escalator more than the other one carries, every round. */
final class OutgrowsTheOther implements CommandProvider, CatalogueBorrower
{
    /** @param list<string> $escalators */
    public function __construct(private readonly string $name, private readonly string $other, private readonly array $escalators = [])
    {
    }

    public function withCatalogue(array $catalogue): self
    {
        $seen = [];
        foreach ($catalogue as $op) {
            if ($op->name === $this->other) {
                $seen = $op->effectCeiling()->escalatesOn;
            }
        }

        // Everything the other carries (it copied mine, so this is a superset of what I answered last
        // round) plus ONE more of my own: monotone, one bigger per round, never settling — and linear,
        // because a double that doubles every round exhausts memory before the bound.
        $own = \count(array_filter($seen, fn (string $e): bool => str_starts_with($e, $this->name . '-')));

        return new self($this->name, $this->other, array_values(array_unique([...$seen, $this->name . '-' . $own])));
    }

    public function operations(): array
    {
        return [new Operation(name: $this->name, effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Compensatable, Authority::WriteAsUser, subject: Subject::Data, escalatesOn: $this->escalators), description: 'x', handler: static fn (): array => [], inputSchema: ['type' => 'object'], mutating: true)];
    }

    public function operationsAtTheFloor(): array
    {
        return (new self($this->name, $this->other))->operations();
    }
}
