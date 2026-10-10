<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Support;

use Milpa\AppRuntime\Agent\TrialRunner;

/**
 * Whether the house, as it is on disk NOW, boots — asked of a fresh process, never of this one.
 *
 * ── THE DEFECT, MEASURED (greenhouse evidence/1038, n5; decisions/0506) ─────────────────────────
 *
 * A promotion registered a plugin whose class misses an interface method. PHP answers that with a
 * compile fatal nobody can catch: every process that boots the house dies. The FrankenPHP workers saw
 * their definition change, left as 0505 told them to, and their replacements died at boot in a loop —
 * 224 → 320 crashes, requests waiting with no answer. And `coa sandbox:undo` booted the same house and
 * died of the same fatal: the way back existed only by hand.
 *
 * A fatal cannot be caught in the process that meets it. It can be SEEN from outside: a child process
 * boots the house the way `coa` does (`resources/house-observe.php boot`), and its exit and its stderr
 * say whether it lived. The child runs the PHP that {@see PhpBinary} finds, because under FrankenPHP
 * `PHP_BINARY` is empty (0505 §5).
 *
 * The answer is null when the house boots, or ONE line saying why not: the fatal's own sentence with
 * the root stripped from its paths, a throwable's class and message, or the exit when there was
 * nothing else. «Could not ask» (no PHP found, no script) is a reason too — it is never read as «boots».
 */
final class BootProbe
{
    /** Past this, the child is stopped and the house reads as not booting — a boot that hangs does not serve either. */
    public const TIMEOUT_SECONDS = 30;

    private readonly string $php;

    private readonly string $script;

    private readonly TrialRunner $runner;

    public function __construct(
        ?string $php = null,
        private readonly int $timeoutSeconds = self::TIMEOUT_SECONDS,
        ?string $script = null,
        ?TrialRunner $runner = null,
    ) {
        $this->php = $php ?? PhpBinary::path();
        $this->script = $script ?? \dirname(__DIR__, 2) . '/resources/house-observe.php';
        // The trials' own runner, so the boot check of a change nobody applied runs in the confinement a trial
        // found here (greenhouse decisions/0607 A; evidence/1180): the same namespaces, the one mask.
        $this->runner = $runner ?? new TrialRunner();
    }

    /**
     * Null when a fresh process boots the house at `$root` — the house AS IT IS, booted PLAIN (unconfined), because
     * an applied house is the governed act and runs with its secrets (decisions/0606 §3). Otherwise one line why not.
     */
    public function whyNot(string $root): ?string
    {
        return $this->boot($root, null)['why'];
    }

    /**
     * Null when the house at `$root` WOULD boot with `$writes` written and `$deletes` removed — asked before anything is written.
     *
     * The change is applied to a {@see BootCandidate} beside the house, never to the house, and a fresh process
     * boots that (greenhouse decisions/0512). Since Rod's alternative A (0607, evidence/1180) that boot runs CONFINED
     * — the code nobody applied boots as a trial: no network, the envelope and the keyring masked — where this
     * machine can confine; where it cannot (no bwrap) it boots unconfined and {@see check()}'s room says so. The
     * reason names paths relative to the house, exactly as {@see whyNot()} does. A candidate that cannot be built is
     * a reason too.
     *
     * `$whileItStands`, when given, is called with the candidate's path once it BOOTED and before it is removed — the
     * one place something else can be asked of the house as it would be (decisions/0540: its routes).
     *
     * @param array<string, string>       $writes        path relative to the root → the bytes it would hold
     * @param list<string>                $deletes       paths relative to the root that would be removed
     * @param null|callable(string): void $whileItStands asked of the booted candidate, before it goes
     */
    public function whyNotWith(string $root, array $writes, array $deletes = [], ?callable $whileItStands = null): ?string
    {
        return $this->check($root, $writes, $deletes, $whileItStands)['why'];
    }

    /**
     * The boot check of a change nobody applied, run as a trial (greenhouse decisions/0607 A; evidence/1180): why it
     * would not boot (null when it would), the ROOM it ran in, and the plugins that mounted FEWER routes under the
     * mask than the house mounts with its secret — so a surface can say the two things 1180 §4.1 asks for, and
     * «boots» is not read as «boots as it will run».
     *
     * The change is booted on a {@see BootCandidate} beside the house, confined when this machine can confine (no
     * network, the envelope and the keyring masked); where it cannot (no bwrap, e.g. macOS) it boots UNCONFINED and
     * the room says so. The «with its secret» count is read from the house as it is — booted plain, as it runs —
     * never by running the unapplied code with the real envelope; a plugin only the candidate has is listed as new.
     *
     * @param array<string, string>       $writes
     * @param list<string>                $deletes
     * @param null|callable(string): void $whileItStands asked of the booted candidate, before it goes
     *
     * @return array{why: ?string, room: string, confined: bool, mounted_less: list<array{plugin: string, with_secret: int, without_secret: int}>, new_plugins: list<array{plugin: string, routes: int}>}
     */
    public function check(string $root, array $writes, array $deletes = [], ?callable $whileItStands = null): array
    {
        try {
            $candidate = BootCandidate::of($root, $writes, $deletes);
        } catch (\RuntimeException $e) {
            return ['why' => 'the house as it would be could not be built to boot it: ' . self::short($e->getMessage(), $root),
                'room' => 'the house as it would be could not be built', 'confined' => false, 'mounted_less' => [], 'new_plugins' => []];
        }
        // A boot check asked from INSIDE a trial must not build a SECOND confinement: a user namespace nested in the
        // trial's own is instant on a host but, in a container, waits forever — the 0.219.0 trial timeout t-0104
        // measured (greenhouse evidence/1186). The enclosing trial already confines (no network, the mask), so the
        // candidate boots PLAIN within it. `make` reaches here this way: it runs in a trial, and the house it
        // scaffolds is checked by {@see HouseBootWitness::writeIfItBoots}.
        $insideTrial = TrialRunner::insideTrial();
        $confinement = $insideTrial ? null : $this->runner->confinement($candidate->path, $root);
        $room = match (true) {
            $insideTrial => 'within the enclosing trial: it already confines this boot (no network, the mask), so the check boots plain inside it rather than nesting a confinement that would never return in a container (decisions/0607; evidence/1186)',
            $confinement === null => 'UNCONFINED: this machine has no unprivileged namespace (no bwrap), so the change booted with the envelope readable and the network open — contain the house in its own container or say so (decisions/0607)',
            default => 'a trial: confined, no network, the envelope and the keyring masked',
        };
        try {
            ['why' => $why, 'routes' => $withoutSecret] = $this->boot($candidate->path, $confinement);
            if ($why === null && $whileItStands !== null) {
                $whileItStands($candidate->path);
            }
        } finally {
            $candidate->remove();
        }

        $mountedLess = $newPlugins = [];
        if ($why === null && $withoutSecret !== null) {
            $withSecret = $this->boot($root, null)['routes'] ?? [];
            foreach ($withoutSecret as $plugin => $without) {
                if (!\array_key_exists($plugin, $withSecret)) {
                    $newPlugins[] = ['plugin' => $plugin, 'routes' => $without];
                } elseif ($without < $withSecret[$plugin]) {
                    $mountedLess[] = ['plugin' => $plugin, 'with_secret' => $withSecret[$plugin], 'without_secret' => $without];
                }
            }
        }

        return ['why' => $why === null ? null : self::short($why, $root), 'room' => $room, 'confined' => $insideTrial || $confinement !== null,
            'mounted_less' => $mountedLess, 'new_plugins' => $newPlugins];
    }

    /**
     * Boot the house at `$path` in a fresh process — confined by `$confinement` when given, plain when null — and
     * read whether it lived and how many routes each plugin mounted.
     *
     * @param list<string>|null $confinement bwrap and its options (no trailing `--`), from {@see TrialRunner::confinement()}
     *
     * @return array{why: ?string, routes: ?array<string, int>}
     */
    private function boot(string $path, ?array $confinement): array
    {
        if ($this->php === '' || !is_file($this->script)) {
            return ['why' => 'no PHP was found to boot the house in a process of its own', 'routes' => null];
        }
        if (!is_file($path . '/vendor/autoload.php')) {
            return ['why' => 'the house has no vendor/autoload.php', 'routes' => null];
        }
        $inner = [$this->php, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'html_errors=0', $this->script, 'boot', $path];
        // A plain boot binds no `/dev/null` (inside a rehearsal's trial it cannot be opened, evidence/1060); a
        // confined boot binds it back, as a trial does — the confinement already carries `--dev-bind /dev/null /dev/null`.
        $command = $confinement === null
            ? ['timeout', '-k', '2', (string) $this->timeoutSeconds, ...$inner]
            : ['timeout', '-k', '2', (string) $this->timeoutSeconds, ...$confinement, '--', ...$inner];
        $run = ChildProcess::run($command);
        if ($run === null) {
            return ['why' => 'no process could be started to boot the house', 'routes' => null];
        }
        ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr] = $run;

        $answer = null;
        foreach (explode("\n", $stdout) as $line) {
            if (str_starts_with($line, '@@house-observe ')) {
                $decoded = json_decode(substr($line, \strlen('@@house-observe ')), true);
                $answer = \is_array($decoded) ? $decoded : null;
            }
        }
        $routes = \is_array($answer['routes_by_plugin'] ?? null)
            ? array_map('intval', $answer['routes_by_plugin']) : null;
        if ($exit === 0 && ($answer['ok'] ?? false) === true) {
            return ['why' => null, 'routes' => $routes];
        }
        if ($exit === 124 || $exit === 137) {
            return ['why' => "the house did not finish booting within {$this->timeoutSeconds}s", 'routes' => null];
        }
        if (\is_string($answer['error'] ?? null)) {
            return ['why' => self::short((string) $answer['error'], $path), 'routes' => null];
        }
        foreach (explode("\n", $stdout . "\n" . $stderr) as $line) {
            if (preg_match('/(Fatal error|Parse error):\s*(.+)$/', strip_tags($line), $m) === 1) {
                return ['why' => self::short($m[1] . ': ' . $m[2], $path), 'routes' => null];
            }
        }

        return ['why' => "the house did not boot (exit {$exit})", 'routes' => null];
    }

    /**
     * One line, the roots stripped from paths, bounded — a reason travels in a header, a log and a page.
     *
     * Public because a front controller that meets a boot that fails says it the same way (decisions/0512).
     */
    public static function oneLine(string $reason, string $root): string
    {
        return self::short($reason, $root);
    }

    /** One line, the root stripped from paths, bounded — a reason travels in a header and in a log. */
    private static function short(string $reason, string $root): string
    {
        $real = realpath($root);
        foreach (array_filter([rtrim($root, '/') . '/', $real !== false ? $real . '/' : null]) as $prefix) {
            $reason = str_replace($prefix, '', $reason);
        }
        // A candidate lives under the house's own `var/`: its prefix is stripped too, so a reason names `src/…`.
        $reason = (string) preg_replace('~var/boot-candidates/[0-9a-f]+/~', '', $reason);
        // Whatever absolute path is left is outside the house (a path repository, the system's PHP): it is cut
        // to its file name, because a reason is shown to whoever asked (decisions/0512).
        $reason = (string) preg_replace('~(?<![\w.:/])/(?:[^\s/:()\'"]+/)+([^\s/:()\'"]+)~', '…/$1', $reason);
        $reason = trim((string) preg_replace('/\s+/', ' ', $reason));

        return mb_strlen($reason) > 300 ? mb_substr($reason, 0, 297) . '...' : $reason;
    }
}
