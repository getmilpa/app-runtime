<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\AiGateway\AnswerVerdict;
use Milpa\EventStore\Event;

/** Replay a finite diagnostic from producer results actually present in model input. */
final class DiagnosticJudge
{
    /** Derive without filesystem reads, model claims about evidence, or progress inflation.
     * @param list<Event> $events
     */
    public static function derive(string $session, array $events, string $candidate): AnswerVerdict
    {
        $declaration = DiagnosticContract::read($events, $session);
        if ($declaration === null) {
            throw new \UnexpectedValueException('No diagnostic contract was declared.');
        }
        $c = $declaration['criterion'];
        $evidence = ['scope' => 'declared_json_projection', 'declarationSeq' => $declaration['seq'],
            'criterionSha256' => $declaration['sha256'], 'throughSeq' => $events[array_key_last($events)]->seq,
            'pages' => []];
        $verdict = static fn (string $status, string $reason): AnswerVerdict => new AnswerVerdict($status, hash('sha256', $candidate), $reason, $evidence);
        $produced = [];
        $delivered = [];
        foreach ($events as $event) {
            if ($event->type === 'session.tool_called') {
                $p = $event->payload;
                if (($p['mutating'] ?? null) !== false) {
                    return $verdict('rejected', 'execution_outside_diagnostic_scope');
                }
                if (($p['tool'] ?? null) === 'source_page' && ($p['ok'] ?? null) === true
                    && is_string($p['result'] ?? null)) {
                    $page = json_decode($p['result'], true);
                    if (is_array($page) && ($page['path'] ?? null) === $c['path'] && ($page['sha256'] ?? null) === $c['sha256']) {
                        $produced[] = ['seq' => $event->seq, 'page' => $page, 'arguments' => $p['arguments'] ?? []];
                    }
                }
            }
            if ($event->type !== 'session.model_called') {
                continue;
            }
            foreach ($event->payload['messages'] ?? [] as $message) {
                if (($message['role'] ?? null) !== 'tool' || !is_string($message['content'] ?? null)) {
                    continue;
                }
                $page = json_decode($message['content'], true);
                foreach ($produced as $read) {
                    if ($page === $read['page'] && !isset($delivered[$read['seq']])) {
                        $delivered[$read['seq']] = $read + ['inputSeq' => $event->seq];
                    }
                }
            }
        }
        $body = '';
        $cursor = null;
        $complete = null;
        $total = null;
        foreach ($delivered as $read) {
            $p = $read['page'];
            $args = $read['arguments'];
            if (($p['ok'] ?? null) !== true || !is_string($p['content'] ?? null)
                || !is_int($p['offset'] ?? null) || !is_int($p['next_offset'] ?? null) || !is_int($p['total_bytes'] ?? null)
                || ($args['path'] ?? null) !== $c['path'] || !array_key_exists('next_cursor', $p)
                || $p['next_offset'] !== $p['offset'] + strlen($p['content'])
                || $p['offset'] < 0 || $p['next_offset'] > $p['total_bytes'] || $p['next_offset'] <= $p['offset']
                || ($total !== null && $total !== $p['total_bytes'])
                || (($p['next_cursor'] === null) !== ($p['next_offset'] === $p['total_bytes']))) {
                return $verdict('indeterminate', 'invalid_page_provenance');
            }
            $total = $p['total_bytes'];
            if (!array_key_exists('cursor', $args)) {
                if ($p['offset'] !== 0) {
                    return $verdict('indeterminate', 'invalid_page_origin');
                }
                $body = '';
                $cursor = null;
            } elseif ($args['cursor'] !== $cursor || $p['offset'] !== strlen($body)) {
                return $verdict('indeterminate', 'invalid_page_continuation');
            }
            $body .= $p['content'];
            $cursor = $p['next_cursor'];
            $evidence['pages'][] = ['toolSeq' => $read['seq'], 'inputSeq' => $read['inputSeq'],
                'offset' => $p['offset'], 'nextOffset' => $p['next_offset']];
            if ($cursor === null) {
                if (strlen($body) !== $total || hash('sha256', $body) !== $c['sha256']) {
                    return $verdict('indeterminate', 'document_hash_mismatch');
                }
                $complete = $body;
            }
        }
        // Rebind after evidence collection; rejected/incomplete verdicts retain their actual reads too.
        $verdict = static fn (string $status, string $reason): AnswerVerdict => new AnswerVerdict($status, hash('sha256', $candidate), $reason, $evidence);
        if ($complete === null) {
            return $verdict('rejected', 'document_not_completely_delivered');
        }
        $document = json_decode($complete);
        if (!$document instanceof \stdClass) {
            return $verdict('indeterminate', 'document_not_json_object');
        }
        $document = (array) $document;
        $expected = [];
        foreach ($c['fields'] as $output => $field) {
            if (!array_key_exists($field, $document) || (!is_scalar($document[$field]) && $document[$field] !== null)) {
                return $verdict('rejected', 'required_scalar_missing');
            }
            $expected[$output] = $document[$field];
        }
        foreach ($c['equals'] as $output => [$left, $right]) {
            if (!array_key_exists($left, $document) || !array_key_exists($right, $document)
                || (!is_scalar($document[$left]) && $document[$left] !== null)
                || (!is_scalar($document[$right]) && $document[$right] !== null)) {
                return $verdict('rejected', 'comparison_scalar_missing');
            }
            $expected[$output] = $document[$left] === $document[$right];
        }
        $raw = trim($candidate);
        if (preg_match('/\A```(?:json)?\s*\n(.*?)\n```\z/s', $raw, $fence)) {
            $raw = $fence[1];
        }
        $report = json_decode($raw);
        if (!$report instanceof \stdClass) {
            return $verdict('rejected', 'report_format');
        }
        $report = (array) $report;
        if (array_filter($report, static fn ($v): bool => !is_scalar($v) && $v !== null) !== []) {
            return $verdict('rejected', 'report_shape_or_types');
        }
        // A valid flat JSON object's scalar tokens expose duplicate members without a second decoder.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{},:]|[^\s{},:]+/s', $raw, $tokens);
        if (count($tokens[0]) !== 4 * count($report) + 1) {
            return $verdict('rejected', 'duplicate_report_key');
        }
        ksort($report);
        ksort($expected);
        if (array_keys($report) !== array_keys($expected)) {
            return $verdict('rejected', 'report_shape_or_types');
        }
        foreach ($expected as $key => $value) {
            if (get_debug_type($report[$key]) !== get_debug_type($value)) {
                return $verdict('rejected', 'report_shape_or_types');
            }
        }
        return $report === $expected ? $verdict('accepted', 'complete_projection_matches')
            : $verdict('rejected', 'report_disagrees_with_delivered_document');
    }
}
