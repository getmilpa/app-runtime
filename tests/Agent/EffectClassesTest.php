<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\EffectClasses;
use PHPUnit\Framework\TestCase;

/**
 * The effect classes an operator may withdraw, named once.
 *
 * Measured on cattle: `--denyEffects=mutates` withdrew nothing, errored nothing and said nothing.
 * `mutates` is not a class — the classes are mutating, external, irreversible and authority — so
 * whoever typed it asked to withdraw an entire class of operations and believed it had happened.
 * A flag that accepts what it does not understand is a law without a mechanism.
 */
final class EffectClassesTest extends TestCase
{
    public function testTheClassesAreTheFourThatExist(): void
    {
        self::assertSame(['mutating', 'external', 'irreversible', 'authority'], EffectClasses::all());
    }

    public function testAClassNobodyDefinedIsReportedBack(): void
    {
        self::assertSame(['mutates'], EffectClasses::unknownIn('mutates'));
    }

    /**
     * The real one passing does not excuse the invented one. A partial understanding that proceeds
     * silently is worse than a refusal: the operator sees SOME withdrawal happen and concludes the
     * whole instruction landed.
     */
    public function testOneGoodClassDoesNotSmuggleABadOneThrough(): void
    {
        self::assertSame(['mutates'], EffectClasses::unknownIn('mutating,mutates'));
    }

    public function testTheKnownOnesAreAccepted(): void
    {
        self::assertSame([], EffectClasses::unknownIn('mutating,external,irreversible,authority'));
    }

    public function testSpacingAndCaseAreNotTypos(): void
    {
        self::assertSame([], EffectClasses::unknownIn(' Mutating , EXTERNAL '));
    }

    public function testNothingAskedIsNothingUnknown(): void
    {
        self::assertSame([], EffectClasses::unknownIn(null));
        self::assertSame([], EffectClasses::unknownIn(''));
    }

    public function testItAlsoTakesTheListAProgrammaticCallerHandsOver(): void
    {
        self::assertSame(['inventada'], EffectClasses::unknownIn(['mutating', 'inventada']));
    }

    /**
     * The refusal names what it did not understand AND what it would have understood — the house
     * already settled that shape for unknown roles, and a refusal that only says «no» leaves the
     * operator guessing at a spelling.
     */
    public function testTheRefusalNamesBothTheBadOneAndTheRealOnes(): void
    {
        $mensaje = EffectClasses::refusal(['mutates']);

        self::assertStringContainsString('mutates', $mensaje);
        foreach (EffectClasses::all() as $clase) {
            self::assertStringContainsString($clase, $mensaje);
        }
    }

    /**
     * THE DUPLICATION THIS REMOVES.
     *
     * The four classes were written twice — in the matcher's `match` and in the schema description an
     * agent reads. A third copy would be worse, and two already disagree the day one of them changes.
     * The convention is called, never copied (greenhouse evidence/0141).
     */
    public function testTheSchemaDescriptionIsBuiltFromTheSameList(): void
    {
        foreach (EffectClasses::all() as $clase) {
            self::assertStringContainsString($clase, EffectClasses::describe());
        }
    }
}
