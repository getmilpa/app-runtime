<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\DevTools\Operations\ImplementationBody;
use Milpa\DevTools\Operations\StaticAnalysisFindings;
use Milpa\DevTools\Operations\SyntaxFinding;

/** Bind the landing judge to its proposal, whether preserved outside the target or rolled back. */
final readonly class AuthoringDiagnostic
{
    /** @param array<string, string>      $expected
     * @param array<string, string>|null                                                  $behavior
     * @param array{parser: string, message: string, line: int, fingerprint: string}|null $syntax
     */
    private function __construct(private array $expected, private ?array $behavior, private ?array $syntax)
    {
    }

    /** Observe the authorized subject before invoking the trial, never infer it from error prose.
     * @param array<string, mixed>       $arguments
     * @param array<string, string>|null $state
     * @param list<string>|null          $writePaths
     */
    public static function prepare(string $tool, array $arguments, TrialWorkspace $workspace, ?array $state, ?array $writePaths): ?self
    {
        if ($tool !== 'implement' || $state === null || $writePaths === null
            || !class_exists(ImplementationBody::class)) {
            return null;
        }
        $plugin = $arguments['plugin'] ?? null;
        $class = $arguments['class'] ?? null;
        $mode = $arguments['mode'] ?? null;
        if (!is_string($plugin) || !is_string($class)
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $plugin)
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $class)
            || !in_array($mode, [null, 'finish'], true)
            || !in_array('src/Plugins/' . $plugin, $writePaths, true)) {
            return null;
        }
        $find = static fn (string $tree, string $basename): array => array_values(array_filter(
            array_keys($state),
            static fn (string $path): bool => str_starts_with($path, $tree . '/') && basename($path) === $basename,
        ));
        $subjects = $find('src/Plugins/' . $plugin, $class . '.php');
        $selectors = $find('tests/Plugins/' . $plugin, $class . 'Test.php');
        if (count($subjects) !== 1) {
            return null;
        }
        $subject = $subjects[0];
        $content = $arguments['content'] ?? null;
        if ($mode === 'finish') {
            $part = $subject . '.milpa-part';
            if (($content !== null && $content !== '') || !isset($state[$part]) || is_link($workspace->copy . '/' . $part)) {
                return null;
            }
            $content = @file_get_contents($workspace->copy . '/' . $part);
            if (!is_string($content) || hash('sha256', $content) !== $state[$part]) {
                return null;
            }
        }
        if (!is_string($content) || $content === '') {
            return null;
        }
        if (!is_file($workspace->copy . '/' . $subject) || is_link($workspace->copy . '/' . $subject)
            || hash_file('sha256', $workspace->copy . '/' . $subject) !== $state[$subject]) {
            return null;
        }
        $behavior = null;
        if (count($selectors) === 1) {
            $selector = $selectors[0];
            if (is_file($workspace->copy . '/' . $selector) && !is_link($workspace->copy . '/' . $selector)
                && hash_file('sha256', $workspace->copy . '/' . $selector) === $state[$selector]) {
                $behavior = ['selector' => $selector, 'selector_sha256' => $state[$selector]];
            }
        }
        $normalized = ImplementationBody::normalize($content, $subject);
        return new self([
            'subject' => $subject,
            'submitted_sha256' => hash('sha256', $content),
            'judged_sha256' => hash('sha256', $normalized),
            'restored_sha256' => $state[$subject],
        ], $behavior, class_exists(SyntaxFinding::class) ? SyntaxFinding::inspect($normalized) : null);
    }

    /** Bind judged bytes, then credit information according to the judge's novelty contract.
     * Runtime errors report a failed scoped execution, not proof that the proposal caused it.
     *
     * @param array<string, string>|null $before
     * @param array<string, string>|null $after
     * @param array<string, mixed>|null  $output
     *
     * @return list<string>
     */
    public function identities(?array $before, ?array $after, ?array $output, int $exit): array
    {
        $receipt = $output['diagnostic'] ?? null;
        if ($exit !== 1 || $before === null || $before === [] || $before !== $after
            || ($output['ok'] ?? null) !== false || !is_array($receipt)
            || ($receipt['schema'] ?? null) !== 'milpa.authoring-diagnostic/v1'
            || ($receipt['stable_subject'] ?? null) !== true) {
            return [];
        }
        $syntax = ($receipt['phase'] ?? null) === 'syntax';
        if ($syntax) {
            if (($receipt['candidate_installed'] ?? null) !== false
                || ($receipt['destination_preserved'] ?? null) !== true
                || array_key_exists('rolled_back', $receipt) || array_key_exists('restored_sha256', $receipt)) {
                return [];
            }
        } elseif (($receipt['rolled_back'] ?? null) !== true) {
            return [];
        }
        foreach ($this->expected as $key => $value) {
            if ($syntax && $key === 'restored_sha256') {
                $key = 'preserved_sha256';
            }
            if (($receipt[$key] ?? null) !== $value) {
                return [];
            }
        }
        // The staged body has already been bound as the proposal. Its transport does not renew it.
        unset($before[$this->expected['subject'] . '.milpa-part']);
        ksort($before);
        if ($syntax) {
            $result = $receipt['result'] ?? null;
            if ($this->syntax === null || !is_array($result) || count($result) !== count($this->syntax)) {
                return [];
            }
            foreach ($this->syntax as $key => $value) {
                if (($result[$key] ?? null) !== $value) {
                    return [];
                }
            }
            return [hash('sha256', json_encode([
                'native-syntax-diagnostic/v1', $this->expected['subject'], $this->syntax['fingerprint'], $before,
            ], JSON_THROW_ON_ERROR))];
        }
        if (($receipt['phase'] ?? null) === 'static-analysis') {
            return $this->staticIdentities($receipt['result'] ?? null, $before);
        }
        if (($receipt['phase'] ?? null) !== 'behavior' || $this->behavior === null) {
            return [];
        }
        foreach ($this->behavior as $key => $value) {
            if (($receipt[$key] ?? null) !== $value) {
                return [];
            }
        }
        $result = $receipt['result'] ?? null;
        if (!is_array($result) || !in_array($result['exit'] ?? null, [1, 2], true)) {
            return [];
        }
        foreach (['tests', 'assertions', 'failures', 'errors'] as $count) {
            if (!is_int($result[$count] ?? null) || $result[$count] < 0) {
                return [];
            }
        }
        if ($result['tests'] < 1 || $result['failures'] + $result['errors'] < 1
            || $result['failures'] + $result['errors'] > $result['tests']) {
            return [];
        }
        return [hash('sha256', json_encode([
            'native-authoring-diagnostic/v1', $this->expected['subject'], $this->expected['judged_sha256'],
            $this->behavior['selector'], $before,
        ], JSON_THROW_ON_ERROR))];
    }

    /** A different body is not new static information when its rule findings are unchanged.
     * @param array<string, string> $before
     *
     * @return list<string>
     */
    private function staticIdentities(mixed $result, array $before): array
    {
        if (!class_exists(StaticAnalysisFindings::class) || !is_array($result)
            || ($result['exit'] ?? null) !== 1 || !is_int($result['errors'] ?? null)
            || !is_array($result['findings'] ?? null) || $result['errors'] !== count($result['findings'])) {
            return [];
        }
        $fingerprint = StaticAnalysisFindings::fingerprint($result['findings']);
        if ($fingerprint === null || ($result['fingerprint'] ?? null) !== $fingerprint) {
            return [];
        }
        return [hash('sha256', json_encode([
            'native-static-analysis-diagnostic/v1', $this->expected['subject'], $fingerprint, $before,
        ], JSON_THROW_ON_ERROR))];
    }
}
