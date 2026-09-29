<?php

/**
 * This file is part of milpa/app-runtime.
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
 * WHETHER A RUN IS ALIVE, ASKED OF THE PROCESS AND NOT INFERRED FROM THE STREAM (greenhouse decisions/0513 §3).
 *
 * A session's stream says a human turn is waiting for its run to end; it cannot say whether the process running it is
 * still there. The panel read «a turn without a termination» as «interrupted» on every load — measured in
 * greenhouse evidence/1036 while the resident was working in another process for two hours. The only party that
 * knows is the process itself, so it holds an exclusive `flock` on one file per session for as long as it runs, and
 * the kernel releases it when the process ends — a `SIGKILL` and a fatal error included. A reader asks with a
 * shared, non-blocking lock it drops at once; it never waits and never takes the run's place.
 *
 * The file stays on disk after the run: its presence means nothing, only the lock does — and never unlinking avoids
 * the race where a second run opens a path the first is removing.
 */
final class RunLease
{
    /** Where the leases live, under the house's root. */
    public const string DIRECTORY = 'var/agent-runs';

    /** @var resource|null the open lease file, locked exclusively — null once released */
    private mixed $handle;

    /** @param resource $handle the open lease file, locked exclusively */
    private function __construct(mixed $handle)
    {
        $this->handle = $handle;
    }

    /**
     * Take the lease on `$sessionId` for this process — or null when the directory cannot be written or another
     * process already holds it. A run that cannot take its lease still runs: liveness is a reading, never a gate.
     */
    public static function take(string $root, string $sessionId): ?self
    {
        $path = self::path($root, $sessionId);
        $directory = \dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return null;
        }
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return new self($handle);
    }

    /**
     * Whether some process holds the lease on `$sessionId` right now. Asking never blocks and never keeps a lock: a
     * lease file nobody holds — or none at all — reads as not running.
     */
    public static function held(string $root, string $sessionId): bool
    {
        $path = self::path($root, $sessionId);
        if (!is_file($path)) {
            return false;
        }
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return false;
        }
        try {
            if (flock($handle, \LOCK_SH | \LOCK_NB)) {
                flock($handle, \LOCK_UN);

                return false;
            }

            return true;
        } finally {
            fclose($handle);
        }
    }

    /** The lease file for `$sessionId`: hashed, so no session id ever becomes a path of its own choosing. */
    public static function path(string $root, string $sessionId): string
    {
        return rtrim($root, '/') . '/' . self::DIRECTORY . '/' . sha1($sessionId) . '.lock';
    }

    /** Release the lease: the run is over. Releasing twice is harmless. */
    public function release(): void
    {
        if (\is_resource($this->handle)) {
            flock($this->handle, \LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
    }

    /** A lease dropped without {@see release()} is still released: the lock must never outlive its run. */
    public function __destruct()
    {
        $this->release();
    }
}
