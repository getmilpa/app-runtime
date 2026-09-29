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

namespace Milpa\AppRuntime\Tests\Console;

use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Support\BootProbe;
use Milpa\AppRuntime\Tests\Fixtures\LabSigner;
use Milpa\AppRuntime\Tests\Fixtures\TinyHouse;
use PHPUnit\Framework\TestCase;

/**
 * `coa plugins:disable-unsafe` turns off the plugin that broke the boot, without booting (greenhouse decisions/0506).
 *
 * Measured on the published train (greenhouse evidence/1036, R1): the house's own «Recovery only» operation
 * died at boot like everything else. Here the shape of seq 442 — a plugin whose `boot()` builds a service
 * without the argument its constructor wants — and `coa` in a child process, as a terminal runs it.
 *
 * @guards a signed disable-unsafe writes the plugin off in `storage/plugins.json`, and the house boots again
 *
 * @refuses an unsigned call (the operation requires confirmation, as always) and a key without `plugins:write`
 *
 * @subject-in milpa/app-runtime
 */
final class DisableUnsafeWithoutBootTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TinyHouse::create('Blog', 'Other');
        file_put_contents($this->root . '/src/Plugins/Blog/Blog.php', str_replace(
            'public function boot(): void {}',
            'public function boot(): void { new \ArrayIterator(...[]); (static fn (array $posts) => $posts)(); }',
            TinyHouse::pluginSource('Blog'),
        ));
        self::assertStringStartsWith('ArgumentCountError: ', (string) (new BootProbe())->whyNot($this->root));
    }

    protected function tearDown(): void
    {
        TinyHouse::remove($this->root);
    }

    public function testASignedDisableUnsafeTurnsTheBrokenPluginOffWithoutBooting(): void
    {
        [$exit, $out] = $this->coa(['plugins:disable-unsafe', '--name=Blog', '--sign']);

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('Recovering without booting it: only plugins:disable-unsafe runs here', $out);
        self::assertStringContainsString('✓ The house boots again.', $out);
        self::assertStringContainsString('"Blog"', (string) file_get_contents($this->root . '/storage/plugins.json'));
        self::assertNull((new BootProbe())->whyNot($this->root));
    }

    public function testUnsignedItAsksForTheSignatureAsAlways(): void
    {
        [$exit, $out] = $this->coa(['plugins:disable-unsafe', '--name=Blog']);

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('Re-run with --sign', $out);
        self::assertFileDoesNotExist($this->root . '/storage/plugins.json');
    }

    public function testAKeyWithoutPluginsWriteIsRefused(): void
    {
        (new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json'))->record(new IdentityEnrolled((new LabSigner())->fingerprint, ['agent:run', 'agent:read'], 'key:0000'));

        [$exit, $out] = $this->coa(['plugins:disable-unsafe', '--name=Blog', '--sign']);

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('plugins:write', $out);
        self::assertFileDoesNotExist($this->root . '/storage/plugins.json');
    }

    /**
     * @param list<string> $argv
     *
     * @return array{0: int, 1: string}
     */
    private function coa(array $argv): array
    {
        $script = $this->root . '/var/coa.php';
        file_put_contents($script, '<?php
require ' . var_export($this->root . '/vendor/autoload.php', true) . ';
$key = new Milpa\AppRuntime\Tests\Fixtures\LabSigner();
exit((new Milpa\AppRuntime\Console\Application(' . var_export($this->root, true) . ', $key, $key))->run(["coa", ...json_decode($argv[1], true)]));
');
        exec('MILPA_TOKEN= ' . escapeshellarg(\PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($script) . ' ' . escapeshellarg((string) json_encode($argv)) . ' 2>&1', $out, $exit);

        return [$exit, implode("\n", $out)];
    }
}
