<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * What a process writes into the house, it runs on its next include — not after OPcache revalidates (decisions/0506).
 *
 * Measured in greenhouse evidence/1038 (o4): after a promotion wrote an edit, `php -S` and FrankenPHP classic
 * served the old code for ~2.35 s. EXECUTED in a child PHP with OPcache on (`opcache.enable_cli=1`, the default
 * `revalidate_freq` of 2 s): the file is included, rewritten, and included again within the same second.
 *
 * @guards a promoted edit and an undo are run on the very next include
 *
 * @refuses nothing — the positive control is the same child WITHOUT the invalidation, which runs the old bytecode
 *
 * @subject-in milpa/app-runtime
 */
final class APromotionIsServedOnTheNextRequestTest extends TestCase
{
    public function testTheWrittenFileIsRunOnTheNextInclude(): void
    {
        self::assertSame('v1 v2', $this->child(forget: true));
    }

    /** POSITIVE CONTROL: without the invalidation, the same child runs the bytecode OPcache kept — the 1038 window. */
    public function testWithoutItOpcacheServesTheOldCode(): void
    {
        self::assertSame('v1 v1', $this->child(forget: false));
    }

    private function child(bool $forget): string
    {
        exec(escapeshellarg(\PHP_BINARY) . ' -d opcache.enable_cli=1 -d opcache.jit=disable -r ' . escapeshellarg('echo (int) function_exists("opcache_get_status") && is_array(@opcache_get_status(false));'), $probe);
        if (($probe[0] ?? '0') !== '1') {
            self::markTestSkipped('this PHP has no OPcache to measure (the lab image has it: greenhouse evidence/1039)');
        }
        $root = sys_get_temp_dir() . '/milpa-compiled-' . bin2hex(random_bytes(4));
        mkdir($root . '/src', 0o777, true);
        $script = $root . '/child.php';
        file_put_contents($script, '<?php
require ' . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';
$file = ' . var_export($root . '/src/Hola.php', true) . ';
file_put_contents($file, "<?php return \'v1\';");
touch($file, time() - 10); // older than file_update_protection, so OPcache keeps it
$first = include $file;
file_put_contents($file, "<?php return \'v2\';");
touch($file, time() - 10); // the same second as the bytecode: only the content moved
' . ($forget ? 'Milpa\AppRuntime\Support\CompiledCode::forget(' . var_export($root, true) . ', ["src/Hola.php"]);' : '') . '
echo $first, " ", include $file;
');
        try {
            // stdout only, JIT off: a PHP with a debugger loaded warns «JIT disabled» on stderr, and that is not the answer.
            exec(escapeshellarg(\PHP_BINARY) . ' -d opcache.enable_cli=1 -d opcache.jit=disable ' . escapeshellarg($script) . ' 2>/dev/null', $out);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }

        return implode("\n", $out);
    }
}
