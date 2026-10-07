<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

// A deterministic handler of domain work, run where the house runs work (greenhouse decisions/0588): what it tries
// to do is the fixture's `case`, and it answers with what the system let it do.
// Usage: php work-runner.php <house root> <operation> '<json input>'
$root = $argv[1];
$input = json_decode($argv[3] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$case = $input['fixture'] ?? 'add';
$wrote = static fn (string $path, string $bytes): bool => @file_put_contents($path, $bytes) !== false;

if ($case === 'add' || $case === 'add-and-fail') {
    // In place, as Milpa\Data\FileRepository writes: open, lock, truncate, write.
    $handle = @fopen($root . '/var/herramientas.json', 'c+');
    if ($handle === false) {
        echo json_encode(['ok' => false, 'error' => 'the store could not be opened: ' . (error_get_last()['message'] ?? '')]), "\n";
        exit(1);
    }
    flock($handle, LOCK_EX);
    $rows = json_decode((string) stream_get_contents($handle), true);
    $rows = is_array($rows) ? $rows : [];
    $rows[] = ['id' => count($rows) + 1, 'nombre' => $input['nombre'] ?? 'Taladro', 'prestada' => false];
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) json_encode($rows));
    fclose($handle);
    if ($case === 'add-and-fail') {
        echo json_encode(['ok' => false, 'error' => 'the rule refused after writing']), "\n";
        exit(1);
    }
    echo json_encode(['ok' => true, 'id' => count($rows)]), "\n";
    exit(0);
}
if ($case === 'peek') {
    // What a handler can see of the pre-images the house keeps while it runs.
    echo json_encode(['ok' => true, 'pre' => array_map('basename', glob($root . '/var/work/*/pre/var/*') ?: [])]), "\n";
    exit(0);
}
if ($case === 'nothing') {
    echo json_encode(['ok' => true, 'id' => 1]), "\n";
    exit(0);
}
if ($case === 'refuses') {
    echo json_encode(['ok' => false, 'error' => 'ya_prestada']), "\n";
    exit(1);
}
if ($case === 'write') {
    $ok = $wrote($root . '/' . $input['path'], "<?php // written by a handler declared data\n");
    echo json_encode(['ok' => $ok, 'wrote' => $ok, 'error' => $ok ? null : (error_get_last()['message'] ?? 'refused')]), "\n";
    exit($ok ? 0 : 1);
}
if ($case === 'connect') {
    $socket = @stream_socket_client('tcp://127.0.0.1:' . $input['port'], $errno, $error, 2.0);
    echo json_encode(['ok' => true, 'connected' => $socket !== false]), "\n";
    exit(0);
}
if ($case === 'sqlite') {
    try {
        $pdo = new PDO('sqlite:' . $root . '/' . $input['path']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS herramientas (nombre TEXT)');
        $pdo->exec("INSERT INTO herramientas VALUES ('Taladro')");
        echo json_encode(['ok' => true, 'rows' => (int) $pdo->query('SELECT COUNT(*) FROM herramientas')->fetchColumn()]), "\n";
        exit(0);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]), "\n";
        exit(1);
    }
}
if ($case === 'tmp') {
    $ok = $wrote(sys_get_temp_dir() . '/scratch.txt', 'x');
    echo json_encode(['ok' => $ok, 'tmp' => sys_get_temp_dir()]), "\n";
    exit($ok ? 0 : 1);
}
echo json_encode(['ok' => false, 'error' => "unknown fixture case «{$case}»"]), "\n";
exit(1);
