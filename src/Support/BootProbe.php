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

    public function __construct(
        ?string $php = null,
        private readonly int $timeoutSeconds = self::TIMEOUT_SECONDS,
        ?string $script = null,
    ) {
        $this->php = $php ?? PhpBinary::path();
        $this->script = $script ?? \dirname(__DIR__, 2) . '/resources/house-observe.php';
    }

    /**
     * Null when a fresh process boots the house at `$root`; otherwise one line saying why it did not.
     */
    public function whyNot(string $root): ?string
    {
        if ($this->php === '' || !is_file($this->script)) {
            return 'no PHP was found to boot the house in a process of its own';
        }
        if (!is_file($root . '/vendor/autoload.php')) {
            return 'the house has no vendor/autoload.php';
        }

        $command = ['timeout', '-k', '2', (string) $this->timeoutSeconds, $this->php,
            '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'html_errors=0', $this->script, 'boot', $root];
        $proc = proc_open(
            implode(' ', array_map('escapeshellarg', $command)),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!\is_resource($proc)) {
            return 'no process could be started to boot the house';
        }
        // stderr is read after stdout: a boot that fills the stderr pipe first would block on it, so both
        // are drained without blocking until the child is gone.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 1) === false) {
                break;
            }
            foreach ($read as $pipe) {
                $chunk = (string) fread($pipe, 65536);
                if ($pipe === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $answer = null;
        foreach (explode("\n", $stdout) as $line) {
            if (str_starts_with($line, '@@house-observe ')) {
                $decoded = json_decode(substr($line, \strlen('@@house-observe ')), true);
                $answer = \is_array($decoded) ? $decoded : null;
            }
        }
        if ($exit === 0 && ($answer['ok'] ?? false) === true) {
            return null;
        }
        if ($exit === 124 || $exit === 137) {
            return "the house did not finish booting within {$this->timeoutSeconds}s";
        }
        if (\is_string($answer['error'] ?? null)) {
            return self::short((string) $answer['error'], $root);
        }
        foreach (explode("\n", $stdout . "\n" . $stderr) as $line) {
            if (preg_match('/(Fatal error|Parse error):\s*(.+)$/', strip_tags($line), $m) === 1) {
                return self::short($m[1] . ': ' . $m[2], $root);
            }
        }

        return "the house did not boot (exit {$exit})";
    }

    /** One line, the root stripped from paths, bounded — a reason travels in a header and in a log. */
    private static function short(string $reason, string $root): string
    {
        $real = realpath($root);
        foreach (array_filter([rtrim($root, '/') . '/', $real !== false ? $real . '/' : null]) as $prefix) {
            $reason = str_replace($prefix, '', $reason);
        }
        $reason = trim((string) preg_replace('/\s+/', ' ', $reason));

        return mb_strlen($reason) > 300 ? mb_substr($reason, 0, 297) . '...' : $reason;
    }
}
