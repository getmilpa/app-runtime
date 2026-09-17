<?php

declare(strict_types=1);

/** Candidate location and byte continuity, derived without writes (0375/0692).
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\EffectObservation;
use Milpa\EventStore\Event;

/** Derive a single-file candidate from native receipts and current files (greenhouse 0375/0692). */
final class CandidateState
{
    /**
     * Read current location and byte continuity; this does not grant or reserve a future action.
     *
     * @param list<mixed> $events The trusted session stream, checked for order and stream identity.
     *
     * @return array<string, mixed>
     */
    public static function read(string $root, array $events, string $workspace): array
    {
        clearstatcache(true);
        $base = ['workspace' => $workspace, 'artifact' => null, 'verification' => null, 'evidence' => [],
            'authorization' => 'not_evaluated', 'next' => null];
        /** @param array<string, mixed> $extra */
        $answer = static fn (string $state, string $reason, array $extra = []): array =>
            array_replace($base, ['state' => $state, 'reason' => $reason], $extra);
        if (!preg_match('/^w[a-f0-9]{12,32}$/D', $workspace) || realpath($root) !== $root) {
            return $answer('indeterminate', 'invalid_location');
        }
        $run = $call = null;
        $seq = 0;
        $stream = null;
        foreach ($events as $event) {
            if (!$event instanceof Event || $event->seq <= $seq || ($stream !== null && $stream !== $event->streamId)) {
                return $answer('indeterminate', 'invalid_stream');
            }
            $seq = $event->seq;
            $stream = $event->streamId;
            if ($event->type === 'session.trial_run_recorded' && ($event->payload['workspace'] ?? null) === $workspace) {
                $run = $event;
            }
            if ($event->type === 'session.tool_called' && in_array($event->payload['tool'] ?? '', ['edit', 'implement'], true)) {
                $raw = $event->payload['result'] ?? null;
                $data = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($data) && ($data['workspace'] ?? null) === $workspace) {
                    $call = $event;
                }
            }
        }
        if ($run === null) {
            return $answer('indeterminate', 'trial_receipt_missing');
        }
        if (($run->payload['exit'] ?? null) !== 0) {
            return $answer('contradicted', 'trial_failed');
        }
        if ($call === null || $call->seq <= $run->seq) {
            return $answer('indeterminate', 'producer_result_missing');
        }
        $p = $call->payload;
        $r = json_decode($p['result'], true);
        $out = $r['output'] ?? null;
        if (!in_array($p['tool'] ?? '', ['edit', 'implement'], true)
            || ($run->payload['operation'] ?? null) !== $p['tool']) {
            return $answer('indeterminate', 'unsupported_producer');
        }
        if (!is_array($p['arguments'] ?? null)
            || EffectObservation::argumentsDigest($p['arguments']) !== ($run->payload['arguments_digest'] ?? null)) {
            return $answer('contradicted', 'arguments_mismatch');
        }
        if (($p['resultChars'] ?? null) !== mb_strlen($p['result'])) {
            return $answer('indeterminate', 'producer_result_incomplete');
        }
        if (($p['ok'] ?? null) !== true || ($p['awaitingConfirmation'] ?? true) !== false
            || ($r['ran_in_trial'] ?? null) !== true || ($r['applied'] ?? null) !== false
            || !is_array($out) || ($out['ok'] ?? null) !== true
            || !is_string($out['verified'] ?? null) || trim($out['verified']) === '') {
            return $answer('indeterminate', 'verification_not_declared');
        }
        $next = ['operation' => 'sandbox:promote', 'arguments' => ['workspace' => $workspace]];
        if (($r['to_apply'] ?? null) !== $next) {
            return $answer('contradicted', 'continuation_mismatch');
        }
        $expected = $run->payload['report'] ?? null;
        $path = $out['file'] ?? null;
        if (!is_array($expected) || !is_string($path) || !isset($expected[$path]) || !is_array($expected[$path])
            || !in_array($expected[$path]['status'] ?? '', ['added', 'modified'], true)
            || !is_string($expected[$path]['sha256'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $expected[$path]['sha256'])) {
            return $answer('indeterminate', 'single_file_candidate_not_proven');
        }
        $changed = [$path => $expected[$path]['status']];
        $staging = null;
        if ($p['tool'] === 'implement' && ($p['arguments']['mode'] ?? null) === 'finish') {
            // DevTools finish consumes this exact sibling; it is not a second deliverable.
            // Keep the full report/receipt and prove its baseline bytes below, rather than
            // discarding arbitrary deleted files or every path with a staging suffix.
            $staging = $path . '.milpa-part';
            if (!str_ends_with($path, '.php') || ($expected[$staging] ?? null) !== ['status' => 'deleted', 'sha256' => null]) {
                return $answer('indeterminate', 'single_file_candidate_not_proven');
            }
            $changed[$staging] = 'deleted';
        }
        if (count($expected) !== count($changed) || ($r['changed'] ?? null) !== $changed) {
            return $answer('indeterminate', 'single_file_candidate_not_proven');
        }
        $base['artifact'] = ['path' => $path, 'sha256' => $expected[$path]['sha256']];
        $base['verification'] = ['scope' => 'producer_declaration', 'detail' => $out['verified']];
        $base['evidence'] = ['trialRunSeq' => $run->seq, 'toolCallSeq' => $call->seq];
        /** @param array<string, mixed> $extra */
        $answer = static fn (string $state, string $reason, array $extra = []): array =>
            array_replace($base, ['state' => $state, 'reason' => $reason], $extra);
        try {
            self::path($root, $path);
            self::path($root, 'var/trials/' . $workspace);
            $manifestFile = self::path($root, 'var/trials/' . $workspace . '/manifest.json');
            $manifestRaw = self::bytes($manifestFile);
            $manifest = json_decode($manifestRaw, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || $manifest === []) {
                throw new \RuntimeException('baseline_unreadable');
            }
            foreach ($manifest as $rel => $hash) {
                if (!is_string($rel) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)
                    || $rel === '.env' || in_array(explode('/', $rel)[0], ['var', 'vendor'], true)) {
                    throw new \RuntimeException('baseline_unreadable');
                }
                self::path($root, $rel);
            }
            if (($expected[$path]['status'] === 'modified') !== isset($manifest[$path])) {
                return $answer('contradicted', 'baseline_status_mismatch');
            }
            if ($staging !== null && ($manifest[$staging] ?? null) !== $expected[$path]['sha256']) {
                return $answer('contradicted', 'consumed_staging_not_proven');
            }
            $receiptFile = self::path($root, 'var/trials/' . $workspace . '/promoted.json');
            if (file_exists($receiptFile)) {
                $receiptRaw = self::bytes($receiptFile);
                $receipt = json_decode($receiptRaw, true, flags: JSON_THROW_ON_ERROR);
                if ($receipt !== $expected) {
                    return $answer('contradicted', 'promotion_receipt_mismatch');
                }
                if (self::hash($root, $path) !== $expected[$path]['sha256']) {
                    return $answer('contradicted', 'promoted_bytes_changed');
                }
                if ($staging !== null && file_exists(self::path($root, $staging))) {
                    return $answer('contradicted', 'consumed_staging_reappeared');
                }
                // The baseline covers copied files, not vendor, environment or all execution inputs.
                foreach ($manifest as $rel => $hash) {
                    if ($rel !== $path && $rel !== $staging && self::hash($root, $rel) !== $hash) {
                        return $answer('contradicted', 'recorded_input_changed');
                    }
                }
                if (file_exists(self::path($root, 'var/trials/' . $workspace . '/copy'))) {
                    return $answer('indeterminate', 'promotion_not_settled');
                }
                if (self::bytes($receiptFile) !== $receiptRaw || self::bytes($manifestFile) !== $manifestRaw) {
                    return $answer('indeterminate', 'observation_changed');
                }
                return $answer('promoted', 'receipt_and_host_match', ['evidence' => $base['evidence'] + [
                    'baselineSha256' => hash('sha256', $manifestRaw), 'promotionSha256' => hash('sha256', $receiptRaw)],
                    'inputScope' => 'recorded_copied_files']);
            }
            $copy = self::path($root, 'var/trials/' . $workspace . '/copy');
            if (!is_dir($copy)) {
                return $answer('indeterminate', 'candidate_and_promotion_missing');
            }
            self::regularTree($copy);
            self::regularTree($root);
            $ws = TrialWorkspace::open($root, $workspace);
            if ($ws === null) {
                return $answer('indeterminate', 'candidate_and_promotion_missing');
            }
            if ($ws->manifest() !== $manifest) {
                return $answer('indeterminate', 'observation_changed');
            }
            if ($ws->diff() !== $expected) {
                return $answer('contradicted', 'candidate_bytes_changed');
            }
            foreach ($manifest as $rel => $hash) {
                if (self::hash($root, $rel) !== $hash) {
                    return $answer('contradicted', 'recorded_input_changed');
                }
            }
            if (!$ws->hasCurrentInputs()) {
                return $answer('contradicted', 'copied_inputs_changed');
            }
            if ($ws->stale() !== [] || $ws->diff() !== $expected || self::bytes($manifestFile) !== $manifestRaw
                || file_exists($receiptFile)) {
                return $answer('indeterminate', 'observation_changed');
            }
            return $answer('pending', 'candidate_and_baseline_match', ['evidence' => $base['evidence'] + [
                'baselineSha256' => hash('sha256', $manifestRaw)], 'inputScope' => 'native_copied_inputs',
                'next' => $next + ['requiresRecheck' => true]]);
        } catch (\JsonException) {
            return $answer('indeterminate', 'unreadable_json');
        } catch (\RuntimeException $error) {
            $reason = in_array($error->getMessage(), ['unsafe_path', 'unreadable_file', 'baseline_unreadable'], true)
                ? $error->getMessage() : 'physical_state_unreadable';
            return $answer('indeterminate', $reason);
        }
    }

    private static function path(string $root, string $relative): string
    {
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\') || str_starts_with($relative, '/')) {
            throw new \RuntimeException('unsafe_path');
        }
        $current = $root;
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \RuntimeException('unsafe_path');
            }
            $current .= '/' . $part;
            if (is_link($current)) {
                throw new \RuntimeException('unsafe_path');
            }
        }
        return $current;
    }

    private static function bytes(string $file): string
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new \RuntimeException('unreadable_file');
        }
        $value = file_get_contents($file);
        if ($value === false) {
            throw new \RuntimeException('unreadable_file');
        }
        return $value;
    }

    private static function hash(string $root, string $relative): ?string
    {
        $file = self::path($root, $relative);
        return file_exists($file) ? hash('sha256', self::bytes($file)) : null;
    }

    private static function regularTree(string $root): void
    {
        // Validate traversal before asking the native workspace to hash its copied-file domain.
        $base = strlen($root) + 1;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn ($file) => !in_array(explode('/', substr($file->getPathname(), $base))[0], ['var', 'vendor'], true)
        ), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new \RuntimeException('unsafe_path');
            }
            if (!$file->isReadable() || (!$file->isDir() && !$file->isFile())) {
                throw new \RuntimeException('unreadable_file');
            }
        }
    }
}
