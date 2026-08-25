<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\{GovernedSequenceRunner, GovernedExecutor, SequenceStep, StepStatus};
use PHPUnit\Framework\TestCase;

final class GovernedSequenceRunnerResumeTest extends TestCase
{
    public function testResumeStartsAtTheCursorDoesNotReExecuteThePrefixAndFinishesTheTail(): void
    {
        $steps = [new SequenceStep('a', []), new SequenceStep('b', []), new SequenceStep('c', [])];
        $runner = new GovernedSequenceRunner();

        // First pass: 'b' is a consent frontier (not yet granted).
        $seen1 = [];
        $gate = new class ($seen1) implements GovernedExecutor {
            /** @param list<string> $seen */
            public function __construct(public array &$seen, public bool $granted = false)
            {
            }
            public function callTool(string $operation, array $arguments): mixed
            {
                $this->seen[] = $operation;
                if ($operation === 'b' && !$this->granted) {
                    return ['requires_confirmation' => true, 'confirm_token' => 't'];
                }
                return ['ok' => true, 'op' => $operation];
            }
        };
        $first = $runner->run($steps, $gate);
        self::assertSame(['a', 'b'], $gate->seen);
        self::assertSame(StepStatus::Paused, $first->outcomes[1]->status);

        $cursor = $first->pausedCursor($steps);
        self::assertNotNull($cursor);
        self::assertSame(1, $cursor->nextIndex);

        // Resume: the SAME grant now covers 'b'; 'a' must NOT run again.
        $seen2 = [];
        $granted = new class ($seen2) implements GovernedExecutor {
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
        $final = $runner->resume($steps, $cursor, $granted);

        self::assertSame(['b', 'c'], $granted->seen, 'a is not re-executed; b then c, in order');
        self::assertTrue($final->completed());
        self::assertSame(3, $final->executedCount());
        self::assertSame(StepStatus::Executed, $final->outcomes[0]->status); // a preserved from the prefix
    }

    public function testResumingAMutatedSequenceIsRejected(): void
    {
        $steps = [new SequenceStep('a', []), new SequenceStep('b', [])];
        $runner = new GovernedSequenceRunner();
        $gate = new class () implements GovernedExecutor {
            public function callTool(string $operation, array $arguments): mixed
            {
                return $operation === 'b' ? ['requires_confirmation' => true, 'confirm_token' => 't'] : ['ok' => true];
            }
        };
        $cursor = $runner->run($steps, $gate)->pausedCursor($steps);
        self::assertNotNull($cursor);

        $mutated = [new SequenceStep('a', []), new SequenceStep('b', ['now' => 'different'])];
        $this->expectException(\InvalidArgumentException::class);
        $runner->resume($mutated, $cursor, $gate); // digest mismatch → property 4
    }
}
