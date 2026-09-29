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

// A stand-in for `coa mcp --child` with the same contract and none of the kernel: it remembers the generation it
// booted with (`<state>/generation`) and leaves with 75 BEFORE running a request when that changed, or AFTER a
// `bump` request that changes it. Every request it RUNS is appended to `<state>/ran.log`; every boot to
// `<state>/starts.log`. `<state>/broken` makes it die at boot; `<state>/tools.json` is what it lists.
$state = $argv[1];
file_put_contents($state . '/starts.log', "start\n", \FILE_APPEND);
if (is_file($state . '/broken')) {
    fwrite(\STDERR, "✗ the plugin Broken is no plugin\n");
    exit(1);
}
$generation = static fn (): string => is_file($state . '/generation') ? (string) file_get_contents($state . '/generation') : '0';
$booted = $generation();
$write = static function (array $message): void {
    fwrite(\STDOUT, json_encode($message) . "\n");
    fflush(\STDOUT);
};

while (($line = fgets(\STDIN)) !== false) {
    if ($generation() !== $booted) {
        exit(75);
    }
    $request = json_decode(trim($line), true);
    if (!is_array($request)) {
        continue;
    }
    $id = $request['id'] ?? null;
    $method = (string) ($request['method'] ?? '');
    $tag = (string) ($request['params']['tag'] ?? '');
    if ($method !== 'ping' && $method !== 'tools/list') {
        file_put_contents($state . '/ran.log', "{$method} {$tag} {$booted}\n", \FILE_APPEND);
    }
    if ($method === 'echo-garbage') {
        fwrite(\STDOUT, "an echo somewhere in the house\n");
    }
    $result = match ($method) {
        'initialize' => ['protocolVersion' => '2025-06-18', 'capabilities' => ['tools' => new stdClass()]],
        'tools/list' => ['tools' => json_decode((string) @file_get_contents($state . '/tools.json'), true) ?: []],
        default => ['tag' => $tag, 'generation' => $booted],
    };
    if ($id !== null) {
        $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }
    if ($method === 'bump') {
        file_put_contents($state . '/generation', (string) ((int) $booted + 1));
    }
    if ($generation() !== $booted) {
        exit(75);
    }
}
