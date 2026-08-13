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

namespace Milpa\AppRuntime\Tui;

/**
 * Whether a screen should print its internal state next to what it paints.
 *
 * greenhouse evidence/0169 shipped a selector fix openly unverified, because that screen could only
 * be read as pixels: the cursor marker is one character that `grep` did not find, a plain capture
 * hid and `cat -A` showed, and three runs gave three answers that did not agree. You cannot claim a
 * window follows a cursor by looking at pixels — the cursor has to be READABLE.
 *
 * IT IS OFF UNLESS ASKED FOR, and that is the whole design. An instrument that leaks into everyone's
 * screen stops being an instrument and becomes noise, and a human has no reason to see indices.
 *
 * The rule lives here rather than inline so the test measures THE rule instead of a copy of it —
 * greenhouse evidence/0141: a convention is called, not copied.
 */
final class TuiProbe
{
    /** Did somebody ask to see the internals? An empty value is not «yes». */
    public static function pedida(): bool
    {
        $valor = getenv('MILPA_TUI_DEBUG');

        return \is_string($valor) && $valor !== '';
    }
}
