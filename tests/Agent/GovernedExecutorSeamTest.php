<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\GovernedExecutor;
use PHPUnit\Framework\TestCase;

final class GovernedExecutorSeamTest extends TestCase
{
    public function testConsentBridgeIsAGovernedExecutor(): void
    {
        self::assertTrue(
            is_subclass_of(ConsentBridge::class, GovernedExecutor::class),
            'ConsentBridge must expose the governed executor seam the runner depends on',
        );
    }

    public function testAFakeCanStandInForTheExecutor(): void
    {
        $fake = new class implements GovernedExecutor {
            public function callTool(string $operation, array $arguments): mixed
            {
                return ['ok' => true, 'echo' => $operation];
            }
        };
        self::assertSame(['ok' => true, 'echo' => 'x'], $fake->callTool('x', []));
    }
}
