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

    public function testInlineFailurePointsToTheRecordedProposalInsteadOfRequestingTheWholeFileAgain(): void
    {
        $sha = str_repeat('b', 64);
        $summary = TrialFailureSummary::from('implement', [
            'error' => 'RenderResult received the wrong target',
            'diagnostic' => [
                'phase' => 'behavior',
                'subject' => 'src/Plugins/Owned/Services/TodoItemRenderer.php',
                'submitted_sha256' => $sha,
                'selector' => 'tests/Plugins/Owned/TodoItemRendererTest.php',
                'result' => ['exit' => 2, 'tests' => 3, 'assertions' => 0, 'failures' => 0, 'errors' => 3],
            ],
        ], '', []);

        self::assertStringContainsString('Repair the recorded proposal with edit', $summary['next']);
        self::assertStringContainsString('source.session=current session', $summary['next']);
        self::assertStringContainsString('source.seq=this failed implement tool-call seq', $summary['next']);
        self::assertStringContainsString('source.sha256=' . $sha, $summary['next']);
        self::assertStringContainsString('exact find/replace edits', $summary['next']);
        self::assertStringContainsString('Do not resubmit the complete file', $summary['next']);
    }
}
