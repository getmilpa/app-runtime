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

// Two stand-ins in one file, for a test that needs a real pseudo-terminal (greenhouse decisions/0524).
// `supervise`: runs KernelSupervisor::terminal over `child`, then prints the terminal's flags as the person finds them.
// `child`: makes the terminal raw the way a screen does, then dies by SIGKILL — the one death nothing can catch.
require dirname(__DIR__, 3) . '/vendor/autoload.php';

if (($argv[1] ?? '') === 'child') {
    shell_exec('stty -icanon -echo -isig min 0 time 0');
    fwrite(STDOUT, 'raw:' . trim((string) shell_exec('stty -a')) . "\n");
    posix_kill(getmypid(), 9);
    sleep(5);
    exit(0);
}

$code = Milpa\AppRuntime\Console\KernelSupervisor::terminal(
    static fn (?string $showing): array => [PHP_BINARY, __FILE__, 'child'],
    __DIR__,
    surface: 'chat',
);
fwrite(STDOUT, "code:{$code}\nafter:" . trim((string) shell_exec('stty -a')) . "\nend\n");
