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
 * Run ONE operation of work in-process, in the house it is given (greenhouse decisions/0588).
 *
 * ── NOT A TRIAL ─────────────────────────────────────────────────────────────────────────────────
 *
 * A trial runs in a copy of the house; this runs in the house itself, because the domain's state is
 * there and a copy is born without it. What confines it is the process it is started in: the host
 * binds the root read-only, takes the network away, and leaves writable only the paths of the state
 * the operation declares. So this file decides nothing about what may be written — the kernel does.
 *
 * ── WHY THIS DOES NOT ASK FOR CONSENT ───────────────────────────────────────────────────────────
 *
 * The same reason as a trial's runner: the host's gate already judged the call — scope, intent,
 * consent, signature — before starting this process. It resolves the declared operation through
 * Application and runs it with OperationRunner, including container-resolved instance handlers.
 * Output is JSON so the host can read what the handler answered; the host, not this process, says
 * what the state was and is.
 *
 * Usage (run from the package, never copied into the house):
 *   php work-run.php <house root> <operation> '<json input>'
 */
$root = $argv[1] ?? null;
$op = $argv[2] ?? null;
$json = $argv[3] ?? '{}';
if (! \is_string($root) || ! is_dir($root) || ! \is_string($op) || $op === '') {
    fwrite(\STDERR, "usage: work-run.php <house root> <operation> '<json input>'\n");
    exit(2);
}
// Relative paths a handler uses belong to the house.
chdir($root);
require $root . '/vendor/autoload.php';

$input = json_decode($json, true);
if (! \is_array($input)) {
    fwrite(\STDERR, "input is not a JSON object\n");
    exit(2);
}

$app = new Milpa\AppRuntime\Console\Application($root);
try {
    // The terminal's lookup is asked with the terminal's own name for the operation: a copy of that rule here once
    // drifted from it, and an admitted seat was told its verb did not exist (greenhouse evidence/1159).
    $operation = (new ReflectionMethod($app, 'find'))->invoke($app, Milpa\AppRuntime\Console\CommandName::of($op));
    if ($operation === null) {
        echo json_encode(['ok' => false, 'error' => "no operation «{$op}» in this app"], \JSON_UNESCAPED_UNICODE), "\n";
        exit(1);
    }
    $kernel = (new ReflectionMethod($app, 'kernel'))->invoke($app);
    $r = (new Milpa\Console\OperationRunner($kernel->container()))->run($operation, $input, 'work');
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => \get_class($e) . ': ' . $e->getMessage()], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), "\n";
    exit(1);
}
if ($r === null) {
    echo json_encode(['ok' => false, 'error' => "no operation «{$op}» in this app"], \JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
}
echo json_encode($r, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), "\n";

// SUCCESS IS «NO ERROR», NOT A LITERAL `ok: true`: operations answer in their own shape.
$failed = (\array_key_exists('ok', $r) && $r['ok'] !== true) || \array_key_exists('error', $r);
exit($failed ? 1 : 0);
