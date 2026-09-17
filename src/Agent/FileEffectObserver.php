<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\EffectObservation;

/**
 * Host-side observation of promotable file effects; failed observation never means an empty diff.
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency
 */
final class FileEffectObserver
{
    /** Snapshot the current copy using the same manifest/diff vocabulary as promotion.
     * @return array<string, string>|null
     */
    public static function trialSnapshot(TrialWorkspace $workspace): ?array
    {
        try {
            $state = $workspace->manifest();
            foreach ($workspace->diff() as $path => $change) {
                if ($change['status'] === 'deleted') {
                    unset($state[$path]);
                } else {
                    $state[$path] = (string) $change['sha256'];
                }
            }
            foreach ($state as $digest) {
                if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                    return null;
                }
            }
            ksort($state);
            return $state;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Capture only the paths the pending promotion can touch.
     * @param list<string> $paths
     *
     * @return array<string, string>|null
     */
    public static function hostSnapshot(string $root, array $paths): ?array
    {
        try {
            $state = [];
            foreach ($paths as $path) {
                $file = $root . '/' . $path;
                if (is_link($file)) {
                    return null;
                }
                if (!file_exists($file)) {
                    continue;
                }
                $digest = hash_file('sha256', $file);
                if ($digest === false) {
                    return null;
                }
                $state[$path] = $digest;
            }
            ksort($state);
            return $state;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Separate proposal and application identities; another workspace never creates novelty.
     * @param array<string, string>|null $before
     * @param array<string, string>|null $after
     * @param list<string>               $evidence
     * @param list<string>               $diagnostics
     */
    public static function compare(?array $before, ?array $after, string $stage, array $evidence = [], array $diagnostics = []): EffectObservation
    {
        if ($before === null || $after === null) {
            return new EffectObservation('app-runtime/file-effects/v1', false);
        }
        $identities = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $path) {
            if (($before[$path] ?? null) !== ($after[$path] ?? null)) {
                $identities[] = hash('sha256', json_encode([$stage, $path, $after[$path] ?? null], JSON_THROW_ON_ERROR));
            }
        }
        sort($identities);
        return new EffectObservation('app-runtime/file-effects/v1', true, $identities, $evidence, $diagnostics);
    }

    /** A structured native test verdict witnesses behavior of this exact copied input tree.
     * @param array<string, mixed>       $arguments
     * @param array<string, string>|null $state
     * @param array<string, mixed>|null  $output
     *
     * @return list<string>
     */
    public static function testEvidence(string $tool, array $arguments, ?array $state, ?array $output): array
    {
        if ($tool !== 'test' || $state === null || ($output['ok'] ?? null) !== true
            || ($output['ran'] ?? null) !== true || !is_int($output['tests'] ?? null) || $output['tests'] < 1
            || !is_int($output['assertions'] ?? null) || $output['assertions'] < 1
            || ($output['errors'] ?? null) !== 0 || ($output['failures'] ?? null) !== 0) {
            return [];
        }
        return [hash('sha256', json_encode(['native-test/v1', EffectObservation::argumentsDigest(['path' => trim((string) ($arguments['path'] ?? '')), 'filter' => trim((string) ($arguments['filter'] ?? ''))]), $state], JSON_THROW_ON_ERROR))];
    }

    /** A failed executed assertion can inform the next step without certifying behavior.
     * Stable scope and copied bytes consume this diagnostic once; output prose and trial ids do not.
     *
     * @param array<string, mixed>      $arguments
     * @param array<mixed>|null         $state
     * @param array<string, mixed>|null $output
     *
     * @return list<string>
     */
    public static function testDiagnostics(string $tool, array $arguments, ?array $state, ?array $output): array
    {
        if ($tool !== 'test' || $state === null || $state === [] || ($output['ok'] ?? null) !== false
            || ($output['ran'] ?? null) !== true || !is_int($output['tests'] ?? null) || $output['tests'] < 1
            || !is_int($output['assertions'] ?? null) || $output['assertions'] < 1
            || ($output['errors'] ?? null) !== 0 || !is_int($output['failures'] ?? null)
            || $output['failures'] < 1 || $output['failures'] > $output['tests']
            || !is_string($arguments['path'] ?? null) || trim($arguments['path']) === ''
            || !is_string($arguments['filter'] ?? '')) {
            return [];
        }
        $path = trim($arguments['path']);
        if (str_starts_with($path, '/')) {
            return [];
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return [];
                }
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }
        foreach ($state as $name => $digest) {
            if (!is_string($name) || $name === '' || !is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                return [];
            }
        }
        ksort($state);
        $scope = ['path' => implode('/', $parts), 'filter' => trim($arguments['filter'] ?? '')];
        return [hash('sha256', json_encode(['native-test-diagnostic/v1', EffectObservation::argumentsDigest($scope), $state], JSON_THROW_ON_ERROR))];
    }

}
