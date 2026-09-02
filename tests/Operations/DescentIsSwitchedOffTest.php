<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\CapabilityOperations;
use Milpa\Command\Operation;
use PHPUnit\Framework\TestCase;

/**
 * `capabilities:enable` keeps the install ceiling as its declaration and declares no descent.
 *
 * The dry-run ceiling is resolved only for that invocation by {@see \Milpa\AppRuntime\Operations\DryRunOperation};
 * it must not weaken the operation's declared profile or revive an uncertified descent.
 *
 * @internal
 */
final class DescentIsSwitchedOffTest extends TestCase
{
    public function testDryRunLeavesTheDeclaredDescentsUntouched(): void
    {
        $enable = null;
        foreach ((new CapabilityOperations())->operations() as $op) {
            if ($op instanceof Operation && $op->name === 'capabilities:enable') {
                $enable = $op;
            }
        }

        self::assertInstanceOf(Operation::class, $enable);
        self::assertSame(
            [],
            $enable->effects->descents,
            'a descent whose envelope does not cover what it promises may not lower any ceiling',
        );
    }
}
