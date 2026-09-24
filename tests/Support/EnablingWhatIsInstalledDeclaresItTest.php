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

use Milpa\AppRuntime\Support\Capabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Enabling a capability that is already installed DECLARES what it is missing, instead of reporting
 * «nothing to do».
 *
 * `composer require` lands a package's code; it does not write its provider into
 * `config/operations.php` nor its plugins into `config/plugins.php` — only enable does. So a package
 * that arrived by composer is on disk and unusable at once. Measured on cattle (greenhouse
 * evidence/0993): after `composer require milpa/devtools`, `make` was not an operation, and a recipe
 * requiring devtools skipped enabling it because it was installed. And the enable that should have
 * fixed it answered «already installed — nothing to do»: the one command whose job is to make a
 * capability usable reported done over one that was not. That branch had no test at all.
 *
 * @guards enable on an installed-but-undeclared package, and the reader that sees the gap
 *
 * @fires  on every capabilities:enable, recipe:apply and recipe:plan
 *
 * @refuses nothing — the defect was a silence, not a missing refusal
 *
 * @subject-in milpa/app-runtime
 */
#[CoversClass(Capabilities::class)]
final class EnablingWhatIsInstalledDeclaresItTest extends TestCase
{
    private const PROVIDER = 'Vendor\\Tools\\Operations\\ToolOperations';

    private string $root = '';

    private string $vendor = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-wire-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0o775, true);
        file_put_contents($this->root . '/config/operations.php', "<?php\n\nreturn [\n    Milpa\\AppRuntime\\Operations\\AgentOperations::class,\n];\n");
        file_put_contents($this->root . '/config/plugins.php', "<?php\n\nreturn [\n];\n");

        $this->vendor = $this->root . '/vendor';
        mkdir($this->vendor . '/composer', 0o775, true);
        file_put_contents($this->vendor . '/composer/installed.json', json_encode(['packages' => [[
            'name' => 'vendor/tools',
            'extra' => ['milpa' => ['capability' => [
                'id' => 'tools',
                'title' => 'tools for the test',
                'operations' => [self::PROVIDER],
            ]]],
        ]]], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testTheReaderSeesTheGapAndOnlyTheGap(): void
    {
        self::assertSame([self::PROVIDER], Capabilities::unwired($this->root, $this->manifest()), 'installed, not declared');

        // THE POSITIVE CONTROL: once named, the same reader reports nothing — or it would report
        // every provider forever and a gap would look like the permanent state.
        $this->declare(self::PROVIDER);
        self::assertSame([], Capabilities::unwired($this->root, $this->manifest()));
    }

    public function testEnablingAnInstalledButUndeclaredPackageDeclaresItAndRunsNoComposer(): void
    {
        $answer = Capabilities::install('vendor/tools', $this->vendor, $this->composerThatMustNotRun(), root: $this->root);

        self::assertTrue($answer['ok'], (string) ($answer['error'] ?? ''));
        self::assertSame([self::PROVIDER], $answer['registered'] ?? null, 'it says what it declared');
        self::assertStringContainsString(self::PROVIDER, (string) file_get_contents($this->root . '/config/operations.php'));
        self::assertStringNotContainsString('nothing to do', (string) ($answer['hint'] ?? ''), 'and does not report done over a gap');
    }

    public function testEnablingItAgainIsNothingToDoAndWritesNothing(): void
    {
        // The control that «nothing to do» still exists — for the case where it is TRUE.
        Capabilities::install('vendor/tools', $this->vendor, $this->composerThatMustNotRun(), root: $this->root);
        $before = (string) file_get_contents($this->root . '/config/operations.php');

        $again = Capabilities::install('vendor/tools', $this->vendor, $this->composerThatMustNotRun(), root: $this->root);

        self::assertTrue($again['ok']);
        self::assertStringContainsString('nothing to do', (string) ($again['hint'] ?? ''));
        self::assertSame($before, (string) file_get_contents($this->root . '/config/operations.php'), 'idempotent');
    }

    public function testADryRunNamesWhatItWouldDeclareAndWritesNothing(): void
    {
        $before = (string) file_get_contents($this->root . '/config/operations.php');

        $dry = Capabilities::install('vendor/tools', $this->vendor, $this->composerThatMustNotRun(), dryRun: true, root: $this->root);

        self::assertTrue($dry['ok']);
        self::assertSame([self::PROVIDER], $dry['would_declare'] ?? null);
        self::assertSame($before, (string) file_get_contents($this->root . '/config/operations.php'), 'a dry run writes nothing');
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return Capabilities::declaredBy($this->vendor)['vendor/tools'] ?? [];
    }

    private function declare(string $class): void
    {
        $file = $this->root . '/config/operations.php';
        $src = (string) file_get_contents($file);
        file_put_contents($file, str_replace('];', '    \\' . $class . "::class,\n];", $src));
    }

    /**
     * The package is already on disk, so composer is the part that was already true: running it would
     * be the wrong fix — a network round-trip to re-land what is there, while the declaration stayed
     * missing. The runner fails the test if it is ever called.
     */
    private function composerThatMustNotRun(): callable
    {
        return static function (string $command): array {
            self::fail("composer must not run for an installed package, and was asked: {$command}");
        };
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
