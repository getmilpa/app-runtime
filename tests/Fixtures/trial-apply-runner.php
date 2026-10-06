<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

// A deterministic producer inside the disposable copy, for the cases of «apply: when_verified»
// (greenhouse decisions/0578): what the trial says of itself is the fixture's `case`.
$input = json_decode($argv[2] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$case = $input['fixture'] ?? 'verified';
$output = ['ok' => true, 'house_boots' => true, 'received' => $input];

if ($case !== 'no-change') {
    file_put_contents(__DIR__ . '/src/Plugins/Blog/Blog.php', "<?php // the plugin\n");
}
if ($case === 'says-not-ok') {
    $output['ok'] = false;
}
if ($case === 'unbootable') {
    $output['house_boots'] = false;
}
if ($case === 'unverified') {
    $output['verify'] = ['ok' => false, 'output' => 'FAIL: Post'];
}
if ($case === 'missing-postconditions') {
    $output['postconditions'] = ['ok' => false, 'missing' => ['entity_file']];
}
if ($case === 'failed') {
    $output['ok'] = false;
    $output['error'] = 'The producer refused.';
}
echo json_encode($output, JSON_THROW_ON_ERROR), "\n";
if ($case === 'failed') {
    exit(1);
}
