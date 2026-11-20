<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\{ModelCallIntake, ProgressReceipt, SessionStore};
use Milpa\AppRuntime\Agent\{CallerReportTask, ObservedExecutor, ReportTaskContract, ReportTaskJudge, SessionHouseDebt, SessionReportTaskJudge};
use Milpa\EventStore\{Event, InMemoryEventStore};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportTaskJudgeTest extends TestCase
{
    /** @return array{InMemoryEventStore, SessionStore, string} */
    private function ledger(): array
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s', 'Report one failed read');
        ReportTaskContract::record($events, CallerReportTask::failedReadOnlyAttempt('s', 'task', 'probe'), ObservedExecutor::unknown());
        $store->recordToolCall('s', 'probe', [], 'FIXTURE_UNAVAILABLE', false);
        $debt = new SessionHouseDebt($store, 's', $events);
        $calls = $debt->read(['mode' => 'calls']);
        $report = $debt->report(['owner' => 'environment', 'kind' => 'tool_error', 'statement' => 'Read failed; no recovery.', 'about' => [$calls['items'][0]['id']]]);
        self::assertTrue($report['recorded']);
        $messages = [
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'provider-id', 'type' => 'function', 'function' => ['name' => 'HouseDebtRead', 'arguments' => json_encode(['mode' => 'findings', 'id' => $report['id']])]]]],
            ['role' => 'tool', 'tool_call_id' => 'provider-id', 'name' => 'HouseDebtRead', 'content' => json_encode($debt->read(['mode' => 'findings', 'id' => $report['id']]))],
        ];
        $store->recordModelCall('s', ModelCallIntake::fromChannelPayload('http://fixture.invalid', ['model' => 'fixture', 'messages' => $messages]));
        return [$events, $store, json_encode(['finding_id' => $report['id']])];
    }

    public function testNativeReadbackAcceptsWithoutInventingProgressAndConsumesBeforeDelivery(): void
    {
        [$events, $store, $candidate] = $this->ledger();
        $rows = $store->stream('s');
        $judge = new SessionReportTaskJudge($events, 's', 2);
        $verdict = $judge->judge($candidate);
        self::assertSame('accepted', $verdict->status);
        self::assertSame('provider-id', $verdict->evidence['toolCallId']);
        self::assertSame(3, $verdict->evidence['attemptSeq']);
        $persisted = $store->stream('s');
        self::assertSame(['candidate' => $candidate, 'verdict' => $verdict->toArray()], end($persisted)->payload);
        self::assertSame('stalled', ProgressReceipt::of($persisted, 0, end($persisted)->seq)->progress);
        self::assertSame('native_input_not_current', ReportTaskJudge::derive('s', $rows, $candidate, end($rows)->seq)->reason);
        $this->expectExceptionMessage('report_task_already_accepted');
        ReportTaskContract::validate($persisted, CallerReportTask::failedReadOnlyAttempt('s', 'task', 'probe'));
    }

    /** @return iterable<string,array{string,string}> */
    public static function corruptions(): iterable
    {
        foreach (['call-id' => 'native_call_identity', 'call-role' => 'native_call_identity', 'call-shape' => 'native_call_shape', 'call-type' => 'native_call_identity', 'duplicate-call' => 'native_call_identity', 'reply-id' => 'native_reply_identity', 'reply-order' => 'native_reply_identity', 'duplicate-reply' => 'native_reply_identity', 'reply-name' => 'native_reply_name', 'producer' => 'readback_not_delivered', 'read-mode' => 'readback_not_delivered', 'read-id' => 'readback_not_delivered', 'read-content' => 'readback_not_delivered', 'read-statement' => 'readback_not_delivered', 'multiple-delivery' => 'multiple_readback_deliveries', 'missing-input' => 'native_input_missing', 'missing-attempt' => 'one_current_attempt_required', 'successful-attempt' => 'failed_readonly_attempt_required', 'mutating-attempt' => 'failed_readonly_attempt_required', 'changed-attempt' => 'report_reference_or_scope', 'report-owner' => 'report_reference_or_scope', 'extra-report' => 'one_current_report_required'] as $case => $reason) {
            yield $case => [$case, $reason];
        }
    }

    #[DataProvider('corruptions')]
    public function testDamagedProvenanceCannotAuthorizeAReport(string $case, string $reason): void
    {
        [, $store, $candidate] = $this->ledger();
        $rows = $store->stream('s');
        foreach ($rows as $i => $event) {
            $p = $event->payload;
            if ($event->type === 'session.model_called') {
                $m = $p['messages'];
                switch ($case) {
                    case 'call-id': $m[0]['tool_calls'][0]['id'] = '';
                        break;
                    case 'call-role': $m[0]['role'] = 'user';
                        break;
                    case 'call-shape': $m[0]['tool_calls'] = null;
                        break;
                    case 'call-type': $m[0]['tool_calls'][0]['type'] = 'other';
                        break;
                    case 'duplicate-call': $m[0]['tool_calls'][] = $m[0]['tool_calls'][0];
                        break;
                    case 'reply-id': $m[1]['tool_call_id'] = 'foreign';
                        break;
                    case 'reply-order': $m = array_reverse($m);
                        break;
                    case 'duplicate-reply': $m[] = $m[1];
                        break;
                    case 'reply-name': $m[1]['name'] = 'foreign';
                        break;
                    case 'producer': $m[0]['tool_calls'][0]['function']['name'] = 'foreign';
                        unset($m[1]['name']);
                        break;
                    case 'read-mode': case 'read-id':
                        $args = json_decode($m[0]['tool_calls'][0]['function']['arguments'], true);
                        $args[$case === 'read-mode' ? 'mode' : 'id'] = 'foreign';
                        $m[0]['tool_calls'][0]['function']['arguments'] = json_encode($args);
                        break;
                    case 'read-content': $m[1]['content'] = '{}';
                        break;
                    case 'read-statement':
                        $read = json_decode($m[1]['content'], true);
                        $read['items'][0]['statement'] = 'forged';
                        $m[1]['content'] = json_encode($read);
                        break;
                    case 'multiple-delivery':
                        $m[] = $m[0];
                        $m[] = $m[1];
                        $m[2]['tool_calls'][0]['id'] = 'second';
                        $m[3]['tool_call_id'] = 'second';
                        break;
                }
                $p['messages'] = $m;
                if ($case === 'missing-input') {
                    unset($rows[$i]);
                    continue;
                }
            }
            if ($event->type === 'session.tool_called') {
                if ($case === 'missing-attempt') {
                    unset($rows[$i]);
                    continue;
                }
                if ($case === 'successful-attempt') {
                    $p['ok'] = true;
                }
                if ($case === 'mutating-attempt') {
                    $p['mutating'] = true;
                }
                if ($case === 'changed-attempt') {
                    $p['result'] = 'changed';
                }
            }
            if ($event->type === 'session.house_debt_reported' && $case === 'report-owner') {
                $p['owner'] = 'house';
            }
            $rows[$i] = new Event($event->streamId, $event->type, $p, $event->seq, $event->recordedAt);
        }
        if ($case === 'extra-report') {
            $rows[] = new Event(SessionStore::PREFIX . 's', 'session.house_debt_reported', [], end($rows)->seq + 1);
        }
        self::assertSame($reason, ReportTaskJudge::derive('s', array_values($rows), $candidate, 2)->reason);
    }

    public function testStrictFinalObjectAndAbsentOptionalReplyName(): void
    {
        [, $store, $candidate] = $this->ledger();
        $rows = $store->stream('s');
        foreach (['{}', '[]', '"text"', $candidate . ' extra', "```json\n" . $candidate . "\n```", str_replace('}', ',"extra":true}', $candidate), str_replace('"}', '\\n"}', $candidate), '{"finding_id":"' . str_repeat('f', 64) . '","finding_id":"' . str_repeat('f', 64) . '"}'] as $bad) {
            self::assertSame('candidate_format', ReportTaskJudge::derive('s', $rows, $bad, 2)->reason);
        }
        $last = array_key_last($rows);
        $e = $rows[$last];
        $p = $e->payload;
        unset($p['messages'][1]['name']);
        $rows[$last] = new Event($e->streamId, $e->type, $p, $e->seq, $e->recordedAt);
        self::assertSame('accepted', ReportTaskJudge::derive('s', $rows, $candidate, 2)->status);
    }
}
