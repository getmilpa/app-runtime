<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

/** Finite reporting delivery derived from native facts; no progress, repair or blame claim. */
final class ReportTaskJudge
{
    /** Derive only from the prior task and a model input observed during this execution leg.
     * @param list<\Milpa\EventStore\Event> $events */
    public static function derive(string $session, array $events, string $candidate, int $afterModelSeq): \Milpa\AiGateway\AnswerVerdict
    {
        $declaration = ReportTaskContract::read($events, $session);
        if ($declaration === null) {
            throw new \RuntimeException('report_task_missing');
        }
        $criterion = (object)['session' => $session,'checkpoint' => $declaration['checkpoint'],'value' => $declaration['criterion'],'sha256' => $declaration['sha256']];
        $evidence = ['scope' => 'caller_failed_attempt_report_readback','criterionSha256' => $criterion->sha256,'declarationSeq' => $declaration['seq'],'session' => $criterion->session,'afterSeq' => $criterion->checkpoint];
        $verdict = static fn (string $reason, bool $accepted = false) => new \Milpa\AiGateway\AnswerVerdict($accepted ? 'accepted' : 'rejected', hash('sha256', $candidate), $reason, $evidence);
        $c = json_decode($candidate, true);
        if (!is_array($c) || array_keys($c) !== ['finding_id'] || !is_string($c['finding_id']) || preg_match('/^[a-f0-9]{64}$/D', $c['finding_id']) !== 1 || preg_match('/\A\s*\{\s*"finding_id"\s*:\s*"[a-f0-9]{64}"\s*\}\s*\z/sD', $candidate) !== 1) {
            return $verdict('candidate_format');
        }
        $prefix = \Milpa\Agent\SessionStore::PREFIX . $criterion->session;
        $all = [];
        $models = [];
        $attempts = [];
        $reports = [];
        $previous = 0;
        foreach ($events as $e) {
            if ($e->streamId !== $prefix || $e->seq <= $previous) {
                return $verdict('invalid_native_stream');
            }
            $previous = $e->seq;
            $all[$e->seq] = $e;
            if ($e->type === 'session.house_debt_reported') {
                $reports[] = $e;
            }
            if ($e->seq <= $criterion->checkpoint) {
                continue;
            }
            if ($e->type === 'session.model_called') {
                $models[$e->seq] = $e;
            }
            if ($e->type === 'session.tool_called' && ($e->payload['tool'] ?? null) === $criterion->value['target_tool']) {
                $attempts[] = $e;
            }
        }
        if ($criterion->checkpoint > 0 && !isset($all[$criterion->checkpoint])) {
            return $verdict('criterion_checkpoint');
        }
        $report = null;
        foreach ($reports as $e) {
            if (hash('sha256', ReportTaskContract::json(['milpa-finding-v1',$prefix,$e->seq])) === $c['finding_id']) {
                $report = $e;
            }
        }
        if ($report === null) {
            return $verdict('report_not_in_session');
        }
        if ($report->seq <= $criterion->checkpoint) {
            return $verdict('stale_report');
        }
        if (count(array_filter($reports, static fn ($e) => $e->seq > $criterion->checkpoint)) !== 1) {
            return $verdict('one_current_report_required');
        }
        if (count($attempts) !== 1) {
            return $verdict('one_current_attempt_required');
        }
        $attempt = $attempts[0];
        if (($attempt->payload['ok'] ?? null) !== false || ($attempt->payload['mutating'] ?? null) !== false || !is_string($attempt->payload['result'] ?? null)) {
            return $verdict('failed_readonly_attempt_required');
        }
        $callId = hash('sha256', ReportTaskContract::json(['milpa-call-v1',$prefix,$attempt->seq]));
        $ref = ['id' => $callId,'stream' => $prefix,'seq' => $attempt->seq,'sha256' => hash('sha256', ReportTaskContract::json($attempt->toArray()))];
        $p = $report->payload;
        if ($report->seq <= $attempt->seq || ($p['version'] ?? null) !== 1 || ($p['source'] ?? null) !== 'model' || ($p['owner_basis'] ?? null) !== 'testimony' || ($p['owner'] ?? null) !== $criterion->value['report']['owner'] || ($p['kind'] ?? null) !== $criterion->value['report']['kind'] || ($p['references'] ?? null) !== [$ref] || ($p['about'] ?? null) !== [$callId] || ($p['ignored_about'] ?? null) !== [] || ($p['disputes'] ?? null) !== null) {
            return $verdict('report_reference_or_scope');
        }
        if ($models === []) {
            return $verdict('native_input_missing');
        }
        $lastModel = $models[array_key_last($models)];
        if ($lastModel->seq <= $report->seq || $lastModel->seq <= $afterModelSeq) {
            return $verdict('native_input_not_current');
        }
        $messages = $lastModel->payload['messages'] ?? [];
        $calls = [];
        $replies = [];
        $delivery = null;
        foreach ($messages as $position => $m) {
            if (array_key_exists('tool_calls', $m) && (!is_array($m['tool_calls']) || !array_is_list($m['tool_calls']))) {
                return $verdict('native_call_shape');
            }
            foreach (is_array($m['tool_calls'] ?? null) ? $m['tool_calls'] : [] as $tc) {
                $id = $tc['id'] ?? null;
                if (($m['role'] ?? null) !== 'assistant' || !is_string($id) || $id === '' || isset($calls[$id]) || ($tc['type'] ?? null) !== 'function') {
                    return $verdict('native_call_identity');
                }
                $argRaw = $tc['function']['arguments'] ?? null;
                $args = is_string($argRaw) ? json_decode($argRaw, true) : null;
                $calls[$id] = ['position' => $position,'name' => $tc['function']['name'] ?? null,'arguments' => $args];
            }
            if (($m['role'] ?? null) !== 'tool') {
                continue;
            }
            $id = $m['tool_call_id'] ?? null;
            if (!is_string($id) || $id === '' || isset($replies[$id]) || !isset($calls[$id]) || $calls[$id]['position'] >= $position) {
                return $verdict('native_reply_identity');
            }
            $replies[$id] = true;
            $call = $calls[$id];
            if (array_key_exists('name', $m) && $m['name'] !== $call['name']) {
                return $verdict('native_reply_name');
            }
            $args = $call['arguments'];
            if ($call['name'] !== 'HouseDebtRead' || ($args['mode'] ?? null) !== 'findings' || ($args['id'] ?? null) !== $c['finding_id']) {
                continue;
            }
            $read = json_decode($m['content'] ?? '', true);
            $items = $read['items'] ?? null;
            if (($read['ok'] ?? null) !== true || !is_array($items) || count($items) !== 1) {
                continue;
            }
            $item = $items[0];
            if (($item['id'] ?? null) !== $c['finding_id'] || ($item['seq'] ?? null) !== $report->seq || ($item['source'] ?? null) !== 'model' || ($item['owner_basis'] ?? null) !== 'testimony' || ($item['owner'] ?? null) !== $p['owner'] || ($item['kind'] ?? null) !== $p['kind'] || ($item['statement'] ?? null) !== $p['statement'] || ($item['references'] ?? null) !== [$ref] || ($item['evidence'] ?? null) !== 1 || ($item['ignored_about'] ?? null) !== []) {
                continue;
            }
            if ($delivery !== null) {
                return $verdict('multiple_readback_deliveries');
            }
            $delivery = ['nativeInputSeq' => $lastModel->seq,'nativeInputSha256' => hash('sha256', ReportTaskContract::json($lastModel->toArray())),'toolCallId' => $id,'reportSeq' => $report->seq,'attemptSeq' => $attempt->seq,'reference' => $ref];
        }
        if ($delivery === null) {
            return $verdict('readback_not_delivered');
        }
        $evidence += $delivery;
        return new \Milpa\AiGateway\AnswerVerdict('accepted', hash('sha256', $candidate), 'current_report_readback_matches', $evidence);
    }
}
