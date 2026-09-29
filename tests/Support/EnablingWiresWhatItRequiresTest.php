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
use Milpa\AppRuntime\Support\DeclarationLedger;
use Milpa\AppRuntime\Web\PasskeyPlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Enabling a capability also wires what it REQUIRES that is installed and was never declared — and never
 * re-adds what somebody removed or disabled (greenhouse decisions/0520, Rod 2026-09-29).
 *
 * `enable admin --sign` on a house whose `milpa/admin` and `milpa/auth` landed by composer (the baked image)
 * wired the panel and left the door it requires unwired: the invitation the act printed answered 404
 * (greenhouse evidence/1054, arm C). The house tells «never declared» from «removed» by what it records: the
 * birth stamp, the declaration ledger its own writers keep, and the plugin registry's explicit disables. When
 * it cannot tell, it does not wire, and says why.
 *
 * @guards capabilities:enable on a capability whose requirements are installed and unwired
 *
 * @fires  on every capabilities:enable
 *
 * @refuses to wire a requirement that was disabled, removed by hand, or whose file's history is unknown
 *
 * @subject-in milpa/app-runtime
 */
#[CoversClass(Capabilities::class)]
#[CoversClass(DeclarationLedger::class)]
final class EnablingWiresWhatItRequiresTest extends TestCase
{
    private string $root = '';

    private string $baked = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-ar-requires-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o775, true);
        mkdir($this->root . '/.milpa', 0o775, true);
        file_put_contents($this->root . '/config/operations.php', "<?php\n\nreturn [\n];\n");
        file_put_contents($this->root . '/config/plugins.php', "<?php\n\nreturn [\n    App\\Plugins\\HelloPlugin\\HelloPlugin::class,\n];\n");
        file_put_contents($this->root . '/config/app.php', "<?php\n\nreturn [\n    'app' => ['name' => 'baked-house'],\n];\n");
        $this->stampBirth();
        $this->baked = $this->vendorWith([
            // The door is reached only THROUGH another package: the graph is followed, not just the first hop.
            $this->package('milpa/admin', 'admin', ['milpa/ui' => '*', 'php' => '>=8.3']),
            $this->package('milpa/ui', 'ui-kit', ['milpa/auth' => '*']),
            $this->package('milpa/auth', 'identity'),
            // Installed, unwired, and required by nobody asked for: never touched.
            $this->package('milpa/devtools', 'devtools'),
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function enabling_the_panel_on_a_baked_house_wires_the_door_it_requires(): void
    {
        $answer = Capabilities::install('admin', $this->baked, $this->composerThatMustNotRun(), root: $this->root);

        self::assertTrue($answer['ok'], json_encode($answer, \JSON_THROW_ON_ERROR));
        self::assertSame('milpa/admin', $answer['capability']);
        $wired = array_column($answer['wired_with_it'] ?? [], null, 'package');
        self::assertSame(['milpa/auth'], array_keys($wired), 'identity only: devtools is not required, ui-kit declares nothing');
        self::assertSame([PasskeyPlugin::class], $wired['milpa/auth']['plugins_declared']);
        self::assertSame(['rpId' => 'localhost', 'written' => true, 'file' => 'config/app.php'], $wired['milpa/auth']['relying_party']);
        self::assertStringContainsString('first_passkey', (string) $answer['hint'], 'the hint is the door\'s');
        self::assertArrayNotHasKey('not_wired', $answer);

        $plugins = (fn (): mixed => include $this->root . '/config/plugins.php')();
        self::assertIsArray($plugins);
        self::assertContains(PasskeyPlugin::class, $plugins);
        self::assertTrue(DeclarationLedger::accounted($this->root, 'config/plugins.php'), 'the house\'s own writes keep the file accounted for');
    }

    #[Test]
    public function enabling_it_again_is_a_no_op(): void
    {
        Capabilities::install('admin', $this->baked, $this->composerThatMustNotRun(), root: $this->root);
        $files = $this->configBytes();

        $again = Capabilities::install('admin', $this->baked, $this->composerThatMustNotRun(), root: $this->root);

        self::assertTrue($again['ok']);
        self::assertStringContainsString('nothing to do', (string) $again['hint']);
        self::assertArrayNotHasKey('wired_with_it', $again);
        self::assertSame($files, $this->configBytes());
    }

    #[Test]
    public function a_fresh_enable_wires_what_was_already_installed(): void
    {
        // The panel arrives by this act; the door it requires was installed BEFORE and never declared.
        $before = $this->vendorWith([$this->package('milpa/auth', 'identity')]);

        $answer = Capabilities::install('admin', $before, static fn (string $c): array => [0, []], vendorAfter: $this->baked, root: $this->root);

        self::assertTrue($answer['ok'], json_encode($answer, \JSON_THROW_ON_ERROR));
        self::assertSame(['milpa/auth'], array_column($answer['wired_with_it'] ?? [], 'package'));
        self::assertSame(['milpa/devtools', 'milpa/ui'], array_column($answer['arrived_with_it'] ?? [], 'package'), 'what arrived stays its own list');
        self::assertStringContainsString('first_passkey', (string) $answer['hint']);
    }

    #[Test]
    public function an_explicitly_disabled_door_is_never_re_added(): void
    {
        mkdir($this->root . '/storage', 0o775, true);
        file_put_contents($this->root . '/storage/plugins.json', json_encode(['plugins' => [
            ['name' => 'Passkey', 'installed' => true, 'enabled' => false],
        ]], \JSON_THROW_ON_ERROR));
        $files = $this->configBytes();

        $answer = Capabilities::install('admin', $this->baked, $this->composerThatMustNotRun(), root: $this->root);

        self::assertTrue($answer['ok']);
        self::assertArrayNotHasKey('wired_with_it', $answer);
        self::assertSame('milpa/auth', $answer['not_wired'][0]['package'] ?? null);
        self::assertStringContainsString('explicitly disabled', $answer['not_wired'][0]['why']);
        self::assertSame($files, $this->configBytes(), 'nothing of the door is written — not even its relying party');

        // Idempotent in refusing, too.
        $again = Capabilities::install('admin', $this->baked, $this->composerThatMustNotRun(), root: $this->root);
        self::assertSame($files, $this->configBytes());
        self::assertStringContainsString('explicitly disabled', $again['not_wired'][0]['why'] ?? '');
    }

    #[Test]
    public function a_door_removed_by_hand_after_the_house_declared_it_is_never_re_added(): void
    {
        Capabilities::install('identity', $this->baked, $this->composerThatMustNotRun(), root: $this->root);
        self::assertStringContainsString(PasskeyPlugin::class, (string) file_get_contents($this->root . '/config/plugins.php'));
        // Somebody takes the door out by hand.
        $file = $this->root . '/config/plugins.php';
        file_put_contents($file, str_replace('    \\' . PasskeyPlugin::class . "::class,\n", '', (string) file_get_contents($file)));
        $files = $this->configBytes();

        $answer = Capabilities::install('admin', $this->baked, $this->composerThatMustNotRun(), root: $this->root);

        self::assertTrue($answer['ok']);
        self::assertArrayNotHasKey('wired_with_it', $answer);
        self::assertStringContainsString('cannot tell never-declared from removed: config/plugins.php', $answer['not_wired'][0]['why'] ?? '');
        self::assertSame($files, $this->configBytes());
    }

    #[Test]
    public function a_hand_edit_breaks_the_chain_for_good(): void
    {
        // A hand edit the house never saw, then a house write on top of it: the write cannot know what the edit
        // removed, so the file stays unaccounted even though its bytes now equal what the house last wrote.
        $file = $this->root . '/config/plugins.php';
        file_put_contents($file, str_replace('HelloPlugin::class', 'HelloPlugin::class, // edited', (string) file_get_contents($file)));
        self::assertFalse(DeclarationLedger::accounted($this->root, 'config/plugins.php'), 'a hand edit is not the birth bytes');
        Capabilities::registerPlugins($this->root, ['App\\Plugins\\Other\\Other']);

        self::assertFalse(DeclarationLedger::accounted($this->root, 'config/plugins.php'));
        $answer = Capabilities::install('admin', $this->baked, $this->composerThatMustNotRun(), root: $this->root);
        self::assertStringContainsString('cannot tell', $answer['not_wired'][0]['why'] ?? '');
    }

    #[Test]
    public function a_house_without_a_birth_record_cannot_tell_and_does_not_wire(): void
    {
        unlink($this->root . '/.milpa/framework.json');
        $files = $this->configBytes();

        $answer = Capabilities::install('admin', $this->baked, $this->composerThatMustNotRun(), root: $this->root);

        self::assertTrue($answer['ok']);
        self::assertStringContainsString('cannot tell', $answer['not_wired'][0]['why'] ?? '');
        self::assertSame($files, $this->configBytes());
    }

    #[Test]
    public function an_unreadable_registry_cannot_rule_out_a_disable(): void
    {
        mkdir($this->root . '/storage', 0o775, true);
        file_put_contents($this->root . '/storage/plugins.json', '{not json');

        $answer = Capabilities::install('admin', $this->baked, $this->composerThatMustNotRun(), root: $this->root);

        self::assertStringContainsString('could not be read', $answer['not_wired'][0]['why'] ?? '');
        self::assertStringNotContainsString(PasskeyPlugin::class, (string) file_get_contents($this->root . '/config/plugins.php'));
    }

    #[Test]
    public function a_door_enabled_in_the_registry_is_not_a_disable(): void
    {
        // The control for the disable check: a record that says «on» must not read as «off».
        mkdir($this->root . '/storage', 0o775, true);
        file_put_contents($this->root . '/storage/plugins.json', json_encode(['plugins' => [
            ['name' => 'Passkey', 'installed' => true, 'enabled' => true],
        ]], \JSON_THROW_ON_ERROR));

        $answer = Capabilities::install('admin', $this->baked, $this->composerThatMustNotRun(), root: $this->root);

        self::assertSame(['milpa/auth'], array_column($answer['wired_with_it'] ?? [], 'package'));
    }

    #[Test]
    public function the_graph_is_followed_and_the_asked_package_is_not_its_own_requirement(): void
    {
        self::assertSame(['milpa/auth', 'milpa/ui'], array_keys(Capabilities::requiredCapabilities('milpa/admin', $this->baked)));
        self::assertSame(['milpa/auth'], array_keys(Capabilities::requiredCapabilities('milpa/ui', $this->baked)));
        self::assertSame([], Capabilities::requiredCapabilities('milpa/auth', $this->baked));
        self::assertSame([], Capabilities::requiredCapabilities('milpa/admin', $this->root . '/no-vendor'));
    }

    /** @return array<string, string> */
    private function configBytes(): array
    {
        $bytes = [];
        foreach (['operations', 'plugins', 'app'] as $name) {
            $bytes[$name] = (string) file_get_contents($this->root . "/config/{$name}.php");
        }

        return $bytes;
    }

    private function stampBirth(): void
    {
        $files = [];
        foreach (['operations', 'plugins', 'app'] as $name) {
            $files["config/{$name}.php"] = (string) hash_file('sha256', $this->root . "/config/{$name}.php");
        }
        file_put_contents($this->root . '/.milpa/framework.json', json_encode([
            'version' => '0.55.0',
            'born' => ['version' => '0.55.0', 'at' => '2026-09-29T00:00:00+00:00', 'files' => $files],
        ], \JSON_THROW_ON_ERROR));
    }

    /** @param list<array<string, mixed>> $packages */
    private function vendorWith(array $packages): string
    {
        $dir = $this->root . '/vendor-' . bin2hex(random_bytes(3));
        mkdir($dir . '/composer', 0o775, true);
        file_put_contents($dir . '/composer/installed.json', json_encode(['packages' => $packages], \JSON_THROW_ON_ERROR));

        return $dir;
    }

    /**
     * @param array<string, string> $require
     *
     * @return array<string, mixed>
     */
    private function package(string $name, string $id, array $require = []): array
    {
        return [
            'name' => $name,
            'version' => '1.0.0',
            'require' => $require,
            'extra' => ['milpa' => ['capability' => ['id' => $id, 'title' => $id]]],
        ];
    }

    private function composerThatMustNotRun(): callable
    {
        return static function (string $command): array {
            self::fail("composer must not run for an installed package, and was asked: {$command}");
        };
    }
}
