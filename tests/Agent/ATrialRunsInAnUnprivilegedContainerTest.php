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
        self::assertStringStartsWith('--unshare-user --unshare-net --unshare-pid --die-with-parent --ro-bind / / --dev-bind /dev/null /dev/null', end($argv), 'the trial runs in the shape the probe found');
        self::assertSame(TrialWorkspace::BOUNDS, $run->bounds, 'the confinement it records is the same: a user namespace changes who it runs as, not what it can write');
    }

    public function testWhereThePlainShapeRunsNothingChanges(): void
    {
        $root = $this->root();
        $runner = new TrialRunner(bwrap: $this->bwrap($root, 'host'));

        self::assertTrue($runner->available());
        $runner->run(TrialWorkspace::materialize($root, 'h1', $this->stub($root)), 'anything', []);

        $argv = $this->calls($root);
        self::assertCount(2, $argv, 'one probe and one run — the probe never tried a second shape');
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
        self::assertStringStartsWith('--unshare-user --unshare-net --unshare-pid --die-with-parent --ro-bind / /', end($argv));
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
