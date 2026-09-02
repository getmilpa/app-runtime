<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Entity\EntityContract;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\Mutation;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * D-04 (greenhouse decisions/0187): entity:contract exposes a generated entity's semantics so an
 * implementation is judged against what the house created. The probe is proven in EntityContractTest;
 * this pins the operation's wiring — read-only contract, the missing-class refusal, and that a real
 * class flows through to the projected mutability.
 */
final class EntityContractReadTest extends TestCase
{
    public function testItReadsAndReachesNobodyAndChangesNothing(): void
    {
        $op = $this->find();
        self::assertSame(Mutation::None, $op->effects->mutation);
        self::assertSame(Authority::Read, $op->effects->authority);
        self::assertFalse($op->requiresConfirmation);
    }

    public function testWithoutAClassNothingIsRead(): void
    {
        $r = $this->call([]);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('required', (string) $r['error']);
    }

    public function testAClassItCannotVouchForSaysSo(): void
    {
        $r = $this->call(['class' => 'Milpa\\Nope\\Missing']);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('cannot vouch', (string) $r['error']);
    }

    public function testAReadonlyEntityFlowsThroughAsImmutable(): void
    {
        $r = $this->call(['class' => EntityContractReadFixture::class]);

        self::assertTrue($r['ok']);
        self::assertSame(EntityContract::IMMUTABLE, $r['mutability']);
        self::assertSame(EntityContract::REPLACE_ENTITY, $r['updateSemantics']);
        self::assertSame('id', $r['persistenceIdentity']);
    }

    // --- helpers ---

    private function find(): \Milpa\Command\Operation
    {
        foreach ((new AgentOperations(new DIContainer()))->operations() as $op) {
            if ($op->name === 'entity:contract') {
                return $op;
            }
        }
        self::fail('entity:contract is not offered');
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function call(array $input): array
    {
        /** @var array<string, mixed> */
        return ($this->find()->handler)($input);
    }
}

final readonly class EntityContractReadFixture
{
    public function __construct(public string $titulo = 'x')
    {
    }

    public function id(): string
    {
        return 't';
    }
}
