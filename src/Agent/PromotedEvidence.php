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

namespace Milpa\AppRuntime\Agent;

/**
 * Acceptance follows the promotion (greenhouse decisions/0469).
 *
 * The acceptance reader was built for candidates `implement` produces and tests confined in the trial.
 * After a declared screen was rehearsed, promoted and checked IN THE HOUSE, it answered `indeterminate`
 * over evidence that existed (evidence/0998, 1004). This reads the chain as it happened, without
 * collapsing its verbs:
 *
 *     declared (in the trial) → promoted (the promotion's receipt) → tested IN THE HOUSE → observed IN THE HOUSE
 *
 * It starts from the promotion's own receipt for the requested workspace and reads only what happened
 * after it, outside any trial: the requested test, and `screen:observe` of the requested screen. A
 * missing link is named by the verb that would supply it. It approves nothing.
 */
final class PromotedEvidence
{
    /**
     * The chain for a promoted workspace, or null when the stream records no promotion of it.
     *
     * @param list<array{seq: int, type: string, payload: array<string, mixed>}> $rows
     * @param array{path: string, filter: string}                                $scope
     * @param array<string, mixed>                                               $screen
     *
     * @return array<string, mixed>|null
     */
    public static function read(array $rows, string $workspace, array $scope, array $screen): ?array
    {
        $promotedAt = null;
        $declaredAt = null;
        foreach ($rows as $row) {
            $result = self::result($row, 'sandbox_promote');
            if ($result !== null && ($result['ok'] ?? null) === true
                && ($result['evidence']['predicate'] ?? null) === 'promoted'
                && ($result['evidence']['from']['workspace'] ?? null) === $workspace) {
                $promotedAt = $row['seq'];
            }
            $declared = self::result($row, 'screen_declare');
            if ($declared !== null && ($declared['ran_in_trial'] ?? null) === true
                && ($declared['workspace'] ?? null) === $workspace
                && ($declared['output']['screen'] ?? null) === $screen['name']) {
                $declaredAt = $row['seq'];
            }
        }
        if ($promotedAt === null) {
            return null;
        }

        $test = ['state' => 'missing'];
        $observed = ['state' => 'missing'];
        foreach ($rows as $row) {
            if ($row['seq'] <= $promotedAt) {
                continue;
            }
            $ran = self::result($row, 'test');
            $arguments = \is_array($row['payload']['arguments'] ?? null) ? $row['payload']['arguments'] : [];
            if ($ran !== null && ! isset($ran['ran_in_trial'])
                && trim((string) ($arguments['path'] ?? '')) === $scope['path']
                && trim((string) ($arguments['filter'] ?? '')) === $scope['filter']) {
                $passed = ($ran['ok'] ?? null) === true && (int) ($ran['failures'] ?? 1) === 0 && (int) ($ran['errors'] ?? 1) === 0;
                $test = ['state' => $passed ? 'current' : 'failed', 'environment' => 'house', 'toolCallSeq' => $row['seq'],
                    'tests' => $ran['tests'] ?? null, 'failures' => $ran['failures'] ?? null, 'errors' => $ran['errors'] ?? null];
            }
            $seen = self::result($row, 'screen_observe');
            if ($seen !== null && ($seen['screen'] ?? null) === $screen['name']) {
                $observed = ($seen['ok'] ?? null) === true && ($seen['evidence']['environment']['kind'] ?? null) === 'house'
                    ? ['state' => 'current', 'environment' => 'house', 'toolCallSeq' => $row['seq'], 'status' => $seen['status'] ?? null]
                    : ['state' => 'not_served', 'environment' => 'house', 'toolCallSeq' => $row['seq'], 'status' => $seen['status'] ?? null];
            }
        }

        $missing = [];
        if ($test['state'] === 'missing') {
            $missing[] = ['operation' => 'test', 'arguments' => array_filter($scope, static fn (string $v): bool => $v !== '')];
        }
        if ($observed['state'] !== 'current') {
            $missing[] = ['operation' => 'screen:observe', 'arguments' => ['name' => $screen['name']]];
        }
        $state = match (true) {
            $test['state'] === 'failed' => 'failed',
            $missing === [] => 'current_evidence',
            default => 'incomplete',
        };

        return [
            'state' => $state,
            'provenance' => ['workspace' => $workspace, 'declaredAtSeq' => $declaredAt, 'promotedAtSeq' => $promotedAt],
            'test' => $test,
            'screen' => $observed,
            ...($missing === [] ? [] : ['missing' => $missing]),
        ];
    }

    /**
     * A completed, confirmed call of `$tool`, decoded — or null for anything else.
     *
     * @param array{seq: int, type: string, payload: array<string, mixed>} $row
     *
     * @return array<string, mixed>|null
     */
    private static function result(array $row, string $tool): ?array
    {
        $payload = $row['payload'];
        if ($row['type'] !== 'session.tool_called' || ($payload['tool'] ?? null) !== $tool
            || ($payload['awaitingConfirmation'] ?? true) !== false || ! \is_string($payload['result'] ?? null)) {
            return null;
        }
        $decoded = json_decode($payload['result'], true);

        return \is_array($decoded) ? $decoded : null;
    }
}
