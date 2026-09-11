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
use Milpa\AppRuntime\Web\PasskeyPlugin;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `capabilities:enable identity` leaves the app with a door, not with three hand edits (greenhouse
 * decisions/0216, point 7): the capability is named by its id, the passkey plugin lands in
 * config/plugins.php, the relying party lands in config/app.php — each verified by loading the file
 * back, each idempotent, none touched for a capability that brings no door.
 */
final class EnablingIdentityOpensTheDoorTest extends TestCase
{
    private string $root;

    private string $vendorBefore;

    private string $vendorAfter;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-ar-door-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o775, true);
        // The skeleton's shape: comments between entries, the returned array closing the file.
        file_put_contents($this->root . '/config/plugins.php', <<<'PHP_'
            <?php

            declare(strict_types=1);

            use App\Plugins\HelloPlugin\HelloPlugin;

            return [
                HelloPlugin::class,
                // Serves over HTTP the operations config/http.php names.
                App\Plugins\OperationsHttpPlugin\OperationsHttpPlugin::class,
            ];

            PHP_);
        file_put_contents($this->root . '/config/app.php', <<<'PHP_'
            <?php

            declare(strict_types=1);

            return [
                'app' => ['name' => 'door-house', 'debug' => true],
                // 'storage' => [
                //     'driver' => 'sqlite',
                // ],
            ];

            PHP_);
        $this->vendorBefore = $this->vendorWith([]);
        $this->vendorAfter = $this->vendorWith([$this->package('milpa/auth', 'identity')]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function the_capability_is_named_by_its_id_before_the_package_is_there(): void
    {
        $available = Capabilities::state($this->vendorBefore)['available'];
        $byPackage = array_column($available, 'id', 'package');

        self::assertSame('identity', $byPackage['milpa/auth']);
        self::assertSame('devtools', $byPackage['milpa/devtools']);
        self::assertSame('persistence', $byPackage['milpa/data']);

        $dry = Capabilities::install('identity', $this->vendorBefore, dryRun: true, root: $this->root);
        self::assertTrue($dry['ok']);
        self::assertSame('milpa/auth', $dry['capability'], 'the id resolves to the package that declares it');
        self::assertSame('composer require milpa/auth', $dry['command']);
    }

    #[Test]
    public function enabling_identity_declares_the_plugin_and_the_relying_party_and_verifies_both(): void
    {
        $ran = [];
        $answer = Capabilities::install(
            'identity',
            $this->vendorBefore,
            function (string $command) use (&$ran): array {
                $ran[] = $command;

                return [0, ['Package operations: 1 install']];
            },
            vendorAfter: $this->vendorAfter,
            root: $this->root,
        );

        self::assertTrue($answer['ok'], json_encode($answer, \JSON_THROW_ON_ERROR));
        self::assertSame(['composer require milpa/auth'], $ran);
        self::assertSame([PasskeyPlugin::class], $answer['plugins_declared']);
        self::assertSame(['rpId' => 'localhost', 'written' => true, 'file' => 'config/app.php'], $answer['relying_party']);
        self::assertStringContainsString('php bin/coa serve', $answer['hint']);

        // VERIFIED BY LOADING, not by reading text: the declarations are what PHP returns from the files.
        $plugins = (fn (): mixed => include $this->root . '/config/plugins.php')();
        self::assertIsArray($plugins);
        self::assertSame(PasskeyPlugin::class, end($plugins), 'the door is declared after the app\'s own plugins');
        self::assertCount(3, $plugins);
        $config = (fn (): mixed => include $this->root . '/config/app.php')();
        self::assertIsArray($config);
        self::assertSame('localhost', $config['passkey']['rpId']);
        self::assertSame('door-house', $config['app']['name'], 'the rest of the file is what it was');
        self::assertStringContainsString('Declared by `capabilities:enable identity`', (string) file_get_contents($this->root . '/config/app.php'));

        // IDEMPOTENT: enabling again finds it installed and touches nothing (F2's rule applied to the door).
        $pluginsFile = (string) file_get_contents($this->root . '/config/plugins.php');
        $appFile = (string) file_get_contents($this->root . '/config/app.php');
        $again = Capabilities::install('identity', $this->vendorAfter, root: $this->root);
        self::assertTrue($again['ok']);
        self::assertStringContainsString('already installed', $again['hint']);
        self::assertStringEqualsFile($this->root . '/config/plugins.php', $pluginsFile);
        self::assertStringEqualsFile($this->root . '/config/app.php', $appFile);

        // And the writers themselves refuse to repeat a declaration that is already there.
        self::assertSame([], Capabilities::registerPlugins($this->root, [PasskeyPlugin::class]));
        self::assertSame(['rpId' => 'localhost', 'written' => false, 'file' => 'config/app.php'], Capabilities::declareRelyingParty($this->root));
    }

    #[Test]
    public function a_relying_party_the_app_already_declares_is_kept(): void
    {
        file_put_contents($this->root . '/config/app.php', "<?php\n\nreturn ['passkey' => ['rpId' => 'notes.example'], 'app' => ['name' => 'x']];\n");
        $before = (string) file_get_contents($this->root . '/config/app.php');

        $answer = Capabilities::install('identity', $this->vendorBefore, static fn (string $c): array => [0, []], vendorAfter: $this->vendorAfter, root: $this->root);

        self::assertTrue($answer['ok']);
        self::assertSame(['rpId' => 'notes.example', 'written' => false, 'file' => 'config/app.php'], $answer['relying_party']);
        self::assertStringEqualsFile($this->root . '/config/app.php', $before);
    }

    #[Test]
    public function a_capability_without_a_door_touches_neither_file(): void
    {
        $pluginsFile = (string) file_get_contents($this->root . '/config/plugins.php');
        $appFile = (string) file_get_contents($this->root . '/config/app.php');
        $after = $this->vendorWith([$this->package('milpa/devtools', 'devtools')]);

        $answer = Capabilities::install('devtools', $this->vendorBefore, static fn (string $c): array => [0, []], vendorAfter: $after, root: $this->root);

        self::assertTrue($answer['ok']);
        self::assertSame('milpa/devtools', $answer['capability'], 'by id, like identity');
        self::assertSame([], $answer['plugins_declared']);
        self::assertArrayNotHasKey('relying_party', $answer);
        self::assertStringEqualsFile($this->root . '/config/plugins.php', $pluginsFile);
        self::assertStringEqualsFile($this->root . '/config/app.php', $appFile);
    }

    #[Test]
    public function a_declaration_that_does_not_load_back_is_reverted_and_said(): void
    {
        // A config file whose last `];` is not the returned array's: the insertion lands somewhere PHP
        // does not return — the writer must notice, put the file back and say so.
        $broken = "<?php\n\nreturn ['app' => ['name' => 'x']];\n\n\$unused = [\n];\n";
        file_put_contents($this->root . '/config/app.php', $broken);

        $answer = Capabilities::declareRelyingParty($this->root);

        self::assertNotNull($answer);
        self::assertFalse($answer['written']);
        self::assertStringContainsString('reverted', $answer['error'] ?? '');
        self::assertStringEqualsFile($this->root . '/config/app.php', $broken);
    }

    /** @param list<array<string, mixed>> $packages */
    private function vendorWith(array $packages): string
    {
        $dir = $this->root . '/vendor-' . bin2hex(random_bytes(3));
        mkdir($dir . '/composer', 0o775, true);
        file_put_contents($dir . '/composer/installed.json', json_encode(['packages' => $packages], \JSON_THROW_ON_ERROR));

        return $dir;
    }

    /** @return array<string, mixed> */
    private function package(string $name, string $id): array
    {
        return [
            'name' => $name,
            'version' => '1.0.0',
            'extra' => ['milpa' => ['capability' => ['id' => $id, 'title' => $id, 'unlocks' => [], 'provides' => []]]],
        ];
    }
}
