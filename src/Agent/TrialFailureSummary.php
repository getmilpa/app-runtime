<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

/** A bounded, actionable front matter for a complete native trial failure. */
final class TrialFailureSummary
{
    private const ERROR_CHARS = 4096;

    /**
     * The original output and stderr remain in the failure envelope. This summary goes first so a
     * bounded tool projection can show what failed and what transition is useful without forcing an
     * agent to page through incidental stack traces or rendered state envelopes.
     *
     * @param array<string, mixed>|null $output
     * @param array<string, mixed>      $arguments
     *
     * @return array<string, mixed>
     */
    public static function from(string $operation, ?array $output, string $stderr, array $arguments): array
    {
        $diagnostic = \is_array($output['diagnostic'] ?? null) ? $output['diagnostic'] : [];
        $counts = \is_array($diagnostic['result'] ?? null) ? $diagnostic['result'] : [];
        if ($counts === []) {
            foreach (['ran', 'tests', 'assertions', 'failures', 'errors'] as $name) {
                if (\array_key_exists($name, $output ?? [])) {
                    $counts[$name] = $output[$name];
                }
            }
        }
        $error = $output['error'] ?? $output['output'] ?? null;
        $mode = \is_string($arguments['mode'] ?? null) ? $arguments['mode'] : null;

        return array_filter([
            'schema' => 'milpa.trial-failure-summary/v1',
            'complete' => true,
            'operation' => $operation,
            'phase' => \is_string($diagnostic['phase'] ?? null) ? $diagnostic['phase'] : null,
            'subject' => \is_string($diagnostic['subject'] ?? null) ? $diagnostic['subject'] : null,
            'judge' => \is_string($diagnostic['selector'] ?? null) ? $diagnostic['selector'] : null,
            'candidate_sha256' => \is_string($diagnostic['submitted_sha256'] ?? null) ? $diagnostic['submitted_sha256'] : null,
            'counts' => $counts === [] ? null : $counts,
            'error_excerpt' => \is_string($error) && $error !== '' ? self::excerpt($error) : null,
            'stderr' => $stderr === '' ? null : ['chars' => mb_strlen($stderr, 'UTF-8'), 'sha256' => hash('sha256', $stderr)],
            'next' => self::next($operation, $mode, $diagnostic),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function excerpt(string $error): string
    {
        if (mb_strlen($error, 'UTF-8') <= self::ERROR_CHARS) {
            return $error;
        }
        $half = intdiv(self::ERROR_CHARS - 32, 2);

        return mb_substr($error, 0, $half, 'UTF-8')
            . "\n… diagnostic middle omitted …\n"
            . mb_substr($error, -$half, null, 'UTF-8');
    }

    /** @param array<string, mixed> $diagnostic */
    private static function next(string $operation, ?string $mode, array $diagnostic): string
    {
        if ($operation === 'implement' && $mode === 'finish') {
            $sha = \is_string($diagnostic['submitted_sha256'] ?? null) ? $diagnostic['submitted_sha256'] : '<candidate_sha256>';

            return 'Repair the staged file with implement mode=amend and expected_sha256=' . $sha
                . '; promote the accepted amendment, then call finish again. Do not repeat finish unchanged.';
        }
        if ($operation === 'implement') {
            $sha = \is_string($diagnostic['submitted_sha256'] ?? null) ? $diagnostic['submitted_sha256'] : '<candidate_sha256>';

            return 'Repair the recorded proposal with edit instead of reconstructing it: use source.session=current '
                . 'session, source.seq=this failed implement tool-call seq, source.sha256=' . $sha
                . ', and exact find/replace edits. Do not resubmit the complete file.';
        }

        return 'Repair the named failure before running this test again.';
    }
}
