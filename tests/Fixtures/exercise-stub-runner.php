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

// A STAND-IN FOR `resources/trial-run.php` that needs no app, for the exercise the house makes before it closes
// (greenhouse decisions/0605): each operation name is one way an operation can answer, in the shapes the real runner
// prints. What a call leaves, it leaves in the copy it runs in — `var/` — so the next call of the same exercise finds it.
chdir(__DIR__);
$operation = $argv[1] ?? '';
$input = json_decode($argv[2] ?? '{}', true);
$input = \is_array($input) ? $input : [];

@mkdir('var', 0o777, true);
$seen = is_file('var/calls') ? (array) json_decode((string) file_get_contents('var/calls'), true) : [];
$seen[] = $operation;
file_put_contents('var/calls', json_encode($seen));
$times = \count(array_keys($seen, $operation, true));

$thrown = static function (string $class, bool $engine, string $message): never {
    echo json_encode(['ok' => false, 'error' => $class . ': ' . $message, 'thrown' => ['class' => $class, 'engine' => $engine]], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), "\n";
    exit(1);
};

switch ($operation) {
    case 'stub:answers':
        echo json_encode(['ok' => true, 'given' => $input, 'calls' => $seen]), "\n";
        exit(0);
    case 'stub:answers-in-its-own-shape':
        // No `ok` at all: most operations return data, not a verdict.
        echo json_encode(['name' => 'x', 'enabled' => true]), "\n";
        exit(0);
    case 'stub:refuses':
        echo json_encode(['ok' => false, 'error' => 'there is no account «7»']), "\n";
        exit(1);
    case 'stub:refuses-with-a-colon':
        // A refusal in the operation's own words that READS like «Class: message». It is not a throw.
        echo json_encode(['ok' => false, 'error' => 'Validation: name is required']), "\n";
        exit(1);
    case 'stub:throws':
        $thrown('Error', true, "Call to undefined method App\\Plugins\\Ledger\\Accounts::add()\nStack trace:\n#0 {main}");
        // no break
    case 'stub:throws-an-exception-of-the-apps':
        $thrown('App\\Plugins\\Ledger\\NoSuchAccount', false, 'no account «7»');
        // no break
    case 'stub:throws-the-second-time':
        if ($times > 1) {
            $thrown('TypeError', true, 'array_merge(): Argument #1 must be of type array, null given');
        }
        echo json_encode(['ok' => true]), "\n";
        exit(0);
    case 'stub:throws-a-secret':
        // What a session's code can put in a message: a value the house keeps as a secret.
        $thrown('RuntimeException', false, 'could not reach https://api.test with the key sk-fixture-secret-0605, twice');
        // no break
    case 'stub:throws-its-own-path':
        // And where it ran: the copy, whose path is the house's machinery and not the session's.
        $thrown('RuntimeException', false, 'cannot open ' . __DIR__ . '/var/accounts.json for writing');
        // no break
    case 'stub:dies':
        // A fatal that is no Throwable: the process ends and the runner never prints its answer.
        fwrite(\STDERR, "PHP Fatal error:  Cannot redeclare App\\Plugins\\Ledger\\helper() in /app/src/Plugins/Ledger/helpers.php on line 9\n");
        exit(255);
    case 'stub:exits-quietly':
        exit(0);
    case 'stub:sleeps':
        sleep(30);
        echo json_encode(['ok' => true]), "\n";
        exit(0);
    case 'stub:is-not-found':
        echo json_encode(['ok' => false, 'error' => "no operation «{$operation}» in this app", 'missing' => true], \JSON_UNESCAPED_UNICODE), "\n";
        exit(1);
    case 'stub:writes-into-the-house':
        // The copy is <house>/var/exercises/<id>/copy: four levels up is the house itself, read-only from in here.
        echo json_encode(['ok' => true, 'host_write' => @file_put_contents(\dirname(__DIR__, 4) . '/written-by-the-exercise.txt', 'x') !== false]), "\n";
        exit(0);
    default:
        echo json_encode(['ok' => false, 'error' => "the stub knows no «{$operation}»"], \JSON_UNESCAPED_UNICODE), "\n";
        exit(1);
}
