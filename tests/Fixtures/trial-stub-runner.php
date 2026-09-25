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

// A STAND-IN FOR `resources/trial-run.php` that needs no app: it proves WHERE a trial process may
// write and WHAT it may reach, which is the whole claim of the confinement. It writes inside its own
// directory (the copy), tries to write into the host root it is told about, and tries one socket.
$operation = $argv[1] ?? '';
$input = json_decode($argv[2] ?? '{}', true);
$input = \is_array($input) ? $input : [];

// A producer that refuses WITH its reason, the way component:define and screen:declare do.
if ($operation === 'refuse') {
    echo json_encode(['ok' => false, 'error' => 'invalid word', 'path' => 'inputs.heading', 'reason' => 'nothing in the composition reads «$heading»']), "\n";
    exit(1);
}

if ($operation === 'fail') {
    echo json_encode(['ok' => false, 'error' => 'asked to fail']), "\n";
    exit(1);
}

// A producer that DEMONSTRATES something in the copy and says so in a receipt, the way
// screen:declare does (greenhouse decisions/0463): the wrapper must seal where it was observed.
if ($operation === 'serve') {
    @mkdir(__DIR__ . '/config', 0o777, true);
    file_put_contents(__DIR__ . '/config/screens.json', json_encode(['blog' => ['type' => 'data-table', 'props' => []]]));
    echo json_encode(['ok' => true, 'screen' => 'blog', 'evidence' => ['predicate' => 'served', 'subject' => 'blog', 'servedAt' => '/live/page?component=blog']]), "\n";
    exit(0);
}

if ($operation === 'sleep') {
    sleep(30);
}

file_put_contents(__DIR__ . '/touched.txt', "trial\n");

$host = \is_string($input['host'] ?? null) ? $input['host'] : '/nonexistent';
$hostWrite = @file_put_contents($host . '/host-touched.txt', "x\n") !== false;

$errno = 0;
$errstr = '';
$socket = @fsockopen('1.1.1.1', 80, $errno, $errstr, 1.0);
$net = $socket !== false;
if ($socket !== false) {
    fclose($socket);
}

echo json_encode(['ok' => true, 'operation' => $operation, 'host_write' => $hostWrite, 'net' => $net]), "\n";
