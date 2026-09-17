<?php

declare(strict_types=1);
/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\Session;
use Milpa\Agent\SessionFacts;

/** Resolve one delivery obligation using fresh native evidence (greenhouse 0385/0702 V2, 0386/0703). */
final class DeliveryClosure
{
    /** Derive from a trusted current observation supplied by the native caller; never reuse a cached model answer.
     * @param array<string,mixed>|null $contract
     * @param array<string,mixed>|null $evidence
     *
     * @return array<string,mixed>
     */
    public static function derive(Session $session, SessionFacts $facts, ?array $contract, ?array $evidence): array
    {
        if ($contract === null) {
            return ClosureVerdict::derive($session, $facts);
        }
        $ledger = ['session' => $session->id, 'workState' => $facts->workState(),
            'openTodos' => $session->pendingTodos(),
            'unverifiedDones' => array_map(static fn ($todo) => $todo->id, $session->unverifiedDones()),
            'verifiedTodos' => array_filter($session->todos, static fn ($todo) => $session->isDoneVerified($todo->id))];
        $reasons = [];
        $bound = self::bound($ledger, $contract, $evidence);
        $positive = $bound && self::positive($evidence);
        if (!$bound) {
            $reasons[] = 'delivery observation does not match the declared contract';
        } elseif (!$positive) {
            $reasons[] = 'declared delivery has no current positive evidence';
        }
        $producers = $bound ? [$evidence['candidate']['evidence']['toolCallSeq'] ?? null] : [];
        if ($bound && isset($contract['members'])) {
            $producers = array_column($evidence['test']['members'] ?? [], 'toolCallSeq');
        }
        $test = $bound ? ($evidence['test']['toolCallSeq'] ?? null) : null;
        $matches = [];
        $unique = true;
        foreach ($producers as $producer) {
            $found = [];
            foreach ($ledger['workState']['artifacts'] as $key => $entry) {
                foreach ($entry['attempts'] as $attempt) {
                    if (is_int($producer) && $attempt['seq'] === $producer) {
                        $found[] = $key;
                        break;
                    }
                }
            }
            if (count($found) !== 1) {
                $unique = false;
            }
            $matches = array_merge($matches, $found);
        }
        if (!$unique || $producers === [] || count($matches) !== count($producers) || count(array_unique($matches)) !== count($producers)) {
            $positive = false;
            $reasons[] = 'delivery producer does not identify exactly one recorded artifact';
        }
        $hasEvidence = $ledger['verifiedTodos'] !== [];
        foreach ($ledger['unverifiedDones'] as $id) {
            $reasons[] = "todo {$id} done without evidence";
        }
        $open = count($ledger['openTodos']);
        if ($open > 0) {
            $reasons[] = $open === 1 ? '1 todo open' : "{$open} todos open";
        }
        foreach ($ledger['workState']['artifacts'] as $key => $entry) {
            $artifact = $entry['artifact']['value'] ?? '?';
            $verification = $entry['verification'] ?? null;
            $current = ($entry['state'] ?? null) === 'verified';
            $bridge = $positive && in_array($key, $matches, true);
            $touched = false;
            foreach ($entry['attempts'] as $attempt) {
                if (($attempt['mutating'] ?? false) === true && ($attempt['awaitingConfirmation'] ?? null) !== true) {
                    $touched = true;
                    if ($bridge && $attempt['seq'] > $test) {
                        $bridge = false;
                        $reasons[] = 'declared delivery changed after its test receipt';
                    }
                }
            }
            if (is_array($verification) && ($verification['verified'] ?? null) === false) {
                $judge = $verification['operation'] ?? '?';
                $reasons[] = "judge {$judge} recorded red for {$artifact}";
                continue;
            }
            $current = $current || $bridge;
            $hasEvidence = $hasEvidence || $current;
            if (!$current && ($touched || $verification !== null)) {
                $reasons[] = "artifact {$artifact} has no current verification";
            }
        }
        if (!$hasEvidence) {
            $reasons[] = 'no positive verification evidence recorded';
        }
        if (count($reasons) > 16) {
            $overflow = count($reasons) - 15;
            $reasons = array_slice($reasons, 0, 15);
            $reasons[] = "… and {$overflow} more recorded facts";
        }
        return ['verified' => $reasons === [], 'reasons' => $reasons, 'scope' => 'declared_delivery_and_recorded_work',
            'evidenceState' => $bound ? ($evidence['state'] ?? 'indeterminate') : 'indeterminate',
            'authorization' => 'not_evaluated', 'humanApproval' => 'not_recorded', 'browserBehavior' => 'not_observed',
            'meaning' => 'sampled_observation_not_a_lock_or_approval'];
    }

    /** @param array<string,mixed> $ledger
     * @param array<string,mixed>      $contract
     * @param array<string,mixed>|null $e
     */
    private static function bound(array $ledger, array $contract, ?array $e): bool
    {
        if ($e === null || ($e['ok'] ?? null) !== true || ($e['schema'] ?? null) !== 'milpa.acceptance-evidence/v1'
            || ($contract['session'] ?? null) !== $ledger['session'] || ($e['session'] ?? null) !== $ledger['session']
            || !is_string($contract['workspace'] ?? null) || !preg_match('/^w[a-f0-9]{12,32}$/D', $contract['workspace'])
            || ($e['candidate']['workspace'] ?? null) !== $contract['workspace']
            || !is_string($contract['artifactPath'] ?? null) || $contract['artifactPath'] === ''
            || ($e['candidate']['artifact']['path'] ?? null) !== $contract['artifactPath']
            || !is_array($contract['test'] ?? null) || !is_array($contract['screen'] ?? null)
            || self::same($e['scope']['test'] ?? null, $contract['test']) === false || self::same($e['scope']['screen'] ?? null, $contract['screen']) === false
            || self::same($e['scope']['members'] ?? null, $contract['members'] ?? null) === false) {
            return false;
        }
        return ($e['authorization'] ?? null) === 'not_evaluated' && ($e['humanApproval'] ?? null) === 'not_recorded'
            && ($e['scope']['browserBehavior'] ?? null) === 'not_observed';
    }

    /** Compare objects without weakening scalar types or list order. */
    private static function same(mixed $a, mixed $b): bool
    {
        if (!is_array($a) || !is_array($b)) {
            return $a === $b;
        }
        if (array_is_list($a) !== array_is_list($b)) {
            return false;
        }
        if (!array_is_list($a)) {
            ksort($a);
            ksort($b);
        }
        if (array_keys($a) !== array_keys($b)) {
            return false;
        }
        foreach ($a as $key => $value) {
            if (!self::same($value, $b[$key])) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $e */
    private static function positive(array $e): bool
    {
        $candidate = $e['candidate'];
        $test = $e['test'] ?? [];
        $screen = $e['screen'] ?? [];
        if (($e['state'] ?? null) !== 'current_evidence' || ($candidate['state'] ?? null) !== 'promoted'
            || ($candidate['reason'] ?? null) !== 'receipt_and_host_match' || ($test['state'] ?? null) !== 'current'
            || ($test['ran'] ?? null) !== true || ($test['coversRequestedScope'] ?? null) !== true
            || ($test['outcome'] ?? null) !== 'passed' || ($test['failures'] ?? null) !== 0 || ($test['errors'] ?? null) !== 0
            || !is_int($test['tests'] ?? null) || $test['tests'] < 1 || !is_int($test['assertions'] ?? null) || $test['assertions'] < 0
            || ($test['candidate'] ?? null) !== $candidate['artifact'] || ($screen['state'] ?? null) !== 'current') {
            return false;
        }
        foreach ([$candidate['artifact']['sha256'] ?? null, $screen['revision'] ?? null, $screen['build'] ?? null] as $hash) {
            if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                return false;
            }
        }
        $producer = $candidate['evidence']['toolCallSeq'] ?? null;
        $trial = $test['trialRunSeq'] ?? null;
        $call = $test['toolCallSeq'] ?? null;
        if (isset($e['scope']['members'])) {
            $members = $test['members'] ?? null;
            if (!is_array($members) || !array_is_list($members) || $members === []
                || array_column(array_column($members, 'artifact'), 'path') !== $e['scope']['members']) {
                return false;
            }
            $selected = false;
            foreach ($members as $member) {
                if (!is_int($member['trialRunSeq'] ?? null) || !is_int($member['toolCallSeq'] ?? null)
                    || $member['trialRunSeq'] < 1 || $member['trialRunSeq'] >= $member['toolCallSeq'] || $member['toolCallSeq'] >= $trial) {
                    return false;
                }
                foreach ([$member['artifact']['sha256'] ?? null, $member['baselineSha256'] ?? null, $member['promotionSha256'] ?? null] as $hash) {
                    if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                        return false;
                    }
                }
                $selected = $selected || ($member['toolCallSeq'] === $producer && $member['artifact'] === $candidate['artifact'] && ($member['workspace'] ?? null) === $candidate['workspace']);
            }
            if (!$selected) {
                return false;
            }
        }
        return is_int($producer) && $producer > 0 && is_int($trial) && is_int($call) && $producer < $trial && $trial < $call
            && ($test['receipt']['toolCallSeq'] ?? null) === $call;
    }
}
