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

// A stand-in for `coa panel <section> --child`: records the section it was opened on; the first `<state>/stale`
// times it leaves as stale, handing over the section named in `<state>/showing`; then it leaves with 0.
[, $state] = $argv;
$section = $argv[2] ?? '-';
file_put_contents($state . '/opened.log', $section . "\n", \FILE_APPEND);
$left = (int) @file_get_contents($state . '/stale');
if ($left > 0) {
    file_put_contents($state . '/stale', (string) ($left - 1));
    $handoff = fopen('php://fd/3', 'w');
    fwrite($handoff, trim((string) @file_get_contents($state . '/showing')));
    fclose($handoff);
    exit(75);
}
exit((int) @file_get_contents($state . '/exit'));
