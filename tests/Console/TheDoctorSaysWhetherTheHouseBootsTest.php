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

namespace Milpa\AppRuntime\Tests\Console;

use Milpa\AppRuntime\Console\Application;
use Milpa\AppRuntime\Support\BootProbe;
use Milpa\AppRuntime\Web\PasskeyPlugin;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * `coa doctor` says whether the house BOOTS, not only whether its plugin graph closes (greenhouse decisions/0533).
 *
 * Measured in evidence/1067: a 0.200.3 house moved to 0.201.0 died at every boot (its relying party had no
 * origins), and `coa doctor` answered «✓ el grafo cierra», exit 0 — so `coa update`, which reads the doctor's
 * exit as its boot check, printed `boots: 1` over a house that booted for nobody. The doctor now asks a fresh
 * process, the way the recovery path does, and names the reason.
 *
 * It also says when the passkey door runs on origins the house derived because config/app.php declares none —
 * and the one act that writes them.
 */
final class TheDoctorSaysWhetherTheHouseBootsTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-doctor-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o777, true);
        mkdir($this->root . '/vendor', 0o777, true);
        file_put_contents($this->root . '/vendor/autoload.php', "<?php\n"); // the probe asks for it before it spawns
        // What the doctor's own table of promises boots in this process — the probe above boots another.
        file_put_contents($this->root . '/config/boot.php', "<?php\nreturn ['container' => new \\" . DIContainer::class . "(), 'plugins' => []];\n");
        file_put_contents($this->root . '/config/plugins.php', "<?php\n\nreturn [\n    \\" . PasskeyPlugin::class . "::class,\n];\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAHouseWhoseGraphClosesButDoesNotBootIsToldSoWithTheReason(): void
    {
        $this->app(['passkey' => ['rpId' => 'localhost', 'origins' => ['http://localhost:8000']]]);

        [$exit, $out] = $this->doctor(['ok' => false, 'error' => 'InvalidArgumentException: something in a plugin boot']);

        self::assertSame(1, $exit, 'a house that does not boot is not a healthy house');
        self::assertStringContainsString('✓ el grafo cierra', $out, 'the graph verdict is still said, on its own line');
        self::assertStringContainsString('✗ this house does not boot: InvalidArgumentException: something in a plugin boot', $out);
    }

    public function testAHouseThatBootsIsNotToldOtherwise(): void
    {
        $this->app(['passkey' => ['rpId' => 'localhost', 'origins' => ['http://localhost:8000']]]);

        [$exit, $out] = $this->doctor(['ok' => true]);

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('does not boot', $out);
        self::assertStringNotContainsString('passkey.origins', $out, 'origins declared: nothing to say');
    }

    public function testADoorOnDerivedOriginsIsNamedWithTheActThatWritesThem(): void
    {
        $this->app(['passkey' => ['rpId' => 'localhost']]);

        [$exit, $out] = $this->doctor(['ok' => true]);

        self::assertSame(0, $exit, 'derived origins boot: a notice, not a failure');
        self::assertStringContainsString('passkey.origins', $out);
        self::assertStringContainsString('http://localhost:8000', $out, 'the origins the door is held to');
        self::assertStringContainsString('capabilities:enable identity --sign', $out, 'and the act that writes them');
    }

    /**
     * The origins the serving process adds are in no file (greenhouse decisions/0534): the doctor says them, so the
     * Desktop's port is not a mystery to whoever reads the doctor inside its container.
     */
    public function testTheOriginsTheServingProcessAddsAreSaid(): void
    {
        $this->app(['passkey' => ['rpId' => 'localhost', 'origins' => ['http://localhost:8000']]]);
        [, $quiet] = $this->doctor(['ok' => true]);
        self::assertStringNotContainsString(PasskeyPlugin::SERVED_ORIGINS_ENV, $quiet, 'nothing added: nothing to say');

        putenv(PasskeyPlugin::SERVED_ORIGINS_ENV . '=http://localhost:8899');
        try {
            [$exit, $out] = $this->doctor(['ok' => true]);
        } finally {
            putenv(PasskeyPlugin::SERVED_ORIGINS_ENV);
        }

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('http://localhost:8899 (' . PasskeyPlugin::SERVED_ORIGINS_ENV . ')', $out);
    }

    public function testAnRpIdTheDoorDoesNotReadIsNotAnnounced(): void
    {
        file_put_contents($this->root . '/config/plugins.php', "<?php\n\nreturn [];\n");
        $this->app(['passkey' => ['rpId' => 'localhost']]);

        [, $out] = $this->doctor(['ok' => true]);

        self::assertStringNotContainsString('passkey.origins', $out, 'without the plugin declared, the rpId is inert');
    }

    /** @param array<string, mixed> $config */
    private function app(array $config): void
    {
        file_put_contents($this->root . '/config/app.php', '<?php return ' . var_export($config, true) . ";\n");
    }

    /**
     * Runs `coa doctor` with a probe whose child answers `$boot` the way resources/house-observe.php does.
     *
     * @param array<string, mixed> $boot
     *
     * @return array{0: int, 1: string}
     */
    private function doctor(array $boot): array
    {
        $script = $this->root . '/observe.php';
        file_put_contents($script, '<?php echo "@@house-observe " . ' . var_export((string) json_encode($boot), true) . ' . "\n"; exit(' . (($boot['ok'] ?? false) === true ? 0 : 1) . ');');

        ob_start();
        $exit = (new Application($this->root, bootProbe: new BootProbe(script: $script)))->run(['coa', 'doctor']);

        return [$exit, (string) ob_get_clean()];
    }
}
