<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

/**
 * Optional host-owned observation around a trial. Implementations must observe outside the
 * tested process, bind their capture to the attempt, and declare partial/unknown observations.
 * This installs no tracer and grants no permission (Greenhouse 0350/0667).
 */
interface TrialInputObserver
{
    /** Prepare the external observation before the executor starts. */
    public function before(TrialInputAttempt $attempt): void;

    /**
     * Return the externally observed record after the executor terminates, never its stdout.
     *
     * @return array<string, mixed>
     */
    public function after(TrialInputAttempt $attempt, int $exit): array;
}
