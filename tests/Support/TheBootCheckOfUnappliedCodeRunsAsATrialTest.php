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

use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Support\BootProbe;
use Milpa\AppRuntime\Support\HouseBootWitness;
use PHPUnit\Framework\TestCase;

/**
 * THE BOOT CHECK OF CODE NOBODY APPLIED RUNS AS A TRIAL (greenhouse decisions/0607, Rod's alternative A;
 * evidence/1180): confined, no network, the envelope and the keyring masked. The applied house's boot does not
 * change — it is the governed act (0606 §3) and boots plain, with its secrets.
 *
 * And it SAYS the two things 1180 §4.1 asks for: the ROOM it ran in (and, where there is no bwrap, that it ran
 * unconfined), and the plugins that mounted FEWER routes without their secret than the house mounts with it —
 * because a plugin that fails closed without its secret mounts nothing and says nothing (decisions/0569), so
 * «boots» would read as «boots as it will run» when the house it booted is smaller.
 */
final class TheBootCheckOfUnappliedCodeRunsAsATrialTest extends TestCase
{
    private string $root;

    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-boot-trial-' . bin2hex(random_bytes(4));
        foreach (['src/Plugins/Normal', 'src/Plugins/FailClosed', 'vendor', 'config', 'public', 'var', '.milpa'] as $dir) {
            mkdir($this->root . '/' . $dir, 0o777, true);
        }
        file_put_contents($this->root . '/vendor/autoload.php', '<?php
$loader = require ' . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . "/../src/" . str_replace("\\\\", "/", substr($class, 4)) . ".php";
    if (str_starts_with($class, "App\\\\") && is_file($file)) {
        require $file;
    }
});
return $loader;
');
        file_put_contents($this->root . '/config/plugins.php', self::pluginsFile('Normal', 'FailClosed'));
        file_put_contents($this->root . '/config/boot.php', '<?php return ["container" => new \Milpa\Container\DIContainer(), "plugins" => require __DIR__ . "/plugins.php"];');
        file_put_contents($this->root . '/config/app.php', '<?php return [];');
        file_put_contents($this->root . '/public/index.php', "<?php\n");
        file_put_contents($this->root . '/.milpa/secrets.json', '{"live":{"secret":"canary"}}');
        file_put_contents($this->root . '/src/Plugins/Normal/Normal.php', self::routePlugin('Normal', '/always'));
        // A plugin that fails closed: it mounts its route only when it can read the envelope — the house's own Live,
        // AgentWorkspace and Passkey do this (decisions/0569). Under the mask the envelope is /dev/null, so it is
        // empty, and the plugin mounts nothing without throwing.
        file_put_contents($this->root . '/src/Plugins/FailClosed/FailClosed.php', self::failClosedPlugin('FailClosed', '/live'));
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name => $value) {
            $value === false ? putenv($name) : putenv("{$name}={$value}");
        }
        self::rmrf($this->root);
    }

    public function testWhereThereIsNoNamespaceTheCandidateBootsUnconfinedAndTheRoomSaysSo(): void
    {
        $probe = new BootProbe(runner: new TrialRunner(bwrap: $this->bwrap('none')));

        $check = $probe->check($this->root, ['src/Plugins/Normal/Normal.php' => self::routePlugin('Normal', '/always') . "\n// touched\n"]);

        self::assertNull($check['why'], (string) json_encode($check));
        self::assertFalse($check['confined'], 'with no unprivileged namespace the candidate boots unconfined');
        self::assertStringContainsString('UNCONFINED', $check['room'], 'and the room SAYS it ran unconfined (decisions/0607)');
    }

    public function testTheConfinedCandidateBootHasNoNetworkTheEnvelopeAndTheKeyringMasked(): void
    {
        $home = $this->dir();
        mkdir($home . '/.gnupg', 0o700, true);
        $this->setEnv('HOME', $home);
        $this->setEnv('GNUPGHOME', false);
        $bwrap = $this->bwrap('host');
        $probe = new BootProbe(runner: new TrialRunner(bwrap: $bwrap));

        $check = $probe->check($this->root, ['src/Plugins/Normal/Normal.php' => self::routePlugin('Normal', '/always') . "\n// touched\n"]);

        self::assertTrue($check['confined']);
        self::assertStringContainsString('confined', $check['room']);
        self::assertStringContainsString('keyring masked', $check['room']);
        // The BOOT command itself (the recorded line that binds the candidate), NOT the namespace probe — the probe
        // always carries --unshare-net, so asserting on the whole log would miss a boot that dropped the network.
        $boot = $this->bootCommandLine();
        self::assertStringContainsString('--bind ' . $this->root . '/var/boot-candidates/', $boot, 'the candidate is bound so its own var/ can be written while it boots');
        self::assertStringContainsString('--unshare-net', $boot, 'the confined boot has no network');
        self::assertStringContainsString('--ro-bind / /', $boot, 'the confined boot runs under a read-only root');
        self::assertStringContainsString('--ro-bind /dev/null ' . $this->root . '/.milpa/secrets.json', $boot, 'the envelope is masked');
        self::assertStringContainsString('--tmpfs ' . $home . '/.gnupg', $boot, 'the keyring the house signs with is masked (evidence/1178)');
    }

    public function testItNamesAPluginThatMountedFewerRoutesWithoutItsSecretAndANewPlugin(): void
    {
        if (!(new TrialRunner())->available()) {
            self::markTestSkipped('this host offers no unprivileged user namespace for bwrap');
        }
        $writes = [
            'config/plugins.php' => self::pluginsFile('Normal', 'FailClosed', 'Added'),
            'src/Plugins/Added/Added.php' => self::routePlugin('Added', '/added'),
        ];

        $check = (new BootProbe())->check($this->root, $writes);

        self::assertNull($check['why'], (string) json_encode($check));
        self::assertTrue($check['confined'], 'the candidate booted confined');
        $less = [];
        foreach ($check['mounted_less'] as $row) {
            $less[$row['plugin']] = [$row['with_secret'], $row['without_secret']];
        }
        self::assertSame([1, 0], $less['FailClosed'] ?? null, 'the fail-closed plugin mounted its route with its secret and none under the mask: ' . json_encode($check['mounted_less']));
        self::assertArrayNotHasKey('Normal', $less, 'a plugin that does not need a secret mounts the same, masked or not');
        $new = array_column($check['new_plugins'], 'plugin');
        self::assertContains('Added', $new, 'a plugin only the candidate has is named as new: ' . json_encode($check['new_plugins']));
    }

    public function testTheUnconfinedRoomReachesWhatAWriterSaysSoAReceiptCarriesIt(): void
    {
        // Where there is no bwrap the check runs unconfined; the sentence must be READ, not die in an array. It is
        // carried into what a writer SAYS (`said`), which every writer spreads into its operation receipt
        // (capabilities:enable, framework:apply, a promotion's landing). It is NOT in the closure verdict.
        $probe = new BootProbe(runner: new TrialRunner(bwrap: $this->bwrap('none')));
        $witness = new HouseBootWitness($this->root, $probe);

        $result = $witness->writeIfItBoots(
            ['src/Plugins/Fine/Fine.php' => self::routePlugin('Fine', '/fine'), 'config/plugins.php' => self::pluginsFile('Normal', 'FailClosed', 'Fine')],
            static function (): void {},
        );

        self::assertNull($result['refused'], (string) json_encode($result));
        self::assertTrue($result['said']['house_boots'] ?? null);
        self::assertStringContainsString('UNCONFINED', $result['said']['boot_room'] ?? '', 'the writer says the check ran unconfined');
    }

    private static function pluginsFile(string ...$plugins): string
    {
        return '<?php return [' . implode(', ', array_map(static fn (string $p): string => "App\\Plugins\\{$p}\\{$p}::class", $plugins)) . "];\n";
    }

    private static function routePlugin(string $name, string $path): string
    {
        return '<?php
declare(strict_types=1);
namespace App\Plugins\\' . $name . ';
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
#[\Milpa\Attributes\PluginMetadata(version: "0.1.0", author: "t", site: "https://example.com", name: "' . $name . '", type: "Service")]
final class ' . $name . ' implements \Milpa\Interfaces\Plugin\PluginInterface, \Milpa\Runtime\Http\RouteProviderInterface
{
    public function __construct(private readonly \Milpa\Interfaces\Di\DIContainerInterface $container) {}
    public function boot(): void {}
    public function install(): void {}
    public function uninstall(): void {}
    public function enable(): void {}
    public function disable(): void {}
    public function routes(): array { return [new Route(' . var_export($path, true) . ', HttpMethod::GET, ' . var_export(trim($path, '/'), true) . ', [], new HandlerReference(self::class, "index"))]; }
}
';
    }

    private static function failClosedPlugin(string $name, string $path): string
    {
        return '<?php
declare(strict_types=1);
namespace App\Plugins\\' . $name . ';
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
#[\Milpa\Attributes\PluginMetadata(version: "0.1.0", author: "t", site: "https://example.com", name: "' . $name . '", type: "Service")]
final class ' . $name . ' implements \Milpa\Interfaces\Plugin\PluginInterface, \Milpa\Runtime\Http\RouteProviderInterface
{
    public function __construct(private readonly \Milpa\Interfaces\Di\DIContainerInterface $container) {}
    public function boot(): void {}
    public function install(): void {}
    public function uninstall(): void {}
    public function enable(): void {}
    public function disable(): void {}
    // Fails closed without its secret: mounts its route only when the envelope holds its key (decisions/0569).
    public function routes(): array
    {
        $raw = @file_get_contents(".milpa/secrets.json");
        $envelope = is_string($raw) && $raw !== "" ? json_decode($raw, true) : null;
        if (!is_array($envelope) || ($envelope["live"]["secret"] ?? "") === "") {
            return [];
        }
        return [new Route(' . var_export($path, true) . ', HttpMethod::GET, ' . var_export(trim($path, '/'), true) . ', [], new HandlerReference(self::class, "index"))];
    }
}
';
    }

    /** A bwrap that records its arguments then, for `host`, execs what follows `--`; `none` refuses every shape. */
    private function bwrap(string $kernel): string
    {
        $path = $this->root . '/bwrap-' . $kernel;
        $refuse = $kernel === 'none' ? 'true' : 'false';
        file_put_contents($path, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($this->root . '/bwrap-argv.txt') . "\n"
            . 'if ' . $refuse . "; then echo 'bwrap: Creating new namespace failed: Operation not permitted' >&2; exit 1; fi\n"
            . "while [ \$# -gt 0 ] && [ \"\$1\" != \"--\" ]; do shift; done\n"
            . "[ \"\$1\" = \"--\" ] && shift\nexec \"\$@\"\n");
        chmod($path, 0o755);

        return $path;
    }

    /** The recorded bwrap invocation that booted the candidate — the one that binds it, not the namespace probe. */
    private function bootCommandLine(): string
    {
        foreach (explode("\n", (string) @file_get_contents($this->root . '/bwrap-argv.txt')) as $line) {
            if (str_contains($line, '--bind ' . $this->root . '/var/boot-candidates/')) {
                return $line;
            }
        }

        return '';
    }

    private function setEnv(string $name, string|false $value): void
    {
        if (!\array_key_exists($name, $this->env)) {
            $this->env[$name] = getenv($name);
        }
        $value === false ? putenv($name) : putenv("{$name}={$value}");
    }

    private function dir(): string
    {
        $dir = sys_get_temp_dir() . '/milpa-boot-trial-home-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o700, true);

        return $dir;
    }

    private static function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
