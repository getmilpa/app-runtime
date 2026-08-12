<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Config\JudgeCeiling;
use Milpa\AppRuntime\Support\CatalogueBorrower;
use Milpa\AppRuntime\Support\Operations;
use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use PHPUnit\Framework\TestCase;

/**
 * The battery greenhouse evidence/0154 froze before this second pass existed.
 *
 * A provider is built in order to produce the catalogue, so when it declares its operations the
 * catalogue does not exist yet and a borrowed ceiling has nothing to borrow from. It borrows from an
 * empty one, GOV-05 makes that the maximum of every axis, and the operation asks for consent by not
 * knowing rather than by what the criterion permits.
 *
 * The second case is the control: a provider that asked for nothing must keep the ceiling it
 * declared. A pass that rebuilds everything could leave the catalogue coincidentally identical and
 * still look right — and if it touches a provider that borrows nothing, it is not a loan, it is a
 * rewrite.
 */
final class BorrowedCeilingTest extends TestCase
{
    /** 1 · the borrower gets the catalogue, and its ceiling is the join of the others. */
    public function testTheBorrowerEndsUpCarryingTheJoinOfTheRest(): void
    {
        $catalogo = Operations::withBorrowedCeilings(
            [$this->pesada(), $this->suave(), $this->prestataria()],
            [[new ProveedorQuePresta(), ['presta']]],
        );

        $techo = $this->porNombre($catalogo, 'presta')->effectCeiling();

        self::assertSame(Mutation::Persistent, $techo->mutation, 'no tomó lo más pesado del catálogo');
        self::assertSame(Authority::Privileged, $techo->authority);
        self::assertSame(Subject::Executable, $techo->subject);
    }

    /**
     * 2 · THE CONTROL: a provider that borrows nothing keeps exactly what it declared.
     *
     * Without this, a second pass that rewrote every operation would pass case one while silently
     * replacing ceilings nobody asked it to touch.
     */
    public function testWhatNobodyBorrowedIsLeftAlone(): void
    {
        $catalogo = Operations::withBorrowedCeilings(
            [$this->pesada(), $this->suave(), $this->prestataria()],
            [[new ProveedorQuePresta(), ['presta']]],
        );

        $suave = $this->porNombre($catalogo, 'suave')->effectCeiling();

        self::assertSame(Mutation::None, $suave->mutation, 'le reescribieron el techo a quien no pidió nada');
        self::assertSame(Authority::Read, $suave->authority);
    }

    /**
     * 3 · the loan EXCLUDES the borrower's own operations.
     *
     * Folding the borrower in is a fixed point that hands back the maximum: on the first pass its
     * ceiling is the conservative Unknown, join() only rises, and the answer is Unknown again.
     */
    public function testTheLoanDoesNotFoldInTheBorrower(): void
    {
        $catalogo = Operations::withBorrowedCeilings(
            [$this->suave(), $this->prestataria()],
            [[new ProveedorQuePresta(), ['presta']]],
        );

        $techo = $this->porNombre($catalogo, 'presta')->effectCeiling();

        self::assertTrue($techo->isFullyClassified(), 'se plegó a sí misma y regresó el máximo');
        self::assertSame(Subject::Configuration, $techo->subject, 'un catálogo suave no baja lo que el acto hace');
        self::assertSame(Authority::WriteAsUser, $techo->authority);
    }

    /**
     * 4 · with nothing else in the catalogue, the loan is still the maximum.
     *
     * Failing upwards is the only failure this axis can afford: an app that declares nothing gets a
     * ceiling that asks rather than one that assumes.
     */
    public function testWithNothingToBorrowFromTheCeilingIsTheMaximum(): void
    {
        $catalogo = Operations::withBorrowedCeilings(
            [$this->prestataria()],
            [[new ProveedorQuePresta(), ['presta']]],
        );

        // Unknown weighs the most on every axis, so joining it with what the act does IS the maximum.
        self::assertEquals(
            EffectProfile::unclassified(),
            $this->porNombre($catalogo, 'presta')->effectCeiling(),
        );
    }

    /** 5 · no borrowers at all and the catalogue comes back untouched. */
    public function testWithoutBorrowersNothingHappens(): void
    {
        $original = [$this->pesada(), $this->suave()];

        self::assertSame($original, Operations::withBorrowedCeilings($original, []));
    }

    /** @param list<Operation> $catalogo */
    private function porNombre(array $catalogo, string $nombre): Operation
    {
        foreach ($catalogo as $op) {
            if ($op->name === $nombre) {
                return $op;
            }
        }

        self::fail("no existe la operación «{$nombre}»");
    }

    private function pesada(): Operation
    {
        return new Operation(
            name: 'pesada',
            description: 'the heaviest thing this synthetic app can do',
            handler: static fn (): array => [],
            mutating: true,
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::ThirdParty,
                reversibility: Reversibility::ManualRecovery,
                authority: Authority::Privileged,
                subject: Subject::Executable,
            ),
        );
    }

    private function suave(): Operation
    {
        return new Operation(
            name: 'suave',
            description: 'a read that changes nothing',
            handler: static fn (): array => [],
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'reads only',
            ),
        );
    }

    private function prestataria(): Operation
    {
        return ProveedorQuePresta::conCatalogo([])->operations()[0];
    }
}

/** A provider whose single operation carries a ceiling borrowed from whatever it is handed. */
final class ProveedorQuePresta implements CommandProvider, CatalogueBorrower
{
    /** @var list<Operation> */
    private array $catalogue = [];

    /** @param list<Operation> $catalogue */
    public static function conCatalogo(array $catalogue): self
    {
        $p = new self();
        $p->catalogue = $catalogue;

        return $p;
    }

    /** @param list<Operation> $catalogue */
    public function withCatalogue(array $catalogue): self
    {
        return self::conCatalogo($catalogue);
    }

    /** What this operation does on its own, before borrowing anything. */
    private static function loQueHace(): EffectProfile
    {
        return new EffectProfile(
            mutation: Mutation::Persistent,
            externality: Externality::None,
            reversibility: Reversibility::Compensatable,
            authority: Authority::WriteAsUser,
            subject: Subject::Configuration,
        );
    }

    /** @return list<Operation> */
    public function operations(): array
    {
        return [new Operation(
            name: 'presta',
            description: 'an operation that borrows its ceiling from the catalogue',
            handler: static fn (): array => [],
            mutating: true,
            effects: self::loQueHace()->join(JudgeCeiling::prestado($this->catalogue)),
        )];
    }
}
