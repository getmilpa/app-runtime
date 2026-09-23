<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\TrialFailureSummary;
use PHPUnit\Framework\TestCase;

final class TrialFailureSummaryTest extends TestCase
{
    public function testFinishFailureNamesTheCandidateJudgeCountsAndAmendTransitionBeforeDetailRecovery(): void
    {
        $sha = str_repeat('a', 64);
        $output = [
            'ok' => false,
            'error' => "TodoBoardRendererTest said:\nSign in was missing",
            'diagnostic' => [
                'phase' => 'behavior',
                'subject' => 'src/Plugins/Owned/Services/TodoBoardRenderer.php',
                'submitted_sha256' => $sha,
                'selector' => 'tests/Plugins/Owned/TodoBoardRendererTest.php',
                'result' => ['exit' => 1, 'tests' => 6, 'assertions' => 46, 'failures' => 4, 'errors' => 0],
            ],
        ];

        $summary = TrialFailureSummary::from('implement', $output, str_repeat('stack', 5000), ['mode' => 'finish']);

        self::assertTrue($summary['complete']);
        self::assertSame('behavior', $summary['phase']);
        self::assertSame($output['diagnostic']['subject'], $summary['subject']);
        self::assertSame($output['diagnostic']['selector'], $summary['judge']);
        self::assertSame($output['diagnostic']['result'], $summary['counts']);
        self::assertSame($output['error'], $summary['error_excerpt']);
        self::assertSame($sha, $summary['candidate_sha256']);
        self::assertStringContainsString('mode=amend', $summary['next']);
        self::assertStringContainsString($sha, $summary['next']);
        self::assertStringContainsString('Do not repeat finish unchanged', $summary['next']);
        self::assertSame(25000, $summary['stderr']['chars']);
        self::assertSame(hash('sha256', str_repeat('stack', 5000)), $summary['stderr']['sha256']);
    }

    public function testLongProducerErrorKeepsItsBeginningAndVerdictTailWithinTheBound(): void
    {
        $error = 'first failure ' . str_repeat('x', 6000) . ' final verdict';
        $summary = TrialFailureSummary::from('test', ['error' => $error], '', []);

        self::assertLessThanOrEqual(4128, mb_strlen($summary['error_excerpt'], 'UTF-8'));
        self::assertStringStartsWith('first failure', $summary['error_excerpt']);
        self::assertStringEndsWith('final verdict', $summary['error_excerpt']);
        self::assertArrayNotHasKey('stderr', $summary);
    }
}
