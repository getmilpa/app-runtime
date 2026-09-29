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

// A stand-in for a terminal child — `coa panel <section> --child`, `coa chat --child`, `coa shell --child`: records the
// section it was opened on, what it found in its environment (the handoff, and whether an EMPTY variable of the
// person's survived); the first `<state>/stale` times it leaves as stale, handing over what `<state>/showing` names —
// and, when `<state>/settled` exists, saying its kernel had been current once; then it leaves with `<state>/exit`.
[, $state] = $argv;
$section = $argv[2] ?? '-';
file_put_contents($state . '/opened.log', $section . "\n", \FILE_APPEND);
file_put_contents($state . '/handed.log', (getenv('MILPA_TERMINAL_HANDOFF') ?: '-') . "\n", \FILE_APPEND);
file_put_contents($state . '/env.log', (array_key_exists('MILPA_LAB_EMPTY', getenv()) ? 'present' : 'absent') . "\n", \FILE_APPEND);
$left = (int) @file_get_contents($state . '/stale');
if ($left > 0) {
    file_put_contents($state . '/stale', (string) ($left - 1));
    $handoff = fopen('php://fd/3', 'w');
    fwrite($handoff, trim((string) @file_get_contents($state . '/showing')) . (is_file($state . '/settled') ? "\nsettled" : ''));
    fclose($handoff);
    exit(75);
}
$exit = trim((string) @file_get_contents($state . '/exit'));
// `kill:<n>`: ends by signal n, as a child stopped from outside does (greenhouse decisions/0524).
if (str_starts_with($exit, 'kill:') && function_exists('posix_kill')) {
    posix_kill(getmypid(), (int) substr($exit, 5));
    sleep(5);
}
exit((int) $exit);
