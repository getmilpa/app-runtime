<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\DevTools\Operations\ImplementationBody;

/** Bind the landing judge to its transient proposal before rollback hides that proposal. */
final readonly class AuthoringDiagnostic
{
    /** @param array<string, string> $expected */
    private function __construct(private array $expected)
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
        if (count($subjects) !== 1 || count($selectors) !== 1) {
            return null;
        }
        $subject = $subjects[0];
        $selector = $selectors[0];
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
        foreach ([$subject, $selector] as $path) {
            if (is_link($workspace->copy . '/' . $path) || hash_file('sha256', $workspace->copy . '/' . $path) !== $state[$path]) {
                return null;
            }
        }
        return new self([
            'subject' => $subject,
            'submitted_sha256' => hash('sha256', $content),
            'judged_sha256' => hash('sha256', ImplementationBody::normalize($content, $subject)),
            'restored_sha256' => $state[$subject],
            'selector' => $selector,
            'selector_sha256' => $state[$selector],
        ]);
    }

    /** Credit information once for the same judged bytes, selector and copied tree.
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
            || ($receipt['phase'] ?? null) !== 'behavior'
            || ($receipt['stable_subject'] ?? null) !== true || ($receipt['rolled_back'] ?? null) !== true) {
            return [];
        }
        foreach ($this->expected as $key => $value) {
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
        // The staged body has already been bound as the proposal. Its transport does not renew it.
        unset($before[$this->expected['subject'] . '.milpa-part']);
        ksort($before);
        return [hash('sha256', json_encode([
            'native-authoring-diagnostic/v1', $this->expected['subject'], $this->expected['judged_sha256'],
            $this->expected['selector'], $before,
        ], JSON_THROW_ON_ERROR))];
    }
}
