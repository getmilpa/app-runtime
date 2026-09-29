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

// The real relay over the stand-in child, on this process's own STDIN/STDOUT — what a client would start.
require $argv[2];

$state = $argv[1];
$relay = new Milpa\AppRuntime\Console\KernelSupervisor(
    static fn (): array => [\PHP_BINARY, __DIR__ . '/child.php', $state],
    $state,
    \STDIN,
    \STDOUT,
    \STDERR,
    idle: (float) ($argv[3] ?? 0.3),
);
exit($relay->relay());
