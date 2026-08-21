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

/**
 * Run ONE operation in-process from the trial copy this file was placed in.
 *
 * ── WHY THIS DOES NOT ASK FOR CONSENT ───────────────────────────────────────────────────────────
 *
 * A trial runs an operation whose consent is the HOST's business: the host gate already decided the
 * call was confined and fit the trial ceiling (greenhouse decisions/0068). So this process must not
 * re-ask through the CLI's `--sign` gate — it resolves the operation through `Application::correr()`,
 * the same resolver the TUI uses, and invokes its handler directly. Output is JSON so the host can
 * read what happened; the host, not this process, computes what changed on disk.
 *
 * The CLI spells `plugins:disable`; the atom is named `plugins.disable` (a `_` or `.` in the atom is
 * written `:` in the terminal). `correr()` matches the atom name, so a trial resolves the terminal
 * spelling first and falls back to the atom spellings.
 *
 * Usage (copied into the root of a trial copy):  php trial-run.php <operation> '<json input>'
 */
require __DIR__ . '/vendor/autoload.php';

$op = $argv[1] ?? null;
$json = $argv[2] ?? '{}';
if (! \is_string($op) || $op === '') {
    fwrite(\STDERR, "usage: trial-run.php <operation> '<json input>'\n");
    exit(2);
}
$input = json_decode($json, true);
if (! \is_array($input)) {
    fwrite(\STDERR, "input is not a JSON object\n");
    exit(2);
}

$app = new Milpa\AppRuntime\Console\Application(__DIR__);
$correr = new ReflectionMethod($app, 'correr');
try {
    $r = $correr->invoke($app, $op, $input);
    if ($r === null && str_contains($op, ':')) {
        $r = $correr->invoke($app, str_replace(':', '.', $op), $input);
    }
    if ($r === null && str_contains($op, ':')) {
        $r = $correr->invoke($app, str_replace(':', '_', $op), $input);
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => \get_class($e) . ': ' . $e->getMessage()], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), "\n";
    exit(1);
}
if ($r === null) {
    echo json_encode(['ok' => false, 'error' => "no operation «{$op}» in this app"], \JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
}
echo json_encode($r, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), "\n";

// SUCCESS IS «NO ERROR», NOT A LITERAL `ok: true`: operations answer in their own shape — the session
// ops say `ok`, the plugin ops answer `{name, enabled, safety}` with no `ok` at all (greenhouse
// evidence/0272). A runner that demanded `ok: true` would call a successful disable a failure.
$failed = (\array_key_exists('ok', $r) && $r['ok'] !== true) || \array_key_exists('error', $r);
exit($failed ? 1 : 0);
