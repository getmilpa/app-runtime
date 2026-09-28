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
 * The PHP a child process of the house runs with — found, not assumed to be `PHP_BINARY`.
 *
 * ── THE DEFECT THIS CLOSES, MEASURED (greenhouse decisions/0505, evidence/1038) ─────────────────
 *
 * Under FrankenPHP — classic or worker — `PHP_BINARY` is the empty string: the process is the web
 * server, not a PHP executable. The house spawns PHP children in two places that matter: the route
 * observer a promotion runs (decisions/0494) and the trial runner. Both took `PHP_BINARY` as their
 * default, so served by FrankenPHP they launched `''`: the promotion observed nothing and the trial
 * runner reported no sandbox. The derived closure of 0494 could never arrive on that server.
 *
 * `PHP_BINARY` still wins when it names an executable (the CLI and `php -S`); otherwise the `php` that
 * sits beside this build (`PHP_BINDIR`), and last the first `php` on `PATH`. Empty means none was found,
 * and the callers already treat an unrunnable binary as «could not ask».
 */
final class PhpBinary
{
    /**
     * The PHP executable a child process should run.
     *
     * @param string      $binary the running process's `PHP_BINARY` (injectable for tests)
     * @param string      $bindir the running build's `PHP_BINDIR` (injectable for tests)
     * @param string|null $path   the `PATH` to search last; null reads the environment
     */
    public static function path(string $binary = \PHP_BINARY, string $bindir = \PHP_BINDIR, ?string $path = null): string
    {
        if ($binary !== '' && is_file($binary) && is_executable($binary)) {
            return $binary;
        }
        $candidates = [rtrim($bindir, '/') . '/php'];
        $path ??= (string) getenv('PATH');
        foreach (explode(\PATH_SEPARATOR, $path) as $dir) {
            if ($dir !== '') {
                $candidates[] = rtrim($dir, '/') . '/php';
            }
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}
