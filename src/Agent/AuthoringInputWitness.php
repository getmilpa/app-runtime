<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

/**
 * File consultations for an assembly continuation must include one staging sibling in its plugin.
 * The observer identifies the consulted path; this class does not guess a class filename or grant
 * authority. Inline content, new assemblies and unobserved staging retain the unknown fallback.
 */
final readonly class AuthoringInputWitness extends TrialInputWitness
{
    /** Limit observation admission to continuation of an existing assembly. */
    protected static function supports(TrialInputAttempt $attempt): bool
    {
        return $attempt->operation === 'implement'
            && in_array($attempt->arguments['mode'] ?? null, ['append', 'amend', 'finish'], true);
    }

    /** @param array<array-key, mixed> $inputs */
    protected static function covers(TrialInputAttempt $attempt, array $inputs): bool
    {
        $plugin = $attempt->arguments['plugin'] ?? null;
        $class = $attempt->arguments['class'] ?? null;
        if (!is_string($plugin) || !is_string($class)
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $plugin)
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $class)) {
            return false;
        }
        $staging = 0;
        foreach ($inputs as $path => $entry) {
            if (is_string($path) && str_ends_with($path, '.php.milpa-part')
                && (str_starts_with($path, 'src/Plugins/' . $plugin . '/')
                    || str_starts_with($path, 'tests/Plugins/' . $plugin . '/'))
                && is_array($entry) && is_array($entry['facets'] ?? null)
                && in_array('presence', $entry['facets'], true)) {
                ++$staging;
            }
        }

        return $staging === 1;
    }
}
