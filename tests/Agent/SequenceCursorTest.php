<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\{SequenceCursor, SequenceStep, StepOutcome, StepStatus};
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
}
