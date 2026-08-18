<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\CapabilityOperations;
use Milpa\Command\Operation;
use PHPUnit\Framework\TestCase;

/**
 * `capabilities:enable` declares no descent while nothing can hold one up.
 *
 * greenhouse decisions/0049. Its certificate covers `mutation` and explicitly does NOT cover
 * `externality`, which is what the descent promised — and evidence/0242 measured why: the network is
 * observed by difference, and difference cannot tell «does not reach out» from «reaches out and
 * swallows the error». Not partial coverage: not knowing.
 *
 * This test exists so the descent cannot come back in silence. It comes back by being CERTIFIED,
 * which is how decisions/0045 said a descent is earned.
 *
 * @internal
 */
final class DescentIsSwitchedOffTest extends TestCase
{
    public function testCapabilitiesEnableDeclaresNoDescentWhileNoneCanBeCertified(): void
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
