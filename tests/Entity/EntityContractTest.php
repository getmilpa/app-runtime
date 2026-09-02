<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app INSTALLS, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Entity;

use Milpa\AppRuntime\Entity\EntityContract;
use PHPUnit\Framework\TestCase;

/**
 * D-04 (greenhouse decisions/0187): the entity's contract, proven by mutating it — not by reading
 * the `readonly` keyword. These cases hold the probe to that: the verdict is whatever PHP does to a
 * throwaway instance, so a class the house generated as `final readonly` reports immutable because
 * its second write throws the very Error the agent hit, and a plain one reports mutable.
 */
final class EntityContractTest extends TestCase
{
    public function testAReadonlyEntityIsImmutableAndReplaceSemantics(): void
    {
        $c = EntityContract::of(ImmutableTareaFixture::class);

        self::assertNotNull($c);
        self::assertSame(EntityContract::IMMUTABLE, $c->mutability);
        self::assertSame(EntityContract::REPLACE_ENTITY, $c->updateSemantics);
        self::assertTrue($c->probed, 'the verdict came from an executed mutation');
    }

    public function testAPlainEntityIsMutableAndInPlaceSemantics(): void
    {
        $c = EntityContract::of(MutableTareaFixture::class);

        self::assertNotNull($c);
        self::assertSame(EntityContract::MUTABLE, $c->mutability);
        self::assertSame(EntityContract::MUTATE_IN_PLACE, $c->updateSemantics);
    }

    /** The identity the entity persists by is `id` when it declares one — property or accessor. */
    public function testPersistenceIdentityIsIdWhenDeclared(): void
    {
        self::assertSame('id', EntityContract::of(ImmutableTareaFixture::class)?->persistenceIdentity);
        self::assertSame('id', EntityContract::of(EntityWithIdAccessorFixture::class)?->persistenceIdentity);
        self::assertNull(EntityContract::of(MutableTareaFixture::class)?->persistenceIdentity, 'no id declared, no identity guessed');
    }

    /**
     * THE PROOF IS EXECUTION, NOT THE KEYWORD (D³): the source never classifies by grepping the
     * `readonly` token — the verdict is the Error PHP throws (or does not). A readonly value type
     * (int) is immutable too: the probe writes a type-appropriate value, so it tests mutability, not
     * type compatibility.
     */
    public function testAReadonlyTypedEntityIsStillImmutable(): void
    {
        self::assertSame(EntityContract::IMMUTABLE, EntityContract::of(ImmutableTypedFixture::class)?->mutability);
    }

    public function testTheVerdictComesFromMutationNotFromReadingTheFlag(): void
    {
        $src = (string) file_get_contents((string) (new \ReflectionClass(EntityContract::class))->getFileName());
        self::assertStringNotContainsString('isReadOnly', $src, 'the contract must not read the readonly reflection flag — it must mutate and observe');
        self::assertStringContainsString('->setValue(', $src, 'the verdict is produced by an executed mutation');
    }

    public function testAMissingClassCannotBeVouchedFor(): void
    {
        self::assertNull(EntityContract::of('Milpa\\Nope\\DoesNotExist'));
        self::assertNull(EntityContract::of(''));
    }

    public function testAnInterfaceOrAbstractIsNotProbed(): void
    {
        self::assertNull(EntityContract::of(SomeEntityInterfaceFixture::class));
        self::assertNull(EntityContract::of(AbstractEntityFixture::class));
    }
}

// --- fixtures ---

final readonly class ImmutableTareaFixture
{
    public function __construct(public string $titulo = 'x')
    {
    }

    public function id(): string
    {
        return 't';
    }
}

final class MutableTareaFixture
{
    public string $titulo = 'x';
}

final readonly class ImmutableTypedFixture
{
    public function __construct(public int $n = 1)
    {
    }
}

final class EntityWithIdAccessorFixture
{
    public string $name = 'x';

    public function id(): string
    {
        return 'e';
    }
}

interface SomeEntityInterfaceFixture
{
    public function id(): string;
}

abstract class AbstractEntityFixture
{
    public string $x = '';
}
