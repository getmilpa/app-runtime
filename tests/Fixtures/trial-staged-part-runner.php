<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

$path = 'src/Plugins/Owned/Services/TodoItemRenderer.php';
$staging = $path . '.milpa-part';
if (!is_dir(__DIR__ . '/src/Plugins/Owned/Services')) {
    mkdir(__DIR__ . '/src/Plugins/Owned/Services', 0o777, true);
}
file_put_contents(__DIR__ . '/' . $staging, "<?php // staged only\n");
echo json_encode(['ok' => true, 'file' => $path, 'staging' => $staging, 'partial' => 'started']), "\n";
