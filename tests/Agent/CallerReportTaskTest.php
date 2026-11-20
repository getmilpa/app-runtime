<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\{CallerReportTask, ObservedExecutor, ReportTaskContract, ReportTaskJudge, SessionReportTaskJudge};
use Milpa\EventStore\{Event, InMemoryEventStore};
use PHPUnit\Framework\TestCase;

final class CallerReportTaskTest extends TestCase
{
    public function testExactFindingIdIsAValidCandidateShape(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('session', 'Report');
        ReportTaskContract::record($events, CallerReportTask::failedReadOnlyAttempt('session', 'task', 'probe'), ObservedExecutor::unknown());
        $verdict = ReportTaskJudge::derive('session', $store->stream('session'), '{"finding_id":"' . str_repeat('a', 64) . '"}', 0);
        self::assertSame('report_not_in_session', $verdict->reason);
        self::assertSame('rejected', $verdict->status);
    }

    public function testDeclarationSurvivesReloadAndCannotBeChanged(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('session', 'Report one failed attempt');
        $task = CallerReportTask::failedReadOnlyAttempt('session', 'task', 'probe');
        ReportTaskContract::record($events, $task, ObservedExecutor::unknown());
        $before = $store->stream('session');
        ReportTaskContract::record($events, CallerReportTask::failedReadOnlyAttempt('session', 'task', 'probe'), ObservedExecutor::unknown());
        self::assertSame($before, $store->stream('session'));
        self::assertSame(ReportTaskContract::hash($task), ReportTaskContract::read((new SessionStore($events))->stream('session'), 'session')['sha256']);
        $this->expectExceptionMessage('report_task_immutable');
        ReportTaskContract::validate($before, CallerReportTask::failedReadOnlyAttempt('session', 'replacement', 'probe'));
    }

    public function testLateDeclarationDoesNotWrite(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('session', 'Ordinary task');
        $store->recordTurn('session', 'user', 'Already invoked');
        $before = $store->stream('session');
        try {
            ReportTaskContract::record($events, CallerReportTask::failedReadOnlyAttempt('session', 'task', 'probe'), ObservedExecutor::unknown());
            self::fail('Late declaration accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('report_task_late', $error->getMessage());
        }
        self::assertSame($before, $store->stream('session'));
    }

    public function testAcceptedJudgmentConsumesTaskEvenBeforeCallerDelivery(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('session', 'Report');
        $task = CallerReportTask::failedReadOnlyAttempt('session', 'task', 'probe');
        ReportTaskContract::record($events, $task, ObservedExecutor::unknown());
        $events->append(new Event(SessionStore::PREFIX . 'session', SessionReportTaskJudge::EVENT, ['verdict' => ['status' => 'accepted']], $events->nextSeq()));
        $this->expectExceptionMessage('report_task_already_accepted');
        ReportTaskContract::validate($store->stream('session'), $task);
    }

    public function testDeclarationInForeignStreamIsRejected(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('session', 'Report');
        ReportTaskContract::record($events, CallerReportTask::failedReadOnlyAttempt('session', 'task', 'probe'), ObservedExecutor::unknown());
        $this->expectExceptionMessage('report_task_stream');
        ReportTaskContract::read($store->stream('session'), 'foreign');
    }
    public function testInvalidIdentityAndDamagedDeclarationsFailClosed(): void
    {
        foreach ([['', 'task', 'probe'], ['s', str_repeat('a', 65), 'probe'], ['s', 't', 'bad-name']] as $args) {
            try {
                CallerReportTask::failedReadOnlyAttempt(...$args);
                self::fail('Invalid identity accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('bounded', $error->getMessage());
            }
        }
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s', 'Report');
        $task = CallerReportTask::failedReadOnlyAttempt('s', 'task', 'probe');
        ReportTaskContract::record($events, $task, ObservedExecutor::unknown());
        $rows = $store->stream('s');
        $declared = $rows[1];
        foreach (['sha256' => 'changed', 'checkpoint' => 0, 'authority_boundary' => 'model', 'attribution' => null, 'criterion' => [], 'task' => ['session' => 's']] as $key => $value) {
            $p = $declared->payload;
            $p[$key] = $value;
            $bad = [$rows[0], new Event($declared->streamId, $declared->type, $p, $declared->seq, $declared->recordedAt)];
            try {
                ReportTaskContract::read($bad, 's');
                self::fail('Damaged declaration accepted');
            } catch (\UnexpectedValueException $error) {
                self::assertStringStartsWith('report_task_', $error->getMessage());
            }
        }
        $this->expectExceptionMessage('report_task_declaration_binding');
        ReportTaskContract::read([...$rows, new Event($declared->streamId, $declared->type, $declared->payload, 3)], 's');
    }

    public function testUnstartedSessionAndMissingDeclarationDoNotCreateAuthority(): void
    {
        $events = new InMemoryEventStore();
        $task = CallerReportTask::failedReadOnlyAttempt('s', 'task', 'probe');
        self::assertNull(ReportTaskContract::read([], 's'));
        try {
            ReportTaskContract::record($events, $task, ObservedExecutor::unknown());
            self::fail('Unstarted task recorded');
        } catch (\RuntimeException $error) {
            self::assertSame('report_task_requires_started_session', $error->getMessage());
        }
        $this->expectExceptionMessage('report_task_missing');
        ReportTaskJudge::derive('s', [], '{}', 0);
    }

}
