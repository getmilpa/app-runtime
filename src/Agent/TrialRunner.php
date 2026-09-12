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
     * @param list<string>|null    $writePaths null retains the unrestricted trial; a list confines authoring
     */
    public function run(TrialWorkspace $workspace, string $operation, array $input, ?array $writePaths = null): TrialRun
    {
        $json = json_encode($input, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';

        // Verifiers need temporary files, including their child processes. Keep those within the
        // existing writable copy, under var/ (excluded from promotion), never in the host's /tmp.
        $temporary = $workspace->copy . '/var/verification-tmp';
        if (!is_dir($temporary) && !mkdir($temporary, 0o700, true) && !is_dir($temporary)) {
            throw new \RuntimeException('Could not prepare temporary storage for the trial.');
        }

        // VENDOR IS BOUND READ-ONLY, NOT COPIED (decisions/0070): the trial boots from the host vendor
        // at the copy path, which keeps the two-root-authorities fix of evidence/0272 (Composer resolves
        // against <copy>) while removing the 160 MB copy — and it TIGHTENS confinement, because a
        // mutation that tried to write vendor now takes an EPERM at write time instead of being caught
        // later at diff time. The bind comes AFTER `--bind <copy>` so it wins over the writable copy at
        // that one path. When the host has no vendor (a bare app), there is nothing to bind.
        $command = ['timeout', '-k', '2', (string) $this->timeoutSeconds, $this->bwrap,
            '--unshare-net', '--unshare-pid', '--die-with-parent', '--ro-bind', '/', '/'];
        if ($writePaths === null) {
            array_push($command, '--bind', $workspace->copy, $workspace->copy);
        } else {
            foreach ($writePaths as $relative) {
                if (!preg_match('~^(?:src|tests)/Plugins/[A-Za-z_][A-Za-z0-9_]*$~D', $relative)) {
                    throw new \RuntimeException('Invalid plugin write boundary.');
                }
                $path = $workspace->copy;
                foreach (explode('/', $relative) as $part) {
                    $path .= '/' . $part;
                    if (is_link($path)) {
                        throw new \RuntimeException('A plugin write boundary cannot contain symbolic links.');
                    }
                }
                if (!is_dir($path) && !mkdir($path, 0o700, true) && !is_dir($path)) {
                    throw new \RuntimeException('Could not prepare the plugin write boundary.');
                }
                array_push($command, '--bind', $path, $path);
            }
            array_push($command, '--bind', $workspace->copy . '/var', $workspace->copy . '/var');
            foreach (['.phpunit.cache', 'vendor'] as $directory) {
                if (!is_dir($workspace->copy . '/' . $directory)) {
                    mkdir($workspace->copy . '/' . $directory, 0o700, true);
                }
            }
            array_push($command, '--tmpfs', $workspace->copy . '/.phpunit.cache');
            file_put_contents($workspace->baseDirectory() . '/authoring.json', (string) json_encode([
                'plugin' => explode('/', $writePaths[0])[2], 'write_paths' => $writePaths,
            ], \JSON_THROW_ON_ERROR));
        }
        $vendor = $workspace->root . '/vendor';
        if (is_dir($vendor)) {
            array_push($command, '--ro-bind', $vendor, $workspace->copy . '/vendor');
        }
        array_push(
            $command,
            '--setenv',
            'TMPDIR',
            $temporary,
            '--',
            $this->php,
            '-d',
            'sys_temp_dir=' . $temporary,
            $workspace->runnerPath(),
            $operation,
            $json
        );
        $cmd = implode(' ', array_map('escapeshellarg', $command));

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
            bounds: $writePaths === null ? $this->bounds() : [
                'fs' => 'ro-root+rw-plugin-source+rw-plugin-tests+rw-trial-var+tmpfs-phpunit-cache',
                'net' => 'unshared', 'pid' => 'unshared',
            ],
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
        // Drain both channels while the child runs. Reading either pipe to EOF first can
        // block the child on the other pipe, turning an ordinary warning into a timeout.
        $output = [1 => '', 2 => ''];
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        try {
            while ($pipes !== []) {
                $ready = $pipes;
                $write = $except = null;
                if (stream_select($ready, $write, $except, null) === false) {
                    throw new \RuntimeException('Could not wait for trial output.');
                }
                foreach ($pipes as $channel => $pipe) {
                    if (!in_array($pipe, $ready, true)) {
                        continue;
                    }
                    $chunk = fread($pipe, 65536);
                    if ($chunk === false) {
                        throw new \RuntimeException('Could not read trial output.');
                    }
                    $output[$channel] .= $chunk;
                    if (feof($pipe)) {
                        fclose($pipe);
                        unset($pipes[$channel]);
                    }
                }
            }
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            // The existing timeout process still owns the deadline, even if both pipes close.
            $exit = proc_close($proc);
        }

        return [$exit, $output[1], $output[2]];
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
