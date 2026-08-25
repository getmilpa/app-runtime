<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\{GovernedExecutor, GovernedSequenceRunner, SequenceCursor, SequenceStep, StepOutcome, StepStatus};
use PHPUnit\Framework\TestCase;

final class SequenceCursorTest extends TestCase
{
    public function testDigestIsStableAndSensitiveToTheDeclaredSteps(): void
    {
        $a = [new SequenceStep('x', ['k' => 1]), new SequenceStep('y', [])];
        $b = [new SequenceStep('x', ['k' => 1]), new SequenceStep('y', [])];
        $c = [new SequenceStep('x', ['k' => 2]), new SequenceStep('y', [])]; // arg changed
        self::assertSame(SequenceCursor::digestOf($a), SequenceCursor::digestOf($b));
        self::assertNotSame(SequenceCursor::digestOf($a), SequenceCursor::digestOf($c));
    }

    public function testACursorCarriesTheDigestNextIndexAndDonePrefix(): void
    {
        $done = [new StepOutcome(new SequenceStep('x', []), StepStatus::Executed, 1)];
        $cur = new SequenceCursor('deadbeef', 1, $done);
        self::assertSame('deadbeef', $cur->digest);
        self::assertSame(1, $cur->nextIndex);
        self::assertSame($done, $cur->done);
    }

    public function testDigestOfFailsClosedOnANonEncodableArgumentInsteadOfHashingEmptyString(): void
    {
        // json_encode() returns false on NAN/INF/non-UTF-8 input; casting that false to '' would
        // make THIS step list collide with the digest of an empty step list (both sha256('')) —
        // a mutated sequence would then pass the digest guard in resume() (property 4 bypassed).
        // digestOf() must fail closed instead of silently producing a constant hash.
        $this->expectException(\JsonException::class);
        SequenceCursor::digestOf([new SequenceStep('x', ['n' => NAN])]);
    }

    public function testRehydrateBuildsACursorThatResumeAccepts(): void
    {
        $steps = [new SequenceStep('a', []), new SequenceStep('b', []), new SequenceStep('c', [])];
        $cursor = SequenceCursor::rehydrate($steps, 2);
        self::assertSame(SequenceCursor::digestOf($steps), $cursor->digest);
        self::assertSame(2, $cursor->nextIndex);
        self::assertCount(2, $cursor->done);
        self::assertSame(StepStatus::Executed, $cursor->done[0]->status);
        // resume() with the SAME declared steps must accept it (count===nextIndex, digest matches) and run only c:
        $seen = [];
        $exec = new class ($seen) implements GovernedExecutor {
            /** @param list<string> $seen */
            public function __construct(public array &$seen)
            {
            }
            public function callTool(string $operation, array $arguments): mixed
            {
                $this->seen[] = $operation;

                return ['ok' => true];
            }
        };
        $result = (new GovernedSequenceRunner())->resume($steps, $cursor, $exec);
        self::assertSame(['c'], $exec->seen);
        self::assertTrue($result->completed());
    }

    public function testRehydrateRejectsAnOutOfRangeNextIndex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SequenceCursor::rehydrate([new SequenceStep('a', [])], 5);
    }
}
