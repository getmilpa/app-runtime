<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
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
 * A leg that DIES still leaves its termination (greenhouse decisions/0509 §6).
 *
 * Measured (evidence/1036): four legs in a row ran out of PHP's 128 MB inside a claim and exited 255, and none
 * left a `session.run_terminated`. The run writes its termination in a `finally`, and a fatal error — memory
 * exhausted, a time limit, a parse error in promoted code — never reaches a `finally`: PHP bails out and only
 * shutdown functions run. The session looked as though the leg were still going; nothing said it died.
 *
 * While a run is ARMED, a shutdown function that sees a fatal error records the termination the run would
 * have recorded: `reason: failed` — the cause {@see \Milpa\AiGateway\RunEnd::Failed} already names, so no
 * reader learns a new one — with the fatal's message, file and line. Only one run is armed at a time (the
 * last to arm), the shutdown function is registered once per process, and a run that ends normally disarms
 * before its own `finally` records, so the fact is never written twice.
 *
 * THE DYING PROCESS NEEDS ROOM TO SPEAK. It died at its memory limit, and appending one event to a file ledger
 * reads the file to number it (~26 MB on the 21 MB ledger of 1036), and the dead frame may still hold that file's
 * shared lock. The handler releases the process's locks and raises the limit by a bounded margin before it writes; a recorder that still fails is swallowed — the process is already dying, and the
 * run being observed has priority over observing it (the {@see DebtSignal} doctrine).
 */
final class FatalTermination
{
    /** The fatal error types after which no `finally` ran. */
    private const FATAL = [\E_ERROR, \E_CORE_ERROR, \E_COMPILE_ERROR, \E_USER_ERROR];

    /** How much more memory the dying process may use to write its one event. */
    private const HEADROOM = 256 * 1024 * 1024;

    /** @var (\Closure(array<string, mixed>): void)|null the armed run's recorder */
    private static ?\Closure $armed = null;

    private static bool $registered = false;

    /**
     * Arm the recorder for the run that starts now; the previous armed run, if any, is replaced.
     *
     * @param \Closure(array<string, mixed>): void $record writes the termination to the session's stream
     */
    public static function arm(\Closure $record): void
    {
        self::$armed = $record;
        if (! self::$registered) {
            self::$registered = true;
            register_shutdown_function(static function (): void {
                self::recordIfFatal(error_get_last());
            });
        }
    }

    /** Disarm: the run reached its own `finally`, which records its termination itself. */
    public static function disarm(): void
    {
        self::$armed = null;
    }

    /**
     * Record the armed run's termination when `$error` is a fatal one; public so the reaction is testable
     * without killing the test process.
     *
     * @param array{type: int, message: string, file: string, line: int}|null $error what error_get_last() answered
     */
    public static function recordIfFatal(?array $error): bool
    {
        $record = self::$armed;
        if ($record === null || $error === null || ! \in_array($error['type'], self::FATAL, true)) {
            return false;
        }
        self::$armed = null;

        // THE DEAD FRAME STILL HOLDS ITS LOCKS. A fatal inside a read of the file ledger (1036: `readRows`, under
        // LOCK_SH) skips the `finally` that unlocks, and the write below asks the same file for LOCK_EX: the process
        // waited on itself forever instead of dying (measured on cattle, evidence/1042). The process is dying, so
        // every lock it holds is released first — nothing else of its work will run.
        foreach (get_resources('stream') as $stream) {
            @flock($stream, \LOCK_UN);
        }

        $limit = self::bytes((string) \ini_get('memory_limit'));
        if ($limit > 0) {
            @ini_set('memory_limit', (string) (max($limit, memory_get_usage(true)) + self::HEADROOM));
        }

        try {
            $record([
                'reason' => 'failed',
                'receipt' => null,
                'fatal' => [
                    'message' => mb_substr($error['message'], 0, 500),
                    'file' => $error['file'],
                    'line' => $error['line'],
                ],
            ]);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /** A php.ini size (`128M`, `1G`, `-1`) in bytes; `-1` (no limit) answers 0. */
    private static function bytes(string $size): int
    {
        $size = trim($size);
        if ($size === '' || $size === '-1') {
            return 0;
        }
        $unit = strtolower($size[\strlen($size) - 1]);
        $value = (int) $size;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
