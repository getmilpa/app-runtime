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
 * Run a child process of the house and read what it said — without opening a device.
 *
 * ── THE DEFECT THIS CLOSES, MEASURED (greenhouse evidence/1060, finding 1) ──────────────────────
 *
 * Inside a rehearsal's trial every writer the house boots a copy for (decisions/0515) answered «no process
 * could be started to boot the house» — on the published train too. The trial is `bwrap --ro-bind / /`, and
 * bwrap binds read-only WITHOUT devices: `/dev/null` is there and cannot be opened (`Permission denied`).
 * BootProbe handed `/dev/null` to its child as stdin, so `proc_open` failed before any process existed. A
 * shell's `2>/dev/null` fails the same way: the shell refuses the redirect and never runs the command, so
 * `git … 2>/dev/null` read as «not a repository» and `command -v rsync 2>/dev/null` as «no rsync».
 *
 * So a child's stdin is a pipe closed at once (it reads end-of-file, as from `/dev/null`), its stdout and
 * stderr are pipes drained together — a child that fills one while the other is read first would block —
 * and the command is an argument list, never a shell line with redirects.
 */
final class ChildProcess
{
    /**
     * Run `$command` to its end; null when no process could be started.
     *
     * @param list<string> $command the program and its arguments, run without a shell
     *
     * @return array{exit: int, stdout: string, stderr: string}|null
     */
    public static function run(array $command, ?string $cwd = null): ?array
    {
        $proc = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!\is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        $output = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        foreach ($open as $pipe) {
            stream_set_blocking($pipe, false);
        }
        while ($open !== []) {
            $read = array_values($open);
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 1) === false) {
                break;
            }
            foreach ($open as $channel => $pipe) {
                $output[$channel] .= (string) fread($pipe, 65536);
                if (feof($pipe)) {
                    fclose($pipe);
                    unset($open[$channel]);
                }
            }
        }
        foreach ($open as $pipe) {
            fclose($pipe);
        }

        return ['exit' => proc_close($proc), 'stdout' => $output[1], 'stderr' => $output[2]];
    }

    /**
     * The exit of `$command` and its stdout as lines, the way `exec()` gives them; exit 127 when nothing started.
     *
     * @param list<string> $command
     *
     * @return array{0: int, 1: list<string>}
     */
    public static function lines(array $command, ?string $cwd = null): array
    {
        $run = self::run($command, $cwd);
        if ($run === null) {
            return [127, []];
        }
        $stdout = rtrim($run['stdout'], "\n");

        return [$run['exit'], $stdout === '' ? [] : array_map('rtrim', explode("\n", $stdout))];
    }
}
