<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

/**
 * The result of running one operation inside a trial: what it exited with, what it printed, the
 * bounds it ran under, and the diff the HOST computed afterwards. A fact, not a claim.
 */
final readonly class TrialRun
{
    /**
     * @param array<string, mixed>|null                             $output the runner's JSON, decoded
     * @param array{fs: string, net: string, pid: string}           $bounds the confinement imposed
     * @param array<string, array{status: string, sha256: ?string}> $report what changed in the copy
     */
    public function __construct(
        public int $exit,
        public ?array $output,
        public string $stdout,
        public string $stderr,
        public array $bounds,
        public array $report,
    ) {
    }

    /**
     * Did it succeed? «No error», not a literal `ok: true` — operations answer in their own shape
     * (greenhouse evidence/0272): a zero exit and no `error` key is success.
     */
    public function ok(): bool
    {
        if ($this->exit !== 0) {
            return false;
        }

        return ! (\is_array($this->output) && \array_key_exists('error', $this->output));
    }
}
