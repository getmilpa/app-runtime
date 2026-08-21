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
 * What makes the confinement TRUE and not merely claimed: it runs the trial's operation in a
 * namespace where the host root is read-only, the network is gone, and the pid space is its own.
 *
 * ── FAIL CLOSED ─────────────────────────────────────────────────────────────────────────────────
 *
 * greenhouse decisions/0069 §9: without an unprivileged user namespace there is no sandbox, and
 * without a sandbox there is NO TRIAL. The runner reports {@see available()} as false and the router
 * plans nothing — the call falls back to the declared ceiling and pauses, exactly as it did before
 * any of this existed. A runner that «copied and diffed and claimed» without the namespace would be
 * claiming a confinement it never imposed, which is the one thing this must never do.
 */
final class TrialRunner
{
    private ?bool $available = null;

    public function __construct(
        private readonly string $bwrap = 'bwrap',
        private readonly int $timeoutSeconds = 60,
        private readonly string $php = \PHP_BINARY,
    ) {
    }

    /** Is there an unprivileged user namespace here for bwrap to use? Probed once, then remembered. */
    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        if (! $this->resolvable($this->bwrap)) {
            return $this->available = false;
        }

        $cmd = sprintf(
            '%s --unshare-net --unshare-pid --die-with-parent --ro-bind / / -- %s -r %s 2>/dev/null',
            escapeshellarg($this->bwrap),
            escapeshellarg($this->php),
            escapeshellarg('exit(0);'),
        );
        exec($cmd, $_, $code);

        return $this->available = $code === 0;
    }

    /**
     * The confinement this runner imposes, named so a recorded trial can state what it ran under.
     *
     * @return array{fs: string, net: string, pid: string}
     */
    public function bounds(): array
    {
        return TrialWorkspace::BOUNDS;
    }

    /**
     * Run one operation in the trial and read the result on the host side.
     *
     * @param array<string, mixed> $input
     */
    public function run(TrialWorkspace $workspace, string $operation, array $input): TrialRun
    {
        $json = json_encode($input, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';

        // VENDOR IS BOUND READ-ONLY, NOT COPIED (decisions/0070): the trial boots from the host vendor
        // at the copy path, which keeps the two-root-authorities fix of evidence/0272 (Composer resolves
        // against <copy>) while removing the 160 MB copy — and it TIGHTENS confinement, because a
        // mutation that tried to write vendor now takes an EPERM at write time instead of being caught
        // later at diff time. The bind comes AFTER `--bind <copy>` so it wins over the writable copy at
        // that one path. When the host has no vendor (a bare app), there is nothing to bind.
        $vendor = $workspace->root . '/vendor';
        $vendorBind = is_dir($vendor)
            ? sprintf(' --ro-bind %s %s', escapeshellarg($vendor), escapeshellarg($workspace->copy . '/vendor'))
            : '';
        $cmd = sprintf(
            'timeout -k 2 %d %s --unshare-net --unshare-pid --die-with-parent --ro-bind / / --bind %s %s%s -- %s %s %s %s',
            $this->timeoutSeconds,
            escapeshellarg($this->bwrap),
            escapeshellarg($workspace->copy),
            escapeshellarg($workspace->copy),
            $vendorBind,
            escapeshellarg($this->php),
            escapeshellarg($workspace->runnerPath()),
            escapeshellarg($operation),
            escapeshellarg($json),
        );

        [$exit, $stdout, $stderr] = $this->exec($cmd);
        // `timeout` exits 124 when it had to kill; say so, because a trial that ran out of time and
        // one that failed are different findings.
        if ($exit === 124 || $exit === 137) {
            $stderr = trim($stderr . "\ntimeout: the trial exceeded {$this->timeoutSeconds}s and was killed");
        }

        return new TrialRun(
            exit: $exit,
            output: $this->lastJson($stdout),
            stdout: $stdout,
            stderr: $stderr,
            bounds: $this->bounds(),
            report: $workspace->diff(),
        );
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function exec(string $cmd): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (! \is_resource($proc)) {
            return [127, '', 'could not start the trial process'];
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [$exit, $stdout, $stderr];
    }

    /** @return array<string, mixed>|null */
    private function lastJson(string $stdout): ?array
    {
        foreach (array_reverse(array_filter(array_map('trim', explode("\n", $stdout)))) as $line) {
            $decoded = json_decode($line, true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function resolvable(string $bin): bool
    {
        if (str_contains($bin, '/')) {
            return is_file($bin) && is_executable($bin);
        }
        exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $_, $code);

        return $code === 0;
    }
}
