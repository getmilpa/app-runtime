<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\{SequenceStep, StepStatus, StepOutcome, SequenceResult};
use PHPUnit\Framework\TestCase;

final class SequenceResultTest extends TestCase
{
    public function testItReportsCompletionExecutedCountAndFrontier(): void
    {
        $a = new StepOutcome(new SequenceStep('a', []), StepStatus::Executed, ['ok' => true]);
        $b = new StepOutcome(new SequenceStep('b', []), StepStatus::Paused, null, 'needs consent');
        $c = new StepOutcome(new SequenceStep('c', []), StepStatus::NotStarted);
        $r = new SequenceResult([$a, $b, $c]);

        self::assertFalse($r->completed(), 'a paused/not-started sequence is not completed');
        self::assertSame(1, $r->executedCount());
        self::assertSame($b, $r->frontier(), 'the frontier is the first non-executed step');
    }

    public function testAllExecutedIsCompletedWithNoFrontier(): void
    {
        $r = new SequenceResult([
            new StepOutcome(new SequenceStep('a', []), StepStatus::Executed, 1),
            new StepOutcome(new SequenceStep('b', []), StepStatus::Executed, 2),
        ]);
        self::assertTrue($r->completed());
        self::assertNull($r->frontier());
        self::assertSame(2, $r->executedCount());
    }
}
