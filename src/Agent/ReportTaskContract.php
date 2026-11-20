<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionStore;
use Milpa\EventStore\{Event, EventStoreInterface};

/** Durable immutable opt-in task of a trusted host caller; attribution is not authentication. */
final class ReportTaskContract
{
    public const EVENT = 'session.report_task_declared';
    private const EXECUTION = ['session.turn', 'session.model_called', 'session.tool_called', 'session.trial_run_recorded'];

    /** Encode canonical ordered task facts for receipts. */
    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Read only a native declaration in the proper stream and before its first execution.
     * @param list<Event> $rows
     *
     * @return array<string,mixed>|null */
    public static function read(array $rows, string $session): ?array
    {
        $found = null;
        $executed = false;
        $previous = 0;
        foreach ($rows as $event) {
            if ($event->streamId !== SessionStore::PREFIX . $session || $event->seq <= $previous) {
                throw new \UnexpectedValueException('report_task_stream');
            }
            if ($event->type === self::EVENT) {
                $p = $event->payload;
                $v = $p['task'] ?? [];
                if (!is_array($v) || array_keys($v) !== ['session', 'task_id', 'target_tool']
                    || !is_string($v['session']) || !is_string($v['task_id']) || !is_string($v['target_tool'])) {
                    throw new \UnexpectedValueException('report_task_identity');
                }
                $task = CallerReportTask::failedReadOnlyAttempt($v['session'], $v['task_id'], $v['target_tool']);
                if ($found !== null || $executed || $task->session !== $session
                    || ($p['schema'] ?? null) !== 'milpa.caller-report-task/v1'
                    || ($p['checkpoint'] ?? null) !== $previous || $previous < 1
                    || ($p['criterion'] ?? null) !== $task->criterion()
                    || ($p['sha256'] ?? null) !== self::hash($task)
                    || ($p['authority_boundary'] ?? null) !== 'trusted_host_php_entry'
                    || !is_array($p['attribution'] ?? null)) {
                    throw new \UnexpectedValueException('report_task_declaration_binding');
                }
                $found = $p + ['seq' => $event->seq];
            }
            $executed = $executed || in_array($event->type, self::EXECUTION, true);
            $previous = $event->seq;
        }
        return $found;
    }

    /** Validate opt-in and resume before any session mutation or provider call.
     * @param list<Event> $rows */
    public static function validate(array $rows, CallerReportTask $task): void
    {
        if (DiagnosticContract::read($rows, $task->session) !== null
            || DeliveryExpectation::read($rows, $task->session) !== null
            || DeliveryScope::read($rows, $task->session) !== null) {
            throw new \InvalidArgumentException('report_task_mixed_contract');
        }
        $existing = self::read($rows, $task->session);
        if ($existing !== null) {
            if ($existing['task'] !== $task->toArray() || $existing['criterion'] !== $task->criterion()) {
                throw new \InvalidArgumentException('report_task_immutable');
            }
            foreach ($rows as $event) {
                if ($event->type === SessionReportTaskJudge::EVENT
                    && ($event->payload['verdict']['status'] ?? null) === 'accepted') {
                    throw new \InvalidArgumentException('report_task_already_accepted');
                }
            }
            return;
        }
        foreach ($rows as $event) {
            if (in_array($event->type, self::EXECUTION, true)) {
                throw new \InvalidArgumentException('report_task_late');
            }
        }
    }

    /** Record once after native session creation; identical resumed scope adds no declaration. */
    public static function record(EventStoreInterface $events, CallerReportTask $task, ObservedExecutor $caller): void
    {
        $rows = (new SessionStore($events))->stream($task->session);
        self::validate($rows, $task);
        if (self::read($rows, $task->session) !== null) {
            return;
        }
        if ($rows === []) {
            throw new \RuntimeException('report_task_requires_started_session');
        }
        $events->append(new Event(
            SessionStore::PREFIX . $task->session,
            self::EVENT,
            ['schema' => 'milpa.caller-report-task/v1', 'task' => $task->toArray(),
                'checkpoint' => $rows[array_key_last($rows)]->seq, 'criterion' => $task->criterion(),
                'sha256' => self::hash($task), 'authority_boundary' => 'trusted_host_php_entry',
                'attribution' => ['source' => $caller->source, 'principal' => $caller->principal?->toArray()]],
            $events->nextSeq()
        ));
    }

    /** Bind the immutable criterion to the caller's session and logical task. */
    public static function hash(CallerReportTask $task): string
    {
        return hash('sha256', self::json(['task' => $task->toArray(), 'criterion' => $task->criterion()]));
    }
}
