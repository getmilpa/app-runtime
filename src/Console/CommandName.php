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

namespace Milpa\AppRuntime\Console;

/**
 * The command an operation is called by on the terminal.
 *
 * A terminal command is `domain:verb`. The names operations declare reach it in three spellings — `token.list`,
 * `sandbox_promote`, `herramienta:prestar` — and the terminal used to write every underscore and every dot as a
 * colon. That is right for a name with nothing else between its two parts, and wrong inside a verb: a resident named
 * a verb `herramienta:dar_baja`, the terminal listed `herramienta:dar:baja`, and answered «no such command» to the
 * name every other surface of the house called it by (greenhouse evidence/1159).
 *
 * So an underscore separates only where nothing else does. In a name that already carries a colon or a dot, it is
 * part of a word.
 */
final class CommandName
{
    /** The terminal's name for the operation that declares `$operation`. */
    public static function of(string $operation): string
    {
        $separatedOtherwise = str_contains($operation, ':') || str_contains($operation, '.');

        return str_replace($separatedOtherwise ? ['.'] : ['_', '.'], ':', $operation);
    }
}
