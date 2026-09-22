<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

/** File consultations from a test must include its requested test path. */
final readonly class TestInputWitness extends TrialInputWitness
{
    /** Retain the published test-only contract. */
    protected static function supports(TrialInputAttempt $attempt): bool
    {
        return $attempt->operation === 'test';
    }

    /** @param array<array-key, mixed> $inputs */
    protected static function covers(TrialInputAttempt $attempt, array $inputs): bool
    {
        $test = $attempt->arguments['path'] ?? null;

        return is_string($test) && isset($inputs[$test]);
    }
}
