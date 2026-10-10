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

// A scripted MCP client for the relay under test: its STDOUT is what the relay reads from the client, its STDIN is
// what the relay writes back. It walks `<state>/plan.json` and records every line it receives, with the step that
// was waiting for it, in `<state>/transcript.jsonl`. The relay runs IN the test process — this is the other end.
//
// Steps: {"send": {...}} · {"raw": "line"} · {"unterminated": {...}} (no newline after it) · {"done-asking": true}
// (closes its writing end and keeps reading) · {"expect": seconds} (the next line, or a timeout record) ·
// {"quiet": seconds} (records anything that arrives) · {"write": ["file", "content"]} · {"touch": "file"} ·
// {"remove": "file"}
[, $state] = $argv;
$plan = json_decode((string) file_get_contents($state . '/plan.json'), true);
$record = static function (array $entry) use ($state): void {
    file_put_contents($state . '/transcript.jsonl', json_encode($entry) . "\n", \FILE_APPEND);
};
stream_set_blocking(\STDIN, false);
$buffer = '';
$next = static function (float $seconds) use (&$buffer): ?string {
    $until = microtime(true) + $seconds;
    while (($nl = strpos($buffer, "\n")) === false) {
        $left = $until - microtime(true);
        if ($left <= 0) {
            return null;
        }
        $read = [\STDIN];
        $write = $except = null;
        if (stream_select($read, $write, $except, (int) $left, (int) (($left - (int) $left) * 1_000_000)) > 0) {
            $chunk = (string) fread(\STDIN, 65536);
            if ($chunk === '' && feof(\STDIN)) {
                return null;
            }
            $buffer .= $chunk;
        }
    }
    $line = substr($buffer, 0, $nl);
    $buffer = substr($buffer, $nl + 1);

    return $line;
};

foreach ($plan as $i => $step) {
    if (isset($step['send'])) {
        fwrite(\STDOUT, json_encode($step['send']) . "\n");
        fflush(\STDOUT);
    } elseif (isset($step['raw'])) {
        fwrite(\STDOUT, $step['raw'] . "\n");
        fflush(\STDOUT);
    } elseif (isset($step['unterminated'])) {
        // The last line of a pipe that ends without a newline: `printf '%s' …`.
        fwrite(\STDOUT, json_encode($step['unterminated']));
        fflush(\STDOUT);
    } elseif (isset($step['done-asking'])) {
        // A script or a CI: it has asked everything it will ask, closes its writing end, and goes on reading.
        fclose(\STDOUT);
    } elseif (isset($step['expect'])) {
        $line = $next((float) $step['expect']);
        $record(['step' => $i, 'line' => $line]);
    } elseif (isset($step['quiet'])) {
        $until = microtime(true) + (float) $step['quiet'];
        while (($left = $until - microtime(true)) > 0) {
            $line = $next($left);
            if ($line !== null) {
                $record(['step' => $i, 'line' => $line]);
            }
        }
    } elseif (isset($step['write'])) {
        file_put_contents($state . '/' . $step['write'][0], $step['write'][1]);
    } elseif (isset($step['touch'])) {
        touch($state . '/' . $step['touch']);
    } elseif (isset($step['remove'])) {
        @unlink($state . '/' . $step['remove']);
    }
}
