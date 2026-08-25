<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\{GovernedSequenceRunner, GovernedExecutor, SequenceStep, StepStatus};
use PHPUnit\Framework\TestCase;

final class GovernedSequenceRunnerTest extends TestCase
{
    public function testItRunsEveryStepInOrderThroughTheExecutor(): void
    {
        $seen = [];
        $executor = new class($seen) implements GovernedExecutor {
            /** @param list<string> $seen */
            public function __construct(public array &$seen) {}
            public function callTool(string $operation, array $arguments): mixed
            {
                $this->seen[] = $operation;
                return ['ok' => true, 'op' => $operation];
            }
        };

        $result = (new GovernedSequenceRunner())->run([
            new SequenceStep('a', []),
            new SequenceStep('b', ['x' => 1]),
            new SequenceStep('c', []),
        ], $executor);

        self::assertSame(['a', 'b', 'c'], $executor->seen, 'steps run in declared order');
        self::assertTrue($result->completed());
        self::assertSame(3, $result->executedCount());
        self::assertSame(StepStatus::Executed, $result->outcomes[1]->status);
        self::assertSame(['ok' => true, 'op' => 'b'], $result->outcomes[1]->result);
    }
}
