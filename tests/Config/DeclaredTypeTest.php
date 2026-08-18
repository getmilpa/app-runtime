<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Config;

use Milpa\AppRuntime\Config\DeclaredType;
use PHPUnit\Framework\TestCase;

final class DeclaredTypeTest extends TestCase
{
    public function testPrimitiveTypesAcceptOnlyTheirExactTransportSpellings(): void
    {
        self::assertSame('instruction', DeclaredType::text()->coerce('instruction'));
        self::assertSame(12, DeclaredType::integer()->coerce(12));
        self::assertTrue(DeclaredType::boolean()->coerce(true));
        self::assertTrue(DeclaredType::boolean()->coerce('true'));

        $this->assertRejected(
            static fn (): mixed => DeclaredType::text()->coerce(12),
            'expected string',
        );
        $this->assertRejected(
            static fn (): mixed => DeclaredType::integer()->coerce('1e2'),
            'base-10 integer',
        );
        $this->assertRejected(
            static fn (): mixed => DeclaredType::boolean()->coerce('yes'),
            'exact text `true` or `false`',
        );
    }

    public function testLiteralsAndUnionsKeepTheSelectedMemberType(): void
    {
        $type = DeclaredType::union(
            DeclaredType::literal(true),
            DeclaredType::literal('pointer'),
            DeclaredType::literal(false),
        );

        self::assertTrue($type->coerce('true'));
        self::assertSame('pointer', $type->coerce('pointer'));
        self::assertFalse($type->coerce(false));
        $this->assertRejected(static fn (): mixed => $type->coerce('summary'), "'pointer'");
        $this->assertRejected(static fn (): mixed => DeclaredType::union(), 'requires at least one member');
    }

    public function testAnOpenArrayPreservesNestedJsonContainerKinds(): void
    {
        $type = DeclaredType::anyArray();
        $value = $type->coerce('{"empty":{},"items":[1]}');

        self::assertIsArray($value);
        self::assertInstanceOf(\stdClass::class, $value['empty']);
        self::assertSame([1], $value['items']);
        $this->assertRejected(static fn (): mixed => $type->coerce('false'), 'JSON array or object');
        $this->assertRejected(static fn (): mixed => $type->coerce('{broken'), 'not valid JSON');
    }

    public function testAListRejectsTheWrongContainerAndTheWrongItemType(): void
    {
        $type = DeclaredType::listOf(DeclaredType::text());

        self::assertSame(['one', 'two'], $type->coerce('["one","two"]'));
        $this->assertRejected(static fn (): mixed => $type->coerce('{"one":"two"}'), 'expected a JSON list');
        $this->assertRejected(static fn (): mixed => $type->coerce('["one",2]'), 'item 1 does not conform');
    }

    public function testAMapValidatesItsContainerKeysAndValues(): void
    {
        $type = DeclaredType::mapOf(DeclaredType::text(), DeclaredType::listOf(DeclaredType::text()));

        self::assertSame(['denied' => ['safe']], $type->coerce('{"denied":["safe"]}'));
        self::assertInstanceOf(\stdClass::class, $type->coerce('{}'));
        $this->assertRejected(static fn (): mixed => $type->coerce('[]'), 'expected a JSON object');
        $this->assertRejected(static fn (): mixed => $type->coerce(['a']), 'expected a JSON object');
        $this->assertRejected(
            static fn (): mixed => $type->coerce([0 => [], 'named' => []]),
            "map key '0' does not conform",
        );
        $this->assertRejected(
            static fn (): mixed => $type->coerce(['denied' => ['safe', 2]]),
            "map entry 'denied' does not conform",
        );
    }

    public function testAShapeRejectsUnknownMissingAndMistypedFields(): void
    {
        $type = DeclaredType::shape([
            'count' => ['type' => DeclaredType::integer()],
        ]);

        self::assertSame(['count' => 2], $type->coerce('{"count":2}'));
        $this->assertRejected(static fn (): mixed => $type->coerce('{"extra":2}'), "field 'extra' is not declared");
        $this->assertRejected(static fn (): mixed => $type->coerce('{}'), "required field 'count' is missing");
        $this->assertRejected(static fn (): mixed => $type->coerce('{"count":"2"}'), "field 'count' does not conform");
    }

    /** @param callable(): mixed $operation */
    private function assertRejected(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail('The value should have been rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
    }
}
