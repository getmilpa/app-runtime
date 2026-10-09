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

use Milpa\AppRuntime\Config\SecretFiles;
use Milpa\AppRuntime\Support\ChildProcess;
use Milpa\AppRuntime\Support\PhpBinary;

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
    /** The namespaces every trial is confined by: its own network (none) and its own pids. */
    private const NAMESPACES = ['--unshare-net', '--unshare-pid', '--die-with-parent'];

    /**
     * The namespace arguments that run here, probed once: false before the probe, null when none does.
     *
     * @var list<string>|false|null
     */
    private array|false|null $namespaces = false;

    /** The PHP a trial runs — found by {@see PhpBinary}, because under FrankenPHP `PHP_BINARY` is empty (0505). */
    private readonly string $php;

    public function __construct(
        private readonly string $bwrap = 'bwrap',
        private readonly int $timeoutSeconds = 60,
        ?string $php = null,
        private readonly ?TrialInputObserver $inputObserver = null,
    ) {
        $this->php = $php ?? PhpBinary::path();
    }

    /** Is there an unprivileged user namespace here for bwrap to use? Probed once, then remembered. */
    public function available(): bool
    {
        return $this->namespaces() !== null;
    }

    /**
     * The namespace arguments a confined process runs with here — the trial's and a confined request's alike —
     * or null when bubblewrap cannot confine anything on this kernel.
     *
     * ── ROOT IN A CONTAINER (greenhouse evidence/1092) ──────────────────────────────────────────────────
     *
     * The plain shape is asked first, and wherever it runs nothing changes: a host's unprivileged bubblewrap
     * already makes a user namespace on its own, and a setuid one must not be asked for one. Root WITHOUT
     * `CAP_SYS_ADMIN` — the house inside the Desktop's container — is refused the plain shape and never asks for
     * a user namespace by itself, because it already is uid 0; there `--unshare-user` is the one shape that
     * runs, with the same read-only root, no network and its own pids. Whichever shape answered is the one every
     * run uses: a confinement is never claimed in one shape and imposed in another.
     *
     * @return list<string>|null
     */
    public function namespaces(): ?array
    {
        if ($this->namespaces !== false) {
            return $this->namespaces;
        }
        if (! $this->resolvable($this->bwrap)) {
            return $this->namespaces = null;
        }
        foreach ([self::NAMESPACES, ['--unshare-user', ...self::NAMESPACES]] as $shape) {
            $probe = ChildProcess::run([$this->bwrap, ...$shape, '--ro-bind', '/', '/', '--', $this->php, '-r', 'exit(0);']);
            if ($probe !== null && $probe['exit'] === 0) {
                return $this->namespaces = $shape;
            }
        }

        return $this->namespaces = null;
    }

    /**
     * This runner with another ceiling of time per call — the same bubblewrap, the same PHP, and the namespaces it
     * already probed. For a call the house makes on its own account and will not wait a whole minute for
     * ({@see CapabilityExercise}): it is no session's authoring call, so nothing observes its inputs for one.
     */
    public function within(int $seconds): self
    {
        $runner = new self($this->bwrap, $seconds, $this->php);
        $runner->namespaces = $this->namespaces();

        return $runner;
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
     * Run one operation of work IN THE HOUSE, confined to its state (greenhouse decisions/0588, rule 2).
     *
     * Not a trial: there is no copy. The child runs against the house itself with the same confinement a trial
     * has — the root read-only, no network, its own pids — and of the house only the paths of the operation's
     * state are bound for writing, with a scratch directory for its temporary files. A handler that writes
     * anywhere else gets the system's error.
     *
     * THE PATHS ARE JUDGED AGAIN HERE, ON THE REAL PATH. They were judged when the declaration was read; between
     * the two a link may have appeared, and a mount follows links. So each one must still be a place for state
     * ({@see HouseWork::notAPlaceForState()}, which walks the real path of the house for links) and must exist.
     *
     * @param string               $runner  the script the child runs: `<runner> <root> <operation> <json>`
     * @param array<string, mixed> $input
     * @param list<string>         $state   the paths the call may write, relative to the house root
     * @param string               $scratch a directory of this call's own, for temporary files
     *
     * @throws \RuntimeException when a path is not a place for state, does not exist or is reached through a link
     */
    public function work(string $root, string $runner, string $operation, array $input, array $state, string $scratch): TrialRun
    {
        $house = realpath($root);
        if ($house === false || $state === []) {
            throw new \RuntimeException('Work runs confined to a declared state, and none was given.');
        }
        $command = ['timeout', '-k', '2', (string) $this->timeoutSeconds, $this->bwrap,
            ...$this->namespaces() ?? self::NAMESPACES, '--ro-bind', '/', '/', '--dev-bind', '/dev/null', '/dev/null'];
        foreach ($state as $relative) {
            $why = HouseWork::notAPlaceForState($house, $relative);
            if ($why !== null) {
                throw new \RuntimeException("«{$relative}» is not mounted for work: {$why}.");
            }
            $path = $house . '/' . $relative;
            if (! file_exists($path)) {
                throw new \RuntimeException("«{$relative}» is not mounted for work: it does not exist.");
            }
            array_push($command, '--bind', $path, $path);
        }
        if (! is_dir($scratch) || is_link($scratch)) {
            throw new \RuntimeException('Work needs a scratch directory of its own.');
        }
        array_push($command, '--bind', $scratch, $scratch, '--setenv', 'TMPDIR', $scratch);
        $command = [...$command, ...$this->maskArgs($house)];
        array_push(
            $command,
            '--',
            $this->php,
            '-d',
            'sys_temp_dir=' . $scratch,
            $runner,
            $house,
            $operation,
            json_encode($input, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}'
        );
        [$exit, $stdout, $stderr] = $this->exec(implode(' ', array_map('escapeshellarg', $command)));
        if ($exit === 124 || $exit === 137) {
            $stderr = trim($stderr . "\ntimeout: the call exceeded {$this->timeoutSeconds}s and was killed");
        }

        return new TrialRun(
            exit: $exit,
            output: $this->lastJson($stdout),
            stdout: $stdout,
            stderr: $stderr,
            bounds: ['fs' => 'ro-root+rw-declared-state+rw-scratch', 'net' => 'unshared', 'pid' => 'unshared'],
            report: [],
        );
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
        //
        // /DEV/NULL, AND NO OTHER DEVICE (greenhouse evidence/1060): bwrap binds read-only WITHOUT devices, so
        // under `--ro-bind / /` alone `/dev/null` is there and cannot be opened. git opens it at every start
        // (and refuses to run without it), a shell's `2>/dev/null` fails before running its command, and the
        // boot witness of 0515 could not start its child — every witnessed writer refused inside a leg. The
        // sink is bound back with its device; /dev/zero, /dev/tty and the rest stay closed.
        $house = (string) realpath($workspace->root);
        $command = ['timeout', '-k', '2', (string) $this->timeoutSeconds, $this->bwrap,
            ...$this->namespaces() ?? self::NAMESPACES, '--ro-bind', '/', '/', '--dev-bind', '/dev/null', '/dev/null'];
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
        $command = [...$command, ...$this->maskArgs($house)];
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

        $attempt = new TrialInputAttempt(bin2hex(random_bytes(16)), $workspace->root, $workspace->copy, $operation, $input);
        $witnessType = $operation === 'test' ? TestInputWitness::class : AuthoringInputWitness::class;
        $observing = $this->inputObserver !== null && \in_array($operation, ['test', 'implement'], true);
        $prepared = false;
        if ($observing) {
            try {
                $this->inputObserver->before($attempt);
                $prepared = true;
            } catch (\Throwable) {
                // An unavailable observer cannot prevent the trial or manufacture a new input.
            }
        }
        [$exit, $stdout, $stderr] = $this->exec($cmd);
        $witness = $observing ? $witnessType::unknown($attempt) : null;
        if ($prepared) {
            try {
                $witness = $witnessType::fromObservation($attempt, $this->inputObserver->after($attempt, $exit));
            } catch (\Throwable) {
                // Preserve the execution verdict, but do not credit an incomplete observation.
            }
        }
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
            inputWitness: $witness,
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

    /**
     * The `--ro-bind /dev/null` arguments that mask every file the house keeps a secret in from a confined
     * process, so `--ro-bind / /` no longer lets a call read the real envelope or a copy a trial or boot
     * candidate kept (greenhouse evidence/1161). The list is the real files {@see SecretFiles::existingUnder()}
     * finds — the envelope, the environment family, Composer's credentials, and any REAL copy below; a copy a
     * house LINKED (a boot candidate links its secrets) resolves to one of these, so masking the target covers
     * the link, and bwrap will not mount onto a symlink in any case. This is the ONE place a confinement's mask
     * is built: every caller that binds a tree writable appends it AFTER that bind, so a writable mount cannot
     * re-expose a real copy it carries. `HouseRouteObserver` shares it through here (the guard holds the list of
     * who confines).
     *
     * @return list<string>
     */
    public function maskArgs(string $root): array
    {
        $args = [];
        foreach (SecretFiles::existingUnder($root) as $secret) {
            array_push($args, '--ro-bind', '/dev/null', $secret);
        }

        return $args;
    }
}
