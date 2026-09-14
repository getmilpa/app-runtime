<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\AppRuntime\Web\ScreenBuild;
use Milpa\AppRuntime\Web\ScreenDrafts;
use Milpa\EventStore\Event;

/** Read candidate/test/draft evidence from native authorities without granting acceptance (greenhouse 0381/0698). */
final class AcceptanceEvidence
{
    /**
     * Read the trusted session and current files. This is a sampled observation, never a lock or approval.
     *
     * Test scope is the exact requested path/filter. The screen target requires name/type and may
     * include an exact definition. The host supplies its native ScreenDrafts service (including any
     * configured active store). Missing services or unverifiable stdout remain indeterminate.
     * Diagnostics stay in the complete session.tool_called receipt, identified by sequence and hash.
     *
     * @param list<mixed>         $events Trusted native session events, not model-window projections.
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $screen
     *
     * @return array<string,mixed>
     */
    public static function read(string $root, array $events, string $workspace, array $scope, array $screen, ?ScreenDrafts $drafts): array
    {
        $base = ['schema' => 'milpa.acceptance-evidence/v1', 'authorization' => 'not_evaluated', 'humanApproval' => 'not_recorded'];
        if (realpath($root) !== $root || !preg_match('/^w[a-f0-9]{12,32}$/D', $workspace)) {
            return $base + ['state' => 'indeterminate', 'reason' => 'invalid_location'];
        }
        if (!is_string($scope['path'] ?? null) || !is_string($scope['filter'] ?? null)
            || array_diff(array_keys($scope), ['path', 'filter']) !== []
            || !is_string($screen['name'] ?? null) || !is_string($screen['type'] ?? null)
            || $screen['name'] === '' || $screen['type'] === ''
            || (array_key_exists('definition', $screen) && !is_array($screen['definition']))) {
            return $base + ['state' => 'indeterminate', 'reason' => 'invalid_requested_scope'];
        }
        $scope = ['path' => trim($scope['path']), 'filter' => trim($scope['filter'])];
        $rows = [];
        $seq = 0;
        $stream = null;
        foreach ($events as $e) {
            if (!$e instanceof Event || $e->seq <= $seq || ($stream !== null && $stream !== $e->streamId)) {
                return $base + ['state' => 'indeterminate', 'reason' => 'invalid_stream'];
            }
            $seq = $e->seq;
            $stream = $e->streamId;
            $rows[] = ['seq' => $e->seq, 'streamId' => $e->streamId, 'type' => $e->type, 'payload' => $e->payload];
        }
        try {
            $observation = self::observe($root, $events, $rows, $workspace, $drafts);
            $result = AcceptanceEvidenceJoin::read($observation, $scope, $screen);
            // Re-observe through the same authorities to reject changes during collection. This
            // cannot reserve later bytes and does not certify inputs outside native copied files.
            if ($observation !== self::observe($root, $events, $rows, $workspace, $drafts)) {
                return $base + ['state' => 'indeterminate', 'reason' => 'observation_changed'];
            }
            if (isset($result['test']['toolCallSeq'])) {
                foreach ($rows as $row) {
                    if ($row['seq'] === $result['test']['toolCallSeq']) {
                        $raw = $row['payload']['result'];
                        $result['test']['receipt'] = ['toolCallSeq' => $row['seq'], 'characters' => mb_strlen($raw), 'sha256' => hash('sha256', $raw)];
                    }
                }
                if (isset($result['test']['output']['ran'])) {
                    $result['test']['ran'] = $result['test']['output']['ran'];
                }
                unset($result['test']['output'], $result['test']['stderr'], $result['test']['diagnostic']);
            }
            return $base + $result;
        } catch (\Throwable) {
            return $base + ['state' => 'indeterminate', 'reason' => 'native_observation_unavailable'];
        }
    }

    /**
     * @param list<mixed>               $events
     * @param list<array<string,mixed>> $rows
     *
     * @return array<string,mixed>
     */
    private static function observe(string $root, array $events, array $rows, string $workspace, ?ScreenDrafts $drafts): array
    {
        clearstatcache(true);
        $candidate = CandidateState::read($root, $events, $workspace);
        $workspaces = [];
        $reviews = [];
        $test = $review = null;
        foreach ($rows as $row) {
            if ($row['type'] === 'session.tool_called') {
                if (($row['payload']['tool'] ?? null) === 'test') {
                    $test = $row;
                }
                if (($row['payload']['tool'] ?? null) === 'screen_review' && !AcceptanceEvidenceJoin::isCatalogueReview($row['payload'])) {
                    $review = $row;
                }
            }
        }
        if ($test !== null && is_string($test['payload']['result'] ?? null)) {
            $result = json_decode($test['payload']['result'], true);
            $id = $result['workspace'] ?? null;
            if (is_string($id)) {
                if (!preg_match('/^w[a-f0-9]{12,32}$/D', $id)) {
                    throw new \RuntimeException('Invalid trial');
                }
                $base = $root . '/var/trials/' . $id;
                foreach ([$root . '/var', $root . '/var/trials', $base, $base . '/copy', $base . '/manifest.json'] as $path) {
                    if (is_link($path)) {
                        throw new \RuntimeException('Unsafe trial');
                    }
                }
                $ws = TrialWorkspace::open($root, $id);
                if ($ws !== null) {
                    $manifest = json_decode((string) file_get_contents($base . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
                    if (!is_array($manifest) || $manifest === [] || $manifest !== $ws->manifest()) {
                        throw new \RuntimeException('Invalid manifest');
                    }
                    $workspaces[$id] = ['manifest' => $manifest, 'copyState' => FileEffectObserver::trialSnapshot($ws), 'hasCurrentInputs' => $ws->hasCurrentInputs()];
                }
            }
        }
        if ($review !== null && $drafts !== null) {
            $id = $review['payload']['arguments']['revision'] ?? null;
            if (is_string($id)) {
                $reviews[$id] = ['ok' => true, 'result' => $drafts->review($id)];
            }
        }
        return ['candidate' => $candidate, 'workspaces' => $workspaces, 'reviews' => $reviews,
            'screenBuild' => (new ScreenBuild($root))->fingerprint(), 'events' => $rows];
    }
}
