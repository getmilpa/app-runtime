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
 * One argument for a POSIX shell, quoted byte for byte (greenhouse decisions/0624).
 *
 * `escapeshellarg()` reads LC_CTYPE: on PHP 8.3 under the C locale — what a PHP started by cron or systemd without
 * LANG gets — it drops the bytes it cannot place, so `escapeshellarg('sí')` is `'s'`. The line a person was offered
 * to sign then named another answer, and a path with an accent named another path. This quotes the same way
 * (single quotes, each `'` closed, escaped and reopened) without asking the locale anything.
 */
final class ShellArgument
{
    /**
     * The argument between single quotes, every byte kept.
     *
     * @throws \ValueError when the value holds a NUL byte, which no shell argument can carry (as escapeshellarg)
     */
    public static function quote(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw new \ValueError('a shell argument cannot contain a NUL byte');
        }

        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
