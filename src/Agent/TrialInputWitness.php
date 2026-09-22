<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

/**
 * Bounded evidence of file consultations in one copied-app execution. Known describes this scope,
 * never complete or semantic execution dependencies. Only the trusted observer constructs it.
 */
abstract readonly class TrialInputWitness
{
    public const SCOPE = 'copied-app-file-content-presence-and-directory-members/v1';
    private const EXCLUDED = ['vendor', 'var', '.phpunit.cache', '.env', 'trial-run.php'];

    /** @param array<string, array{facets: list<string>, before: array<string, mixed>}> $inputs */
    final protected function __construct(
        public TrialInputAttempt $attempt,
        public string $status,
        public array $inputs = [],
    ) {
    }

    /** A configured observer that could not establish an input does not erase failure history. */
    public static function unknown(TrialInputAttempt $attempt): static
    {
        return new static($attempt, 'unknown');
    }

    /** Validate correlation and the closed file-state vocabulary before the guard can use it.
     * @param array<string, mixed> $record
     */
    public static function fromObservation(TrialInputAttempt $attempt, array $record): static
    {
        if (!static::supports($attempt)
            || ($record['id'] ?? null) !== $attempt->id
            || ($record['copy'] ?? null) !== $attempt->copy
            || ($record['operation'] ?? null) !== $attempt->operation
            || ($record['arguments'] ?? null) !== $attempt->arguments
            || ($record['scope'] ?? null) !== self::SCOPE
            || ($record['complete_execution_inputs'] ?? null) !== false
        ) {
            return static::unknown($attempt);
        }
        if (($record['status'] ?? null) === 'partial') {
            return new static($attempt, 'partial');
        }
        $inputs = $record['inputs'] ?? null;
        if (($record['status'] ?? null) !== 'known' || !is_array($inputs) || $inputs === []
            || count($inputs) > 4096 || !static::covers($attempt, $inputs)) {
            return static::unknown($attempt);
        }
        foreach ($inputs as $path => &$entry) {
            if (!is_string($path) || !self::validPath($path) || !is_array($entry)) {
                return static::unknown($attempt);
            }
            $facets = $entry['facets'] ?? null;
            $before = $entry['before'] ?? null;
            if (!is_array($facets) || !array_is_list($facets) || $facets === [] || !is_array($before)) {
                return static::unknown($attempt);
            }
            foreach ($facets as $facet) {
                if (!in_array($facet, ['content', 'presence', 'members'], true)) {
                    return static::unknown($attempt);
                }
            }
            $kind = $before['kind'] ?? null;
            if (!in_array($kind, ['file', 'directory', 'missing'], true)) {
                return static::unknown($attempt);
            }
            $state = ['kind' => $kind];
            if ($kind === 'file' && in_array('content', $facets, true)) {
                if (!is_string($before['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $before['sha256'])) {
                    return static::unknown($attempt);
                }
                $state['sha256'] = $before['sha256'];
            }
            if ($kind === 'directory' && in_array('members', $facets, true)) {
                $members = $before['members'] ?? null;
                if (!is_array($members) || !array_is_list($members)) {
                    return static::unknown($attempt);
                }
                foreach ($members as $member) {
                    if (!is_string($member) || !preg_match('~^[^/\\\\\x00-\x1f]+$~D', $member) || in_array($member, ['.', '..'], true)) {
                        return static::unknown($attempt);
                    }
                }
                sort($members);
                $state['members'] = $members;
            }
            sort($facets);
            $entry = ['facets' => array_values(array_unique($facets)), 'before' => $state];
        }
        unset($entry);
        ksort($inputs);

        return new static($attempt, 'known', $inputs);
    }

    /** Whether this operation belongs to the witness contract. */
    abstract protected static function supports(TrialInputAttempt $attempt): bool;

    /** Whether the observation includes the input required by this operation's contract.
     * @param array<array-key, mixed> $inputs
     */
    abstract protected static function covers(TrialInputAttempt $attempt, array $inputs): bool;

    /** Identity excludes the workspace and attempt, which change even when the input does not. */
    public function identity(): string
    {
        return hash('sha256', (string) json_encode([self::SCOPE, $this->inputs], JSON_UNESCAPED_SLASHES));
    }

    /** True is unchanged, false is changed, null means comparison could not be established. */
    public function matchesCurrent(): ?bool
    {
        if ($this->status !== 'known') {
            return null;
        }
        $changed = false;
        foreach ($this->inputs as $relative => $entry) {
            $path = $this->attempt->root;
            clearstatcache();
            if (is_link($path) || !is_dir($path)) {
                return null;
            }
            foreach ($relative === '.' ? [] : explode('/', $relative) as $part) {
                $path .= '/' . $part;
                if (is_link($path)) {
                    return null;
                }
            }
            $kind = is_file($path) ? 'file' : (is_dir($path) ? 'directory' : 'missing');
            if ($kind === 'missing' && file_exists($path)) {
                return null;
            }
            // A failed lookup under an unreadable parent is not evidence of absence.
            if ($kind === 'missing') {
                $parent = dirname($path);
                while (!file_exists($parent) && $parent !== dirname($parent)) {
                    $parent = dirname($parent);
                }
                if (!is_dir($parent) || !is_readable($parent) || !is_executable($parent)) {
                    return null;
                }
            }
            $current = ['kind' => $kind];
            if ($kind === 'file' && in_array('content', $entry['facets'], true)) {
                $digest = @hash_file('sha256', $path);
                if ($digest === false) {
                    return null;
                }
                $current['sha256'] = $digest;
            }
            if ($kind === 'directory' && in_array('members', $entry['facets'], true)) {
                $members = @scandir($path);
                if ($members === false) {
                    return null;
                }
                $members = array_values(array_diff($members, ['.', '..'], $relative === '.' ? self::EXCLUDED : []));
                sort($members);
                $current['members'] = $members;
            }
            $changed = $changed || $current !== $entry['before'];
        }

        return !$changed;
    }

    /** Paths are relative to the observed copy, without links or traversal aliases. */
    private static function validPath(string $path): bool
    {
        if ($path === '.') {
            return true;
        }
        if ($path === '' || str_starts_with($path, '/') || preg_match('~[\\\\\x00-\x1f]~', $path)) {
            return false;
        }
        $parts = explode('/', $path);

        return array_intersect($parts, ['', '.', '..']) === [] && !in_array($parts[0], self::EXCLUDED, true);
    }
}
