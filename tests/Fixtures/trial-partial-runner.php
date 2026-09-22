<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

// A deterministic producer inside the disposable copy. Confinement and actual multipart
// promotion are measured separately with real bwrap in Greenhouse evidence/0907.
$input = json_decode($argv[2] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$case = $input['fixture'] ?? 'start';
$file = 'src/Plugins/Owned/Services/Renderer.php';
$staging = $file . '.milpa-part';
$bytes = "<?php // Partial renderer\n";
$output = ['ok' => true, 'file' => $file, 'staging' => $staging,
    'sha256' => hash('sha256', $bytes), 'partial' => 'Producer says append next'];

if ($case !== 'no-change') {
    file_put_contents(__DIR__ . '/' . $staging, $bytes);
}
if ($case === 'extra-change') {
    file_put_contents(__DIR__ . '/src/Plugins/Owned/Services/Extra.php', '<?php // Extra');
}
if ($case === 'deleted') {
    unlink(__DIR__ . '/' . $staging);
}
if ($case === 'empty-partial') {
    $output['partial'] = '';
}
if ($case === 'missing-partial') {
    unset($output['partial']);
}
if ($case === 'verified') {
    $output['verified'] = 'Producer declaration';
}
if ($case === 'wrong-path') {
    $output['staging'] = 'other.php.milpa-part';
}
if ($case === 'wrong-file') {
    $output['file'] = 'source.txt';
}
if ($case === 'missing-file') {
    unset($output['file']);
}
if ($case === 'bad-hash') {
    $output['sha256'] = 'not-a-sha256';
}
if ($case === 'missing-hash') {
    unset($output['sha256']);
}
if ($case === 'different-hash') {
    $output['sha256'] = str_repeat('0', 64);
}
if ($case === 'failed') {
    $output['ok'] = false;
    $output['error'] = 'Producer refused the part';
}
if ($case === 'missing-output') {
    echo "null\n";
} else {
    echo json_encode($output, JSON_THROW_ON_ERROR), "\n";
}
if ($case === 'failed') {
    exit(1);
}
