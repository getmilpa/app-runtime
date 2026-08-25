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
        $executor = new class ($seen) implements GovernedExecutor {
            /** @param list<string> $seen */
            public function __construct(public array &$seen)
            {
            }
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

    public function testItStopsFailClosedAtARefusedStep(): void
    {
        $seen = [];
        $executor = new class ($seen) implements \Milpa\AppRuntime\Agent\GovernedExecutor {
            /** @param list<string> $seen */
            public function __construct(public array &$seen)
            {
            }
            public function callTool(string $operation, array $arguments): mixed
            {
                $this->seen[] = $operation;
                if ($operation === 'b') {
                    throw new \Milpa\AiGateway\ToolCallRefusedException('needs your authorization');
                }
                return ['ok' => true];
            }
        };

        $result = (new \Milpa\AppRuntime\Agent\GovernedSequenceRunner())->run([
            new \Milpa\AppRuntime\Agent\SequenceStep('a', []),
            new \Milpa\AppRuntime\Agent\SequenceStep('b', []),
            new \Milpa\AppRuntime\Agent\SequenceStep('c', []),
        ], $executor);

        self::assertSame(['a', 'b'], $executor->seen, 'c must never be attempted after b is refused');
        self::assertFalse($result->completed());
        self::assertSame(1, $result->executedCount());
        self::assertSame(\Milpa\AppRuntime\Agent\StepStatus::Executed, $result->outcomes[0]->status);
        self::assertSame(\Milpa\AppRuntime\Agent\StepStatus::Paused, $result->outcomes[1]->status);
        self::assertSame('needs your authorization', $result->outcomes[1]->reason);
        self::assertSame(\Milpa\AppRuntime\Agent\StepStatus::NotStarted, $result->outcomes[2]->status);
    }

    public function testANonRefusalFailureStopsTheSequenceOrderedNotAtomic(): void
    {
        $seen = [];
        $executor = new class ($seen) implements GovernedExecutor {
            /** @param list<string> $seen */
            public function __construct(public array &$seen)
            {
            }
            public function callTool(string $operation, array $arguments): mixed
            {
                $this->seen[] = $operation;
                if ($operation === 'b') {
                    throw new \RuntimeException('boom');
                }
                return ['ok' => true];
            }
        };

        $result = (new GovernedSequenceRunner())->run([
            new SequenceStep('a', []),
            new SequenceStep('b', []),
            new SequenceStep('c', []),
        ], $executor);

        self::assertSame(['a', 'b'], $executor->seen, 'c must never be attempted after b fails');
        self::assertFalse($result->completed());
        self::assertSame(1, $result->executedCount());
        self::assertSame(StepStatus::Executed, $result->outcomes[0]->status);
        self::assertSame(StepStatus::Failed, $result->outcomes[1]->status);
        self::assertSame('boom', $result->outcomes[1]->reason);
        self::assertSame(StepStatus::NotStarted, $result->outcomes[2]->status);
    }
}
