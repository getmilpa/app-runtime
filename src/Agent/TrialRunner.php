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
use Milpa\AppRuntime\Standing\StandingLedger;
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
    // `--unshare-ipc` (greenhouse CH, L-2): without it a confined process shares the host SysV/POSIX-shm IPC and
    // could attach to another process's shared-memory segment. `--new-session` (L-3): a new session so the child
    // keeps no controlling TTY of the launcher (a trial's child reads from pipes, not a tty, so this breaks nothing).
    private const NAMESPACES = ['--unshare-net', '--unshare-pid', '--unshare-ipc', '--new-session', '--die-with-parent'];

    /**
     * Set in a trial's own environment (`--setenv`), so a boot check asked from INSIDE a trial does not build a SECOND
     * confinement. A user namespace nested in the trial's own is instant on a host but, in a container, neither works
     * nor fails — it waits, and the trial timed out (the 0.219.0 regression t-0104 measured, greenhouse evidence/1186).
     * The enclosing trial already confines (no network, the mask), so the inner check boots plain within it (0607).
     */
    public const INSIDE_TRIAL_ENV = 'MILPA_TRIAL';

    /**
     * The namespace arguments that run here, probed once: false before the probe, null when none does.
     *
     * @var list<string>|false|null
     */
    private array|false|null $namespaces = false;

    /** Whether a fresh procfs mounts here (B5), probed once with the confining shape: null before the probe. */
    private ?bool $procMountable = null;

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
     * Is bubblewrap here at all? The difference between «install it» and «it is here, and is refused a namespace»
     * (greenhouse evidence/1186: the image carries it, and under Docker's own profile it cannot confine). The tool
     * is looked for, never run: whether it CAN confine is {@see available()}'s to say, and is asked once.
     */
    public function installed(): bool
    {
        return $this->resolvable($this->bwrap);
    }

    /**
     * True when this very process runs INSIDE a trial — so a confinement asked here would nest in the trial's own
     * (never do that: {@see INSIDE_TRIAL_ENV}). The enclosing trial already confines whatever this process boots.
     */
    public static function insideTrial(): bool
    {
        return getenv(self::INSIDE_TRIAL_ENV) === '1';
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
     * Can a fresh procfs be mounted here, under the shape this runner confines with? Probed once, then remembered —
     * like {@see namespaces()}, and with the same shape, so a confinement is not claimed with `/proc` private in one
     * place and imposed in another.
     *
     * `--proc /proc` (B5, {@see maskArgs()}) hides the host's other processes' command lines. It needs the pid
     * namespace — which the probed shape carries — AND the kernel's leave to mount a procfs over the read-only root.
     * On bare metal it mounts. In a default Docker container `/proc` is masked (the runtime's own read-only overlays
     * and masked paths) and bwrap cannot mount a fresh one: the mount fails and takes the whole confinement with it,
     * so NO trial booted in the `:dev` image (greenhouse CH, t-0110 C8). There `--proc` is skipped and the container's
     * own pid namespace does the hiding the flag would — the host's processes are already out of this `/proc`.
     *
     * False when nothing can be confined here at all (no namespace shape): there is no confinement to add `/proc` to.
     */
    public function procMountable(): bool
    {
        if ($this->procMountable !== null) {
            return $this->procMountable;
        }
        $namespaces = $this->namespaces();
        if ($namespaces === null) {
            return $this->procMountable = false;
        }
        $probe = ChildProcess::run([$this->bwrap, ...$namespaces, '--ro-bind', '/', '/', '--proc', '/proc', '--', $this->php, '-r', 'exit(0);']);

        return $this->procMountable = ($probe !== null && $probe['exit'] === 0);
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
        $runner->procMountable = $this->procMountable();

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
     * The argv that confines a boot of a tree the house did NOT apply — the boot check of code nobody applied runs
     * as a trial (greenhouse decisions/0607, Rod's alternative A; evidence/1180): the namespaces a trial found here
     * (no network, its own pids), the root read-only, `/dev/null` bound back so git and a shell's `2>/dev/null`
     * work (evidence/1060), the tree bound so its own `var/` can be written while it boots, and THE MASK — the
     * secret files AND the keyring the house signs with — through the one place ({@see maskArgs}, placed AFTER the
     * writable bind so the bind cannot re-expose a secret the tree carries).
     *
     * Null when nothing can be confined here (no unprivileged namespace, e.g. macOS): the caller boots the candidate
     * UNCONFINED and must SAY so (0607: where there is no bwrap it runs unconfined; confining it there is another
     * slice). The applied house's boot is not confined by this and does not change.
     *
     * @return list<string>|null
     */
    public function confinement(string $tree, string $secretsRoot): ?array
    {
        $namespaces = $this->namespaces();
        if ($namespaces === null) {
            return null;
        }

        return [$this->bwrap, ...$namespaces, ...self::environmentFloor($tree, $tree), '--ro-bind', '/', '/', '--dev-bind', '/dev/null', '/dev/null',
            '--bind', $tree, $tree, ...$this->maskArgs($secretsRoot)];
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
            ...$this->namespaces() ?? self::NAMESPACES, ...self::environmentFloor($scratch, $scratch), '--ro-bind', '/', '/', '--dev-bind', '/dev/null', '/dev/null'];
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
        array_push($command, '--bind', $scratch, $scratch); // TMPDIR/MILPA_TRIAL set by environmentFloor()
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
            ...$this->namespaces() ?? self::NAMESPACES, ...self::environmentFloor($temporary, $temporary), '--ro-bind', '/', '/', '--dev-bind', '/dev/null', '/dev/null'];
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
            // TMPDIR/MILPA_TRIAL set by environmentFloor()
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
        // THE BOOK OF STANDING GRANTS AND ITS HEAD ARE MASKED TOO (greenhouse decisions/0615, «Lo que la tercera
        // revisión encontró»; decided by Rod 2026-10-12). Mode 600 keeps them from other users of the machine and not
        // from the processes the house itself starts for a seat: under `--ro-bind / /` a rehearsal and a run of work
        // read every signed grant, every call a seat made and every argument it carried. The house reads its book in
        // its own process, before and after the confined one; nothing confined has a reason to.
        foreach (StandingLedger::existingUnder($root) as $book) {
            array_push($args, '--ro-bind', '/dev/null', $book);
        }
        // THE KEYRING THE HOUSE SIGNS WITH IS NOT A FILE OF THE HOUSE, AND IT IS MASKED TOO (greenhouse evidence/1178,
        // decided by Rod 2026-10-09). Under `--ro-bind / /` the keyring gpg reads and the agent's sockets are reachable
        // from a confined process: 1178 measured that a plugin's `boot()` or an operation's handler could read the
        // private key the Desktop keeps mounted and SIGN a governed act as the person who holds it. Each keyring
        // directory and each gpg-agent socket directory is overlaid with an empty tmpfs — a directory cannot be masked
        // with `--ro-bind /dev/null`, which mounts a file. Each is named by its REAL path (symlinks resolved) and
        // must exist: `--tmpfs` must create its mountpoint, which cannot be done inside `--ro-bind / /` (the tree is
        // read-only), so an absent or symlinked target is handled by {@see keyringDirectories()}, never passed raw.
        foreach (self::keyringDirectories() as $directory) {
            array_push($args, '--tmpfs', $directory);
        }
        // HUECO B3 (greenhouse CH): the host's path AF_UNIX sockets are reachable from a confined process — the
        // root is bound read-only but `--unshare-net` does not cover a PATH socket (it is a filesystem object), so
        // a confined call could connect to `docker.sock` (= root, with the operator in the `docker` group), to the
        // ssh-agent (H-1, signs with the user's SSH keys), dbus, wayland, X11, pipewire or pulse. Each existing one
        // is overlaid with `/dev/null` so the path is no longer a socket: a connect to the `/dev/null` character
        // device is refused (ECONNREFUSED/111) — the connect fails regardless. Named by its real path; a leg needs
        // none of them.
        // MAINTENANCE: {@see hostSocketPaths()} and {@see environmentFloor()}'s whitelist are HAND-MAINTAINED lists
        // that must track the code AND the deployment — a non-standard `DOCKER_HOST` path, or an ssh-agent outside
        // the gnome default, is invisible to a path-pinned list and must be added here when the deployment uses it.
        foreach (self::hostSocketPaths() as $socket) {
            $real = realpath($socket);
            // Mask only a socket this user could actually open (connect = W_OK): that is the one worth closing, and
            // a socket whose parent directory the user cannot traverse (e.g. a root-only `/run/containerd`) both
            // cannot be reached anyway AND makes bwrap fail to bind. `is_writable` on the real path answers both.
            if ($real !== false && !is_dir($real) && @is_writable($real)) {
                array_push($args, '--ro-bind', '/dev/null', $real);
            }
        }
        // HUECO B5 (greenhouse CH): a fresh procfs so the confined process cannot read the command lines of the
        // host's other processes. `--unshare-pid` already hid their environ; `/proc` was still the host's because
        // `--ro-bind / /` carried it in. Mounted AFTER the root bind so it wins; needs the pid namespace, which the
        // probed shape already carries. CONDITIONAL (t-0110 C8): added only where a procfs CAN be mounted — on bare
        // metal. In a default container `/proc` is masked and bwrap cannot mount a fresh one; forcing it failed the
        // whole confinement and no trial booted in the `:dev` image. There it is skipped and the container's own pid
        // namespace already hides the host's processes, so B5's end holds without the flag ({@see procMountable()}).
        if ($this->procMountable()) {
            array_push($args, '--proc', '/proc');
        }

        return $args;
    }

    /**
     * HUECO B3: the class of host path AF_UNIX sockets a confined process must not reach. The daemon sockets whose
     * connect is authority on the host — Docker and Podman and containerd, the session and system message buses,
     * the display and audio servers — named by absolute path (the per-user ones under the real `XDG_RUNTIME_DIR`
     * / `/run/user/<uid>`). The mask is SPECIFIC: a socket not on this list stays reachable, by design.
     *
     * @return list<string>
     */
    private static function hostSocketPaths(): array
    {
        $uid = function_exists('posix_getuid') ? posix_getuid() : (int) (getenv('UID') ?: 0);
        $runtime = (string) (getenv('XDG_RUNTIME_DIR') ?: '/run/user/' . $uid);
        $paths = [
            '/var/run/docker.sock', '/run/docker.sock',
            '/run/podman/podman.sock', $runtime . '/podman/podman.sock', '/run/containerd/containerd.sock',
            '/run/dbus/system_bus_socket', $runtime . '/bus', $runtime . '/systemd/private',
            $runtime . '/pipewire-0', $runtime . '/pulse/native',
        ];
        // THE SSH-AGENT (greenhouse CH, H-1): a socket whose connect signs with the user's private SSH keys (git
        // push, server login) — the exact class B3 closes. Read the LAUNCHER's SSH_AUTH_SOCK from the PARENT's
        // environment HERE, while the args are built, because `--clearenv` will have cleared it from the child; add
        // the well-known gnome-keyring defaults an attacker can hard-code even without the variable.
        $ssh = getenv('SSH_AUTH_SOCK');
        if (is_string($ssh) && $ssh !== '') {
            $paths[] = $ssh;
        }
        $paths[] = $runtime . '/gcr/ssh';
        $paths[] = $runtime . '/keyring/ssh';
        // DISPLAY/COMPOSITOR by glob (L-1): the instance number varies — cover every `wayland-N` and X display `XN`,
        // not only `:0`.
        foreach ([...(glob($runtime . '/wayland-*') ?: []), ...(glob('/tmp/.X11-unix/X*') ?: [])] as $display) {
            $paths[] = $display;
        }

        return $paths;
    }

    /**
     * HUECO B4 (greenhouse CH): the exact environment a confined child is allowed to see. The confinement did not
     * clear the environment, so agent-authored code read by `getenv` whatever the process that opened the leg
     * carried — the model provider's `MILPA_AGENT_BASIC_AUTH` and any third-party `*_KEY`/`*_TOKEN`. A trial runner
     * and a `test` read nothing from the environment but a writable scratch and a working PATH; everything else is
     * cleared. `$home`/`$tmp` are a writable directory of this call's own.
     *
     * @return list<string>
     */
    private static function environmentFloor(string $home, string $tmp): array
    {
        return [
            '--clearenv',
            '--setenv', 'PATH', '/usr/local/bin:/usr/bin:/bin',
            '--setenv', 'HOME', $home,
            '--setenv', 'TMPDIR', $tmp,
            '--setenv', 'LANG', 'C.UTF-8',
            '--setenv', 'LC_ALL', 'C.UTF-8',
            '--setenv', self::INSIDE_TRIAL_ENV, '1',
        ];
    }

    /** The smartcard daemon's socket directory the Desktop mounts into the house when it is present (0121). */
    public const PCSCD_SOCKET_DIR = '/run/pcscd';

    /**
     * The REAL directories a confined process must not reach because the house signs with what they hold — distinct,
     * existing, each resolved through symlinks (greenhouse evidence/1178): the keyring `GNUPGHOME` names, the default
     * `$HOME/.gnupg`, the gpg-agent's runtime sockets under `$XDG_RUNTIME_DIR/gnupg` and `/run/user/<uid>/gnupg`, and
     * the smartcard daemon's socket directory the Desktop mounts. Read from the environment the runner runs in,
     * because that is what a confined child inherits.
     *
     * The REAL path, not the path as it came: a keyring reached through a symlink (a common `$HOME/.gnupg` pointing
     * elsewhere) would otherwise leave the real directory readable by its own path, and bwrap cannot mount a tmpfs
     * onto a symlink at all. `realpath()` resolves it and returns false for an absent or broken one, which is left
     * out — under a read-only root there is then nothing to hide, and masking it would make bwrap fail.
     *
     * @return list<string>
     */
    private static function keyringDirectories(): array
    {
        $candidates = [];
        $gnupg = getenv('GNUPGHOME');
        if (\is_string($gnupg) && $gnupg !== '') {
            $candidates[] = $gnupg;
        }
        $home = getenv('HOME');
        if (\is_string($home) && $home !== '') {
            $candidates[] = rtrim($home, '/') . '/.gnupg';
        }
        foreach ([getenv('XDG_RUNTIME_DIR'), '/run/user/' . (string) getmyuid()] as $run) {
            if (\is_string($run) && $run !== '') {
                $candidates[] = rtrim($run, '/') . '/gnupg';
            }
        }
        $candidates[] = self::PCSCD_SOCKET_DIR;

        $directories = [];
        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_dir($real) && !\in_array($real, $directories, true)) {
                $directories[] = $real;
            }
        }

        return $directories;
    }
}
