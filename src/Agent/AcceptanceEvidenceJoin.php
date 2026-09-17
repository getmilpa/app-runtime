<?php

declare(strict_types=1);
/** Native receipt derivation, graduated from greenhouse 0379/0696 and 0381/0698.
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */

namespace Milpa\AppRuntime\Agent;

use RuntimeException;
use Throwable;
use Milpa\Agent\EffectObservation;
use Milpa\AppRuntime\Web\ScreenDrafts;

/**
 * Pure derivation; callers use AcceptanceEvidence to collect native facts.
 *
 * @internal
 */
final class AcceptanceEvidenceJoin
{
    /**
     * Recognize only unambiguous catalogue requests. Failed or malformed directed attempts
     * must remain eligible; choosing by result would conceal a later refusal (greenhouse 0383/0700).
     *
     * @param array<string,mixed> $payload
     */
    public static function isCatalogueReview(array $payload): bool
    {
        $arguments = $payload['arguments'] ?? null;

        return $arguments === [] || $arguments === ['revision' => ''];
    }

    /**
     * Derive evidence from the collector.
     *
     * @param array<string,mixed> $observation
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $screen
     *
     * @return array<string,mixed>
     */
    public static function read(array $observation, array $scope, array $screen): array
    {
        $base = ['authorization' => 'not_evaluated', 'humanApproval' => 'not_recorded',
            'scope' => ['test' => $scope, 'screen' => $screen, 'coverage' => 'requested_test_scope',
                'inputs' => 'native_copied_files_and_screen_build', 'browserBehavior' => 'not_observed'],
            'candidate' => $observation['candidate'] ?? null];
        if (isset($observation['memberPaths'])) {
            $base['scope']['members'] = $observation['memberPaths'];
        }
        try {
            $events = $observation['events'] ?? null;
            if (!is_array($events)) {
                throw new RuntimeException('events_missing');
            }
            $seq = 0;
            $stream = null;
            foreach ($events as $e) {
                if (!is_int($e['seq'] ?? null) || $e['seq'] <= $seq || !is_string($e['streamId'] ?? null)
                    || ($stream !== null && $stream !== $e['streamId'])) {
                    throw new RuntimeException('invalid_stream');
                }
                $seq = $e['seq'];
                $stream = $e['streamId'];
            }
            $test = self::test($events, $observation, $scope);
            $review = self::review($events, $observation, $screen);
            $candidate = $base['candidate'];
            $state = 'incomplete';
            if (($candidate['state'] ?? null) === 'indeterminate' || in_array('indeterminate', [$test['state'], $review['state']], true)) {
                $state = 'indeterminate';
            } elseif (($candidate['state'] ?? null) === 'contradicted' || in_array('stale', [$test['state'], $review['state']], true)) {
                $state = 'historical_evidence';
            } elseif ($test['state'] === 'failed') {
                $state = 'failed';
            } elseif (($candidate['state'] ?? null) === 'promoted' && $test['state'] === 'current' && $review['state'] === 'current') {
                $state = 'current_evidence';
            }
            return $base + ['state' => $state, 'test' => $test, 'screen' => $review];
        } catch (Throwable $error) {
            return $base + ['state' => 'indeterminate', 'reason' => $error->getMessage()];
        }
    }

    /**
     * @param list<array<string,mixed>> $events
     *
     * @return list<array<string,mixed>>
     */
    private static function calls(array $events, string $tool): array
    {
        return array_values(array_filter($events, static fn ($e) => $e['type'] === 'session.tool_called' && ($e['payload']['tool'] ?? null) === $tool));
    }

    /**
     * @param array<string,mixed> $call
     *
     * @return array<string,mixed>
     */
    private static function result(array $call): array
    {
        $p = $call['payload'];
        if (!is_string($p['result'] ?? null) || ($p['resultChars'] ?? null) !== mb_strlen($p['result'])
            || ($p['ok'] ?? null) !== true || ($p['awaitingConfirmation'] ?? true) !== false) {
            throw new RuntimeException('incomplete_or_refused_tool_result');
        }
        $r = json_decode($p['result'], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($r)) {
            throw new RuntimeException('invalid_tool_result');
        }
        return $r;
    }

    /**
     * @param list<array<string,mixed>> $events
     * @param array<string,mixed>       $observation
     * @param array<string,mixed>       $scope
     *
     * @return array<string,mixed>
     */
    private static function test(array $events, array $observation, array $scope): array
    {
        $calls = self::calls($events, 'test');
        if ($calls === []) {
            return ['state' => 'missing'];
        }
        $call = end($calls);
        $args = $call['payload']['arguments'] ?? null;
        if (!is_array($args)) {
            throw new RuntimeException('test_arguments_missing');
        }
        foreach (['path', 'filter'] as $key) {
            if (isset($args[$key]) && !is_string($args[$key])) {
                throw new RuntimeException('invalid_test_scope');
            }
        }
        $actual = ['path' => trim($args['path'] ?? ''), 'filter' => trim($args['filter'] ?? '')];
        $digest = EffectObservation::argumentsDigest($args);
        $previous = count($calls) > 1 ? $calls[count($calls) - 2]['seq'] : 0;
        $attempts = array_values(array_filter($events, static fn ($e) => $e['type'] === 'session.trial_run_recorded'
            && ($e['payload']['operation'] ?? null) === 'test' && $e['seq'] > $previous && $e['seq'] < $call['seq']));
        if ($attempts === [] && ($call['payload']['ok'] ?? null) === false
            && is_string($call['payload']['result'] ?? null)
            && ($call['payload']['resultChars'] ?? null) === mb_strlen($call['payload']['result'])
            && ($call['payload']['awaitingConfirmation'] ?? null) === false) {
            return ['state' => 'indeterminate', 'meaning' => 'failed_call_without_trial_receipt',
                'scope' => $actual, 'toolCallSeq' => $call['seq'], 'diagnostic' => $call['payload']['result']];
        }
        if (count($attempts) !== 1 || ($attempts[0]['payload']['arguments_digest'] ?? null) !== $digest) {
            throw new RuntimeException('test_attempt_missing_or_ambiguous');
        }
        $p = $call['payload'];
        $run = $attempts[0];
        if (!is_bool($p['ok'] ?? null) || !is_string($p['result'] ?? null)
            || ($p['resultChars'] ?? null) !== mb_strlen($p['result'])
            || ($p['awaitingConfirmation'] ?? null) !== false) {
            throw new RuntimeException('incomplete_test_result');
        }
        $result = json_decode($p['result'], true, flags: JSON_THROW_ON_ERROR);
        $failed = !$p['ok'];
        $exit = $run['payload']['exit'] ?? null;
        $id = $result['workspace'] ?? null;
        if (!is_array($result) || !is_string($id) || $id !== ($run['payload']['workspace'] ?? null)
            || !is_int($exit) || ($result['ran_in_trial'] ?? null) !== true
            || ($result['applied'] ?? null) !== false || array_key_exists('to_apply', $result)
            || !array_key_exists('output', $result)) {
            throw new RuntimeException('native_test_workspace_mismatch');
        }
        if ($failed && (($result['schema'] ?? null) !== 'milpa.trial-test-failure/v1'
            || ($result['ok'] ?? null) !== false || ($result['trial_exit'] ?? null) !== $exit
            || !is_string($result['stderr'] ?? null))) {
            throw new RuntimeException('native_failure_envelope_mismatch');
        }
        if (!$failed && (isset($result['schema']) || ($result['changed'] ?? null) !== [])) {
            throw new RuntimeException('native_success_envelope_mismatch');
        }
        $out = $result['output'];
        if ($out !== null && !is_array($out)) {
            throw new RuntimeException('invalid_test_output');
        }
        // The frozen runner emits one JSON record plus LF. Extra stdout cannot be reconstructed
        // from the durable decoded output: remain indeterminate instead of guessing its bytes.
        $stdout = $out === null ? '' : json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        if (hash('sha256', $stdout) !== ($run['payload']['output_digest'] ?? null)) {
            throw new RuntimeException('test_output_digest_mismatch');
        }
        if ($failed !== ($exit !== 0 || (is_array($out) && array_key_exists('error', $out)))) {
            throw new RuntimeException('native_test_exit_mismatch');
        }
        $outcome = 'unavailable';
        $counts = [];
        if (is_array($out) && array_key_exists('ran', $out)) {
            if (!is_bool($out['ran']) || ($out['ok'] ?? null) !== !$failed) {
                throw new RuntimeException('test_verdict_contradiction');
            }
            foreach (['tests', 'assertions', 'failures', 'errors'] as $key) {
                if (!array_key_exists($key, $out) || ($out[$key] !== null && (!is_int($out[$key]) || $out[$key] < 0))
                    || (!$out['ran'] && $out[$key] !== null)) {
                    throw new RuntimeException('invalid_test_counts');
                }
                $counts[$key] = $out[$key];
            }
            $outcome = $out['ran'] ? ($failed ? 'failed' : 'passed') : 'not_run';
        }
        if (!$failed && ($outcome !== 'passed' || ($counts['tests'] ?? 0) < 1
            || ($counts['assertions'] ?? 0) < 1 || $counts['failures'] !== 0 || $counts['errors'] !== 0)) {
            throw new RuntimeException('test_verdict_missing');
        }
        $ws = $observation['workspaces'][$id] ?? null;
        if (!is_array($ws) || !is_array($ws['copyState'] ?? null) || !is_array($ws['manifest'] ?? null)
            || $ws['copyState'] !== $ws['manifest'] || ($run['payload']['report'] ?? null) !== []) {
            throw new RuntimeException('test_inputs_not_preserved');
        }
        $effects = array_values(array_filter($events, static fn ($e) => $e['type'] === 'session.effect_observed'
            && ($e['payload']['tool'] ?? null) === 'test' && $e['seq'] > $run['seq'] && $e['seq'] < $call['seq']));
        if (count($effects) !== 1) {
            throw new RuntimeException('test_effect_ambiguous');
        }
        $effect = $effects[0]['payload'];
        $expected = FileEffectObserver::testEvidence('test', $args, $ws['copyState'], $out);
        if ((!$failed && $expected === []) || ($failed && $expected !== []) || ($effect['argumentsDigest'] ?? null) !== $digest
            || ($call['payload']['effectObservationSeq'] ?? null) !== $effects[0]['seq']
            || ($effect['observation']['producer'] ?? null) !== 'app-runtime/file-effects/v1'
            || ($effect['observation']['schema'] ?? null) !== 'milpa.agent.effect-observation/v1'
            || ($effect['observation']['known'] ?? null) !== true
            || ($effect['observation']['evidence'] ?? null) !== $expected
            || ($effect['observation']['artifacts'] ?? null) !== []) {
            throw new RuntimeException('test_input_digest_mismatch');
        }
        $artifact = $observation['candidate']['artifact'] ?? null;
        if (!is_array($artifact) || !is_string($artifact['path'] ?? null) || !is_string($artifact['sha256'] ?? null)
            || ($ws['manifest'][$artifact['path']] ?? null) !== $artifact['sha256']) {
            throw new RuntimeException('test_candidate_mismatch');
        }
        if (!is_bool($ws['hasCurrentInputs'] ?? null)) {
            throw new RuntimeException('test_freshness_unknown');
        }
        $base = ['workspace' => $id, 'trialRunSeq' => $run['seq'], 'toolCallSeq' => $call['seq'],
            'scope' => $actual, 'coversRequestedScope' => $actual === $scope, 'exit' => $exit,
            'output' => $out, 'outcome' => $outcome, 'effectSeq' => $effects[0]['seq'], 'candidate' => $artifact] + $counts;
        if (isset($observation['members'])) {
            foreach ($observation['members'] as $member) {
                if (($ws['manifest'][$member['artifact']['path']] ?? null) !== $member['artifact']['sha256']
                    || $member['toolCallSeq'] >= $run['seq']) {
                    throw new RuntimeException('test_member_mismatch');
                }
            }
            $base['members'] = $observation['members'];
        }
        if ($failed) {
            $base['stderr'] = $result['stderr'];
        }
        if ($expected !== []) {
            $base['evidenceDigest'] = $expected[0];
        }
        if (!$ws['hasCurrentInputs']) {
            return $base + ['state' => 'stale'];
        }
        return $base + ['state' => $failed ? 'failed' : ($actual === $scope ? 'current' : 'partial')];
    }

    /**
     * @param list<array<string,mixed>> $events
     * @param array<string,mixed>       $observation
     * @param array<string,mixed>       $target
     *
     * @return array<string,mixed>
     */
    private static function review(array $events, array $observation, array $target): array
    {
        $calls = array_values(array_filter(
            self::calls($events, 'screen_review'),
            static fn ($event) => !self::isCatalogueReview($event['payload'])
        ));
        if ($calls === []) {
            return ['state' => 'missing'];
        }
        $call = end($calls);
        $result = self::result($call);
        $revision = $call['payload']['arguments']['revision'] ?? null;
        if (!is_string($revision) || ($result['ok'] ?? null) !== true || ($result['result']['id'] ?? null) !== $revision) {
            throw new RuntimeException('revision_result_missing');
        }
        $drafts = array_values(array_filter(self::calls($events, 'screen_draft'), static fn ($e) => $e['seq'] < $call['seq']));
        $matching = [];
        foreach ($drafts as $draft) {
            $d = self::result($draft);
            if (($d['result']['id'] ?? null) === $revision) {
                $matching[] = $d['result'];
            }
        }
        if (count($matching) !== 1) {
            throw new RuntimeException('draft_producer_ambiguous');
        }
        $record = $matching[0];
        unset($record['id'], $record['reviewAt'], $record['previewAt']);
        if (ScreenDrafts::hash($record) !== $revision || ($record['kind'] ?? null) !== 'draft'
            || ($record['name'] ?? null) !== $target['name'] || ($record['definition']['type'] ?? null) !== $target['type']) {
            throw new RuntimeException('draft_identity_mismatch');
        }
        if (isset($target['definition']) && ScreenDrafts::hash($record['definition']) !== ScreenDrafts::hash($target['definition'])) {
            throw new RuntimeException('unexpected_screen_definition');
        }
        $current = $observation['reviews'][$revision] ?? null;
        if (($current['ok'] ?? null) !== true) {
            throw new RuntimeException('current_review_unavailable');
        }
        $current = $current['result'];
        $plain = $current;
        unset($plain['id'], $plain['reviewAt'], $plain['previewAt'], $plain['current'], $plain['fresh'], $plain['restorable']);
        if ($plain !== $record || ($current['id'] ?? null) !== $revision) {
            throw new RuntimeException('current_revision_mismatch');
        }
        $historical = $result['result'];
        $old = $historical;
        unset($old['id'], $old['reviewAt'], $old['previewAt'], $old['current'], $old['fresh'], $old['restorable']);
        if ($old !== $record) {
            throw new RuntimeException('recorded_review_mismatch');
        }
        $fresh = ($current['fresh'] ?? null) === true && ($current['build'] ?? null) === ($observation['screenBuild'] ?? null)
            && ScreenDrafts::hash($record['before']) === ScreenDrafts::hash($current['current']);
        return ['state' => $fresh ? 'current' : 'stale', 'revision' => $revision, 'build' => $record['build'],
            'toolCallSeq' => $call['seq'], 'meaning' => 'immutable_draft_read_only'];
    }
}
