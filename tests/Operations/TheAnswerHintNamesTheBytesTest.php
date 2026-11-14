<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\PendingQuestion;
use Milpa\AppRuntime\Operations\AgentOperations;
use PHPUnit\Framework\TestCase;

/**
 * THE PAUSE'S HINT SAYS WHERE A PROMOTION IS READ, AND THE ANSWER NAMES ITS BYTES (greenhouse decisions/0633).
 */
final class TheAnswerHintNamesTheBytesTest extends TestCase
{
    public function testAPromotionsHintReadsFirstAndNamesTheDigest(): void
    {
        $hint = $this->hint(new PendingQuestion('perm:sandbox:promote', 'Promote?', ['yes', 'no'], (string) json_encode(['operation' => 'sandbox:promote', 'digest' => 'diff:' . str_repeat('e', 64)])));

        self::assertStringContainsString('agent:show --session=s1', $hint);
        self::assertStringContainsString('--digest=diff:' . str_repeat('e', 64), $hint);
    }

    public function testTheControlAPlainQuestionsHintIsUnchanged(): void
    {
        $hint = $this->hint(new PendingQuestion('perm:make', 'May I?', ['yes', 'no']));

        self::assertStringNotContainsString('--digest', $hint);
        self::assertStringNotContainsString('agent:show', $hint);
        self::assertStringContainsString('agent:answer --session=s1 --answer=<yes|no> --sign', $hint);
    }

    private function hint(PendingQuestion $question): string
    {
        $m = new \ReflectionMethod(AgentOperations::class, 'answerHint');

        return (string) $m->invoke(null, 's1', $question);
    }
}
