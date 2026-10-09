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
 * Run ONE operation of a capability the house is about to close on, from the copy this file was placed in
 * (greenhouse decisions/0605, R1 — {@see Milpa\AppRuntime\Agent\CapabilityExercise}).
 *
 * ── WHAT IT IS, BESIDE `trial-run.php` ──────────────────────────────────────────────────────────
 *
 * The same act: one operation, in-process, found by the name the terminal calls it by, with nobody's signature — the
 * host already confined this process to a copy that is discarded. Two things are its own:
 *
 * - IT READS THE INPUT FROM THE DECLARATION, HERE. The house runs this at the end of the leg that built the
 *   capability, and that leg's own process booted before any of it landed: only a process started now, from the copy,
 *   sees what the operation declares. Each required input gets one value of its declared type; the input argument
 *   is ignored.
 * - IT SAYS WHAT HAPPENED IN FIELDS, NOT IN A SENTENCE. `thrown: {class, engine}` when it had to catch something —
 *   `engine` is whether it is an `\Error`, a defect no input excuses — and `missing: true` when it found no such
 *   operation, which is the house's failing and not the operation throwing. An operation that refuses with
 *   «Validation: name is required» refused: its answer travels as it gave it.
 *
 * Usage (copied into the root of a copy):  php trial-run.php <operation> '<ignored>'
 */
chdir(__DIR__);
require __DIR__ . '/vendor/autoload.php';

$op = $argv[1] ?? null;
if (! \is_string($op) || $op === '') {
    fwrite(\STDERR, "usage: exercise-run.php <operation>\n");
    exit(2);
}
$say = static function (array $answer, int $exit): never {
    echo json_encode($answer, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
    exit($exit);
};

try {
    $app = new Milpa\AppRuntime\Console\Application(__DIR__);
    // The terminal's own lookup, asked with the terminal's own name for the operation, as `trial-run.php` asks it.
    $operation = (new ReflectionMethod($app, 'find'))->invoke($app, Milpa\AppRuntime\Console\CommandName::of($op));
    if (! $operation instanceof Milpa\Command\Operation) {
        $say(['ok' => false, 'error' => "no operation «{$op}» in this app", 'missing' => true], 1);
    }
    $kernel = (new ReflectionMethod($app, 'kernel'))->invoke($app);
    $answer = (new Milpa\Console\OperationRunner($kernel->container()))->run($operation, Milpa\AppRuntime\Agent\CapabilityExercise::inputFor($operation), 'trial');
} catch (Throwable $e) {
    $say(['ok' => false, 'error' => \get_class($e) . ': ' . $e->getMessage(), 'thrown' => ['class' => \get_class($e), 'engine' => $e instanceof Error]], 1);
}

// An operation answers in its own shape, and most answer data and no verdict: only what it says of itself is read.
$answer = \is_array($answer) ? $answer : ['answer' => $answer];
// What it answered is the operation's, and it cannot say it threw: those two fields are this runner's alone.
unset($answer['thrown'], $answer['missing']);
$say($answer, (\array_key_exists('ok', $answer) && $answer['ok'] !== true) || \array_key_exists('error', $answer) ? 1 : 0);
