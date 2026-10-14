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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\HouseRouteObserver;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use PHPUnit\Framework\TestCase;

/**
 * Inside the Desktop's container the house runs as root WITHOUT `CAP_SYS_ADMIN` (greenhouse evidence/1091, E1).
 * There bubblewrap cannot make a namespace with root's privileges, and — because the caller is uid 0 — it never
 * asks for a user namespace on its own: every trial was refused. Asked for one (`--unshare-user`), and with the
 * four syscalls the Desktop's seccomp profile adds, it gets the same confinement as on a host (evidence/1092).
 *
 * The fake bubblewraps here stand for the three kernels a house meets: one where the plain shape runs (a host),
 * one where only a user namespace does (root in an unprivileged container), and one where neither does.
 */
final class ATrialRunsInAnUnprivilegedContainerTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::rmrf($root);
        }
    }

    public function testWhereOnlyAUserNamespaceRunsTheTrialAsksForOne(): void
    {
        $root = $this->root();
        $bwrap = $this->bwrap($root, 'container');
        $runner = new TrialRunner(bwrap: $bwrap);

        self::assertTrue($runner->available(), 'root in an unprivileged container confines with a user namespace');
        $ws = TrialWorkspace::materialize($root, 'c1', $this->stub($root));
        $run = $runner->run($ws, 'anything', []);

        self::assertSame(0, $run->exit, $run->stderr);
        $argv = $this->calls($root);
        self::assertStringStartsWith('--unshare-user --unshare-net --unshare-pid --unshare-ipc --new-session --die-with-parent', end($argv), 'the trial runs in the shape the probe found');
        self::assertSame(TrialWorkspace::BOUNDS, $run->bounds, 'the confinement it records is the same: a user namespace changes who it runs as, not what it can write');
    }

    public function testWhereThePlainShapeRunsNothingChanges(): void
    {
        $root = $this->root();
        $runner = new TrialRunner(bwrap: $this->bwrap($root, 'host'));

        self::assertTrue($runner->available());
        $runner->run(TrialWorkspace::materialize($root, 'h1', $this->stub($root)), 'anything', []);

        $argv = $this->calls($root);
        self::assertCount(3, $argv, 'the namespace probe, the procfs probe (B5), and the run — no second namespace shape');
        foreach ($argv as $call) {
            self::assertStringNotContainsString('--unshare-user', $call);
        }
    }

    public function testWhereNeitherShapeRunsThereIsNoTrial(): void
    {
        $root = $this->root();
        $runner = new TrialRunner(bwrap: $this->bwrap($root, 'none'));

        self::assertFalse($runner->available());
        self::assertFalse($runner->available(), 'remembered');
        self::assertCount(2, $this->calls($root), 'both shapes were asked once, then never again');
        self::assertNull($runner->namespaces());
    }

    public function testAConfinedRequestUsesTheShapeTheTrialFound(): void
    {
        $root = $this->root();
        $bwrap = $this->bwrap($root, 'container');
        $observer = new HouseRouteObserver(php: \PHP_BINARY, script: $this->observeScript($root), bwrap: $bwrap);

        self::assertTrue($observer->confines());
        $seen = $observer->observeRoute($root, '/blog', confined: true);

        self::assertSame('served', $seen['entry']['predicate'] ?? null, json_encode($seen) ?: '');
        $argv = $this->calls($root);
        self::assertStringStartsWith('--unshare-user --unshare-net --unshare-pid --unshare-ipc --new-session --die-with-parent', end($argv), 'the confined request uses the shape the trial found');
    }

    /**
     * B5 IS CONDITIONAL (greenhouse CH, t-0110 C8). `--proc /proc` gives the confined process a fresh procfs so it
     * cannot read the host's other processes' command lines — but a fresh procfs only mounts where the kernel
     * allows it. In a default Docker container `/proc` is masked and bubblewrap cannot mount one: forcing `--proc`
     * fails the whole confinement and no trial boots. The runner probes once ({@see TrialRunner::procMountable()})
     * and adds `--proc` only where it mounts; where it does not, the flag is skipped and the container's own pid
     * namespace already keeps the host's processes out of this `/proc`, so B5's end holds without the flag.
     */
    public function testWhereAFreshProcfsCannotMountTheTrialRunsWithoutProc(): void
    {
        // CONTROL (+): where a fresh procfs mounts, the mask adds `--proc /proc`.
        $host = $this->root();
        $mounts = new TrialRunner(bwrap: $this->bwrap($host, 'host'));
        $mounts->run(TrialWorkspace::materialize($host, 'p1', $this->stub($host)), 'anything', []);
        self::assertTrue($mounts->procMountable(), 'where a fresh procfs mounts, B5 is given by the flag');
        $hostArgv = $this->calls($host);
        self::assertStringContainsString('--proc /proc', end($hostArgv), 'the flag is added where it mounts');

        // THE CONTAINER CASE: a masked `/proc` the kernel refuses a fresh procfs over. The trial still boots, with
        // `--proc` skipped; `--unshare-pid` — the namespace that hides the host — stays.
        $masked = $this->root();
        $runner = new TrialRunner(bwrap: $this->bwrap($masked, 'masked-proc'));
        $run = $runner->run(TrialWorkspace::materialize($masked, 'p2', $this->stub($masked)), 'anything', []);

        self::assertSame(0, $run->exit, $run->stderr);
        self::assertFalse($runner->procMountable(), 'a fresh procfs does not mount here');
        $maskedArgv = $this->calls($masked);
        self::assertStringNotContainsString('--proc', end($maskedArgv), 'where a procfs cannot mount, the flag is skipped and the trial still boots');
        self::assertStringContainsString('--unshare-pid', end($maskedArgv), 'the pid namespace that hides the host stays');
    }

    /**
     * A SECRET HAS ONE PLACE TO LIVE (greenhouse evidence/1161). A trial process runs under «--ro-bind / /», so
     * the house's real envelope and any copy an old trial kept are visible to it read-only. The runner masks
     * each one that exists with «--ro-bind /dev/null», so a call that runs in a trial reads nothing of them. A
     * file of the house that is not a secret is not masked, and the trial still runs.
     */
    public function testTheRunnerMasksTheRealEnvelopeAndOldTrialCopiesFromAConfinedProcess(): void
    {
        $root = $this->root();
        mkdir($root . '/.milpa', 0o777, true);
        file_put_contents($root . '/.milpa/secrets.json', '{"ai":{"key":"canary"}}');
        file_put_contents($root . '/auth.json', '{"canary":1}');
        file_put_contents($root . '/.env.local', "KEY=canary\n");
        mkdir($root . '/var/trials/wold/copy/.milpa', 0o777, true);
        file_put_contents($root . '/var/trials/wold/copy/.milpa/secrets.json', '{"ai":{"key":"old-canary"}}');
        mkdir($root . '/var/boot-candidates/bold/.milpa', 0o777, true);
        file_put_contents($root . '/var/boot-candidates/bold/.milpa/secrets.json', '{"ai":{"key":"boot-canary"}}');
        $runner = new TrialRunner(bwrap: $this->bwrap($root, 'host'));

        $runner->run(TrialWorkspace::materialize($root, 'w1', $this->stub($root)), 'anything', []);

        $calls = $this->calls($root);
        $argv = $calls === [] ? '' : end($calls);
        self::assertStringContainsString('--ro-bind /dev/null ' . $root . '/.milpa/secrets.json', $argv, 'the real envelope is masked');
        self::assertStringContainsString('--ro-bind /dev/null ' . $root . '/auth.json', $argv, 'composer credentials are masked');
        self::assertStringContainsString('--ro-bind /dev/null ' . $root . '/var/trials/wold/copy/.milpa/secrets.json', $argv, 'an old trial copy is masked');
        self::assertStringContainsString('--ro-bind /dev/null ' . $root . '/.env.local', $argv, 'an env-local is masked');
        self::assertStringContainsString('--ro-bind /dev/null ' . $root . '/var/boot-candidates/bold/.milpa/secrets.json', $argv, 'a boot candidate copy is masked');
        self::assertStringNotContainsString('--ro-bind /dev/null ' . $root . '/src/A.php', $argv, 'a file that is not a secret is not masked');
    }

    /**
     * A trial marks its own environment, so a boot check asked from INSIDE it (make → HouseBootWitness →
     * BootProbe::check) sees it is already confined and boots PLAIN instead of nesting a user namespace in the
     * trial's own — which is instant on a host but never returns in a container, the 0.219.0 timeout t-0104 measured
     * (the 0.219.0 regression t-0104 measured, greenhouse evidence/1186).
     */
    public function testTheTrialMarksItselfSoAnInnerBootCheckDoesNotNestAConfinement(): void
    {
        $root = $this->root();
        $runner = new TrialRunner(bwrap: $this->bwrap($root, 'host'));

        $runner->run(TrialWorkspace::materialize($root, 'm1', $this->stub($root)), 'anything', []);

        $argv = $this->calls($root);
        self::assertStringContainsString('--setenv ' . TrialRunner::INSIDE_TRIAL_ENV . ' 1', end($argv) ?: '', 'the trial carries the mark that stops an inner boot check from nesting a confinement');
    }

    /** A work trial carries the same mark: every trial already confines whatever runs inside it (evidence/1186). */
    public function testAWorkTrialAlsoMarksItselfSoNothingInsideItNestsAConfinement(): void
    {
        $root = $this->root();
        mkdir($root . '/var/data', 0o777, true);
        file_put_contents($root . '/var/data/x.json', '{}');
        mkdir($scratch = $root . '/scratch', 0o777, true);
        $runner = new TrialRunner(bwrap: $this->bwrap($root, 'host'));

        $runner->work($root, $this->stub($root), 'anything', [], ['var/data/x.json'], $scratch);

        $argv = $this->calls($root);
        self::assertStringContainsString('--setenv ' . TrialRunner::INSIDE_TRIAL_ENV . ' 1', end($argv) ?: '', 'a work trial marks itself too');
    }

    /**
     * A bubblewrap that records its arguments and then behaves like the named kernel.
     *
     * It runs what follows `--`, or — as a confined request names no `--` — what starts at this PHP.
     * `host` runs anything; `container` refuses to make a namespace unless asked for a user namespace — what
     * bubblewrap answers to uid 0 without `CAP_SYS_ADMIN` (evidence/1092, arms-a2); `none` refuses everything.
     */
    private function bwrap(string $root, string $kernel): string
    {
        $path = $root . '/bwrap-' . $kernel;
        $refuse = match ($kernel) {
            'host' => 'false',
            'container' => 'case " $* " in *" --unshare-user "*) false ;; *) true ;; esac',
            // A container whose `/proc` is masked: every shape runs EXCEPT one that asks to mount a fresh procfs
            // (`--proc`), which the kernel refuses — the `:dev` image's case (greenhouse CH, t-0110 C8).
            'masked-proc' => 'case " $* " in *" --proc "*) true ;; *) false ;; esac',
            default => 'true',
        };
        file_put_contents($path, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($root . '/bwrap-argv.txt') . "\n"
            . 'if ' . $refuse . "; then echo 'bwrap: Creating new namespace failed: Operation not permitted' >&2; exit 1; fi\n"
            . 'while [ $# -gt 0 ] && [ "$1" != "--" ] && [ "$1" != ' . escapeshellarg(\PHP_BINARY) . " ]; do shift; done\n"
            . "[ \"\$1\" = \"--\" ] && shift\nexec \"\$@\"\n");
        chmod($path, 0o755);

        return $path;
    }

    /** @return list<string> */
    private function calls(string $root): array
    {
        return array_values(array_filter(explode("\n", (string) @file_get_contents($root . '/bwrap-argv.txt'))));
    }

    private function stub(string $root): string
    {
        $stub = $root . '/runner.php';
        file_put_contents($stub, "<?php echo json_encode(['operation' => \$argv[1]]);\n");

        return $stub;
    }

    private function observeScript(string $root): string
    {
        $script = $root . '/observe.php';
        file_put_contents($script, '<?php echo ' . var_export(HouseRouteObserver::MARK, true) . ", json_encode(['status' => 200, 'bytes' => 2]), \"\\n\";\n");

        return $script;
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/milpa-trial-container-' . bin2hex(random_bytes(4));
        mkdir($root . '/src', 0o777, true);
        file_put_contents($root . '/src/A.php', "<?php // a\n");
        $this->roots[] = $root;

        return $root;
    }

    private static function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
